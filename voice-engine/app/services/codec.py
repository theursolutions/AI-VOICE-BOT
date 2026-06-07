"""μ-law / PCM16 codec helpers for the Twilio Media Streams channel.

Twilio's Media Streams API exchanges base64-encoded μ-law 8 kHz mono
frames (each frame is 160 samples = 20 ms). The rest of our pipeline
operates on PCM16 at 16 kHz (STT input) or 24 kHz (TTS output), so we
need to convert in both directions.

stdlib `audioop` covers all the cases we need:
  • ulaw2lin(data, 2)         → μ-law → PCM16  (same sample rate)
  • lin2ulaw(data, 2)         → PCM16 → μ-law  (same sample rate)
  • ratecv(data, 2, 1, in_sr, out_sr, state)
                              → resample PCM16 mono to a new rate

We expose two convenience wrappers — `ulaw_to_pcm16_16k` for STT prep
and `pcm16_24k_to_ulaw_8k` for TTS playback to the caller.
"""

from __future__ import annotations

import audioop
import base64


# ---------------------------------------------------------------------
# Inbound: Twilio → us (μ-law 8 kHz → PCM16 16 kHz)
# ---------------------------------------------------------------------

def ulaw_to_pcm16_16k(ulaw_bytes: bytes, ratecv_state=None):
    """Decode μ-law (8 kHz, mono) to PCM16 16 kHz.

    Returns (pcm16_bytes, new_ratecv_state). The state must be threaded
    through successive calls so resampling stays glitch-free across
    chunk boundaries.
    """
    if not ulaw_bytes:
        return b"", ratecv_state
    # μ-law (1 byte/sample) → linear PCM16 (2 bytes/sample), same sample rate (8 kHz).
    pcm8k = audioop.ulaw2lin(ulaw_bytes, 2)
    # Resample 8 kHz → 16 kHz.
    pcm16k, new_state = audioop.ratecv(pcm8k, 2, 1, 8000, 16000, ratecv_state)
    return pcm16k, new_state


def ulaw_b64_to_pcm16_16k(b64: str, ratecv_state=None):
    """Same as above but accepts the base64 string Twilio sends."""
    return ulaw_to_pcm16_16k(base64.b64decode(b64), ratecv_state)


# ---------------------------------------------------------------------
# Outbound: us → Twilio (PCM16 → μ-law 8 kHz)
# ---------------------------------------------------------------------

def pcm16_to_ulaw_8k(pcm_bytes: bytes, sample_rate: int, ratecv_state=None):
    """Encode PCM16 at any sample rate to μ-law 8 kHz mono.

    Returns (ulaw_bytes, new_ratecv_state). Twilio Media Streams expects
    160-sample chunks (20 ms @ 8 kHz). The caller is responsible for
    chunking — we just convert whatever you hand us.
    """
    if not pcm_bytes:
        return b"", ratecv_state
    if sample_rate != 8000:
        pcm8k, new_state = audioop.ratecv(pcm_bytes, 2, 1, sample_rate, 8000, ratecv_state)
    else:
        pcm8k, new_state = pcm_bytes, ratecv_state
    ulaw = audioop.lin2ulaw(pcm8k, 2)
    return ulaw, new_state


def pcm16_to_ulaw_b64(pcm_bytes: bytes, sample_rate: int, ratecv_state=None):
    """Same as above but returns the base64 string Twilio expects."""
    ulaw, new_state = pcm16_to_ulaw_8k(pcm_bytes, sample_rate, ratecv_state)
    return base64.b64encode(ulaw).decode("ascii"), new_state


# ---------------------------------------------------------------------
# Twilio frame chunking (160 samples = 20 ms @ 8 kHz)
# ---------------------------------------------------------------------

TWILIO_FRAME_BYTES = 160  # 160 μ-law bytes = 160 samples = 20 ms


def chunk_ulaw_for_twilio(ulaw_bytes: bytes):
    """Yield 160-byte frames suitable for Twilio Media Streams.

    Twilio will accept larger payloads but smaller is smoother — 20 ms
    frames keep the audio paced for the caller without jitter.
    """
    for i in range(0, len(ulaw_bytes), TWILIO_FRAME_BYTES):
        chunk = ulaw_bytes[i:i + TWILIO_FRAME_BYTES]
        if chunk:
            yield chunk
