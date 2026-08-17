"""Liveness / readiness probes."""

from __future__ import annotations

import asyncio

from fastapi import APIRouter, Request

from app.domain.schemas import ChatMessage

router = APIRouter()


@router.get("/healthz")
async def healthz() -> dict:
    return {"status": "ok"}


@router.get("/metrics")
async def metrics(request: Request) -> dict:
    """Lightweight live metrics for the admin compute-mesh dashboard."""
    app = request.app
    active_calls = 0
    try:  # WhatsApp WebRTC bridge keeps in-flight calls in a module dict.
        from app.integrations.meta import whatsapp_call
        active_calls = len(getattr(whatsapp_call, "_calls", {}) or {})
    except Exception:  # noqa: BLE001
        pass

    settings = getattr(app.state, "settings", None)
    return {
        "ok": True,
        "active_calls": active_calls,
        "llm_provider": getattr(settings, "llm_provider", None),
        "llm_fallback": (getattr(settings, "llm_fallback_provider", "") or None),
        "stt_ready": getattr(app.state, "stt_service", None) is not None,
        "tts_ready": getattr(app.state, "tts_service", None) is not None,
    }


@router.get("/healthz/llm")
async def healthz_llm(request: Request) -> dict:
    """Actually CALL every tier of the fallback chain and report which answer.

    Deliberately separate from /healthz, which must stay free and instant for
    the container probe — this one spends a few tokens per tier and can take
    seconds if a local model has to load.

    It exists because a broken fallback is invisible until the moment it is
    needed: with the primary healthy, a chain pointing at an ollama host that
    was never deployed looks exactly like a working one. Run this after a
    deploy, and a tier that would have failed silently at quota time shows up
    as ok=false with the reason attached.
    """
    llm = getattr(request.app.state, "llm_service", None)
    if llm is None:
        return {"ok": False, "error": "llm_service not initialised"}

    async def probe(backend) -> dict:
        entry = {"backend": type(backend).__name__,
                 "model": getattr(backend, "model", None)}
        try:
            # Smallest call that still proves the model is loadable and the
            # credentials work — a name that was never pulled 404s right here.
            result = await asyncio.wait_for(
                backend.chat([ChatMessage(role="user", content="ping")],
                             max_tokens=1),
                timeout=90,
            )
            entry["ok"] = True
            entry["model_reported"] = result.model
        except Exception as exc:  # noqa: BLE001
            entry["ok"] = False
            entry["error"] = f"{type(exc).__name__}: {exc}"[:300]
        return entry

    primary = await probe(llm._backend)
    primary["role"] = "primary"
    tiers = [primary]
    for fb in llm._fallbacks:
        entry = await probe(fb)
        entry["role"] = "fallback"
        tiers.append(entry)

    return {
        # The chain is only healthy if SOMETHING can still answer after the
        # primary's quota runs out — a working primary is not the question.
        "ok": any(t["ok"] for t in tiers[1:]) if len(tiers) > 1 else False,
        "provider": llm.provider,
        "fallback_count": len(llm._fallbacks),
        "tiers": tiers,
    }
