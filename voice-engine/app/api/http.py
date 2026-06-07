"""FastAPI entrypoint.

* Loads heavy models once during the lifespan handler.
* Mounts the new routers (``/llm``, ``/extract``, ``/stt``, ``/tts``,
  ``/healthz``) and the WebSocket (``/ws/turn``).
* Re-mounts the legacy endpoints under ``/legacy`` with a deprecation
  warning so existing clients keep working during the migration.
"""

from __future__ import annotations

import logging
import os
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles

from app.config import get_settings
from app.api.routes import admin as admin_route
from app.api.routes import extract as extract_route
from app.api.routes import health as health_route
from app.api.routes import llm as llm_route
from app.api.routes import stt as stt_route
from app.api.routes import tts as tts_route
from app.integrations.laravel_client import LaravelClient
from app.services.llm_service import LLMService
from app.services.extractor_service import ExtractorService

logger = logging.getLogger(__name__)
logging.basicConfig(level=logging.INFO)


def _try_init(name: str, factory):
    """Construct a service; on failure (e.g. heavy ML dep missing) log
    a warning and return None so the rest of the app keeps working."""
    try:
        return factory()
    except Exception as exc:  # noqa: BLE001
        logger.warning("Optional service '%s' not available: %s", name, exc)
        return None


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    app.state.settings = settings

    # Core text-only services (lightweight deps).
    app.state.llm_service = LLMService()
    app.state.laravel_client = LaravelClient()
    app.state.extractor_service = ExtractorService(app.state.llm_service)

    # Optional voice services — require numpy/torch/Coqui/faster-whisper.
    # We import lazily so the app boots without them and the text path
    # keeps working. Hitting /stt or /tts will return 503 if missing.
    def _build_stt():
        from app.services.stt_service import STTService
        return STTService(
            model_name=settings.whisper_model,
            device=settings.whisper_device,
            compute_type=settings.whisper_compute_type,
        )

    def _build_tts():
        from app.services.tts_service import TTSService
        return TTSService(model_name=settings.coqui_model, gpu=settings.coqui_use_gpu)

    app.state.stt_service = _try_init("stt_service", _build_stt)
    app.state.tts_service = _try_init("tts_service", _build_tts)

    # Warm up the heavy voice models at boot. Otherwise the *first* user
    # request pays the model-load cost (TTS/XTTS-v2 is ~1.8GB and can take
    # minutes on CPU), which blows past Laravel's HTTP timeout and surfaces
    # to the widget as "Upstream API error".
    for _name, _svc in (
        ("stt_service", app.state.stt_service),
        ("tts_service", app.state.tts_service),
    ):
        if _svc is not None:
            try:
                _svc.load()
            except Exception as exc:  # noqa: BLE001
                logger.warning("Warm-up of '%s' failed: %s", _name, exc)

    # Optional RAG services — require qdrant-client + trafilatura + etc.
    def _build_rag():
        from app.services.embedding_service import EmbeddingService
        from app.services.ingest_service import IngestService
        from app.services.retrieval_service import RetrievalService
        from app.services.vector_store import VectorStore
        emb  = EmbeddingService()
        vs   = VectorStore()
        return {
            "embedding_service": emb,
            "vector_store": vs,
            "ingest_service": IngestService(embedding=emb, vector_store=vs),
            "retrieval_service": RetrievalService(embedding=emb, vector_store=vs),
        }

    rag = _try_init("rag", _build_rag) or {}
    app.state.embedding_service = rag.get("embedding_service")
    app.state.vector_store      = rag.get("vector_store")
    app.state.ingest_service    = rag.get("ingest_service")
    app.state.retrieval_service = rag.get("retrieval_service")

    logger.info(
        "Voice CRM Agent FastAPI started — stt=%s tts=%s rag=%s",
        bool(app.state.stt_service),
        bool(app.state.tts_service),
        bool(app.state.retrieval_service),
    )
    try:
        yield
    finally:
        await app.state.llm_service.aclose()
        await app.state.laravel_client.aclose()
        logger.info("Voice CRM Agent FastAPI shutting down")


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(title="Voice CRM Agent", version="0.2.0", lifespan=lifespan)

    app.add_middleware(
        CORSMiddleware,
        allow_origins=settings.cors_allow_origins,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )

    # Static mount for synthesised wav files. Legacy /llm/respond path
    # writes to ``voice_outputs/``; the WS path writes per-turn replies
    # under ``voice_outputs/sessions/<sid>/<ts>.wav``. We expose the same
    # dir under two URL prefixes to keep both surfaces working.
    voice_outputs = os.path.abspath(settings.voice_output_dir)
    os.makedirs(os.path.join(voice_outputs, "sessions"), exist_ok=True)
    app.mount(
        "/voice_outputs",
        StaticFiles(directory=voice_outputs),
        name="voice_outputs",
    )
    app.mount(
        "/voice",
        StaticFiles(directory=voice_outputs),
        name="voice",
    )

    # Core routers — always available
    app.include_router(health_route.router, tags=["health"])
    app.include_router(llm_route.router, tags=["llm"])
    app.include_router(extract_route.router, tags=["extract"])
    app.include_router(stt_route.router, tags=["stt"])
    app.include_router(tts_route.router, tags=["tts"])
    app.include_router(admin_route.router, tags=["admin"])

    # Optional routers — import lazily so missing deps don't break boot.
    try:
        from app.api.routes import rag as rag_route
        app.include_router(rag_route.router, tags=["rag"])
    except Exception as exc:  # noqa: BLE001
        logger.warning("RAG routes not available: %s", exc)

    try:
        from app.api import ws as ws_route
        app.include_router(ws_route.router, tags=["ws"])
    except Exception as exc:  # noqa: BLE001
        logger.warning("WebSocket route not available: %s", exc)

    # Twilio Media Streams (phone-call channel). Reuses STT/LLM/TTS
    # via app.state services attached in the lifespan handler.
    try:
        from app.api import phone as phone_route
        app.include_router(phone_route.router, tags=["phone"])
    except Exception as exc:  # noqa: BLE001
        logger.warning("Phone (Twilio Media Streams) route not available: %s", exc)

    # Legacy endpoints — keep working under /legacy until callers migrate.
    try:
        from app.api import legacy_bridge
        app.include_router(legacy_bridge.router, prefix="/legacy", tags=["legacy"])
        logger.warning(
            "Legacy endpoints mounted under /legacy/* — DEPRECATED. "
            "Migrate to /llm/respond, /extract, /stt, /tts, /ws/turn."
        )
    except Exception as exc:  # noqa: BLE001
        logger.warning("Could not mount legacy endpoints: %s", exc)

    return app


app = create_app()
