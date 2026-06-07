"""``/tts`` — synthesise speech from text and return a wav file."""

from __future__ import annotations

import logging
import os
import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse

from app.config import get_settings

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/tts")
async def tts(
    request: Request,
    text: str = Form(...),
    language: str = Form(default="en"),
    speaker_wav: UploadFile | None = File(default=None),
):
    tts_service = request.app.state.tts_service
    settings = get_settings()

    speaker_path: str | None = None
    cleanup_speaker = False
    try:
        if speaker_wav is not None:
            with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
                tmp.write(await speaker_wav.read())
                speaker_path = tmp.name
            cleanup_speaker = True
        else:
            speaker_path = settings.default_speaker_wav
            if not os.path.exists(speaker_path):
                raise HTTPException(
                    status_code=400,
                    detail="no speaker_wav provided and DEFAULT_SPEAKER_WAV not found",
                )

        out_path = tts_service.synthesize_to_file(
            text=text, speaker_wav_path=speaker_path, language=language
        )
        return FileResponse(
            out_path, media_type="audio/wav", filename=Path(out_path).name
        )
    except HTTPException:
        raise
    except Exception as exc:  # noqa: BLE001
        logger.exception("tts failed")
        raise HTTPException(status_code=500, detail=f"tts failure: {exc}") from exc
    finally:
        if cleanup_speaker and speaker_path:
            try:
                os.unlink(speaker_path)
            except OSError:
                pass
