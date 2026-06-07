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
            // 120s: CPU TTS (XTTS-v2) synthesis can take well over 30s for a
            // full reply. A shorter timeout surfaces to the widget as a
            // generic "Upstream API error".
            'timeout'  => 120,
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
