"""LLM service with pluggable backends.

Public surface (unchanged across backends):

* :meth:`chat` — single-shot, returns a :class:`ChatResult`.
* :meth:`stream_chat` — async generator yielding ``{"type": "delta", ...}``
  per text chunk, then a terminal ``{"type": "final", ...}``.
* :meth:`extract` — JSON-mode call constrained by ``response_schema``.

Backend is selected by ``settings.llm_provider`` (env: ``LLM_PROVIDER``):

* ``"groq"``     — OpenAI-compatible API at api.groq.com (free tier, fast).
* ``"ollama"``   — local Ollama server, OpenAI-compatible (no API key, offline).
* ``"anthropic"`` — Claude via the official Anthropic SDK.

Both backends accept the same incoming ``ChatMessage`` list (mix of
``system``/``user``/``assistant`` roles) and emit the same frame shape so
``/llm/respond``, ``/ws/turn`` and the extractor never need to know which
provider is active.

Prompt caching: the **Anthropic** backend marks the first ``system`` block
with ``cache_control: ephemeral`` so the static project context stays
cached across turns even when per-turn RAG reference data is appended
after it. Groq does not currently support server-side prompt caching, so
that's a no-op there.
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass, field
from typing import Any, AsyncIterator, Dict, List, Optional

from app.config import get_settings
from app.domain.schemas import ChatMessage

logger = logging.getLogger(__name__)


@dataclass
class ChatResult:
    text: str
    tokens_in: int = 0
    tokens_out: int = 0
    model: str = ""
    raw: Dict[str, Any] = field(default_factory=dict)


# ---------------------------------------------------------------------------
# Anthropic backend
# ---------------------------------------------------------------------------

def _split_for_anthropic(
    messages: List[ChatMessage],
) -> tuple[List[Dict[str, Any]], List[Dict[str, str]]]:
    """Anthropic separates the system prompt from the messages list.

    Returns (system_blocks, chat_messages). The first system block carries
    a cache breakpoint so subsequent per-turn additions (reference data)
    don't invalidate the cached prefix.
    """

    system_texts: List[str] = []
    chat: List[Dict[str, str]] = []

    for msg in messages:
        if msg.role == "system":
            if msg.content and msg.content.strip():
                system_texts.append(msg.content)
            continue
        if msg.role in ("user", "assistant"):
            chat.append({"role": msg.role, "content": msg.content})

    if not system_texts:
        return [], chat

    system_blocks: List[Dict[str, Any]] = []
    for i, text in enumerate(system_texts):
        block: Dict[str, Any] = {"type": "text", "text": text}
        if i == 0:
            block["cache_control"] = {"type": "ephemeral"}
        system_blocks.append(block)
    return system_blocks, chat


class _AnthropicBackend:
    def __init__(self, api_key: str, model: str, max_tokens: int, timeout: float):
        from anthropic import AsyncAnthropic
        if not api_key:
            raise RuntimeError("ANTHROPIC_API_KEY is not set in voice-engine/.env")
        self.model = model
        self.max_tokens = max_tokens
        self._client = AsyncAnthropic(api_key=api_key, timeout=timeout)

    async def close(self) -> None:
        await self._client.close()

    async def chat(self, messages: List[ChatMessage]) -> ChatResult:
        system_blocks, chat = _split_for_anthropic(messages)
        if not chat:
            return ChatResult(text="", model=self.model)

        kwargs: Dict[str, Any] = {
            "model": self.model,
            "max_tokens": self.max_tokens,
            "messages": chat,
        }
        if system_blocks:
            kwargs["system"] = system_blocks

        response = await self._client.messages.create(**kwargs)
        text = "".join(b.text for b in response.content if b.type == "text")
        usage = response.usage
        return ChatResult(
            text=text,
            tokens_in=usage.input_tokens,
            tokens_out=usage.output_tokens,
            model=response.model,
        )

    async def stream_chat(self, messages: List[ChatMessage]) -> AsyncIterator[Dict[str, Any]]:
        system_blocks, chat = _split_for_anthropic(messages)
        if not chat:
            yield {"type": "final", "text": "", "tokens_in": 0, "tokens_out": 0, "model": self.model}
            return

        kwargs: Dict[str, Any] = {
            "model": self.model,
            "max_tokens": self.max_tokens,
            "messages": chat,
        }
        if system_blocks:
            kwargs["system"] = system_blocks

        async with self._client.messages.stream(**kwargs) as stream:
            async for text in stream.text_stream:
                if text:
                    yield {"type": "delta", "text": text}
            final = await stream.get_final_message()

        text = "".join(b.text for b in final.content if b.type == "text")
        usage = final.usage
        yield {
            "type": "final",
            "text": text,
            "tokens_in": usage.input_tokens,
            "tokens_out": usage.output_tokens,
            "model": final.model,
        }

    async def extract(self, prompt: str, response_schema: Dict[str, Any]) -> Dict[str, Any]:
        import anthropic
        try:
            response = await self._client.messages.create(
                model=self.model,
                max_tokens=self.max_tokens,
                messages=[{"role": "user", "content": prompt}],
                output_config={"format": {"type": "json_schema", "schema": response_schema}},
            )
        except anthropic.BadRequestError as exc:
            logger.warning("extract: BadRequest from Anthropic: %s", exc)
            return {}

        text = "".join(b.text for b in response.content if b.type == "text").strip()
        if not text:
            return {}
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            logger.warning("extract: model returned non-JSON output: %r", text[:200])
            return {}


# ---------------------------------------------------------------------------
# Groq backend (OpenAI-compatible API)
# ---------------------------------------------------------------------------

def _to_openai_messages(messages: List[ChatMessage]) -> List[Dict[str, str]]:
    """OpenAI-format keeps system as a role in the messages list."""
    return [
        {"role": msg.role, "content": msg.content}
        for msg in messages
        if msg.content and msg.content.strip()
    ]


class _GroqBackend:
    def __init__(self, api_key: str, model: str, max_tokens: int, timeout: float):
        from openai import AsyncOpenAI
        if not api_key:
            raise RuntimeError("GROQ_API_KEY is not set in voice-engine/.env")
        self.model = model
        self.max_tokens = max_tokens
        self._client = AsyncOpenAI(
            api_key=api_key,
            base_url="https://api.groq.com/openai/v1",
            timeout=timeout,
        )

    async def close(self) -> None:
        await self._client.close()

    async def chat(self, messages: List[ChatMessage]) -> ChatResult:
        msgs = _to_openai_messages(messages)
        if not msgs:
            return ChatResult(text="", model=self.model)

        resp = await self._client.chat.completions.create(
            model=self.model,
            messages=msgs,
            max_tokens=self.max_tokens,
        )
        choice = resp.choices[0]
        text = choice.message.content or ""
        usage = resp.usage
        return ChatResult(
            text=text,
            tokens_in=usage.prompt_tokens if usage else 0,
            tokens_out=usage.completion_tokens if usage else 0,
            model=resp.model,
        )

    async def stream_chat(self, messages: List[ChatMessage]) -> AsyncIterator[Dict[str, Any]]:
        msgs = _to_openai_messages(messages)
        if not msgs:
            yield {"type": "final", "text": "", "tokens_in": 0, "tokens_out": 0, "model": self.model}
            return

        aggregated = ""
        tokens_in = 0
        tokens_out = 0
        final_model = self.model

        stream = await self._client.chat.completions.create(
            model=self.model,
            messages=msgs,
            max_tokens=self.max_tokens,
            stream=True,
            stream_options={"include_usage": True},
        )
        async for chunk in stream:
            final_model = chunk.model or final_model
            if chunk.choices:
                delta = chunk.choices[0].delta
                if delta and delta.content:
                    aggregated += delta.content
                    yield {"type": "delta", "text": delta.content}
            # Last chunk on Groq carries the usage block with empty choices.
            if chunk.usage:
                tokens_in = chunk.usage.prompt_tokens or 0
                tokens_out = chunk.usage.completion_tokens or 0

        yield {
            "type": "final",
            "text": aggregated,
            "tokens_in": tokens_in,
            "tokens_out": tokens_out,
            "model": final_model,
        }

    async def extract(self, prompt: str, response_schema: Dict[str, Any]) -> Dict[str, Any]:
        # Groq supports JSON mode. Schema isn't enforced server-side the way
        # Anthropic's output_config.format is, so we attach the schema to the
        # user message and let the model emit a JSON object that conforms.
        schema_hint = (
            "Return ONLY a JSON object that matches this schema. No prose.\n"
            f"Schema: {json.dumps(response_schema)}\n\n"
            f"Input:\n{prompt}"
        )
        resp = await self._client.chat.completions.create(
            model=self.model,
            messages=[{"role": "user", "content": schema_hint}],
            max_tokens=self.max_tokens,
            response_format={"type": "json_object"},
        )
        text = (resp.choices[0].message.content or "").strip()
        if not text:
            return {}
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            logger.warning("extract: groq returned non-JSON output: %r", text[:200])
            return {}


# ---------------------------------------------------------------------------
# Ollama backend (local, OpenAI-compatible API)
# ---------------------------------------------------------------------------

class _OllamaBackend(_GroqBackend):
    """Local Ollama server via its OpenAI-compatible ``/v1`` endpoint.

    Ollama speaks the same chat/completions wire format as OpenAI/Groq, so
    we reuse the entire Groq implementation (chat / stream_chat / extract)
    and only swap the client: a local ``base_url`` and a dummy API key
    (Ollama ignores it but the SDK requires a non-empty string).
    """

    def __init__(self, base_url: str, model: str, max_tokens: int, timeout: float):
        from openai import AsyncOpenAI
        self.model = model
        self.max_tokens = max_tokens
        self._client = AsyncOpenAI(
            api_key="ollama",  # required by the SDK; ignored by the server
            base_url=base_url,
            timeout=timeout,
        )


# ---------------------------------------------------------------------------
# Public dispatcher
# ---------------------------------------------------------------------------

class LLMService:
    def __init__(
        self,
        provider: Optional[str] = None,
        api_key: Optional[str] = None,
        model: Optional[str] = None,
        max_tokens: Optional[int] = None,
        timeout: float = 60.0,
    ) -> None:
        settings = get_settings()
        provider = (provider or settings.llm_provider or "groq").lower()
        self.provider = provider

        if provider == "anthropic":
            self._backend: Any = _AnthropicBackend(
                api_key=api_key or settings.anthropic_api_key,
                model=model or settings.anthropic_model,
                max_tokens=max_tokens or settings.anthropic_max_tokens,
                timeout=timeout,
            )
        elif provider == "groq":
            self._backend = _GroqBackend(
                api_key=api_key or settings.groq_api_key,
                model=model or settings.groq_model,
                max_tokens=max_tokens or settings.groq_max_tokens,
                timeout=timeout,
            )
        elif provider == "ollama":
            self._backend = _OllamaBackend(
                base_url=settings.ollama_base_url,
                model=model or settings.ollama_model,
                max_tokens=max_tokens or settings.ollama_max_tokens,
                timeout=timeout,
            )
        else:
            raise RuntimeError(
                f"Unknown LLM_PROVIDER '{provider}'. Use 'groq', 'ollama' or 'anthropic'."
            )

        self.model = self._backend.model

    async def aclose(self) -> None:
        await self._backend.close()

    async def chat(
        self,
        messages: List[ChatMessage],
        generation_config: Optional[Dict[str, Any]] = None,
    ) -> ChatResult:
        return await self._backend.chat(messages)

    async def stream_chat(
        self,
        messages: List[ChatMessage],
        generation_config: Optional[Dict[str, Any]] = None,
    ) -> AsyncIterator[Dict[str, Any]]:
        async for frame in self._backend.stream_chat(messages):
            yield frame

    async def extract(
        self,
        prompt: str,
        response_schema: Dict[str, Any],
    ) -> Dict[str, Any]:
        return await self._backend.extract(prompt, response_schema)
