"""Lead extraction endpoint."""

from __future__ import annotations

from fastapi import APIRouter, Depends, HTTPException, Request

from app.api.deps import require_internal_secret
from app.domain.schemas import ExtractRequest, ExtractResult

router = APIRouter(dependencies=[Depends(require_internal_secret)])


@router.post("/extract", response_model=ExtractResult)
async def extract(req: ExtractRequest, request: Request) -> ExtractResult:
    extractor = request.app.state.extractor_service
    try:
        return await extractor.extract(req)
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"extract failure: {exc}") from exc
