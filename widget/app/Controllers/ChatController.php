<?php
namespace Ursolutions\Tvaibwc\Controllers;

/**
 * ChatController — proxies the browser widget through to the Laravel
 * Voice CRM Agent control plane.
 *
 * Contract:
 *  - Auth header: X-CLIENT-API-KEY = projects.project_api_key
 *  - POST {LARAVEL_BASE_URL}/api/v1/sessions          → start a session
 *  - POST {LARAVEL_BASE_URL}/api/v1/sessions/{id}/turn → submit a single turn
 *
 * Spec: ai-voice-bot-admin/docs/API_CONTRACT.md
 */
class ChatController
{
    /**
     * Resolve the Laravel base URL (no trailing slash).
     */
    private function laravelBaseUrl(): string
    {
        $base = $_ENV['LARAVEL_BASE_URL'] ?? 'http://127.0.0.1:8001';
        return rtrim($base, '/');
    }

    /**
     * Resolve the X-CLIENT-API-KEY value (the project's project_api_key).
     *
     * Resolution order:
     *   1. `project_api_key` field on the incoming POST (embed mode —
     *      the widget passes through the key from its ?key= URL param)
     *   2. $_ENV['TVAIBWC_PROJECT_API_KEY']    (single-project deploys)
     *   3. $_ENV['CLIENT_API_KEY']             (legacy var name)
     */
    private function projectApiKey(?array $payload = null): string
    {
        if (is_array($payload) && !empty($payload['project_api_key'])) {
            return (string) $payload['project_api_key'];
        }
        return $_ENV['TVAIBWC_PROJECT_API_KEY']
            ?? $_ENV['CLIENT_API_KEY']
            ?? '';
    }

    /**
     * Default headers for every Laravel request.
     */
    private function authHeaders(?array $payload = null): array
    {
        return [
            'X-CLIENT-API-KEY' => $this->projectApiKey($payload),
            'Accept'           => 'application/json',
            'Content-Type'     => 'application/json',
        ];
    }

    /**
     * POST /api/v1/sessions — create (or resume) a chat session.
     *
     * Request fields (all optional except channel):
     *   channel, customer_name, customer_phone, customer_email, voice_id, metadata
     *
     * Returns the upstream JSON {session_id, token, ws_url, expires_in}
     * wrapped in our standard envelope.
     */
    public function startSession($payload)
    {
        $url = $this->laravelBaseUrl() . '/api/v1/sessions';

        // Build the body. Cast metadata if it arrived as a JSON string from FormData.
        $metadata = $payload['metadata'] ?? [];
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        $body = [
            'channel'        => $payload['channel'] ?? 'web',
            'customer_name'  => $payload['customer_name']  ?? null,
            'customer_phone' => $payload['customer_phone'] ?? null,
            'customer_email' => $payload['customer_email'] ?? null,
            'metadata'       => $metadata,
        ];
        // Visitor's language pick from the widget header dropdown.
        if (!empty($payload['language'])) {
            $body['language'] = $payload['language'];
        }
        if (!empty($payload['external_id'])) {
            $body['external_id'] = $payload['external_id'];
        }
        if (!empty($payload['voice_id'])) {
            $body['voice_id'] = (int) $payload['voice_id'];
        }

        $response = (object) callApi($url, 'POST', $this->authHeaders($payload), $body, 15, false);
        return $this->envelope($response, 'Session started');
    }

    /**
     * POST /api/v1/sessions/{id}/turn — send a single user turn.
     *
     * $sessionId  : the session_id returned by startSession()
     * $payload    : { text?, audio_url?, respond_with?, stream? }
     */
    public function sendTurn($sessionId, $payload)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            header('Content-Type: application/json');
            return json_encode([
                'success'     => false,
                'status_code' => 400,
                'message'     => 'Missing or invalid session_id',
                'response'    => null,
            ]);
        }

        $url = $this->laravelBaseUrl() . "/api/v1/sessions/{$sessionId}/turn";

        $respondWith = $payload['respond_with'] ?? null;
        if (!$respondWith) {
            // Map the legacy widget toggle. response_in_voice=1 → audio reply.
            $voice = $payload['response_in_voice'] ?? 0;
            $respondWith = ((string) $voice === '1' || $voice === true) ? 'audio' : 'text';
        }

        $body = [
            'text'         => $payload['text'] ?? ($payload['message_text'] ?? null),
            'audio_url'    => $payload['audio_url'] ?? null,
            'respond_with' => $respondWith,
            'stream'       => isset($payload['stream'])
                ? filter_var($payload['stream'], FILTER_VALIDATE_BOOLEAN)
                : false,
        ];

        // 120s to match the Laravel→voice-engine timeout: a voice (audio)
        // reply runs CPU TTS synthesis which can exceed 30s.
        $response = (object) callApi($url, 'POST', $this->authHeaders($payload), $body, 120, false);
        return $this->envelope($response, 'Turn processed');
    }

    /**
     * POST /api/v1/sessions/{id}/flow/step — advance the bound flow.
     *
     * Payload: { choice_id?: "1", text?: "free-form" }
     * Returns the WebFlowRunner envelope:
     *   { messages, expecting, current_node_id, handoff, ended, cost_avoided }
     */
    public function flowStep($sessionId, $payload)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            header('Content-Type: application/json');
            return json_encode([
                'success'     => false,
                'status_code' => 400,
                'message'     => 'Missing or invalid session_id',
                'response'    => null,
            ]);
        }

        $url = $this->laravelBaseUrl() . "/api/v1/sessions/{$sessionId}/flow/step";
        $body = [
            'choice_id' => $payload['choice_id'] ?? null,
            'text'      => $payload['text'] ?? null,
        ];

        $response = (object) callApi($url, 'POST', $this->authHeaders($payload), $body, 30, false);
        return $this->envelope($response, 'Flow step processed');
    }

    /**
     * POST /api/v1/sessions/{id}/flow/restart — re-enter the bound
     * flow from its Start node. Powers the "Back to menu" pill.
     */
    public function flowRestart($sessionId, $payload)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            header('Content-Type: application/json');
            return json_encode([
                'success'     => false,
                'status_code' => 400,
                'message'     => 'Missing or invalid session_id',
                'response'    => null,
            ]);
        }

        $url = $this->laravelBaseUrl() . "/api/v1/sessions/{$sessionId}/flow/restart";
        $response = (object) callApi($url, 'POST', $this->authHeaders($payload), [], 30, false);
        return $this->envelope($response, 'Flow restarted');
    }

    /**
     * POST /api/v1/sessions/{id}/end — close the conversation.
     * Marks session.status = 'ended' so the admin sees it as completed.
     * Idempotent — safe to call on an already-ended session.
     */
    public function endSession($sessionId, $payload)
    {
        $sessionId = (int) $sessionId;
        if ($sessionId <= 0) {
            header('Content-Type: application/json');
            return json_encode([
                'success'     => false,
                'status_code' => 400,
                'message'     => 'Missing or invalid session_id',
                'response'    => null,
            ]);
        }

        $url = $this->laravelBaseUrl() . "/api/v1/sessions/{$sessionId}/end";
        $response = (object) callApi($url, 'POST', $this->authHeaders($payload), [], 10, false);
        return $this->envelope($response, 'Session ended');
    }

    /**
     * Action handler — the JS layer hits this via ChatHandler.php with
     *   action=startSession      → start a session
     *   action=sendTurn          → send a turn
     *   action=flowStep          → advance bound flow with choice/text
     *   action=flowRestart       → re-enter the bound flow from start
     *   action=endSession        → mark session ended in DB
     *   action=chatResponse      → legacy alias, mapped to sendTurn for BC
     */
    public function chatResponse($payload)
    {
        // Legacy entry point. If a session_id is present, route to sendTurn;
        // otherwise warn the caller — startSession must run first now.
        $sessionId = $payload['session_id'] ?? null;
        if ($sessionId) {
            return $this->sendTurn($sessionId, $payload);
        }
        header('Content-Type: application/json');
        return json_encode([
            'success'     => false,
            'status_code' => 400,
            'message'     => 'session_id required. Call action=startSession first.',
            'response'    => null,
        ]);
    }

    /**
     * Wrap a callApi() result in the envelope the widget JS expects:
     *   { success, status_code, message, response }
     */
    private function envelope(object $response, string $okMessage): string
    {
        header('Content-Type: application/json');

        $success = !empty($response->success);
        $status  = $response->status ?? 0;
        $data    = $response->data ?? null;

        if ($success && $status >= 200 && $status < 300) {
            return json_encode([
                'success'     => true,
                'status_code' => $status,
                'message'     => $okMessage,
                'response'    => $data,
            ]);
        }

        // Sanitise — server/system error strings ("Upstream API error",
        // "cURL error 28", "SQLSTATE...") must never reach the chat UI.
        // Log the raw details for the developer, surface a calm,
        // human-readable message for the visitor.
        $rawDetail = is_string($response->error ?? null) ? $response->error : null;
        if (function_exists('error_log') && ($rawDetail || $data)) {
            error_log('[tvaibwc] upstream failure status=' . $status
                . ' detail=' . ($rawDetail ?: json_encode($data)));
        }

        return json_encode([
            'success'     => false,
            'status_code' => $status ?: 502,
            'message'     => 'Sorry, I had trouble responding just now. Please try again in a moment.',
            // Keep `response` null for the user-facing layer — raw Laravel
            // / Guzzle exception text used to leak into the chat bubble.
            'response'    => null,
        ]);
    }
}
