"""Gemini listening — what a phone caller said, and how they said it.

Whisper (``stt_service``) only returns words, and on 8 kHz phone audio the
base model garbles Urdu ("Lahore" → "the whore") and often mistakes it for
English. One Gemini request hears the turn the way a person would:

* ``text``      — the words, in the language's own script;
* ``language``  — ISO 639-1 code; spoken Urdu is "ur" even when mixed with
  English words;
* ``mood``      — calm / happy / confused / worried / frustrated / angry /
  sad, so the reply (and the voice delivering it) can meet the caller
  where they are;
* ``caller``    — male / female / unknown, for "sir" / "madam";
* ``complete``  — false when the caller stopped mid-thought, so the agent
  waits instead of talking over them.

Measured 2026-10-11 on phone-quality clips: Gemini 7/7 languages right vs
Whisper 6/7, and the Urdu it hears is word-for-word where Whisper's is not.
It also runs in Google's cloud, so ten parallel calls don't queue on the
CPU the way Whisper does. Whisper stays as the fallback when Google is
unreachable.
"""

from __future__ import annotations

import audioop
import base64
import io
import json
import logging
import re
import time
import wave
from dataclasses import dataclass
from typing import Optional

import httpx

logger = logging.getLogger(__name__)

API_URL = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
UPLOAD_RATE = 8000  # phone audio carries nothing above 4 kHz; half the upload

MOODS = ("calm", "happy", "confused", "worried", "frustrated", "angry", "sad")
CALLERS = ("male", "female", "unknown")

INSTRUCTIONS = (
    "You are the ears of a phone agent. The audio is one turn of a caller on a phone line.\n"
    "- language: ISO 639-1 code of the language spoken, judged by how it SOUNDS. Spoken Hindustani "
    "from Pakistan is Urdu (ur), also when mixed with English words; hi only for clearly Sanskritised Hindi.\n"
    "- text: exactly what the caller said, word for word, written in that language's own script: "
    "ur in Urdu (Perso-Arabic) script — never Devanagari; hi, mr and ne in Devanagari; pa from Pakistan "
    "in Shahmukhi; te in Telugu, gu in Gujarati script, and so on. English words mixed into another "
    "language stay in English letters. Do not translate, summarise or correct. Empty when there is no "
    "speech, only noise, music or someone in the background.\n"
    "- mood: how the caller sounds — from their tone of voice as much as their words.\n"
    "- caller: the caller's voice, male or female; unknown when you cannot tell.\n"
    "- complete: false only when the caller clearly broke off mid-sentence or mid-thought.\n"
    "- clear: true only when a person clearly spoke words to the agent. false for silence, line noise, "
    "breathing, music, people talking in the background, or the agent's own voice echoing back. "
    "Never guess words you did not clearly hear."
)

SCHEMA = {
    "type": "OBJECT",
    "properties": {
        "text": {"type": "STRING"},
        "language": {"type": "STRING"},
        "mood": {"type": "STRING", "enum": list(MOODS)},
        "caller": {"type": "STRING", "enum": list(CALLERS)},
        "complete": {"type": "BOOLEAN"},
        "clear": {"type": "BOOLEAN"},
    },
    "required": ["text", "language", "mood", "caller", "complete", "clear"],
    "propertyOrdering": ["clear", "language", "text", "mood", "caller", "complete"],
}


class ListenerError(Exception):
    """Gemini could not hear this turn (quota, network, bad response)."""


@dataclass
class Heard:
    text: str
    language: Optional[str]
    mood: str = "calm"
    caller: str = "unknown"
    complete: bool = True
    ms: int = 0


class GeminiListener:
    def __init__(self, api_key: str, model: str, timeout: float = 8.0,
                 http: Optional[httpx.AsyncClient] = None) -> None:
        self.model = model
        # The key travels in a header so it never lands in a URL or a log.
        self._client = http or httpx.AsyncClient(timeout=timeout, headers={"x-goog-api-key": api_key})
        # Older models refuse thinkingLevel; dropped after the first refusal.
        self._thinking = True

    async def hear(self, pcm16_16k: bytes, language: Optional[str] = None,
                   hints: str = "") -> Heard:
        """One caller turn (PCM16 mono 16 kHz). ``language``: the agent's
        fixed call language, or None to detect. ``hints``: names and
        products the caller may say."""
        started = time.monotonic()
        prompt = INSTRUCTIONS
        if language:
            prompt += f"\nThe caller is expected to speak the language with code '{language}'."
        if hints:
            # Only a spelling aid. Worded as "the caller may say X", it made
            # the model hear a whole sentence of X in half a sentence of Urdu.
            prompt += (f"\nSpelling aid — names this business uses: {hints}. Use one only if the "
                       "caller actually says it; never add words that were not spoken.")

        body = {
            "systemInstruction": {"parts": [{"text": prompt}]},
            "contents": [{"role": "user", "parts": [
                {"inlineData": {"mimeType": "audio/wav", "data": _wav_b64(pcm16_16k)}},
                # Repeated next to the audio: the lite models sometimes wrote
                # Urdu in Hindi script despite the instructions.
                {"text": "Transcribe this caller turn. Urdu goes in Urdu script (اردو), never Hindi script."},
            ]}],
            "generationConfig": {
                "temperature": 0,
                "responseMimeType": "application/json",
                "responseSchema": SCHEMA,
            },
        }
        data = await self._post(body)
        try:
            raw = data["candidates"][0]["content"]["parts"][0]["text"]
        except (KeyError, IndexError, TypeError) as exc:
            # No candidate: usually a safety block on noise. Nothing heard.
            logger.info("listener: no transcript (%s)", data.get("promptFeedback") or exc)
            return Heard(text="", language=language, ms=_ms(started))
        out = _parse(raw)
        if out.get("clear") is False:
            # Noise, echo or background talk: answering it is how a silent
            # caller heard "ji, aap ki baat Umer company se ho rahi hai" over
            # and over (live test, 2026-10-11).
            return Heard(text="", language=language, ms=_ms(started))
        mood = str(out.get("mood") or "calm").lower()
        caller = str(out.get("caller") or "unknown").lower()
        return Heard(
            text=str(out.get("text") or "").strip(),
            language=(str(out.get("language") or "").lower()[:3] or language),
            mood=mood if mood in MOODS else "calm",
            caller=caller if caller in CALLERS else "unknown",
            complete=out.get("complete") is not False,
            ms=_ms(started),
        )

    async def _post(self, body: dict) -> dict:
        if self._thinking:
            body["generationConfig"]["thinkingConfig"] = {"thinkingLevel": "minimal"}
        try:
            resp = await self._client.post(API_URL.format(model=self.model), json=body)
            if resp.status_code == 400 and self._thinking:
                self._thinking = False
                body["generationConfig"].pop("thinkingConfig", None)
                resp = await self._client.post(API_URL.format(model=self.model), json=body)
        except httpx.HTTPError as exc:
            raise ListenerError(f"network: {exc}") from exc
        if resp.status_code != 200:
            raise ListenerError(f"HTTP {resp.status_code}: {resp.text[:200]}")
        try:
            return resp.json()
        except ValueError as exc:
            raise ListenerError(f"bad response: {exc}") from exc

    async def aclose(self) -> None:
        await self._client.aclose()


def _wav_b64(pcm16_16k: bytes) -> str:
    pcm8, _ = audioop.ratecv(pcm16_16k, 2, 1, 16000, UPLOAD_RATE, None)
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(UPLOAD_RATE)
        w.writeframes(pcm8)
    return base64.b64encode(buf.getvalue()).decode("ascii")


def _parse(raw: str) -> dict:
    try:
        return json.loads(raw)
    except ValueError:
        # Seen on Indic scripts: a broken "\u0" escape inside the text.
        try:
            return json.loads(re.sub(r"\\u(?![0-9a-fA-F]{4})", r"\\\\u", raw))
        except ValueError:
            raise ListenerError(f"unreadable transcript: {raw[:120]}") from None


def _ms(started: float) -> int:
    return int((time.monotonic() - started) * 1000)
