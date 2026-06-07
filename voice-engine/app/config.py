"""Centralised settings via pydantic-settings.

All values can be overridden by environment variables (case-insensitive)
or a local ``.env`` file in the project root. Keep this module side
effect free other than reading env vars — it is imported eagerly by the
FastAPI lifespan handler.
"""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import List, Optional

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    # --- Gemini -----------------------------------------------------------
    gemini_api_key: str = Field(default="", alias="GEMINI_API_KEY")
    gemini_api_url: str = Field(
        default="https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent",
        alias="GEMINI_API_URL",
    )
    gemini_model: str = Field(default="gemini-2.5-flash", alias="GEMINI_MODEL")

    # --- Anthropic (Claude) ----------------------------------------------
    # Set LLM_PROVIDER=anthropic (default) to use Claude. Set =gemini to
    # fall back to the Gemini path (kept for compatibility but not exercised
    # in the current code paths).
    llm_provider: str = Field(default="groq", alias="LLM_PROVIDER")
    anthropic_api_key: str = Field(default="", alias="ANTHROPIC_API_KEY")
    anthropic_model: str = Field(default="claude-opus-4-7", alias="ANTHROPIC_MODEL")
    anthropic_max_tokens: int = Field(default=4096, alias="ANTHROPIC_MAX_TOKENS")

    # --- Groq (OpenAI-compatible, free tier) -----------------------------
    groq_api_key: str = Field(default="", alias="GROQ_API_KEY")
    groq_model: str = Field(default="llama-3.3-70b-versatile", alias="GROQ_MODEL")
    groq_max_tokens: int = Field(default=4096, alias="GROQ_MAX_TOKENS")

    # --- Ollama (local, OpenAI-compatible) -------------------------------
    # Set LLM_PROVIDER=ollama to route the LLM through a locally running
    # Ollama server (no API key, no network egress). Make sure the model
    # named here has been pulled: `ollama pull <model>`.
    ollama_base_url: str = Field(
        default="http://localhost:11434/v1", alias="OLLAMA_BASE_URL"
    )
    ollama_model: str = Field(default="qwen2.5:7b", alias="OLLAMA_MODEL")
    ollama_max_tokens: int = Field(default=4096, alias="OLLAMA_MAX_TOKENS")

    # --- Auth -------------------------------------------------------------
    python_jwt_secret: str = Field(default="change-me", alias="PYTHON_JWT_SECRET")
    python_jwt_algorithm: str = Field(default="HS256", alias="PYTHON_JWT_ALGORITHM")
    python_internal_secret: str = Field(
        default="change-me-internal", alias="PYTHON_INTERNAL_SECRET"
    )

    # --- Laravel webhook --------------------------------------------------
    laravel_base_url: str = Field(
        default="http://127.0.0.1:8000", alias="LARAVEL_BASE_URL"
    )
    laravel_turn_completed_path: str = Field(
        default="/api/internal/turn-completed",
        alias="LARAVEL_TURN_COMPLETED_PATH",
    )

    # --- Models -----------------------------------------------------------
    coqui_model: str = Field(
        default="tts_models/multilingual/multi-dataset/xtts_v2",
        alias="COQUI_MODEL",
    )
    whisper_model: str = Field(default="base", alias="WHISPER_MODEL")
    whisper_device: str = Field(default="cpu", alias="WHISPER_DEVICE")
    whisper_compute_type: str = Field(default="int8", alias="WHISPER_COMPUTE_TYPE")
    coqui_use_gpu: bool = Field(default=False, alias="COQUI_USE_GPU")

    # --- Voice / audio defaults ------------------------------------------
    default_speaker_wav: str = Field(
        default=str(Path(__file__).resolve().parent.parent / "temp_speaker.wav"),
        alias="DEFAULT_SPEAKER_WAV",
    )
    audio_sample_rate: int = Field(default=24000, alias="AUDIO_SAMPLE_RATE")

    # --- Per-turn audio file storage -------------------------------------
    # Each assistant voice reply is written to disk as a WAV and served
    # back via /voice/... so the widget can replay old sessions. The URL
    # prefix is what gets stored in messages.audio_url.
    voice_output_dir: str = Field(
        default=str(Path(__file__).resolve().parent.parent / "voice_outputs"),
        alias="VOICE_OUTPUT_DIR",
    )
    voice_output_url_prefix: str = Field(
        default="http://127.0.0.1:8002/voice",
        alias="VOICE_OUTPUT_URL_PREFIX",
    )

    # --- HTTP / CORS ------------------------------------------------------
    cors_allow_origins: List[str] = Field(
        default_factory=lambda: ["*"], alias="CORS_ALLOW_ORIGINS"
    )

    # --- RAG / vector store ----------------------------------------------
    qdrant_url: str = Field(default="http://127.0.0.1:6333", alias="QDRANT_URL")
    qdrant_api_key: Optional[str] = Field(default=None, alias="QDRANT_API_KEY")
    qdrant_collection: str = Field(default="crm_chunks", alias="QDRANT_COLLECTION")
    embedding_model: str = Field(
        default="models/text-embedding-004", alias="EMBEDDING_MODEL"
    )
    rag_chunk_max_tokens: int = Field(default=500, alias="RAG_CHUNK_MAX_TOKENS")
    rag_chunk_overlap: int = Field(default=50, alias="RAG_CHUNK_OVERLAP")
    rag_crawl_max_pages: int = Field(default=50, alias="RAG_CRAWL_MAX_PAGES")
    rag_crawl_max_depth: int = Field(default=2, alias="RAG_CRAWL_MAX_DEPTH")

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
        extra="ignore",
    )


@lru_cache
def get_settings() -> Settings:
    """Return a cached :class:`Settings` instance."""

    return Settings()
