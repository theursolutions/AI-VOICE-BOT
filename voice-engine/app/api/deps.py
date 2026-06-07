"""FastAPI dependencies — settings and JWT-bearer auth."""

from __future__ import annotations

from typing import Annotated, Optional

from fastapi import Depends, Header, HTTPException, Query, status

from app.config import Settings, get_settings
from app.domain.auth import AuthError, SessionClaims, decode_token


SettingsDep = Annotated[Settings, Depends(get_settings)]


def _extract_bearer(authorization: Optional[str]) -> Optional[str]:
    if not authorization:
        return None
    parts = authorization.split(" ", 1)
    if len(parts) == 2 and parts[0].lower() == "bearer":
        return parts[1].strip()
    return authorization.strip()


def require_session(
    authorization: Optional[str] = Header(default=None),
    token: Optional[str] = Query(default=None),
) -> SessionClaims:
    """Resolve a :class:`SessionClaims` from either a Bearer header or
    a ``?token=`` query parameter (used by browser EventSource clients).
    """

    raw = _extract_bearer(authorization) or token
    if not raw:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="missing JWT",
        )
    try:
        return decode_token(raw)
    except AuthError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED, detail=str(exc)
        ) from exc


def require_internal_secret(
    x_internal_secret: Optional[str] = Header(default=None, alias="X-Internal-Secret"),
    settings: SettingsDep = None,  # type: ignore[assignment]
) -> None:
    """Reject calls that don't carry the shared ``X-Internal-Secret``."""

    if settings is None:
        settings = get_settings()
    if not x_internal_secret or x_internal_secret != settings.python_internal_secret:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="invalid internal secret",
        )


SessionDep = Annotated[SessionClaims, Depends(require_session)]
