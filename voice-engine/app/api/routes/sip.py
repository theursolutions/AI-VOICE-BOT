"""SIP accounts on our Asterisk, managed by Laravel.

Laravel owns the list (client extensions in the admin panel, one-call
accounts for website "Call us" buttons); this pushes each one to Asterisk
over ARI, live. Behind ``X-Internal-Secret`` (Laravel ↔ engine only).

    PUT    /internal/sip/accounts/{name}   create or update
    DELETE /internal/sip/accounts/{name}
    GET    /internal/sip/accounts?prefix=c12_   → {"accounts": {name: state}}
"""

from __future__ import annotations

import logging
from typing import Literal

import httpx
from fastapi import APIRouter, Depends, HTTPException, Request
from pydantic import BaseModel, Field

from app.api.deps import require_internal_secret
from app.calls.asterisk import AccountError

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/internal/sip", dependencies=[Depends(require_internal_secret)])


class AccountIn(BaseModel):
    password: str = Field(min_length=12, max_length=128)
    kind: Literal["app", "webrtc", "guest"]
    context: str
    callerid: str = Field(default="", max_length=120)


def _gateway(request: Request):
    gateway = getattr(request.app.state, "asterisk", None)
    if gateway is None:
        raise HTTPException(status_code=503, detail="Asterisk is not configured on this voice engine")
    return gateway


@router.put("/accounts/{name}")
async def put_account(name: str, body: AccountIn, request: Request) -> dict:
    gateway = _gateway(request)
    try:
        await gateway.put_account(name, body.password, body.kind, body.context, body.callerid)
    except AccountError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    except httpx.HTTPError as exc:
        logger.warning("SIP account %s not pushed: %s", name, exc)
        raise HTTPException(status_code=502, detail=f"Asterisk refused the account: {exc}") from exc
    return {"ok": True, "name": name}


@router.delete("/accounts/{name}")
async def delete_account(name: str, request: Request) -> dict:
    await _gateway(request).delete_account(name)
    return {"ok": True}


@router.get("/accounts")
async def account_states(request: Request, prefix: str) -> dict:
    if len(prefix) < 2:
        raise HTTPException(status_code=422, detail="prefix too short")
    try:
        return {"accounts": await _gateway(request).account_states(prefix)}
    except httpx.HTTPError as exc:
        raise HTTPException(status_code=502, detail=f"Asterisk unreachable: {exc}") from exc
