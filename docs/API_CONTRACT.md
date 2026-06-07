# Voice CRM Agent — API Contract

The single source of truth for the JSON shapes exchanged between
WebChatBot ↔ Laravel ↔ Python.

## Multi-tenancy

- `users`, `projects`, `clients`, `payment_plans` live in the **app DB**.
- `sessions`, `messages`, `session_summaries`, `leads`, `voices` live in the
  **per-project tenant DB** (credentials in `projects.db_*`).
- Every API call is scoped to a single project. Laravel switches the `tenant`
  connection via `App\Services\Tenant\TenantManager::useFor($project)` before
  any chat-data query.
- The Python worker is stateless. It receives `project_id` in the JWT (data
  plane) and in the webhook payload (control plane); Laravel resolves the
  tenant DB from there.
- Default voice provider: **Coqui** (local). ElevenLabs is opt-in per project.

## Auth

| Direction              | Header              | Value                                     |
|------------------------|---------------------|-------------------------------------------|
| Browser/Channel → Laravel | `X-CLIENT-API-KEY`  | `projects.project_api_key`                |
| Browser → Python (WS)  | `?token=<JWT>`      | Minted by Laravel, HS256, contains `session_id`, `project_id`, `voice_id`, `exp` |
| Python → Laravel       | `X-Internal-Secret` | `PYTHON_INTERNAL_SECRET` (env, shared)    |

## Control plane (Laravel HTTP)

### `POST /api/v1/sessions`

Request:
```json
{
  "channel": "web|whatsapp|twilio|plivo|api",
  "external_id": "optional channel id (twilio call sid, etc.)",
  "customer_name": "string?",
  "customer_phone": "string?",
  "customer_email": "string?",
  "voice_id": 12,
  "metadata": {}
}
```

Response `201`:
```json
{
  "session_id": 1234,
  "token": "eyJ...",
  "ws_url": "ws://python:8000/ws/turn",
  "expires_in": 3600
}
```

### `POST /api/v1/sessions/{id}/turn`  *(text fallback path)*

Request:
```json
{
  "text": "string?",
  "audio_url": "string?",
  "respond_with": "text|audio|both",
  "stream": false
}
```

Response `200`: full assistant message row.

### `POST /api/v1/sessions/{id}/end`

Closes the session. Returns `{session_id, status}`.

## Data plane (Python WebSocket)

`WS /ws/turn?token=<JWT>` — full duplex.

### Client → server frames

```json
{"type": "audio.start",  "format": "pcm16", "sample_rate": 16000}
{"type": "audio.chunk",  "seq": 0, "data": "<base64>"}
{"type": "audio.end"}
{"type": "text",         "text": "..."}
{"type": "barge_in"}
```

### Server → client frames

```json
{"type": "stt.partial", "text": "..."}
{"type": "stt.final",   "text": "..."}
{"type": "llm.delta",   "text": "..."}
{"type": "llm.final",   "text": "...", "tokens_in": 0, "tokens_out": 0}
{"type": "audio.chunk", "seq": 0, "data": "<base64>", "format": "pcm16"}
{"type": "audio.end"}
{"type": "turn.end",    "latency_ms": 1840}
{"type": "error",       "code": "...", "message": "..."}
```

Python persists the assistant turn via the internal webhook below
once `turn.end` is emitted. Browser does NOT round-trip through
Laravel for streamed audio/text.

## Persistence webhook (Python → Laravel)

### `POST /api/internal/turn-completed`

```json
{
  "project_id": 7,
  "session_id": 1234,
  "role": "assistant",
  "content": "final transcript text",
  "audio_url": "s3://... or null",
  "tokens_in": 312,
  "tokens_out": 88,
  "latency_ms": 1840,
  "model_used": "gemini-2.5-flash",
  "metadata": {"intent": "data|conversation", "tool_calls": []}
}
```

Response `201`: `{"message_id": 5678}`.

Side effect: queues `ExtractLeadFromTurn` job, which calls
`POST /extract` on Python and merges the result into the `leads` row
for that session.

## Lead extraction (Laravel → Python)

### `POST /extract`

```json
{
  "session_id": 1234,
  "project_id": 7,
  "user_text": "...",
  "assistant_text": "...",
  "existing_fields": {"name": "Ali"}
}
```

Response:
```json
{
  "fields": {
    "name": "Ali Khan",
    "email": "ali@example.com",
    "phone": "+60123456789",
    "intent": "demo_request",
    "budget": "RM 5k",
    "timeline": "this month",
    "custom": {}
  },
  "confidence": 0.82
}
```

Only return fields the model is confident about. Missing fields are
preferred over hallucinated ones.
