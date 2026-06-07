"""Query-side RAG: embed → vector search → assemble :class:`Passage`."""

from __future__ import annotations

import asyncio
import logging
from typing import List, Optional

from app.domain.schemas import Passage
from app.services.embedding_service import EmbeddingService
from app.services.vector_store import VectorStore

logger = logging.getLogger(__name__)


class RetrievalService:
    def __init__(self, embedding: EmbeddingService, vector_store: VectorStore) -> None:
        self.embedding = embedding
        self.vector_store = vector_store

    async def retrieve(
        self,
        project_id: int,
        query: str,
        top_k: int = 5,
        source_ids: Optional[List[int]] = None,
    ) -> List[Passage]:
        if not query or not query.strip():
            return []
        try:
            qvec = await self.embedding.embed(query)
        except Exception:  # noqa: BLE001
            logger.exception("query embed failed")
            return []

        try:
            hits = await asyncio.to_thread(
                self.vector_store.search,
                project_id,
                qvec,
                top_k,
                source_ids,
            )
        except Exception:  # noqa: BLE001
            logger.exception("vector search failed")
            return []

        passages: List[Passage] = []
        for hit in hits:
            payload = hit.payload or {}
            passages.append(
                Passage(
                    text=str(payload.get("text", "")),
                    score=float(hit.score),
                    citation=dict(payload.get("citation") or {}),
                    source_id=int(payload.get("source_id", 0) or 0),
                    source_type=str(payload.get("source_type", "")),
                )
            )
        return passages
