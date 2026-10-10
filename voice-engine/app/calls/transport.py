"""The audio side of a call — one implementation per telephony provider."""

from __future__ import annotations

from typing import Protocol


class CallTransport(Protocol):
    """Moves the AI's voice to the caller.

    Inbound audio flows the other way: the provider adapter decodes it to
    PCM16 16 kHz mono and hands it to ``CallSession.feed_audio``.

    Optional capabilities, read with ``getattr`` so a transport that
    lacks them simply doesn't offer them:

    * ``can_transfer: bool`` + ``async transfer()`` — move the caller to a
      person once the AI's last words have played (Asterisk).
    * ``async hang_up()`` — end the call (after the AI's goodbye, or when
      the line stayed silent). Without it the call simply stays open.
    * ``bills_minutes: bool`` — the session reports call length for
      per-minute billing, because the provider has no billing webhook of
      its own (Asterisk; Twilio's status callback bills Twilio calls).
    """

    #: Provider name stored with each turn ("twilio", "asterisk", …).
    provider: str

    async def play(self, pcm16: bytes, sample_rate: int) -> float:
        """Queue PCM16 mono audio for the caller.

        Returns how many seconds of audio were queued, so the session knows
        when the caller stops hearing the AI (needed for barge-in).
        """
        ...

    async def stop_playback(self) -> None:
        """Drop audio that is queued but not yet heard (barge-in)."""
        ...
