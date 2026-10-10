"""Asterisk calls: ARI call control + AudioSocket audio → the call core.

    caller → trunk / PBX / browser → Asterisk → Stasis(ai-crm, inbound, <dialed>)
      1. StasisStart  → ask Laravel who answers (/api/internal/asterisk/call-start)
      2. answer; put the caller in a mixing bridge
      3. an externalMedia (AudioSocket) channel — "the AI leg" — joins the
         bridge; Asterisk connects to our AudioSocket server with the call
         id we chose
      4. a CallSession runs over that connection: greeting, turns, barge-in
      5. transfer: drop the AI leg, send the caller to the dialplan's
         [ai-transfer] context at the number's transfer target
      6. a hang-up on either side tears the rest down

ARI is plain REST plus one WebSocket of events, so this uses httpx and
websockets directly (the old ari-py library is unmaintained).
"""

from __future__ import annotations

import asyncio
import audioop
import json
import logging
import re
import uuid
from dataclasses import dataclass
from typing import Dict, Optional
from urllib.parse import quote

import httpx
import websockets

from app.calls.audiosocket import (
    KIND_AUDIO,
    KIND_ERROR,
    KIND_HANGUP,
    RATE,
    AudioSocketServer,
    AudioSocketTransport,
    read_frame,
)
from app.calls.session import CallServices, CallSession
from app.domain.auth import AuthError, SessionClaims, decode_token

logger = logging.getLogger(__name__)

TRANSFER_CONTEXT = "ai-transfer"  # dialplan context that reaches people
STT_RATE = 16000

# SIP accounts the admin panel creates (pushed over ARI, kept in astdb —
# telephony/asterisk/conf/sorcery.conf). Names: "c<client>_<extension>" for
# client extensions, "w<random>" for one-call website guests.
GUEST_PREFIX = "w"
ACCOUNT_NAME = re.compile(r"^[a-z0-9_]{3,40}$")
# The only dialplan contexts an account may be put in. Neither can reach
# an outside line, so a leaked password can't run up a phone bill.
ACCOUNT_CONTEXTS = frozenset({"internal-calls", "web-guest"})
_WEBRTC = {"webrtc": "yes", "dtls_auto_generate_cert": "yes", "disallow": "all",
           "allow": "ulaw,alaw,opus"}  # G.711 first: no transcoding to the AI
ACCOUNT_KINDS = {
    # Desk phones and apps: MicroSIP, Zoiper, Linphone, a Grandstream handset.
    "app": ({"disallow": "all", "allow": "ulaw,alaw,g722", "direct_media": "no",
             "rtp_symmetric": "yes", "force_rport": "yes", "rewrite_contact": "yes",
             "dtmf_mode": "rfc4733"},
            {"max_contacts": "3", "remove_existing": "yes", "qualify_frequency": "30"}),
    # The admin panel's browser phone.
    "webrtc": (_WEBRTC, {"max_contacts": "3", "remove_existing": "yes", "qualify_frequency": "0"}),
    # A website visitor's "Call us" button: one browser, one call.
    "guest": (_WEBRTC, {"max_contacts": "1", "remove_existing": "yes", "qualify_frequency": "0"}),
}


class AccountError(ValueError):
    """A SIP account request we refuse to push to Asterisk."""


@dataclass
class AsteriskCall:
    channel_id: str                  # the caller's channel
    call_id: str                     # AudioSocket id; also names the AI leg
    claims: SessionClaims
    transfer_to: Optional[str] = None
    bridge_id: Optional[str] = None
    media_id: Optional[str] = None   # the AI leg (externalMedia channel)
    session: Optional[CallSession] = None
    transferring: bool = False
    ended: bool = False
    # The SIP account that placed the call, when it came from one of ours
    # ("c12_2001" a client extension, "w…" a website caller).
    endpoint: str = ""
    max_seconds: Optional[int] = None  # website calls are time-limited


class AsteriskGateway:
    def __init__(self, services: CallServices, settings,
                 http: Optional[httpx.AsyncClient] = None) -> None:
        self.services = services
        self.app_name = settings.asterisk_ari_app
        base = settings.asterisk_ari_url.rstrip("/")
        self.events_url = (
            base.replace("http", "ws", 1)
            + f"/ari/events?app={quote(self.app_name)}"
            + f"&api_key={quote(settings.asterisk_ari_user)}:{quote(settings.asterisk_ari_password)}"
        )
        self.ari = http or httpx.AsyncClient(
            base_url=base + "/ari",
            auth=(settings.asterisk_ari_user, settings.asterisk_ari_password),
            timeout=10,
        )
        self.audiosocket = AudioSocketServer(settings.audiosocket_bind)
        self.advertise = settings.audiosocket_advertise
        self.connected = False
        self.calls: Dict[str, AsteriskCall] = {}      # by caller channel id
        self._by_media: Dict[str, AsteriskCall] = {}  # by AI-leg channel id
        self._tasks: set = set()
        self._listener: Optional[asyncio.Task] = None

    async def start(self) -> None:
        await self.audiosocket.start()
        self._listener = asyncio.create_task(self._listen())

    async def stop(self) -> None:
        if self._listener:
            self._listener.cancel()
        for call in list(self.calls.values()):
            await self._teardown(call, hang_up_caller=True)
        await self.audiosocket.close()
        await self.ari.aclose()

    # ── ARI events ─────────────────────────────────────────────────────

    async def _listen(self) -> None:
        backoff = 1.0
        while True:
            try:
                async with websockets.connect(self.events_url) as ws:
                    self.connected = True
                    backoff = 1.0
                    logger.info("ARI connected: app=%s", self.app_name)
                    async for raw in ws:
                        try:
                            self.handle_event(json.loads(raw))
                        except Exception:  # noqa: BLE001 — keep listening
                            logger.exception("ARI event failed")
            except asyncio.CancelledError:
                raise
            except Exception as exc:  # noqa: BLE001 — Asterisk down/restarting
                logger.warning("ARI unavailable (%s) — retrying in %.0fs", exc, backoff)
            self.connected = False
            await asyncio.sleep(backoff)
            backoff = min(backoff * 2, 30.0)

    def handle_event(self, event: dict) -> None:
        kind = event.get("type")
        channel = event.get("channel") or {}
        cid = channel.get("id", "")

        if kind == "StasisStart":
            args = event.get("args") or []
            if cid in self._by_media or not args:
                return  # our own AI legs enter the app too
            if args[0] == "inbound":
                dialed = args[1] if len(args) > 1 else (channel.get("dialplan") or {}).get("exten", "")
                # The dialplan passes the calling account, for calls from
                # client extensions and website callers (extensions.conf).
                endpoint = args[2] if len(args) > 2 else ""
                self._spawn(self._inbound(channel, dialed, endpoint))
            else:
                logger.warning("ARI: unexpected Stasis args %s — hanging up", args)
                self._spawn(self._hang_up(cid))

        elif kind in ("StasisEnd", "ChannelDestroyed"):
            if cid in self.calls:
                # The caller hung up — or left for the transfer target.
                self._spawn(self._teardown(self.calls[cid], hang_up_caller=False))
            elif cid in self._by_media:
                # The AI leg died: don't leave the caller in silence.
                self._spawn(self._teardown(self._by_media[cid], hang_up_caller=True))

    # ── one call ───────────────────────────────────────────────────────

    async def _inbound(self, channel: dict, dialed: str, endpoint: str = "") -> None:
        cid = channel["id"]
        caller = (channel.get("caller") or {}).get("number", "") or ""

        start = await self.services.laravel.asterisk_call_start(cid, caller, dialed, endpoint)
        if not start or not start.get("ok"):
            reason = (start or {}).get("reason", "Laravel unreachable")
            logger.warning("ARI: call %s to %s rejected (%s)", cid, dialed, reason)
            await self._hang_up(cid)
            return
        try:
            claims = decode_token(start.get("token", ""))
        except AuthError as exc:
            logger.warning("ARI: bad session token for %s: %s", cid, exc)
            await self._hang_up(cid)
            return

        call_id = str(uuid.uuid4())
        call = AsteriskCall(channel_id=cid, call_id=call_id, claims=claims,
                            transfer_to=start.get("transfer_to") or None,
                            media_id=f"ai-{call_id}", endpoint=endpoint,
                            max_seconds=int(start["max_seconds"]) if start.get("max_seconds") else None)
        self.calls[cid] = call
        self._by_media[call.media_id] = call
        greeting = start.get("greeting") or ""
        self.audiosocket.expect(call_id, lambda r, w: self._run_audio(call, greeting, r, w))

        try:
            await self._post(f"/channels/{cid}/answer")
            if call.max_seconds:
                # Backstop to the AI's own "time's up" goodbye: Asterisk
                # hangs up a few seconds after the limit, whatever happens.
                await self._post(f"/channels/{cid}/variable",
                                 variable="TIMEOUT(absolute)", value=str(call.max_seconds + 8))
            bridge = await self._post("/bridges", type="mixing")
            call.bridge_id = bridge["id"]
            await self._post(f"/bridges/{call.bridge_id}/addChannel", channel=cid)
            await self._post(
                "/channels/externalMedia",
                channelId=call.media_id,
                app=self.app_name,
                external_host=self.advertise,
                encapsulation="audiosocket",
                transport="tcp",
                format="slin",  # 8 kHz PCM16, what AudioSocket carries
                data=call_id,
            )
            await self._post(f"/bridges/{call.bridge_id}/addChannel", channel=call.media_id)
            logger.info("ARI: call %s from %s to %s answered (session %s)",
                        cid, caller or "unknown", dialed, claims.session_id)
        except httpx.HTTPError as exc:
            # Includes the caller hanging up while Laravel was answering.
            logger.warning("ARI: setting up call %s failed: %s", cid, exc)
            await self._teardown(call, hang_up_caller=True)

    async def _run_audio(self, call: AsteriskCall, greeting: str,
                         reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        """The AudioSocket connection for ``call`` — lives as long as the AI leg."""
        on_transfer = (lambda: self._spawn(self._transfer(call))) if call.transfer_to else None
        # The AI said goodbye (or the line went dead): hang up on the caller.
        on_hangup = lambda: self._spawn(self._teardown(call, hang_up_caller=True))  # noqa: E731
        transport = AudioSocketTransport(writer, on_transfer, on_hangup)
        session = CallSession(self.services, transport, call.claims, call_ref=call.channel_id)
        session.max_seconds = call.max_seconds
        call.session = session
        await session.start()
        session.greet(greeting)

        resample_state = None
        try:
            while True:
                kind, payload = await read_frame(reader)
                if kind == KIND_AUDIO:
                    pcm16, resample_state = audioop.ratecv(payload, 2, 1, RATE, STT_RATE, resample_state)
                    session.feed_audio(pcm16)
                elif kind == KIND_ERROR:
                    logger.warning("AudioSocket error on %s: %r", call.channel_id, payload)
                    break
                elif kind == KIND_HANGUP:
                    break
                # DTMF and anything newer: not used yet.
        except (asyncio.IncompleteReadError, ConnectionError):
            pass
        finally:
            transport.close()
            await session.close()
            if not call.transferring:
                await self._teardown(call, hang_up_caller=True)

    async def _transfer(self, call: AsteriskCall) -> None:
        """Hand the caller to a person through the dialplan's [ai-transfer]
        context, where the deployment decides how to reach the target (a
        local extension, a queue, or a PBX over its trunk)."""
        call.transferring = True
        logger.info("ARI: transferring %s to %s", call.channel_id, call.transfer_to)
        try:
            if call.bridge_id:
                await self._post(f"/bridges/{call.bridge_id}/removeChannel", channel=call.channel_id)
            await self._post(f"/channels/{call.channel_id}/continue",
                             context=TRANSFER_CONTEXT, extension=call.transfer_to, priority=1)
        except httpx.HTTPError as exc:
            logger.warning("ARI: transfer of %s failed: %s", call.channel_id, exc)
            call.transferring = False  # → the teardown below hangs up the caller
        await self._teardown(call, hang_up_caller=not call.transferring)

    async def _teardown(self, call: AsteriskCall, hang_up_caller: bool) -> None:
        if call.ended:
            return
        call.ended = True
        self.calls.pop(call.channel_id, None)
        self.audiosocket.forget(call.call_id)
        if call.media_id:
            self._by_media.pop(call.media_id, None)
            await self._hang_up(call.media_id)
        if call.bridge_id:
            await self._delete(f"/bridges/{call.bridge_id}")
        if hang_up_caller and not call.transferring:
            await self._hang_up(call.channel_id)
        if call.endpoint.startswith(GUEST_PREFIX):
            # A website caller's account is good for this one call only.
            await self.delete_account(call.endpoint)
        if call.session is not None:
            await call.session.close()
        else:
            # Hung up before the audio connected: still close the session.
            await self.services.laravel.post_session_ended(
                call.claims.project_id, call.claims.session_id)

    # ── SIP accounts (extensions, website callers) ─────────────────────

    async def put_account(self, name: str, password: str, kind: str, context: str,
                          callerid: str = "") -> None:
        """Create or update a SIP account, live — no reload."""
        if not ACCOUNT_NAME.match(name):
            raise AccountError("account names are 3-40 lowercase letters, digits or _")
        if kind not in ACCOUNT_KINDS:
            raise AccountError(f"unknown account kind {kind!r}")
        if context not in ACCOUNT_CONTEXTS:
            raise AccountError(f"context {context!r} is not allowed")
        if len(password) < 12:
            raise AccountError("passwords are at least 12 characters")
        endpoint, aor = ACCOUNT_KINDS[kind]
        await self._push("auth", name, {"auth_type": "userpass", "username": name, "password": password})
        await self._push("aor", name, aor)
        fields = dict(endpoint, context=context, auth=name, aors=name)
        if callerid:
            fields["callerid"] = callerid
        await self._push("endpoint", name, fields)

    async def delete_account(self, name: str) -> None:
        if not ACCOUNT_NAME.match(name):
            return
        for kind in ("endpoint", "aor", "auth"):
            await self._delete(f"/asterisk/config/dynamic/res_pjsip/{kind}/{name}")

    async def account_states(self, prefix: str) -> Dict[str, str]:
        """{name: "online" | "offline"} for every account starting with ``prefix``."""
        resp = await self.ari.get("/endpoints/PJSIP")
        resp.raise_for_status()
        return {e["resource"]: e.get("state", "unknown") for e in resp.json()
                if e.get("resource", "").startswith(prefix)}

    async def _push(self, kind: str, name: str, fields: Dict[str, str]) -> None:
        resp = await self.ari.put(
            f"/asterisk/config/dynamic/res_pjsip/{kind}/{name}",
            json={"fields": [{"attribute": k, "value": v} for k, v in fields.items()]},
        )
        resp.raise_for_status()

    # ── ARI REST ───────────────────────────────────────────────────────

    async def _post(self, path: str, **params) -> dict:
        resp = await self.ari.post(path, params={k: v for k, v in params.items() if v is not None})
        resp.raise_for_status()
        return resp.json() if resp.content else {}

    async def _delete(self, path: str) -> None:
        try:
            await self.ari.delete(path)  # 404 = already gone, which is fine
        except httpx.HTTPError as exc:
            logger.debug("ARI DELETE %s failed: %s", path, exc)

    async def _hang_up(self, channel_id: str) -> None:
        await self._delete(f"/channels/{channel_id}")

    def _spawn(self, coro) -> None:
        task = asyncio.ensure_future(coro)
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)
