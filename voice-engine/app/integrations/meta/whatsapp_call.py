"""WhatsApp Business Calling — WebRTC media bridge (aiortc).

Flow (signaling done by Laravel, media terminated here):

    Meta ──connect webhook (SDP offer)──▶ Laravel
    Laravel ──POST /whatsapp/call/offer {token, call_id, sdp}──▶ THIS module
        · decode the session JWT (same claims as the WS/phone path)
        · build an aiortc RTCPeerConnection, set the remote offer
        · attach an outbound audio track, create the SDP answer
        · return {sdp, type} to Laravel
    Laravel ──pre_accept + accept (answer)──▶ Meta (Graph API)
    Meta ◀──── WebRTC media (Opus) ────▶ THIS module
        · inbound Opus → PCM16 16k → VAD → STT → resolve_context → LLM → TTS
        · TTS PCM16 24k → 48k → outbound Opus track
    Meta ──terminate webhook──▶ Laravel ──POST /whatsapp/call/terminate──▶ close

This reuses the EXACT services the Twilio phone path uses
(stt_service / llm_service / tts_service / laravel_client from app.state),
so a WhatsApp call gets the same brain + data access as every other channel.

Requirements: ``aiortc`` and ``av`` (PyAV). Install:  pip install aiortc av
Real calls need this host reachable for WebRTC media — set a TURN server
via WHATSAPP_TURN_URL/USERNAME/PASSWORD for production/NAT traversal.

NOTE: v1. The signaling contract and media wiring follow Meta's documented
WhatsApp Business Calling API + aiortc patterns, but this path can only be
exercised end-to-end against real Meta traffic, so expect to tune codec /
ICE / pacing once a live number is connected.
"""

from __future__ import annotations

import asyncio
import audioop
import fractions
import logging
import re
import time
from typing import Dict, Optional

from fastapi import APIRouter, Header, HTTPException, Request

from app.domain.auth import AuthError, decode_token
from app.domain.schemas import ChatMessage
from app.services.intent import is_chitchat

logger = logging.getLogger(__name__)
router = APIRouter()

# In-flight calls, keyed by Meta's call id.
_calls: "Dict[str, CallBridge]" = {}

_SENTENCE_BOUNDARY = re.compile(r"([\.!\?。！？]+)(\s|$)")

# Endpointing on the resampled 16k stream (mirrors phone.py heuristics).
SILENCE_THRESHOLD_RMS = 600
SILENCE_MS_TO_FLUSH = 700
MIN_VOICED_MS = 240


def _split_sentences(buffer: str):
    sentences, last = [], 0
    for m in _SENTENCE_BOUNDARY.finditer(buffer):
        s = buffer[last:m.end()].strip()
        if s:
            sentences.append(s)
        last = m.end()
    return sentences, buffer[last:]


def _internal_guard(secret_header: Optional[str]) -> None:
    from app.config import get_settings
    expected = get_settings().python_internal_secret
    if not secret_header or secret_header != expected:
        raise HTTPException(status_code=401, detail="bad internal secret")


# ---------------------------------------------------------------------------
# Outbound audio track — TTS audio is pushed in; aiortc pulls 20ms frames.
# ---------------------------------------------------------------------------
def _make_playback_track():
    """Built lazily so the module imports without aiortc/av installed."""
    import av  # noqa: WPS433
    from aiortc import MediaStreamTrack  # noqa: WPS433

    class PlaybackTrack(MediaStreamTrack):
        kind = "audio"

        def __init__(self, sample_rate: int = 48000) -> None:
            super().__init__()
            self.sample_rate = sample_rate
            self.samples_per_frame = int(sample_rate * 0.02)  # 20 ms
            self._buf = bytearray()        # int16 LE mono @ sample_rate
            self._lock = asyncio.Lock()
            self._pts = 0
            self._start: Optional[float] = None

        async def push(self, pcm16: bytes) -> None:
            async with self._lock:
                self._buf.extend(pcm16)

        async def recv(self):
            # Pace frames to wall-clock so playback runs at 1x speed.
            if self._start is None:
                self._start = time.monotonic()
            target = self._start + (self._pts / self.sample_rate)
            delay = target - time.monotonic()
            if delay > 0:
                await asyncio.sleep(delay)

            nbytes = self.samples_per_frame * 2
            async with self._lock:
                if len(self._buf) >= nbytes:
                    chunk = bytes(self._buf[:nbytes])
                    del self._buf[:nbytes]
                else:
                    chunk = bytes(self._buf) + b"\x00" * (nbytes - len(self._buf))
                    self._buf.clear()

            frame = av.AudioFrame(format="s16", layout="mono", samples=self.samples_per_frame)
            frame.sample_rate = self.sample_rate
            frame.pts = self._pts
            frame.time_base = fractions.Fraction(1, self.sample_rate)
            frame.planes[0].update(chunk)
            self._pts += self.samples_per_frame
            return frame

    return PlaybackTrack()


# ---------------------------------------------------------------------------
# Per-call bridge: owns the peer connection + the turn loop.
# ---------------------------------------------------------------------------
class CallBridge:
    def __init__(self, call_id: str, claims, app) -> None:
        self.call_id = call_id
        self.claims = claims
        self.app = app
        self.pc = None
        self.playback = None
        self._consumer: Optional[asyncio.Task] = None
        self._turn_lock = asyncio.Lock()
        self.speaker_wav = claims.speaker_wav or app.state.settings.default_speaker_wav
        self.language = claims.language or "en"

    async def answer(self, offer_sdp: str) -> dict:
        from aiortc import (  # noqa: WPS433
            RTCPeerConnection, RTCSessionDescription, RTCConfiguration, RTCIceServer,
        )
        settings = self.app.state.settings

        ice = [RTCIceServer(urls=[settings.whatsapp_stun_url])] if settings.whatsapp_stun_url else []
        if settings.whatsapp_turn_url:
            ice.append(RTCIceServer(
                urls=[settings.whatsapp_turn_url],
                username=settings.whatsapp_turn_username or None,
                credential=settings.whatsapp_turn_password or None,
            ))
        self.pc = RTCPeerConnection(configuration=RTCConfiguration(iceServers=ice))
        self.playback = _make_playback_track()
        self.pc.addTrack(self.playback)

        @self.pc.on("connectionstatechange")
        async def _on_state():  # noqa: WPS430
            logger.info("wa-call %s state=%s", self.call_id, self.pc.connectionState)
            if self.pc.connectionState in ("failed", "closed", "disconnected"):
                await self.close()

        @self.pc.on("track")
        def _on_track(track):  # noqa: WPS430
            logger.info("wa-call %s inbound track kind=%s", self.call_id, track.kind)
            if track.kind == "audio":
                self._consumer = asyncio.create_task(self._consume(track))

        await self.pc.setRemoteDescription(RTCSessionDescription(sdp=offer_sdp, type="offer"))
        answer = await self.pc.createAnswer()
        # aiortc completes ICE gathering during setLocalDescription, so the
        # returned SDP already carries candidates (non-trickle) — which is
        # what Meta expects in the answer.
        await self.pc.setLocalDescription(answer)
        return {"sdp": self.pc.localDescription.sdp, "type": self.pc.localDescription.type}

    async def _consume(self, track) -> None:
        """Pull inbound Opus → resample to 16k → endpoint → process turns."""
        import av  # noqa: WPS433
        resampler = av.AudioResampler(format="s16", layout="mono", rate=16000)
        buf = bytearray()
        voiced_ms = 0
        silent_ms = 0
        try:
            while True:
                frame = await track.recv()
                for r in resampler.resample(frame):
                    pcm = bytes(r.planes[0])[: r.samples * 2]
                    if not pcm:
                        continue
                    buf.extend(pcm)
                    frame_ms = (r.samples / 16000) * 1000
                    try:
                        rms = audioop.rms(pcm, 2)
                    except audioop.error:
                        rms = 0
                    if rms < SILENCE_THRESHOLD_RMS:
                        silent_ms += frame_ms
                        if voiced_ms >= MIN_VOICED_MS and silent_ms >= SILENCE_MS_TO_FLUSH:
                            utterance = bytes(buf)
                            buf.clear()
                            voiced_ms = 0
                            silent_ms = 0
                            asyncio.create_task(self._process(utterance))
                    else:
                        voiced_ms += frame_ms
                        silent_ms = 0
        except Exception as exc:  # noqa: BLE001 — track ends on hangup
            logger.info("wa-call %s inbound track closed: %s", self.call_id, exc)

    async def _process(self, pcm16_16k: bytes) -> None:
        async with self._turn_lock:
            stt = self.app.state.stt_service
            llm = self.app.state.llm_service
            laravel = self.app.state.laravel_client
            if not stt:
                return
            try:
                tx = await asyncio.to_thread(
                    stt.transcribe_pcm16, pcm16_16k, 16000,
                    self.language if self.language != "auto" else None, False,
                )
                user_text = (tx.text or "").strip()
            except Exception as exc:  # noqa: BLE001
                logger.warning("wa-call %s STT failed: %s", self.call_id, exc)
                return
            if not user_text:
                return
            logger.info("wa-call %s STT: %s", self.call_id, user_text[:160])

            context_block = ""
            if not is_chitchat(user_text):
                await self.speak("Let me check that.")
                try:
                    context_block = await laravel.resolve_context(
                        project_id=self.claims.project_id,
                        session_id=self.claims.session_id,
                        user_text=user_text,
                    )
                except Exception as exc:  # noqa: BLE001
                    logger.warning("wa-call %s resolve_context failed: %s", self.call_id, exc)

            messages = []
            if context_block:
                messages.append(ChatMessage(role="system", content=context_block))
            messages.append(ChatMessage(role="user", content=user_text))

            pending = ""
            try:
                async for evt in llm.stream_chat(messages):
                    if evt.get("type") == "delta":
                        pending += evt["text"]
                        sentences, pending = _split_sentences(pending)
                        for s in sentences:
                            await self.speak(s)
                if pending.strip():
                    await self.speak(pending.strip())
            except Exception:  # noqa: BLE001
                logger.exception("wa-call %s LLM stream failed", self.call_id)
                await self.speak("Sorry, I'm having trouble with that. Could you repeat?")

    async def speak(self, text: str) -> None:
        """Synthesize text → push 48k PCM to the outbound track."""
        tts = self.app.state.tts_service
        if not text or not tts or not self.playback:
            return
        loop = asyncio.get_running_loop()
        queue: asyncio.Queue = asyncio.Queue()
        _DONE = object()

        def _produce():
            try:
                for chunk in tts.stream(text, self.speaker_wav, language=self.language):
                    if chunk:
                        loop.call_soon_threadsafe(queue.put_nowait, chunk)
            except Exception as exc:  # noqa: BLE001
                loop.call_soon_threadsafe(queue.put_nowait, exc)
            finally:
                loop.call_soon_threadsafe(queue.put_nowait, _DONE)

        producer = asyncio.create_task(asyncio.to_thread(_produce))
        rate_state = None
        try:
            while True:
                item = await queue.get()
                if item is _DONE:
                    break
                if isinstance(item, Exception):
                    logger.warning("wa-call %s TTS error: %s", self.call_id, item)
                    break
                # XTTS PCM16 @ 24k → 48k for the WebRTC track.
                pcm48, rate_state = audioop.ratecv(item, 2, 1, 24000, 48000, rate_state)
                await self.playback.push(pcm48)
        finally:
            try:
                await producer
            except Exception:  # noqa: BLE001
                pass

    async def close(self) -> None:
        if self._consumer:
            self._consumer.cancel()
        if self.pc:
            try:
                await self.pc.close()
            except Exception:  # noqa: BLE001
                pass
        _calls.pop(self.call_id, None)
        logger.info("wa-call %s closed", self.call_id)


# ---------------------------------------------------------------------------
# HTTP endpoints (called by Laravel; secured by the internal secret).
# ---------------------------------------------------------------------------
@router.post("/whatsapp/call/offer")
async def call_offer(request: Request, x_internal_secret: str = Header(default="")):
    _internal_guard(x_internal_secret)
    body = await request.json()
    token = body.get("token") or ""
    call_id = str(body.get("call_id") or "")
    sdp = body.get("sdp") or ""
    if not call_id or not sdp:
        raise HTTPException(status_code=422, detail="call_id and sdp required")

    try:
        claims = decode_token(token)
    except AuthError as exc:
        raise HTTPException(status_code=401, detail=f"bad token: {exc}")

    app = request.app
    if not (app.state.stt_service and app.state.tts_service):
        raise HTTPException(status_code=503, detail="voice services unavailable")

    try:
        bridge = CallBridge(call_id, claims, app)
        answer = await bridge.answer(sdp)
    except ModuleNotFoundError as exc:
        logger.error("wa-call: aiortc/av not installed: %s", exc)
        raise HTTPException(status_code=501, detail="aiortc/av not installed")
    except Exception as exc:  # noqa: BLE001
        logger.exception("wa-call %s answer failed", call_id)
        raise HTTPException(status_code=500, detail=str(exc))

    _calls[call_id] = bridge
    logger.info("wa-call %s answered (session=%s project=%s)",
                call_id, claims.session_id, claims.project_id)
    return answer


@router.post("/whatsapp/call/terminate")
async def call_terminate(request: Request, x_internal_secret: str = Header(default="")):
    _internal_guard(x_internal_secret)
    body = await request.json()
    call_id = str(body.get("call_id") or "")
    bridge = _calls.get(call_id)
    if bridge:
        await bridge.close()
        return {"terminated": True, "call_id": call_id}
    return {"terminated": False, "call_id": call_id, "reason": "not found"}
