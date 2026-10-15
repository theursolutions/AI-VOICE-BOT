"""/llm/structured: schema-driven JSON for admin tasks (Lead Generation).

    cd voice-engine && .venv/Scripts/python -m unittest tests.test_structured -v
"""

import asyncio
import unittest
from types import SimpleNamespace

from fastapi import HTTPException

from app.api.routes.structured import StructuredRequest, llm_structured
from app.services.llm_service import ExtractResult
from app.services.schema_check import validate

QUALIFY = {
    "type": "object",
    "required": ["score", "band", "explanation"],
    "properties": {
        "score": {"type": "integer", "minimum": 0, "maximum": 100},
        "band": {"type": "string", "enum": ["high", "medium", "low", "needs_review"]},
        "explanation": {"type": "string", "maxLength": 600},
        "hypotheses": {"type": "array", "maxItems": 3, "items": {"type": "string"}},
    },
}


class _FakeLLM:
    def __init__(self, data):
        self.data = data
        self.calls = []

    async def extract(self, prompt, schema, **kw):
        self.calls.append(kw)
        return ExtractResult(data=self.data, tokens_in=11, tokens_out=7, model="fake-1")


def _call(data, **overrides):
    llm = _FakeLLM(data)
    request = SimpleNamespace(app=SimpleNamespace(state=SimpleNamespace(llm_service=llm)))
    body = {"prompt": "score this", "json_schema": QUALIFY, **overrides}
    return asyncio.run(llm_structured(StructuredRequest(**body), request)), llm


class SchemaCheckTest(unittest.TestCase):
    def test_conforming_object_is_valid(self):
        self.assertEqual(validate({"score": 80, "band": "high", "explanation": "ok"}, QUALIFY), [])

    def test_out_of_range_and_wrong_enum_are_reported(self):
        errors = validate({"score": 140, "band": "great", "explanation": "x"}, QUALIFY)
        self.assertTrue(any("maximum" in e for e in errors))
        self.assertTrue(any("band" in e for e in errors))

    def test_missing_required_and_bool_is_not_integer(self):
        errors = validate({"score": True, "band": "low"}, QUALIFY)
        self.assertTrue(any("explanation: required" in e for e in errors))
        self.assertTrue(any("$.score" in e for e in errors))

    def test_array_items_and_length(self):
        errors = validate({"score": 1, "band": "low", "explanation": "x", "hypotheses": ["a", 2, "c", "d"]}, QUALIFY)
        self.assertTrue(any("more than 3" in e for e in errors))
        self.assertTrue(any("hypotheses[1]" in e for e in errors))


class StructuredRouteTest(unittest.TestCase):
    def test_valid_output_with_usage_and_brain_credentials_passed_through(self):
        res, llm = _call({"score": 72, "band": "medium", "explanation": "Has a website."},
                         provider="openai_compat", api_key="k", base_url="https://x", model="m")
        self.assertTrue(res.valid)
        self.assertEqual((res.tokens_in, res.tokens_out, res.model), (11, 7, "fake-1"))
        self.assertEqual(llm.calls[0], {"provider": "openai_compat", "model": "m", "api_key": "k", "base_url": "https://x"})

    def test_nonconforming_output_is_returned_but_flagged(self):
        res, _ = _call({"score": "high"})
        self.assertFalse(res.valid)
        self.assertTrue(res.errors)

    def test_empty_output_is_invalid(self):
        res, _ = _call({})
        self.assertFalse(res.valid)

    def test_schema_must_be_an_object(self):
        with self.assertRaises(HTTPException):
            _call({}, json_schema={"type": "array"})


if __name__ == "__main__":
    unittest.main()
