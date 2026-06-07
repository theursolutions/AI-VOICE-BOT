"""Light-weight intent classifier used by the WS adapters.

We use this to decide whether a turn needs a roundtrip to Laravel's
``/resolve-context`` (RAG + DB + webhook tools) or whether it can be
answered straight from the LLM's base knowledge. Roundtrips take 3-8s
on CPU so skipping them for greetings + small talk makes the bot feel
much more responsive.

Heuristic-based on purpose. An LLM classifier would be more accurate
but adds the very latency we're trying to avoid. The patterns here are
intentionally narrow — false positives (chitchat marked as data) just
mean we save a roundtrip; false negatives (data marked as chitchat)
mean the bot answers without context, which is worse, so prefer the
false-positive direction.
"""

from __future__ import annotations

import re


# Regex patterns matched against the lowercased, stripped query.
# Each pattern is a complete utterance shape — we anchor to the start
# (`^`) and require a word boundary at the end (`\b`) so things like
# "how are leads doing?" don't accidentally hit "how are".
_CHITCHAT_PATTERNS = [
    # Greetings
    r"^(hi|hello|hey|yo|sup|hiya|howdy)\b",
    r"^good (morning|afternoon|evening|night|day)\b",
    # "How are you" family (NOT "how are leads", "how are sales")
    r"^how (are|r|'?s) (you|u)\b",
    r"^how('?s| is) (it|your day) going\b",
    r"^what'?s up\b",
    # Acknowledgements
    r"^(ok|okay|fine|great|good|cool|nice|alright|sure|yeah|yes|yep|no|nope|nah)\b",
    # Thanks
    r"^thank(s| you)\b",
    r"^appreciate (it|that)\b",
    # Goodbyes
    r"^(bye|goodbye|see ?ya|cya|catch you later|talk (later|soon)|gotta go|have a good)",
    # Small-talk about the bot
    r"who are you\b",
    r"what('?s| is) your name\b",
    r"can you help\b",
    r"^test(ing)?\b",
]


# Data-question signals that should always go through the resolver
# even if the message is short — protects against false positives
# for phrases like "how many ..." where "how" alone is ambiguous.
_DATA_SIGNALS = [
    r"\bhow many\b",
    r"\bhow much\b",
    r"\b(list|show|find|search|count|count me)\b",
    r"\b(who|where|when|which)\b",
    r"\b(what|whats) (is|are|was) (the|my|our|your)\b",
    r"\bgive me\b",
    r"\b(report|stats|statistics|metrics|analytics)\b",
    r"\bget (me )?(the|a|all)\b",
]


def is_chitchat(user_text: str) -> bool:
    """True if `user_text` is plausibly small-talk (no DB needed)."""
    if not user_text:
        return True
    text = user_text.lower().strip().rstrip("?.,!")

    # Data-question signals always win — even short messages with a
    # data verb in them go through the resolver.
    for p in _DATA_SIGNALS:
        if re.search(p, text):
            return False

    # Very short messages are usually small talk ("hi", "ok", "bye").
    if len(text) <= 4:
        return True

    for p in _CHITCHAT_PATTERNS:
        if re.search(p, text):
            return True

    return False
