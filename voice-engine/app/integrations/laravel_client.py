"""Async client for the Laravel internal webhook.

The single entry point right now is :meth:`post_turn_completed` which
notifies Laravel after a WS turn ends so it can persist the assistant
message and queue lead extraction.
"""

from __future__ import annotations

import logging
from typing import Optional

import httpx

from app.config import get_settings
from app.domain.schemas import TurnCompletedPayload

logger = logging.getLogger(__name__)


class LaravelClient:
    def __init__(
        self,
        base_url: Optional[str] = None,
        internal_secret: Optional[str] = None,
        timeout: float = 10.0,
    ) -> None:
        settings = get_settings()
        self.base_url = (base_url or settings.laravel_base_url).rstrip("/")
        self.internal_secret = internal_secret or settings.python_internal_secret
        self.turn_completed_path = settings.laravel_turn_completed_path
        self.timeout = timeout
        self._client: Optional[httpx.AsyncClient] = None

    @property
    def client(self) -> httpx.AsyncClient:
        if self._client is None:
            self._client = httpx.AsyncClient(timeout=self.timeout)
        return self._client

    async def aclose(self) -> None:
        if self._client is not None:
            await self._client.aclose()
            self._client = None

    async def post_turn_completed(
        self, payload: TurnCompletedPayload
    ) -> Optional[dict]:
        url = f"{self.base_url}{self.turn_completed_path}"
        headers = {"X-Internal-Secret": self.internal_secret}
        try:
            resp = await self.client.post(
                url, json=payload.model_dump(), headers=headers
            )
            resp.raise_for_status()
            return resp.json()
        except httpx.HTTPError as exc:
            # Fire-and-forget — never break the WS turn on a webhook fail.
            logger.warning("turn-completed webhook failed: %s", exc)
            return None

    async def post_session_ended(
        self, project_id: int, session_id: int, call_seconds: Optional[int] = None
    ) -> None:
        """Tell Laravel the session is over so it flips status='ended'.

        Fires from ws_turn's `finally` block on disconnect. Fire-and-
        forget — if Laravel is unreachable, the per-call status webhook
        from Twilio still eventually marks the session ended, so this
        is belt-and-braces.

        ``call_seconds`` meters telephony minutes for providers with no
        billing webhook of their own (Asterisk). Twilio calls leave it out.
        """
        url = f"{self.base_url}/api/internal/session-ended"
        headers = {"X-Internal-Secret": self.internal_secret}
        body = {"project_id": project_id, "session_id": session_id}
        if call_seconds is not None:
            body["call_seconds"] = call_seconds
        try:
            resp = await self.client.post(url, json=body, headers=headers, timeout=5.0)
            resp.raise_for_status()
        except httpx.HTTPError as exc:
            logger.warning("session-ended webhook failed: %s", exc)

    async def call_profile(self, project_id: int, session_id: int) -> dict:
        """Fetch the agent's voice system prompt for a phone/SIP call.

        Built by Laravel's MemoryBuilder: agent persona, business profile,
        what we already know about the caller, the reply-language rule and
        the spoken-reply rules. Returns ``{}`` on failure — the call then
        falls back to the generic style prompt rather than failing.
        """
        url = f"{self.base_url}/api/internal/call-profile"
        headers = {"X-Internal-Secret": self.internal_secret}
        body = {"project_id": project_id, "session_id": session_id}
        try:
            resp = await self.client.post(url, json=body, headers=headers, timeout=10.0)
            resp.raise_for_status()
            return resp.json() or {}
        except (httpx.HTTPError, ValueError) as exc:
            logger.warning("call-profile call failed: %s", exc)
            return {}

    async def asterisk_call_start(
        self, channel_id: str, caller: str, dialed: str, endpoint: str = ""
    ) -> Optional[dict]:
        """Ask Laravel who answers an Asterisk call.

        ``endpoint``: the SIP account that placed it, for calls from client
        extensions ("c12_2001") and website callers ("w…").

        Returns ``{ok, token, session_id, project_id, greeting, transfer_to,
        max_seconds}``, ``{ok: false, reason}`` when no project takes the
        call, or None when Laravel can't be reached.
        """
        url = f"{self.base_url}/api/internal/asterisk/call-start"
        headers = {"X-Internal-Secret": self.internal_secret}
        body = {"channel_id": channel_id, "caller": caller, "dialed": dialed}
        if endpoint:
            body["endpoint"] = endpoint
        try:
            resp = await self.client.post(url, json=body, headers=headers, timeout=15.0)
            if resp.status_code in (403, 404, 429):
                return resp.json()
            resp.raise_for_status()
            return resp.json()
        except (httpx.HTTPError, ValueError) as exc:
            logger.warning("asterisk call-start failed: %s", exc)
            return None

    async def resolve_context(
        self, project_id: int, session_id: int, user_text: str
    ) -> str:
        """The "Reference data" block only — see :meth:`resolve`."""
        return (await self.resolve(project_id, session_id, user_text))["context"]

    async def resolve(self, project_id: int, session_id: int, user_text: str) -> dict:
        """Ask Laravel to run the full DataSourceRouter chain (RAG,
        webhook tools, live SQL) and return ``{context, handoff}``:
        a single "Reference data" string ready to inject as a system
        message, and whether the caller asked for a person (phone calls
        with a transfer target only).

        Without this, the WS path bypasses Laravel and the bot never
        sees per-project context. Returns an empty context on failure
        so the LLM still gets the chance to reply from its base
        knowledge.
        """
        url = f"{self.base_url}/api/internal/resolve-context"
        headers = {"X-Internal-Secret": self.internal_secret}
        body = {
            "project_id": project_id,
            "session_id": session_id,
            "user_text":  user_text,
        }
        try:
            # Generous timeout — the resolver chain may include 2-3
            # internal LLM calls (tool picker + table picker + SQL gen)
            # that can each take several seconds on the free Groq tier.
            resp = await self.client.post(
                url, json=body, headers=headers, timeout=60.0
            )
            resp.raise_for_status()
            data = resp.json()
            return {"context": data.get("context", "") or "",
                    "handoff": bool(data.get("handoff"))}
        except (httpx.HTTPError, ValueError) as exc:
            logger.warning("resolve-context call failed: %s", exc)
            return {"context": "", "handoff": False}
