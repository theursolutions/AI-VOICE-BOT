# Voice CRM Agent — Python service

Stateless voice + text pipeline. Receives `project_id`, `session_id`,
`voice_id` from JWT (data plane) or webhook payload (control plane).
After every turn, it POSTs to Laravel `/api/internal/turn-completed`
to persist the assistant message.

## Endpoints

| Method | Path                  | Purpose                                        |
|--------|-----------------------|------------------------------------------------|
| GET    | `/healthz`            | Liveness probe                                 |
| POST   | `/llm/respond`        | Non-streaming Gemini reply (Laravel fallback)  |
| POST   | `/extract`            | Structured lead-field extraction               |
| POST   | `/stt`                | File-upload transcription (faster-whisper)     |
| POST   | `/tts`                | Text → wav (Coqui XTTS-v2 voice cloning)       |
| POST   | `/rag/ingest`         | Queue website/document ingest (BackgroundTask) |
| GET    | `/rag/status/{job}`   | Poll ingest job progress                       |
| POST   | `/rag/query`          | Embed query + return top-k passages            |
| WS     | `/ws/turn?token=<JWT>`| Full-duplex audio + text streaming             |
| *      | `/legacy/*`           | Deprecated endpoints from the old `main.py`    |

## Environment variables

| Var                       | Default                                   |
|---------------------------|-------------------------------------------|
| `GEMINI_API_KEY`          | _(required)_                              |
| `GEMINI_API_URL`          | `…/gemini-2.5-flash:generateContent`      |
| `GEMINI_MODEL`            | `gemini-2.5-flash`                        |
| `PYTHON_JWT_SECRET`       | _(required, shared with Laravel)_         |
| `PYTHON_INTERNAL_SECRET`  | _(required, shared with Laravel)_         |
| `LARAVEL_BASE_URL`        | `http://127.0.0.1:8000`                   |
| `COQUI_MODEL`             | `tts_models/multilingual/multi-dataset/xtts_v2` |
| `COQUI_USE_GPU`           | `false`                                   |
| `WHISPER_MODEL`           | `base`                                    |
| `WHISPER_DEVICE`          | `cpu`                                     |
| `WHISPER_COMPUTE_TYPE`    | `int8`                                    |
| `DEFAULT_SPEAKER_WAV`     | `<repo>/temp_speaker.wav`                 |
| `QDRANT_URL`              | `http://127.0.0.1:6333`                   |
| `QDRANT_API_KEY`          | _(only for Qdrant Cloud)_                 |
| `QDRANT_COLLECTION`       | `crm_chunks`                              |
| `EMBEDDING_MODEL`         | `models/text-embedding-004`               |
| `RAG_CHUNK_MAX_TOKENS`    | `500`                                     |
| `RAG_CHUNK_OVERLAP`       | `50`                                      |
| `RAG_CRAWL_MAX_PAGES`     | `50`                                      |
| `RAG_CRAWL_MAX_DEPTH`     | `2`                                       |

## Run

```bash
pip install -r requirements.txt
uvicorn app.api.http:app --host 0.0.0.0 --port 8000
```

## Qdrant (RAG vector store)

The RAG endpoints (`/rag/*`) expect a Qdrant instance at `QDRANT_URL`.
Easiest local setup:

```bash
docker run -p 6333:6333 -p 6334:6334 -v qdrant_storage:/qdrant/storage qdrant/qdrant
```

A collection named `crm_chunks` (768d, cosine) is auto-created on the
first FastAPI boot via `VectorStore.__init__`. If Qdrant is unreachable
the rest of the service still starts; `/rag/*` calls will return empty
results or surface errors until Qdrant comes online.

### RAG endpoints

| Endpoint                | Body                                                                        |
|-------------------------|-----------------------------------------------------------------------------|
| `POST /rag/ingest`      | `{project_id, source_id, type: 'website'\|'document', config: {...}}` → `{job_id, status: 'queued'}` |
| `GET  /rag/status/{id}` | → `{job_id, source_id, status, progress, chunks_indexed, pages_processed, errors[], error?}` |
| `POST /rag/query`       | `{project_id, query, top_k?, source_ids?}` → `{passages: [{text, score, citation, source_id, source_type}]}` |

`config` for `type=website`: `{"url": "...", "max_depth": 2?, "max_pages": 50?}`.
`config` for `type=document`: `{"files": [{"path": "C:\\abs\\path.pdf", "original_name": "..."}, ...]}`.

**Known limitation (MVP):** document ingest reads from local absolute
filesystem paths. Laravel and the voice-engine must share the same host
or have a shared volume mount. Swap for object-store downloads when the
two services split.

## WS frame flow (`/ws/turn`)

Client sends `audio.start` → N × `audio.chunk` (base64 PCM16) →
`audio.end` (or a single `text` frame to skip STT). The server
transcribes the buffered audio with faster-whisper + WebRTC VAD and
emits `stt.final`. It then streams Gemini, forwarding each token as
`llm.delta`. Completed sentences are flushed to Coqui XTTS-v2
`inference_stream`, whose PCM16 chunks are emitted as `audio.chunk`
frames. After `llm.final`, the server sends `audio.end`, then
`turn.end` with `latency_ms`, and fires a fire-and-forget POST to
Laravel `/api/internal/turn-completed` (header `X-Internal-Secret`)
so the assistant message can be persisted and lead-extraction queued.
A client may emit `barge_in` mid-turn to cancel the in-flight pipeline.
