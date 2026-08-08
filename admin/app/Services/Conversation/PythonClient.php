<?php

namespace App\Services\Conversation;

use GuzzleHttp\Client;

class PythonClient
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => rtrim(config('services.python.base_url'), '/').'/',
            // 280s. Both halves of a turn run on CPU on this host and are slow:
            //   • local LLM (Ollama, qwen2.5:7b) ~0.6 tok/s  → 60-120s per reply
            //   • CPU XTTS-v2 synthesis          ~0.8s/char  → 25s+ per sentence
            // A shorter timeout aborts a generation that would have succeeded
            // and surfaces to the widget as a generic "Upstream API error".
            //
            // Deliberately BELOW php.ini's max_execution_time (300s) so Guzzle
            // times out first and Laravel can return a real error page, rather
            // than PHP fatally killing the worker mid-request.
            'timeout'  => 280,
            'headers'  => [
                'X-Internal-Secret' => config('services.python.internal_secret'),
                'Accept'            => 'application/json',
            ],
        ]);
    }

    public function llm(array $messages, array $options = []): array
    {
        $res = $this->http->post('llm/respond', [
            'json' => ['messages' => $messages] + $options,
        ]);

        return json_decode((string) $res->getBody(), true);
    }

    public function extract(array $payload): array
    {
        $res = $this->http->post('extract', ['json' => $payload]);
        return json_decode((string) $res->getBody(), true);
    }

    public function ragIngest(int $projectId, int $sourceId, string $type, array $config): array
    {
        $res = $this->http->post('rag/ingest', [
            'json' => [
                'project_id' => $projectId,
                'source_id'  => $sourceId,
                'type'       => $type,
                'config'     => $config,
            ],
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    public function ragStatus(string $jobId): array
    {
        $res = $this->http->get("rag/status/{$jobId}");
        return json_decode((string) $res->getBody(), true);
    }

    /**
     * Delete all RAG vectors for one source within one project. Called when
     * a document/website data source is permanently removed.
     */
    public function ragDeleteSource(int $projectId, int $sourceId): array
    {
        $res = $this->http->post('rag/delete-source', [
            'json' => ['project_id' => $projectId, 'source_id' => $sourceId],
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    // --- DuckDB unified store (snapshots=SQL, KB/crawler=BM25 FTS) --------

    /** Load a tabular upload into the project's DuckDB file as a SQL table. */
    public function duckLoadTable(int $projectId, int $sourceId, array $files): array
    {
        $res = $this->http->post('duck/load-table', [
            'json'    => ['project_id' => $projectId, 'source_id' => $sourceId, 'files' => $files],
            'timeout' => 300,
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /**
     * Run a generated SELECT against the project's DuckDB file. Throws on a
     * 4xx so the caller's repair loop sees the DB error text.
     */
    public function duckQuery(int $projectId, string $sql): array
    {
        $res = $this->http->post('duck/query', [
            'json'        => ['project_id' => $projectId, 'sql' => $sql],
            'http_errors' => true,
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /** Store KB/crawler text chunks into the project's DuckDB BM25 index. */
    public function duckLoadDocs(int $projectId, int $sourceId, array $chunks): array
    {
        $res = $this->http->post('duck/load-docs', [
            'json'    => ['project_id' => $projectId, 'source_id' => $sourceId, 'chunks' => $chunks],
            'timeout' => 300,
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /** BM25 keyword search over one or more docs sources. */
    public function duckSearch(int $projectId, array $sourceIds, string $query, int $topK = 5): array
    {
        $res = $this->http->post('duck/search', [
            'json' => [
                'project_id' => $projectId,
                'source_ids' => array_values($sourceIds),
                'query'      => $query,
                'top_k'      => $topK,
            ],
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /** Drop a source's DuckDB table(s) when the source is deleted. */
    public function duckDropSource(int $projectId, int $sourceId): array
    {
        $res = $this->http->post('duck/drop-source', [
            'json' => ['project_id' => $projectId, 'source_id' => $sourceId],
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /**
     * Load a tabular upload (CSV/XLSX/JSON) into a MySQL table for
     * text-to-SQL querying. The engine reads the file off disk, infers
     * column types, (re)creates the table, and bulk-inserts. Returns
     * {table, database, row_count, columns:[{name, source_name}]}.
     *
     * @param array<int,array{path:string,original_name?:string}> $files
     * @param array{host:string,port:int,user:string,password:string,database:string} $mysql
     */
    public function snapshotLoad(array $files, array $mysql, string $table): array
    {
        $res = $this->http->post('snapshot/load', [
            'json'    => ['files' => $files, 'mysql' => $mysql, 'table' => $table],
            'timeout' => 300,   // large sheets + bulk insert can run long
        ]);
        return json_decode((string) $res->getBody(), true);
    }

    /**
     * Relay a WhatsApp call SDP offer to the voice-engine WebRTC bridge and
     * return the SDP answer (or null). Used by the meta-channels call handler.
     */
    public function whatsappCallOffer(string $token, string $callId, string $sdp): ?string
    {
        $res = $this->http->post('whatsapp/call/offer', [
            'json' => ['token' => $token, 'call_id' => $callId, 'sdp' => $sdp],
        ]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['sdp'] ?? null;
    }

    /** Live voice-engine metrics (active calls, provider, readiness). Null if unreachable. */
    public function metrics(): ?array
    {
        try {
            $res = $this->http->get('metrics', ['timeout' => 4]);
            return json_decode((string) $res->getBody(), true) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function whatsappCallTerminate(string $callId): void
    {
        $this->http->post('whatsapp/call/terminate', [
            'json' => ['call_id' => $callId],
        ]);
    }

    /**
     * Transcribe raw audio bytes via the voice-engine /stt endpoint.
     * Used to turn inbound WhatsApp/Messenger voice notes into text.
     */
    public function transcribe(string $bytes, string $filename = 'audio.ogg', ?string $language = null): ?string
    {
        $multipart = [
            ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
        ];
        if ($language) {
            $multipart[] = ['name' => 'language', 'contents' => $language];
        }
        $res = $this->http->post('stt', ['multipart' => $multipart]);
        $data = json_decode((string) $res->getBody(), true);
        return $data['text'] ?? null;
    }

    /**
     * Transcode audio bytes to a target container via the voice-engine
     * (e.g. browser webm/opus → WhatsApp-accepted ogg/opus). Returns the
     * transcoded bytes, or null on failure (caller can fall back).
     */
    public function transcodeAudio(string $bytes, string $filename = 'audio.webm', string $target = 'ogg'): ?string
    {
        try {
            $res = $this->http->post('audio/transcode', [
                'multipart' => [
                    ['name' => 'file', 'contents' => $bytes, 'filename' => $filename],
                    ['name' => 'target', 'contents' => $target],
                ],
            ]);
            if ($res->getStatusCode() >= 400) {
                return null;
            }
            $out = (string) $res->getBody();
            return $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('transcodeAudio failed: ' . $e->getMessage());
            return null;
        }
    }

    public function ragQuery(int $projectId, string $query, int $topK = 5, ?array $sourceIds = null): array
    {
        $payload = [
            'project_id' => $projectId,
            'query'      => $query,
            'top_k'      => $topK,
        ];
        if ($sourceIds !== null) {
            $payload['source_ids'] = $sourceIds;
        }
        $res = $this->http->post('rag/query', ['json' => $payload]);
        return json_decode((string) $res->getBody(), true);
    }
}
