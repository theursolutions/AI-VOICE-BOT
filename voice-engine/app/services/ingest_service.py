"""Orchestrates extract → chunk → store for KB documents + crawled sites.

Storage is now DuckDB full-text (BM25) instead of embeddings + Qdrant: we
reuse the same parsers (``document_ingest``), crawler (``website_ingest``)
and chunker, but accumulate the chunks and write them to the project's
DuckDB file via :meth:`DuckStore.load_docs` (which (re)creates the
``docs_<source_id>`` table + BM25 index). No embedding model, no vector DB.

Status is held in a process-local dict keyed by ``job_id``. Cleared on
restart; Laravel re-syncs if it cares.
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
from app.services.website_ingest import crawl_and_extract

logger = logging.getLogger(__name__)


@dataclass
class IngestReport:
    chunks_indexed: int = 0
    pages_processed: int = 0
    errors: List[str] = field(default_factory=list)


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
    """Extract + chunk KB/website content into the DuckDB BM25 store."""

    def __init__(self, duck_store: Any) -> None:
        self.duck = duck_store
        self._settings = get_settings()

    def _chunk(self, text: str) -> List[str]:
        return chunk_text(
            text,
            max_tokens=self._settings.rag_chunk_max_tokens,
            overlap=self._settings.rag_chunk_overlap,
        )

    async def _store(self, project_id: int, source_id: int, chunks: List[Dict[str, Any]], report: IngestReport) -> None:
        if self.duck is None:
            report.errors.append("duck store unavailable")
            return
        try:
            res = await asyncio.to_thread(self.duck.load_docs, project_id, source_id, chunks)
            report.chunks_indexed = int(res.get("row_count", len(chunks)))
        except Exception as exc:  # noqa: BLE001
            logger.exception("duck load_docs failed")
            report.errors.append(f"store failed: {exc}")

    def _finalize(self, status: Optional[Dict[str, Any]], report: IngestReport) -> None:
        if status is None:
            return
        status["status"] = "failed" if report.errors and report.chunks_indexed == 0 else "done"
        status["error"] = "; ".join(report.errors) if report.errors else None
        status["errors"] = list(report.errors)
        status["chunks_indexed"] = report.chunks_indexed
        status["pages_processed"] = report.pages_processed
        status["progress"] = 1.0
        status["finished_at"] = time.time()

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

        chunks: List[Dict[str, Any]] = []
        try:
            async for page in crawl_and_extract(url, max_depth=depth, max_pages=pages_cap):
                report.pages_processed += 1
                for ch in self._chunk(page.text):
                    chunks.append({"text": ch, "citation": {"url": page.url, "title": page.title}})
                if status is not None:
                    status["pages_processed"] = report.pages_processed
                    status["progress"] = min(0.9, report.pages_processed / max(pages_cap, 1))
        except Exception as exc:  # noqa: BLE001
            logger.exception("website ingest crashed")
            report.errors.append(f"crawl failed: {exc}")

        await self._store(project_id, source_id, chunks, report)
        self._finalize(status, report)
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

        chunks: List[Dict[str, Any]] = []
        total = max(len(files), 1)
        for idx, entry in enumerate(files, start=1):
            path = entry.get("path", "")
            original = entry.get("original_name") or path
            try:
                async for extracted in parse_file(path, original_name=original):
                    for ch in self._chunk(extracted.text):
                        chunks.append({"text": ch, "citation": dict(extracted.citation)})
                report.pages_processed += 1
            except Exception as exc:  # noqa: BLE001
                logger.exception("document parse failed for %s", path)
                report.errors.append(f"{original}: {exc}")
            if status is not None:
                status["pages_processed"] = report.pages_processed
                status["progress"] = min(0.9, idx / total)

        await self._store(project_id, source_id, chunks, report)
        self._finalize(status, report)
        return report


def register_job(job_id: str, source_id: int) -> Dict[str, Any]:
    return _init_status(job_id, source_id)


def mark_failed(job_id: str, error: str) -> None:
    entry = _STATUS.get(job_id)
    if entry is None:
        return
    entry["status"] = "failed"
    entry["error"] = error
    entry["errors"] = list(entry.get("errors", [])) + [error]
    entry["finished_at"] = time.time()
