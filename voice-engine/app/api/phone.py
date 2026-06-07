"""``/ws/phone`` — Twilio Media Streams adapter for phone calls.

Frame protocol (Twilio Media Streams v1):
    Client → server JSON frames:
        {event: "connected", protocol: "Call", version: "1.0.0"}
        {event: "start",     streamSid, start: {accountSid, callSid,
                                                tracks, customParameters, mediaFormat}}
        {event: "media",     streamSid, media: {track, chunk, timestamp,
                                                payload (base64 μ-law)}}
        {event: "mark",      streamSid, mark: {name}}      (echo of our mark)
        {event: "stop",      streamSid, stop: {accountSid, callSid}}
    Server → client JSON frames:
        {event: "media",    streamSid, media: {payload (base64 μ-law)}}
        {event: "mark",     streamSid, mark: {name}}       (request echo)
        {event: "clear",    streamSid}                     (drop queued audio)

Pipeline per call:
    1. Twilio handshake (connected + start) → grab streamSid + JWT-auth.
    2. Buffer inbound μ-law → run VAD-ish silence detection to gate
       turns. When the caller stops talking for ~700 ms, we treat the
       buffered audio as a complete utterance.
    3. Decode μ-law 8 k → PCM16 16 k → faster-whisper STT.
    4. Fetch per-project context from Laravel (DB / RAG / webhook tools).
    5. Stream LLM reply.
    6. TTS each sentence → PCM16 24 k → resample → μ-law 8 k → send
       back to Twilio as 20 ms frames.

This module reuses the existing services attached to the FastAPI app
state (stt_service, llm_service, tts_service, laravel_client) so the
phone channel gets the same data access as the web widget.
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import re
import time
from typing import Optional

from fastapi import APIRouter, Query, WebSocket, WebSocketDisconnect
from fastapi.websockets import WebSocketState

from app.domain.auth import AuthError, decode_token
from app.domain.schemas import ChatMessage
from app.services.codec import (
    chunk_ulaw_for_twilio,
    pcm16_to_ulaw_8k,
    ulaw_to_pcm16_16k,
)
from app.services.intent import is_chitchat

logger = logging.getLogger(__name__)
router = APIRouter()


# Sentence boundary for sentence-by-sentence TTS (same as /ws/turn).
_SENTENCE_BOUNDARY = re.compile(r"([\.!\?。！？]+)(\s|$)")

# Silence detection: Twilio frames are 160 samples (20 ms) @ 8 kHz μ-law.
# We collect frames into a buffer; when N consecutive low-energy frames
# arrive after at least one loud-enough frame, we treat that as an
# end-of-utterance and flush to STT.
SILENCE_THRESHOLD_RMS = 600        # μ-law RMS threshold (empirical)
SILENCE_FRAMES_TO_FLUSH = 35       # ~700 ms of silence triggers a turn
MIN_VOICED_FRAMES = 12             # ignore single-spike noise (~240 ms)


def _ulaw_frame_is_silent(pcm16_bytes: bytes) -> bool:
    """Cheap RMS energy on the decoded PCM. Returns True for ~quiet frames."""
    if not pcm16_bytes:
        return True
    import audioop
    try:
        rms = audioop.rms(pcm16_bytes, 2)
    except audioop.error:
        return True
    return rms < SILENCE_THRESHOLD_RMS


def _split_sentences(buffer: str):
    sentences = []
    last = 0
    for match in _SENTENCE_BOUNDARY.finditer(buffer):
        end = match.end()
        sentence = buffer[last:end].strip()
        if sentence:
            sentences.append(sentence)
        last = end
    return sentences, buffer[last:]


@router.websocket("/ws/phone")
async def ws_phone(websocket: WebSocket, token: str = Query(default="")) -> None:
    """Twilio Media Streams adapter.

    Auth note: Twilio STRIPS query-string parameters from the
    <Stream url="..."> URL it opens. The only way to receive a token
    is via <Parameter name="token" value="JWT"/> children in the
    TwiML — they arrive on the `start` event's `customParameters`
    field. So we accept the WS first, wait for `start`, then verify
    the JWT. Misauthenticated streams are closed immediately.

    The `token` query param is still accepted as a fallback for tools
    like `websocat` that want to test the endpoint outside Twilio.
    """
    origin = websocket.headers.get("origin")
    logger.info("ws/phone handshake from origin=%s", origin or "(none)")

    # Accept the WS up-front. Twilio sends `connected` → `start` —
    # the token lives in `start.customParameters.token`. If we tried
    # to decode_token() here it would fail and we'd never see start.
    await websocket.accept()

    app = websocket.app
    stt_service     = app.state.stt_service
    llm_service     = app.state.llm_service
    tts_service     = app.state.tts_service
    laravel_client  = app.state.laravel_client
    settings        = app.state.settings

    if not (stt_service and tts_service):
        logger.warning("ws/phone: STT or TTS service unavailable, closing")
        await websocket.close(code=4503, reason="voice services unavailable")
        return

    claims = None
    # If the caller already passed ?token=... (manual testing), try it.
    if token:
        try:
            claims = decode_token(token)
            logger.info("ws/phone authenticated via query token")
        except AuthError as exc:
            logger.info("ws/phone query token rejected: %s", exc)

    stream_sid: Optional[str] = None
    inbound_pcm = bytearray()
    inbound_resample_state = None
    voiced_frames = 0
    silent_frames = 0
    # Defaults until claims arrive (or stay as defaults if Twilio fails
    # to send a token at all and we fall back to global defaults).
    speaker_wav = settings.default_speaker_wav
    language    = "en"
    if claims is not None:
        speaker_wav = claims.speaker_wav or settings.default_speaker_wav
        language    = claims.language or "en"

    # Lock so STT/LLM/TTS for one utterance can't overlap with the next.
    turn_lock = asyncio.Lock()

    async def speak_back(text: str) -> None:
        """Synthesize `text`, send μ-law frames back to Twilio."""
        if not text or not stream_sid:
            return
        # Coqui XTTS yields PCM16 at 24 kHz. We convert to μ-law 8 kHz
        # in chunks so audio starts playing as soon as it's generated.
        out_state = None
        loop = asyncio.get_running_loop()
        queue: asyncio.Queue = asyncio.Queue()
        _DONE = object()

        def _produce():
            try:
                for chunk in tts_service.stream(text, speaker_wav, language=language):
                    if chunk:
                        loop.call_soon_threadsafe(queue.put_nowait, chunk)
            except Exception as exc:  # noqa: BLE001
                loop.call_soon_threadsafe(queue.put_nowait, exc)
            finally:
                loop.call_soon_threadsafe(queue.put_nowait, _DONE)

        producer = asyncio.create_task(asyncio.to_thread(_produce))
        try:
            while True:
                item = await queue.get()
                if item is _DONE:
                    break
                if isinstance(item, Exception):
                    logger.warning("ws/phone TTS error: %s", item)
                    break
                # PCM16 24 kHz → μ-law 8 kHz.
                ulaw_bytes, out_state = pcm16_to_ulaw_8k(item, 24000, out_state)
                # Pace into 20 ms frames so the caller hears smooth audio.
                for frame in chunk_ulaw_for_twilio(ulaw_bytes):
                    await websocket.send_json({
                        "event": "media",
                        "streamSid": stream_sid,
                        "media": {"payload": base64.b64encode(frame).decode("ascii")},
                    })
        finally:
            try:
                await producer
            except Exception:  # noqa: BLE001
                pass

    async def process_utterance(pcm_bytes: bytes) -> None:
        """Run STT → resolve context → LLM stream → TTS back to caller."""
        async with turn_lock:
            t0 = time.monotonic()
            try:
                tx = await asyncio.to_thread(
                    stt_service.transcribe_pcm16,
                    bytes(pcm_bytes),
                    16000,
                    language if language != "auto" else None,
                    False,
                )
                user_text = (tx.text or "").strip()
            except Exception as exc:  # noqa: BLE001
                logger.warning("ws/phone STT failed: %s", exc)
                return
            stt_ms = int((time.monotonic() - t0) * 1000)
            if not user_text:
                logger.info("ws/phone STT empty (%dms)", stt_ms)
                return
            logger.info("ws/phone STT (%dms): %s", stt_ms, user_text[:200])

            # Classifier decides whether this turn needs the resolver
            # chain (DB / RAG / webhook lookups) or is just chitchat.
            #   - Chitchat ("hi", "thanks", "how are you") → skip the
            #     filler AND skip resolve-context. Goes straight to the
            #     LLM with no context. Reply lands in 1-2s.
            #   - Data question → say "Let me check that." while we
            #     fetch context. Reply lands in 4-8s but caller knows
            #     the bot heard them.
            chitchat = is_chitchat(user_text)
            logger.info("ws/phone classified as %s", "chitchat" if chitchat else "data-question")

            filler_task = None
            context_block = ""
            if not chitchat:
                filler_task = asyncio.create_task(speak_back("Let me check that."))

                if claims is not None:
                    try:
                        context_block = await laravel_client.resolve_context(
                            project_id=claims.project_id,
                            session_id=claims.session_id,
                            user_text=user_text,
                        )
                    except Exception as exc:  # noqa: BLE001
                        logger.warning("ws/phone resolve_context failed: %s", exc)
                ctx_ms = int((time.monotonic() - t0) * 1000)
                logger.info("ws/phone context fetched (%dms): %d chars", ctx_ms, len(context_block))

                # Make sure the filler finished playing before we start
                # the real reply (avoids overlapping audio frames).
                try:
                    await filler_task
                except Exception:  # noqa: BLE001
                    pass

            messages = []
            if context_block:
                messages.append(ChatMessage(role="system", content=context_block))
            messages.append(ChatMessage(role="user", content=user_text))

            pending = ""
            aggregated = ""
            try:
                async for evt in llm_service.stream_chat(messages):
                    if evt["type"] == "delta":
                        delta = evt["text"]
                        aggregated += delta
                        pending += delta
                        sentences, remainder = _split_sentences(pending)
                        pending = remainder
                        for sentence in sentences:
                            await speak_back(sentence)
                if pending.strip():
                    await speak_back(pending.strip())
            except Exception as exc:  # noqa: BLE001
                logger.exception("ws/phone LLM stream failed")
                await speak_back("Sorry, I'm having trouble with that. Could you repeat?")

            logger.info("ws/phone turn complete: %d chars reply", len(aggregated))

    # ─────────────────────── main read loop ────────────────────────
    try:
        while True:
            if websocket.client_state != WebSocketState.CONNECTED:
                break
            try:
                raw = await websocket.receive_text()
            except WebSocketDisconnect:
                break
            try:
                msg = json.loads(raw)
            except json.JSONDecodeError:
                continue
            event = msg.get("event")

            if event == "connected":
                logger.info("ws/phone connected: %s", msg)
                continue

            if event == "start":
                start = msg.get("start", {}) or {}
                stream_sid = msg.get("streamSid") or start.get("streamSid")
                logger.info(
                    "ws/phone start: streamSid=%s callSid=%s tracks=%s",
                    stream_sid, start.get("callSid"), start.get("tracks"),
                )
                # Twilio delivers our JWT here, not in the URL.
                if claims is None:
                    params = start.get("customParameters", {}) or {}
                    twilio_token = params.get("token") or ""
                    if twilio_token:
                        try:
                            claims = decode_token(twilio_token)
                            speaker_wav = claims.speaker_wav or settings.default_speaker_wav
                            language    = claims.language or "en"
                            logger.info(
                                "ws/phone authenticated via Twilio Parameter — session=%s project=%s",
                                claims.session_id, claims.project_id,
                            )
                        except AuthError as exc:
                            logger.warning("ws/phone customParameter token rejected: %s", exc)
                            await websocket.close(code=4401, reason=str(exc))
                            return
                    else:
                        logger.warning("ws/phone no token in customParameters — closing")
                        await websocket.close(code=4401, reason="missing token")
                        return
                continue

            if event == "media":
                media = msg.get("media", {}) or {}
                payload_b64 = media.get("payload", "")
                if not payload_b64 or not stream_sid:
                    continue
                # Decode → PCM16 16 kHz, append to utterance buffer.
                try:
                    pcm16, inbound_resample_state = ulaw_to_pcm16_16k(
                        base64.b64decode(payload_b64), inbound_resample_state
                    )
                except Exception:  # noqa: BLE001
                    continue
                if not pcm16:
                    continue
                inbound_pcm.extend(pcm16)

                if _ulaw_frame_is_silent(pcm16):
                    silent_frames += 1
                    # Endpoint detection: enough silence after some voiced frames → flush.
                    if (voiced_frames >= MIN_VOICED_FRAMES and
                            silent_frames >= SILENCE_FRAMES_TO_FLUSH):
                        utterance = bytes(inbound_pcm)
                        inbound_pcm.clear()
                        voiced_frames = 0
                        silent_frames = 0
                        # Fire and forget; the lock inside serialises calls.
                        asyncio.create_task(process_utterance(utterance))
                else:
                    voiced_frames += 1
                    silent_frames = 0
                continue

            if event == "stop":
                logger.info("ws/phone stop: %s", msg.get("stop", {}))
                # Final flush if there's a pending utterance.
                if voiced_frames >= MIN_VOICED_FRAMES and inbound_pcm:
                    asyncio.create_task(process_utterance(bytes(inbound_pcm)))
                break

            # 'mark' echoes etc. — ignore.
    except WebSocketDisconnect:
        logger.info("ws/phone disconnected")
    except Exception:  # noqa: BLE001
        logger.exception("ws/phone fatal")
    finally:
        if websocket.client_state == WebSocketState.CONNECTED:
            await websocket.close()
