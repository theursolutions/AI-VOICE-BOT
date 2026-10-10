"""Asterisk AudioSocket — the audio half of an Asterisk call.

For every call, the ARI client asks Asterisk for an ``externalMedia``
channel with ``encapsulation=audiosocket``; Asterisk then opens one TCP
connection to our server and speaks a tiny framed protocol:

    [kind: 1 byte][length: 2 bytes, big-endian][payload]

    0x00  hangup
    0x01  call id — 16-byte UUID, always the first frame
    0x03  DTMF digit (newer Asterisk versions)
    0x10  audio — signed 16-bit little-endian PCM, 8 kHz mono
    0xff  error

The call id is the one we chose when creating the channel, so a
connection whose id we never issued is refused — that is what stops a
stranger who can reach the port from injecting audio into a call.

Audio back to Asterisk must arrive at real time: Asterisk plays frames as
they come, so a burst would be garbled or dropped. The transport paces
20 ms frames on a clock.
"""

from __future__ import annotations

import asyncio
import audioop
import logging
import uuid
from typing import Awaitable, Callable, Dict, Optional, Tuple

logger = logging.getLogger(__name__)

KIND_HANGUP = 0x00
KIND_UUID = 0x01
KIND_DTMF = 0x03
KIND_AUDIO = 0x10
KIND_ERROR = 0xFF

RATE = 8000
FRAME_SECONDS = 0.02
FRAME_BYTES = int(RATE * FRAME_SECONDS) * 2  # 320 bytes of PCM16
LEAD_SECONDS = 0.06  # sent ahead of the clock to ride out event-loop jitter

Handler = Callable[[asyncio.StreamReader, asyncio.StreamWriter], Awaitable[None]]


def encode(kind: int, payload: bytes = b"") -> bytes:
    return bytes([kind]) + len(payload).to_bytes(2, "big") + payload


async def read_frame(reader: asyncio.StreamReader) -> Tuple[int, bytes]:
    header = await reader.readexactly(3)
    length = int.from_bytes(header[1:3], "big")
    payload = await reader.readexactly(length) if length else b""
    return header[0], payload


class AudioSocketTransport:
    """Plays the AI's voice into an AudioSocket connection, paced at real time."""

    provider = "asterisk"
    bills_minutes = True  # Asterisk has no billing webhook; the session meters

    def __init__(self, writer: asyncio.StreamWriter,
                 on_transfer: Optional[Callable[[], None]] = None,
                 on_hangup: Optional[Callable[[], None]] = None) -> None:
        self._writer = writer
        self._on_transfer = on_transfer
        self._on_hangup = on_hangup
        self.can_transfer = on_transfer is not None
        self._pending = bytearray()      # PCM16 8 kHz not yet sent
        self._resample_state = None
        self._wake = asyncio.Event()
        self._drained = asyncio.Event()
        self._drained.set()
        self._pacer = asyncio.create_task(self._pace())

    async def play(self, pcm16: bytes, sample_rate: int) -> float:
        if sample_rate != RATE:
            pcm16, self._resample_state = audioop.ratecv(
                pcm16, 2, 1, sample_rate, RATE, self._resample_state)
        self._pending.extend(pcm16)
        self._drained.clear()
        self._wake.set()
        return len(pcm16) / 2 / RATE

    async def stop_playback(self) -> None:
        self._pending.clear()
        self._resample_state = None
        self._drained.set()

    async def transfer(self) -> None:
        """Let the AI's last sentence finish, then hand the call over."""
        await self._drained.wait()
        if self._on_transfer:
            self._on_transfer()

    async def hang_up(self) -> None:
        """Let the goodbye finish, then end the call."""
        await self._drained.wait()
        if self._on_hangup:
            self._on_hangup()
        else:
            # Asterisk ends the AudioSocket leg on a hangup frame.
            try:
                self._writer.write(encode(KIND_HANGUP))
                await self._writer.drain()
            except ConnectionError:
                pass

    def close(self) -> None:
        self._pacer.cancel()

    async def _pace(self) -> None:
        loop = asyncio.get_running_loop()
        next_at: Optional[float] = None
        try:
            while True:
                if not self._pending:
                    self._drained.set()
                    next_at = None
                    self._wake.clear()
                    await self._wake.wait()
                    continue

                frame = bytes(self._pending[:FRAME_BYTES])
                del self._pending[:FRAME_BYTES]
                if len(frame) < FRAME_BYTES:
                    frame += b"\x00" * (FRAME_BYTES - len(frame))

                if next_at is None:
                    next_at = loop.time() - LEAD_SECONDS
                self._writer.write(encode(KIND_AUDIO, frame))
                await self._writer.drain()

                next_at += FRAME_SECONDS
                delay = next_at - loop.time()
                if delay > 0:
                    await asyncio.sleep(delay)
                elif delay < -0.25:
                    # Fell far behind (loop was busy): resync rather than
                    # blasting a backlog Asterisk would garble.
                    next_at = loop.time()
        except (ConnectionError, asyncio.CancelledError):
            pass
        finally:
            # Nothing more will play: never leave a pending transfer waiting.
            self._drained.set()


class AudioSocketServer:
    """Accepts Asterisk's AudioSocket connections and hands each one to the
    call that is expecting it."""

    def __init__(self, bind: str) -> None:
        host, _, port = bind.rpartition(":")
        self.host = host or "0.0.0.0"
        self.port = int(port)
        self._expected: Dict[str, Handler] = {}
        self._server: Optional[asyncio.AbstractServer] = None

    def expect(self, call_id: str, handler: Handler) -> None:
        self._expected[call_id] = handler

    def forget(self, call_id: str) -> None:
        self._expected.pop(call_id, None)

    async def start(self) -> None:
        self._server = await asyncio.start_server(self._accept, self.host, self.port)
        logger.info("AudioSocket listening on %s:%s", self.host, self.port)

    async def close(self) -> None:
        if self._server:
            self._server.close()
            await self._server.wait_closed()

    @property
    def bound_port(self) -> int:
        """The real port (useful when bound to port 0 in tests)."""
        return self._server.sockets[0].getsockname()[1] if self._server else self.port

    async def _accept(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        peer = writer.get_extra_info("peername")
        try:
            kind, payload = await asyncio.wait_for(read_frame(reader), timeout=5)
            if kind != KIND_UUID or len(payload) != 16:
                logger.warning("AudioSocket %s: expected a call id, got kind=0x%02x", peer, kind)
                return
            call_id = str(uuid.UUID(bytes=payload))
            handler = self._expected.pop(call_id, None)
            if handler is None:
                logger.warning("AudioSocket %s: unknown call id %s — refused", peer, call_id)
                return
            await handler(reader, writer)
        except (asyncio.TimeoutError, asyncio.IncompleteReadError, ConnectionError):
            pass
        except Exception:  # noqa: BLE001 — one bad call must not stop the server
            logger.exception("AudioSocket %s: call failed", peer)
        finally:
            writer.close()
