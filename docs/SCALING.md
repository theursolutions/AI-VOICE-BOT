# Scaling the AI compute path

How the system handles load, where it breaks, and how to scale it. The short
version: **agents (personas/humans) are not AI capacity** — capacity is
queue workers, LLM provider limits, and (for voice) the model servers.

---

## 1. The two load paths

**A. Text/chat** (WhatsApp / Instagram / Facebook / web widget)
```
inbound → ConversationManager → ToolPicker (1 LLM call) → resolvers (RAG/DB/webhook)
        → LLM reply → persist → ExtractLeadFromTurn (1 more LLM call)
```
Mostly **I/O-bound** (network waits on the LLM). Scales horizontally with
queue workers + LLM throughput.

**B. Voice calls** — a long-lived WS/WebRTC connection per call running
real-time **STT → LLM → TTS**. **CPU-bound** (XTTS ≈ ~1s/char on CPU). A
handful of concurrent calls saturate one box. This is the hard ceiling.

> 10,000 *per day* of text is easy. 10,000 *concurrent* voice calls is a
> fleet-of-GPUs / cloud-TTS problem. Always size against *concurrency*.

---

## 2. Bottlenecks (and status)

| Bottleneck | Status | Fix |
|---|---|---|
| `QUEUE_CONNECTION=sync` → jobs run **inline**, blocking the request | dev default | Switch to `database`/`redis` + run workers (§3) |
| LLM provider **rate-limits** (Groq 429) abort turns | ✅ **fixed** | Retry+backoff + **provider fallback** now in `LLMService` (§4) |
| Single CPU **voice-engine** (XTTS/Whisper) | open | Cloud TTS/STT or GPU + horizontal instances (§5) |
| `CACHE_DRIVER=file` (dedup locks, not multi-server safe) | dev default | `redis` for cache + sessions at multi-server scale |
| Two LLM calls per text turn (ToolPicker + reply) | by design | ToolPicker self-skips when a project has no tools and no human agents |

---

## 3. Enable async processing (the #1 text-throughput win)

The `jobs` / `failed_jobs` tables already exist; `.env.example` already sets
`QUEUE_CONNECTION=database`. To turn it on:

1. In `.env`: `QUEUE_CONNECTION=database` (or `redis` if Redis is running).
2. Run workers — locally: `php artisan queue:work`; in production use the
   Supervisor pool in [`deploy/supervisor/aicrm-queue.conf`](../deploy/supervisor/aicrm-queue.conf)
   (raise `numprocs` to add concurrency).

> ⚠️ With a non-sync driver you **must** run a worker, or queued jobs
> (WhatsApp replies, lead extraction) never process. That's why dev ships
> `sync`.

Effect: webhooks return instantly; `ProcessInboundMessage` + `ExtractLeadFromTurn`
run on the worker pool. Throughput ≈ `workers × jobs/sec`.

---

## 4. LLM resilience (implemented)

`voice-engine` `LLMService` now retries retryable errors (429/5xx/timeout)
with exponential backoff, then **fails over to a second provider**:

```env
LLM_PROVIDER=groq
LLM_FALLBACK_PROVIDER=anthropic   # or ollama for offline; empty = none
LLM_MAX_RETRIES=2
```

Applies to `chat`, `extract`, and `stream_chat` (stream only fails over
*before* the first token, to avoid duplicate output). For higher ceilings:
raise your Groq tier, or shard across multiple keys behind a gateway.

---

## 5. Scaling voice (the hard part)

The CPU XTTS/Whisper is the ceiling. In order of leverage:

1. **Offload TTS/STT to elastic cloud services** — ElevenLabs (already
   supported per-project) for TTS, Deepgram/AssemblyAI/Whisper-cloud for STT.
   Removes the CPU bottleneck entirely; biggest unlock.
2. **GPU** the self-hosted models — one GPU handles many more concurrent
   streams than CPU.
3. **Horizontally scale the voice-engine** — multiple instances behind a
   router with **session affinity** (a call must stick to its instance);
   autoscale on active-call count.
4. **Overflow queue / callback** when concurrent calls exceed capacity, and
   respect provider concurrency caps (Twilio / WhatsApp calling limits).

> Don't fix this with `uvicorn --workers N` on one box: each worker reloads
> ~2 GB of models. Scale by **instances**, and keep the light text-LLM
> endpoints separate from the heavy STT/TTS ones where possible.

---

## 6. Horizontal Laravel

Once cache + sessions + queue are in Redis, the Laravel app is stateless —
put N instances behind a load balancer and tune PHP-FPM workers. DB: add
read replicas / connection pooling; the per-project tenant sharding already
spreads write load.

---

## 7. Capacity model

Pick measured per-unit numbers, then:

```
text workers needed   ≈ peak_jobs_per_sec × avg_job_seconds
voice instances needed ≈ peak_concurrent_calls ÷ calls_per_instance
human-agent capacity   = Σ(online agents × their max_active_chats)   ← overflow queues
```

**Watch:** queue depth, worker utilization, LLM latency + 429 rate, active
calls per voice instance, DB connections. Drive autoscaling off these, and
add per-project rate limits so one tenant can't starve others.

---

## 8. Quick path to 10k/day text
1. `.env`: `QUEUE_CONNECTION=database`, `CACHE_DRIVER=redis` (if available).
2. Run the Supervisor worker pool (`numprocs` to taste).
3. Set `LLM_FALLBACK_PROVIDER` + a higher Groq tier.
4. (Multi-server) move sessions/cache to Redis, scale Laravel behind an LB.

Voice at scale is a separate effort — start with cloud TTS/STT (§5).
