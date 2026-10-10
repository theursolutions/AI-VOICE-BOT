"""``/tts`` — synthesise speech from text and return a wav file."""

from __future__ import annotations

import asyncio
import logging
import os
import tempfile
import uuid
import wave
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse

from app.config import get_settings
from app.services.google_tts import GoogleTTSError

logger = logging.getLogger(__name__)
router = APIRouter()


@router.post("/tts")
async def tts(
    request: Request,
    text: str = Form(...),
    language: str = Form(default="en"),
    speaker_wav: UploadFile | None = File(default=None),
    # provider=google + voice=<name> speaks with a Google voice (voice
    # previews, Twilio greetings); otherwise the cloned/local XTTS voice.
    provider: str = Form(default=""),
    voice: str = Form(default=""),
):
    tts_service = request.app.state.tts_service
    settings = get_settings()

    if provider == "google":
        return await _google_tts(request, text, voice, settings)

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


async def _google_tts(request: Request, text: str, voice: str, settings) -> FileResponse:
    google = getattr(request.app.state, "google_tts", None)
    if google is None:
        raise HTTPException(status_code=503, detail="Google voices are not configured (GEMINI_API_KEY)")
    if not voice:
        raise HTTPException(status_code=422, detail="voice is required for provider=google")
    try:
        pcm = await asyncio.to_thread(google.synthesize, text, voice)
    except GoogleTTSError as exc:
        # 429 = quota (free tier allows ~3/min) — say so, don't hide it as a 500.
        status = 429 if "HTTP 429" in str(exc) else 502
        raise HTTPException(status_code=status, detail=f"Google voice failed: {exc}") from exc

    out_dir = Path(settings.voice_output_dir) / "google"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_path = out_dir / f"{uuid.uuid4().hex}.wav"
    with wave.open(str(out_path), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(google.sample_rate)
        w.writeframes(pcm)
    return FileResponse(str(out_path), media_type="audio/wav", filename=out_path.name)
