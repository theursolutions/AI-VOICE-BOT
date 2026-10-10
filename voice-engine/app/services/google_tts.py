"""Google voices — Gemini text-to-speech.

One request per sentence returns the whole sentence as audio, which we
normalise to PCM16 mono 24 kHz (what XTTS produces, so every channel can
play either). Ready-made voices only ("Kore", "Puck", …) — no cloning —
but they speak whatever language the text is in, Urdu script and Roman
Urdu included, which the local XTTS voice cannot.

The API key's project must be billed for real use: the free tier allows
only a few speech requests a minute per model, and a phone call makes one
per sentence. :class:`GoogleVoice` falls back to the local voice whenever
Google refuses, so a caller never gets silence.
"""

from __future__ import annotations

import audioop
import base64
import hashlib
import io
import json
import logging
import os
import re
import threading
import time
import wave
from collections import OrderedDict
from typing import Any, Dict, Iterable, Iterator, List, Optional, Tuple

import httpx

logger = logging.getLogger(__name__)

API_URL = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
OUTPUT_RATE = 24000
SLICE_BYTES = OUTPUT_RATE * 2 // 2  # hand audio over in 0.5 s slices


class GoogleTTSError(Exception):
    """Google could not speak this text (quota, network, bad voice …)."""


class RateLimited(GoogleTTSError):
    """HTTP 429 for one model; ``retry_after`` seconds until it may be asked again."""

    def __init__(self, message: str, retry_after: float) -> None:
        super().__init__(message)
        self.retry_after = retry_after


class GoogleTTS:
    """Gemini TTS with two quota savers:

    * phrases said on every call (greeting, "anything else?", goodbyes) are
      remembered — in memory, and on disk when ``cache_dir`` is set — so
      they cost one request ever instead of one per call, and play at once;
    * each model has its own quota, so a 429 on one moves to the next model
      in ``models`` and rests the limited one until Google says it may
      retry. Billed keys still get only 100 requests a day per TTS model
      (Tier 1, seen 2026-10-11).
    """

    sample_rate = OUTPUT_RATE
    CACHE_MAX_CHARS = 160   # remember short phrases only
    CACHE_ENTRIES = 256

    def __init__(self, api_key: str, model: str, fallback_models: Iterable[str] = (),
                 timeout: float = 20.0, http: Optional[httpx.Client] = None,
                 cache_dir: Optional[str] = None) -> None:
        self.models = [model] + [m for m in fallback_models if m and m != model]
        self.model = model
        # Sync client: synthesis runs on worker threads, like XTTS. The key
        # travels in a header so it never lands in a URL or an access log.
        self._client = http or httpx.Client(timeout=timeout, headers={"x-goog-api-key": api_key})
        self._resting: Dict[str, float] = {}  # model -> monotonic time it may be used again
        self._cache: "OrderedDict[Tuple[str, str, str], bytes]" = OrderedDict()
        self._lock = threading.Lock()  # calls synthesize from several threads
        self._cache_dir = cache_dir or None
        if self._cache_dir:
            os.makedirs(self._cache_dir, exist_ok=True)

    def synthesize(self, text: str, voice: str, style: Optional[str] = None) -> bytes:
        """``text`` spoken by ``voice``, as PCM16 mono 24 kHz. ``style``: how
        to deliver it ("gently and reassuringly"), see :func:`speech_prompt`."""
        key = (voice, style or "", text)
        cached = self._cached(key)
        if cached is not None:
            return cached

        last: Optional[GoogleTTSError] = None
        for model in self._available_models():
            try:
                pcm = self._request(model, text, voice, style)
            except RateLimited as exc:
                with self._lock:
                    self._resting[model] = time.monotonic() + exc.retry_after
                logger.info("Google TTS %s rate-limited for %.0fs — trying the next model", model, exc.retry_after)
                last = exc
                continue
            self._remember(key, pcm)
            return pcm

        raise last or GoogleTTSError("HTTP 429: every Google voice model is resting after its quota")

    def stream(self, text: str, voice: str, style: Optional[str] = None) -> Iterator[bytes]:
        """``text`` spoken by ``voice`` as PCM16 24 kHz chunks, yielded as
        Google produces them: the first ~1.1 s after the request, where a
        whole sentence takes ~4.6 s (measured 2026-10-11). Live calls use
        this; previews and greetings use :meth:`synthesize`."""
        key = (voice, style or "", text)
        cached = self._cached(key)
        if cached is not None:
            yield cached
            return

        last: Optional[GoogleTTSError] = None
        for model in self._available_models():
            chunks: List[bytes] = []
            try:
                for pcm in self._stream_request(model, text, voice, style):
                    chunks.append(pcm)
                    yield pcm
            except RateLimited as exc:
                if chunks:
                    raise  # mid-sentence: too late to switch model
                with self._lock:
                    self._resting[model] = time.monotonic() + exc.retry_after
                logger.info("Google TTS %s rate-limited for %.0fs — trying the next model", model, exc.retry_after)
                last = exc
                continue
            if not chunks:
                raise GoogleTTSError(f"{model} returned no audio")
            self._remember(key, b"".join(chunks))
            return

        raise last or GoogleTTSError("HTTP 429: every Google voice model is resting after its quota")

    # ── phrase cache ───────────────────────────────────────────────────

    def _cached(self, key: Tuple[str, str, str]) -> Optional[bytes]:
        with self._lock:
            pcm = self._cache.get(key)
            if pcm is not None:
                self._cache.move_to_end(key)
                return pcm
        path = self._cache_path(key)
        if path and os.path.exists(path):
            try:
                with open(path, "rb") as fh:
                    pcm = fh.read()
            except OSError:
                return None
            self._remember(key, pcm, to_disk=False)
            return pcm
        return None

    def _remember(self, key: Tuple[str, str, str], pcm: bytes, to_disk: bool = True) -> None:
        if len(key[2]) > self.CACHE_MAX_CHARS or not pcm:
            return
        with self._lock:
            self._cache[key] = pcm
            self._cache.move_to_end(key)
            if len(self._cache) > self.CACHE_ENTRIES:
                self._cache.popitem(last=False)
        path = self._cache_path(key) if to_disk else None
        if path:
            try:
                tmp = f"{path}.{threading.get_ident()}.tmp"
                with open(tmp, "wb") as fh:
                    fh.write(pcm)
                os.replace(tmp, path)
            except OSError as exc:
                logger.debug("Google TTS cache write failed: %s", exc)

    def _cache_path(self, key: Tuple[str, str, str]) -> Optional[str]:
        if not self._cache_dir or len(key[2]) > self.CACHE_MAX_CHARS:
            return None
        digest = hashlib.sha1("\0".join(key).encode("utf-8")).hexdigest()
        return os.path.join(self._cache_dir, digest + ".pcm")

    def _stream_request(self, model: str, text: str, voice: str,
                        style: Optional[str] = None) -> Iterator[bytes]:
        url = API_URL.format(model=model).replace(":generateContent", ":streamGenerateContent") + "?alt=sse"
        body = {
            "contents": [{"parts": [{"text": speech_prompt(text, style)}]}],
            "generationConfig": {
                "responseModalities": ["AUDIO"],
                "speechConfig": {"voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}},
            },
        }
        carry = b""  # an odd trailing byte waits for the next chunk
        try:
            with self._client.stream("POST", url, json=body) as resp:
                if resp.status_code != 200:
                    resp.read()
                    if resp.status_code == 429:
                        raise RateLimited(f"HTTP 429: {_error_message(resp)}", _retry_after(resp))
                    raise GoogleTTSError(f"HTTP {resp.status_code}: {_error_message(resp)}")
                for line in resp.iter_lines():
                    if not line.startswith("data:"):
                        continue
                    try:
                        event = json.loads(line[5:])
                    except ValueError:
                        continue
                    parts = ((event.get("candidates") or [{}])[0].get("content") or {}).get("parts") or []
                    for part in parts:
                        data = part.get("inlineData") or {}
                        if not data.get("data"):
                            continue
                        pcm = carry + to_pcm16_24k(base64.b64decode(data["data"]), data.get("mimeType", ""))
                        cut = len(pcm) - (len(pcm) % 2)
                        carry = pcm[cut:]
                        if cut:
                            yield pcm[:cut]
        except httpx.HTTPError as exc:
            raise GoogleTTSError(f"network: {exc}") from exc

    def _available_models(self) -> List[str]:
        now = time.monotonic()
        with self._lock:
            return [m for m in self.models if self._resting.get(m, 0.0) <= now]

    def _request(self, model: str, text: str, voice: str, style: Optional[str] = None) -> bytes:
        body = {
            "contents": [{"parts": [{"text": speech_prompt(text, style)}]}],
            "generationConfig": {
                "responseModalities": ["AUDIO"],
                "speechConfig": {"voiceConfig": {"prebuiltVoiceConfig": {"voiceName": voice}}},
            },
        }
        try:
            resp = self._client.post(API_URL.format(model=model), json=body)
        except httpx.HTTPError as exc:
            raise GoogleTTSError(f"network: {exc}") from exc
        if resp.status_code == 429:
            raise RateLimited(f"HTTP 429: {_error_message(resp)}", _retry_after(resp))
        if resp.status_code != 200:
            raise GoogleTTSError(f"HTTP {resp.status_code}: {_error_message(resp)}")
        try:
            part = resp.json()["candidates"][0]["content"]["parts"][0]["inlineData"]
            audio = base64.b64decode(part["data"])
        except (KeyError, IndexError, TypeError, ValueError) as exc:
            raise GoogleTTSError(f"unexpected response: {exc}") from exc
        return to_pcm16_24k(audio, part.get("mimeType", ""))

    def close(self) -> None:
        self._client.close()


class GoogleVoice:
    """A Google voice behind the same ``stream()`` signature as XTTS.

    When Google refuses (quota, network), the sentence is spoken by the
    local voice instead — slower, but never silent.
    """

    sample_rate = OUTPUT_RATE

    def __init__(self, google: GoogleTTS, voice: str, fallback: Any = None) -> None:
        self.google = google
        self.voice = voice
        self.fallback = fallback

    def stream(self, text: str, speaker_wav: Optional[str] = None,
               language: str = "en", style: Optional[str] = None) -> Iterator[bytes]:
        produced = False
        try:
            for pcm in self.google.stream(text, self.voice, style):
                produced = True
                for i in range(0, len(pcm), SLICE_BYTES):
                    yield pcm[i:i + SLICE_BYTES]
        except GoogleTTSError as exc:
            logger.warning("Google voice %s failed (%s)%s", self.voice, exc,
                           "" if produced else " — local voice for this sentence")
            # Half a sentence already played: repeating it in another voice
            # would be worse than stopping.
            if not produced and self.fallback is not None:
                # The local voice has no styles: it reads the words plainly.
                yield from self.fallback.stream(text, speaker_wav, language=language)


def speech_prompt(text: str, style: Optional[str] = None) -> str:
    """What the voice model is sent for ``text``.

    A delivery style goes in Google's "director's notes" layout, which the
    model reads as direction rather than script. A bare "Say this gently:"
    prefix was spoken aloud in testing; the notes layout was not (0 of 3,
    Urdu and English, 2026-10-11). The TTS models refuse a separate system
    instruction, so the notes have to travel with the text."""
    if not style:
        return text
    return f"### DIRECTOR'S NOTES\nStyle: {style}.\n\n### TRANSCRIPT\n{text}"


def pick_tts(local: Any, google: Optional[GoogleTTS], claims: Any) -> Any:
    """The voice a session speaks with: its Google voice when the admin
    chose one (and Google is configured), otherwise the local XTTS voice."""
    provider = getattr(claims, "tts_provider", None) if claims is not None else None
    voice = getattr(claims, "tts_voice", None) if claims is not None else None
    if provider == "google" and voice and google is not None:
        return GoogleVoice(google, voice, fallback=local)
    return local


def to_pcm16_24k(audio: bytes, mime: str) -> bytes:
    """WAV or raw L16 audio → PCM16 mono 24 kHz."""
    if audio[:4] == b"RIFF":
        try:
            with wave.open(io.BytesIO(audio)) as w:
                rate, width, channels = w.getframerate(), w.getsampwidth(), w.getnchannels()
                pcm = w.readframes(w.getnframes())
        except wave.Error:
            rate, width, channels, pcm = OUTPUT_RATE, 2, 1, audio[44:]
    else:
        # "audio/L16;codec=pcm;rate=24000" — little-endian despite the name.
        m = re.search(r"rate=(\d+)", mime or "")
        rate, width, channels, pcm = (int(m.group(1)) if m else OUTPUT_RATE), 2, 1, audio

    if width != 2:
        pcm = audioop.lin2lin(pcm, width, 2)
    if channels == 2:
        pcm = audioop.tomono(pcm, 2, 0.5, 0.5)
    if rate != OUTPUT_RATE:
        pcm, _ = audioop.ratecv(pcm, 2, 1, rate, OUTPUT_RATE, None)
    return pcm


def _retry_after(resp: httpx.Response, default: float = 60.0) -> float:
    """Seconds from Google's RetryInfo ("38s"), else a minute."""
    try:
        for detail in resp.json().get("error", {}).get("details", []):
            delay = detail.get("retryDelay")
            if delay:
                return float(str(delay).rstrip("s")) + 1.0
    except (ValueError, AttributeError):
        pass
    return default


def _error_message(resp: httpx.Response) -> str:
    try:
        return str(resp.json().get("error", {}).get("message", ""))[:200].replace("\n", " ")
    except ValueError:
        return resp.text[:200]
