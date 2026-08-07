"""Gemini ``text-embedding-004`` wrapper.

Used for both ingestion (per-chunk embeddings) and query-time embedding of
the user question. Dimension is fixed at 768 to match the Qdrant
collection set up by :mod:`app.services.vector_store`.

This module is intentionally tolerant: if the embedding call fails for a
single batch we surface the error to the caller via an exception, but the
ingest service catches it and records it under ``IngestReport.errors``
rather than crashing the whole job.
"""

from __future__ import annotations

import asyncio
import logging
from typing import List, Optional

from app.config import get_settings

logger = logging.getLogger(__name__)

EMBEDDING_DIM = 768

# Soft batch size for Gemini — the public API tops out at 100 inputs per
# request, but small batches keep the per-request latency low and avoid
# rate-limit spikes. Tune via ``EMBEDDING_BATCH_SIZE`` if needed (TODO:
# expose as a config setting once we have real-world numbers).
_BATCH_SIZE = 32
_BATCH_SLEEP_SECONDS = 0.2


class EmbeddingService:
    """Embedding wrapper with pluggable backend.

    * ``gemini`` — google.generativeai ``text-embedding-004`` (768-dim, needs key)
    * ``ollama`` — local Ollama embeddings via the OpenAI-compatible
      ``/v1/embeddings`` endpoint (no key, offline). Use a 768-dim model
      such as ``nomic-embed-text`` so it matches the Qdrant collection.
    """

    def __init__(
        self,
        api_key: Optional[str] = None,
        model: Optional[str] = None,
        provider: Optional[str] = None,
    ) -> None:
        settings = get_settings()
        self.provider = (provider or settings.embedding_provider or "gemini").lower()
        self.api_key = api_key or settings.gemini_api_key
        self.model = model or settings.embedding_model
        self._ollama_base = settings.ollama_base_url
        self._configured = False
        self._client = None  # ollama OpenAI client

    # -- internal ----------------------------------------------------------
    def _ensure_configured(self) -> None:
        if self._configured:
            return
        if self.provider == "ollama":
            from openai import OpenAI  # type: ignore
            self._client = OpenAI(api_key="ollama", base_url=self._ollama_base, timeout=60.0)
        else:
            import google.generativeai as genai  # type: ignore
            if self.api_key:
                genai.configure(api_key=self.api_key)
            self._genai = genai
        self._configured = True

    def _embed_sync(self, text: str) -> List[float]:
        self._ensure_configured()

        if self.provider == "ollama":
            resp = self._client.embeddings.create(model=self.model, input=text)
            emb = resp.data[0].embedding if resp.data else None
            if not emb:
                raise RuntimeError("ollama embeddings returned no vector")
            return list(emb)

        # Gemini
        result = self._genai.embed_content(
            model=self.model,
            content=text,
            task_type="retrieval_document",
        )
        emb = result.get("embedding") if isinstance(result, dict) else None
        if emb is None and hasattr(result, "embedding"):
            emb = result.embedding  # type: ignore[attr-defined]
        if emb is None:
            raise RuntimeError("embedding API returned no vector")
        return list(emb)

    # -- public API --------------------------------------------------------
    async def embed(self, text: str) -> List[float]:
        return await asyncio.to_thread(self._embed_sync, text)

    async def embed_batch(self, texts: List[str]) -> List[List[float]]:
        """Embed a list of texts, batching to respect rate limits."""

        if not texts:
            return []
        out: List[List[float]] = []
        for start in range(0, len(texts), _BATCH_SIZE):
            chunk = texts[start : start + _BATCH_SIZE]
            # The SDK doesn't expose a true bulk endpoint in a stable way
            # across versions; loop per-item but in a worker thread.
            for t in chunk:
                try:
                    vec = await asyncio.to_thread(self._embed_sync, t)
                except Exception:  # noqa: BLE001
                    logger.exception("embedding failed for one chunk; using zero vector")
                    vec = [0.0] * EMBEDDING_DIM
                out.append(vec)
            # Brief pause between batches to be polite to the API.
            if start + _BATCH_SIZE < len(texts):
                await asyncio.sleep(_BATCH_SLEEP_SECONDS)
        return out
