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
    gemini_max_tokens: int = Field(default=4096, alias="GEMINI_MAX_TOKENS")
    # Gemini's OpenAI-compatible endpoint. Lets it be driven by the same client
    # as Groq/Ollama instead of needing a bespoke backend.
    gemini_base_url: str = Field(
        default="https://generativelanguage.googleapis.com/v1beta/openai/",
        alias="GEMINI_BASE_URL",
    )

    # Cerebras — another free-tier, OpenAI-compatible inference provider. Useful
    # as a third tier so a daily quota on one provider doesn't end up on the slow
    # local model.
    cerebras_api_key: str = Field(default="", alias="CEREBRAS_API_KEY")
    cerebras_model: str = Field(default="llama-3.3-70b", alias="CEREBRAS_MODEL")
    cerebras_max_tokens: int = Field(default=4096, alias="CEREBRAS_MAX_TOKENS")
    cerebras_base_url: str = Field(
        default="https://api.cerebras.ai/v1",
        alias="CEREBRAS_BASE_URL",
    )

    # --- Anthropic (Claude) ----------------------------------------------
    # Set LLM_PROVIDER=anthropic (default) to use Claude. Set =gemini to
    # fall back to the Gemini path (kept for compatibility but not exercised
    # in the current code paths).
    llm_provider: str = Field(default="groq", alias="LLM_PROVIDER")
    # Resilience under load: when the primary provider rate-limits (429) or
    # has a transient error, retry with backoff, then fall back to this
    # secondary provider (e.g. groq → anthropic, or → ollama for offline).
    # Empty = no fallback. Must differ from llm_provider.
    llm_fallback_provider: str = Field(default="", alias="LLM_FALLBACK_PROVIDER")
    llm_max_retries: int = Field(default=2, alias="LLM_MAX_RETRIES")
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
    # The local tier needs its OWN timeout. Cloud providers answer in seconds, so
    # the shared 60s default is generous for them — but CPU inference is a
    # different order of magnitude: a cold model load alone costs ~16s, before
    # generating a single token of a RAG-laden prompt at a few tokens/second.
    # Sharing the cloud timeout meant the last tier of the chain could time out
    # on every long answer and look exactly like "the fallback does nothing".
    # It is the last resort — a slow answer beats no answer.
    ollama_timeout: float = Field(default=300.0, alias="OLLAMA_TIMEOUT")

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
    # Optional: load XTTS-v2 from a local checkpoint directory instead of the
    # Coqui model registry. The directory must contain model.pth, config.json
    # and vocab.json. Used to run fine-tuned XTTS-v2 checkpoints (e.g. the
    # Urdu fine-tune suhaibrashid17/XTTS-v2-Urdu-FT) while keeping the exact
    # same streaming + voice-cloning code path. Empty = use coqui_model above.
    xtts_checkpoint_dir: str = Field(default="", alias="XTTS_CHECKPOINT_DIR")
    whisper_model: str = Field(default="base", alias="WHISPER_MODEL")
    whisper_device: str = Field(default="cpu", alias="WHISPER_DEVICE")
    whisper_compute_type: str = Field(default="int8", alias="WHISPER_COMPUTE_TYPE")
    # Transcriptions that can run at once. One worker made simultaneous
    # callers queue for speech-to-text (10 calls: 13.4 s median wait for the
    # first word of a reply, vs 8.7 s with 4 — measured 2026-10-11).
    whisper_workers: int = Field(default=4, alias="WHISPER_WORKERS")
    coqui_use_gpu: bool = Field(default=False, alias="COQUI_USE_GPU")

    # --- WhatsApp calling (WebRTC media bridge) --------------------------
    # ICE servers for the aiortc peer connection. A STUN server is enough
    # for testing on a public host; real calls behind NAT need a TURN
    # server (set WHATSAPP_TURN_URL + credentials).
    whatsapp_stun_url: str = Field(
        default="stun:stun.l.google.com:19302", alias="WHATSAPP_STUN_URL"
    )
    whatsapp_turn_url: str = Field(default="", alias="WHATSAPP_TURN_URL")
    whatsapp_turn_username: str = Field(default="", alias="WHATSAPP_TURN_USERNAME")
    whatsapp_turn_password: str = Field(default="", alias="WHATSAPP_TURN_PASSWORD")

    # --- Voice / audio defaults ------------------------------------------
    default_speaker_wav: str = Field(
        default=str(Path(__file__).resolve().parent.parent / "temp_speaker.wav"),
        alias="DEFAULT_SPEAKER_WAV",
    )
    audio_sample_rate: int = Field(default=24000, alias="AUDIO_SAMPLE_RATE")

    # --- Google voices (Gemini text-to-speech) ---------------------------
    # Ready-made voices picked per project/agent in the admin; uses
    # GEMINI_API_KEY. The free tier allows ~3 speech requests a minute —
    # a call needs one per sentence, so real use needs a billed project.
    gemini_tts_model: str = Field(default="gemini-3.8-flash-lite-tts", alias="GEMINI_TTS_MODEL")
    # Each model has its own quota: on a 429 the next one here is tried.
    gemini_tts_fallback_models: str = Field(
        default="gemini-3.8-flash-tts,gemini-3.1-flash-tts-preview",
        alias="GEMINI_TTS_FALLBACK_MODELS",
    )
    # Short phrases (greetings, "anything else?", goodbyes) are kept here
    # once spoken, so they never cost a request again — Tier 1 keys get
    # only 100 speech requests a day per model. Empty = memory only.
    google_tts_cache_dir: str = Field(
        default=str(Path(__file__).resolve().parent.parent / "tts_cache"),
        alias="GOOGLE_TTS_CACHE_DIR",
    )

    # --- Phone / SIP calls (app/calls) -----------------------------------
    # Barge-in: the caller talking over the AI cuts the AI off. It takes
    # this much continuous speech, so a cough or line noise doesn't.
    call_barge_in: bool = Field(default=True, alias="CALL_BARGE_IN")
    call_barge_in_ms: int = Field(default=600, alias="CALL_BARGE_IN_MS")
    # How many earlier exchanges of THIS call the model sees.
    call_history_turns: int = Field(default=10, alias="CALL_HISTORY_TURNS")

    # Listening: "gemini" hears words, language, mood and the caller's
    # voice (sir/madam) in one request; "whisper" keeps calls fully local.
    # gemini-3.1-flash-lite: 1.6 s median, language 11/12, mood 12/12 on
    # phone-quality clips (2026-10-11); gemini-3.5-flash is a little more
    # accurate on rarer languages but ~0.3 s slower.
    call_listener: str = Field(default="gemini", alias="CALL_LISTENER")
    call_listen_model: str = Field(default="gemini-3.1-flash-lite", alias="CALL_LISTEN_MODEL")
    call_listen_timeout: float = Field(default=6.0, alias="CALL_LISTEN_TIMEOUT")
    # Silence that ends the caller's turn. A caller who stopped mid-thought
    # (the listener can tell) gets CALL_INCOMPLETE_WAIT_MS more to go on.
    call_end_silence_ms: int = Field(default=650, alias="CALL_END_SILENCE_MS")
    call_incomplete_wait_ms: int = Field(default=1500, alias="CALL_INCOMPLETE_WAIT_MS")
    # "Let me check" only when the lookup is slower than this.
    call_filler_after_ms: int = Field(default=300, alias="CALL_FILLER_AFTER_MS")
    # The voice's delivery follows the caller's mood (Google voices).
    call_voice_emotion: bool = Field(default=True, alias="CALL_VOICE_EMOTION")
    # When the line goes quiet: check in after IDLE_PROMPT seconds (after
    # an answer) or IDLE_WAIT seconds (after the AI asked something), then
    # IDLE_GOODBYE seconds later say goodbye and hang up.
    call_idle_prompts: bool = Field(default=True, alias="CALL_IDLE_PROMPTS")
    call_idle_prompt_seconds: float = Field(default=4.5, alias="CALL_IDLE_PROMPT_SECONDS")
    call_idle_wait_seconds: float = Field(default=8.0, alias="CALL_IDLE_WAIT_SECONDS")
    call_idle_goodbye_seconds: float = Field(default=6.0, alias="CALL_IDLE_GOODBYE_SECONDS")

    # --- Asterisk: ARI call control + AudioSocket audio (app/calls) ------
    # Off unless ASTERISK_ARI_PASSWORD is set; Twilio needs none of this.
    asterisk_ari_url: str = Field(default="http://127.0.0.1:8088", alias="ASTERISK_ARI_URL")
    asterisk_ari_user: str = Field(default="ai-crm", alias="ASTERISK_ARI_USER")
    asterisk_ari_password: str = Field(default="", alias="ASTERISK_ARI_PASSWORD")
    asterisk_ari_app: str = Field(default="ai-crm", alias="ASTERISK_ARI_APP")
    # Where our AudioSocket server listens, and the host:port Asterisk must
    # connect to — different when Asterisk runs in WSL, Docker or elsewhere.
    audiosocket_bind: str = Field(default="0.0.0.0:9092", alias="AUDIOSOCKET_BIND")
    audiosocket_advertise: str = Field(default="127.0.0.1:9092", alias="AUDIOSOCKET_ADVERTISE")

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

    # --- DuckDB (unified local store: snapshots=SQL, KB/crawler=BM25 FTS) -
    # One file per project under this dir. Replaces the per-snapshot MySQL
    # tables and the Qdrant vector store + embedding model.
    duckdb_dir: str = Field(
        default=str(Path(__file__).resolve().parent.parent / "duckdb_data"),
        alias="DUCKDB_DIR",
    )

    # --- RAG / vector store (legacy — being retired in favour of DuckDB) --
    qdrant_url: str = Field(default="http://127.0.0.1:6333", alias="QDRANT_URL")
    qdrant_api_key: Optional[str] = Field(default=None, alias="QDRANT_API_KEY")
    qdrant_collection: str = Field(default="crm_chunks", alias="QDRANT_COLLECTION")
    # Embeddings (RAG vector step) — SEPARATE from the chat LLM. Set
    # EMBEDDING_PROVIDER=ollama to keep RAG fully local (no Gemini key);
    # use a 768-dim model (nomic-embed-text) so it matches the Qdrant
    # collection. 'gemini' uses text-embedding-004 (needs GEMINI_API_KEY).
    embedding_provider: str = Field(default="gemini", alias="EMBEDDING_PROVIDER")
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
