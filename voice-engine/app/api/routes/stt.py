"""``/stt`` — file upload transcription using faster-whisper."""

from __future__ import annotations

import logging
from typing import Optional

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile

from app.domain.schemas import STTResult

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/stt", response_model=STTResult)
async def stt(
    request: Request,
    file: UploadFile = File(...),
    language: Optional[str] = Form(default=None),
) -> STTResult:
    stt_service = request.app.state.stt_service
    try:
        data = await file.read()
        if not data:
            raise HTTPException(status_code=400, detail="empty audio upload")
        tx = stt_service.transcribe_bytes(
            data, filename=file.filename or "audio.wav", language=language
        )
        return STTResult(
            text=tx.text, language=tx.language, duration_ms=tx.duration_ms
        )
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        logger.exception("stt failed")
        raise HTTPException(status_code=500, detail=f"stt failure: {exc}") from exc
