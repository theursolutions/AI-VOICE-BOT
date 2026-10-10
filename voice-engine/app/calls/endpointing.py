"""End-of-speech detection for phone/SIP audio.

A cheap RMS energy gate on 16 kHz PCM16: once the caller has spoken for a
moment and then gone quiet for ~700 ms, the buffered audio is one
utterance. Providers deliver different frame sizes (Twilio and AudioSocket
20 ms, WebRTC 10–20 ms), so everything is counted in milliseconds.
"""

from __future__ import annotations

import audioop
from typing import Optional

SAMPLE_RATE = 16000
BYTES_PER_MS = SAMPLE_RATE * 2 // 1000  # PCM16 mono

# Thresholds carried over from the original Twilio handler (empirical).
SILENCE_RMS = 600          # PCM16 RMS below this counts as silence
MIN_VOICED_MS = 240        # less speech than this is a click or a cough
END_SILENCE_MS = 700       # this much silence after speech ends the turn
MAX_UTTERANCE_MS = 30_000  # never buffer more than this in one turn
PREROLL_MS = 300           # silence kept before speech so words aren't clipped


class Endpointer:
    def __init__(self, end_silence_ms: int = END_SILENCE_MS) -> None:
        self.end_silence_ms = end_silence_ms
        self._buf = bytearray()
        self._voiced_ms = 0.0
        self._silent_ms = 0.0
        #: Length of the caller's current uninterrupted speech — what
        #: barge-in is measured against.
        self.speech_run_ms = 0.0

    @property
    def in_speech(self) -> bool:
        """The caller has started an utterance that hasn't ended yet."""
        return self._voiced_ms >= MIN_VOICED_MS

    def feed(self, pcm16: bytes) -> Optional[bytes]:
        """Add a frame; returns a finished utterance, or None."""
        if not pcm16:
            return None
        ms = len(pcm16) / BYTES_PER_MS
        self._buf.extend(pcm16)

        if _is_voiced(pcm16):
            self._voiced_ms += ms
            self._silent_ms = 0.0
            self.speech_run_ms += ms
        else:
            self._silent_ms += ms
            self.speech_run_ms = 0.0

        if self._voiced_ms >= MIN_VOICED_MS and (
            self._silent_ms >= self.end_silence_ms
            or len(self._buf) >= MAX_UTTERANCE_MS * BYTES_PER_MS
        ):
            return self._take()

        if self._silent_ms >= self.end_silence_ms and self._voiced_ms < MIN_VOICED_MS:
            # Silence, or a blip too short to be speech, then quiet: drop it
            # but keep a little pre-roll so the next word's start survives.
            keep = PREROLL_MS * BYTES_PER_MS
            del self._buf[:-keep]
            self._voiced_ms = 0.0
        return None

    def flush(self) -> Optional[bytes]:
        """Whatever the caller said right before hanging up."""
        if self._voiced_ms >= MIN_VOICED_MS:
            return self._take()
        self._reset()
        return None

    def _take(self) -> bytes:
        utterance = bytes(self._buf)
        self._reset()
        return utterance

    def _reset(self) -> None:
        self._buf.clear()
        self._voiced_ms = 0.0
        self._silent_ms = 0.0
        self.speech_run_ms = 0.0


# Speech rises and falls with every syllable; hum, buzz, fans and hiss stay
# level. Measured (std of log frame loudness): noise 0.00–0.09, speech in
# 9 languages 0.59–0.90 (phone-quality clips, 2026-10-11).
MIN_SPEECH_MODULATION = 0.25


def looks_like_speech(pcm16: bytes) -> bool:
    """False for steady noise loud enough to pass the energy gate. Without
    this, a buzzing line was "heard" as "Hello, Umer Foods se baat kar raha
    hoon?" and answered, again and again, with nobody speaking."""
    import numpy as np

    samples = np.frombuffer(pcm16[: len(pcm16) // 2 * 2], dtype=np.int16).astype(np.float32)
    n = SAMPLE_RATE // 50  # 20 ms frames
    frames = samples[: len(samples) // n * n].reshape(-1, n)
    if not len(frames):
        return False
    rms = np.sqrt((frames ** 2).mean(axis=1))
    loud = rms[rms >= SILENCE_RMS]
    if len(loud) < MIN_VOICED_MS // 20:
        return True  # too short to judge; the endpointer already let it through
    return float(np.std(np.log(loud))) >= MIN_SPEECH_MODULATION


def _is_voiced(pcm16: bytes) -> bool:
    try:
        return audioop.rms(pcm16, 2) >= SILENCE_RMS
    except audioop.error:
        return False
