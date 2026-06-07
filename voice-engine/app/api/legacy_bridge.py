"""Re-exports the legacy endpoints under ``/legacy/*``.

The original ``main.py`` defined four endpoints (``/process``,
``/generate-response``, ``/AI-bot``, ``/AI-bot-voice``) that are still
in active use by the older WebChatBot. We keep them functional by
re-using the same handlers, but emit a deprecation log on every call.

NOTE: the legacy code instantiated its own service singletons. To
avoid loading the model twice we lean on the new ``app.state``
services where possible and fall back to the original implementations
for endpoints that don't have a clean modern equivalent yet.
"""

from __future__ import annotations

import logging
import os
import tempfile
from pathlib import Path

import httpx
from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse

logger = logging.getLogger(__name__)
router = APIRouter()


def _warn(endpoint: str) -> None:
    logger.warning(
        "DEPRECATED legacy endpoint %s — migrate to the new routes "
        "(/llm/respond, /extract, /stt, /tts, /ws/turn).",
        endpoint,
    )


@router.post("/process")
async def legacy_process(
    request: Request,
    text: str = Form(None),
    file: UploadFile = File(None),
):
    _warn("/legacy/process")
    if not text and not file:
        return JSONResponse({"error": "No input provided."}, status_code=400)

    if text:
        cleaned = text.strip()
        if not cleaned:
            return JSONResponse({"error": "Empty text."}, status_code=400)
        return {"input_type": "text", "text": cleaned}

    file_bytes = await file.read()
    try:
        tx = request.app.state.stt_service.transcribe_bytes(
            file_bytes, filename=file.filename or "audio.wav"
        )
        return {"input_type": "audio", "text": tx.text}
    except Exception as exc:  # noqa: BLE001
        return JSONResponse({"error": str(exc)}, status_code=500)


@router.post("/generate-response")
async def legacy_generate_response(request: Request, text: str = Form(...)):
    _warn("/legacy/generate-response")
    from app.domain.schemas import ChatMessage

    llm = request.app.state.llm_service
    try:
        result = await llm.chat([ChatMessage(role="user", content=text)])
    except Exception as exc:  # noqa: BLE001
        return JSONResponse(
            status_code=500, content={"error": f"Error generating response: {exc}"}
        )
    return {"intent": "conversation", "response": result.text}


@router.post("/AI-bot")
async def legacy_ai_bot(
    request: Request,
    file: UploadFile = File(None),
    text: str = Form(None),
):
    _warn("/legacy/AI-bot")
    if not file and not text:
        return JSONResponse(
            status_code=400, content={"error": "You must provide either a file or text"}
        )

    if file:
        file_bytes = await file.read()
        try:
            tx = request.app.state.stt_service.transcribe_bytes(
                file_bytes, filename=file.filename or "audio.wav"
            )
            user_text = tx.text
        except Exception as exc:  # noqa: BLE001
            return JSONResponse({"error": str(exc)}, status_code=500)
    else:
        user_text = (text or "").strip()

    from app.domain.schemas import ChatMessage

    try:
        result = await request.app.state.llm_service.chat(
            [ChatMessage(role="user", content=user_text)]
        )
    except Exception as exc:  # noqa: BLE001
        return JSONResponse({"error": str(exc)}, status_code=502)

    return {
        "process_result": {"input_type": "audio" if file else "text", "text": user_text},
        "generate_result": {"intent": "conversation", "response": result.text},
    }


@router.post("/AI-bot-voice")
async def legacy_ai_bot_voice(
    request: Request,
    file: UploadFile = File(None),
    text: str = Form(None),
    response_in_voice: bool = Form(False),
    speaker_wav: UploadFile = File(...),
):
    _warn("/legacy/AI-bot-voice")
    if not file and not text:
        raise HTTPException(status_code=400, detail="Either 'file' or 'text' must be provided.")

    # 1) get input text
    if file:
        file_bytes = await file.read()
        try:
            tx = request.app.state.stt_service.transcribe_bytes(
                file_bytes, filename=file.filename or "audio.wav"
            )
            user_text = tx.text
        except Exception as exc:  # noqa: BLE001
            raise HTTPException(status_code=500, detail=str(exc)) from exc
    else:
        user_text = (text or "").strip()

    if not user_text:
        raise HTTPException(status_code=400, detail="empty input text")

    # 2) generate reply
    from app.domain.schemas import ChatMessage

    try:
        reply = await request.app.state.llm_service.chat(
            [ChatMessage(role="user", content=user_text)]
        )
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"llm: {exc}") from exc

    if not response_in_voice:
        return {
            "input_text": user_text,
            "reply": reply.text,
            "intent": "conversation",
        }

    # 3) synth voice
    with tempfile.NamedTemporaryFile(delete=False, suffix=".wav") as tmp_speaker:
        tmp_speaker.write(await speaker_wav.read())
        tmp_speaker_path = tmp_speaker.name

    try:
        out_path = request.app.state.tts_service.synthesize_to_file(
            text=reply.text, speaker_wav_path=tmp_speaker_path, language="en"
        )
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=500, detail=f"tts: {exc}") from exc
    finally:
        Path(tmp_speaker_path).unlink(missing_ok=True)

    return FileResponse(
        out_path, media_type="audio/wav", filename=Path(out_path).name
    )
