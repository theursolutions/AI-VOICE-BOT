"""
AI-CRM customer-hosted query agent (Tier 3b).

Runs on the customer's network. Polls the cloud admin for pending SQL
queries, executes them against a local read-only DB user, returns
results. The customer's DB credentials never leave their network.

Required env vars:
  LARAVEL_BASE_URL     e.g. https://app.aicrm.example
  ENROLLMENT_TOKEN     one-time bootstrap token (only on first run)
  -- OR --
  AGENT_TOKEN          long-lived bearer (cached after enroll)

  DB_DRIVER            "mysql" | "postgres"   (default mysql)
  DB_HOST              host of customer's DB
  DB_PORT              e.g. 3306
  DB_NAME              database
  DB_USER              **read-only** user
  DB_PASSWORD          password

Hard safety rails (enforced here, not trusted from server):
  - SQL must start with SELECT (case-insensitive)
  - SQL must not contain a semicolon
  - max_rows is honoured even if the server asks for more
  - query timeout = 5s
  - read-only DB user is the customer's responsibility (we can't enforce it)
"""

from __future__ import annotations

import logging
import os
import re
import signal
import sys
import time
from typing import Any

import httpx

CLIENT_VERSION = "0.1.0"
SQL_GUARD = re.compile(r"^\s*select\b", re.IGNORECASE)
HARD_MAX_ROWS = 500
QUERY_TIMEOUT_SECONDS = 5

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("aicrm-agent")


def env(name: str, default: str | None = None, *, required: bool = False) -> str | None:
    val = os.environ.get(name, default)
    if required and not val:
        log.error("Missing required env var: %s", name)
        sys.exit(2)
    return val


def db_run_query(sql: str, max_rows: int) -> list[dict[str, Any]]:
    """Run SQL against the customer's DB and return rows. Caller has
    already enforced the SELECT-only guard."""

    driver = env("DB_DRIVER", "mysql").lower()
    host   = env("DB_HOST", required=True)
    port   = int(env("DB_PORT", "3306"))
    name   = env("DB_NAME", required=True)
    user   = env("DB_USER", required=True)
    pw     = env("DB_PASSWORD", "")

    safe_cap = min(max_rows, HARD_MAX_ROWS)

    if driver == "mysql":
        import pymysql
        conn = pymysql.connect(
            host=host, port=port, user=user, password=pw, database=name,
            charset="utf8mb4", cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5, read_timeout=QUERY_TIMEOUT_SECONDS,
        )
        try:
            with conn.cursor() as cur:
                cur.execute(sql)
                rows = cur.fetchmany(safe_cap)
                return [dict(r) for r in rows]
        finally:
            conn.close()

    elif driver == "postgres":
        import psycopg
        with psycopg.connect(
            host=host, port=port, user=user, password=pw, dbname=name,
            connect_timeout=5, options=f"-c statement_timeout={QUERY_TIMEOUT_SECONDS * 1000}",
        ) as conn:
            with conn.cursor() as cur:
                cur.execute(sql)
                cols = [d[0] for d in cur.description or []]
                rows = cur.fetchmany(safe_cap)
                return [dict(zip(cols, r)) for r in rows]

    raise RuntimeError(f"Unsupported DB_DRIVER: {driver}")


def enroll(base_url: str, enrollment_token: str) -> str:
    log.info("Enrolling with admin at %s", base_url)
    resp = httpx.post(
        f"{base_url.rstrip('/')}/api/v1/agent/enroll",
        json={"enrollment_token": enrollment_token, "client_version": CLIENT_VERSION},
        timeout=15,
    )
    resp.raise_for_status()
    data = resp.json()
    token = data["token"]
    log.info("Enrolled. agent_uid=%s", data.get("agent_uid"))
    persist_token(token)
    return token


def persist_token(token: str) -> None:
    path = env("TOKEN_FILE", "/data/agent.token")
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            f.write(token)
    except OSError as e:
        log.warning("Could not persist token to %s: %s", path, e)


def load_persisted_token() -> str | None:
    path = env("TOKEN_FILE", "/data/agent.token")
    if os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                return f.read().strip()
        except OSError:
            return None
    return None


def resolve_token(base_url: str) -> str:
    explicit = env("AGENT_TOKEN")
    if explicit:
        return explicit

    persisted = load_persisted_token()
    if persisted:
        return persisted

    enrollment = env("ENROLLMENT_TOKEN")
    if not enrollment:
        log.error("No AGENT_TOKEN, no persisted token, and no ENROLLMENT_TOKEN. Cannot continue.")
        sys.exit(2)

    return enroll(base_url, enrollment)


def is_safe_select(sql: str) -> tuple[bool, str | None]:
    if not SQL_GUARD.match(sql):
        return False, "Only SELECT statements are allowed"
    if ";" in sql:
        return False, "Multiple statements / semicolons are not allowed"
    lower = sql.lower()
    forbidden = ["into outfile", "into dumpfile", "load_file(", "load data"]
    for keyword in forbidden:
        if keyword in lower:
            return False, f"Forbidden construct: {keyword}"
    return True, None


def handle_work(client: httpx.Client, work: dict[str, Any], base_url: str) -> None:
    request_id = work["request_id"]
    sql        = work["sql"]
    max_rows   = int(work.get("max_rows", 100))

    log.info("[req=%s] received: %s", request_id[:8], sql[:120].replace("\n", " "))

    ok, reason = is_safe_select(sql)
    if not ok:
        log.warning("[req=%s] rejected: %s", request_id[:8], reason)
        post_result(client, base_url, request_id, status="failed", error=reason)
        return

    try:
        rows = db_run_query(sql, max_rows)
        log.info("[req=%s] returned %d rows", request_id[:8], len(rows))
        post_result(client, base_url, request_id, status="done", rows=rows)
    except Exception as e:  # noqa: BLE001
        log.exception("[req=%s] query failed", request_id[:8])
        post_result(client, base_url, request_id, status="failed", error=str(e))


def post_result(
    client: httpx.Client,
    base_url: str,
    request_id: str,
    *,
    status: str,
    rows: list[dict[str, Any]] | None = None,
    error: str | None = None,
) -> None:
    body: dict[str, Any] = {"request_id": request_id, "status": status}
    if rows is not None:
        body["rows"] = rows
    if error:
        body["error"] = error

    client.post(f"{base_url.rstrip('/')}/api/v1/agent/result", json=body, timeout=15)


def run_loop(base_url: str, token: str) -> None:
    headers = {"Authorization": f"Bearer {token}"}
    poll_url = f"{base_url.rstrip('/')}/api/v1/agent/poll"

    with httpx.Client(headers=headers) as client:
        log.info("Polling %s", poll_url)
        while True:
            try:
                r = client.get(poll_url, params={"wait": 25}, timeout=30)
                if r.status_code == 401:
                    log.error("Authentication failed. Token revoked or expired. Re-enroll.")
                    sys.exit(3)
                r.raise_for_status()
                data = r.json()
                work = data.get("work")
                if work:
                    handle_work(client, work, base_url)
                # else: empty poll, just go again
            except httpx.RequestError as e:
                log.warning("Network error: %s; retrying in 5s", e)
                time.sleep(5)


def main() -> None:
    base_url = env("LARAVEL_BASE_URL", required=True)
    token    = resolve_token(base_url)

    def _handle_signal(signum: int, _frame: Any) -> None:
        log.info("Received signal %d, shutting down", signum)
        sys.exit(0)

    signal.signal(signal.SIGINT, _handle_signal)
    signal.signal(signal.SIGTERM, _handle_signal)

    run_loop(base_url, token)


if __name__ == "__main__":
    main()
