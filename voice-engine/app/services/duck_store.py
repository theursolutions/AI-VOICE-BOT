"""Unified local data store backed by DuckDB — one file per project.

Replaces BOTH the per-snapshot MySQL tables AND the Qdrant vector store +
embedding model. Everything a project's data sources need now lives in a
single columnar, compressed file: ``<DUCKDB_DIR>/project_<id>.duckdb``.

  * structured snapshots (products, buses, …) -> table ``snap_<source_id>``
        queried with SQL (exact lookups, filters, sorts, counts, top-N).
  * KB documents + crawled website text        -> table ``docs_<source_id>``
        queried with DuckDB's FTS extension (BM25 keyword relevance).

Why DuckDB: columnar + compressed (tiny on disk vs MySQL rows or vectors),
analytical SQL is its strength, FTS replaces embeddings so there's no
embedding model or vector service to run. One file per project keeps tenants
isolated and makes lifecycle trivial (DROP TABLE per source, delete file per
project).

Connection model: DuckDB allows only one read-write *instance* per file per
process. We therefore cache ONE read-write connection per project and
serialise access with a per-project lock (queries are sub-millisecond, so
serialising is cheap). Different projects use different files → no contention.
Assumes a single engine process (uvicorn without multiple workers).
"""

from __future__ import annotations

import json
import logging
import os
import re
import threading
from typing import Any, Dict, List

logger = logging.getLogger(__name__)

_IDENT_RE = re.compile(r"[^0-9a-zA-Z_]+")


def _ident(name: str, fallback: str) -> str:
    s = _IDENT_RE.sub("_", str(name).strip().lower()).strip("_")
    s = re.sub(r"_+", "_", s)
    if not s:
        s = fallback
    if s[0].isdigit():
        s = "c_" + s
    return s[:60]


class DuckStore:
    def __init__(self, base_dir: str) -> None:
        self.base_dir = base_dir
        os.makedirs(base_dir, exist_ok=True)
        self._conns: Dict[int, Any] = {}
        self._locks: Dict[int, threading.Lock] = {}
        self._fts_ready: set = set()
        self._master = threading.Lock()

    def _path(self, project_id: int) -> str:
        return os.path.join(self.base_dir, f"project_{int(project_id)}.duckdb")

    def _lock(self, project_id: int) -> threading.Lock:
        with self._master:
            lock = self._locks.get(project_id)
            if lock is None:
                lock = threading.Lock()
                self._locks[project_id] = lock
            return lock

    def _conn(self, project_id: int):
        """Cached read-write connection (creates the file if missing).
        Caller MUST hold ``_lock(project_id)``."""
        con = self._conns.get(project_id)
        if con is None:
            import duckdb

            con = duckdb.connect(self._path(project_id))
            self._conns[project_id] = con
        return con

    def _ensure_fts(self, project_id: int, con) -> None:
        if project_id in self._fts_ready:
            return
        con.execute("INSTALL fts; LOAD fts;")
        self._fts_ready.add(project_id)

    # ---- structured snapshots -------------------------------------------
    def load_table(self, project_id: int, source_id: int, files: List[Dict[str, str]]) -> Dict[str, Any]:
        import pandas as pd

        frames = []
        for f in files:
            p = str((f or {}).get("path", "")).strip()
            if not (p and os.path.exists(p)):
                continue
            ext = os.path.splitext(p)[1].lower()
            if ext in (".xlsx", ".xls"):
                frames.append(pd.read_excel(p))
            elif ext == ".json":
                try:
                    frames.append(pd.read_json(p))
                except ValueError:
                    frames.append(pd.read_json(p, lines=True))
            else:
                frames.append(pd.read_csv(p))
        if not frames:
            raise FileNotFoundError("no readable snapshot files on disk")

        df = frames[0] if len(frames) == 1 else pd.concat(frames, ignore_index=True, sort=False)
        if df.empty or len(df.columns) == 0:
            raise ValueError("snapshot file has no rows/columns")

        cols: List[str] = []
        col_map: List[Dict[str, str]] = []
        seen: Dict[str, bool] = {}
        for i, c in enumerate(df.columns):
            s = _ident(c, f"col_{i + 1}")
            base, n = s, 1
            while s in seen:
                n += 1
                s = f"{base}_{n}"
            seen[s] = True
            cols.append(s)
            col_map.append({"name": s, "source_name": str(c)})
        df.columns = cols

        table = _ident(f"snap_{source_id}", "snap")
        with self._lock(project_id):
            con = self._conn(project_id)
            con.execute(f'DROP TABLE IF EXISTS "{table}"')
            con.register("df_in", df)
            con.execute(f'CREATE TABLE "{table}" AS SELECT * FROM df_in')
            con.unregister("df_in")
            count = con.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            info = con.execute(f'PRAGMA table_info("{table}")').fetchall()
            # Enrich each column with a few distinct sample values. This is
            # what lets the text-to-SQL model pick the RIGHT column (an "id"
            # query like PRD-1002 → product_id, not product_name) and the
            # RIGHT literal (stock_status = 'Out of Stock', not 'out of stock').
            schema = []
            for r in info:
                cname, ctype = r[1], r[2]
                line = f"{cname} {ctype}"
                try:
                    vals = con.execute(
                        f'SELECT DISTINCT "{cname}" FROM "{table}" '
                        f'WHERE "{cname}" IS NOT NULL LIMIT 3'
                    ).fetchall()
                    examples = [str(v[0])[:40] for v in vals if v[0] is not None]
                    if examples:
                        line += " — e.g. " + ", ".join(examples)
                except Exception:  # noqa: BLE001
                    pass
                schema.append(line)

        logger.info("duck load_table project=%s table=%s rows=%s", project_id, table, count)
        return {"table": table, "row_count": int(count), "columns": col_map, "schema": schema}

    def query(self, project_id: int, sql: str) -> List[Dict[str, Any]]:
        with self._lock(project_id):
            con = self._conn(project_id)
            cur = con.execute(sql)
            names = [d[0] for d in cur.description]
            rows = [dict(zip(names, r)) for r in cur.fetchall()]
        return rows

    # ---- KB / crawler text (BM25 full-text) -----------------------------
    def load_docs(self, project_id: int, source_id: int, chunks: List[Dict[str, Any]]) -> Dict[str, Any]:
        table = _ident(f"docs_{source_id}", "docs")
        with self._lock(project_id):
            con = self._conn(project_id)
            self._ensure_fts(project_id, con)
            con.execute(f'DROP TABLE IF EXISTS "{table}"')
            con.execute(f'CREATE TABLE "{table}" (id BIGINT, text VARCHAR, citation VARCHAR)')
            rows = [
                [i, str(c.get("text", "") or ""), json.dumps(c.get("citation") or {})]
                for i, c in enumerate(chunks)
                if str(c.get("text", "") or "").strip()
            ]
            if rows:
                con.executemany(f'INSERT INTO "{table}" VALUES (?, ?, ?)', rows)
                con.execute(f"PRAGMA create_fts_index('{table}', 'id', 'text', overwrite=1)")
            count = con.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
        logger.info("duck load_docs project=%s table=%s chunks=%s", project_id, table, count)
        return {"table": table, "row_count": int(count)}

    def search_docs(self, project_id: int, source_ids: List[int], query: str, k: int = 5) -> List[Dict[str, Any]]:
        if not os.path.exists(self._path(project_id)):
            return []
        out: List[Dict[str, Any]] = []
        with self._lock(project_id):
            con = self._conn(project_id)
            self._ensure_fts(project_id, con)
            for sid in source_ids:
                table = _ident(f"docs_{sid}", "docs")
                exists = con.execute(
                    "SELECT 1 FROM information_schema.tables WHERE table_name = ?", [table]
                ).fetchone()
                if not exists:
                    continue
                sql = (
                    f'SELECT text, citation, fts_main_{table}.match_bm25(id, ?) AS score '
                    f'FROM "{table}" WHERE score IS NOT NULL ORDER BY score DESC LIMIT {int(k)}'
                )
                for r in con.execute(sql, [query]).fetchall():
                    out.append({
                        "text": r[0],
                        "citation": json.loads(r[1] or "{}"),
                        "score": float(r[2] or 0.0),
                    })
        out.sort(key=lambda x: x["score"], reverse=True)
        return out[:k]

    # ---- lifecycle ------------------------------------------------------
    def drop_source(self, project_id: int, source_id: int) -> None:
        if not os.path.exists(self._path(project_id)):
            return
        with self._lock(project_id):
            con = self._conn(project_id)
            for prefix in ("snap", "docs"):
                table = _ident(f"{prefix}_{source_id}", prefix)
                con.execute(f'DROP TABLE IF EXISTS "{table}"')
