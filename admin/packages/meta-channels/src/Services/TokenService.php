<?php

namespace Msd\MetaChannels\Services;

use GuzzleHttp\Client;

/**
 * Facebook access-token lifecycle.
 *
 * Meta hands back a SHORT-LIVED user token (1–2 hours) from the OAuth
 * exchange. Storing that as the working credential — which the original
 * flow did — means every channel a customer connects stops working the
 * same afternoon, with no error anyone sees until a message fails to send.
 *
 * The fix is one extra call: trade it for a long-lived token (~60 days)
 * immediately. That matters twice over, because the long-lived token is
 * also the only thing that makes a later retry possible without dragging
 * the customer back through Meta's consent screens.
 *
 * Token lifetimes, for reference:
 *   short-lived user   1–2 hours
 *   long-lived user    ~60 days
 *   page token         derived from a long-lived user token → never expires
 *   system user token  never expires (the right answer for WhatsApp)
 */
class TokenService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('meta.app');
    }

    /**
     * Exchange a short-lived token for a long-lived one.
     *
     * @return array{token:string, expires_in:?int, expires_at:?\Illuminate\Support\Carbon}
     */
    public function exchangeForLongLived(string $shortLived): array
    {
        $data = $this->get('oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->cfg['id'],
            'client_secret'     => $this->cfg['secret'],
            'fb_exchange_token' => $shortLived,
        ]);

        $token = $data['access_token'] ?? null;
        if (! $token) {
            throw new \RuntimeException('Long-lived token exchange returned no access_token.');
        }

        // Meta omits expires_in for tokens that never expire — treat a
        // missing value as "no expiry" rather than defaulting to something
        // arbitrary, or we would expire a permanent token by accident.
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;

        return [
            'token'      => $token,
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
        ];
    }

    /**
     * Inspect a token via /debug_token: validity, real expiry, granted
     * scopes. Used to record what we actually hold, and to explain a
     * failure in terms the customer can act on ("the number wasn't
     * granted") rather than a raw Graph error.
     *
     * @return array{valid:bool, expires_at:?\Illuminate\Support\Carbon, scopes:array, type:?string, error:?string}
     */
    public function inspect(string $token): array
    {
        try {
            $data = $this->get('debug_token', [
                'input_token'  => $token,
                // App token: any call to debug_token must be authenticated as
                // the app itself, not as the user being inspected.
                'access_token' => $this->cfg['id'] . '|' . $this->cfg['secret'],
            ])['data'] ?? [];
        } catch (\Throwable $e) {
            return ['valid' => false, 'expires_at' => null, 'scopes' => [], 'type' => null, 'error' => $e->getMessage()];
        }

        // expires_at of 0 means "never expires" in Meta's encoding.
        $expires = (int) ($data['expires_at'] ?? 0);

        return [
            'valid'      => (bool) ($data['is_valid'] ?? false),
            'expires_at' => $expires > 0 ? now()->setTimestamp($expires) : null,
            'scopes'     => (array) ($data['scopes'] ?? []),
            'type'       => $data['type'] ?? null,
            'error'      => $data['error']['message'] ?? null,
        ];
    }

    /**
     * Which of the scopes we asked for did the user actually withhold?
     * Meta's consent screen lets people untick individual permissions, and
     * the resulting failure surfaces far downstream as an unhelpful
     * "(#200) Permissions error" — this turns it into a named list.
     *
     * @return array<int,string>
     */
    public function missingScopes(string $token, string $provider): array
    {
        $wanted = array_filter(explode(',', (string) ($this->cfg['scopes'][$provider] ?? '')));
        if (! $wanted) {
            return [];
        }

        $granted = $this->inspect($token)['scopes'];

        return array_values(array_diff($wanted, $granted));
    }

    private function get(string $path, array $query): array
    {
        $url = rtrim($this->cfg['graph_base'], '/') . '/' . $this->cfg['graph_version'] . '/' . ltrim($path, '/');
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);

        $resp = $client->get($url, ['query' => $query]);
        $json = json_decode((string) $resp->getBody(), true);

        if ($resp->getStatusCode() >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException("Graph {$path}: {$msg}");
        }

        return is_array($json) ? $json : [];
    }
}
