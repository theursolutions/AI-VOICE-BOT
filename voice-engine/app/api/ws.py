"""``/ws/turn`` — full-duplex audio + text WebSocket.

Frame protocol is defined in ``docs/API_CONTRACT.md`` and mirrored as
Pydantic models in :mod:`app.domain.schemas`.

Pipeline per turn:

1. Client opens WS with ``?token=<JWT>``. We verify and pull
   ``session_id``, ``project_id``, ``voice_id`` from the claims.
2. Client emits one of: ``text`` (skip STT), or
   ``audio.start`` → N ``audio.chunk`` → ``audio.end``.
3. We run STT (chunked via VAD) and emit ``stt.partial``/``stt.final``
   frames.
4. We stream Gemini and forward ``llm.delta`` frames; buffered text is
   flushed to TTS sentence-by-sentence.
5. TTS yields PCM16 chunks which we base64-encode and emit as
   ``audio.chunk`` frames, terminated by ``audio.end``.
6. We emit ``turn.end`` and fire-and-forget the
   ``/api/internal/turn-completed`` webhook.

This file intentionally keeps the per-turn state inside a small
:class:`TurnState` dataclass so future bargein logic / cancellation
hooks have one place to land.
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import re
import time
import wave
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

from fastapi import APIRouter, Query, WebSocket, WebSocketDisconnect
from fastapi.websockets import WebSocketState

from app.domain.auth import AuthError, SessionClaims, decode_token
from app.services.intent import is_chitchat
from app.domain.schemas import (
    ChatMessage,
    TurnCompletedPayload,
)

logger = logging.getLogger(__name__)
router = APIRouter()


# Soft sentence boundary: punctuation followed by whitespace or end.
_SENTENCE_BOUNDARY = re.compile(r"([\.!\?。！？]+)(\s|$)")


# Languages the XTTS-v2 multilingual model can actually speak. Anything
# outside this set has no voice, so we fall back to English *audio* (the
# reply TEXT may still be in the user's language — only the spoken audio
# is English when the language is unsupported).
XTTS_LANGS = frozenset({
    "en", "es", "fr", "de", "it", "pt", "pl", "tr", "ru", "nl",
    "cs", "ar", "zh", "ja", "hu", "ko", "hi",
})

# Friendly names so the style prompt reads naturally ("reply in Arabic").
LANG_NAMES = {
    "en": "English", "ar": "Arabic", "ur": "Urdu", "hi": "Hindi",
    "es": "Spanish", "fr": "French", "de": "German", "it": "Italian",
    "pt": "Portuguese", "ru": "Russian", "zh": "Chinese", "ja": "Japanese",
    "ko": "Korean", "tr": "Turkish", "nl": "Dutch", "pl": "Polish",
    "cs": "Czech", "hu": "Hungarian",
}


def _norm_lang(lang: Optional[str]) -> str:
    """Normalise a locale-ish code to our short form ('en-US' → 'en',
    'zh-CN' → 'zh'). Returns '' for empty/auto."""
    if not lang:
        return ""
    code = lang.strip().lower().replace("_", "-")
    if code in ("auto", ""):
        return ""
    if code.startswith("zh"):
        return "zh"
    return code.split("-")[0]


def _coerce_tts_lang(lang: Optional[str]) -> str:
    """Pick a language XTTS can speak; fall back to English otherwise."""
    code = _norm_lang(lang)
    return code if code in XTTS_LANGS else "en"


def _lang_name(lang: Optional[str]) -> str:
    return LANG_NAMES.get(_norm_lang(lang), "English")


def build_style_prompt(preferred: Optional[str]) -> str:
    """Style + language contract, rebuilt per turn.

    The WS path otherwise sends only the reference-data block + the
    user's message, so without this the model rambles and sometimes
    emits HTML (<br>) the widget renders literally. Short + plain-text
    also keeps TTS latency/cost down (CPU XTTS runs ~1s per character).

    Language rule: the model mirrors the user's actual language, so a
    visitor who picked English but typed Urdu still gets an Urdu reply.
    The picked language is only the fallback when the language is
    unclear; English is the final fallback.
    """
    pref_name = _lang_name(preferred)
    return (
        "You are a helpful assistant. Reply in a short, precise and natural "
        "way — usually 1-3 sentences and no more than ~60 words. Get straight "
        "to the point; skip filler and pleasantries. "
        "Always reply in the SAME language as the user's most recent message. "
        f"If you cannot tell which language they used, reply in {pref_name}. "
        "If you cannot write that language, use English. "
        "GROUNDING RULES (follow exactly): when a 'Reference data' section is "
        "provided, answer ONLY using the facts in it; copy numbers, names and "
        "values from it EXACTLY — never round, estimate, or invent. If the "
        "requested fact is not in the Reference data, say you don't have that "
        "information — do NOT make up companies, numbers, products or details. "
        "Use plain text only — no markdown, no HTML tags, and never write "
        "'<br>'. When you need details from the user, ask for one thing at a time."
    )


@dataclass
class TurnState:
    claims: SessionClaims
    audio_buffer: bytearray = field(default_factory=bytearray)
    sample_rate: int = 16000
    text_input: Optional[str] = None
    # Per-turn language preference sent by the client (the widget's
    # header picker). Used as the LLM fallback language + the TTS voice
    # language. None → fall back to the session/claims language.
    language: Optional[str] = None
    started_at: float = field(default_factory=time.monotonic)
    cancelled: bool = False


def _split_sentences(buffer: str) -> tuple[List[str], str]:
    """Greedy sentence splitter.

    Returns ``(complete_sentences, remainder)``. Used to decide when
    to flush text into TTS without waiting for the entire LLM response.
    """

    sentences: List[str] = []
    last = 0
    for match in _SENTENCE_BOUNDARY.finditer(buffer):
        end = match.end()
        sentence = buffer[last:end].strip()
        if sentence:
            sentences.append(sentence)
        last = end
    return sentences, buffer[last:]


@router.websocket("/ws/turn")
async def ws_turn(websocket: WebSocket, token: str = Query(default="")) -> None:
    # ----- auth --------------------------------------------------------
    # WebSockets aren't subject to the standard CORS middleware. We
    # accept any origin (the JWT in `token` is the real auth boundary),
    # but log the Origin so cross-origin issues are debuggable when a
    # widget is embedded on a customer site.
    origin = websocket.headers.get("origin")
    logger.info("ws/turn handshake from origin=%s", origin or "(none)")

    try:
        claims = decode_token(token)
    except AuthError as exc:
        await websocket.close(code=4401, reason=str(exc))
        return

    await websocket.accept()
    app = websocket.app
    stt_service = app.state.stt_service
    llm_service = app.state.llm_service
    tts_service = app.state.tts_service
    laravel_client = app.state.laravel_client
    settings = app.state.settings

    state = TurnState(claims=claims)
    logger.info(
        "ws/turn opened: session=%s project=%s voice=%s",
        claims.session_id,
        claims.project_id,
        claims.voice_id,
    )

    # Track the in-flight turn so we can cancel it when the call ends,
    # the user starts a new turn (barge-in), or the WS dies. Without
    # this the LLM + TTS keep running long after the caller hangs up
    # — burning Groq tokens and CPU.
    current_turn: Optional[asyncio.Task] = None

    def _spawn_turn() -> None:
        nonlocal current_turn, state
        # Cancel any previous turn still draining the LLM / TTS.
        if current_turn and not current_turn.done():
            current_turn.cancel()
        local_state = state
        current_turn = asyncio.create_task(_run_turn(
            websocket,
            local_state,
            stt_service=stt_service,
            llm_service=llm_service,
            tts_service=tts_service,
            laravel_client=laravel_client,
            speaker_wav=claims.speaker_wav or settings.default_speaker_wav,
            language=claims.language,
            voice_output_dir=settings.voice_output_dir,
            voice_output_url_prefix=settings.voice_output_url_prefix,
            audio_sample_rate=settings.audio_sample_rate,
        ))
        # Reset state for the next user input. The task already captured
        # the previous state object so this doesn't affect it.
        state = TurnState(claims=claims)

    try:
        while True:
            if websocket.client_state != WebSocketState.CONNECTED:
                break

            try:
                msg = await websocket.receive_json()
            except WebSocketDisconnect:
                break
            except Exception as exc:  # noqa: BLE001
                await _send_error(websocket, "bad_frame", str(exc))
                continue

            ftype = msg.get("type")

            if ftype == "audio.start":
                state.audio_buffer.clear()
                state.text_input = None
                state.language = (msg.get("language") or "").strip() or None
                state.sample_rate = int(msg.get("sample_rate", 16000))
                state.started_at = time.monotonic()
                logger.info("ws/turn audio.start sr=%d", state.sample_rate)
                continue

            if ftype == "audio.chunk":
                data_b64 = msg.get("data") or ""
                if data_b64:
                    try:
                        decoded = base64.b64decode(data_b64)
                        state.audio_buffer.extend(decoded)
                    except Exception:  # noqa: BLE001
                        await _send_error(websocket, "bad_chunk", "invalid base64 audio")
                continue

            if ftype == "audio.end":
                logger.info(
                    "ws/turn audio.end buffered=%d bytes (~%.1fs @ %dHz pcm16)",
                    len(state.audio_buffer),
                    len(state.audio_buffer) / 2 / max(state.sample_rate, 1),
                    state.sample_rate,
                )
                # Debug: dump the incoming buffer to a WAV so we can listen
                # back. Lets us tell the difference between "mic returned
                # silence" and "we corrupted the bytes in transit".
                try:
                    import wave
                    import os
                    os.makedirs("voice_outputs", exist_ok=True)
                    out_path = "voice_outputs/last_mic_input.wav"
                    with wave.open(out_path, "wb") as wf:
                        wf.setnchannels(1)
                        wf.setsampwidth(2)
                        wf.setframerate(state.sample_rate)
                        wf.writeframes(bytes(state.audio_buffer))
                    logger.info("ws/turn DEBUG: dumped raw mic audio to %s", out_path)
                except Exception as _exc:  # noqa: BLE001
                    logger.warning("ws/turn debug-dump failed: %s", _exc)
                _spawn_turn()
                continue

            if ftype == "text":
                state.text_input = (msg.get("text") or "").strip()
                state.language = (msg.get("language") or "").strip() or None
                state.started_at = time.monotonic()
                _spawn_turn()
                state = TurnState(claims=claims)
                continue

            if ftype == "barge_in":
                state.cancelled = True
                # TODO: wire cancellation into in-flight LLM/TTS tasks.
                continue

            await _send_error(websocket, "unknown_frame", f"unknown type: {ftype}")
    except WebSocketDisconnect:
        logger.info("ws/turn disconnected: session=%s", claims.session_id)
    except Exception as exc:  # noqa: BLE001
        logger.exception("ws/turn fatal")
        await _send_error(websocket, "fatal", str(exc))
    finally:
        # Cancel any in-flight turn (LLM stream + TTS) so the call's
        # hang-up actually stops billing the LLM provider. Give the
        # task a moment to handle CancelledError + flush persistence
        # before the WS closes.
        state.cancelled = True
        if current_turn and not current_turn.done():
            current_turn.cancel()
            try:
                await asyncio.wait_for(current_turn, timeout=2.0)
            except (asyncio.CancelledError, asyncio.TimeoutError, Exception):  # noqa: BLE001
                pass
        # Tell Laravel the session is over so dashboards stop showing
        # it as "active". Belt-and-braces — Twilio's status callback
        # usually fires too, but if it's delayed or fails this
        # guarantees the session ends.
        try:
            await laravel_client.post_session_ended(
                claims.project_id, claims.session_id
            )
        except Exception:  # noqa: BLE001
            pass
        if websocket.client_state == WebSocketState.CONNECTED:
            await websocket.close()


# ---------------------------------------------------------------------------
# Per-turn pipeline
# ---------------------------------------------------------------------------


async def _run_turn(
    websocket: WebSocket,
    state: TurnState,
    *,
    stt_service,
    llm_service,
    tts_service,
    laravel_client,
    speaker_wav: str,
    language: str,
    voice_output_dir: str,
    voice_output_url_prefix: str,
    audio_sample_rate: int,
) -> None:
    user_text = state.text_input or ""

    # Hoisted outputs that `_persist_turn` reads when firing the
    # persistence webhook. Lifted from later in the function so error
    # paths can persist what they have (e.g. user_text from STT) even
    # when the LLM bails on Groq 429 etc. Without this the session
    # shows up in the dashboard with zero messages even though the
    # caller clearly spoke.
    aggregated: str = ""
    tokens_in: int = 0
    tokens_out: int = 0
    model_used: str = llm_service.model
    final_audio_url: Optional[str] = None
    latency_ms: int = 0
    # Language the caller actually spoke (filled in by STT auto-detect for
    # voice turns). None for text turns — there we trust the LLM to mirror.
    detected_lang: Optional[str] = None
    # Fallback language for this turn: the client's per-turn pick, else the
    # session/claims language, else English.
    preferred_lang: str = state.language or language or "en"

    def _persist_turn(error: Optional[Dict[str, str]] = None) -> None:
        """Fire the persistence webhook with whatever state we have.
        Idempotent on the Laravel side via the user_content dedup."""
        meta: Dict[str, Any] = {
            "voice_id": state.claims.voice_id,
            "channel":  state.claims.channel or "web",
            # Effective language for this turn (detected speech wins, else
            # the caller's pick / session default).
            "language": detected_lang or preferred_lang,
        }
        if error:
            meta["error"] = error
        payload = TurnCompletedPayload(
            project_id=state.claims.project_id,
            session_id=state.claims.session_id,
            role="assistant",
            content=aggregated or None,
            audio_url=final_audio_url,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            latency_ms=latency_ms,
            model_used=model_used,
            metadata=meta,
            user_content=user_text or None,
            cancelled=state.cancelled,
        )
        asyncio.create_task(laravel_client.post_turn_completed(payload))

    # 1) STT (skip if the client sent text directly) ---------------------
    if not user_text and state.audio_buffer:
        try:
            tx = await asyncio.to_thread(
                stt_service.transcribe_pcm16,
                bytes(state.audio_buffer),
                state.sample_rate,
                None,   # auto-detect: a caller speaking a different
                        # language than the UI picker still transcribes
                        # correctly (e.g. picked English, spoke Arabic).
                False,  # vad_filter — disabled; the default Silero VAD
                        # is too aggressive on mic input that's been
                        # downsampled + AGC'd and ate full recordings.
                        # Whisper handles silence padding fine without it.
            )
            user_text = tx.text
            detected_lang = tx.language  # e.g. 'ar', 'hi', 'en' or None
            logger.info("ws/turn STT: text=%r lang=%s duration_ms=%d",
                        user_text[:120], detected_lang, tx.duration_ms)
            await websocket.send_json({"type": "stt.final", "text": user_text})
        except Exception as exc:  # noqa: BLE001
            await _send_error(websocket, "stt_failed", str(exc))
            _persist_turn({"code": "stt_failed", "message": str(exc)})
            return

    if not user_text:
        await _send_error(websocket, "empty_input", "no transcribable audio or text")
        _persist_turn({"code": "empty_input", "message": "no audio or text"})
        return

    # 2) Pull per-project context from Laravel (RAG passages, webhook
    #    tool results, live-SQL rows). Skip the roundtrip for plain
    #    chitchat — "hi", "thanks", "bye" don't need DB lookups and
    #    the LLM answers them in ~1s from base knowledge.
    context_block = ""
    if not is_chitchat(user_text):
        try:
            context_block = await laravel_client.resolve_context(
                project_id=state.claims.project_id,
                session_id=state.claims.session_id,
                user_text=user_text,
            )
            if context_block:
                logger.info(
                    "ws/turn fetched context block: %d chars",
                    len(context_block),
                )
        except Exception as exc:  # noqa: BLE001
            # Never break the turn on a context-fetch failure — fall back
            # to the LLM's base knowledge.
            logger.warning("ws/turn resolve_context failed: %s", exc)
    else:
        logger.info("ws/turn classified as chitchat — skipping resolve_context")

    # 3) LLM (streaming) -------------------------------------------------
    # Lead with the style + language contract so replies stay short,
    # plain-text and in the right language even with no reference block.
    # Fallback language = what the caller spoke (voice) or picked (text).
    messages = [ChatMessage(role="system",
                            content=build_style_prompt(detected_lang or preferred_lang))]
    if context_block:
        messages.append(ChatMessage(role="system", content=context_block))
    messages.append(ChatMessage(role="user", content=user_text))

    # Voice language: prefer what the caller actually spoke, else their
    # pick; coerce to something XTTS can speak (else English audio).
    tts_lang = _coerce_tts_lang(detected_lang or preferred_lang)
    # aggregated / tokens_in / tokens_out / model_used hoisted to the
    # top of the function so error paths can still persist what's
    # available. Don't re-init here.

    # Buffered TTS pipeline: flush sentence-by-sentence
    pending = ""
    audio_seq = 0

    # Per-turn WAV file. We write each TTS chunk to disk AS it is sent
    # over the WS, so:
    #   - the file exists incrementally during streaming (could be served
    #     with HTTP Range for late joiners)
    #   - by turn.end the file is complete and we store its URL in
    #     messages.audio_url for replay on session reload.
    turn_ts = int(time.time() * 1000)
    sid_dir = os.path.join(voice_output_dir, "sessions", str(state.claims.session_id))
    os.makedirs(sid_dir, exist_ok=True)
    audio_filename = f"{turn_ts}.wav"
    audio_disk_path = os.path.join(sid_dir, audio_filename)
    audio_rel_url = f"/sessions/{state.claims.session_id}/{audio_filename}"
    audio_full_url = voice_output_url_prefix.rstrip("/") + audio_rel_url
    wav_writer: Optional[wave.Wave_write] = None
    try:
        wav_writer = wave.open(audio_disk_path, "wb")
        wav_writer.setnchannels(1)
        wav_writer.setsampwidth(2)
        wav_writer.setframerate(audio_sample_rate)
    except Exception as exc:  # noqa: BLE001
        logger.warning("ws/turn could not open turn audio file: %s", exc)
        wav_writer = None

    try:
        async for evt in llm_service.stream_chat(messages):
            if state.cancelled:
                break
            if evt["type"] == "delta":
                delta = evt["text"]
                aggregated += delta
                pending += delta
                await websocket.send_json({"type": "llm.delta", "text": delta})

                sentences, remainder = _split_sentences(pending)
                pending = remainder
                for sentence in sentences:
                    if state.cancelled:
                        break
                    audio_seq = await _stream_tts_sentence(
                        websocket,
                        tts_service,
                        sentence,
                        speaker_wav,
                        audio_seq,
                        is_cancelled=lambda: state.cancelled,
                        wav_writer=wav_writer,
                        language=tts_lang,
                    )
            elif evt["type"] == "final":
                tokens_in = int(evt.get("tokens_in", 0))
                tokens_out = int(evt.get("tokens_out", 0))
                model_used = evt.get("model", model_used)
    except Exception as exc:  # noqa: BLE001
        await _send_error(websocket, "llm_failed", str(exc))
        # Persist the user's STT'd input + the partial bot reply (if any)
        # so the dashboard can show "User said X — bot errored". Without
        # this, every Groq 429 produces a ghost session with no messages.
        _persist_turn({"code": "llm_failed", "message": str(exc)})
        return

    # Flush any trailing text that didn't end on punctuation. Skip if the
    # turn was cancelled — we don't want to keep speaking after Stop.
    if pending.strip() and not state.cancelled:
        audio_seq = await _stream_tts_sentence(
            websocket, tts_service, pending.strip(), speaker_wav, audio_seq,
            is_cancelled=lambda: state.cancelled,
            wav_writer=wav_writer,
            language=tts_lang,
        )

    # Finalise the on-disk WAV. If no chunks were written (TTS failed or
    # turn was cancelled before any audio), unlink the empty file so we
    # don't leave a broken URL behind.
    bytes_written = 0
    if wav_writer is not None:
        try:
            # wave.getnframes() reflects what writeframes() actually pushed.
            bytes_written = wav_writer.getnframes() * wav_writer.getsampwidth()
            wav_writer.close()
        except Exception as exc:  # noqa: BLE001
            logger.warning("ws/turn closing wav writer failed: %s", exc)
        wav_writer = None
    final_audio_url = audio_full_url if bytes_written > 0 else None
    if bytes_written == 0:
        try:
            os.unlink(audio_disk_path)
        except OSError:
            pass

    await websocket.send_json(
        {
            "type": "llm.final",
            "text": aggregated,
            "tokens_in": tokens_in,
            "tokens_out": tokens_out,
        }
    )
    await websocket.send_json({"type": "audio.end"})

    latency_ms = int((time.monotonic() - state.started_at) * 1000)
    await websocket.send_json(
        {
            "type": "turn.end",
            "latency_ms": latency_ms,
            "audio_url": final_audio_url,
        }
    )

    # 3) Persist via the shared helper (used by both success + error
    #    paths — see _persist_turn at the top of this function).
    _persist_turn()


async def _stream_tts_sentence(
    websocket: WebSocket,
    tts_service,
    sentence: str,
    speaker_wav: str,
    seq_start: int,
    is_cancelled=None,
    wav_writer: Optional[wave.Wave_write] = None,
    language: str = "en",
) -> int:
    """Stream a single sentence to the client, forwarding each PCM16 chunk
    the moment the model produces it. Returns the next seq number.

    ``tts_service.stream`` is a *blocking* generator (it runs torch), so we
    drain it on a worker thread and hand chunks to the event loop through a
    queue. This keeps latency low — the client starts playing the first
    chunk while the rest of the sentence is still synthesising — instead of
    buffering the entire sentence before sending anything.
    """

    if not sentence.strip():
        return seq_start

    seq = seq_start
    loop = asyncio.get_running_loop()
    queue: asyncio.Queue = asyncio.Queue()
    _DONE = object()

    def _produce() -> None:
        # Runs on a worker thread; push each chunk back onto the loop.
        try:
            for chunk in tts_service.stream(sentence, speaker_wav, language=language):
                if chunk:
                    loop.call_soon_threadsafe(queue.put_nowait, chunk)
        except Exception as exc:  # noqa: BLE001 — forwarded to the async side
            loop.call_soon_threadsafe(queue.put_nowait, exc)
        finally:
            loop.call_soon_threadsafe(queue.put_nowait, _DONE)

    producer = asyncio.create_task(asyncio.to_thread(_produce))
    try:
        while True:
            # User pressed Stop / barge_in. Stop sending chunks for this
            # sentence and drain the producer so the worker thread exits.
            if is_cancelled and is_cancelled():
                logger.info("ws/turn TTS cancelled mid-sentence")
                break

            item = await queue.get()
            if item is _DONE:
                break
            if isinstance(item, Exception):
                await _send_error(websocket, "tts_failed", str(item))
                break
            if is_cancelled and is_cancelled():
                # Cancellation arrived while we were waiting on queue.get()
                logger.info("ws/turn TTS cancelled (post-dequeue)")
                break
            # Persist to disk BEFORE sending. If the WS write fails the
            # bytes are still on disk and the file URL is still valid for
            # replay. Cancellation also writes the partial output so the
            # user can replay what was generated before they hit Stop.
            if wav_writer is not None:
                try:
                    wav_writer.writeframes(item)
                except Exception as exc:  # noqa: BLE001
                    logger.warning("ws/turn wav write failed: %s", exc)
            await websocket.send_json(
                {
                    "type": "audio.chunk",
                    "seq": seq,
                    "data": base64.b64encode(item).decode("ascii"),
                    "format": "pcm16",
                }
            )
            seq += 1
    finally:
        # Drain to let the worker thread finish naturally — Coqui's
        # inference_stream doesn't expose interrupt, so we have to let
        # it run to completion. The chunks just go nowhere.
        try:
            while True:
                drained = await asyncio.wait_for(queue.get(), timeout=0.01)
                if drained is _DONE:
                    break
        except asyncio.TimeoutError:
            pass
        try:
            await producer
        except Exception:  # noqa: BLE001
            pass

    return seq


async def _send_error(websocket: WebSocket, code: str, message: str) -> None:
    """Emit an ``error`` frame AND close the turn.

    Every early-return path in ``_run_turn`` goes through here. Without
    a follow-up ``turn.end`` the client's bubble + send-button state
    stay stuck waiting for a completion that never arrives — the user
    would have to wait for the 12 s client-side watchdog to recover.

    Sending ``turn.end`` here is safe even for the success path because
    the success path doesn't call ``_send_error`` (so there's no double
    emission). The client manager is idempotent regardless.
    """
    if websocket.client_state != WebSocketState.CONNECTED:
        return
    try:
        await websocket.send_json(
            {"type": "error", "code": code, "message": message}
        )
        # Pair the error with a turn-end so the client can release UI
        # state immediately instead of waiting on the watchdog.
        await websocket.send_json(
            {"type": "turn.end", "latency_ms": 0, "audio_url": None, "error": code}
        )
    except Exception:  # noqa: BLE001
        pass
