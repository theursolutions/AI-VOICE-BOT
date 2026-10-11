"""One phone/SIP call: listen → understand → look up → reply, turn after turn.

Provider-agnostic. A provider adapter (``app/api/phone.py`` for Twilio,
``app/calls/asterisk.py`` for Asterisk) decodes inbound audio to PCM16
16 kHz and calls :meth:`CallSession.feed_audio`; the AI's voice goes back
out through the adapter's :class:`~app.calls.transport.CallTransport`.

What makes it sound like a person rather than a phone menu:

* it hears HOW the caller speaks, not just what (``app/services/listener``):
  their mood shapes the reply and the voice delivering it, and a caller
  whose voice reads as a man or a woman is called "sir" or "madam";
* while it looks something up it says something fitting — about their
  order, a price, their account — or reassures an upset caller, never
  the same line twice, and nothing at all when the answer is quick;
* it doesn't talk over people: a caller who pauses mid-thought is waited
  for, and what they add is heard together with what they'd said;
* barge-in: the caller talking over the AI cuts the AI off;
* when the line goes quiet it checks in ("anything else, sir?", "take
  your time"), and if nobody answers it says goodbye and hangs up; a
  caller who says they're done gets a warm goodbye and the call ends;
* the agent's persona, what we know about the caller, and every earlier
  turn of the call (``/api/internal/call-profile``); every turn is saved
  to Laravel; transfer to a person where the provider can move the call.
"""

from __future__ import annotations

import asyncio
import difflib
import logging
import re
import threading
import time
from collections import deque
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

from app.calls.endpointing import Endpointer, looks_like_speech
from app.calls.phrases import (
    UPSET,
    Phrases,
    book_key,
    ensure_book,
    has_book,
    is_closing,
    offers_more_help,
    says_goodbye,
)
from app.calls.transport import CallTransport
from app.domain.auth import SessionClaims
from app.domain.schemas import ChatMessage, TurnCompletedPayload
from app.services.google_tts import GoogleVoice, pick_tts
from app.services.intent import is_chitchat
from app.services.listener import Heard
from app.services.speech_text import (
    END_CALL,
    XTTS_LANGS,
    build_style_prompt,
    clean_for_speech,
    coerce_tts_lang,
    delivery_style,
    has_end_call,
    lang_name,
    norm_lang,
    split_first_clause,
    split_sentences,
)

logger = logging.getLogger(__name__)

STT_SAMPLE_RATE = 16000

# What Whisper (the fallback ears) chooses between when the agent
# auto-detects: open detection labels Urdu speech "Hindi" ~99% of the time.
LANGUAGE_CANDIDATES = ("en", "ur")

APOLOGY = {
    "en": "Sorry, I didn't quite get that. Could you say it again?",
    "ur": "معاف کیجیے، بات صحیح سے سمجھ نہیں آئی۔ کیا آپ دوبارہ بتا سکتے ہیں؟",
    "roman": "Maaf kijiye, baat sahi se samajh nahi aayi. Kya aap dobara bata sakte hain?",
}
TRANSFER_NOTE = (
    "The caller asked for a person and is being transferred right now. Reply with "
    "ONE short sentence, in the caller's language, telling them you are connecting "
    "them to a member of the team. Ask nothing else."
)
# A time-limited call (website "Call us") says its goodbye this long before
# the limit — at 55 s of a 1-minute call — whatever is happening: the
# goodbye itself takes ~5 s and is always cached, so it plays at once.
TIME_UP_LEAD_S = 5

MOOD_NOTES = {
    "worried": "The caller sounds worried. Before helping, acknowledge it briefly and sincerely in "
               "your own words, and reassure them — without over-apologising.",
    "sad": "The caller sounds upset or sad. Be gentle and kind; acknowledge how they feel in your own words.",
    "frustrated": "The caller sounds frustrated. Acknowledge it honestly in your own words, don't be "
                  "defensive, and get straight to what will fix it.",
    "angry": "The caller sounds angry. Stay calm and respectful, acknowledge their frustration "
             "sincerely in your own words, never argue, and focus on solving it.",
    "confused": "The caller sounds confused. Explain simply, one step at a time, and check it makes sense.",
    "happy": "The caller sounds cheerful. Match their warmth.",
}

#: Calls in progress, for /metrics. Keyed "<provider>:<call ref>".
ACTIVE_CALLS: Dict[str, "CallSession"] = {}


@dataclass
class CallServices:
    stt: Any
    llm: Any
    tts: Any  # the local (XTTS) voice
    laravel: Any
    settings: Any
    google_tts: Any = None  # Google voices, when GEMINI_API_KEY is set
    listener: Any = None    # Gemini listening; Whisper (stt) when absent

    @classmethod
    def from_app(cls, app) -> "CallServices":
        s = app.state
        return cls(stt=s.stt_service, llm=s.llm_service, tts=s.tts_service,
                   laravel=s.laravel_client, settings=s.settings,
                   google_tts=getattr(s, "google_tts", None),
                   listener=getattr(s, "listener", None))


class CallSession:
    def __init__(
        self,
        services: CallServices,
        transport: CallTransport,
        claims: SessionClaims,
        call_ref: str = "",
    ) -> None:
        self.s = services
        self.transport = transport
        self.claims = claims
        self.key = f"{transport.provider}:{call_ref or claims.session_id}"

        settings = services.settings
        cfg = lambda name, default: getattr(settings, name, default)  # noqa: E731
        self.speaker_wav = claims.speaker_wav or settings.default_speaker_wav
        # The session's voice: a Google voice, or the cloned local one.
        self.tts = pick_tts(services.tts, services.google_tts, claims)
        # "auto" = answer each caller in their own language; else the
        # agent's fixed call language.
        self.language = claims.language or "en"
        self.agent_language = "en"   # the agent's language setting (en, ur, roman, es …)
        self.barge_in = bool(cfg("call_barge_in", True))
        self.barge_in_ms = int(cfg("call_barge_in_ms", 400))
        self.history_turns = int(cfg("call_history_turns", 10))
        self.emotion = bool(cfg("call_voice_emotion", True))
        self.listen_timeout = float(cfg("call_listen_timeout", 6.0))
        self.filler_after = int(cfg("call_filler_after_ms", 300)) / 1000
        self.incomplete_wait = int(cfg("call_incomplete_wait_ms", 1500)) / 1000
        self.idle_enabled = bool(cfg("call_idle_prompts", True))
        self.idle_prompt_s = float(cfg("call_idle_prompt_seconds", 4.5))
        self.idle_wait_s = float(cfg("call_idle_wait_seconds", 8.0))
        self.idle_goodbye_s = float(cfg("call_idle_goodbye_seconds", 6.0))

        self.endpointer = Endpointer(int(cfg("call_end_silence_ms", 650)))
        self.phrases = Phrases()
        self.history: List[ChatMessage] = []
        # From the agent's call settings (call profile): persona + caller
        # facts ("" = generic prompt), words to listen for, whether Urdu
        # replies are written in Urdu script or Roman Urdu, voice gender.
        self.system_prompt = ""
        self.stt_hints = ""
        self.urdu_script = "urdu"
        self.voice_gender: Optional[str] = None

        # What the listener has learned about the caller.
        self.reply_lang: Optional[str] = None
        self.mood = "calm"
        self.caller = "unknown"
        self._caller_spoke = False
        #: Per-turn timings, for logs and the scenario tests.
        self.stats: List[Dict[str, Any]] = []

        self._turn: Optional[asyncio.Task] = None   # what the AI is doing: a reply, a greeting, a check-in
        self._reply_turn: Optional[asyncio.Task] = None  # the latest reply to the caller
        self._cut = 0                  # bumped by barge-in: stops whatever is being spoken
        self._turn_audio = b""         # the current turn's speech, until its reply starts
        self._reply_started = False    # the current turn's answer is audible
        self._absorbed: set = set()    # turns merged into the next one
        self._profile: Optional[asyncio.Future] = None
        self._started = time.monotonic()
        self._handed_off = False       # transferred to a person: the AI is done
        self._playing_until = 0.0      # monotonic time the queued audio ends
        self._last_voice_at = time.monotonic()
        self._last_ai_question = False
        self._last_ai_offer = False    # it just asked "anything else?"
        self._idle_plan: List[str] = []
        self._idle_said_at = 0.0
        self._ending: Optional[asyncio.Task] = None
        self._watcher: Optional[asyncio.Task] = None
        #: Longest this call may last (website calls), set by the provider
        #: adapter; None = no limit.
        self.max_seconds: Optional[int] = None
        self._out_of_time = False
        self._closed = False
        self._background: set = set()
        self._recent_spoken: deque = deque(maxlen=12)  # for telling echo from the caller

    # ── lifecycle ──────────────────────────────────────────────────────

    async def start(self) -> None:
        ACTIVE_CALLS[self.key] = self
        logger.info("call %s started: session=%s project=%s",
                    self.key, self.claims.session_id, self.claims.project_id)
        # Loaded in the background so the caller's first words are already
        # being heard; the first turn waits for it only if it's still due.
        self._profile = asyncio.ensure_future(self._load_profile())
        self._watcher = asyncio.ensure_future(self._watch_silence())

    async def close(self) -> None:
        """The caller hung up (or the provider dropped the call)."""
        if self._closed:
            return
        self._closed = True
        ACTIVE_CALLS.pop(self.key, None)
        for task in (self._turn, self._watcher):
            if task and not task.done():
                task.cancel()
        logger.info("call %s ended", self.key)
        # Providers without a billing webhook of their own (Asterisk) meter
        # the call here; Twilio's status callback meters Twilio calls.
        call_seconds = None
        if getattr(self.transport, "bills_minutes", False):
            call_seconds = max(1, int(time.monotonic() - self._started))
        await self.s.laravel.post_session_ended(
            self.claims.project_id, self.claims.session_id, call_seconds=call_seconds,
        )

    def greet(self, text: Optional[str]) -> None:
        """Say the opening line. The caller can talk over it like any reply."""
        text = (text or "").strip()
        if not text or self._closed:
            return
        _append(self.history, "assistant", text)
        self._last_ai_question = _asks(text)
        self._last_ai_offer = offers_more_help(text)
        self._turn = asyncio.create_task(self._speak(text, self.language))

    # ── inbound audio ──────────────────────────────────────────────────

    def feed_audio(self, pcm16_16k: bytes) -> None:
        """PCM16 16 kHz mono from the caller, any frame size."""
        if self._closed or self._handed_off or self._out_of_time:
            return
        utterance = self.endpointer.feed(pcm16_16k)
        if self.endpointer.speech_run_ms > 0:
            self._heard_voice()

        if (self.barge_in and self._ai_audible()
                and self.endpointer.speech_run_ms >= self.barge_in_ms):
            self._interrupt()

        if utterance:
            previous = self._turn
            if previous and not previous.done():
                if previous is self._reply_turn and not self._reply_started:
                    # The caller carried on before the AI answered: one
                    # thought, one turn — heard together.
                    utterance = self._turn_audio + utterance
                    self._absorbed.add(previous)
                previous.cancel()
            self._turn_audio = utterance
            self._reply_started = False
            self._turn = self._reply_turn = asyncio.create_task(self._run_turn(utterance))

    def _heard_voice(self) -> None:
        self._last_voice_at = time.monotonic()
        self._idle_plan = []

    def _ai_audible(self) -> bool:
        return time.monotonic() < self._playing_until

    def _interrupt(self) -> None:
        logger.info("call %s barge-in: caller spoke over the AI", self.key)
        if self._ending and not self._ending.done():
            # "Wait, one more thing!" over the goodbye: stay on the line.
            self._ending.cancel()
            self._ending = None
        if self._reply_started and self._turn and not self._turn.done():
            self._turn.cancel()
            self._turn = None
        # Only a "let me check" was playing: the turn carries on; if the
        # caller is adding to their question, the next utterance merges.
        self._cut += 1
        self._playing_until = 0.0
        self._spawn(self.transport.stop_playback())

    # ── one turn ───────────────────────────────────────────────────────

    async def _run_turn(self, utterance: bytes) -> None:
        me = asyncio.current_task()
        started = time.monotonic()
        stat: Dict[str, Any] = {"t0": started, "speech_ms": len(utterance) // 32}
        heard: Optional[Heard] = None
        user_text = ""
        reply = ""
        reply_lang = self.reply_lang or self.language
        tokens_in = tokens_out = 0
        model = getattr(self.s.llm, "model", "")
        error: Optional[Dict[str, str]] = None
        cancelled = False
        handoff = False
        end_call = False
        try:
            heard = await self._hear(utterance, stat)
            user_text = heard.text
            stat.update(listen_ms=heard.ms, text=user_text, language=heard.language,
                        mood=heard.mood, caller=heard.caller, complete=heard.complete)
            if not user_text:
                logger.info("call %s heard nothing (%dms)", self.key, heard.ms)
                return
            if self._is_echo(user_text):
                # The AI's own voice coming back down the line, not the caller.
                logger.info("call %s ignored its own echo: %s", self.key, user_text[:120])
                stat["echo"] = True
                user_text = ""
                return
            reply_lang = self._reply_language(heard.language)
            self.reply_lang = reply_lang
            self.mood = heard.mood
            if heard.caller != "unknown":
                self.caller = heard.caller
            self._caller_spoke = True
            logger.info("call %s heard (%dms, %s, %s): %s", self.key, heard.ms,
                        reply_lang, heard.mood, user_text[:200])
            self._ensure_phrasebook(reply_lang)

            if self._caller_is_leaving(user_text):
                # "Allah Hafiz" / "nahi bas, shukriya" after "anything else?":
                # thank them and end the call at once — no lookup, no
                # "let me check", no waiting for the model to write a goodbye.
                goodbye = self._goodbye_line(reply_lang)
                if goodbye:
                    stat["quick_goodbye"] = True
                    await self._speak(goodbye, reply_lang, kind="reply", stat=stat)
                    reply, end_call = goodbye, True
                    logger.info("call %s: the caller said goodbye — ending the call", self.key)
                    self._ending = asyncio.ensure_future(self._end_call())
                    return

            if not heard.complete:
                # They stopped mid-thought: give them a moment to go on.
                # If they do, their next words cancel this turn and merge.
                await self._wait_for_more()

            context = ""
            # Nothing to look up in small talk or a goodbye.
            if not is_chitchat(user_text) and not is_closing(user_text):
                context, handoff = await self._look_up(user_text, reply_lang, stat)

            if self._profile is not None:
                # Shielded: an interrupted turn must not cancel the load.
                await asyncio.shield(self._profile)

            style = delivery_style(self.mood) if self.emotion else None
            # The opening is spoken the moment it exists (first sentence, or
            # first clause); everything after it goes to the voice as ONE
            # request once the model has finished — it plays while the opening
            # is still being heard. One request per sentence used up Google's
            # daily voice quota mid-call (live, 2026-10-11).
            pending = ""
            spoken = False
            async for evt in self.s.llm.stream_chat(self._messages(context, user_text, handoff, reply_lang)):
                if evt["type"] == "delta":
                    reply += evt["text"]
                    pending += evt["text"]
                    if spoken:
                        continue
                    sentences, rest = split_sentences(pending)
                    if not sentences:
                        clause, rest = split_first_clause(pending)
                        sentences = [clause] if clause else []
                    if sentences:
                        await self._wait_for_floor()
                        self._reply_started = True
                        pending = " ".join(sentences[1:] + [rest]).strip()
                        await self._speak(sentences[0], reply_lang, style, kind="reply", stat=stat)
                        spoken = True
                elif evt["type"] == "final":
                    tokens_in = int(evt.get("tokens_in") or 0)
                    tokens_out = int(evt.get("tokens_out") or 0)
                    model = evt.get("model") or model
            if pending.strip() and not spoken:
                await self._wait_for_floor()
                self._reply_started = True
            await self._speak(pending, reply_lang, style, kind="reply", stat=stat)
            if stat.get("first_audio_ms") is not None:
                logger.info("call %s timing: heard in %sms, first sound %sms, answer %sms after the caller stopped",
                            self.key, stat.get("listen_ms"), stat.get("first_audio_ms"), stat.get("reply_audio_ms"))

            # The marker, or both sides saying goodbye: the model doesn't
            # always remember the marker.
            end_call = has_end_call(reply) or (is_closing(user_text) and says_goodbye(reply))
            reply = clean_for_speech(reply) if end_call else reply
            if handoff:
                logger.info("call %s transferring to a person", self.key)
                self._handed_off = True
                await self.transport.transfer()
            elif end_call:
                logger.info("call %s: the caller is done — ending the call", self.key)
                self._ending = asyncio.ensure_future(self._end_call())
        except asyncio.CancelledError:
            cancelled = True
            raise
        except Exception as exc:  # noqa: BLE001 — a turn must never kill the call
            logger.exception("call %s turn failed", self.key)
            error = {"code": "turn_failed", "message": str(exc)[:300]}
            key = book_key(reply_lang, self.urdu_script)
            await self._speak(APOLOGY.get(key, APOLOGY["en"]), reply_lang if key in APOLOGY else "en")
        finally:
            stat["cancelled"] = cancelled
            stat["merged"] = me in self._absorbed
            self.stats.append(stat)
            if user_text and me not in self._absorbed:
                self._remember(user_text, reply)
                self._last_ai_question = _asks(reply)
                self._last_ai_offer = offers_more_help(reply)
                self._persist(user_text, reply, reply_lang, tokens_in, tokens_out, model,
                              int((time.monotonic() - started) * 1000), cancelled, error,
                              handoff, heard, end_call)
                if not cancelled:
                    # Facts captured by lead extraction show up next turn.
                    self._spawn(self._load_profile())
            self._absorbed.discard(me)

    async def _hear(self, utterance: bytes, stat: Dict[str, Any]) -> Heard:
        """What the caller said — Gemini listening, else Whisper."""
        fixed = self._fixed_stt_language()
        if not looks_like_speech(utterance):
            # Steady line noise: both listeners invent words for it.
            stat["noise"] = True
            return Heard(text="", language=fixed)
        if self.s.listener is not None:
            try:
                heard = await asyncio.wait_for(
                    self.s.listener.hear(utterance, language=fixed, hints=self.stt_hints),
                    timeout=self.listen_timeout)
                stat["listener"] = "gemini"
                return heard
            except asyncio.CancelledError:
                raise
            except Exception as exc:  # noqa: BLE001 — Whisper is the fallback
                logger.warning("call %s listener failed (%s) — Whisper for this turn", self.key, exc)
        started = time.monotonic()
        tx = await asyncio.to_thread(
            self.s.stt.transcribe_pcm16, utterance, STT_SAMPLE_RATE, fixed, False,
            initial_prompt=self.stt_hints or None,
            candidates=None if fixed else LANGUAGE_CANDIDATES,
        )
        stat["listener"] = "whisper"
        return Heard(text=(tx.text or "").strip(), language=tx.language or fixed,
                     ms=int((time.monotonic() - started) * 1000))

    async def _look_up(self, user_text: str, lang: str, stat: Dict[str, Any]):
        """Ask Laravel for the data behind the answer. If that takes a
        moment, say something fitting meanwhile — a quick answer needs no
        "let me check"."""
        resolving = asyncio.ensure_future(self.s.laravel.resolve(
            project_id=self.claims.project_id,
            session_id=self.claims.session_id,
            user_text=user_text,
        ))
        filler_task = None
        try:
            done, _ = await asyncio.wait({resolving}, timeout=self.filler_after)
            if not done and not self.endpointer.in_speech:
                filler = self._filler(lang, user_text)
                if filler:
                    stat["filler"] = filler
                    # One reassuring delivery for every upset mood, so each
                    # line has at most two recordings and they stay cached.
                    style = delivery_style("worried") if self.emotion and self.mood in UPSET else None
                    filler_task = asyncio.ensure_future(self._speak(filler, lang, style, kind="filler", stat=stat))
            resolved = await resolving
        except BaseException:
            resolving.cancel()
            if filler_task:
                filler_task.cancel()
            raise
        if filler_task:
            await filler_task
        # Only acted on when this provider can actually move the call.
        handoff = resolved["handoff"] and bool(getattr(self.transport, "can_transfer", False))
        return resolved["context"], handoff

    async def _wait_for_more(self) -> None:
        deadline = time.monotonic() + self.incomplete_wait
        while time.monotonic() < deadline or self.endpointer.in_speech:
            await asyncio.sleep(0.1)

    async def _wait_for_floor(self, limit: float = 10.0) -> None:
        """Never start answering over the caller: if they're mid-sentence,
        wait — what they're saying will arrive as a turn that merges with
        this one (and cancels it)."""
        deadline = time.monotonic() + limit
        while self.endpointer.in_speech and time.monotonic() < deadline:
            await asyncio.sleep(0.05)

    def _messages(self, context: str, user_text: str, handoff: bool = False,
                  lang: Optional[str] = None) -> List[ChatMessage]:
        system = self.system_prompt or build_style_prompt(self.language)
        notes = self._turn_notes(user_text, lang)
        if notes:
            system = f"{system}\n\n{notes}"
        if handoff:
            # Ahead of the conversation: a system message after the user
            # turn is dropped by some providers (see MemoryBuilder::build).
            system = f"{system}\n\n{TRANSFER_NOTE}"
        msgs = [ChatMessage(role="system", content=system)]
        if context:
            msgs.append(ChatMessage(role="system", content=context))
        for m in self.history[-self.history_turns * 2:]:
            _append(msgs, m.role, m.content)
        _append(msgs, "user", user_text)
        return msgs

    def _turn_notes(self, user_text: str = "", lang: Optional[str] = None) -> str:
        """What the listener noticed, as guidance for this one reply."""
        notes = ["RIGHT NOW ON THIS CALL:"]
        code = norm_lang(lang)
        if code and not self._fixed_stt_language():
            # Said outright: the model otherwise drifted into the Roman Urdu
            # of the greeting when an English caller spoke.
            if code == "ur":
                script = "Roman Urdu" if self.urdu_script == "roman" else "Urdu script"
                notes.append(f"- The caller is speaking Urdu: reply in {script}, as the language rule says.")
            else:
                notes.append(f"- The caller is speaking {lang_name(code)}: reply in {lang_name(code)}.")
        if self.voice_gender in ("male", "female"):
            forms = "feminine (main karti hoon)" if self.voice_gender == "female" else "masculine (main karta hoon)"
            notes.append(f"- Your voice is a {'woman' if self.voice_gender == 'female' else 'man'}'s: "
                         f"use {forms} first-person forms wherever the language marks gender.")
        if any(m.role == "assistant" for m in self.history):
            notes.append("- You already greeted the caller at the start; don't greet again or answer a greeting they didn't give.")
        if is_closing(user_text):
            notes.append(f"- This sounds like the caller is wrapping up. If they are done, say a short, warm goodbye "
                         f"and end with {END_CALL}.")
        if self.mood in MOOD_NOTES:
            notes.append("- " + MOOD_NOTES[self.mood])
        if self.caller in ("male", "female"):
            word = "sir" if self.caller == "male" else "madam"
            notes.append(f"- The caller's voice is a {'man' if self.caller == 'male' else 'woman'}'s: "
                         f"you may address them as \"{word}\" now and then (not in every reply).")
        openers = _openers(self.history)
        if openers:
            notes.append("- Your recent replies began: " + "; ".join(f'"{o}"' for o in openers)
                         + ". Begin this one differently — often just answer directly.")
        return "\n".join(notes) if len(notes) > 1 else ""

    def _remember(self, user_text: str, reply: str) -> None:
        _append(self.history, "user", user_text)
        if reply.strip():
            _append(self.history, "assistant", reply.strip())

    def _reply_language(self, heard: Optional[str]) -> str:
        fixed = self._fixed_stt_language()
        if fixed:
            return fixed
        code = norm_lang(heard) or norm_lang(self.reply_lang) or "en"
        # Spoken Hindi and Urdu are one language; this platform answers it
        # as Urdu unless the agent is set to Hindi.
        if code == "hi" and self.agent_language != "hi":
            code = "ur"
        return code

    def _fixed_stt_language(self) -> Optional[str]:
        """The agent's fixed call language, or None when it auto-detects."""
        return norm_lang(self.language) or None

    # ── when the line goes quiet ───────────────────────────────────────

    async def _watch_silence(self) -> None:
        """Checks in when the caller goes quiet, ends a dead call, and ends
        a time-limited call (website "Call us") politely on time."""
        while not self._closed:
            await asyncio.sleep(0.25)
            if self._time_is_up():
                continue
            if (not self.idle_enabled or self._handed_off or self._ending
                    or (self._turn and not self._turn.done())
                    or self.endpointer.in_speech or self._ai_audible()):
                continue
            quiet = time.monotonic() - max(self._playing_until, self._last_voice_at, self._idle_said_at)
            if not self._idle_plan:
                if not self._caller_spoke:
                    plan, wait = ["still_there", "goodbye_silent"], self.idle_prompt_s
                elif self._last_ai_offer:
                    # It already asked "anything else?": silence is the answer.
                    plan, wait = ["goodbye"], self.idle_prompt_s
                elif self._last_ai_question:
                    plan, wait = ["take_your_time", "still_there", "goodbye_silent"], self.idle_wait_s
                else:
                    plan, wait = ["anything_else", "goodbye"], self.idle_prompt_s
                if quiet >= wait:
                    self._idle_plan = plan
                    self._turn = asyncio.ensure_future(self._say_idle(self._idle_plan.pop(0)))
            elif quiet >= self.idle_goodbye_s:
                self._turn = asyncio.ensure_future(self._say_idle(self._idle_plan.pop(0)))

    def _time_is_up(self) -> bool:
        """Say the time-up goodbye TIME_UP_LEAD_S before the limit, cutting
        off whatever is being said — the caller must hear it."""
        if not self.max_seconds or self._ending or self._handed_off:
            return bool(self._ending) and bool(self.max_seconds)
        left = self.max_seconds - (time.monotonic() - self._started)
        if left > TIME_UP_LEAD_S:
            return False
        logger.info("call %s: time limit reached (%ss)", self.key, self.max_seconds)
        if self._turn and not self._turn.done():
            self._turn.cancel()
        if self._ai_audible():
            self._cut += 1
            self._playing_until = 0.0
            self._spawn(self.transport.stop_playback())
        self._out_of_time = True  # nothing the caller says reopens it
        self._ending = asyncio.ensure_future(self._say_idle("time_up"))
        return True

    async def _say_idle(self, kind: str) -> None:
        lang = self.reply_lang or self._fixed_stt_language() or self.agent_language
        key = book_key(lang, self.urdu_script)
        if not self._voice_speaks(lang) or not has_book(key):
            lang, key = "en", "en"
        line = self.phrases.say(kind, key, self.caller)
        if not line:
            return
        logger.info("call %s quiet line (%s): %s", self.key, kind, line)
        _append(self.history, "assistant", line)
        self._last_ai_offer = kind == "anything_else"
        self._last_ai_question = _asks(line)
        await self._speak(line, lang, kind="idle")
        self._idle_said_at = time.monotonic()
        if kind == "time_up":
            await self._end_call()
        elif kind.startswith("goodbye"):
            self._ending = asyncio.ensure_future(self._end_call())

    async def _end_call(self) -> None:
        """Let the goodbye play out, then hang up."""
        while self._ai_audible():
            await asyncio.sleep(0.1)
        await asyncio.sleep(0.5)
        hang_up = getattr(self.transport, "hang_up", None)
        if hang_up is None or self._closed:
            return
        logger.info("call %s: hanging up after the goodbye", self.key)
        await hang_up()

    # ── speaking ───────────────────────────────────────────────────────

    async def _speak(self, text: Optional[str], lang: Optional[str],
                     style: Optional[str] = None, kind: str = "reply",
                     stat: Optional[Dict[str, Any]] = None) -> None:
        """Synthesize ``text`` and play it, chunk by chunk as the voice makes it."""
        text = clean_for_speech(text)
        if not text or self._closed:
            return
        self._recent_spoken.append(text)
        tts = self.tts
        tts_lang = coerce_tts_lang(lang)  # only the local voice uses it
        sample_rate = int(getattr(tts, "sample_rate", 24000))
        # Delivery styles are a Google voice feature.
        extra = {"style": style} if style and isinstance(tts, GoogleVoice) else {}
        cut = self._cut
        loop = asyncio.get_running_loop()
        queue: asyncio.Queue = asyncio.Queue()
        stop = threading.Event()
        done = object()

        def produce() -> None:
            try:
                for chunk in tts.stream(text, self.speaker_wav, language=tts_lang, **extra):
                    if stop.is_set():
                        break
                    if chunk:
                        loop.call_soon_threadsafe(queue.put_nowait, chunk)
            except Exception as exc:  # noqa: BLE001 — handed to the async side
                loop.call_soon_threadsafe(queue.put_nowait, exc)
            finally:
                loop.call_soon_threadsafe(queue.put_nowait, done)

        self._spawn(asyncio.to_thread(produce))
        try:
            while True:
                item = await queue.get()
                if item is done:
                    break
                if isinstance(item, Exception):
                    logger.warning("call %s TTS error: %s", self.key, item)
                    break
                if self._cut != cut:
                    break  # the caller spoke over it
                seconds = await self.transport.play(item, sample_rate)
                now = time.monotonic()
                if stat is not None:
                    stat.setdefault("first_audio_ms", int((now - stat["t0"]) * 1000))
                    stat.setdefault(f"{kind}_audio_ms", int((now - stat["t0"]) * 1000))
                self._playing_until = max(self._playing_until, now) + seconds
        finally:
            # Never wait for the worker: XTTS can't stop mid-chunk, and a
            # barge-in must not wait for it. It quits at the next chunk.
            stop.set()

    def _voice_speaks(self, lang: Optional[str]) -> bool:
        """Google voices speak every call language; the local voice only XTTS's."""
        return isinstance(self.tts, GoogleVoice) or norm_lang(lang) in XTTS_LANGS

    def _filler(self, lang: Optional[str], user_text: str) -> Optional[str]:
        """Something fitting to say while the answer is looked up — in the
        caller's language, and only in a voice that can speak it."""
        if not self._voice_speaks(lang):
            return None
        return self.phrases.checking(book_key(lang, self.urdu_script), user_text, self.mood, self.caller)

    def _is_echo(self, heard: str) -> bool:
        """Is this the AI's own recent words, heard back on a line with no
        echo cancelling? A few words that recur in a recent line, or a close
        match to one, count."""
        said = _norm_words(heard)
        if len(said) < 2:
            return False
        joined = " ".join(said)
        for line in self._recent_spoken:
            words = _norm_words(line)
            if not words:
                continue
            if joined in " ".join(words) and len(said) >= 3:
                return True
            if difflib.SequenceMatcher(None, joined, " ".join(words)).ratio() >= 0.7:
                return True
        return False

    def _caller_is_leaving(self, text: str) -> bool:
        """An outright goodbye ("Allah Hafiz", "bye"), or "no, that's all,
        thanks" in answer to "anything else?"."""
        return is_closing(text) and (says_goodbye(text) or self._last_ai_offer)

    def _goodbye_line(self, lang: Optional[str]) -> Optional[str]:
        """A warm thank-you-and-goodbye from the phrasebook — said instantly
        once its recording is cached. None: let the model say goodbye."""
        key = book_key(lang, self.urdu_script)
        if not self._voice_speaks(lang) or not has_book(key):
            return None
        return self.phrases.say("goodbye", key, self.caller)

    def _ensure_phrasebook(self, lang: Optional[str]) -> None:
        key = book_key(lang, self.urdu_script)
        if not has_book(key):
            self._spawn(ensure_book(key, self.s.llm))

    # ── Laravel ────────────────────────────────────────────────────────

    async def _load_profile(self) -> None:
        try:
            profile = await self.s.laravel.call_profile(self.claims.project_id,
                                                        self.claims.session_id)
        except Exception as exc:  # noqa: BLE001 — keep the previous prompt
            logger.warning("call %s profile load failed: %s", self.key, exc)
            return
        profile = profile or {}
        if profile.get("system_prompt"):
            self.system_prompt = profile["system_prompt"]
        # The agent's call language: a fixed code ("en", "ur", "es" …) or "auto".
        if profile.get("stt_language"):
            self.language = profile["stt_language"]
        if profile.get("language"):
            self.agent_language = profile["language"]
        self.stt_hints = profile.get("stt_hints") or ""
        self.urdu_script = "roman" if profile.get("urdu_script") == "roman" else "urdu"
        self.voice_gender = profile.get("voice_gender") or None
        fixed = self._fixed_stt_language()
        if fixed:
            self._ensure_phrasebook(fixed)

    def _persist(self, user_text: str, reply: str, lang: str, tokens_in: int, tokens_out: int,
                 model: str, latency_ms: int, cancelled: bool,
                 error: Optional[Dict[str, str]], handoff: bool = False,
                 heard: Optional[Heard] = None, end_call: bool = False) -> None:
        meta: Dict[str, Any] = {
            "channel": self.claims.channel or "phone",
            "provider": self.transport.provider,
            "language": lang,
            "voice_id": self.claims.voice_id,
        }
        if heard is not None:
            meta["mood"] = heard.mood
            meta["listen_ms"] = heard.ms
        if error:
            meta["error"] = error
        if handoff:
            meta["handoff"] = True
        if end_call:
            meta["ended_call"] = True
        payload = TurnCompletedPayload(
            project_id=self.claims.project_id,
            session_id=self.claims.session_id,
            role="assistant",
            content=reply.strip() or None,
            tokens_in=tokens_in,
            tokens_out=tokens_out,
            latency_ms=latency_ms,
            model_used=model or "",
            metadata=meta,
            user_content=user_text,
            cancelled=cancelled,
        )
        self._spawn(self.s.laravel.post_turn_completed(payload))

    def _spawn(self, coro) -> None:
        """Fire-and-forget, holding a reference so the task isn't collected."""
        task = asyncio.ensure_future(coro)
        self._background.add(task)
        task.add_done_callback(self._background.discard)


def _norm_words(text: str) -> List[str]:
    return re.findall(r"\w+", clean_for_speech(text).lower())


def _asks(text: str) -> bool:
    """Did this line end by asking the caller something?"""
    return clean_for_speech(text).rstrip().endswith(("?", "؟"))


def _openers(history: List[ChatMessage], replies: int = 3) -> List[str]:
    """The first words of the AI's last few replies — so the next one can
    start differently instead of "Ji bilkul" every time."""
    out: List[str] = []
    for m in reversed(history):
        if m.role != "assistant":
            continue
        words = re.findall(r"\S+", clean_for_speech(m.content))[:3]
        if words:
            out.append(" ".join(words).rstrip(",.!?،۔؟"))
        if len(out) >= replies:
            break
    return list(reversed(out))


def _append(msgs: List[ChatMessage], role: str, content: str) -> None:
    """Append, merging into the previous message when the role repeats.

    An interrupted turn leaves a user message with no reply; the next
    user message joins it, because some providers reject two in a row.
    """
    if msgs and msgs[-1].role == role and role != "system":
        msgs[-1] = ChatMessage(role=role, content=f"{msgs[-1].content}\n{content}")
    else:
        msgs.append(ChatMessage(role=role, content=content))
