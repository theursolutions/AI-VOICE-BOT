"""HTTP fallback for Laravel's ``ConversationManager``.

When the streaming WS path isn't available (e.g. server-rendered web
chat or a non-browser channel), Laravel POSTs to ``/llm/respond`` with
the full message history and gets back a JSON envelope. If
``respond_with`` includes audio we synthesise a wav and return its URL
relative to the FastAPI process.
"""

from __future__ import annotations

import logging
import os
from typing import Any, Dict

from fastapi import APIRouter, Depends, HTTPException, Request

from app.api.deps import require_internal_secret
from app.config import get_settings
from app.domain.schemas import LLMRequest, LLMResponse

logger = logging.getLogger(__name__)
router = APIRouter(dependencies=[Depends(require_internal_secret)])


@router.post("/llm/respond", response_model=LLMResponse)
async def llm_respond(req: LLMRequest, request: Request) -> LLMResponse:
    llm = request.app.state.llm_service
    tts = getattr(request.app.state, "tts_service", None)
    settings = get_settings()

    try:
        result = await llm.chat(
            req.messages,
            provider=req.provider,
            temperature=req.temperature,
            max_tokens=req.max_tokens,
            model=req.model,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("llm.chat failed")
        raise HTTPException(status_code=502, detail=f"llm failure: {exc}") from exc

    audio_url = None
    metadata: Dict[str, Any] = dict(req.metadata or {})
    metadata["model"] = result.model

    if req.respond_with in ("audio", "both") and tts is not None and result.text:
        speaker_wav = settings.default_speaker_wav
        # TODO: resolve voice_id → speaker_wav via Laravel /api/internal/voice/{id}
        if not os.path.exists(speaker_wav):
            logger.warning(
                "default_speaker_wav not found at %s; skipping audio synth", speaker_wav
            )
        else:
            try:
                wav_path = tts.synthesize_to_file(
                    text=result.text, speaker_wav_path=speaker_wav, language="en"
                )
                # The static mount at /voice_outputs/* is added in http.py
                audio_url = f"/voice_outputs/{os.path.basename(wav_path)}"
            except Exception as exc:  # noqa: BLE001
                logger.exception("TTS file synth failed")
                metadata["tts_error"] = str(exc)

    return LLMResponse(
        text=result.text,
        audio_url=audio_url,
        tokens_in=result.tokens_in,
        tokens_out=result.tokens_out,
        model=result.model,
        metadata=metadata,
    )
