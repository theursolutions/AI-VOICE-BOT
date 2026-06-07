# AI-CRM Query Agent

A small program that lets the AI-CRM voice bot answer questions about
**your** CRM **without ever giving us your database password**.

You run this on your own network. It opens an outbound HTTPS connection
to AI-CRM's cloud, picks up SQL queries we send, runs them against
your DB locally, and returns the results. Your credentials never
leave your network.

## What's safe

- The agent **only** runs SELECT statements. Anything else (`INSERT`,
  `UPDATE`, `DELETE`, `DROP`, multiple statements separated by `;`,
  `LOAD_FILE`, `INTO OUTFILE`) is rejected client-side before it
  touches your database.
- Run the agent against a **read-only DB user** restricted to the
  tables you want exposed. This is the strongest guarantee — we
  literally can't ask for data the user can't see.
- The agent caps result sets at 500 rows and times each query out
  after 5 seconds.

## Quickstart (Docker)

1. In the AI-CRM admin UI, create an agent for your project. Copy the
   one-time `enrollment_token`.

2. Create a read-only MySQL user on your CRM database:
   ```sql
   CREATE USER 'aicrm_ro'@'%' IDENTIFIED BY 'a-strong-password';
   GRANT SELECT ON your_crm_db.contacts TO 'aicrm_ro'@'%';
   GRANT SELECT ON your_crm_db.deals    TO 'aicrm_ro'@'%';
   -- repeat for any other tables you want exposed.
   FLUSH PRIVILEGES;
   ```

3. Run the agent:
   ```bash
   docker run -d --name aicrm-agent \
     -v aicrm-agent-data:/data \
     -e LARAVEL_BASE_URL=https://app.aicrm.example \
     -e ENROLLMENT_TOKEN=<one-time-token-from-step-1> \
     -e DB_HOST=your.db.host \
     -e DB_PORT=3306 \
     -e DB_NAME=your_crm_db \
     -e DB_USER=aicrm_ro \
     -e DB_PASSWORD=<your password> \
     aicrm/query-agent:latest
   ```

   After the first successful enrollment, the long-lived bearer token
   is written to `/data/agent.token` (persisted in the named volume).
   On restart the agent reuses it; you can remove `ENROLLMENT_TOKEN`
   from your run command.

## Environment variables

| Var | Required | Notes |
|---|---|---|
| `LARAVEL_BASE_URL` | yes | e.g. `https://app.aicrm.example` |
| `ENROLLMENT_TOKEN` | first run only | one-time bootstrap |
| `AGENT_TOKEN` | optional | skip enrollment, paste a long-lived token |
| `DB_DRIVER` | no | `mysql` (default) or `postgres` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | yes | your DB |
| `TOKEN_FILE` | no | default `/data/agent.token` |
| `LOG_LEVEL` | no | `INFO` (default), `DEBUG`, `WARNING` |

## Without Docker

```bash
pip install -r requirements.txt
LARAVEL_BASE_URL=... ENROLLMENT_TOKEN=... DB_HOST=... \
DB_PORT=3306 DB_NAME=... DB_USER=... DB_PASSWORD=... \
python agent.py
```

## How it works

1. **Enroll** — first run sends `ENROLLMENT_TOKEN` to
   `POST /api/v1/agent/enroll`, receives a long-lived bearer token.
2. **Poll** — `GET /api/v1/agent/poll?wait=25` long-polls for work.
3. **Validate** — every incoming SQL is checked client-side:
   - must start with `SELECT`
   - no `;`
   - no `LOAD_FILE` / `INTO OUTFILE` / `INTO DUMPFILE` / `LOAD DATA`
4. **Run** — execute against your DB with a 5-second timeout.
5. **Return** — POST results to `/api/v1/agent/result`.
6. Loop.

## Revoke / rotate

Revoke the token in the admin UI. The agent will get `401` on its
next poll and exit; remove and re-run with a fresh
`ENROLLMENT_TOKEN`.
