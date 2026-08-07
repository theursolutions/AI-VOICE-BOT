"""Turn a tabular upload (CSV / XLSX / JSON) into a real MySQL table.

A "data snapshot" is structured data (a product catalog, a tour list, a
bus timetable …). Semantic RAG is the wrong engine for it — users ask
exact-lookup / filter / sort / aggregate questions ("details of PRD-1002",
"10 highest-priced products", "how many in stock") that similarity search
can't answer. So instead of (only) embedding the rows, we load them into a
MySQL table and let the existing text-to-SQL resolver query it.

This generalises to ANY upload: we infer column names + SQL types from the
file's own headers, so products / tours / buses all get their own
auto-built table with zero manual modelling.

Called by the ``POST /snapshot/load`` control-plane endpoint. Laravel
passes the file path(s), a MySQL connection, and a target table name; we
(re)create the table and bulk-insert, then return the column list + row
count so Laravel can introspect + store the schema.
"""

from __future__ import annotations

import logging
import os
import re
from typing import Any, Dict, List

logger = logging.getLogger(__name__)

_IDENT_RE = re.compile(r"[^0-9a-zA-Z_]+")


def _sanitize_ident(name: str, fallback: str) -> str:
    """MySQL-safe identifier from an arbitrary header / table name."""
    s = _IDENT_RE.sub("_", str(name).strip().lower()).strip("_")
    s = re.sub(r"_+", "_", s)
    if not s:
        s = fallback
    if s[0].isdigit():
        s = "c_" + s
    return s[:60]


def _read_frame(path: str):
    import pandas as pd

    ext = os.path.splitext(path)[1].lower()
    if ext in (".xlsx", ".xls"):
        return pd.read_excel(path)
    if ext == ".json":
        try:
            return pd.read_json(path)
        except ValueError:
            return pd.read_json(path, lines=True)
    # default: CSV (also covers .txt exported as delimited)
    return pd.read_csv(path)


def _sql_type(series) -> str:
    import pandas.api.types as pdt

    dtype = series.dtype
    if pdt.is_bool_dtype(dtype):
        return "TINYINT(1)"
    if pdt.is_integer_dtype(dtype):
        return "BIGINT"
    if pdt.is_float_dtype(dtype):
        return "DOUBLE"
    if pdt.is_datetime64_any_dtype(dtype):
        return "DATETIME"
    # object / string → size by max observed length
    try:
        maxlen = int(series.astype(str).str.len().max() or 0)
    except Exception:  # noqa: BLE001
        maxlen = 255
    if maxlen > 1000:
        return "TEXT"
    return f"VARCHAR({min(max(maxlen + 20, 32), 1024)})"


def load_tabular(files: List[Dict[str, str]], mysql: Dict[str, Any], table: str) -> Dict[str, Any]:
    import numpy as np
    import pandas as pd
    import pymysql

    frames = []
    for f in files:
        p = str((f or {}).get("path", "")).strip()
        if p and os.path.exists(p):
            frames.append(_read_frame(p))
    if not frames:
        raise FileNotFoundError("no readable snapshot files on disk")

    df = frames[0] if len(frames) == 1 else pd.concat(frames, ignore_index=True, sort=False)
    if df.empty or len(df.columns) == 0:
        raise ValueError("snapshot file has no rows/columns")

    # Sanitize + de-duplicate column names, keep a map back to originals.
    orig_cols = list(df.columns)
    new_cols: List[str] = []
    col_map: List[Dict[str, str]] = []
    seen: Dict[str, bool] = {}
    for i, c in enumerate(orig_cols):
        s = _sanitize_ident(c, f"col_{i + 1}")
        base, n = s, 1
        while s in seen:
            n += 1
            s = f"{base}_{n}"
        seen[s] = True
        new_cols.append(s)
        col_map.append({"name": s, "source_name": str(c)})
    df.columns = new_cols

    coltypes = {c: _sql_type(df[c]) for c in new_cols}
    table = _sanitize_ident(table, "snap")
    db = str(mysql["database"])

    conn = pymysql.connect(
        host=str(mysql.get("host", "127.0.0.1")),
        port=int(mysql.get("port", 3306)),
        user=str(mysql.get("user", "root")),
        password=str(mysql.get("password", "")),
        charset="utf8mb4",
        autocommit=True,
    )
    try:
        cur = conn.cursor()
        cur.execute(f"CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        cur.execute(f"USE `{db}`")
        cur.execute(f"DROP TABLE IF EXISTS `{table}`")
        cols_ddl = ",\n  ".join(f"`{c}` {coltypes[c]}" for c in new_cols)
        cur.execute(f"CREATE TABLE `{table}` (\n  {cols_ddl}\n) ENGINE=InnoDB")

        df2 = df.where(pd.notnull(df), None)

        def conv(v):
            if v is None:
                return None
            if isinstance(v, np.integer):
                return int(v)
            if isinstance(v, np.floating):
                return None if pd.isna(v) else float(v)
            if isinstance(v, np.bool_):
                return int(v)
            if isinstance(v, (pd.Timestamp,)):
                return v.to_pydatetime()
            return v

        rows = [tuple(conv(v) for v in r) for r in df2.itertuples(index=False, name=None)]
        if rows:
            placeholders = ",".join(["%s"] * len(new_cols))
            cols_ins = ",".join(f"`{c}`" for c in new_cols)
            stmt = f"INSERT INTO `{table}` ({cols_ins}) VALUES ({placeholders})"
            for i in range(0, len(rows), 500):
                cur.executemany(stmt, rows[i : i + 500])

        cur.execute(f"SELECT COUNT(*) FROM `{table}`")
        count = int(cur.fetchone()[0])
        cur.close()
    finally:
        conn.close()

    logger.info("snapshot loaded: %s.%s rows=%s cols=%s", db, table, count, len(new_cols))
    return {"table": table, "database": db, "row_count": count, "columns": col_map}
