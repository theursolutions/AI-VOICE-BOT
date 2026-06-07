"""Parse uploaded documents into ``ExtractedChunk`` records.

Supported extensions: ``.pdf``, ``.docx``, ``.csv``, ``.txt``, ``.json``.

KNOWN LIMITATION: this module reads from a local absolute filesystem
path. For the MVP, Laravel and the voice-engine share the same Windows
host, so Laravel can place uploads in a folder both can read. Once the
two services are split, replace ``parse_file`` with an S3/object-store
download step (TODO).
"""

from __future__ import annotations

import asyncio
import csv
import logging
import os
from dataclasses import dataclass, field
from typing import Any, AsyncIterator, Dict

logger = logging.getLogger(__name__)


@dataclass
class ExtractedChunk:
    text: str
    citation: Dict[str, Any] = field(default_factory=dict)


# Hard cap on per-file size to avoid OOM during MVP. Tune as needed.
_MAX_BYTES = 50 * 1024 * 1024  # 50 MB


def _check_size(path: str) -> None:
    try:
        size = os.path.getsize(path)
    except OSError as exc:
        raise FileNotFoundError(f"cannot stat {path}: {exc}") from exc
    if size > _MAX_BYTES:
        raise ValueError(f"file too large ({size} bytes): {path}")


def _parse_pdf_sync(path: str, original_name: str) -> list[ExtractedChunk]:
    from pypdf import PdfReader  # type: ignore

    out: list[ExtractedChunk] = []
    reader = PdfReader(path)
    for i, page in enumerate(reader.pages, start=1):
        try:
            text = page.extract_text() or ""
        except Exception:  # noqa: BLE001
            logger.exception("pdf page %d extract failed in %s", i, path)
            text = ""
        text = text.strip()
        if not text:
            continue
        out.append(
            ExtractedChunk(
                text=text,
                citation={
                    "file_path": path,
                    "original_name": original_name,
                    "page": i,
                },
            )
        )
    return out


def _parse_docx_sync(path: str, original_name: str) -> list[ExtractedChunk]:
    import docx  # type: ignore  # python-docx

    document = docx.Document(path)
    paragraphs = [p.text.strip() for p in document.paragraphs if p.text and p.text.strip()]
    body = "\n\n".join(paragraphs)
    if not body.strip():
        return []
    return [
        ExtractedChunk(
            text=body,
            citation={"file_path": path, "original_name": original_name},
        )
    ]


def _parse_csv_sync(path: str, original_name: str) -> list[ExtractedChunk]:
    # We use the stdlib csv module to dodge pandas at import time. Each
    # row becomes one chunk-ish blob ("col: val | col: val"); ingest_service
    # then runs the chunker over it to merge tiny rows.
    out: list[ExtractedChunk] = []
    with open(path, "r", encoding="utf-8", errors="replace", newline="") as fh:
        reader = csv.DictReader(fh)
        for i, row in enumerate(reader, start=1):
            parts = [
                f"{(k or '').strip()}: {('' if v is None else str(v)).strip()}"
                for k, v in row.items()
            ]
            text = " | ".join(p for p in parts if p)
            if not text.strip():
                continue
            out.append(
                ExtractedChunk(
                    text=text,
                    citation={
                        "file_path": path,
                        "original_name": original_name,
                        "row": i,
                    },
                )
            )
    return out


def _parse_txt_sync(path: str, original_name: str) -> list[ExtractedChunk]:
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        text = fh.read().strip()
    if not text:
        return []
    return [
        ExtractedChunk(
            text=text,
            citation={"file_path": path, "original_name": original_name},
        )
    ]


def _parse_json_sync(path: str, original_name: str) -> list[ExtractedChunk]:
    """Parse a JSON file into one chunk per top-level record.

    Supports two shapes:
      1. ``[{...}, {...}]`` — array of objects (most common: data export).
      2. ``{...}`` — single object → one chunk with all key/value pairs.

    Each row is flattened to ``key: value | key: value`` text so the
    embedder can index it the same way as a CSV row.
    """
    import json
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        try:
            data = json.load(fh)
        except json.JSONDecodeError as exc:
            raise ValueError(f"invalid JSON: {exc}") from exc

    rows: list[dict] = []
    if isinstance(data, list):
        rows = [r for r in data if isinstance(r, dict)]
    elif isinstance(data, dict):
        # If it's a wrapper like {"items": [...]}, dig one level.
        list_value = next((v for v in data.values() if isinstance(v, list)), None)
        if list_value:
            rows = [r for r in list_value if isinstance(r, dict)]
        else:
            rows = [data]

    out: list[ExtractedChunk] = []
    for i, row in enumerate(rows, start=1):
        parts = []
        for k, v in row.items():
            if v is None or v == "":
                continue
            # Stringify non-scalar values so the chunk stays readable.
            sv = v if isinstance(v, (str, int, float, bool)) else json.dumps(v, ensure_ascii=False)
            parts.append(f"{str(k).strip()}: {str(sv).strip()}")
        text = " | ".join(parts)
        if not text.strip():
            continue
        out.append(
            ExtractedChunk(
                text=text,
                citation={
                    "file_path": path,
                    "original_name": original_name,
                    "row": i,
                },
            )
        )
    return out


_PARSERS = {
    ".pdf":  _parse_pdf_sync,
    ".docx": _parse_docx_sync,
    ".csv":  _parse_csv_sync,
    ".txt":  _parse_txt_sync,
    ".json": _parse_json_sync,
}


async def parse_file(path: str, original_name: str | None = None) -> AsyncIterator[ExtractedChunk]:
    """Yield :class:`ExtractedChunk` records from one local file.

    Raises ``ValueError`` for unsupported extensions, ``FileNotFoundError``
    for missing files. Callers (the ingest service) wrap these in the
    ``IngestReport.errors`` list rather than letting them bubble.
    """

    if not os.path.isabs(path):
        raise ValueError(f"document path must be absolute: {path}")
    if not os.path.exists(path):
        raise FileNotFoundError(f"document not found: {path}")
    _check_size(path)

    name = original_name or os.path.basename(path)
    ext = os.path.splitext(path)[1].lower()
    parser = _PARSERS.get(ext)
    if parser is None:
        raise ValueError(f"unsupported file extension: {ext}")

    chunks = await asyncio.to_thread(parser, path, name)
    for ch in chunks:
        yield ch
