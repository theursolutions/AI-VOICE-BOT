"""HTTP API of the places worker. Run: ``uvicorn app.places.app:app --port 8010``.

Every route except /healthz requires the same ``X-Internal-Secret`` the voice
engine uses; only the admin app calls this service.

Long work never blocks a request: building an area or a country index starts
in the background and the response says ``loading``; the admin polls (its
search job re-queues itself with a delay) until ``ready``.
"""

from __future__ import annotations

import logging
import math
import os
from datetime import date, datetime
from decimal import Decimal
from typing import Any, Dict, List, Optional

from fastapi import Depends, FastAPI, HTTPException
from pydantic import BaseModel, Field

from app.api.deps import require_internal_secret
from app.places.store import PlacesError, PlacesStore

logger = logging.getLogger(__name__)

app = FastAPI(title="places-worker", docs_url=None, redoc_url=None)
_store: Optional[PlacesStore] = None


def store() -> PlacesStore:
    global _store
    if _store is None:
        _store = PlacesStore(
            cache_dir=os.getenv("PLACES_CACHE_DIR", "/app/places_cache"),
            base=os.getenv("OVERTURE_BASE", "s3://overturemaps-us-west-2/release"),
            release=os.getenv("OVERTURE_RELEASE") or None,
            max_builds=int(os.getenv("PLACES_MAX_BUILDS", "2")),
        )
    return _store


def _jsonable(v: Any) -> Any:
    """DuckDB rows carry Decimals, dates and NaN floats JSON can't hold."""
    if isinstance(v, dict):
        return {k: _jsonable(x) for k, x in v.items()}
    if isinstance(v, (list, tuple)):
        return [_jsonable(x) for x in v]
    if isinstance(v, Decimal):
        return float(v)
    if isinstance(v, float) and (math.isnan(v) or math.isinf(v)):
        return None
    if isinstance(v, (date, datetime)):
        return v.isoformat()
    return v


def _bad(exc: PlacesError) -> HTTPException:
    return HTTPException(status_code=422, detail=str(exc))


class AreaReq(BaseModel):
    bbox: List[float] = Field(..., min_length=4, max_length=4)


class SearchReq(BaseModel):
    area_id: str
    filters: Dict[str, Any] = {}
    offset: int = 0
    limit: int = Field(100, ge=1, le=500)


class GeocodeReq(BaseModel):
    country: str
    q: str = ""
    limit: int = Field(10, ge=1, le=25)


@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.get("/places/release", dependencies=[Depends(require_internal_secret)])
def release():
    try:
        return {"release": store().release()}
    except PlacesError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc


@app.post("/places/geocode", dependencies=[Depends(require_internal_secret)])
def geocode(req: GeocodeReq):
    try:
        return _jsonable(store().geocode(req.country, req.q, req.limit))
    except PlacesError as exc:
        raise _bad(exc) from exc


@app.post("/places/area", dependencies=[Depends(require_internal_secret)])
def area(req: AreaReq):
    try:
        return store().ensure_area(req.bbox)
    except PlacesError as exc:
        raise _bad(exc) from exc


@app.get("/places/area/{area_id}/categories", dependencies=[Depends(require_internal_secret)])
def categories(area_id: str, limit: int = 300):
    try:
        st = store().area_status(area_id)
        if st["status"] != "ready":
            return st
        return {**st, "categories": store().categories(area_id, limit)}
    except PlacesError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc


@app.post("/places/search", dependencies=[Depends(require_internal_secret)])
def search(req: SearchReq):
    try:
        return _jsonable({"status": "ready", **store().search(req.area_id, req.filters, req.offset, req.limit)})
    except PlacesError as exc:
        raise HTTPException(status_code=404, detail=str(exc)) from exc
