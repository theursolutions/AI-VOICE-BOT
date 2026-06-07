"""Admin hot-swap endpoints — primarily used by Laravel's Brain
Settings page to apply env changes without restarting uvicorn.

Reload flow:
  1. Laravel writes new LLM_PROVIDER / GROQ_API_KEY / WHISPER_DEVICE /
     COQUI_USE_GPU / etc. into voice-engine/.env via EnvManager.
  2. Admin clicks "Reload Python" → Laravel POSTs to /admin/reload.
  3. This handler:
        - clears the Settings lru_cache so the next get_settings() pulls
          fresh values from .env
        - tears down + re-creates the LLM service (cheap)
        - tears down + re-creates the STT + TTS services (expensive —
          model load is 10-30s for XTTS, only do it when device changes)
  4. Returns 200 once the services are healthy again.

Protected by the same X-Internal-Secret used elsewhere.
"""

from __future__ import annotations

import logging
import os
from typing import Optional

from dotenv import load_dotenv
from fastapi import APIRouter, Depends, HTTPException, Request

from app.api.deps import require_internal_secret
from app.config import get_settings
from app.services.llm_service import LLMService

logger = logging.getLogger(__name__)
router = APIRouter(dependencies=[Depends(require_internal_secret)])


def _resolve_env_path() -> Optional[str]:
    """Find voice-engine/.env on disk. We look in the cwd first since
    that's how uvicorn is normally launched from the voice-engine dir."""
    for candidate in (".env", "voice-engine/.env"):
        if os.path.isfile(candidate):
            return os.path.abspath(candidate)
    return None


@router.post("/admin/reload")
async def admin_reload(request: Request) -> dict:
    """Re-read .env and rebuild the LLM service. STT/TTS reload is
    triggered only when the device or model setting changed (model
    load is slow). Returns a summary of what was applied."""

    env_path = _resolve_env_path()
    if env_path:
        load_dotenv(env_path, override=True)
        logger.info("admin/reload: refreshed env from %s", env_path)

    # Snapshot OLD settings before invalidating the cache so we know
    # whether the device/model actually changed (which is what forces
    # a costly STT/TTS rebuild).
    old = get_settings()
    old_device = old.whisper_device
    old_model  = old.whisper_model
    old_compute = old.whisper_compute_type
    old_coqui_gpu = old.coqui_use_gpu

    # Clear the lru_cache so a fresh Settings() reads from os.environ.
    get_settings.cache_clear()
    new = get_settings()

    app = request.app
    summary = {
        "provider": {"old": old.llm_provider, "new": new.llm_provider},
        "whisper":  {"old": f"{old_device}/{old_model}/{old_compute}",
                     "new": f"{new.whisper_device}/{new.whisper_model}/{new.whisper_compute_type}"},
        "coqui_gpu": {"old": old_coqui_gpu, "new": new.coqui_use_gpu},
        "reloaded":  {"llm": False, "stt": False, "tts": False},
    }

    # --- LLM (cheap, always rebuild — it's a single httpx client) ---
    try:
        if app.state.llm_service:
            await app.state.llm_service.aclose()
    except Exception as exc:  # noqa: BLE001
        logger.warning("admin/reload: closing old llm_service failed: %s", exc)
    app.state.llm_service = LLMService()
    summary["reloaded"]["llm"] = True
    logger.info("admin/reload: LLM service rebuilt (provider=%s)", new.llm_provider)

    # --- STT (rebuild only when device / model / compute changed) ---
    stt_changed = (
        old_device != new.whisper_device
        or old_model != new.whisper_model
        or old_compute != new.whisper_compute_type
    )
    if stt_changed:
        try:
            from app.services.stt_service import STTService
            app.state.stt_service = STTService(
                model_name=new.whisper_model,
                device=new.whisper_device,
                compute_type=new.whisper_compute_type,
            )
            try:
                app.state.stt_service.load()
            except Exception as exc:  # noqa: BLE001
                logger.warning("admin/reload: STT warm-up failed: %s", exc)
            summary["reloaded"]["stt"] = True
            logger.info("admin/reload: STT rebuilt (%s on %s, %s)",
                        new.whisper_model, new.whisper_device, new.whisper_compute_type)
        except Exception as exc:  # noqa: BLE001
            logger.exception("admin/reload: STT rebuild failed")
            raise HTTPException(status_code=500, detail=f"STT rebuild failed: {exc}")

    # --- TTS (rebuild only when GPU setting changed) ---
    tts_changed = old_coqui_gpu != new.coqui_use_gpu
    if tts_changed:
        try:
            from app.services.tts_service import TTSService
            app.state.tts_service = TTSService(
                model_name=new.coqui_model, gpu=new.coqui_use_gpu
            )
            try:
                app.state.tts_service.load()
            except Exception as exc:  # noqa: BLE001
                logger.warning("admin/reload: TTS warm-up failed: %s", exc)
            summary["reloaded"]["tts"] = True
            logger.info("admin/reload: TTS rebuilt (gpu=%s)", new.coqui_use_gpu)
        except Exception as exc:  # noqa: BLE001
            logger.exception("admin/reload: TTS rebuild failed")
            raise HTTPException(status_code=500, detail=f"TTS rebuild failed: {exc}")

    return summary


@router.get("/admin/diag")
async def admin_diag(request: Request) -> dict:
    """Read-only snapshot of the running services + current env. Lets
    the admin page show "currently using Groq llama-3.3 on CPU" etc.
    without writing anything."""

    s = get_settings()
    app = request.app
    return {
        "llm": {
            "provider": s.llm_provider,
            "groq_model": s.groq_model,
            "anthropic_model": s.anthropic_model,
            "gemini_model": s.gemini_model,
            "ollama_model": s.ollama_model,
            "ollama_base_url": s.ollama_base_url,
            "running": app.state.llm_service is not None,
        },
        "whisper": {
            "model": s.whisper_model,
            "device": s.whisper_device,
            "compute_type": s.whisper_compute_type,
            "running": app.state.stt_service is not None,
        },
        "coqui": {
            "model": s.coqui_model,
            "gpu": s.coqui_use_gpu,
            "running": app.state.tts_service is not None,
        },
    }
