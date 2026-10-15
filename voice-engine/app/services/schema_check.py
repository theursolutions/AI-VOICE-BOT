"""Minimal JSON-Schema checker for LLM structured output.

Covers the keywords the admin's structured prompts use — ``type``,
``properties``, ``required``, ``enum``, ``items``, ``minimum``/``maximum``,
``maxLength``, ``maxItems`` — and nothing else. Not a general validator; it
exists so a model that ignores the schema (JSON mode on most OpenAI-compatible
providers is advisory) is reported as invalid instead of being trusted.
"""

from __future__ import annotations

from typing import Any, Dict, List

_TYPES = {
    "object": dict,
    "array": list,
    "string": str,
    "boolean": bool,
    "null": type(None),
}


def _is_type(value: Any, expected: str) -> bool:
    if expected == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if expected == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    py = _TYPES.get(expected)
    return py is not None and isinstance(value, py)


def validate(value: Any, schema: Dict[str, Any], path: str = "$") -> List[str]:
    """Return a list of human-readable problems; empty means valid."""
    errors: List[str] = []

    expected = schema.get("type")
    if expected is not None:
        options = expected if isinstance(expected, list) else [expected]
        if not any(_is_type(value, t) for t in options):
            return [f"{path}: expected {'/'.join(options)}"]

    if "enum" in schema and value not in schema["enum"]:
        errors.append(f"{path}: not one of {schema['enum']}")

    if isinstance(value, (int, float)) and not isinstance(value, bool):
        if "minimum" in schema and value < schema["minimum"]:
            errors.append(f"{path}: below minimum {schema['minimum']}")
        if "maximum" in schema and value > schema["maximum"]:
            errors.append(f"{path}: above maximum {schema['maximum']}")

    if isinstance(value, str) and "maxLength" in schema and len(value) > schema["maxLength"]:
        errors.append(f"{path}: longer than {schema['maxLength']}")

    if isinstance(value, dict):
        for key in schema.get("required", []):
            if key not in value:
                errors.append(f"{path}.{key}: required")
        for key, sub in (schema.get("properties") or {}).items():
            if key in value:
                errors.extend(validate(value[key], sub, f"{path}.{key}"))

    if isinstance(value, list):
        if "maxItems" in schema and len(value) > schema["maxItems"]:
            errors.append(f"{path}: more than {schema['maxItems']} items")
        if isinstance(schema.get("items"), dict):
            for i, item in enumerate(value):
                errors.extend(validate(item, schema["items"], f"{path}[{i}]"))

    return errors
