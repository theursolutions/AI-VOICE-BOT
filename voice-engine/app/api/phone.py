"""``/ws/phone`` — Twilio Media Streams adapter for phone calls.

Frame protocol (Twilio Media Streams v1):
    Client → server JSON frames:
        {event: "connected", protocol: "Call", version: "1.0.0"}
        {event: "start",     streamSid, start: {accountSid, callSid,
                                                tracks, customParameters, mediaFormat}}
        {event: "media",     streamSid, media: {track, chunk, timestamp,
                                                payload (base64 μ-law)}}
        {event: "mark",      streamSid, mark: {name}}      (echo of our mark)
        {event: "stop",      streamSid, stop: {accountSid, callSid}}
    Server → client JSON frames:
        {event: "media",    streamSid, media: {payload (base64 μ-law)}}
        {event: "clear",    streamSid}                     (drop queued audio)

This module only speaks Twilio: it authenticates the stream, turns μ-law
8 kHz into PCM16 16 kHz for the call core, and turns the AI's voice back
into μ-law frames. Listening, STT, LLM, TTS, history, persona, transcripts
and barge-in all live in :mod:`app.calls.session`, shared with every other
telephony provider.
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
from typing import Optional

from fastapi import APIRouter, Query, WebSocket, WebSocketDisconnect
from fastapi.websockets import WebSocketState

from app.calls.session import CallServices, CallSession
from app.domain.auth import AuthError, SessionClaims, decode_token
from app.services.codec import (
    chunk_ulaw_for_twilio,
    pcm16_to_ulaw_8k,
    ulaw_to_pcm16_16k,
)

logger = logging.getLogger(__name__)
router = APIRouter()

ULAW_RATE = 8000  # one μ-law byte per sample


class TwilioTransport:
    """Plays the AI's voice into a Twilio Media Stream."""

    provider = "twilio"

    def __init__(self, websocket: WebSocket, stream_sid: str) -> None:
        self.ws = websocket
        self.stream_sid = stream_sid
        self._resample_state = None
        # Turn audio and barge-in "clear" come from different tasks.
        self._send_lock = asyncio.Lock()

    async def play(self, pcm16: bytes, sample_rate: int) -> float:
        ulaw, self._resample_state = pcm16_to_ulaw_8k(pcm16, sample_rate, self._resample_state)
        # Twilio buffers and plays at real time, so frames go out as fast as
        # we make them; the returned duration tells the session how long the
        # caller will be hearing them.
        async with self._send_lock:
            for frame in chunk_ulaw_for_twilio(ulaw):
                await self.ws.send_json({
                    "event": "media",
                    "streamSid": self.stream_sid,
                    "media": {"payload": base64.b64encode(frame).decode("ascii")},
                })
        return len(ulaw) / ULAW_RATE

    async def stop_playback(self) -> None:
        self._resample_state = None
        try:
            async with self._send_lock:
                await self.ws.send_json({"event": "clear", "streamSid": self.stream_sid})
        except Exception as exc:  # noqa: BLE001 — caller may already be gone
            logger.info("ws/phone clear not sent: %s", exc)

    async def hang_up(self) -> None:
        """End the call: with nothing after <Connect> in the TwiML, Twilio
        hangs up once the stream closes. The session waits for the goodbye
        to finish playing first."""
        self.hung_up = True
        try:
            async with self._send_lock:
                await self.ws.close()
        except Exception as exc:  # noqa: BLE001 — already closed
            logger.info("ws/phone close: %s", exc)


@router.websocket("/ws/phone")
async def ws_phone(websocket: WebSocket, token: str = Query(default="")) -> None:
    """Twilio Media Streams adapter.

    Auth note: Twilio STRIPS query-string parameters from the
    <Stream url="..."> URL it opens. The only way to receive a token
    is via <Parameter name="token" value="JWT"/> children in the
    TwiML — they arrive on the `start` event's `customParameters`
    field. So we accept the WS first, wait for `start`, then verify
    the JWT. Misauthenticated streams are closed immediately.

    The `token` query param is still accepted as a fallback for tools
    like `websocat` that want to test the endpoint outside Twilio.
    """
    logger.info("ws/phone handshake from origin=%s", websocket.headers.get("origin") or "(none)")

    # Accept the WS up-front. Twilio sends `connected` → `start` —
    # the token lives in `start.customParameters.token`.
    await websocket.accept()

    services = CallServices.from_app(websocket.app)
    if not (services.stt and services.tts):
        logger.warning("ws/phone: STT or TTS service unavailable, closing")
        await websocket.close(code=4503, reason="voice services unavailable")
        return

    claims: Optional[SessionClaims] = None
    if token:
        try:
            claims = decode_token(token)
            logger.info("ws/phone authenticated via query token")
        except AuthError as exc:
            logger.info("ws/phone query token rejected: %s", exc)

    call: Optional[CallSession] = None
    inbound_state = None

    try:
        while websocket.client_state == WebSocketState.CONNECTED:
            try:
                raw = await websocket.receive_text()
            except WebSocketDisconnect:
                break
            except RuntimeError:
                # We closed the stream ourselves (the AI hung up).
                break
            try:
                msg = json.loads(raw)
            except json.JSONDecodeError:
                continue
            event = msg.get("event")

            if event == "start":
                start = msg.get("start", {}) or {}
                stream_sid = msg.get("streamSid") or start.get("streamSid")
                logger.info("ws/phone start: streamSid=%s callSid=%s",
                            stream_sid, start.get("callSid"))
                if claims is None:
                    # Twilio delivers our JWT here, not in the URL.
                    params = start.get("customParameters", {}) or {}
                    try:
                        claims = decode_token(params.get("token") or "")
                    except AuthError as exc:
                        logger.warning("ws/phone token rejected: %s", exc)
                        await websocket.close(code=4401, reason=str(exc))
                        return
                call = CallSession(services, TwilioTransport(websocket, stream_sid), claims,
                                   call_ref=start.get("callSid") or stream_sid or "")
                await call.start()

            elif event == "media" and call is not None:
                payload_b64 = (msg.get("media", {}) or {}).get("payload", "")
                if not payload_b64:
                    continue
                try:
                    pcm16, inbound_state = ulaw_to_pcm16_16k(
                        base64.b64decode(payload_b64), inbound_state
                    )
                except Exception:  # noqa: BLE001 — skip one bad frame
                    continue
                call.feed_audio(pcm16)

            elif event == "stop":
                logger.info("ws/phone stop: %s", msg.get("stop", {}))
                break

            # 'connected', 'mark', 'dtmf' — nothing to do.
    except WebSocketDisconnect:
        logger.info("ws/phone disconnected")
    except Exception:  # noqa: BLE001
        logger.exception("ws/phone fatal")
    finally:
        if call is not None:
            await call.close()
        if (websocket.client_state == WebSocketState.CONNECTED
                and websocket.application_state == WebSocketState.CONNECTED):
            await websocket.close()
