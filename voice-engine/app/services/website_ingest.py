"""Same-domain website crawler + Trafilatura main-content extractor.

No JS rendering — pages that ship their copy in client-side JS will come
back empty. That is acceptable for Tier 1 ingestion; customers with SPAs
should provide a sitemap or a pre-rendered snapshot. (TODO: add an
optional Playwright fallback once we have a real customer who needs it.)
"""

from __future__ import annotations

import asyncio
import logging
from dataclasses import dataclass
from typing import AsyncIterator, Optional, Set
from urllib.parse import urldefrag, urljoin, urlparse

import httpx

logger = logging.getLogger(__name__)


@dataclass
class ExtractedPage:
    url: str
    title: str
    text: str


def _same_origin(seed: str, candidate: str) -> bool:
    a = urlparse(seed)
    b = urlparse(candidate)
    if b.scheme not in ("http", "https"):
        return False
    return (a.netloc or "").lower() == (b.netloc or "").lower()


def _normalise(url: str) -> str:
    url, _ = urldefrag(url)
    return url.rstrip("/")


def _extract_links(html: str, base_url: str) -> Set[str]:
    # Lightweight href extraction — we intentionally avoid a full HTML
    # parser dep. trafilatura already pulled in lxml so we *could* use
    # it, but the regex keeps this module self-contained.
    import re

    out: Set[str] = set()
    for m in re.finditer(r'href=["\']([^"\']+)["\']', html, flags=re.IGNORECASE):
        href = m.group(1).strip()
        if not href or href.startswith(("mailto:", "tel:", "javascript:")):
            continue
        try:
            absolute = urljoin(base_url, href)
        except ValueError:
            continue
        out.add(_normalise(absolute))
    return out


async def _fetch(client: httpx.AsyncClient, url: str) -> Optional[str]:
    try:
        resp = await client.get(url, follow_redirects=True, timeout=20.0)
        if resp.status_code >= 400:
            return None
        ctype = resp.headers.get("content-type", "")
        if "html" not in ctype.lower():
            return None
        return resp.text
    except Exception:  # noqa: BLE001
        logger.debug("fetch failed for %s", url, exc_info=True)
        return None


def _extract(html: str, url: str) -> Optional[ExtractedPage]:
    try:
        import trafilatura  # type: ignore

        extracted = trafilatura.extract(
            html,
            url=url,
            include_comments=False,
            include_tables=True,
            favor_recall=True,
        )
        if not extracted or not extracted.strip():
            return None
        # Best-effort title: trafilatura's metadata helper.
        title = url
        try:
            meta = trafilatura.extract_metadata(html, default_url=url)
            if meta and getattr(meta, "title", None):
                title = meta.title  # type: ignore[attr-defined]
        except Exception:  # noqa: BLE001
            pass
        return ExtractedPage(url=url, title=title, text=extracted.strip())
    except Exception:  # noqa: BLE001
        logger.exception("trafilatura extraction failed for %s", url)
        return None


async def crawl_and_extract(
    start_url: str,
    max_depth: int = 2,
    max_pages: int = 50,
) -> AsyncIterator[ExtractedPage]:
    """BFS crawl of ``start_url`` within the same origin.

    Yields :class:`ExtractedPage` objects one at a time so the caller can
    stream embeddings without buffering the entire site.
    """

    start = _normalise(start_url)
    seen: Set[str] = {start}
    queue: list[tuple[str, int]] = [(start, 0)]
    visited_pages = 0

    async with httpx.AsyncClient(
        headers={"User-Agent": "VoiceCRM-Ingest/1.0 (+https://example.com)"}
    ) as client:
        while queue and visited_pages < max_pages:
            url, depth = queue.pop(0)
            html = await _fetch(client, url)
            if html is None:
                continue
            page = _extract(html, url)
            visited_pages += 1
            if page is not None:
                yield page

            if depth < max_depth:
                for link in _extract_links(html, url):
                    if link in seen:
                        continue
                    if not _same_origin(start_url, link):
                        continue
                    seen.add(link)
                    queue.append((link, depth + 1))

            # Be nice to the origin.
            await asyncio.sleep(0.1)
