"""Speech-to-text service backed by ``faster-whisper``.

The legacy implementation used the openai ``whisper`` package which
loads the entire audio into memory and only supports file-based input.
``faster-whisper`` exposes a CTranslate2 backend that is roughly 4x
faster on CPU and supports streaming-friendly numpy buffers + a built-in
VAD filter.
"""

from __future__ import annotations

import io
import logging
import os
import tempfile
from dataclasses import dataclass
from typing import Iterable, List, Optional, Tuple

import numpy as np

logger = logging.getLogger(__name__)


@dataclass
class Transcription:
    text: str
    language: Optional[str]
    duration_ms: int


class STTService:
    """Thin wrapper around :class:`faster_whisper.WhisperModel`.

    Lazily loads the model on first use so the FastAPI lifespan can
    decide whether to warm it up at boot or defer.
    """

    def __init__(
        self,
        model_name: str = "base",
        device: str = "cpu",
        compute_type: str = "int8",
    ) -> None:
        self.model_name = model_name
        self.device = device
        self.compute_type = compute_type
        self._model = None  # type: ignore[assignment]

    # -- model lifecycle ----------------------------------------------------
    def load(self) -> None:
        if self._model is not None:
            return
        from faster_whisper import WhisperModel  # local import keeps boot light

        logger.info(
            "Loading faster-whisper model=%s device=%s compute=%s",
            self.model_name,
            self.device,
            self.compute_type,
        )
        self._model = WhisperModel(
            self.model_name,
            device=self.device,
            compute_type=self.compute_type,
        )

    @property
    def model(self):
        if self._model is None:
            self.load()
        return self._model

    # -- transcription ------------------------------------------------------
    def transcribe_file(
        self,
        file_path: str,
        language: Optional[str] = None,
        vad_filter: bool = True,
    ) -> Transcription:
        segments, info = self.model.transcribe(
            file_path,
            language=language,
            vad_filter=vad_filter,
            beam_size=1,
        )
        text_parts: List[str] = []
        for seg in segments:
            text_parts.append(seg.text)
        text = " ".join(p.strip() for p in text_parts).strip()
        return Transcription(
            text=text,
            language=getattr(info, "language", None),
            duration_ms=int((getattr(info, "duration", 0.0) or 0.0) * 1000),
        )

    def transcribe_bytes(
        self,
        audio_bytes: bytes,
        filename: str = "audio.wav",
        language: Optional[str] = None,
    ) -> Transcription:
        ext = (os.path.splitext(filename)[1] or ".wav").lower()
        with tempfile.NamedTemporaryFile(suffix=ext, delete=False) as tmp:
            tmp.write(audio_bytes)
            tmp_path = tmp.name
        try:
            return self.transcribe_file(tmp_path, language=language)
        finally:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

    def transcribe_pcm16(
        self,
        pcm: bytes,
        sample_rate: int = 16000,
        language: Optional[str] = None,
        vad_filter: bool = True,
    ) -> Transcription:
        """Transcribe raw little-endian PCM16 mono audio without writing
        to disk. Used by the WebSocket streaming pipeline once the
        client emits ``audio.end``.
        """

        if not pcm:
            return Transcription(text="", language=None, duration_ms=0)

        audio = np.frombuffer(pcm, dtype=np.int16).astype(np.float32) / 32768.0
        if sample_rate != 16000:
            # faster-whisper expects 16kHz; resample with simple linear interpolation
            try:
                import librosa  # type: ignore

                audio = librosa.resample(
                    audio, orig_sr=sample_rate, target_sr=16000
                )
            except Exception:  # noqa: BLE001 — best-effort fallback
                logger.warning(
                    "librosa resample failed; passing raw audio at sr=%s", sample_rate
                )

        segments, info = self.model.transcribe(
            audio,
            language=language,
            vad_filter=vad_filter,
            beam_size=1,
        )
        text = " ".join(seg.text.strip() for seg in segments).strip()
        return Transcription(
            text=text,
            language=getattr(info, "language", None),
            duration_ms=int((getattr(info, "duration", 0.0) or 0.0) * 1000),
        )


# ---------------------------------------------------------------------------
# WebRTC VAD chunking helper
# ---------------------------------------------------------------------------


class VADChunker:
    """Partition an incoming PCM16 stream into utterance-sized chunks
    using ``webrtcvad``.

    The WS handler appends each ``audio.chunk`` payload via
    :meth:`feed`, and consumes finalised utterances from
    :meth:`pop_finalised`. When ``audio.end`` arrives, call
    :meth:`flush` to drain whatever remains.
    """

    def __init__(
        self,
        sample_rate: int = 16000,
        frame_ms: int = 30,
        aggressiveness: int = 2,
        silence_ms: int = 600,
    ) -> None:
        if sample_rate not in (8000, 16000, 32000, 48000):
            raise ValueError("webrtcvad requires sample_rate in {8000,16000,32000,48000}")
        if frame_ms not in (10, 20, 30):
            raise ValueError("webrtcvad requires frame_ms in {10,20,30}")
        self.sample_rate = sample_rate
        self.frame_bytes = int(sample_rate * (frame_ms / 1000.0)) * 2  # int16 mono
        self.silence_frames = max(1, silence_ms // frame_ms)
        self._buf = bytearray()
        self._utterance = bytearray()
        self._silence_run = 0
        self._finalised: List[bytes] = []

        try:
            import webrtcvad  # type: ignore

            self._vad = webrtcvad.Vad(aggressiveness)
        except Exception:  # noqa: BLE001
            logger.warning("webrtcvad unavailable; VAD disabled (everything = speech)")
            self._vad = None  # type: ignore[assignment]

    def feed(self, pcm: bytes) -> None:
        self._buf.extend(pcm)
        while len(self._buf) >= self.frame_bytes:
            frame = bytes(self._buf[: self.frame_bytes])
            del self._buf[: self.frame_bytes]
            self._consume_frame(frame)

    def _consume_frame(self, frame: bytes) -> None:
        if self._vad is None:
            self._utterance.extend(frame)
            return

        is_speech = self._vad.is_speech(frame, self.sample_rate)
        if is_speech:
            self._utterance.extend(frame)
            self._silence_run = 0
        else:
            if self._utterance:
                self._utterance.extend(frame)
                self._silence_run += 1
                if self._silence_run >= self.silence_frames:
                    self._finalised.append(bytes(self._utterance))
                    self._utterance.clear()
                    self._silence_run = 0

    def pop_finalised(self) -> List[bytes]:
        out, self._finalised = self._finalised, []
        return out

    def flush(self) -> bytes:
        # Drain remaining buffer and any in-flight utterance.
        if self._buf:
            self._utterance.extend(self._buf)
            self._buf.clear()
        leftover = bytes(self._utterance)
        self._utterance.clear()
        self._silence_run = 0
        return leftover
