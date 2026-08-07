"""Liveness / readiness probes."""

from __future__ import annotations

from fastapi import APIRouter, Request

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
