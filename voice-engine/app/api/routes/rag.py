"""RAG ingest + query routes.

All endpoints sit behind ``X-Internal-Secret`` because they are part of
the control plane between Laravel and the voice-engine. The browser
never touches them directly.
"""

from __future__ import annotations

import logging
import uuid
from typing import Any, Dict, List

from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException, Request

from app.api.deps import require_internal_secret
from app.domain.schemas import (
    IngestRequest,
    IngestResponse,
    IngestStatus,
    Passage,
    RagQueryRequest,
    RagQueryResponse,
)
from app.services.ingest_service import (
    IngestService,
    get_status,
    mark_failed,
    register_job,
)

logger = logging.getLogger(__name__)
router = APIRouter(dependencies=[Depends(require_internal_secret)])


async def _run_website(
    ingest: IngestService,
    job_id: str,
    project_id: int,
    source_id: int,
    cfg: Dict[str, Any],
) -> None:
    url = str(cfg.get("url", "")).strip()
    if not url:
        mark_failed(job_id, "missing 'url' in config")
        return
    max_depth = cfg.get("max_depth")
    max_pages = cfg.get("max_pages")
    try:
        await ingest.ingest_website(
            project_id=project_id,
            source_id=source_id,
            url=url,
            max_depth=int(max_depth) if max_depth is not None else None,
            max_pages=int(max_pages) if max_pages is not None else None,
            job_id=job_id,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("background website ingest crashed")
        mark_failed(job_id, str(exc))


async def _run_documents(
    ingest: IngestService,
    job_id: str,
    project_id: int,
    source_id: int,
    cfg: Dict[str, Any],
) -> None:
    files_raw = cfg.get("files") or []
    files: List[Dict[str, str]] = []
    for f in files_raw:
        if not isinstance(f, dict):
            continue
        path = str(f.get("path", "")).strip()
        if not path:
            continue
        files.append(
            {
                "path": path,
                "original_name": str(f.get("original_name") or "").strip(),
            }
        )
    if not files:
        mark_failed(job_id, "no files provided in config")
        return
    try:
        await ingest.ingest_documents(
            project_id=project_id,
            source_id=source_id,
            files=files,
            job_id=job_id,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("background document ingest crashed")
        mark_failed(job_id, str(exc))


@router.post("/rag/ingest", response_model=IngestResponse)
async def rag_ingest(
    req: IngestRequest,
    background: BackgroundTasks,
    request: Request,
) -> IngestResponse:
    ingest: IngestService = request.app.state.ingest_service
    job_id = str(uuid.uuid4())
    register_job(job_id, req.source_id)

    if req.type == "website":
        background.add_task(
            _run_website, ingest, job_id, req.project_id, req.source_id, req.config or {}
        )
    elif req.type == "document":
        background.add_task(
            _run_documents, ingest, job_id, req.project_id, req.source_id, req.config or {}
        )
    else:  # pragma: no cover — Pydantic Literal blocks this already
        raise HTTPException(status_code=400, detail=f"unknown ingest type: {req.type}")

    return IngestResponse(job_id=job_id, status="queued")


@router.get("/rag/status/{job_id}", response_model=IngestStatus)
async def rag_status(job_id: str) -> IngestStatus:
    entry = get_status(job_id)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown job_id")
    return IngestStatus(
        job_id=entry["job_id"],
        source_id=int(entry.get("source_id", 0) or 0),
        status=entry.get("status", "queued"),
        progress=float(entry.get("progress", 0.0) or 0.0),
        chunks_indexed=int(entry.get("chunks_indexed", 0) or 0),
        pages_processed=int(entry.get("pages_processed", 0) or 0),
        errors=list(entry.get("errors") or []),
        error=entry.get("error"),
    )


@router.post("/rag/query", response_model=RagQueryResponse)
async def rag_query(req: RagQueryRequest, request: Request) -> RagQueryResponse:
    retrieval = request.app.state.retrieval_service
    try:
        passages: List[Passage] = await retrieval.retrieve(
            project_id=req.project_id,
            query=req.query,
            top_k=req.top_k,
            source_ids=req.source_ids,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("rag query failed")
        raise HTTPException(status_code=502, detail=f"rag query failure: {exc}") from exc
    return RagQueryResponse(passages=passages)
