"""Provider-agnostic phone/SIP call core.

A call is one :class:`~app.calls.session.CallSession` (listen → STT → LLM →
TTS, with history, persona, transcripts and barge-in) plus one
:class:`~app.calls.transport.CallTransport` that moves audio for a provider
(Twilio Media Streams today, Asterisk AudioSocket next).
"""
