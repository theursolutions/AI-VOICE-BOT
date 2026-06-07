"""JWT helpers for the data-plane WebSocket and REST endpoints.

Tokens are minted by Laravel using HS256 with ``PYTHON_JWT_SECRET`` and
must contain at minimum ``session_id``, ``project_id``, and ``exp``.
``voice_id`` is optional but expected for voice-enabled sessions.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Optional

import jwt

from app.config import get_settings


class AuthError(Exception):
    """Raised when a JWT cannot be verified or is missing required claims."""


@dataclass(frozen=True)
class SessionClaims:
    session_id: int
    project_id: int
    voice_id: Optional[int]
    # Absolute path on disk to the speaker reference WAV. Resolved by
    # Laravel at mint time so Python doesn't have to look it up.
    speaker_wav: Optional[str]
    # Language code for STT + TTS. Defaults to "en" if not provided.
    language: str
    # Channel ("web", "voice", "phone", "sms") — propagates into
    # metadata so we can split analytics later.
    channel: Optional[str]
    raw: Dict[str, Any]


def decode_token(token: str) -> SessionClaims:
    """Verify ``token`` and return the parsed claims.

    Raises :class:`AuthError` on any failure (signature, expiry,
    missing claims).
    """

    settings = get_settings()
    if not token:
        raise AuthError("missing token")

    try:
        payload = jwt.decode(
            token,
            settings.python_jwt_secret,
            algorithms=[settings.python_jwt_algorithm],
        )
    except jwt.ExpiredSignatureError as exc:
        raise AuthError("token expired") from exc
    except jwt.InvalidTokenError as exc:
        raise AuthError(f"invalid token: {exc}") from exc

    try:
        session_id = int(payload["session_id"])
        project_id = int(payload["project_id"])
    except (KeyError, TypeError, ValueError) as exc:
        raise AuthError("missing session_id/project_id claim") from exc

    voice_id_raw = payload.get("voice_id")
    voice_id = int(voice_id_raw) if voice_id_raw is not None else None

    speaker_wav = payload.get("speaker_wav")
    if isinstance(speaker_wav, str) and not speaker_wav.strip():
        speaker_wav = None

    language = (payload.get("language") or "en").strip() or "en"
    channel  = payload.get("channel")

    return SessionClaims(
        session_id=session_id,
        project_id=project_id,
        voice_id=voice_id,
        speaker_wav=speaker_wav,
        language=language,
        channel=channel,
        raw=payload,
    )
