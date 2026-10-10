"""How a call sounds human: listening for mood and voice, varied lines
while checking, waiting for a caller mid-thought, checking in when the
line goes quiet, and ending the call with a goodbye.

    cd voice-engine && .venv/Scripts/python -m unittest discover -s tests -v
"""

from __future__ import annotations

import asyncio
import base64
import io
import json
import os
import tempfile
import unittest
import wave

import httpx

from app.calls.audiosocket import AudioSocketTransport
from app.calls.phrases import BUILTIN, Phrases, _parse_book, ensure_book, topic_of, _GENERATED
from app.calls.session import ACTIVE_CALLS
from app.services.google_tts import GoogleTTS, GoogleVoice, speech_prompt
from app.services.listener import GeminiListener, Heard, ListenerError
from app.services.speech_text import clean_for_speech, has_end_call

from test_call_session import (
    FakeLaravel,
    FakeLLM,
    FakeSTT,
    FakeTransport,
    make_call,
    say,
    settle,
    silence,
    speech,
    wait_for,
)


class FakeListener:
    def __init__(self, *heard: Heard, fail: bool = False) -> None:
        self.heard = list(heard)
        self.fail = fail
        self.calls = []

    async def hear(self, pcm, language=None, hints=""):
        self.calls.append({"bytes": len(pcm), "language": language, "hints": hints})
        if self.fail:
            raise ListenerError("HTTP 503")
        return self.heard.pop(0) if self.heard else Heard(text="", language=None)


class FakeGoogle:
    sample_rate = 24000

    def __init__(self) -> None:
        self.spoken = []

    def stream(self, text, voice, style=None):
        self.spoken.append((text, style))
        yield b"\x01\x00" * 2400  # 0.1 s


def lines(lang, kind, honorific=""):
    """The phrasebook lines as spoken, "{,hon}" filled or dropped."""
    comma = "، " if lang == "ur" else ", "
    return [l.replace("{,hon}", f"{comma}{honorific}" if honorific else "") for l in BUILTIN[lang][kind]]


def google_call(listener, llm, laravel=None, transport=None, **settings):
    call = make_call(FakeSTT(), llm, laravel or FakeLaravel(), transport, listener=listener, **settings)
    google = FakeGoogle()
    call.tts = GoogleVoice(google, "Kore")
    return call, google


class ListeningTest(unittest.IsolatedAsyncioTestCase):
    async def asyncTearDown(self):
        ACTIVE_CALLS.clear()

    async def test_mood_and_voice_shape_the_reply_and_its_delivery(self):
        listener = FakeListener(Heard(text="Mera order abhi tak nahi aaya", language="ur",
                                      mood="worried", caller="male"))
        llm = FakeLLM("Main abhi check karti hoon.")
        call, google = google_call(listener, llm)
        call.language = "auto"
        await call.start()
        await say(call, speech(500) + silence(700))
        await settle(call)

        system = llm.calls[0][0].content
        self.assertIn("worried", system)
        self.assertIn('"sir"', system)
        styles = [style for text, style in google.spoken if text.startswith("Main abhi")]
        self.assertTrue(styles and "reassuring" in styles[0])
        self.assertEqual(call.caller, "male")

    async def test_calm_caller_gets_the_voice_unstyled(self):
        listener = FakeListener(Heard(text="thanks", language="en", mood="calm"))
        call, google = google_call(listener, FakeLLM("You're welcome."))
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual(google.spoken, [("You're welcome.", None)])

    async def test_the_reply_is_told_which_language_the_caller_spoke(self):
        listener = FakeListener(Heard(text="This is the third time I'm calling!", language="en", mood="angry"))
        laravel = FakeLaravel()
        laravel.profile_extra = {"stt_language": "auto", "language": "roman", "urdu_script": "roman",
                                 "voice_gender": "female"}
        llm = FakeLLM("I'm so sorry about that.")
        call, _ = google_call(listener, llm, laravel)
        call.greet("Assalam o Alaikum! Bataiye, hum aap ki kya madad kar sakte hain?")
        await call.start()
        await call._profile
        await say(call, speech(400) + silence(700))
        await settle(call)
        system = llm.calls[0][0].content
        # An English caller after a Roman Urdu greeting still gets English.
        self.assertIn("The caller is speaking English: reply in English.", system)
        self.assertIn("main karti hoon", system)
        self.assertIn("don't greet again", system)

    async def test_a_buzzing_line_is_not_a_caller(self):
        from test_call_session import buzz
        listener = FakeListener(Heard(text="Hello, Umer Foods se baat kar raha hoon?", language="ur"))
        llm = FakeLLM()
        call, _ = google_call(listener, llm)
        await call.start()
        await say(call, buzz(1500) + silence(700))
        await settle(call)
        self.assertEqual(listener.calls, [])  # never sent to be "heard"
        self.assertEqual(llm.calls, [])
        self.assertTrue(call.stats[-1]["noise"])

    async def test_its_own_voice_echoing_back_is_not_a_turn(self):
        laravel = FakeLaravel()
        llm = FakeLLM()
        listener = FakeListener(Heard(text="call karne ka shukriya bataiye hum aap ki kya madad", language="ur"))
        call, google = google_call(listener, llm, laravel)
        await call.start()
        call.greet("Assalam o Alaikum! Call karne ka shukriya. Bataiye, hum aap ki kya madad kar sakte hain?")
        await settle(call)
        await say(call, speech(700) + silence(700))
        await settle(call)
        self.assertEqual(llm.calls, [])
        self.assertEqual(laravel.turns, [])
        self.assertTrue(call.stats[-1]["echo"])

    async def test_listener_outage_falls_back_to_whisper(self):
        laravel = FakeLaravel()
        call = make_call(FakeSTT("thanks"), FakeLLM("You're welcome."), laravel,
                         listener=FakeListener(fail=True))
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        await wait_for(lambda: laravel.turns)
        self.assertEqual(laravel.turns[0].user_content, "thanks")
        self.assertEqual(call.stats[0]["listener"], "whisper")

    async def test_urdu_heard_as_hindi_is_answered_as_urdu(self):
        listener = FakeListener(Heard(text="मेरा ऑर्डर कहाँ है", language="hi"))
        laravel = FakeLaravel()
        call, _ = google_call(listener, FakeLLM("Ji."), laravel)
        call.language = "auto"
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        await wait_for(lambda: laravel.turns)
        self.assertEqual(laravel.turns[0].metadata["language"], "ur")
        self.assertEqual(laravel.turns[0].metadata["mood"], "calm")

    async def test_a_fixed_call_language_is_passed_to_the_listener(self):
        listener = FakeListener(Heard(text="hola", language="es"))
        laravel = FakeLaravel()
        laravel.profile_extra = {"stt_language": "es", "language": "es", "stt_hints": "Umer Foods"}
        call, _ = google_call(listener, FakeLLM("Hola."), laravel)
        await call.start()
        await call._profile
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual(listener.calls[0]["language"], "es")
        self.assertEqual(listener.calls[0]["hints"], "Umer Foods")


class TurnTakingTest(unittest.IsolatedAsyncioTestCase):
    async def asyncTearDown(self):
        ACTIVE_CALLS.clear()

    async def test_caller_who_stopped_mid_thought_is_waited_for(self):
        listener = FakeListener(
            Heard(text="My order number is", language="en", complete=False),
            Heard(text="My order number is 4567", language="en"),
        )
        laravel = FakeLaravel()
        llm = FakeLLM("Order 4567 ships today.")
        call = make_call(FakeSTT(), llm, laravel, listener=listener, call_incomplete_wait_ms=1500)
        await call.start()
        await say(call, speech(400) + silence(700))
        await asyncio.sleep(0.3)                     # a pause, as people do
        await say(call, speech(400) + silence(700))  # ...and they carry on
        await settle(call)
        await wait_for(lambda: laravel.turns)

        self.assertEqual(len(llm.calls), 1)          # nothing said to the half sentence
        self.assertEqual(laravel.turns[0].user_content, "My order number is 4567")

    async def test_slow_lookup_gets_a_fitting_line_quick_lookup_none(self):
        quick = FakeLaravel()
        call, google = google_call(FakeListener(Heard(text="where is my order", language="en")),
                                   FakeLLM("It ships today."), quick)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        self.assertEqual([t for t, _ in google.spoken], ["It ships today."])

        slow = FakeLaravel(resolve_delay=0.5)
        call, google = google_call(FakeListener(Heard(text="where is my order", language="en")),
                                   FakeLLM("It ships today."), slow)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        spoken = [t for t, _ in google.spoken]
        self.assertIn(spoken[0], BUILTIN["en"]["checking"]["order"])
        self.assertEqual(spoken[1], "It ships today.")


class QuietLineTest(unittest.IsolatedAsyncioTestCase):
    FAST = dict(call_idle_prompts=True, call_idle_prompt_seconds=0.3,
                call_idle_wait_seconds=0.5, call_idle_goodbye_seconds=0.3)

    async def asyncTearDown(self):
        ACTIVE_CALLS.clear()

    async def test_after_an_answer_it_asks_anything_else_then_says_goodbye_and_hangs_up(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        listener = FakeListener(Heard(text="Do you deliver to Lahore?", language="en", caller="female"))
        call, google = google_call(listener, FakeLLM("Yes, in two days."), transport=transport, **self.FAST)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1, timeout=5)

        spoken = [t for t, _ in google.spoken]
        self.assertIn(spoken[-2], lines("en", "anything_else", "madam"))
        self.assertIn(spoken[-1], lines("en", "goodbye", "madam"))

    async def test_silence_after_anything_else_is_answered_with_a_goodbye(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        listener = FakeListener(Heard(text="Do you deliver to Lahore?", language="en"))
        call, google = google_call(listener, FakeLLM("Yes, in two days. Anything else I can help with?"),
                                   transport=transport, **self.FAST)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1, timeout=5)
        spoken = [t for t, _ in google.spoken]
        # Not "anything else?" a second time.
        self.assertIn(spoken[-1], lines("en", "goodbye"))
        self.assertEqual(len(spoken), 3)

    async def test_after_a_question_it_gives_them_time_first(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        listener = FakeListener(Heard(text="I want to order biscuits", language="en"))
        call, google = google_call(listener, FakeLLM("Sure, how many cartons?"), transport=transport, **self.FAST)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1, timeout=6)
        spoken = [t for t, _ in google.spoken]
        self.assertIn(spoken[1], lines("en", "take_your_time"))
        self.assertIn(spoken[2], lines("en", "still_there"))
        self.assertIn(spoken[3], lines("en", "goodbye_silent"))

    async def test_silent_line_after_the_greeting(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        call, google = google_call(FakeListener(), FakeLLM(), transport=transport, **self.FAST)
        await call.start()
        call.greet("Hi! Thanks for calling.")
        await wait_for(lambda: transport.hangups == 1, timeout=5)
        spoken = [t for t, _ in google.spoken]
        self.assertIn(spoken[1], lines("en", "still_there"))
        self.assertIn(spoken[2], lines("en", "goodbye_silent"))

    async def test_caller_speaking_resets_the_quiet_clock(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        call, google = google_call(FakeListener(), FakeLLM(), transport=transport, **self.FAST)
        await call.start()
        for _ in range(8):  # a caller who keeps making (short) sounds
            await say(call, speech(100) + silence(100))
            await asyncio.sleep(0.1)
        self.assertEqual(google.spoken, [])

    async def test_a_time_limited_call_says_goodbye_on_time_and_hangs_up(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        call, google = google_call(FakeListener(), FakeLLM(), transport=transport)
        call.max_seconds = 11  # time-up line 10 s before: after ~1 s here
        await call.start()
        call.greet("Hi!")
        await wait_for(lambda: transport.hangups == 1, timeout=5)
        self.assertIn(google.spoken[-1][0], lines("en", "time_up"))
        # Talking now changes nothing: the call is over.
        await say(call, speech(700) + silence(700))
        self.assertEqual(call.stats, [])

    async def test_urdu_check_in_with_madam(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        listener = FakeListener(Heard(text="Lahore mein delivery kitne din mein hoti hai", language="ur",
                                      caller="female"))
        laravel = FakeLaravel()
        laravel.profile_extra = {"stt_language": "auto", "urdu_script": "roman", "language": "roman"}
        call, google = google_call(listener, FakeLLM("Do din mein."), laravel, transport, **self.FAST)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: len(google.spoken) >= 2, timeout=5)
        check_in = google.spoken[1][0]
        self.assertIn(check_in, lines("roman", "anything_else", "madam"))
        await call.close()


class EndingTest(unittest.IsolatedAsyncioTestCase):
    async def asyncTearDown(self):
        ACTIVE_CALLS.clear()

    async def test_caller_who_is_done_gets_a_goodbye_and_the_call_ends(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        laravel = FakeLaravel()
        listener = FakeListener(Heard(text="Nahi bas, shukriya", language="ur"))
        call, google = google_call(listener, FakeLLM("Shukriya, Allah Hafiz! [END_CALL]"), laravel, transport)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1)
        self.assertEqual([t for t, _ in google.spoken], ["Shukriya, Allah Hafiz!"])  # marker never spoken
        await wait_for(lambda: laravel.turns)
        self.assertEqual(laravel.turns[0].content, "Shukriya, Allah Hafiz!")
        self.assertTrue(laravel.turns[0].metadata["ended_call"])

    async def test_caller_saying_goodbye_is_thanked_at_once_and_the_call_ends(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        laravel = FakeLaravel()
        listener = FakeListener(Heard(text="ٹھیک ہے۔ بہت شکریہ۔ اللہ حافظ۔", language="ur", caller="male"))
        llm = FakeLLM("never used")
        call, google = google_call(listener, llm, laravel, transport)
        call.language = "auto"
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1)
        # No lookup, no "let me check", no waiting for the model.
        self.assertEqual(laravel.context_calls, 0)
        self.assertEqual(llm.calls, [])
        self.assertEqual(len(google.spoken), 1)
        self.assertIn(google.spoken[0][0], lines("ur", "goodbye", "سر"))
        await wait_for(lambda: laravel.turns)
        self.assertTrue(laravel.turns[0].metadata["ended_call"])

    async def test_no_thanks_after_anything_else_ends_the_call_at_once(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        listener = FakeListener(Heard(text="Delivery to Lahore?", language="en"),
                                Heard(text="No, that's all, thanks", language="en"))
        llm = FakeLLM("Two days. Anything else I can help with?")
        call, google = google_call(listener, llm, FakeLaravel(), transport)
        await call.start()
        await say(call, speech(400) + silence(700))
        await settle(call)
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1)
        self.assertEqual(len(llm.calls), 1)
        self.assertIn(google.spoken[-1][0], lines("en", "goodbye"))

    async def test_both_sides_saying_goodbye_ends_the_call_without_the_marker(self):
        transport = FakeTransport(seconds_per_chunk=0.05)
        laravel = FakeLaravel()
        # "Ok, thanks" with nothing asked before: the model decides.
        listener = FakeListener(Heard(text="Theek hai, shukriya", language="ur"))
        call, google = google_call(listener, FakeLLM("Aap ka bohat shukriya! Allah Hafiz."), laravel, transport)
        call.language = "auto"
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: transport.hangups == 1)
        self.assertEqual(laravel.context_calls, 0)  # a closing is not a lookup
        self.assertEqual([t for t, _ in google.spoken], ["Aap ka bohat shukriya!", "Allah Hafiz."])

    async def test_talking_over_the_goodbye_keeps_the_call_open(self):
        transport = FakeTransport(seconds_per_chunk=2.0)  # a long goodbye
        listener = FakeListener(Heard(text="bye", language="en"))
        call, _ = google_call(listener, FakeLLM("Goodbye! [END_CALL]"), FakeLaravel(), transport)
        await call.start()
        await say(call, speech(400) + silence(700))
        await wait_for(lambda: call._ending is not None)
        await say(call, speech(500))  # "wait, one more thing!"
        await asyncio.sleep(0.8)
        self.assertEqual(transport.hangups, 0)

    async def test_audiosocket_hangs_up_after_the_last_word(self):
        class Writer:
            def __init__(self):
                self.data = bytearray()

            def write(self, b):
                self.data.extend(b)

            async def drain(self):
                pass

        ended = []
        transport = AudioSocketTransport(Writer(), on_hangup=lambda: ended.append(True))
        await transport.play(b"\x00\x00" * 1600, 8000)   # 0.2 s still to play
        await transport.hang_up()
        self.assertEqual(ended, [True])
        self.assertTrue(transport._drained.is_set())
        transport.close()


class SpeechTextTest(unittest.TestCase):
    def test_marker_and_markdown_are_never_spoken(self):
        self.assertEqual(clean_for_speech("**2 din** mein. Allah Hafiz! [END_CALL]"), "2 din mein. Allah Hafiz!")
        self.assertEqual(clean_for_speech("- First\n- Second"), "First Second")
        self.assertEqual(clean_for_speech("See [our site](https://x.pk) or https://x.pk/a"), "See our site or")
        self.assertEqual(clean_for_speech("Order #4567 (20 cartons) ships today."), "Order 4567, 20 cartons, ships today.")
        self.assertTrue(has_end_call("bye [end_call]"))
        # The words themselves are not the marker.
        self.assertFalse(has_end_call("Do you want to end call recording?"))

    def test_style_travels_as_directors_notes(self):
        self.assertEqual(speech_prompt("Hello."), "Hello.")
        prompt = speech_prompt("Hello.", "gently")
        self.assertTrue(prompt.startswith("### DIRECTOR'S NOTES\nStyle: gently."))
        self.assertTrue(prompt.endswith("### TRANSCRIPT\nHello."))


class LlmWireFormatTest(unittest.TestCase):
    def test_every_system_message_reaches_the_model_as_one(self):
        # Gemini's OpenAI layer kept only the last system message: the
        # persona and call rules vanished whenever reference data followed.
        from app.domain.schemas import ChatMessage
        from app.services.llm_service import _to_openai_messages
        out = _to_openai_messages([
            ChatMessage(role="system", content="You are Sara."),
            ChatMessage(role="system", content="### Reference data\n- Lahore: 2 days"),
            ChatMessage(role="assistant", content="Hi!"),
            ChatMessage(role="user", content="Lahore?"),
        ])
        self.assertEqual([m["role"] for m in out], ["system", "assistant", "user"])
        self.assertEqual(out[0]["content"], "You are Sara.\n\n### Reference data\n- Lahore: 2 days")
        # A system-only request still gives the model something to answer.
        self.assertEqual(_to_openai_messages([ChatMessage(role="system", content="Route this.")])[0]["role"], "user")


class PhrasesTest(unittest.IsolatedAsyncioTestCase):
    def test_closings_and_goodbyes(self):
        from app.calls.phrases import is_closing, offers_more_help, says_goodbye
        for text in ("Nahi bas, shukriya", "نہیں بس بہت شکریہ", "No, that's all, thank you!", "ok bye",
                     "Theek hai, Allah Hafiz"):
            self.assertTrue(is_closing(text), text)
        for text in ("No, my order hasn't arrived", "Theek hai, aur Karachi ke liye?", ""):
            self.assertFalse(is_closing(text), text)
        self.assertTrue(says_goodbye("Shukriya, Allah Hafiz!"))
        self.assertFalse(says_goodbye("Your order arrives tomorrow."))
        self.assertTrue(offers_more_help("Kya kisi aur cheez mein madad kar sakti hoon?"))

    def test_topics(self):
        self.assertEqual(topic_of("Mera refund kab aaye ga?"), "account")
        self.assertEqual(topic_of("میرا آرڈر کہاں ہے"), "order")
        self.assertEqual(topic_of("Lahore mein delivery kitne din mein hoti hai?"), "delivery")
        self.assertEqual(topic_of("biscuit ka rate kya hai"), "price")
        self.assertEqual(topic_of("hello"), "general")

    def test_honorific_fills_or_disappears(self):
        phrases = Phrases()
        line = phrases.say("anything_else", "ur", "male")
        self.assertTrue(line.endswith(("، سر؟", "، سر۔")), line)
        line = phrases.say("anything_else", "en", None)
        self.assertNotIn("{", line)
        self.assertNotIn(", ?", line)

    async def test_other_languages_are_written_once_by_the_model(self):
        _GENERATED.pop("es", None)
        book = {"checking": ["Un momento, lo reviso."], "reassure": ["No se preocupe."],
                "anything_else": ["¿Algo más{,hon}?"], "take_your_time": ["Con calma{,hon}."],
                "still_there": ["¿Sigue ahí{,hon}?"], "goodbye": ["Gracias por llamar{,hon}, adiós."],
                "goodbye_silent": ["No le escucho{,hon}. Adiós."], "hon": {"male": "señor", "female": "señora"}}
        llm = FakeLLM("```json " + json.dumps(book, ensure_ascii=False) + " ```")
        self.assertTrue(await ensure_book("es", llm))
        self.assertTrue(await ensure_book("es", llm))
        self.assertEqual(len(llm.calls), 1)  # kept for every later call
        self.assertEqual(Phrases().say("anything_else", "es", "female"), "¿Algo más, señora?")
        _GENERATED.pop("es", None)

    def test_unusable_model_output_is_not_kept(self):
        self.assertIsNone(_parse_book("Sorry, I can't help with that."))
        self.assertIsNone(_parse_book('{"checking": ["x"]}'))  # no goodbyes


class ListenerTest(unittest.IsolatedAsyncioTestCase):
    async def test_one_request_returns_words_language_mood_and_voice(self):
        seen = []

        def handler(request: httpx.Request) -> httpx.Response:
            body = json.loads(request.content)
            seen.append(body)
            if "thinkingConfig" in body["generationConfig"]:
                return httpx.Response(400, json={"error": {"message": "thinking not supported"}})
            out = {"language": "ur", "text": "میرا آرڈر", "mood": "worried", "caller": "female", "complete": True}
            return httpx.Response(200, json={"candidates": [{"content": {"parts": [{"text": json.dumps(out)}]}}]})

        client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
        listener = GeminiListener("key", "gemini-test", http=client)
        heard = await listener.hear(b"\x00\x00" * 16000, hints="Umer Foods")
        self.assertEqual((heard.text, heard.language, heard.mood, heard.caller), ("میرا آرڈر", "ur", "worried", "female"))
        # Refused thinking once, retried without, and remembers that.
        self.assertEqual(len(seen), 2)
        await listener.hear(b"\x00\x00" * 16000)
        self.assertEqual(len(seen), 3)
        # Phone audio is uploaded at 8 kHz: half the bytes.
        audio = base64.b64decode(seen[-1]["contents"][0]["parts"][0]["inlineData"]["data"])
        with wave.open(io.BytesIO(audio)) as w:
            self.assertEqual(w.getframerate(), 8000)
        self.assertIn("Umer Foods", seen[0]["systemInstruction"]["parts"][0]["text"])
        await listener.aclose()

    async def test_noise_or_background_talk_is_nothing_heard(self):
        def handler(request):
            out = {"clear": False, "language": "ur", "text": "kya meri baat Umer company se ho rahi hai",
                   "mood": "calm", "caller": "male", "complete": True}
            return httpx.Response(200, json={"candidates": [{"content": {"parts": [{"text": json.dumps(out)}]}}]})
        listener = GeminiListener("key", "m", http=httpx.AsyncClient(transport=httpx.MockTransport(handler)))
        heard = await listener.hear(b"\x00\x00" * 1600, hints="Umer company")
        self.assertEqual(heard.text, "")
        await listener.aclose()

    async def test_unknown_values_are_tamed(self):
        def handler(request):
            out = {"language": "en", "text": " hi ", "mood": "ecstatic", "caller": "robot", "complete": False}
            return httpx.Response(200, json={"candidates": [{"content": {"parts": [{"text": json.dumps(out)}]}}]})
        listener = GeminiListener("key", "m", http=httpx.AsyncClient(transport=httpx.MockTransport(handler)))
        heard = await listener.hear(b"\x00\x00" * 1600)
        self.assertEqual((heard.text, heard.mood, heard.caller, heard.complete), ("hi", "calm", "unknown", False))
        await listener.aclose()


class PhraseCacheTest(unittest.TestCase):
    def test_short_phrases_survive_a_restart_on_disk(self):
        requests = []

        def handler(request):
            requests.append(json.loads(request.content))
            pcm = base64.b64encode(b"\x01\x00" * 240).decode()
            return httpx.Response(200, json={"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "audio/L16;rate=24000", "data": pcm}}]}}]})

        with tempfile.TemporaryDirectory() as tmp:
            first = GoogleTTS("k", "m", http=httpx.Client(transport=httpx.MockTransport(handler)), cache_dir=tmp)
            audio = first.synthesize("Allah Hafiz!", "Kore", style="warmly")
            second = GoogleTTS("k", "m", http=httpx.Client(transport=httpx.MockTransport(handler)), cache_dir=tmp)
            self.assertEqual(second.synthesize("Allah Hafiz!", "Kore", style="warmly"), audio)
            self.assertEqual(len(requests), 1)
            # A different delivery is a different recording.
            second.synthesize("Allah Hafiz!", "Kore")
            self.assertEqual(len(requests), 2)
            self.assertIn("DIRECTOR'S NOTES", requests[0]["contents"][0]["parts"][0]["text"])
            first.close()
            second.close()
