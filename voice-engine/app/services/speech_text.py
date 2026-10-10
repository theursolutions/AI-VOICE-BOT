"""Text helpers shared by every spoken channel (web voice, phone, SIP).

Sentence splitting decides when LLM text is flushed to TTS; the language
helpers pick a voice XTTS can actually speak and phrase the reply-language
rule for the system prompt.
"""

from __future__ import annotations

import re
from typing import List, Optional, Tuple

# Soft sentence boundary: punctuation followed by whitespace or end.
# Includes the Urdu full stop (۔) and question mark (؟) — without them an
# Urdu-script reply never splits and is only spoken once the LLM finishes.
SENTENCE_BOUNDARY = re.compile(r"([\.!\?。！？۔؟]+)(\s|$)")

# Languages the XTTS-v2 multilingual model can actually speak. Anything
# outside this set has no voice, so we fall back to English *audio* (the
# reply TEXT may still be in the user's language — only the spoken audio
# is English when the language is unsupported).
XTTS_LANGS = frozenset({
    "en", "es", "fr", "de", "it", "pt", "pl", "tr", "ru", "nl",
    "cs", "ar", "zh", "ja", "hu", "ko", "hi",
})

# Friendly names so the style prompt reads naturally ("reply in Arabic").
LANG_NAMES = {
    "en": "English", "ar": "Arabic", "ur": "Urdu", "hi": "Hindi",
    "es": "Spanish", "fr": "French", "de": "German", "it": "Italian",
    "pt": "Portuguese", "ru": "Russian", "zh": "Chinese", "ja": "Japanese",
    "ko": "Korean", "tr": "Turkish", "nl": "Dutch", "pl": "Polish",
    "cs": "Czech", "hu": "Hungarian",
    # Every language a call can run in (BotAgent::CALL_LANGUAGES).
    "pa": "Punjabi", "sd": "Sindhi", "ps": "Pashto", "bn": "Bengali",
    "ta": "Tamil", "te": "Telugu", "mr": "Marathi", "gu": "Gujarati",
    "kn": "Kannada", "ml": "Malayalam", "ne": "Nepali", "si": "Sinhala",
    "fa": "Persian", "he": "Hebrew", "uk": "Ukrainian", "ro": "Romanian",
    "el": "Greek", "sv": "Swedish", "da": "Danish", "nb": "Norwegian",
    "no": "Norwegian", "fi": "Finnish", "bg": "Bulgarian", "id": "Indonesian",
    "ms": "Malay", "th": "Thai", "vi": "Vietnamese", "fil": "Filipino",
    "tl": "Filipino", "sw": "Swahili", "am": "Amharic",
}


def split_sentences(buffer: str) -> Tuple[List[str], str]:
    """Greedy sentence splitter.

    Returns ``(complete_sentences, remainder)``. Used to decide when
    to flush text into TTS without waiting for the entire LLM response.
    """
    sentences: List[str] = []
    last = 0
    for match in SENTENCE_BOUNDARY.finditer(buffer):
        end = match.end()
        sentence = buffer[last:end].strip()
        if sentence:
            sentences.append(sentence)
        last = end
    return sentences, buffer[last:]


# Where a reply may be cut before its first sentence ends: a comma (Latin
# or Urdu ،), semicolon, colon or dash followed by a space.
CLAUSE_BOUNDARY = re.compile(r"[,،;:—–]\s")


def split_first_clause(buffer: str, min_chars: int = 24) -> Tuple[Optional[str], str]:
    """The opening clause of a reply once it is long enough to say on its
    own, so the caller hears the AI start before the first sentence ends.
    Returns ``(clause, remainder)`` or ``(None, buffer)``."""
    for match in CLAUSE_BOUNDARY.finditer(buffer):
        if match.start() >= min_chars:
            return buffer[:match.end()].strip(), buffer[match.end():]
    return None, buffer


# The model ends a call by closing its goodbye with this marker (see the
# call prompt, MemoryBuilder::voicePrompt). Never spoken.
END_CALL = "[END_CALL]"
# Bracketed, or with the underscore — never the plain words "end call".
_END_CALL_RE = re.compile(r"\[\s*END[_ ]CALL\s*\]|\bEND_CALL\b", re.IGNORECASE)
_MD_LINK = re.compile(r"\[([^\]]+)\]\((?:https?://)?[^)]+\)")
_URL = re.compile(r"https?://\S+")
_MD_MARKS = re.compile(r"(\*\*|__|\*|`+|^#{1,6}\s*|^\s*[-•>]\s+|^\s*\d+[.)]\s+)", re.MULTILINE)


def has_end_call(text: str) -> bool:
    return bool(_END_CALL_RE.search(text or ""))


def clean_for_speech(text: Optional[str]) -> str:
    """What the voice should actually say: no end-of-call marker, and no
    markdown or links — a model that slips into chat formatting would
    otherwise have "asterisk asterisk" read to the caller."""
    text = _END_CALL_RE.sub(" ", text or "")
    text = _MD_LINK.sub(r"\1", text)
    text = _URL.sub(" ", text)
    text = _MD_MARKS.sub("", text)
    # "#4567" reads as "hash"; brackets are just a pause when spoken.
    text = re.sub(r"#(?=\d)", "", text)
    text = re.sub(r"\s*\(([^)]*)\)", r", \1,", text)
    text = re.sub(r",\s*([,.!?،۔؟])", r"\1", text)
    return re.sub(r"\s+", " ", text).strip()


# How the voice delivers a reply to a caller in each mood (Google voices
# only; the listener reports the mood). Calm callers get the voice's own
# natural delivery — no notes, nothing that could be read aloud.
DELIVERY_STYLES = {
    "worried": "gently, warmly and reassuringly, at a calm unhurried pace",
    "sad": "softly and kindly, with quiet warmth, unhurried",
    "frustrated": "calmly and patiently, with genuine understanding, never rushed",
    "angry": "calmly, softly and respectfully, with sincere empathy, never defensive",
    "confused": "clearly and patiently, a little slower, friendly",
    "happy": "warmly and cheerfully, with a smile in the voice",
}


def delivery_style(mood: Optional[str]) -> Optional[str]:
    return DELIVERY_STYLES.get((mood or "").lower())


def norm_lang(lang: Optional[str]) -> str:
    """Normalise a locale-ish code to our short form ('en-US' → 'en',
    'zh-CN' → 'zh'). Returns '' for empty/auto."""
    if not lang:
        return ""
    code = lang.strip().lower().replace("_", "-")
    if code in ("auto", ""):
        return ""
    if code.startswith("zh"):
        return "zh"
    return code.split("-")[0]


def coerce_tts_lang(lang: Optional[str]) -> str:
    """Pick a language XTTS can speak; fall back to English otherwise."""
    code = norm_lang(lang)
    return code if code in XTTS_LANGS else "en"


def lang_name(lang: Optional[str]) -> str:
    return LANG_NAMES.get(norm_lang(lang), "English")


def build_style_prompt(preferred: Optional[str]) -> str:
    """Style + language contract, rebuilt per turn.

    The WS path otherwise sends only the reference-data block + the
    user's message, so without this the model rambles and sometimes
    emits HTML (<br>) the widget renders literally. Short + plain-text
    also keeps TTS latency/cost down (CPU XTTS runs ~1s per character).

    Language rule: the model mirrors the user's actual language, so a
    visitor who picked English but typed Urdu still gets an Urdu reply.
    The picked language is only the fallback when the language is
    unclear; English is the final fallback.
    """
    pref_name = lang_name(preferred)
    return (
        "You are a helpful assistant. Reply in a short, precise and natural "
        "way — usually 1-3 sentences and no more than ~60 words. Get straight "
        "to the point; skip filler and pleasantries. "
        "Always reply in the SAME language as the user's most recent message. "
        f"If you cannot tell which language they used, reply in {pref_name}. "
        "If you cannot write that language, use English. "
        "GROUNDING RULES (follow exactly): when a 'Reference data' section is "
        "provided, answer ONLY using the facts in it; copy numbers, names and "
        "values from it EXACTLY — never round, estimate, or invent. If the "
        "requested fact is not in the Reference data, say you don't have that "
        "information — do NOT make up companies, numbers, products or details. "
        "Use plain text only — no markdown, no HTML tags, and never write "
        "'<br>'. When you need details from the user, ask for one thing at a time."
    )
