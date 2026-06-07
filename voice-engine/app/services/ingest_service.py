"""Orchestrates extract → chunk → embed → upsert.

Status is held in a process-local dict keyed by ``job_id`` (also keyed by
``source_id`` for convenience). On restart the dict is cleared; Laravel
is expected to retry/re-sync if it cares.
"""

from __future__ import annotations

import asyncio
import logging
import time
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

from app.config import get_settings
from app.services.chunker import chunk_text
from app.services.document_ingest import parse_file
from app.services.embedding_service import EmbeddingService
from app.services.vector_store import VectorPoint, VectorStore
from app.services.website_ingest import crawl_and_extract

logger = logging.getLogger(__name__)


@dataclass
class IngestReport:
    chunks_indexed: int = 0
    pages_processed: int = 0
    errors: List[str] = field(default_factory=list)


# Module-level status table. Keys are ``job_id`` strings.
# This is intentionally global so the BackgroundTasks coroutine and the
# /rag/status route both see the same data. Cleared on process restart.
_STATUS: Dict[str, Dict[str, Any]] = {}


def _init_status(job_id: str, source_id: int) -> Dict[str, Any]:
    entry = {
        "job_id": job_id,
        "source_id": int(source_id),
        "status": "queued",
        "progress": 0.0,
        "chunks_indexed": 0,
        "pages_processed": 0,
        "errors": [],
        "error": None,
        "started_at": time.time(),
        "finished_at": None,
    }
    _STATUS[job_id] = entry
    return entry


def get_status(job_id: str) -> Optional[Dict[str, Any]]:
    return _STATUS.get(job_id)


class IngestService:
    """Wraps the embedding service + vector store + extractors."""

    def __init__(self, embedding: EmbeddingService, vector_store: VectorStore) -> None:
        self.embedding = embedding
        self.vector_store = vector_store
        self._settings = get_settings()

    # -- shared helpers ----------------------------------------------------
    def _chunk(self, text: str) -> List[str]:
        return chunk_text(
            text,
            max_tokens=self._settings.rag_chunk_max_tokens,
            overlap=self._settings.rag_chunk_overlap,
        )

    async def _embed_and_upsert(
        self,
        project_id: int,
        source_id: int,
        source_type: str,
        chunks: List[str],
        base_citation: Dict[str, Any],
        report: IngestReport,
    ) -> None:
        if not chunks:
            return
        try:
            vectors = await self.embedding.embed_batch(chunks)
        except Exception as exc:  # noqa: BLE001
            logger.exception("embedding batch failed")
            report.errors.append(f"embedding failed: {exc}")
            return

        points: List[VectorPoint] = []
        for text, vec in zip(chunks, vectors):
            points.append(
                VectorPoint(
                    id=self.vector_store.new_point_id(),
                    vector=vec,
                    payload={
                        "project_id": int(project_id),
                        "source_id": int(source_id),
                        "source_type": source_type,
                        "text": text,
                        "citation": base_citation,
                    },
                )
            )
        try:
            await asyncio.to_thread(self.vector_store.upsert, points)
            report.chunks_indexed += len(points)
        except Exception as exc:  # noqa: BLE001
            logger.exception("vector upsert failed")
            report.errors.append(f"upsert failed: {exc}")

    # -- website ----------------------------------------------------------
    async def ingest_website(
        self,
        project_id: int,
        source_id: int,
        url: str,
        max_depth: Optional[int] = None,
        max_pages: Optional[int] = None,
        job_id: Optional[str] = None,
    ) -> IngestReport:
        depth = max_depth if max_depth is not None else self._settings.rag_crawl_max_depth
        pages_cap = max_pages if max_pages is not None else self._settings.rag_crawl_max_pages
        report = IngestReport()

        status = _STATUS.get(job_id) if job_id else None
        if status is not None:
            status["status"] = "running"

        # Re-ingest: clear any prior vectors for this source.
        try:
            await asyncio.to_thread(self.vector_store.delete_by_source, source_id)
        except Exception as exc:  # noqa: BLE001
            logger.exception("delete_by_source failed (continuing)")
            report.errors.append(f"delete_by_source failed: {exc}")

        try:
            async for page in crawl_and_extract(url, max_depth=depth, max_pages=pages_cap):
                report.pages_processed += 1
                chunks = self._chunk(page.text)
                await self._embed_and_upsert(
                    project_id=project_id,
                    source_id=source_id,
                    source_type="website",
                    chunks=chunks,
                    base_citation={"url": page.url, "title": page.title},
                    report=report,
                )
                if status is not None:
                    status["pages_processed"] = report.pages_processed
                    status["chunks_indexed"] = report.chunks_indexed
                    status["progress"] = (
                        min(1.0, report.pages_processed / max(pages_cap, 1))
                    )
        except Exception as exc:  # noqa: BLE001
            logger.exception("website ingest crashed")
            report.errors.append(f"crawl failed: {exc}")

        if status is not None:
            status["status"] = "failed" if report.errors and report.chunks_indexed == 0 else "done"
            status["error"] = "; ".join(report.errors) if report.errors else None
            status["errors"] = list(report.errors)
            status["chunks_indexed"] = report.chunks_indexed
            status["pages_processed"] = report.pages_processed
            status["progress"] = 1.0
            status["finished_at"] = time.time()

        return report

    # -- documents --------------------------------------------------------
    async def ingest_documents(
        self,
        project_id: int,
        source_id: int,
        files: List[Dict[str, str]],
        job_id: Optional[str] = None,
    ) -> IngestReport:
        report = IngestReport()
        status = _STATUS.get(job_id) if job_id else None
        if status is not None:
            status["status"] = "running"

        try:
            await asyncio.to_thread(self.vector_store.delete_by_source, source_id)
        except Exception as exc:  # noqa: BLE001
            logger.exception("delete_by_source failed (continuing)")
            report.errors.append(f"delete_by_source failed: {exc}")

        total = max(len(files), 1)
        for idx, entry in enumerate(files, start=1):
            path = entry.get("path", "")
            original = entry.get("original_name") or path
            try:
                async for extracted in parse_file(path, original_name=original):
                    chunks = self._chunk(extracted.text)
                    citation = dict(extracted.citation)
                    await self._embed_and_upsert(
                        project_id=project_id,
                        source_id=source_id,
                        source_type="document",
                        chunks=chunks,
                        base_citation=citation,
                        report=report,
                    )
                report.pages_processed += 1
            except Exception as exc:  # noqa: BLE001
                logger.exception("document parse failed for %s", path)
                report.errors.append(f"{original}: {exc}")

            if status is not None:
                status["pages_processed"] = report.pages_processed
                status["chunks_indexed"] = report.chunks_indexed
                status["progress"] = idx / total

        if status is not None:
            status["status"] = "failed" if report.errors and report.chunks_indexed == 0 else "done"
            status["error"] = "; ".join(report.errors) if report.errors else None
            status["errors"] = list(report.errors)
            status["chunks_indexed"] = report.chunks_indexed
            status["pages_processed"] = report.pages_processed
            status["progress"] = 1.0
            status["finished_at"] = time.time()

        return report


def register_job(job_id: str, source_id: int) -> Dict[str, Any]:
    """Expose status initialisation to the route layer."""

    return _init_status(job_id, source_id)


def mark_failed(job_id: str, error: str) -> None:
    entry = _STATUS.get(job_id)
    if entry is None:
        return
    entry["status"] = "failed"
    entry["error"] = error
    entry["errors"] = list(entry.get("errors", [])) + [error]
    entry["finished_at"] = time.time()
