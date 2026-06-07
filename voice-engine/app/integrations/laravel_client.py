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

    async def resolve_context(
        self, project_id: int, session_id: int, user_text: str
    ) -> str:
        """Ask Laravel to run the full DataSourceRouter chain (RAG,
        webhook tools, live SQL) and return a single "Reference data"
        string ready to inject as a system message.

        Without this, the WS path bypasses Laravel and the bot never
        sees per-project context. Returns an empty string on failure
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
            return data.get("context", "") or ""
        except httpx.HTTPError as exc:
            logger.warning("resolve-context call failed: %s", exc)
            return ""
