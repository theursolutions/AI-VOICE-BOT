"""Pydantic models that match the cross-system API contract.

See :mod:`docs.API_CONTRACT` for the canonical spec.
"""

from __future__ import annotations

from typing import Any, Dict, List, Literal, Optional, Union

from pydantic import BaseModel, Field


# ---------------------------------------------------------------------------
# Shared / messaging
# ---------------------------------------------------------------------------

Role = Literal["system", "user", "assistant"]


class ChatMessage(BaseModel):
    role: Role
    content: str


# ---------------------------------------------------------------------------
# /llm/respond
# ---------------------------------------------------------------------------

RespondWith = Literal["text", "audio", "both"]


class LLMRequest(BaseModel):
    messages: List[ChatMessage]
    # Both optional — internal callers like the SQL generator or the
    # webhook tool picker don't have a chat session attached. Laravel's
    # ConversationManager still sets them on real turns for telemetry.
    project_id: Optional[int] = None
    session_id: Optional[int] = None
    voice_id: Optional[int] = None
    respond_with: RespondWith = "text"
    stream: bool = False
    metadata: Optional[Dict[str, Any]] = None
    # Per-request provider override. Control-plane callers (SQL generation,
    # source router) can request a stronger model (e.g. "groq") for one
    # call while the chat path stays on the configured local provider.
    # None / empty = use the server's configured LLM_PROVIDER.
    provider: Optional[str] = None
    # Per-request generation knobs. Control-plane callers (SQL generation,
    # routers, condense) pass temperature=0 for deterministic, faster output
    # and a small max_tokens since their replies are short. None = backend
    # default.
    temperature: Optional[float] = None
    max_tokens: Optional[int] = None
    # Per-request model override (within the chosen provider). Lets reasoning
    # calls request a stronger local model (e.g. qwen2.5:7b) while chat stays
    # on a fast one. None = the provider's configured default model.
    model: Optional[str] = None


class LLMResponse(BaseModel):
    text: str
    audio_url: Optional[str] = None
    tokens_in: int = 0
    tokens_out: int = 0
    model: str
    metadata: Dict[str, Any] = Field(default_factory=dict)


# ---------------------------------------------------------------------------
# /extract
# ---------------------------------------------------------------------------


class ExtractRequest(BaseModel):
    session_id: int
    project_id: int
    user_text: Optional[str] = None
    assistant_text: Optional[str] = None
    # Full conversation history (capped server-side). Each entry is
    # {role, content}. When provided, the extractor uses this instead of
    # the single user_text/assistant_text pair so it can catch info
    # mentioned earlier in the chat (e.g. "my email is x@y.com" 5 turns ago).
    history: List[ChatMessage] = Field(default_factory=list)
    existing_fields: Dict[str, Any] = Field(default_factory=dict)


class LeadFields(BaseModel):
    """Lead fields the LLM is allowed to populate.

    Every field is optional. Missing fields are preferred over
    hallucinated ones — the prompt instructs the model to omit anything
    it is not confident about.
    """

    name: Optional[str] = None
    email: Optional[str] = None
    phone: Optional[str] = None
    intent: Optional[str] = None
    budget: Optional[str] = None
    timeline: Optional[str] = None
    custom: Dict[str, Any] = Field(default_factory=dict)


class ExtractResult(BaseModel):
    fields: LeadFields
    confidence: float = 0.0


# ---------------------------------------------------------------------------
# /stt and /tts
# ---------------------------------------------------------------------------


class STTResult(BaseModel):
    text: str
    language: Optional[str] = None
    duration_ms: Optional[int] = None


class TTSRequest(BaseModel):
    text: str
    voice_id: Optional[int] = None
    speaker_wav_url: Optional[str] = None
    language: str = "en"
    project_id: Optional[int] = None
    session_id: Optional[int] = None


# ---------------------------------------------------------------------------
# WebSocket frames — /ws/turn
# ---------------------------------------------------------------------------


class AudioStartFrame(BaseModel):
    type: Literal["audio.start"] = "audio.start"
    format: Literal["pcm16"] = "pcm16"
    sample_rate: int = 16000


class AudioChunkFrame(BaseModel):
    type: Literal["audio.chunk"] = "audio.chunk"
    seq: int = 0
    data: str  # base64 PCM16
    format: Optional[str] = None  # echoed by server frames


class AudioEndFrame(BaseModel):
    type: Literal["audio.end"] = "audio.end"


class TextFrame(BaseModel):
    type: Literal["text"] = "text"
    text: str


class BargeInFrame(BaseModel):
    type: Literal["barge_in"] = "barge_in"


# Inbound discriminated union
TurnIn = Union[
    AudioStartFrame,
    AudioChunkFrame,
    AudioEndFrame,
    TextFrame,
    BargeInFrame,
]


class STTPartialFrame(BaseModel):
    type: Literal["stt.partial"] = "stt.partial"
    text: str


class STTFinalFrame(BaseModel):
    type: Literal["stt.final"] = "stt.final"
    text: str


class LLMDeltaFrame(BaseModel):
    type: Literal["llm.delta"] = "llm.delta"
    text: str


class LLMFinalFrame(BaseModel):
    type: Literal["llm.final"] = "llm.final"
    text: str
    tokens_in: int = 0
    tokens_out: int = 0


class TurnEndFrame(BaseModel):
    type: Literal["turn.end"] = "turn.end"
    latency_ms: int


class ErrorFrame(BaseModel):
    type: Literal["error"] = "error"
    code: str
    message: str


# Outbound discriminated union
TurnFrame = Union[
    STTPartialFrame,
    STTFinalFrame,
    LLMDeltaFrame,
    LLMFinalFrame,
    AudioChunkFrame,
    AudioEndFrame,
    TurnEndFrame,
    ErrorFrame,
]


# ---------------------------------------------------------------------------
# Internal webhook to Laravel — POST /api/internal/turn-completed
# ---------------------------------------------------------------------------


class TurnCompletedPayload(BaseModel):
    project_id: int
    session_id: int
    role: Role = "assistant"
    content: str
    audio_url: Optional[str] = None
    tokens_in: int = 0
    tokens_out: int = 0
    latency_ms: int = 0
    model_used: str = ""
    metadata: Dict[str, Any] = Field(default_factory=dict)
    # For the WS path: the transcribed/typed user input that produced this
    # assistant reply. Laravel will persist it as a `messages` row of
    # role=user alongside the assistant row. HTTP path persists the user
    # message in TurnController so this field is None there.
    user_content: Optional[str] = None
    cancelled: bool = False


# ---------------------------------------------------------------------------
# RAG ingest + query
# ---------------------------------------------------------------------------

IngestType = Literal["website", "document"]
IngestStatusLiteral = Literal["queued", "running", "done", "failed"]


class IngestRequest(BaseModel):
    project_id: int
    source_id: int
    type: IngestType
    # For ``website``: ``{"url": str, "max_depth": int?, "max_pages": int?}``.
    # For ``document``: ``{"files": [{"path": str, "original_name": str}, ...]}``.
    config: Dict[str, Any] = Field(default_factory=dict)


class IngestResponse(BaseModel):
    job_id: str
    status: IngestStatusLiteral = "queued"


class IngestStatus(BaseModel):
    job_id: str
    source_id: int
    status: IngestStatusLiteral
    progress: float = 0.0
    chunks_indexed: int = 0
    pages_processed: int = 0
    errors: List[str] = Field(default_factory=list)
    error: Optional[str] = None


class RagQueryRequest(BaseModel):
    project_id: int
    query: str
    top_k: int = 5
    source_ids: Optional[List[int]] = None


class Passage(BaseModel):
    text: str
    score: float
    citation: Dict[str, Any] = Field(default_factory=dict)
    source_id: int
    source_type: str


class RagQueryResponse(BaseModel):
    passages: List[Passage] = Field(default_factory=list)
