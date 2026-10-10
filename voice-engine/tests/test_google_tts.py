"""Tests for Google voices (Gemini TTS) and choosing a session's voice.

Google itself is faked — no network, no quota used.

    cd voice-engine && .venv/Scripts/python -m unittest discover -s tests -v
"""

from __future__ import annotations

import io
import struct
import time
import unittest
import wave
from types import SimpleNamespace

from app.domain.auth import SessionClaims, decode_token
from app.services.google_tts import (
    GoogleTTSError,
    GoogleVoice,
    pick_tts,
    to_pcm16_24k,
)
from test_call_session import FakeLaravel, FakeLLM, FakeSTT, FakeTransport, make_call, say, settle, silence, speech


def wav_bytes(rate: int, seconds: float, channels: int = 1) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(channels)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(struct.pack("<h", 1000) * int(rate * seconds) * channels)
    return buf.getvalue()


class FakeGoogle:
    sample_rate = 24000

    def __init__(self, fail: bool = False) -> None:
        self.fail = fail
        self.calls: list[tuple[str, str]] = []
        self.styles: list = []

    def synthesize(self, text, voice, style=None):
        self.calls.append((text, voice))
        self.styles.append(style)
        if self.fail:
            raise GoogleTTSError("HTTP 429: quota")
        return b"\x01\x00" * 24000  # 1 s

    def stream(self, text, voice, style=None):
        yield self.synthesize(text, voice, style)


class FakeLocal:
    sample_rate = 24000

    def __init__(self) -> None:
        self.calls: list[str] = []

    def stream(self, text, speaker_wav, language="en"):
        self.calls.append(text)
        yield b"\x00\x00" * 2400


def claims(provider=None, voice=None) -> SessionClaims:
    return SessionClaims(session_id=7, project_id=3, voice_id=None, speaker_wav=None,
                         language="en", channel="phone", raw={},
                         tts_provider=provider, tts_voice=voice)


class AudioFormatTest(unittest.TestCase):
    def test_wav_at_24k_passes_through(self):
        self.assertEqual(len(to_pcm16_24k(wav_bytes(24000, 1.0), "audio/wav")), 48000)

    def test_other_rates_and_stereo_become_24k_mono(self):
        self.assertAlmostEqual(len(to_pcm16_24k(wav_bytes(48000, 1.0, channels=2), "audio/wav")), 48000, delta=8)

    def test_raw_l16_uses_the_rate_in_the_mime_type(self):
        raw = struct.pack("<h", 1000) * 16000  # 1 s at 16 kHz
        self.assertAlmostEqual(len(to_pcm16_24k(raw, "audio/L16;codec=pcm;rate=16000")), 48000, delta=8)


class VoiceChoiceTest(unittest.TestCase):
    def test_google_voice_speaks_in_half_second_slices(self):
        google, local = FakeGoogle(), FakeLocal()
        chunks = list(GoogleVoice(google, "Kore", fallback=local).stream("Hello.", None))
        self.assertEqual(google.calls, [("Hello.", "Kore")])
        self.assertEqual(len(chunks), 2)
        self.assertEqual(local.calls, [])

    def test_quota_or_outage_falls_back_to_the_local_voice(self):
        google, local = FakeGoogle(fail=True), FakeLocal()
        chunks = list(GoogleVoice(google, "Kore", fallback=local).stream("Hello.", "speaker.wav"))
        self.assertEqual(local.calls, ["Hello."])
        self.assertTrue(chunks)

    def test_session_voice_choice(self):
        google, local = FakeGoogle(), FakeLocal()
        self.assertIsInstance(pick_tts(local, google, claims("google", "Puck")), GoogleVoice)
        self.assertIs(pick_tts(local, google, claims("xtts")), local)
        self.assertIs(pick_tts(local, google, claims()), local)               # older tokens
        self.assertIs(pick_tts(local, None, claims("google", "Puck")), local)  # no API key

    def test_token_carries_the_voice_choice(self):
        import jwt
        from app.config import get_settings
        s = get_settings()
        token = jwt.encode({"session_id": 1, "project_id": 2, "exp": int(time.time()) + 60,
                            "tts_provider": "google", "tts_voice": "Kore"},
                           s.python_jwt_secret, algorithm=s.python_jwt_algorithm)
        c = decode_token(token)
        self.assertEqual((c.tts_provider, c.tts_voice), ("google", "Kore"))


class QuotaSaversTest(unittest.TestCase):
    """GoogleTTS against a fake Google: remembered phrases, model rotation on 429."""

    def google(self, limited=()):
        import base64
        import httpx
        from app.services.google_tts import GoogleTTS
        self.requests = []

        def handler(request: httpx.Request) -> httpx.Response:
            model = request.url.path.split("/models/")[1].split(":")[0]
            self.requests.append(model)
            if model in limited:
                return httpx.Response(429, json={"error": {"code": 429, "message": "quota", "details": [
                    {"@type": "type.googleapis.com/google.rpc.RetryInfo", "retryDelay": "38s"}]}})
            data = base64.b64encode(wav_bytes(24000, 0.5)).decode()
            return httpx.Response(200, json={"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "audio/wav", "data": data}}]}}]})

        return GoogleTTS("key", "lite", fallback_models=["flash", "preview"],
                         http=httpx.Client(transport=httpx.MockTransport(handler)))

    def test_the_greeting_costs_one_request_not_one_per_call(self):
        g = self.google()
        for _ in range(5):
            g.synthesize("Hi! Thanks for calling. How can I help you?", "Zephyr")
        self.assertEqual(self.requests, ["lite"])

    def test_a_rate_limited_model_hands_over_to_the_next_and_rests(self):
        g = self.google(limited={"lite"})
        g.synthesize("First reply sentence that is long enough to matter here, honestly.", "Zephyr")
        g.synthesize("Second reply.", "Zephyr")
        # lite was tried once, then rested for its retry delay.
        self.assertEqual(self.requests, ["lite", "flash", "flash"])

    def test_every_model_limited_fails_fast_without_asking_again(self):
        g = self.google(limited={"lite", "flash", "preview"})
        with self.assertRaises(GoogleTTSError):
            g.synthesize("Hello.", "Zephyr")
        with self.assertRaises(GoogleTTSError):
            g.synthesize("Hello again.", "Zephyr")  # all resting: no new requests
        self.assertEqual(self.requests, ["lite", "flash", "preview"])


class StreamingTest(unittest.TestCase):
    """GoogleTTS.stream against a fake streamGenerateContent (SSE)."""

    def google(self, limited=(), chunks=3):
        import base64, json
        import httpx
        from app.services.google_tts import GoogleTTS
        self.requests = []

        def handler(request: httpx.Request) -> httpx.Response:
            model = request.url.path.split("/models/")[1].split(":")[0]
            self.requests.append((model, request.url.path.split(":")[-1]))
            if model in limited:
                return httpx.Response(429, json={"error": {"message": "quota", "details": [
                    {"@type": "type.googleapis.com/google.rpc.RetryInfo", "retryDelay": "20s"}]}})
            pcm = struct.pack("<h", 500) * 2400  # 0.1 s per event
            events = "".join("data: " + json.dumps({"candidates": [{"content": {"parts": [{"inlineData": {
                "mimeType": "audio/L16;codec=pcm;rate=24000", "data": base64.b64encode(pcm).decode()}}]}}]}) + "\n\n"
                for _ in range(chunks))
            return httpx.Response(200, text=events, headers={"content-type": "text/event-stream"})

        return GoogleTTS("key", "lite", fallback_models=["flash"], http=httpx.Client(transport=httpx.MockTransport(handler)))

    def test_audio_arrives_in_chunks_as_google_makes_it(self):
        g = self.google(chunks=3)
        parts = list(g.stream("Hello there.", "Zephyr"))
        self.assertEqual(len(parts), 3)
        self.assertEqual(self.requests, [("lite", "streamGenerateContent")])

    def test_a_streamed_greeting_is_remembered(self):
        g = self.google()
        list(g.stream("Hi! Thanks for calling.", "Zephyr"))
        list(g.stream("Hi! Thanks for calling.", "Zephyr"))
        self.assertEqual(len(self.requests), 1)

    def test_a_rate_limited_model_hands_over_when_streaming_too(self):
        g = self.google(limited={"lite"})
        self.assertTrue(list(g.stream("Hello.", "Zephyr")))
        self.assertEqual([m for m, _ in self.requests], ["lite", "flash"])

    def test_no_second_voice_after_half_a_sentence(self):
        class HalfThenFail:
            def stream(self, text, voice, style=None):
                yield b"\x01\x00" * 2400
                raise GoogleTTSError("network: reset")
        local = FakeLocal()
        list(GoogleVoice(HalfThenFail(), "Kore", fallback=local).stream("Hello.", None))
        self.assertEqual(local.calls, [])  # repeating it in another voice would be worse


class CallWithGoogleVoiceTest(unittest.IsolatedAsyncioTestCase):
    async def test_call_speaks_with_the_chosen_google_voice(self):
        google = FakeGoogle()
        transport = FakeTransport()
        call = make_call(FakeSTT("thanks"), FakeLLM("You're welcome."), FakeLaravel(), transport)
        call.claims = claims("google", "Kore")
        call.tts = pick_tts(call.s.tts, google, call.claims)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual(google.calls, [("You're welcome.", "Kore")])
        self.assertTrue(transport.played)


class TtsRouteTest(unittest.TestCase):
    def client(self, google):
        from fastapi import FastAPI
        from fastapi.testclient import TestClient
        from app.api.routes import tts
        app = FastAPI()
        app.include_router(tts.router)
        app.state.tts_service = FakeLocal()
        app.state.google_tts = google
        return TestClient(app)

    def test_preview_in_a_google_voice_returns_a_wav(self):
        resp = self.client(FakeGoogle()).post("/tts", data={"text": "Hi", "provider": "google", "voice": "Kore"})
        self.assertEqual(resp.status_code, 200)
        with wave.open(io.BytesIO(resp.content)) as w:
            self.assertEqual((w.getframerate(), w.getnchannels()), (24000, 1))

    def test_quota_is_reported_as_429_not_a_crash(self):
        resp = self.client(FakeGoogle(fail=True)).post("/tts", data={"text": "Hi", "provider": "google", "voice": "Kore"})
        self.assertEqual(resp.status_code, 429)


if __name__ == "__main__":
    unittest.main()
