"""Schema-driven JSON generation for admin control-plane tasks.

``/extract`` is hard-wired to the conversation lead schema. Lead Generation
needs the same JSON-mode call with its own schemas (prospect qualification,
outreach drafts), so this exposes ``LLMService.extract`` generically: the
caller sends a prompt and a JSON schema, and gets back the parsed object,
whether it conformed, and what the call cost (so admin can bill the brain
that served it, as with every other LLM path).

Per-request brain credentials (provider / model / api_key / base_url) are
honoured exactly as on ``/llm/respond``. Nothing here is persisted or logged
beyond a validation summary — prompts can carry tenant data.
"""

from __future__ import annotations

import logging
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, Field

from app.api.deps import require_internal_secret
from app.services.schema_check import validate

logger = logging.getLogger(__name__)
router = APIRouter(dependencies=[Depends(require_internal_secret)])


class StructuredRequest(BaseModel):
    prompt: str = Field(..., min_length=1, max_length=60_000)
    json_schema: Dict[str, Any]
    # What the call is for (e.g. "leadgen_qualify") — logging only.
    task: Optional[str] = Field(default=None, max_length=64)
    provider: Optional[str] = None
    model: Optional[str] = None
    api_key: Optional[str] = None
    base_url: Optional[str] = None


class StructuredResponse(BaseModel):
    data: Dict[str, Any] = {}
    valid: bool = False
    errors: List[str] = []
    tokens_in: int = 0
    tokens_out: int = 0
    model: str = ""


@router.post("/llm/structured", response_model=StructuredResponse)
async def llm_structured(req: StructuredRequest, request: Request) -> StructuredResponse:
    if req.json_schema.get("type") != "object":
        raise HTTPException(status_code=422, detail="json_schema must describe an object")

    llm = request.app.state.llm_service
    try:
        result = await llm.extract(
            req.prompt,
            req.json_schema,
            provider=req.provider,
            model=req.model,
            api_key=req.api_key,
            base_url=req.base_url,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("llm.structured failed task=%s", req.task)
        raise HTTPException(status_code=502, detail=f"llm failure: {exc}") from exc

    data = result.data if isinstance(result.data, dict) else {}
    errors = validate(data, req.json_schema) if data else ["$: model returned no JSON object"]
    if errors:
        logger.warning("llm.structured task=%s model=%s invalid: %s", req.task, result.model, errors[:5])

    return StructuredResponse(
        data=data,
        valid=not errors,
        errors=errors[:20],
        tokens_in=result.tokens_in,
        tokens_out=result.tokens_out,
        model=result.model,
    )
