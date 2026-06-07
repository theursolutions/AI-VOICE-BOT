"""Lead extraction pipeline.

The pipeline:
1. Build a flat conversation excerpt (last N turns, capped).
2. Ask the LLM to populate a small structured schema (LeadFields).
3. Strip empty/null values so we don't overwrite existing data with blanks.
4. Recompute a conservative confidence score based on which fields the
   model actually populated AND any obvious noise (e.g. a placeholder
   "user@example.com" → drop the email and lower confidence).

The Python side never decides whether to *save* the lead. That's
Laravel's job — it applies the project-level confidence threshold and
dedup rules.
"""

from __future__ import annotations

import json
import logging
import re
from typing import Any, Dict, List, Optional

from app.domain.schemas import ChatMessage, ExtractRequest, ExtractResult, LeadFields
from app.services.llm_service import LLMService

logger = logging.getLogger(__name__)


# Hand-crafted to keep Gemini happy; the auto-derived JSON schema from
# Pydantic includes too many keywords (definitions, etc.) that Gemini
# rejects.
LEAD_RESPONSE_SCHEMA: Dict[str, Any] = {
    "type": "object",
    "properties": {
        "fields": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "email": {"type": "string"},
                "phone": {"type": "string"},
                "intent": {"type": "string"},
                "budget": {"type": "string"},
                "timeline": {"type": "string"},
                "custom": {"type": "object"},
            },
        },
        "confidence": {"type": "number"},
    },
    "required": ["fields", "confidence"],
}


PROMPT_TEMPLATE = """You are a strict lead-extraction engine for a CRM.

Read the conversation excerpt and return a JSON object with the lead
fields the **user (customer)** actually stated. The "assistant" is the
AI bot — its replies are context, NOT data sources. Never extract a
field from the assistant's words.

# Rules

1. Only populate a field when the user CLEARLY stated it. If unsure,
   leave it OUT. Missing fields are preferred over hallucinated ones.
2. Never invent placeholder values (`user@example.com`, `+1234567890`,
   `John Doe`, `unknown`). If the value looks like a placeholder, OMIT it.
3. Normalise phone to E.164 (`+CC...`). If the country code is unclear,
   keep the digits as the user said them but prefix with `+` if they
   said one. Strip spaces, dashes, parens.
4. `intent` is one of: `demo_request`, `pricing`, `support`,
   `sales_inquiry`, `complaint`, `general`. Lowercase snake_case. Pick
   the closest fit.
5. `budget` and `timeline` are free-form short strings ("under $5k",
   "this quarter", "ASAP"). Keep the user's own wording when clear.
6. `custom` is an object of any other concrete, useful key→value pairs
   the user mentioned (e.g. `{{"company": "Acme", "industry": "saas"}}`).
   Skip vague mentions.
7. Preserve existing fields below — only overwrite when the new turn
   gives clearly better evidence.

# Confidence

Set `confidence` to a float in [0, 1]:
  - `0.0`  no usable info extracted
  - `0.3`  one weak field (name only, or intent only)
  - `0.6`  contact (email OR phone) extracted + something else
  - `0.85` contact + intent + a second concrete field
  - `1.0`  full lead with email + phone + intent + budget/timeline

Be conservative — if a field could be a misheard transcript, lower the score.

# Few-shot examples

EXAMPLE 1
Existing: {{}}
USER: Hi, I'm Sarah Johnson from Acme. We're looking at pricing for the pro plan.
USER: My email is sarah.johnson@acme.com.
Output:
{{"fields":{{"name":"Sarah Johnson","email":"sarah.johnson@acme.com","intent":"pricing","custom":{{"company":"Acme","plan":"pro"}}}},"confidence":0.85}}

EXAMPLE 2
Existing: {{"name":"Mike"}}
USER: yeah we need a demo by next friday
USER: budget is around 20k
Output:
{{"fields":{{"intent":"demo_request","timeline":"next friday","budget":"around 20k"}},"confidence":0.7}}

EXAMPLE 3 (don't hallucinate)
Existing: {{}}
USER: hi
ASSISTANT: Hi, how can I help?
USER: just looking around
Output:
{{"fields":{{"intent":"general"}},"confidence":0.2}}

# This conversation

Existing fields (do not regress):
{existing_fields}

Conversation excerpt (latest at the bottom):
{conversation}
""".strip()


# Heuristic placeholders we should reject even if the LLM hands them back.
_PLACEHOLDER_EMAILS = {"user@example.com", "test@test.com", "john@doe.com", "name@email.com"}
_PLACEHOLDER_NAMES  = {"john doe", "jane doe", "unknown", "anonymous", "user"}
_PLACEHOLDER_PHONES = {"+1234567890", "0000000000", "1234567890"}

_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
_INTENT_ALLOWED = {"demo_request", "pricing", "support", "sales_inquiry", "complaint", "general"}


def _format_history(req: ExtractRequest) -> str:
    """Flatten history into a transcript block. Falls back to the
    user_text/assistant_text pair if history wasn't passed."""
    msgs: List[ChatMessage] = list(req.history or [])
    if not msgs:
        if req.user_text:
            msgs.append(ChatMessage(role="user", content=req.user_text))
        if req.assistant_text:
            msgs.append(ChatMessage(role="assistant", content=req.assistant_text))

    # Trim to the last 20 messages — keeps the prompt bounded.
    if len(msgs) > 20:
        msgs = msgs[-20:]

    lines = []
    for m in msgs:
        prefix = "USER" if m.role == "user" else "ASSISTANT" if m.role == "assistant" else m.role.upper()
        # Trim each message to 600 chars so a long-winded turn can't blow the prompt.
        content = (m.content or "")[:600]
        lines.append(f"{prefix}: {content}")
    return "\n".join(lines) if lines else "(empty conversation)"


def _is_placeholder_email(v: str) -> bool:
    v = v.lower().strip()
    if v in _PLACEHOLDER_EMAILS:
        return True
    return not _EMAIL_RE.match(v)


def _clean_phone(v: str) -> Optional[str]:
    digits = re.sub(r"[^\d+]", "", v)
    if not digits or digits in _PLACEHOLDER_PHONES:
        return None
    if len(re.sub(r"\D", "", digits)) < 7:
        return None
    return digits


def _sanitise(fields: Dict[str, Any]) -> Dict[str, Any]:
    """Remove placeholders, normalise, drop empty values.

    Empty values would otherwise overwrite real existing data on the
    Laravel side when ExtractLeadFromTurn merges.
    """
    out: Dict[str, Any] = {}

    name = (fields.get("name") or "").strip()
    if name and name.lower() not in _PLACEHOLDER_NAMES:
        out["name"] = name

    email = (fields.get("email") or "").strip()
    if email and not _is_placeholder_email(email):
        out["email"] = email.lower()

    phone = _clean_phone((fields.get("phone") or "").strip())
    if phone:
        out["phone"] = phone

    intent = (fields.get("intent") or "").strip().lower().replace(" ", "_")
    if intent in _INTENT_ALLOWED:
        out["intent"] = intent
    elif intent:
        # Allow unknown intents but flag them so future analysis can see drift.
        logger.info("extractor: non-standard intent %r — keeping", intent)
        out["intent"] = intent

    for k in ("budget", "timeline"):
        v = (fields.get(k) or "").strip()
        if v:
            out[k] = v

    custom = fields.get("custom") or {}
    if isinstance(custom, dict):
        cleaned_custom = {ck: cv for ck, cv in custom.items() if cv not in (None, "", [], {})}
        if cleaned_custom:
            out["custom"] = cleaned_custom

    return out


def _recompute_confidence(fields: Dict[str, Any], model_confidence: float) -> float:
    """Conservative confidence: take min of (LLM's estimate, heuristic).

    Heuristic ladder mirrors the prompt's calibration so the LLM and
    our own scoring stay aligned.
    """
    has_contact = bool(fields.get("email") or fields.get("phone"))
    has_intent  = bool(fields.get("intent"))
    extras      = sum(1 for k in ("name", "budget", "timeline") if fields.get(k))
    extras     += 1 if fields.get("custom") else 0

    if has_contact and has_intent and extras >= 2: heuristic = 0.95
    elif has_contact and has_intent:               heuristic = 0.8
    elif has_contact:                              heuristic = 0.6
    elif has_intent and extras >= 1:               heuristic = 0.5
    elif has_intent or extras >= 1:                heuristic = 0.3
    else:                                          heuristic = 0.0

    # Cap by the model's own estimate (don't inflate above what the LLM said).
    return round(min(max(model_confidence, 0.0), 1.0) * 0.5 + heuristic * 0.5, 2)


class ExtractorService:
    def __init__(self, llm: LLMService) -> None:
        self.llm = llm

    async def extract(self, req: ExtractRequest) -> ExtractResult:
        prompt = PROMPT_TEMPLATE.format(
            existing_fields=json.dumps(req.existing_fields or {}, ensure_ascii=False),
            conversation=_format_history(req),
        )

        raw = await self.llm.extract(prompt, LEAD_RESPONSE_SCHEMA)
        if not raw:
            return ExtractResult(fields=LeadFields(), confidence=0.0)

        fields_data = raw.get("fields") or {}
        model_confidence = float(raw.get("confidence", 0.0) or 0.0)

        cleaned = _sanitise(fields_data)
        confidence = _recompute_confidence(cleaned, model_confidence)

        try:
            fields = LeadFields(**cleaned)
        except Exception:  # noqa: BLE001
            logger.warning("LLM returned malformed fields: %r", cleaned)
            fields = LeadFields()

        return ExtractResult(fields=fields, confidence=confidence)
