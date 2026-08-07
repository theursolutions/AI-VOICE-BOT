"""``/audio/transcode`` — remux/re-encode audio to a target container.

Primary use: agent voice notes recorded in the browser arrive as
``audio/webm`` (Opus), but WhatsApp voice messages require
``audio/ogg`` (Opus). Since the codec is already Opus this is mostly a
container swap, done with PyAV (bundled ffmpeg libs — no ffmpeg CLI
needed).

Returns the transcoded bytes directly with the right Content-Type so the
caller (Laravel ChatController) can upload them straight to Meta.
"""

from __future__ import annotations

import io
import logging
from typing import Optional

from fastapi import APIRouter, File, Form, HTTPException, Response, UploadFile

logger = logging.getLogger(__name__)
router = APIRouter()

_FORMATS = {
    "ogg": ("ogg", "libopus", "audio/ogg"),   # WhatsApp voice note
    "mp3": ("mp3", "libmp3lame", "audio/mpeg"),
    "m4a": ("ipod", "aac", "audio/mp4"),
}


@router.post("/audio/transcode")
async def transcode(
    file: UploadFile = File(...),
    target: str = Form(default="ogg"),
) -> Response:
    fmt = _FORMATS.get(target.lower())
    if not fmt:
        raise HTTPException(status_code=422, detail=f"unsupported target: {target}")
    container_fmt, codec, content_type = fmt

    data = await file.read()
    if not data:
        raise HTTPException(status_code=400, detail="empty audio upload")

    try:
        import av  # local import — optional dep
    except Exception as exc:  # noqa: BLE001
        logger.error("av (PyAV) not installed: %s", exc)
        raise HTTPException(status_code=501, detail="transcoding unavailable (PyAV missing)")

    try:
        out_bytes = _transcode(av, data, container_fmt, codec)
    except Exception as exc:  # noqa: BLE001
        logger.exception("transcode failed")
        raise HTTPException(status_code=500, detail=f"transcode failure: {exc}")

    return Response(content=out_bytes, media_type=content_type)


def _transcode(av, data: bytes, container_fmt: str, codec: str) -> bytes:
    """Decode any input audio and re-encode to (container_fmt, codec) mono 48k."""
    in_buf = io.BytesIO(data)
    out_buf = io.BytesIO()

    inp = av.open(in_buf)
    out = av.open(out_buf, mode="w", format=container_fmt)
    try:
        ostream = out.add_stream(codec, rate=48000)
        # Opus/most encoders want float-planar mono for voice.
        try:
            ostream.format = "fltp"
        except Exception:  # noqa: BLE001 — some builds infer it
            pass

        resampler = av.AudioResampler(format="fltp", layout="mono", rate=48000)

        for frame in inp.decode(audio=0):
            frame.pts = None
            for rframe in _as_list(resampler.resample(frame)):
                for packet in ostream.encode(rframe):
                    out.mux(packet)
        # Flush resampler + encoder.
        for rframe in _as_list(resampler.resample(None)):
            for packet in ostream.encode(rframe):
                out.mux(packet)
        for packet in ostream.encode(None):
            out.mux(packet)
    finally:
        out.close()
        inp.close()

    return out_buf.getvalue()


def _as_list(frames) -> list:
    """PyAV's resample() returns a frame or a list depending on version."""
    if frames is None:
        return []
    return frames if isinstance(frames, list) else [frames]
