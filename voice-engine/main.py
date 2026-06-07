"""Thin shim: the canonical app now lives in :mod:`app.api.http`.

The legacy endpoints (``/process``, ``/generate-response``, ``/AI-bot``,
``/AI-bot-voice``) are still served, but under the ``/legacy`` prefix
and with a deprecation log on every call. Migrate clients to:

* ``POST /llm/respond``  (was ``/AI-bot`` / ``/generate-response``)
* ``POST /stt``          (was ``/process`` for audio input)
* ``POST /tts``          (was the audio half of ``/AI-bot-voice``)
* ``POST /extract``      (new: lead extraction)
* ``WS   /ws/turn``      (new: full-duplex streaming pipeline)

Run with::

    uvicorn app.api.http:app --host 0.0.0.0 --port 8000
"""

from app.api.http import app  # noqa: F401  re-export for backwards compat
