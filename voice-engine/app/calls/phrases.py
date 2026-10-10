"""What the agent says between answers, the way a person would.

* While it looks something up — matched to what the caller asked about
  (an order, a price, their account, stock) and to how they sound: a
  worried or upset caller hears reassurance, not "One moment, let me
  check." for the fifth time.
* When the line goes quiet — "anything else, sir?" after an answer, "take
  your time" after a question, "are you there?" when nothing was heard.
* Goodbye — warm when the caller is done, kind when the line went dead.

No line is said twice in one call while an unused one is left. English,
Urdu and Roman Urdu are written here. Any other language is written once
by the model, the first time a call needs it, and kept for later calls.

Urdu lines use "hum" or subjunctive forms (dekh lun, check kar lun),
which read the same in a male or a female voice. "{,hon}" is where a
polite "sir" / "madam" goes when the listener could tell the caller's
voice; otherwise it disappears.
"""

from __future__ import annotations

import asyncio
import json
import logging
import random
import re
from typing import Any, Dict, List, Optional

from app.domain.schemas import ChatMessage
from app.services.speech_text import lang_name, norm_lang

logger = logging.getLogger(__name__)

# Upset callers hear reassurance while the agent checks.
UPSET = frozenset({"worried", "frustrated", "angry", "sad"})

BUILTIN: Dict[str, Dict[str, Any]] = {
    "en": {
        "hon": {"male": "sir", "female": "madam"},
        "checking": {
            "order": [
                "Let me pull up your order.",
                "One sec, I'm checking where your order is.",
                "Okay, let me look into that delivery for you.",
                "Sure, give me a moment to find your order.",
                "Right, checking the order status now.",
            ],
            "delivery": [
                "Let me check the delivery times for you.",
                "One moment, I'll see how long delivery takes.",
                "Sure, let me look at the delivery schedule.",
            ],
            "price": [
                "Let me check the latest price for you.",
                "One moment, I'll look up the rates.",
                "Sure, let me see what that costs.",
                "Give me a second to check that price.",
            ],
            "account": [
                "Let me open your account details.",
                "One moment, checking your account.",
                "Sure, let me look at your billing.",
                "Okay, pulling up your payment details.",
            ],
            "stock": [
                "Let me check what we have in stock.",
                "One moment, I'll check availability.",
                "Sure, let me see if that's available.",
            ],
            "general": [
                "Let me check that for you.",
                "One moment, please.",
                "Sure, give me a second.",
                "Okay, let me look that up.",
                "Hmm, let me see.",
                "Just a moment while I check.",
            ],
        },
        "reassure": [
            "Don't worry, I'll sort this out. Let me check.",
            "I understand. Let me look into it right away.",
            "I'm on it, just a moment.",
            "I hear you. Let me check what's going on.",
            "I'm sorry about this. Let me look right now.",
        ],
        "anything_else": [
            "Is there anything else I can help you with{,hon}?",
            "Anything else you'd like to ask{,hon}?",
            "Is there something else I can do for you today{,hon}?",
            "Can I help you with anything else{,hon}?",
        ],
        "take_your_time": [
            "Take your time{,hon}, I'm right here.",
            "No rush{,hon}, I'm still on the line.",
            "Whenever you're ready{,hon}.",
        ],
        "still_there": [
            "Hello{,hon}, are you still there?",
            "Hello{,hon}, can you hear me?",
            "Sorry{,hon}, I didn't catch anything. Are you there?",
        ],
        "goodbye": [
            "Alright{,hon}, thank you for calling. Have a wonderful day, goodbye!",
            "It was a pleasure helping you{,hon}. Take care, goodbye!",
            "Thanks for your time{,hon}. Have a great day!",
        ],
        "time_up": [
            "That's all the time we have on this call{,hon}. Thank you for calling, and feel free to call again later. Goodbye!",
            "Our call time is up{,hon}. Thanks so much for calling, goodbye!",
        ],
        "goodbye_silent": [
            "I can't seem to hear you{,hon}, so I'll end the call now. Please call again anytime. Goodbye!",
            "It looks like the line has gone quiet{,hon}. Feel free to call back anytime. Goodbye!",
        ],
    },
    "ur": {
        "hon": {"male": "سر", "female": "میڈم"},
        "checking": {
            "order": [
                "جی، آپ کا آرڈر چیک کر لوں۔",
                "ایک سیکنڈ، آرڈر کی تفصیل نکالتے ہیں۔",
                "جی ضرور، ذرا آرڈر کا اسٹیٹس دیکھ لوں۔",
                "بس ایک سیکنڈ، ڈلیوری کا پتا کرتے ہیں۔",
                "اچھا، دیکھتے ہیں آپ کا آرڈر کہاں تک پہنچا ہے۔",
            ],
            "delivery": [
                "جی، ڈلیوری کا ٹائم چیک کر لوں۔",
                "ایک سیکنڈ، دیکھتے ہیں ڈلیوری میں کتنے دن لگتے ہیں۔",
                "جی ضرور، ڈلیوری شیڈول دیکھ لوں۔",
            ],
            "price": [
                "جی، تازہ ریٹ چیک کر لوں۔",
                "ایک سیکنڈ، قیمت دیکھ لیتے ہیں۔",
                "جی ضرور، ذرا ریٹ لسٹ دیکھ لوں۔",
                "بس ایک سیکنڈ، ریٹ کنفرم کر لوں۔",
            ],
            "account": [
                "جی، آپ کا اکاؤنٹ دیکھ لوں۔",
                "ایک سیکنڈ، بلنگ کی تفصیل نکالتے ہیں۔",
                "جی، پیمنٹ کی تفصیل چیک کر لوں۔",
                "بس ایک سیکنڈ، اکاؤنٹ کھول کر دیکھتے ہیں۔",
            ],
            "stock": [
                "جی، اسٹاک چیک کر لوں۔",
                "ایک سیکنڈ، دیکھ لیتے ہیں کہ دستیاب ہے یا نہیں۔",
                "جی، ابھی معلوم کرتے ہیں کہ اسٹاک میں ہے یا نہیں۔",
            ],
            "general": [
                "جی، ایک سیکنڈ۔",
                "جی ضرور، ذرا چیک کر لوں۔",
                "بس ایک سیکنڈ، دیکھتے ہیں۔",
                "جی، ابھی معلوم کرتے ہیں۔",
                "اچھا، ذرا دیکھ لوں۔",
                "جی، ابھی بتاتے ہیں، بس ایک سیکنڈ۔",
            ],
        },
        "reassure": [
            "آپ فکر نہ کریں، ابھی دیکھ لیتے ہیں۔",
            "جی بالکل، آپ پریشان نہ ہوں، ابھی چیک کرتے ہیں۔",
            "کوئی مسئلہ نہیں، ابھی حل نکالتے ہیں، بس ایک سیکنڈ۔",
            "آپ بے فکر رہیں، بس ایک سیکنڈ دیں۔",
            "جی، یہ تو واقعی پریشانی کی بات ہے، ابھی دیکھتے ہیں۔",
        ],
        "anything_else": [
            "اس کے علاوہ کوئی اور خدمت{,hon}؟",
            "اور بتائیے{,hon}، کچھ اور؟",
            "کوئی اور سوال ہو تو بلا جھجک پوچھیں{,hon}۔",
            "کیا کسی اور چیز میں مدد ہو سکتی ہے{,hon}؟",
        ],
        "take_your_time": [
            "جی، آرام سے{,hon}، میں لائن پر ہوں۔",
            "کوئی جلدی نہیں{,hon}، آرام سے بتائیں۔",
            "جی، جب آپ تیار ہوں تب بتا دیں{,hon}۔",
        ],
        "still_there": [
            "ہیلو{,hon}، کیا آپ لائن پر ہیں؟",
            "ہیلو{,hon}، آواز نہیں آ رہی، کیا آپ لائن پر ہیں؟",
            "ہیلو{,hon}؟ آپ کی آواز نہیں آئی۔",
        ],
        "goodbye": [
            "جی ٹھیک ہے{,hon}، کال کرنے کا بہت شکریہ۔ اللہ حافظ!",
            "آپ کا بہت شکریہ{,hon}۔ آپ کا دن اچھا گزرے، اللہ حافظ!",
            "شکریہ{,hon}، کبھی بھی ضرورت ہو تو ضرور کال کیجیے گا۔ اللہ حافظ!",
        ],
        "time_up": [
            "اس کال کا وقت پورا ہو گیا ہے{,hon}۔ کال کرنے کا بہت شکریہ، آپ بعد میں دوبارہ کال کر سکتے ہیں۔ اللہ حافظ!",
            "معذرت{,hon}، کال کا وقت ختم ہو گیا ہے۔ بات کرنے کا بہت شکریہ، اللہ حافظ!",
        ],
        "goodbye_silent": [
            "لگتا ہے آواز نہیں آ رہی{,hon}۔ آپ دوبارہ کال کر لیجیے گا، اللہ حافظ!",
            "آپ کی آواز نہیں آ رہی{,hon}، اس لیے کال ختم کر رہے ہیں۔ دوبارہ کال کریں تو ہم حاضر ہیں۔ اللہ حافظ!",
        ],
    },
    "roman": {
        "hon": {"male": "sir", "female": "madam"},
        "checking": {
            "order": [
                "Ji, aap ka order check kar loon.",
                "Aik second, order ki tafseel nikalte hain.",
                "Ji zaroor, zara order ka status dekh loon.",
                "Bas aik second, delivery ka pata karte hain.",
                "Acha, dekhte hain aap ka order kahan tak pohancha hai.",
            ],
            "delivery": [
                "Ji, delivery ka time check kar loon.",
                "Aik second, dekhte hain delivery mein kitne din lagte hain.",
                "Ji zaroor, delivery schedule dekh loon.",
            ],
            "price": [
                "Ji, taaza rate check kar loon.",
                "Aik second, qeemat dekh lete hain.",
                "Ji zaroor, zara rate list dekh loon.",
                "Bas aik second, rate confirm kar loon.",
            ],
            "account": [
                "Ji, aap ka account dekh loon.",
                "Aik second, billing ki tafseel nikalte hain.",
                "Ji, payment ki details check kar loon.",
                "Bas aik second, account khol kar dekhte hain.",
            ],
            "stock": [
                "Ji, stock check kar loon.",
                "Aik second, dekh lete hain ke available hai ya nahi.",
                "Ji, abhi maloom karte hain ke stock mein hai ya nahi.",
            ],
            "general": [
                "Ji, aik second.",
                "Ji zaroor, zara check kar loon.",
                "Bas aik second, dekhte hain.",
                "Ji, abhi maloom karte hain.",
                "Acha, zara dekh loon.",
                "Ji, abhi batate hain, bas aik second.",
            ],
        },
        "reassure": [
            "Aap fikar na karein, abhi dekh lete hain.",
            "Ji bilkul, aap pareshan na hon, abhi check karte hain.",
            "Koi masla nahi, abhi hal nikalte hain, bas aik second.",
            "Aap befikar rahein, bas aik second dein.",
            "Ji, yeh to waqai pareshani ki baat hai, abhi dekhte hain.",
        ],
        "anything_else": [
            "Is ke ilawa koi aur khidmat{,hon}?",
            "Aur bataiye{,hon}, kuch aur?",
            "Koi aur sawal ho to bila jhijak poochein{,hon}.",
            "Kya kisi aur cheez mein madad ho sakti hai{,hon}?",
        ],
        "take_your_time": [
            "Ji, aaram se{,hon}, main line par hoon.",
            "Koi jaldi nahi{,hon}, aaram se bataiye.",
            "Ji, jab aap tayyar hon tab bata dein{,hon}.",
        ],
        "still_there": [
            "Hello{,hon}, kya aap line par hain?",
            "Hello{,hon}, awaaz nahi aa rahi, kya aap line par hain?",
            "Hello{,hon}? Aap ki awaaz nahi aayi.",
        ],
        "goodbye": [
            "Ji theek hai{,hon}, call karne ka bohat shukriya. Allah Hafiz!",
            "Aap ka bohat shukriya{,hon}. Aap ka din acha guzre, Allah Hafiz!",
            "Shukriya{,hon}, kabhi bhi zaroorat ho to zaroor call kijiye ga. Allah Hafiz!",
        ],
        "time_up": [
            "Is call ka waqt poora ho gaya hai{,hon}. Call karne ka bohat shukriya, aap baad mein dobara call kar sakte hain. Allah Hafiz!",
            "Maazrat{,hon}, call ka waqt khatam ho gaya hai. Baat karne ka bohat shukriya, Allah Hafiz!",
        ],
        "goodbye_silent": [
            "Lagta hai awaaz nahi aa rahi{,hon}. Aap dobara call kar lijiye ga, Allah Hafiz!",
            "Aap ki awaaz nahi aa rahi{,hon}, is liye call khatam kar rahe hain. Dobara call karein to hum haazir hain. Allah Hafiz!",
        ],
    },
}

KINDS = ("reassure", "anything_else", "take_your_time", "still_there", "goodbye", "goodbye_silent", "time_up")

# What a caller is asking about, from words they use in English, Roman
# Urdu and Urdu script. First match wins, so refunds read as "account"
# before "order".
TOPICS = (
    ("account", r"account|bill|invoice|payment|refund|balance|paise|paisay|pese|اکاؤنٹ|بل\b|انوائس|پیمنٹ|ریفنڈ|ادائیگی|پیسے"),
    ("order", r"order|parcel|shipment|track|courier|dispatch|pohanch|pahunch|آرڈر|پارسل|پہنچ|ٹریک"),
    ("delivery", r"deliver|shipping|kitne din|kab tak|ڈلیوری|ڈیلیوری|کتنے دن|کب تک"),
    ("price", r"price|cost|rate|how much|discount|qeemat|keemat|kitne ka|kitne ki|kitnay|قیمت|ریٹ|کتنے کا|کتنے کی|ڈسکاؤنٹ"),
    ("stock", r"stock|available|availability|milega|milay|dastiyab|اسٹاک|دستیاب|ملے گا|ملے گی"),
)
_TOPIC_RE = [(name, re.compile(rx, re.IGNORECASE)) for name, rx in TOPICS]

# A caller turn made only of these words is a closing ("no, that's all,
# thanks", "nahi bas, shukriya", "theek hai, Allah Hafiz"): nothing to
# look up, and no "let me check" before the goodbye.
CLOSING_WORDS = frozenset("""
no nope nothing nah that's thats that is all else thanks thank you very much so lot bye goodbye good
ok okay alright fine great perfect have a nice day take care cheers it's its done sir madam
nahi nai na bas bus bohat bahut boht shukriya shukria shukriyah meharbani mehrbani allah hafiz
khuda theek thik hai he ji jee acha accha achha sab kuch aap ka ki bhi jazakallah jazak salamat raho
walaikum wa alaikum salam assalam o
نہیں بس بہت شکریہ مہربانی اللہ حافظ خدا ٹھیک ہے جی اچھا سب کچھ آپ کا کی بھی جزاک السلام علیکم وعلیکم سلام
""".split())
_WORD = re.compile(r"[\w']+", re.UNICODE)
_GOODBYE = re.compile(
    r"allah\s*hafiz|khuda\s*hafiz|اللہ\s*حافظ|خدا\s*حافظ|fi\s*aman|good\s*-?\s*bye|\bbye\b|take care"
    r"|have a (nice|good|great|wonderful) day|apna khayal|اپنا خیال", re.IGNORECASE)
_MORE_HELP = re.compile(
    r"anything else|something else|any other|koi aur|kuch aur|kisi aur|mazeed|کوئی اور|کچھ اور|کسی اور|مزید",
    re.IGNORECASE)


def is_closing(text: str) -> bool:
    """A short turn that only thanks, agrees or says goodbye."""
    words = _WORD.findall((text or "").lower())
    return 0 < len(words) <= 10 and all(w in CLOSING_WORDS for w in words)


def says_goodbye(text: str) -> bool:
    return bool(_GOODBYE.search(text or ""))


def offers_more_help(text: str) -> bool:
    """Did the agent just ask "anything else?" (in any of our languages)."""
    return bool(_MORE_HELP.search(text or ""))

# Other languages, written by the model once per process.
_GENERATED: Dict[str, Dict[str, Any]] = {}
_GENERATING: Dict[str, "asyncio.Future"] = {}


def topic_of(text: str) -> str:
    for name, rx in _TOPIC_RE:
        if rx.search(text or ""):
            return name
    return "general"


def book_key(lang: Optional[str], urdu_script: str = "urdu") -> str:
    """Which phrasebook a reply language uses ("roman" is Urdu written in
    Latin letters)."""
    code = norm_lang(lang) or "en"
    if code in ("ur", "hi"):
        return "roman" if urdu_script == "roman" else "ur"
    return code


class Phrases:
    """One call's phrasebook: remembers what it said, so it varies."""

    def __init__(self, rng: Optional[random.Random] = None) -> None:
        self._used: set = set()
        self._rng = rng or random.Random()

    def checking(self, key: str, user_text: str = "", mood: str = "calm",
                 honorific: Optional[str] = None) -> Optional[str]:
        """Something natural to say while the answer is looked up."""
        book = _book(key)
        if not book:
            return None
        if mood in UPSET and book.get("reassure"):
            options = book["reassure"]
        else:
            checking = book.get("checking") or {}
            if isinstance(checking, list):  # a generated book has one list
                options = checking
            else:
                options = checking.get(topic_of(user_text)) or checking.get("general") or []
        return self._pick(options, book, honorific)

    def say(self, kind: str, key: str, honorific: Optional[str] = None) -> Optional[str]:
        """``kind``: anything_else, take_your_time, still_there, goodbye, goodbye_silent."""
        book = _book(key)
        if not book:
            return None
        return self._pick(book.get(kind) or [], book, honorific)

    def _pick(self, options: List[str], book: Dict[str, Any], honorific: Optional[str]) -> Optional[str]:
        if not options:
            return None
        fresh = [o for o in options if o not in self._used]
        choice = self._rng.choice(fresh or options)
        self._used.add(choice)
        return _honour(choice, book, honorific)


def _book(key: str) -> Optional[Dict[str, Any]]:
    return BUILTIN.get(key) or _GENERATED.get(key)


def _honour(line: str, book: Dict[str, Any], caller: Optional[str]) -> str:
    """Fill "{,hon}" with ", sir" / ", madam" — or drop it."""
    word = (book.get("hon") or {}).get(caller or "")
    comma = "، " if any("؀" <= ch <= "ۿ" for ch in line) else ", "
    return line.replace("{,hon}", f"{comma}{word}" if word else "")


def has_book(key: str) -> bool:
    return _book(key) is not None


async def ensure_book(key: str, llm: Any) -> bool:
    """Write the phrasebook for a language we have none for (Spanish,
    Arabic, Tamil …), once per process. Returns whether one exists."""
    if has_book(key) or not key or llm is None:
        return has_book(key)
    pending = _GENERATING.get(key)
    if pending is None:
        pending = asyncio.ensure_future(_generate(key, llm))
        _GENERATING[key] = pending
    try:
        await asyncio.shield(pending)
    except Exception:  # noqa: BLE001 — logged in _generate; try again next call
        pass
    finally:
        if pending.done():
            _GENERATING.pop(key, None)
    return has_book(key)


GENERATE_PROMPT = (
    "Write the short spoken lines a warm, polite phone agent uses, in {name} (language code {code}), "
    "the way native speakers really talk on the phone — natural and everyday, not formal or robotic. "
    "Each line under 12 words. No names. Gender-neutral: nothing that shows whether the agent is a man "
    "or a woman. Where a polite 'sir' or 'madam' would go, write {{,hon}} exactly (it is replaced later).\n"
    "Return JSON only, every value in {name}:\n"
    '{{"checking": [6 different ways to say "let me check that for you" while looking something up],\n'
    ' "reassure": [4 ways to calm a worried or upset caller while you check],\n'
    ' "anything_else": [4 ways to ask if there is anything else, with {{,hon}}],\n'
    ' "take_your_time": [3 ways to say take your time, I am on the line, with {{,hon}}],\n'
    ' "still_there": [3 ways to ask hello, are you still there, with {{,hon}}],\n'
    ' "goodbye": [3 warm goodbyes thanking them for calling, with {{,hon}}],\n'
    ' "goodbye_silent": [2 kind goodbyes when you cannot hear the caller, inviting them to call back, with {{,hon}}],\n'
    ' "time_up": [2 polite goodbyes saying the call time is up, thanking them, with {{,hon}}],\n'
    ' "hon": {{"male": "the word for sir", "female": "the word for madam"}}}}'
)


async def _generate(key: str, llm: Any) -> None:
    prompt = GENERATE_PROMPT.format(name=lang_name(key), code=key)
    text = ""
    try:
        async for evt in llm.stream_chat([ChatMessage(role="user", content=prompt)]):
            if evt.get("type") == "delta":
                text += evt.get("text", "")
    except Exception:  # noqa: BLE001
        logger.exception("phrasebook for %s: generation failed", key)
        raise
    book = _parse_book(text)
    if book:
        _GENERATED[key] = book
        logger.info("phrasebook for %s written by the model", key)
    else:
        logger.warning("phrasebook for %s: unusable model output: %s", key, text[:200])


def _parse_book(text: str) -> Optional[Dict[str, Any]]:
    match = re.search(r"\{.*\}", text or "", re.DOTALL)
    if not match:
        return None
    try:
        raw = json.loads(match.group(0))
    except ValueError:
        return None
    book: Dict[str, Any] = {}
    for kind in ("checking",) + KINDS:
        lines = [str(x).strip() for x in (raw.get(kind) or []) if str(x).strip()]
        if lines:
            book[kind] = lines
    hon = raw.get("hon") or {}
    if isinstance(hon, dict):
        book["hon"] = {k: str(v) for k, v in hon.items() if k in ("male", "female") and v}
    # A book without checking lines or goodbyes isn't worth keeping.
    return book if book.get("checking") and book.get("goodbye") else None
