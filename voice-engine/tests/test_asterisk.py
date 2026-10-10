"""Tests for the Asterisk side: AudioSocket and the ARI gateway.

ARI is faked with httpx.MockTransport, the AudioSocket server is real
(bound to a free local port), speech/LLM/TTS/Laravel are the fakes from
test_call_session.

    cd voice-engine && .venv/Scripts/python -m unittest discover -s tests -v
"""

from __future__ import annotations

import asyncio
import struct
import time
import unittest
import uuid
from types import SimpleNamespace

import httpx
import jwt

from app.calls.asterisk import AsteriskGateway
from app.calls.audiosocket import (
    FRAME_BYTES,
    KIND_AUDIO,
    KIND_UUID,
    AudioSocketServer,
    AudioSocketTransport,
    encode,
    read_frame,
)
from app.calls.session import ACTIVE_CALLS, CallServices
from app.config import get_settings
from test_call_session import FakeLaravel, FakeLLM, FakeSTT, FakeTTS, wait_for


def pcm8k(ms: int, amplitude: int) -> bytes:
    # 60 ms loud, 60 ms soft: speech-like, not a steady tone (which the call
    # now treats as line noise).
    return b"".join(struct.pack("<h", amplitude if (i // 480) % 2 == 0 else amplitude // 4)
                    for i in range(8000 * ms // 1000))


class FakeWriter:
    def __init__(self) -> None:
        self.frames: list[tuple[float, bytes]] = []

    def write(self, data: bytes) -> None:
        self.frames.append((time.monotonic(), data))

    async def drain(self) -> None:
        pass


class AudioSocketProtocolTest(unittest.IsolatedAsyncioTestCase):
    async def test_frames_round_trip(self):
        reader = asyncio.StreamReader()
        reader.feed_data(encode(KIND_AUDIO, b"\x01\x02") + encode(0x00))
        self.assertEqual(await read_frame(reader), (KIND_AUDIO, b"\x01\x02"))
        self.assertEqual(await read_frame(reader), (0x00, b""))

    async def test_audio_goes_out_paced_at_real_time_not_in_a_burst(self):
        writer = FakeWriter()
        transport = AudioSocketTransport(writer)
        seconds = await transport.play(pcm8k(200, 1000), 8000)  # 200 ms = 10 frames
        self.assertAlmostEqual(seconds, 0.2)
        await transport.transfer()  # returns once everything has been sent
        transport.close()

        self.assertEqual(len(writer.frames), 10)
        self.assertTrue(all(len(f) == 3 + FRAME_BYTES for _, f in writer.frames))
        spread = writer.frames[-1][0] - writer.frames[0][0]
        self.assertGreater(spread, 0.1)  # a burst would be ~0 s

    async def test_barge_in_drops_audio_not_yet_sent(self):
        writer = FakeWriter()
        transport = AudioSocketTransport(writer)
        await transport.play(pcm8k(2000, 1000), 8000)  # 2 s queued
        await asyncio.sleep(0.05)
        await transport.stop_playback()
        await asyncio.sleep(0.1)
        transport.close()
        self.assertLess(len(writer.frames), 20)

    async def test_unknown_call_ids_are_refused(self):
        server = AudioSocketServer("127.0.0.1:0")
        await server.start()
        handled = []

        async def handler(reader, writer):
            handled.append(True)

        server.expect(str(uuid.uuid4()), handler)
        reader, writer = await asyncio.open_connection("127.0.0.1", server.bound_port)
        writer.write(encode(KIND_UUID, uuid.uuid4().bytes))  # not the expected id
        await writer.drain()
        self.assertEqual(await reader.read(), b"")  # server hung up
        writer.close()
        await server.close()
        self.assertEqual(handled, [])


# ── ARI gateway ────────────────────────────────────────────────────────


class FakeAri:
    """Records ARI requests and answers like Asterisk would."""

    def __init__(self) -> None:
        self.requests: list[tuple[str, str, dict]] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path.removeprefix("/ari")
        params = dict(request.url.params)
        self.requests.append((request.method, path, params))
        if request.method == "POST" and path == "/bridges":
            return httpx.Response(200, json={"id": "bridge-1"})
        if request.method == "POST" and path == "/channels/externalMedia":
            return httpx.Response(200, json={"id": params["channelId"]})
        return httpx.Response(204)

    def find(self, method: str, path: str):
        return [p for m, pth, p in self.requests if m == method and pth == path]


class AsteriskLaravel(FakeLaravel):
    def __init__(self, start: dict, **kw) -> None:
        super().__init__(**kw)
        self.start = start

    async def asterisk_call_start(self, channel_id, caller, dialed, endpoint=""):
        self.started = (channel_id, caller, dialed)
        self.endpoint = endpoint
        return self.start


def token() -> str:
    s = get_settings()
    return jwt.encode({"session_id": 9, "project_id": 4, "channel": "phone",
                       "exp": int(time.time()) + 60},
                      s.python_jwt_secret, algorithm=s.python_jwt_algorithm)


class AsteriskGatewayTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.ari = FakeAri()

    async def asyncTearDown(self):
        await self.gateway.audiosocket.close()
        ACTIVE_CALLS.clear()

    async def make_gateway(self, laravel, stt, llm):
        settings = SimpleNamespace(
            asterisk_ari_url="http://ari.test", asterisk_ari_user="ai-crm",
            asterisk_ari_password="secret", asterisk_ari_app="ai-crm",
            audiosocket_bind="127.0.0.1:0", audiosocket_advertise="10.0.0.5:9092",
            default_speaker_wav="speaker.wav", call_barge_in=True,
            call_barge_in_ms=400, call_history_turns=10,
        )
        services = CallServices(stt=stt, llm=llm, tts=FakeTTS(), laravel=laravel, settings=settings)
        http = httpx.AsyncClient(base_url="http://ari.test/ari",
                                 transport=httpx.MockTransport(self.ari))
        self.gateway = AsteriskGateway(services, settings, http=http)
        await self.gateway.audiosocket.start()
        return self.gateway

    def stasis_start(self, channel_id="chan-1", args=("inbound", "1122")):
        self.gateway.handle_event({
            "type": "StasisStart", "args": list(args),
            "channel": {"id": channel_id, "caller": {"number": "03001234567"}},
        })

    async def test_website_call_is_time_limited_and_its_one_call_account_removed(self):
        laravel = AsteriskLaravel({"ok": True, "token": token(), "greeting": "Hi!", "max_seconds": 60})
        await self.make_gateway(laravel, FakeSTT(), FakeLLM())
        self.stasis_start(args=("inbound", "1", "wq7k2m9x4p1a"))
        media, reader, writer = await self.connect_ai_leg()

        self.assertEqual(laravel.endpoint, "wq7k2m9x4p1a")
        # Asterisk itself hangs up shortly after the limit, whatever happens.
        self.assertEqual(self.ari.find("POST", "/channels/chan-1/variable"),
                         [{"variable": "TIMEOUT(absolute)", "value": "68"}])
        await wait_for(lambda: self.gateway.calls["chan-1"].session is not None)
        self.assertEqual(self.gateway.calls["chan-1"].session.max_seconds, 60)

        self.gateway.handle_event({"type": "StasisEnd", "channel": {"id": "chan-1"}})
        await wait_for(lambda: self.ari.find("DELETE", "/asterisk/config/dynamic/res_pjsip/auth/wq7k2m9x4p1a"))
        self.assertTrue(self.ari.find("DELETE", "/asterisk/config/dynamic/res_pjsip/endpoint/wq7k2m9x4p1a"))
        writer.close()

    async def test_accounts_go_only_into_contexts_with_no_outside_line(self):
        from app.calls.asterisk import AccountError
        gateway = await self.make_gateway(AsteriskLaravel({}), FakeSTT(), FakeLLM())
        with self.assertRaises(AccountError):
            await gateway.put_account("c3_2001", "a-long-password", "app", "from-trunk")
        with self.assertRaises(AccountError):
            await gateway.put_account("C3 2001!", "a-long-password", "app", "internal-calls")
        with self.assertRaises(AccountError):
            await gateway.put_account("c3_2001", "short", "app", "internal-calls")

        await gateway.put_account("c3_2002", "a-long-password", "webrtc", "internal-calls", "Ali <2002>")
        puts = [(m, p) for m, p, _ in self.ari.requests if m == "PUT"]
        self.assertEqual([p.rsplit("/", 2)[-2] for _, p in puts], ["auth", "aor", "endpoint"])

    async def connect_ai_leg(self):
        await wait_for(lambda: self.ari.find("POST", "/channels/externalMedia"))
        media = self.ari.find("POST", "/channels/externalMedia")[0]
        reader, writer = await asyncio.open_connection("127.0.0.1", self.gateway.audiosocket.bound_port)
        writer.write(encode(KIND_UUID, uuid.UUID(media["data"]).bytes))
        await writer.drain()
        return media, reader, writer

    async def say(self, writer, ms, amplitude):
        audio = pcm8k(ms, amplitude)
        for i in range(0, len(audio), FRAME_BYTES):
            writer.write(encode(KIND_AUDIO, audio[i:i + FRAME_BYTES]))
        await writer.drain()

    async def test_call_is_answered_bridged_greeted_and_transferred_on_request(self):
        laravel = AsteriskLaravel({"ok": True, "token": token(), "greeting": "Hello, thanks for calling.",
                                   "transfer_to": "1001"}, handoff=True)
        await self.make_gateway(laravel, FakeSTT("Can I talk to a person?"),
                                FakeLLM("Sure, connecting you now."))
        self.stasis_start()
        media, reader, writer = await self.connect_ai_leg()

        # Asterisk was driven the way the call needs.
        self.assertEqual(laravel.started, ("chan-1", "03001234567", "1122"))
        self.assertTrue(self.ari.find("POST", "/channels/chan-1/answer"))
        self.assertEqual(self.ari.find("POST", "/bridges"), [{"type": "mixing"}])
        self.assertEqual(media["encapsulation"], "audiosocket")
        self.assertEqual(media["transport"], "tcp")
        self.assertEqual(media["format"], "slin")
        self.assertEqual(media["external_host"], "10.0.0.5:9092")
        self.assertEqual(media["channelId"], "ai-" + media["data"])
        self.assertEqual([p["channel"] for p in self.ari.find("POST", "/bridges/bridge-1/addChannel")],
                         ["chan-1", media["channelId"]])

        # The greeting comes back as AudioSocket audio.
        kind, payload = await asyncio.wait_for(read_frame(reader), 3)
        self.assertEqual((kind, len(payload)), (KIND_AUDIO, FRAME_BYTES))

        # Caller asks for a person → the AI answers, then hands the call over.
        await self.say(writer, 400, 3000)
        await self.say(writer, 800, 0)
        await wait_for(lambda: self.ari.find("POST", "/channels/chan-1/continue"), timeout=6)
        self.assertEqual(self.ari.find("POST", "/channels/chan-1/continue"),
                         [{"context": "ai-transfer", "extension": "1001", "priority": "1"}])
        await wait_for(lambda: laravel.ended)

        self.assertTrue(laravel.turns[-1].metadata.get("handoff"))
        self.assertEqual(laravel.turns[-1].metadata["provider"], "asterisk")
        self.assertTrue(self.ari.find("DELETE", f"/channels/{media['channelId']}"))  # AI leg dropped
        self.assertFalse(self.ari.find("DELETE", "/channels/chan-1"))  # caller kept, transferred
        self.assertGreaterEqual(laravel.call_seconds, 1)               # Asterisk minutes metered
        writer.close()

    async def test_number_nobody_owns_is_hung_up(self):
        laravel = AsteriskLaravel({"ok": False, "reason": "not_configured"})
        await self.make_gateway(laravel, FakeSTT(), FakeLLM())
        self.stasis_start("chan-2")
        await wait_for(lambda: self.ari.find("DELETE", "/channels/chan-2"))
        self.assertFalse(self.ari.find("POST", "/channels/chan-2/answer"))

    async def test_caller_hanging_up_tears_down_the_ai_leg(self):
        laravel = AsteriskLaravel({"ok": True, "token": token(), "greeting": ""})
        await self.make_gateway(laravel, FakeSTT(), FakeLLM())
        self.stasis_start()
        media, reader, writer = await self.connect_ai_leg()
        await wait_for(lambda: ACTIVE_CALLS)

        self.gateway.handle_event({"type": "StasisEnd", "channel": {"id": "chan-1"}})
        await wait_for(lambda: laravel.ended)
        self.assertTrue(self.ari.find("DELETE", f"/channels/{media['channelId']}"))
        self.assertTrue(self.ari.find("DELETE", "/bridges/bridge-1"))
        self.assertEqual(self.gateway.calls, {})
        writer.close()


if __name__ == "__main__":
    unittest.main()
