"""DuckDB control-plane routes — the unified local data store.

Behind ``X-Internal-Secret`` (Laravel ↔ engine only). Replaces the MySQL
snapshot loader and the Qdrant query/ingest endpoints:

  * POST /duck/load-table   structured snapshot → SQL table
  * POST /duck/query        run a generated SELECT, return rows
  * POST /duck/load-docs    KB/crawler chunks → BM25 full-text table
  * POST /duck/search       BM25 keyword search, return passages
  * POST /duck/drop-source  remove a source's table(s) (delete lifecycle)
"""

from __future__ import annotations

import asyncio
import logging
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel

from app.api.deps import require_internal_secret

logger = logging.getLogger(__name__)
router = APIRouter(dependencies=[Depends(require_internal_secret)])


def _store(request: Request):
    store = getattr(request.app.state, "duck_store", None)
    if store is None:
        raise HTTPException(status_code=503, detail="duck store unavailable")
    return store


class LoadTableRequest(BaseModel):
    project_id: int
    source_id: int
    files: List[Dict[str, str]]


class QueryRequest(BaseModel):
    project_id: int
    sql: str


class LoadDocsRequest(BaseModel):
    project_id: int
    source_id: int
    chunks: List[Dict[str, Any]]


class SearchRequest(BaseModel):
    project_id: int
    source_ids: List[int]
    query: str
    top_k: int = 5


class DropSourceRequest(BaseModel):
    project_id: int
    source_id: int


@router.post("/duck/load-table")
async def duck_load_table(req: LoadTableRequest, request: Request) -> Dict[str, Any]:
    try:
        return await asyncio.to_thread(
            _store(request).load_table, req.project_id, req.source_id, req.files
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("duck load_table failed")
        raise HTTPException(status_code=502, detail=f"load_table failure: {exc}") from exc


@router.post("/duck/query")
async def duck_query(req: QueryRequest, request: Request) -> Dict[str, Any]:
    try:
        rows = await asyncio.to_thread(_store(request).query, req.project_id, req.sql)
    except Exception as exc:  # noqa: BLE001
        # Surface the DB error text so Laravel's repair loop can use it.
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    return {"rows": rows, "row_count": len(rows)}


@router.post("/duck/load-docs")
async def duck_load_docs(req: LoadDocsRequest, request: Request) -> Dict[str, Any]:
    try:
        return await asyncio.to_thread(
            _store(request).load_docs, req.project_id, req.source_id, req.chunks
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("duck load_docs failed")
        raise HTTPException(status_code=502, detail=f"load_docs failure: {exc}") from exc


@router.post("/duck/search")
async def duck_search(req: SearchRequest, request: Request) -> Dict[str, Any]:
    try:
        passages = await asyncio.to_thread(
            _store(request).search_docs, req.project_id, req.source_ids, req.query, req.top_k
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("duck search failed")
        raise HTTPException(status_code=502, detail=f"search failure: {exc}") from exc
    return {"passages": passages}


@router.post("/duck/drop-source")
async def duck_drop_source(req: DropSourceRequest, request: Request) -> Dict[str, Any]:
    try:
        await asyncio.to_thread(_store(request).drop_source, req.project_id, req.source_id)
    except Exception as exc:  # noqa: BLE001
        logger.exception("duck drop_source failed")
        raise HTTPException(status_code=502, detail=f"drop_source failure: {exc}") from exc
    return {"dropped": True}
