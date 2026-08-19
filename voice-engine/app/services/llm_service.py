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

import hashlib
import asyncio
import json
import logging
from dataclasses import dataclass, field
from typing import Any, AsyncIterator, Dict, List, Optional

from app.config import get_settings
from app.domain.schemas import ChatMessage

logger = logging.getLogger(__name__)


# Status codes / error names that are worth retrying or failing over on.
_RETRYABLE_CODES = {408, 409, 425, 429, 500, 502, 503, 504}
_RETRYABLE_HINTS = ("ratelimit", "timeout", "connection", "internalserver",
                    "serviceunavailable", "overloaded", "apistatus")


# Exhausted-quota / billing conditions. PERMANENT for this provider — retrying
# it is pointless — but the whole reason a fallback chain exists, since another
# provider is unaffected. Matched on the message as well as the class, because
# providers express this very differently: an OpenAI-compatible endpoint may
# raise RateLimitError(429) with "insufficient_quota", while others use 402, 403
# or a plain 400 whose only clue is the wording.
#
# 404 belongs here too, even though it reads like a client error. From an
# OpenAI-compatible endpoint it means the MODEL is missing, not the request:
# Ollama answers 404 "model ... not found, try pulling it first" for a name that
# was never pulled into its volume. That is this provider being unusable, not a
# request no provider will accept — the distinction the old classification got
# wrong, turning a one-model typo into a dead chain and a silent channel.
_FAILOVER_CODES = {401, 402, 403, 404}
_FAILOVER_HINTS = ("quota", "insufficient", "credit", "billing", "exceeded",
                   "payment", "suspended", "deactivated", "expired",
                   "not found", "notfound", "does not exist", "try pulling",
                   "model_not_found", "no such model")


def _status_of(exc: Exception):
    return getattr(exc, "status_code", None) or getattr(exc, "status", None)


def _is_retryable(exc: Exception) -> bool:
    """True for transient errors worth retrying THE SAME provider."""
    code = _status_of(exc)
    if isinstance(code, int) and code in _RETRYABLE_CODES:
        return True
    name = type(exc).__name__.lower()
    return any(h in name for h in _RETRYABLE_HINTS)


def _should_failover(exc: Exception) -> bool:
    """True when ANOTHER provider is worth trying.

    Broader than _is_retryable on purpose. Conflating the two was the bug: a
    Groq daily quota running out is permanent for Groq and useless to retry, so
    it was re-raised as "failing over won't help" and the local model was never
    called at all — the symptom being silent empty replies on every channel
    while ollama sat idle with no requests against it.

    Everything transient qualifies, plus quota/billing/auth conditions. A
    genuine 400 for a malformed request still does not: no provider will accept
    it and walking the chain only multiplies the latency.
    """
    if _is_retryable(exc):
        return True

    code = _status_of(exc)
    if isinstance(code, int) and code in _FAILOVER_CODES:
        return True

    blob = (str(exc) + " " + type(exc).__name__).lower()
    return any(h in blob for h in _FAILOVER_HINTS)


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

    async def chat(self, messages: List[ChatMessage], temperature: Optional[float] = None,
                   max_tokens: Optional[int] = None, model: Optional[str] = None) -> ChatResult:
        system_blocks, chat = _split_for_anthropic(messages)
        if not chat:
            return ChatResult(text="", model=self.model)

        kwargs: Dict[str, Any] = {
            "model": model or self.model,
            "max_tokens": max_tokens or self.max_tokens,
            "messages": chat,
        }
        if temperature is not None:
            kwargs["temperature"] = temperature
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

    async def stream_chat(
        self,
        messages: List[ChatMessage],
        temperature: Optional[float] = None,
    ) -> AsyncIterator[Dict[str, Any]]:
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
        # Callers need the same determinism knob the non-streaming path has:
        # grounded Q&A runs at ~0.2, free conversation at ~0.85.
        if temperature is not None:
            kwargs["temperature"] = temperature

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

    async def chat(self, messages: List[ChatMessage], temperature: Optional[float] = None,
                   max_tokens: Optional[int] = None, model: Optional[str] = None) -> ChatResult:
        msgs = _to_openai_messages(messages)
        if not msgs:
            return ChatResult(text="", model=self.model)

        kwargs: Dict[str, Any] = {
            "model": model or self.model,
            "messages": msgs,
            "max_tokens": max_tokens or self.max_tokens,
        }
        if temperature is not None:
            kwargs["temperature"] = temperature
        resp = await self._client.chat.completions.create(**kwargs)
        choice = resp.choices[0]
        text = choice.message.content or ""
        usage = resp.usage
        return ChatResult(
            text=text,
            tokens_in=usage.prompt_tokens if usage else 0,
            tokens_out=usage.completion_tokens if usage else 0,
            model=resp.model,
        )

    async def stream_chat(
        self,
        messages: List[ChatMessage],
        temperature: Optional[float] = None,
    ) -> AsyncIterator[Dict[str, Any]]:
        msgs = _to_openai_messages(messages)
        if not msgs:
            yield {"type": "final", "text": "", "tokens_in": 0, "tokens_out": 0, "model": self.model}
            return

        aggregated = ""
        tokens_in = 0
        tokens_out = 0
        final_model = self.model

        extra: Dict[str, Any] = {}
        # Same determinism knob as the non-streaming path (see chat()).
        if temperature is not None:
            extra["temperature"] = temperature

        stream = await self._client.chat.completions.create(
            model=self.model,
            messages=msgs,
            max_tokens=self.max_tokens,
            stream=True,
            stream_options={"include_usage": True},
            **extra,
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
# Backend factory
# ---------------------------------------------------------------------------

class _OpenAICompatBackend(_GroqBackend):
    """Any provider that speaks the OpenAI chat-completions wire format.

    Covers Google Gemini (via its ``/v1beta/openai/`` endpoint), Cerebras,
    OpenRouter, Together and friends. The whole Groq implementation is reused —
    chat / stream_chat / extract are identical on the wire — so only the
    ``base_url`` and key differ. Adding another provider is one entry in
    ``_build_backend`` plus its settings.
    """

    def __init__(self, base_url: str, api_key: str, model: str, max_tokens: int, timeout: float):
        from openai import AsyncOpenAI
        self.model = model
        self.max_tokens = max_tokens
        self._client = AsyncOpenAI(
            api_key=api_key or "none",   # SDK requires a non-empty string
            base_url=base_url,
            timeout=timeout,
        )


def _build_backend(provider: str, settings, timeout: float):
    provider = (provider or "groq").lower()
    if provider == "anthropic":
        return _AnthropicBackend(settings.anthropic_api_key, settings.anthropic_model,
                                 settings.anthropic_max_tokens, timeout)
    if provider == "groq":
        return _GroqBackend(settings.groq_api_key, settings.groq_model,
                            settings.groq_max_tokens, timeout)
    if provider == "ollama":
        # Deliberately NOT the shared timeout — see Settings.ollama_timeout.
        return _OllamaBackend(settings.ollama_base_url, settings.ollama_model,
                              settings.ollama_max_tokens,
                              max(timeout, settings.ollama_timeout))
    if provider == "gemini":
        return _OpenAICompatBackend(settings.gemini_base_url, settings.gemini_api_key,
                                    settings.gemini_model, settings.gemini_max_tokens, timeout)
    if provider == "cerebras":
        return _OpenAICompatBackend(settings.cerebras_base_url, settings.cerebras_api_key,
                                    settings.cerebras_model, settings.cerebras_max_tokens, timeout)
    raise RuntimeError(
        f"Unknown LLM provider '{provider}'. "
        "Use 'groq', 'gemini', 'cerebras', 'anthropic' or 'ollama'.")


# ---------------------------------------------------------------------------
# Public dispatcher — with retry + provider fallback for load resilience
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

        # Primary backend (honors explicit overrides when supplied).
        if provider == "anthropic":
            self._backend: Any = _AnthropicBackend(
                api_key or settings.anthropic_api_key, model or settings.anthropic_model,
                max_tokens or settings.anthropic_max_tokens, timeout)
        elif provider == "groq":
            self._backend = _GroqBackend(
                api_key or settings.groq_api_key, model or settings.groq_model,
                max_tokens or settings.groq_max_tokens, timeout)
        elif provider == "ollama":
            self._backend = _OllamaBackend(
                settings.ollama_base_url, model or settings.ollama_model,
                max_tokens or settings.ollama_max_tokens,
                max(timeout, settings.ollama_timeout))
        else:
            # gemini / cerebras / any other OpenAI-compatible provider. Delegated
            # so the primary and fallback paths accept exactly the same names —
            # otherwise a provider usable as a fallback would be rejected here.
            self._backend = _build_backend(provider, settings, timeout)

        # Fallback CHAIN for when the primary rate-limits / fails.
        #
        # LLM_FALLBACK_PROVIDER accepts a comma-separated list, tried left to
        # right: e.g. "gemini,ollama" means a Groq daily-quota exhaustion moves
        # to Gemini's free tier first and only reaches the slow local model if
        # every cloud tier is also unavailable.
        #
        # A single value still works exactly as before.
        # When nothing is configured, DERIVE a chain rather than running with no
        # safety net. An exhausted Groq quota is a routine event, not an
        # exceptional one — free tiers reset daily — and the failure it produced
        # was silent empty replies on every channel at once. Requiring an env var
        # to be set in advance means the one time it matters, it is not set.
        #
        # Order: cloud tiers whose keys are actually present (fast, no RAM),
        # then the local model last (always reachable, slow, no quota to
        # exhaust). Ollama needs no key, so it is the one tier that cannot
        # itself run out — which is exactly what a last resort should be.
        configured = (settings.llm_fallback_provider or "").strip()
        if not configured:
            candidates = []
            if settings.groq_api_key:      candidates.append("groq")
            if settings.gemini_api_key:    candidates.append("gemini")
            if settings.cerebras_api_key:  candidates.append("cerebras")
            if settings.anthropic_api_key: candidates.append("anthropic")
            candidates.append("ollama")

            configured = ",".join(c for c in candidates if c != provider)
            logger.info("LLM fallback chain auto-derived: %s", configured or "(none available)")

        self._fallbacks: List[Any] = []
        for fb in [p.strip().lower() for p in configured.split(",")]:
            if not fb or fb == provider:
                continue
            try:
                self._fallbacks.append(_build_backend(fb, settings, timeout))
                logger.info("LLM fallback provider enabled: %s", fb)
            except Exception as exc:  # noqa: BLE001
                # A missing key or unknown name must not stop the app booting —
                # the remaining tiers still work.
                logger.warning("LLM fallback '%s' unavailable: %s", fb, exc)

        # Back-compat: some call sites still read `_fallback` (singular).
        self._fallback = self._fallbacks[0] if self._fallbacks else None

        self._max_retries = max(0, int(settings.llm_max_retries))
        self.model = self._backend.model

        # Per-request provider overrides, lazily built + cached by name.
        # Lets control-plane calls (SQL gen, router) use a stronger model
        # for one call while chat stays on the configured local provider.
        self._timeout = timeout
        self._overrides: Dict[str, Any] = {}

    def _backend_for(self, provider: Optional[str], api_key: Optional[str] = None,
                     base_url: Optional[str] = None, model: Optional[str] = None):
        """Resolve the backend for an optional per-request provider override.

        With api_key/base_url supplied, the caller is describing a brain
        configured in the admin rather than one this service has env for — the
        bring-your-own-key path. provider="openai_compat" plus those two fields
        fully specify any provider speaking the OpenAI chat-completions format,
        which is why a client pasting a DeepSeek key needs no code here.

        Those backends are cached under a key that includes the credentials, so
        two clients on the same provider with different keys never share a
        client object. Getting that wrong would route one tenant's conversation
        through another tenant's account — a billing and privacy failure, not a
        performance one.
        """
        if not provider and not api_key:
            return self._backend

        provider = (provider or "").lower()

        # A per-request credential means we cannot reuse the env-configured
        # backend even when the provider name matches.
        if api_key or base_url:
            # Hashed rather than raw: this key is logged on build and held in a
            # dict for the process lifetime, and neither is a place for a
            # customer's API key to sit in cleartext.
            fingerprint = hashlib.sha256(
                f"{provider}|{base_url or ''}|{api_key or ''}|{model or ''}".encode()
            ).hexdigest()[:16]
            cache_key = f"byok:{fingerprint}"

            if cache_key not in self._overrides:
                settings = get_settings()
                if provider == "anthropic":
                    backend: Any = _AnthropicBackend(
                        api_key or settings.anthropic_api_key,
                        model or settings.anthropic_model,
                        settings.anthropic_max_tokens, self._timeout)
                elif provider == "ollama":
                    backend = _OllamaBackend(
                        base_url or settings.ollama_base_url,
                        model or settings.ollama_model,
                        settings.ollama_max_tokens,
                        max(self._timeout, settings.ollama_timeout))
                else:
                    # Everything else goes through the generic compat client.
                    #
                    # NO fallback to the configured Gemini endpoint here, and that
                    # matters more than it looks. Defaulting base_url to Gemini's
                    # meant a Groq brain saved without one was quietly sent to
                    # Google — so a misconfigured brain failed AS A DIFFERENT
                    # PROVIDER, and the operator got a Google "model not found"
                    # error while testing Groq. An unusable configuration must say
                    # so, not silently become a call to somewhere else.
                    if not base_url:
                        raise RuntimeError(
                            "This brain has no endpoint URL, so there is nowhere to send the "
                            "request. Set its Base URL (e.g. https://api.groq.com/openai/v1)."
                        )
                    if not model:
                        raise RuntimeError(
                            "This brain has no model set, and the provider needs one. "
                            "Pick a model for it."
                        )

                    backend = _OpenAICompatBackend(
                        base_url,
                        api_key or "",
                        model,
                        settings.gemini_max_tokens, self._timeout)

                self._overrides[cache_key] = backend
                logger.info("LLM per-request backend built: provider=%s fp=%s", provider, fingerprint)

            return self._overrides[cache_key]

        if provider == self.provider:
            return self._backend
        if provider not in self._overrides:
            self._overrides[provider] = _build_backend(provider, get_settings(), self._timeout)
            logger.info("LLM override backend built on demand: %s", provider)
        return self._overrides[provider]

    async def aclose(self) -> None:
        await self._backend.close()
        # Every tier in the chain owns an HTTP client — close them all, not just
        # the first, or shutdown leaks a connection pool per configured fallback.
        for b in self._fallbacks:
            try:
                await b.close()
            except Exception:  # noqa: BLE001
                pass
        for b in self._overrides.values():
            try:
                await b.close()
            except Exception:  # noqa: BLE001
                pass

    async def _resilient(self, fn, label: str, primary=None):
        """Run fn(backend) with backoff retries on the primary, then fail
        over to the fallback backend once. `primary` defaults to the
        configured backend but may be a per-request override backend."""
        primary = primary or self._backend
        delay = 0.8
        last: Optional[Exception] = None
        for attempt in range(self._max_retries + 1):
            try:
                return await fn(primary)
            except Exception as exc:  # noqa: BLE001
                last = exc
                if not _should_failover(exc):
                    raise   # e.g. a malformed request — no provider will accept it
                if _is_retryable(exc) and attempt < self._max_retries:
                    logger.warning("LLM %s: retryable error (%s); retry %d/%d in %.1fs",
                                   label, type(exc).__name__, attempt + 1, self._max_retries, delay)
                    await asyncio.sleep(delay)
                    delay *= 2
                    continue
                # Permanent for this provider (quota, billing, auth). Retrying it
                # only burns the visitor's patience — go to the chain now.
                break
        # Primary exhausted on a retryable error (e.g. Groq 429 rate limit) →
        # fail over once. Prefer the configured fallback; otherwise, when the
        # failed call used a per-request override (e.g. provider=groq), fall
        # back to the default configured backend (e.g. local Ollama) so a
        # rate-limit degrades gracefully instead of erroring the whole turn.
        chain: List[Any] = list(self._fallbacks)
        # When the failed call used a per-request override (e.g. provider=groq),
        # the configured default (e.g. local Ollama) is also a valid last resort.
        if primary is not self._backend and self._backend not in chain:
            chain.append(self._backend)

        # The primary's MESSAGE, not just its class. What propagates from here is
        # the LAST tier's error, so without this line the reason the primary
        # failed is lost entirely — and that is the one worth reading, because it
        # is the provider the operator actually configured. A Groq brain reporting
        # a Google error was unreadable for exactly this reason.
        if last is not None:
            logger.warning("LLM %s: primary %s failed: %s",
                           label, type(primary).__name__, str(last)[:400])

        for alt in chain:
            if alt is primary:
                continue
            logger.warning("LLM %s: trying fallback %s", label, type(alt).__name__)
            try:
                return await fn(alt)
            except Exception as exc:  # noqa: BLE001
                # Keep walking, whatever this tier said. Re-raising a
                # non-failover error here was wrong: the "no provider will
                # accept it" reasoning holds for the PRIMARY, where the request
                # has not been tried anywhere yet, but by this point we already
                # know the request is fine and are hunting for a provider that
                # can serve it. One misconfigured tier — a model that was never
                # pulled, a bad base_url — must not veto the tiers behind it.
                # The chain is 1-3 entries, so the wasted latency is bounded.
                logger.warning("LLM %s: fallback %s also failed (%s)",
                               label, type(alt).__name__, type(exc).__name__)
                last = exc
        raise last  # type: ignore[misc]

    async def chat(self, messages: List[ChatMessage], generation_config=None, provider: Optional[str] = None,
                   temperature: Optional[float] = None, max_tokens: Optional[int] = None,
                   model: Optional[str] = None, api_key: Optional[str] = None,
                   base_url: Optional[str] = None) -> ChatResult:
        primary = self._backend_for(provider, api_key=api_key, base_url=base_url, model=model)

        # The per-request model applies to the PRIMARY ONLY. A model name belongs
        # to one provider, so carrying it into a failover guarantees a request no
        # fallback can serve — and worse, it replaces the real error with a
        # meaningless one.
        #
        # That is exactly how a Groq brain reported a Google failure: Groq was
        # called with llama-3.3-70b-versatile and failed; 404 and "not found"
        # count as failover-worthy, so the chain moved to the env-configured
        # Gemini backend — still asking for llama-3.3-70b-versatile — and Google
        # answered "models/llama-3.3-70b-versatile is not found". The Groq error
        # was never surfaced at all, which is why the symptom pointed at the wrong
        # vendor and stayed baffling.
        #
        # Each fallback now uses its own configured default model, which is the
        # only name it can be relied on to serve.
        return await self._resilient(
            lambda b: b.chat(
                messages,
                temperature=temperature,
                max_tokens=max_tokens,
                model=model if b is primary else None,
            ),
            "chat",
            primary=primary,
        )

    async def extract(self, prompt: str, response_schema: Dict[str, Any]) -> Dict[str, Any]:
        return await self._resilient(lambda b: b.extract(prompt, response_schema), "extract")

    async def stream_chat(
        self,
        messages: List[ChatMessage],
        generation_config=None,
        temperature: Optional[float] = None,
    ) -> AsyncIterator[Dict[str, Any]]:
        # Retry / fail over only BEFORE the first token is emitted — once we've
        # streamed text we can't safely restart without duplicating output.
        backend = self._backend
        tried: List[Any] = [backend]
        attempt = 0
        delay = 0.8
        while True:
            started = False
            try:
                async for frame in backend.stream_chat(messages, temperature=temperature):
                    started = True
                    yield frame
                return
            except Exception as exc:  # noqa: BLE001
                # `started` is the one hard stop: once frames have gone out we
                # cannot switch provider mid-reply without garbling it.
                if started:
                    raise
                # The "no provider will accept this request" veto only makes
                # sense on the primary, where nothing has been tried yet. Once
                # we are walking the chain the request is known-good, so a tier
                # rejecting it means that tier is unusable — keep going rather
                # than let it veto the tiers behind it.
                if backend is self._backend and not _should_failover(exc):
                    raise
                if _is_retryable(exc) and attempt < self._max_retries:
                    attempt += 1
                    logger.warning("LLM stream: retryable pre-output error (%s); retry %d/%d",
                                   type(exc).__name__, attempt, self._max_retries)
                    await asyncio.sleep(delay)
                    delay *= 2
                    continue
                # Permanent for this provider (quota, billing, auth) — do not
                # retry it, fall through to the chain below. This is the path
                # the webchat takes, since it streams over the WebSocket, so a
                # quota error here was the one users actually hit.
                # Walk the fallback chain, skipping tiers already tried.
                nxt = next((b for b in self._fallbacks if b not in tried), None)
                if nxt is not None:
                    logger.warning("LLM stream: %s exhausted; failing over to %s",
                                   type(backend).__name__, type(nxt).__name__)
                    backend = nxt
                    tried.append(nxt)
                    attempt = 0
                    delay = 0.8
                    continue
                raise
