<?php

namespace App\Services\DataSource\Resolvers;

use App\Models\DataSource;
use App\Models\Skill;
use App\Services\DataSource\ResolverInterface;
use App\Services\DataSource\ResolverResult;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tier-C resolver: when the bot decides a registered tool matches the
 * user's intent, call the customer's HTTP endpoint and surface the
 * response as a "records" result the LLM can synthesize into a reply.
 *
 * Config shape (from DataSourceWebController::storeWebhook):
 *   {
 *     url:          string,
 *     method:       'GET' | 'POST',
 *     when_to_use:  string,        // bot's intent-matching prompt
 *     auth_type:    'none' | 'bearer' | 'basic' | 'api_key' | 'header',
 *     auth_value:   string|null,
 *     auth_header:  string|null,   // header name for api_key / header
 *     args:         array          // expected args schema for the LLM
 *   }
 *
 * The `context['args']` array (filled in by the LLM upstream) is sent
 * to the endpoint — as query string for GET, JSON body for POST.
 */
class WebhookResolver implements ResolverInterface
{
    public function type(): string
    {
        return DataSource::TYPE_WEBHOOK;
    }

    public function resolve(string $userQuery, DataSource $source, array $context = []): ResolverResult
    {
        $cfg = $source->config ?? [];
        $url = $cfg['url'] ?? '';
        if (!$url) {
            return ResolverResult::error($source->id, $source->type, 'webhook: missing URL');
        }

        // Capability gate: this tool only fires if the session's agent is
        // allowed to use it (via its assigned skills). Enforced here too —
        // not just in ToolPicker — because the router fires every active
        // webhook when no tool was explicitly picked. Unbound tools stay
        // global; absent a gate, nothing is restricted.
        $gate = $context['tool_gate'] ?? null;
        if (is_array($gate) && !Skill::toolPermitted((int) $source->id, $gate)) {
            return ResolverResult::empty($source->id, $source->type);
        }

        // Honor the ToolPicker's decision: if a webhook_decision is
        // present and it picked a DIFFERENT tool, stay silent. If it
        // picked this one, use its extracted args. If no decision was
        // made at all (no LLM router upstream), fall back to context.args.
        $decision = $context['webhook_decision'] ?? null;
        if (is_array($decision)) {
            if ((int) ($decision['tool_id'] ?? 0) !== (int) $source->id) {
                return ResolverResult::empty($source->id, $source->type);
            }
            $args = is_array($decision['args'] ?? null) ? $decision['args'] : [];
        } else {
            $args = is_array($context['args'] ?? null) ? $context['args'] : [];
        }

        $method = strtoupper($cfg['method'] ?? 'GET');

        try {
            $client = new GuzzleClient([
                'timeout'         => 8.0,
                'connect_timeout' => 3.0,
                'http_errors'     => false,
            ]);

            $options = [
                'headers' => $this->buildHeaders($cfg),
            ];
            if ($method === 'POST') {
                $options['json'] = $args;
            } elseif (!empty($args)) {
                $options['query'] = $args;
            }

            $response = $client->request($method, $url, $options);
            $code = $response->getStatusCode();
            $body = (string) $response->getBody();

            if ($code >= 400) {
                Log::warning('WebhookResolver: non-success status', [
                    'source_id' => $source->id, 'url' => $url, 'code' => $code,
                ]);
                return ResolverResult::error(
                    $source->id, $source->type,
                    "webhook returned HTTP {$code}"
                );
            }

            // Try JSON first; fall back to raw text so the LLM gets *something*.
            $decoded = json_decode($body, true);
            $records = is_array($decoded)
                ? (array_is_list($decoded) ? $decoded : [$decoded])
                : [['raw' => $body]];

            return ResolverResult::records(
                $source->id,
                $source->type,
                $records,
                [
                    'tool_name'   => $source->name,
                    'when_to_use' => $cfg['when_to_use'] ?? '',
                    'args'        => $args,
                ],
            );
        } catch (GuzzleException | Throwable $e) {
            Log::warning('WebhookResolver: request failed', [
                'source_id' => $source->id, 'url' => $url, 'error' => $e->getMessage(),
            ]);
            return ResolverResult::error($source->id, $source->type, $e->getMessage());
        }
    }

    public function validateConfig(array $config): array
    {
        $errors = [];
        if (empty($config['url']) || !filter_var($config['url'], FILTER_VALIDATE_URL)) {
            $errors['url'] = 'A valid HTTPS URL is required';
        }
        if (empty($config['when_to_use'])) {
            $errors['when_to_use'] = 'Describe when the bot should call this tool';
        }
        $method = strtoupper($config['method'] ?? '');
        if (!in_array($method, ['GET', 'POST'], true)) {
            $errors['method'] = 'Method must be GET or POST';
        }
        return $errors;
    }

    public function needsSync(): bool
    {
        // Webhook tools are live — nothing to ingest periodically.
        return false;
    }

    public function sync(DataSource $source): void
    {
        // No-op; needsSync() is false.
    }

    private function buildHeaders(array $cfg): array
    {
        $h = ['Accept' => 'application/json'];
        $value = self::decryptAuthValue($cfg['auth_value'] ?? null);
        switch ($cfg['auth_type'] ?? 'none') {
            case 'bearer':
                $h['Authorization'] = 'Bearer ' . $value;
                break;
            case 'basic':
                $h['Authorization'] = 'Basic ' . base64_encode($value);
                break;
            case 'api_key':
                $h[$cfg['auth_header'] ?: 'X-API-Key'] = $value;
                break;
            case 'header':
                if (!empty($cfg['auth_header'])) {
                    $h[$cfg['auth_header']] = $value;
                }
                break;
        }
        return $h;
    }

    /**
     * Auth values are stored encrypted by DataSourceWebController. This
     * helper transparently decrypts; if decryption fails (legacy
     * unencrypted rows or wrong APP_KEY), returns the raw value so the
     * old plaintext rows still work.
     */
    public static function decryptAuthValue(?string $stored): string
    {
        if ($stored === null || $stored === '') return '';
        try {
            return Crypt::decryptString($stored);
        } catch (Throwable $e) {
            return $stored;
        }
    }
}
