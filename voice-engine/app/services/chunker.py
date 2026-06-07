"""Paragraph-aware text chunker.

Token counts are approximated as ``len(text) / 4`` — good enough for
chunking decisions without pulling in tiktoken. We split on double
newlines first, accumulate paragraphs until the next one would overflow
``max_tokens``, and add a small token-level overlap between adjacent
chunks so retrieval recall doesn't suffer at boundaries.

If a single paragraph is itself larger than ``max_tokens`` we fall back
to sentence-level splitting; if a single sentence is still too big we
hard-slice on character count. The function is pure (no I/O) so it is
trivial to unit test.
"""

from __future__ import annotations

import re
from typing import List

_SENT_END = re.compile(r"(?<=[\.\!\?])\s+")


def _approx_tokens(text: str) -> int:
    return max(1, len(text) // 4)


def _split_sentences(paragraph: str) -> List[str]:
    parts = [p.strip() for p in _SENT_END.split(paragraph) if p and p.strip()]
    return parts or [paragraph]


def _hard_slice(text: str, max_chars: int) -> List[str]:
    return [text[i : i + max_chars] for i in range(0, len(text), max_chars)]


def chunk_text(
    text: str,
    max_tokens: int = 500,
    overlap: int = 50,
) -> List[str]:
    """Split ``text`` into approximately ``max_tokens``-sized chunks."""

    if not text or not text.strip():
        return []
    if max_tokens <= 0:
        return [text.strip()]

    max_chars = max_tokens * 4
    overlap_chars = max(0, overlap) * 4

    paragraphs = [p.strip() for p in re.split(r"\n\s*\n", text) if p and p.strip()]
    chunks: List[str] = []
    buf = ""

    def flush():
        nonlocal buf
        if buf.strip():
            chunks.append(buf.strip())
        buf = ""

    for para in paragraphs:
        # Paragraph alone overflows — split sentence-wise.
        if _approx_tokens(para) > max_tokens:
            flush()
            sentence_buf = ""
            for sent in _split_sentences(para):
                if _approx_tokens(sent) > max_tokens:
                    # Sentence itself too long — hard slice.
                    if sentence_buf.strip():
                        chunks.append(sentence_buf.strip())
                        sentence_buf = ""
                    chunks.extend(_hard_slice(sent, max_chars))
                    continue
                if _approx_tokens(sentence_buf + " " + sent) > max_tokens:
                    if sentence_buf.strip():
                        chunks.append(sentence_buf.strip())
                    sentence_buf = sent
                else:
                    sentence_buf = (sentence_buf + " " + sent).strip()
            if sentence_buf.strip():
                chunks.append(sentence_buf.strip())
            continue

        candidate = (buf + "\n\n" + para).strip() if buf else para
        if _approx_tokens(candidate) > max_tokens:
            flush()
            buf = para
        else:
            buf = candidate

    flush()

    # Apply a character-level overlap so retrieval doesn't miss matches
    # that straddle a chunk boundary. We prefix each chunk (except the
    # first) with the tail of the previous one.
    if overlap_chars > 0 and len(chunks) > 1:
        overlapped: List[str] = [chunks[0]]
        for i in range(1, len(chunks)):
            tail = chunks[i - 1][-overlap_chars:]
            overlapped.append((tail + " " + chunks[i]).strip())
        chunks = overlapped

    return chunks
