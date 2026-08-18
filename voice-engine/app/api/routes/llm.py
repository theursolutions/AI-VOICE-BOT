"""HTTP fallback for Laravel's ``ConversationManager``.

When the streaming WS path isn't available (e.g. server-rendered web
chat or a non-browser channel), Laravel POSTs to ``/llm/respond`` with
the full message history and gets back a JSON envelope. If
``respond_with`` includes audio we synthesise a wav and return its URL
relative to the FastAPI process.
"""

from __future__ import annotations

import json
import logging
import os
from typing import Any, Dict

from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse

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
            # Per-request credentials for an admin-configured brain. Absent for
            # every existing caller, which keeps using this service's own env.
            api_key=req.api_key,
            base_url=req.base_url,
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


@router.post("/llm/stream")
async def llm_stream(req: LLMRequest, request: Request):
    """Server-Sent Events variant of ``/llm/respond`` — text only.

    Exists because the LLM may be self-hosted on CPU, where a single reply can
    take 30-60s. Buffering that into one JSON response means the user watches a
    spinner the whole time; streaming shows the first words in a second or two.
    Total time is unchanged — only the perceived latency.

    Frame format (one JSON object per SSE ``data:`` line):
        {"type":"delta","text":"..."}                      incremental token(s)
        {"type":"final","text":"...","tokens_in":N,...}    complete reply
        {"type":"error","message":"..."}                   generation failed

    Audio is deliberately NOT synthesised here: XTTS needs the full sentence,
    so callers that want speech should use /llm/respond or the WS pipeline.
    """
    llm = request.app.state.llm_service

    async def events():
        try:
            async for frame in llm.stream_chat(req.messages, temperature=req.temperature):
                yield f"data: {json.dumps(frame, ensure_ascii=False)}\n\n"
        except Exception as exc:  # noqa: BLE001
            logger.exception("llm.stream_chat failed")
            yield "data: " + json.dumps({"type": "error", "message": str(exc)}) + "\n\n"

    return StreamingResponse(
        events(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache, no-transform",
            # Tells nginx (and any Caddy/HAProxy hop) not to buffer the body —
            # without it the whole point of streaming is lost at the proxy.
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
        },
    )
