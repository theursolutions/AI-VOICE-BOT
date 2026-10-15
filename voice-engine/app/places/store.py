"""Local cache over Overture Maps places/divisions.

Two expensive things happen remotely, once, and are cached as Parquet files
under ``PLACES_CACHE_DIR/<release>/``:

* an **area** — every place inside a bounding box (one S3 scan with bbox
  predicate pushdown; seconds to ~2 minutes for a city);
* a **country index** of named divisions (cities, districts, regions) with
  their extents, for turning "Dubai" into a bounding box.

Everything after that — category lists, filtering, paging — is a local DuckDB
query over those files and takes milliseconds. Builds run in a small thread
pool; callers poll a status instead of holding a connection open.

The release is pinned into every cache key, so a search started on one
release pages through that release even after a newer one is published.

Values only ever reach SQL as bound parameters. File paths are built from
hashes and validated country codes, never from caller strings.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import threading
import time
from concurrent.futures import Future, ThreadPoolExecutor
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple

import duckdb

logger = logging.getLogger(__name__)

_COUNTRY = re.compile(r"^[A-Z]{2}$")
_AREA_ID = re.compile(r"^[0-9a-z.\-]{1,40}_[0-9a-f]{16}$")
_MAX_AREA_DEG = 2.5          # same ceiling as admin's SearchCriteria
_FAILED_RETRY_AFTER = 600    # seconds before a failed build may be retried
# Bump when the cached area columns change, so old files are never read with
# a query that expects the new shape.
AREA_SCHEMA = 2


@dataclass
class _Build:
    future: Optional[Future] = None
    error: Optional[str] = None
    failed_at: float = 0.0
    started_at: float = field(default_factory=time.time)


class PlacesError(Exception):
    """A request the store can't serve (bad input, unknown area)."""


class PlacesStore:
    def __init__(self, cache_dir: str, base: str = "s3://overturemaps-us-west-2/release",
                 release: Optional[str] = None, s3_region: str = "us-west-2",
                 max_builds: int = 2, remote=None):
        self.cache_dir = cache_dir
        self.base = base.rstrip("/")
        self.s3_region = s3_region
        self._release_override = release
        self._release_cache: Tuple[Optional[str], float] = (None, 0.0)
        self._builds: Dict[str, _Build] = {}
        self._lock = threading.Lock()
        self._pool = ThreadPoolExecutor(max_workers=max_builds, thread_name_prefix="places-build")
        # Injected in tests: callable(sql, params, out_path) that writes the file.
        self._remote = remote
        os.makedirs(cache_dir, exist_ok=True)

    # ── release ─────────────────────────────────────────────────────────
    def release(self) -> str:
        if self._release_override:
            return self._release_override
        cached, at = self._release_cache
        if cached and time.time() - at < 86400:
            return cached
        try:
            import httpx
            latest = httpx.get("https://stac.overturemaps.org/catalog.json", timeout=15).json().get("latest")
            if latest and re.match(r"^\d{4}-\d{2}-\d{2}\.\d+$", latest):
                self._release_cache = (latest, time.time())
                return latest
        except Exception as exc:  # noqa: BLE001
            logger.warning("places.release_lookup_failed: %s", exc)
        if cached:
            return cached
        raise PlacesError("Could not determine the current Overture release; set OVERTURE_RELEASE.")

    # ── connections ─────────────────────────────────────────────────────
    def _remote_con(self) -> duckdb.DuckDBPyConnection:
        con = duckdb.connect()
        con.execute("INSTALL httpfs; LOAD httpfs;")
        con.execute(f"SET s3_region='{self.s3_region}';")
        return con

    @staticmethod
    def _local_con() -> duckdb.DuckDBPyConnection:
        return duckdb.connect()

    def _run_remote(self, sql: str, params: list, out_path: str) -> None:
        if self._remote is not None:
            self._remote(sql, params, out_path)
            return
        tmp = out_path + ".tmp"
        con = self._remote_con()
        try:
            con.execute(f"COPY ({sql}) TO '{tmp}' (FORMAT PARQUET, COMPRESSION ZSTD)", params)
        finally:
            con.close()
        os.replace(tmp, out_path)   # atomic: a half-written file is never "ready"

    # ── build bookkeeping ───────────────────────────────────────────────
    def _status(self, key: str, path: str, start) -> Dict[str, Any]:
        if os.path.exists(path):
            return {"status": "ready"}
        with self._lock:
            b = self._builds.get(key)
            if b and b.future and not b.future.done():
                return {"status": "loading", "since": int(b.started_at)}
            if b and b.future and b.future.done():
                exc = b.future.exception()
                if exc is None and os.path.exists(path):
                    return {"status": "ready"}
                b.error = str(exc) if exc else "build produced no file"
                b.failed_at = b.failed_at or time.time()
                b.future = None
            if b and b.error and time.time() - b.failed_at < _FAILED_RETRY_AFTER:
                return {"status": "failed", "error": b.error}
            self._builds[key] = _Build(future=self._pool.submit(start))
            return {"status": "loading", "since": int(time.time())}

    # ── areas ───────────────────────────────────────────────────────────
    @staticmethod
    def _check_bbox(bbox: List[float]) -> List[float]:
        if len(bbox) != 4:
            raise PlacesError("bbox must be [xmin, ymin, xmax, ymax]")
        x0, y0, x1, y1 = (float(v) for v in bbox)
        if not (-180 <= x0 < x1 <= 180 and -90 <= y0 < y1 <= 90):
            raise PlacesError("bbox out of range")
        if x1 - x0 > _MAX_AREA_DEG or y1 - y0 > _MAX_AREA_DEG:
            raise PlacesError("area too large")
        return [round(x0, 4), round(y0, 4), round(x1, 4), round(y1, 4)]

    def area_id(self, bbox: List[float]) -> str:
        box = self._check_bbox(bbox)
        release = self.release()
        digest = hashlib.sha1(json.dumps([AREA_SCHEMA, release, box]).encode()).hexdigest()[:16]
        return f"{release}_{digest}"

    def _area_path(self, area_id: str) -> str:
        if not _AREA_ID.match(area_id):
            raise PlacesError("invalid area id")
        release = area_id.rsplit("_", 1)[0]
        d = os.path.join(self.cache_dir, release, "areas")
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, area_id.rsplit("_", 1)[1] + ".parquet")

    def ensure_area(self, bbox: List[float]) -> Dict[str, Any]:
        box = self._check_bbox(bbox)
        area_id = self.area_id(box)
        path = self._area_path(area_id)
        release = area_id.rsplit("_", 1)[0]
        src = f"{self.base}/{release}/theme=places/type=place/*"

        sql = f"""
            SELECT id,
                   names."primary"                         AS name,
                   names.common['en']                      AS name_en,
                   taxonomy."primary"                      AS category,
                   taxonomy.hierarchy                      AS hierarchy,
                   basic_category,
                   brand.names."primary"                   AS brand,
                   websites, phones, emails, socials, addresses,
                   confidence, operating_status,
                   list_transform(sources, s -> {{'dataset': s.dataset, 'license': s.license,
                                                   'record_id': s.record_id, 'update_time': s.update_time}}) AS sources,
                   bbox.xmin AS lng, bbox.ymin AS lat
            FROM read_parquet('{src}', hive_partitioning=1)
            WHERE bbox.xmin BETWEEN $1 AND $3 AND bbox.ymin BETWEEN $2 AND $4
        """
        status = self._status(area_id, path, lambda: self._run_remote(sql, box, path))
        status["area_id"] = area_id
        status["release"] = release
        if status["status"] == "ready":
            status["total"] = self._scalar(f"SELECT count(*) FROM read_parquet('{path}')")
        return status

    def area_status(self, area_id: str) -> Dict[str, Any]:
        path = self._area_path(area_id)
        if os.path.exists(path):
            return {"status": "ready", "area_id": area_id, "total": self._scalar(f"SELECT count(*) FROM read_parquet('{path}')")}
        with self._lock:
            b = self._builds.get(area_id)
        if b and b.future and not b.future.done():
            return {"status": "loading", "area_id": area_id}
        raise PlacesError("area not loaded")

    def categories(self, area_id: str, limit: int = 300) -> List[Dict[str, Any]]:
        path = self._ready_area(area_id)
        con = self._local_con()
        try:
            rows = con.execute(
                f"""SELECT category, basic_category, count(*) AS n
                    FROM read_parquet('{path}') WHERE category IS NOT NULL
                    GROUP BY ALL ORDER BY n DESC LIMIT $1""",
                [max(1, min(int(limit), 1000))],
            ).fetchall()
        finally:
            con.close()
        return [{"key": r[0], "group": r[1], "count": r[2]} for r in rows]

    def search(self, area_id: str, filters: Dict[str, Any], offset: int, limit: int) -> Dict[str, Any]:
        path = self._ready_area(area_id)
        where, params = self._where(filters)
        limit = max(1, min(int(limit), 500))
        offset = max(0, int(offset))
        con = self._local_con()
        try:
            total = con.execute(f"SELECT count(*) FROM read_parquet('{path}') WHERE {where}", params).fetchone()[0]
            cur = con.execute(
                f"""SELECT * FROM read_parquet('{path}') WHERE {where}
                    ORDER BY confidence DESC NULLS LAST, id
                    LIMIT {limit} OFFSET {offset}""",
                params,
            )
            cols = [d[0] for d in cur.description]
            items = [dict(zip(cols, row)) for row in cur.fetchall()]
        finally:
            con.close()
        nxt = offset + len(items)
        return {"total": total, "items": items, "next_offset": nxt if nxt < total else None}

    @staticmethod
    def _where(f: Dict[str, Any]) -> Tuple[str, list]:
        clauses, params = ["TRUE"], []

        def p(v):
            params.append(v)
            return f"${len(params)}"

        cats = [str(c) for c in (f.get("categories") or []) if c][:50]
        if cats:
            ph = p(cats)
            clauses.append(f"(category IN (SELECT unnest({ph})) OR list_has_any(hierarchy, {ph}::VARCHAR[]))")
        for word in str(f.get("keywords") or "").split()[:8]:
            like = p(f"%{word}%")
            clauses.append(f"(name ILIKE {like} OR name_en ILIKE {like} OR category ILIKE {like} "
                           f"OR basic_category ILIKE {like} OR brand ILIKE {like})")
        if f.get("require_website"):
            clauses.append("len(websites) > 0")
        if f.get("require_phone"):
            clauses.append("len(phones) > 0")
        if f.get("require_email"):
            clauses.append("len(emails) > 0")
        if f.get("require_social"):
            clauses.append("len(socials) > 0")
        if f.get("min_confidence"):
            clauses.append(f"confidence >= {p(float(f['min_confidence']))}")
        if f.get("exclude_closed", True):
            clauses.append("coalesce(operating_status, 'open') = 'open'")
        center, radius = f.get("center"), f.get("radius_km")
        if center and radius:
            lng, lat, r = p(float(center[0])), p(float(center[1])), p(float(radius))
            clauses.append(
                f"2 * 6371 * asin(sqrt(pow(sin(radians(lat - {lat}) / 2), 2) + "
                f"cos(radians({lat})) * cos(radians(lat)) * pow(sin(radians(lng - {lng}) / 2), 2))) <= {r}"
            )
        return " AND ".join(clauses), params

    def _ready_area(self, area_id: str) -> str:
        path = self._area_path(area_id)
        if not os.path.exists(path):
            raise PlacesError("area not loaded")
        return path

    # ── geocoding ───────────────────────────────────────────────────────
    def _country_path(self, release: str, country: str) -> str:
        d = os.path.join(self.cache_dir, release, "divisions")
        os.makedirs(d, exist_ok=True)
        return os.path.join(d, f"{country}.parquet")

    def ensure_country(self, country: str) -> Dict[str, Any]:
        country = (country or "").upper()
        if not _COUNTRY.match(country):
            raise PlacesError("country must be an ISO 3166-1 alpha-2 code")
        release = self.release()
        path = self._country_path(release, country)
        div = f"{self.base}/{release}/theme=divisions"
        # A division is a point; its extent comes from its division_area rows
        # (land and maritime parts — land only, so a coastal city's box isn't
        # stretched out to sea).
        sql = f"""
            WITH d AS (
                SELECT id, names."primary" AS name, names.common['en'] AS name_en,
                       subtype, region, population
                FROM read_parquet('{div}/type=division/*', hive_partitioning=1)
                WHERE country = $1
                  AND subtype IN ('locality','localadmin','borough','county','macrocounty','region','neighborhood','macrohood')
            ), a AS (
                SELECT division_id, min(bbox.xmin) x0, min(bbox.ymin) y0, max(bbox.xmax) x1, max(bbox.ymax) y1
                FROM read_parquet('{div}/type=division_area/*', hive_partitioning=1)
                WHERE country = $1 AND coalesce(is_land, TRUE)
                GROUP BY division_id
            )
            SELECT d.*, a.x0, a.y0, a.x1, a.y1 FROM d JOIN a ON a.division_id = d.id
        """
        status = self._status(f"country:{release}:{country}", path, lambda: self._run_remote(sql, [country], path))
        status.update({"country": country, "release": release})
        return status

    def geocode(self, country: str, q: str, limit: int = 10) -> Dict[str, Any]:
        status = self.ensure_country(country)
        if status["status"] != "ready":
            return status
        path = self._country_path(status["release"], status["country"])
        q = (q or "").strip()
        if len(q) < 2:
            return {**status, "matches": []}
        con = self._local_con()
        try:
            rows = con.execute(
                f"""SELECT name, name_en, subtype, region, population, x0, y0, x1, y1
                    FROM read_parquet('{path}')
                    WHERE name ILIKE $1 OR name_en ILIKE $1
                    -- Exact name, then prefix, then areas small enough to search,
                    -- biggest first: "Dubai" should offer the emirate before a
                    -- one-block district that merely starts with the word.
                    ORDER BY coalesce(lower(name_en) = lower($2) OR lower(name) = lower($2), FALSE) DESC,
                             coalesce(name_en ILIKE $2 || '%' OR name ILIKE $2 || '%', FALSE) DESC,
                             ((x1 - x0) > $4 OR (y1 - y0) > $4) ASC,
                             (x1 - x0) * (y1 - y0) DESC
                    LIMIT $3""",
                [f"%{q}%", q, max(1, min(int(limit), 25)), _MAX_AREA_DEG],
            ).fetchall()
        finally:
            con.close()
        matches = []
        for name, name_en, subtype, region, pop, x0, y0, x1, y1 in rows:
            box = [x0, y0, x1, y1]
            matches.append({
                "name": name_en or name,
                "local_name": name if name_en and name_en != name else None,
                "subtype": subtype,
                "region": region,
                "population": pop,
                "bbox": box,
                "too_large": (x1 - x0) > _MAX_AREA_DEG or (y1 - y0) > _MAX_AREA_DEG,
            })
        return {**status, "matches": matches}

    # ── helpers ─────────────────────────────────────────────────────────
    def _scalar(self, sql: str, params: Optional[list] = None):
        con = self._local_con()
        try:
            return con.execute(sql, params or []).fetchone()[0]
        finally:
            con.close()
