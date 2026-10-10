"""Tests for the provider-agnostic call core (app/calls).

Speech, LLM, TTS and Laravel are fakes, so these run in a second with no
models loaded:

    cd voice-engine && .venv/Scripts/python -m unittest discover -s tests -v
"""

from __future__ import annotations

import asyncio
import struct
import unittest
from types import SimpleNamespace

from app.calls.endpointing import Endpointer
from app.calls.session import ACTIVE_CALLS, CallServices, CallSession
from app.domain.auth import SessionClaims
from app.services.speech_text import split_sentences

FRAME_MS = 20
FRAME_BYTES = 16000 * 2 * FRAME_MS // 1000  # 20 ms of PCM16 @ 16 kHz


def speech(ms: int) -> list[bytes]:
    # Loudness rising and falling like syllables: steady sound is line
    # noise to the call (endpointing.looks_like_speech).
    loud = struct.pack("<h", 6000) * (FRAME_BYTES // 2)
    soft = struct.pack("<h", 1500) * (FRAME_BYTES // 2)
    return [loud if (i // 3) % 2 == 0 else soft for i in range(ms // FRAME_MS)]


def buzz(ms: int) -> list[bytes]:
    return [struct.pack("<h", 3000) * (FRAME_BYTES // 2)] * (ms // FRAME_MS)


def silence(ms: int) -> list[bytes]:
    return [b"\x00" * FRAME_BYTES] * (ms // FRAME_MS)


# ── fakes ──────────────────────────────────────────────────────────────


class FakeSTT:
    def __init__(self, *texts: str) -> None:
        self.texts = list(texts)

    def transcribe_pcm16(self, pcm, sample_rate, language, vad_filter, **kwargs):
        self.last_kwargs = kwargs
        return SimpleNamespace(text=self.texts.pop(0) if self.texts else "", language=language)


class FakeLLM:
    model = "fake-model"

    def __init__(self, *replies: str, delay: float = 0.0) -> None:
        self.replies = list(replies)
        self.delay = delay
        self.calls: list[list] = []

    async def stream_chat(self, messages):
        self.calls.append(messages)
        reply = self.replies.pop(0) if self.replies else "OK."
        for word in reply.split(" "):
            if self.delay:
                await asyncio.sleep(self.delay)
            yield {"type": "delta", "text": word + " "}
        yield {"type": "final", "tokens_in": 10, "tokens_out": 5, "model": self.model}


class FakeTTS:
    sample_rate = 24000

    def __init__(self) -> None:
        self.spoken: list[str] = []

    def stream(self, text, speaker_wav, language="en"):
        self.spoken.append(text)
        # 0.5 s of audio per sentence.
        yield b"\x00" * (self.sample_rate * 2 // 2)


class FakeLaravel:
    def __init__(self, prompt: str = "You are Sara, the help desk agent.", handoff: bool = False,
                 resolve_delay: float = 0.0) -> None:
        self.prompt = prompt
        self.handoff = handoff
        self.resolve_delay = resolve_delay
        self.call_seconds = None
        self.turns = []
        self.ended = []
        self.context_calls = 0

    async def call_profile(self, project_id, session_id):
        return {"system_prompt": self.prompt, **getattr(self, "profile_extra", {})}

    async def resolve(self, project_id, session_id, user_text):
        self.context_calls += 1
        if self.resolve_delay:
            await asyncio.sleep(self.resolve_delay)
        return {"context": "### Reference data\n- Delivery to Lahore takes 2 days.",
                "handoff": self.handoff}

    async def post_turn_completed(self, payload):
        self.turns.append(payload)

    async def post_session_ended(self, project_id, session_id, call_seconds=None):
        self.ended.append(session_id)
        self.call_seconds = call_seconds


class FakeTransport:
    provider = "fake"

    def __init__(self, seconds_per_chunk: float | None = None) -> None:
        self.played = []
        self.stops = 0
        self.hangups = 0
        self.seconds_per_chunk = seconds_per_chunk

    async def play(self, pcm16, sample_rate):
        self.played.append(pcm16)
        return self.seconds_per_chunk or len(pcm16) / 2 / sample_rate

    async def stop_playback(self):
        self.stops += 1

    async def hang_up(self):
        self.hangups += 1


def make_call(stt, llm, laravel=None, transport=None, barge_in=True, listener=None, **settings_overrides):
    settings = SimpleNamespace(**{
        "default_speaker_wav": "speaker.wav", "call_barge_in": barge_in,
        "call_barge_in_ms": 400, "call_history_turns": 10,
        # Off unless a test is about the quiet line.
        "call_idle_prompts": False, **settings_overrides})
    services = CallServices(stt=stt, llm=llm, tts=FakeTTS(),
                            laravel=laravel or FakeLaravel(), settings=settings, listener=listener)
    claims = SessionClaims(session_id=7, project_id=3, voice_id=None, speaker_wav=None,
                           language="en", channel="phone", raw={})
    return CallSession(services, transport or FakeTransport(), claims, call_ref="CA123")


async def say(call: CallSession, frames: list[bytes]) -> None:
    for frame in frames:
        call.feed_audio(frame)
        await asyncio.sleep(0)


async def settle(call: CallSession) -> None:
    """Let the running turn and its background tasks finish."""
    for _ in range(200):
        if (call._turn is None or call._turn.done()) and not call._background:
            return
        await asyncio.sleep(0.01)


async def wait_for(condition, timeout: float = 3.0) -> None:
    for _ in range(int(timeout / 0.01)):
        if condition():
            return
        await asyncio.sleep(0.01)
    raise AssertionError("condition not met in time")


# ── tests ──────────────────────────────────────────────────────────────


class EndpointerTest(unittest.TestCase):
    def test_speech_then_silence_is_one_utterance(self):
        ep = Endpointer()
        out = [ep.feed(f) for f in speech(400) + silence(700)]
        utterances = [u for u in out if u]
        self.assertEqual(len(utterances), 1)
        self.assertEqual(len(utterances[0]), FRAME_BYTES * (20 + 35))

    def test_short_blip_is_dropped(self):
        ep = Endpointer()
        out = [ep.feed(f) for f in speech(100) + silence(2000)]
        self.assertFalse(any(out))
        self.assertLessEqual(len(ep._buf), 300 * 32)  # only the pre-roll is kept

    def test_flush_returns_speech_cut_off_by_hangup(self):
        ep = Endpointer()
        for f in speech(300):
            ep.feed(f)
        self.assertIsNotNone(ep.flush())


class SentenceSplitTest(unittest.TestCase):
    def test_speech_starts_at_the_first_comma(self):
        from app.services.speech_text import split_first_clause
        clause, rest = split_first_clause("Your order is confirmed for Friday, and the driver will call you")
        self.assertEqual(clause, "Your order is confirmed for Friday,")
        self.assertEqual(rest, "and the driver will call you")
        self.assertEqual(split_first_clause("Yes, sure")[0], None)  # too short to say alone
        self.assertEqual(split_first_clause("جی ہاں، آپ کا آرڈر جمعہ تک پہنچ جائے گا، ان شاء اللہ")[0],
                         "جی ہاں، آپ کا آرڈر جمعہ تک پہنچ جائے گا،")

    def test_urdu_full_stop_and_question_mark_split(self):
        sentences, rest = split_sentences("آپ کا آرڈر بک ہو گیا۔ کچھ اور؟ جی")
        self.assertEqual(sentences, ["آپ کا آرڈر بک ہو گیا۔", "کچھ اور؟"])
        self.assertEqual(rest, "جی")


class LanguagePickTest(unittest.TestCase):
    def test_urdu_heard_as_hindi_counts_as_urdu(self):
        from app.services.stt_service import STTService
        stt = STTService()
        stt._model = SimpleNamespace(detect_language=lambda audio: ("hi", 0.9, [("hi", 0.8), ("en", 0.15), ("ur", 0.05)]))
        self.assertEqual(stt._pick_language(None, ("en", "ur")), "ur")
        stt._model = SimpleNamespace(detect_language=lambda audio: ("en", 0.9, [("en", 0.9), ("hi", 0.1)]))
        self.assertEqual(stt._pick_language(None, ("en", "ur")), "en")


class CallSessionTest(unittest.IsolatedAsyncioTestCase):
    async def asyncTearDown(self):
        ACTIVE_CALLS.clear()

    async def test_agent_call_settings_reach_speech_recognition(self):
        stt = FakeSTT("hello")
        laravel = FakeLaravel()
        laravel.profile_extra = {"stt_language": "auto", "stt_hints": "Lahore, Karachi, Umer company"}
        call = make_call(stt, FakeLLM("Hi."), laravel)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual(stt.last_kwargs["initial_prompt"], "Lahore, Karachi, Umer company")
        self.assertEqual(tuple(stt.last_kwargs["candidates"]), ("en", "ur"))

    async def test_checking_line_fits_the_question_and_the_callers_language(self):
        from app.calls.phrases import BUILTIN
        from app.services.google_tts import GoogleVoice
        call = make_call(FakeSTT(), FakeLLM())
        self.assertIn(call._filler("en", "Where is my order?"), BUILTIN["en"]["checking"]["order"])
        self.assertIn(call._filler("en", "How much is a carton?"), BUILTIN["en"]["checking"]["price"])
        self.assertIsNone(call._filler("ur", "آرڈر"))  # the local voice can't speak Urdu
        call.tts = GoogleVoice(object(), "Kore")
        self.assertIn(call._filler("ur", "میرا آرڈر کہاں ہے"), BUILTIN["ur"]["checking"]["order"])
        call.urdu_script = "roman"
        self.assertIn(call._filler("ur", "mera refund kab aaye ga"), BUILTIN["roman"]["checking"]["account"])

    async def test_checking_line_is_never_repeated_while_others_are_left(self):
        from app.calls.phrases import BUILTIN
        call = make_call(FakeSTT(), FakeLLM())
        lines = [call._filler("en", "where is my order") for _ in BUILTIN["en"]["checking"]["order"]]
        self.assertEqual(len(set(lines)), len(lines))

    async def test_upset_caller_hears_reassurance_while_the_agent_checks(self):
        from app.calls.phrases import BUILTIN
        call = make_call(FakeSTT(), FakeLLM())
        call.mood = "worried"
        self.assertIn(call._filler("en", "where is my order"), BUILTIN["en"]["reassure"])

    async def test_turn_uses_persona_speaks_and_is_saved(self):
        laravel = FakeLaravel()
        llm = FakeLLM("Delivery to Lahore takes 2 days.")
        transport = FakeTransport()
        call = make_call(FakeSTT("Do you deliver to Lahore?"), llm, laravel, transport)
        await call.start()

        await say(call, speech(500) + silence(700))
        await settle(call)

        system = llm.calls[0][0]
        self.assertEqual(system.role, "system")
        self.assertIn("Sara", system.content)                    # persona from Laravel
        self.assertIn("Reference data", llm.calls[0][1].content)  # context looked up
        self.assertTrue(transport.played)                         # filler + reply spoken
        turn = laravel.turns[-1]
        self.assertEqual(turn.user_content, "Do you deliver to Lahore?")
        self.assertEqual(turn.content, "Delivery to Lahore takes 2 days.")
        self.assertEqual(turn.metadata["provider"], "fake")
        self.assertFalse(turn.cancelled)

        await call.close()
        self.assertEqual(laravel.ended, [7])
        self.assertNotIn(call.key, ACTIVE_CALLS)

    async def test_second_turn_remembers_the_first(self):
        llm = FakeLLM("Hello, how can I help?", "Your name is Ali.")
        call = make_call(FakeSTT("hello my name is Ali", "what is my name"), llm)
        await call.start()

        await say(call, speech(400) + silence(700))
        await settle(call)
        await say(call, speech(400) + silence(700))
        await settle(call)

        second = [(m.role, m.content) for m in llm.calls[1]]
        self.assertIn(("user", "hello my name is Ali"), second)
        self.assertIn(("assistant", "Hello, how can I help?"), second)
        self.assertEqual(second[-1], ("user", "what is my name"))

    async def test_chitchat_skips_the_data_lookup(self):
        laravel = FakeLaravel()
        call = make_call(FakeSTT("thanks"), FakeLLM("You're welcome."), laravel)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual(laravel.context_calls, 0)

    async def test_caller_talking_over_the_ai_cuts_it_off(self):
        laravel = FakeLaravel()
        # Slow reply, each spoken sentence "lasts" 5 s for the caller.
        llm = FakeLLM("One. Two. Three. Four. Five. Six.", delay=0.05)
        transport = FakeTransport(seconds_per_chunk=5.0)
        call = make_call(FakeSTT("thanks", "stop, wait"), llm, laravel, transport)
        await call.start()

        await say(call, speech(400) + silence(700))
        for _ in range(100):  # until the AI is audibly talking
            if transport.played:
                break
            await asyncio.sleep(0.01)
        await say(call, speech(500))  # caller talks over it

        self.assertEqual(transport.stops, 1)
        await wait_for(lambda: laravel.turns)  # the cut-off turn is still saved
        self.assertTrue(laravel.turns[0].cancelled)
        self.assertLess(len(laravel.turns[0].content or ""), len("One. Two. Three. Four. Five. Six."))

    async def test_barge_in_can_be_switched_off(self):
        llm = FakeLLM("One. Two. Three.", delay=0.05)
        transport = FakeTransport(seconds_per_chunk=5.0)
        call = make_call(FakeSTT("thanks"), llm, transport=transport, barge_in=False)
        await call.start()
        await say(call, speech(400) + silence(700))
        for _ in range(100):
            if transport.played:
                break
            await asyncio.sleep(0.01)
        await say(call, speech(500))
        self.assertEqual(transport.stops, 0)

    async def test_caller_carrying_on_before_the_answer_is_heard_as_one_turn(self):
        laravel = FakeLaravel()
        llm = FakeLLM("First answer is long.", "Second answer.", delay=0.2)
        stt = FakeSTT("thanks", "thanks, actually never mind")
        audio = []
        original = stt.transcribe_pcm16
        stt.transcribe_pcm16 = lambda pcm, *a, **k: (audio.append(len(pcm)), original(pcm, *a, **k))[1]
        call = make_call(stt, llm, laravel)
        await call.start()

        await say(call, speech(400) + silence(700))
        await asyncio.sleep(0.05)                    # first turn is mid-LLM, nothing said yet
        await say(call, speech(400) + silence(700))  # caller adds something
        await settle(call)
        await wait_for(lambda: laravel.turns)

        # One turn, not a half-answer to the first half: the second
        # hearing got both stretches of speech together.
        self.assertEqual(len(laravel.turns), 1)
        self.assertEqual(laravel.turns[0].user_content, "thanks, actually never mind")
        self.assertFalse(laravel.turns[0].cancelled)
        self.assertGreater(audio[1], audio[0] * 1.8)
        self.assertEqual(call.stats[0]["merged"], True)

    async def test_once_the_answer_is_audible_a_new_utterance_is_a_new_turn(self):
        laravel = FakeLaravel()
        llm = FakeLLM("One. Two. Three.", "Okay.", delay=0.05)
        transport = FakeTransport(seconds_per_chunk=0.1)
        call = make_call(FakeSTT("thanks", "stop"), llm, laravel, transport)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.played)
        await asyncio.sleep(0.2)
        await say(call, speech(300) + silence(700))
        await wait_for(lambda: len(laravel.turns) == 2)
        self.assertEqual([t.user_content for t in laravel.turns], ["thanks", "stop"])


class TwilioAdapterTest(unittest.TestCase):
    """/ws/phone end to end: Twilio frames in, μ-law frames out, turn saved."""

    def test_call_over_the_twilio_media_stream(self):
        import audioop
        import base64
        import json
        import time

        import jwt
        from fastapi import FastAPI
        from fastapi.testclient import TestClient

        from app.api import phone
        from app.config import get_settings

        laravel = FakeLaravel()
        app = FastAPI()
        app.include_router(phone.router)
        app.state.stt_service = FakeSTT("thanks")
        app.state.llm_service = FakeLLM("You're welcome.")
        app.state.tts_service = FakeTTS()
        app.state.laravel_client = laravel
        app.state.settings = SimpleNamespace(default_speaker_wav="speaker.wav", call_barge_in=True,
                                             call_barge_in_ms=400, call_history_turns=10)

        settings = get_settings()
        token = jwt.encode({"session_id": 7, "project_id": 3, "channel": "phone",
                            "exp": int(time.time()) + 60},
                           settings.python_jwt_secret, algorithm=settings.python_jwt_algorithm)

        def ulaw_frames(ms: int, amplitude: int) -> list[str]:
            # 60 ms loud, 60 ms soft: speech-like, not a steady tone.
            pcm8k = b"".join(struct.pack("<h", amplitude if (i // 480) % 2 == 0 else amplitude // 4)
                             for i in range(8000 * ms // 1000))
            ulaw = audioop.lin2ulaw(pcm8k, 2)
            return [base64.b64encode(ulaw[i:i + 160]).decode() for i in range(0, len(ulaw), 160)]

        with TestClient(app).websocket_connect("/ws/phone") as ws:
            ws.send_text(json.dumps({"event": "connected"}))
            ws.send_text(json.dumps({"event": "start", "streamSid": "MZ1", "start": {
                "callSid": "CA1", "customParameters": {"token": token}}}))
            for payload in ulaw_frames(400, 3000) + ulaw_frames(800, 0):
                ws.send_text(json.dumps({"event": "media", "streamSid": "MZ1",
                                         "media": {"payload": payload}}))
            reply = ws.receive_json()
            self.assertEqual(reply["event"], "media")
            self.assertEqual(reply["streamSid"], "MZ1")
            self.assertEqual(len(base64.b64decode(reply["media"]["payload"])), 160)  # 20 ms
            # Drain the rest of the reply, then hang up.
            deadline = time.time() + 3
            while not laravel.turns and time.time() < deadline:
                ws.receive_json()
            ws.send_text(json.dumps({"event": "stop", "streamSid": "MZ1", "stop": {}}))

        self.assertEqual(laravel.turns[0].user_content, "thanks")
        self.assertEqual(laravel.turns[0].content, "You're welcome.")
        self.assertEqual(laravel.turns[0].metadata["provider"], "twilio")
        self.assertEqual(laravel.ended, [7])


if __name__ == "__main__":
    unittest.main()
