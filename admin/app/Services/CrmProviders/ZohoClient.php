<?php

namespace App\Services\CrmProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper around Zoho CRM's OAuth + Search Records API.
 *
 * Zoho has regional auth hosts (accounts.zoho.com / .eu / .in / .com.au /
 * .com.cn / .jp). The token response carries an `api_domain` (e.g.
 * https://www.zohoapis.com or https://www.zohoapis.eu) — we surface it so
 * the caller can persist it in `config.api_domain` and keep subsequent
 * calls in the right datacenter.
 *
 * All public methods are crash-proof — non-2xx -> log + [].
 *
 * Docs:
 *   - OAuth:   https://www.zoho.com/crm/developer/docs/api/v3/auth-request.html
 *   - Search:  https://www.zoho.com/crm/developer/docs/api/v3/search-records.html
 */
class ZohoClient
{
    private const AUTHORIZE_PATH = '/oauth/v2/auth';
    private const TOKEN_PATH     = '/oauth/v2/token';
    private const DEFAULT_API    = 'https://www.zohoapis.com';
    private const TIMEOUT        = 15;

    private const SCOPES = [
        'ZohoCRM.modules.contacts.READ',
        'ZohoCRM.modules.accounts.READ',
        'ZohoCRM.modules.deals.READ',
        'ZohoCRM.users.READ',
        'offline_access',
    ];

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'timeout'     => self::TIMEOUT,
            'http_errors' => false,
            'headers'     => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function authorizationUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id'     => (string) config('services.zoho.client_id'),
            'redirect_uri'  => (string) config('services.zoho.redirect_uri'),
            'scope'         => implode(',', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ];

        return $this->authHost().self::AUTHORIZE_PATH.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, api_domain: ?string}|array{}
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->postToken([
            'grant_type'    => 'authorization_code',
            'client_id'     => (string) config('services.zoho.client_id'),
            'client_secret' => (string) config('services.zoho.client_secret'),
            'redirect_uri'  => (string) config('services.zoho.redirect_uri'),
            'code'          => $code,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, api_domain: ?string}|array{}
     */
    public function refresh(string $refreshToken): array
    {
        // Zoho's refresh response does NOT include a new refresh_token —
        // caller should retain the original. We still return a uniform shape.
        $out = $this->postToken([
            'grant_type'    => 'refresh_token',
            'client_id'     => (string) config('services.zoho.client_id'),
            'client_secret' => (string) config('services.zoho.client_secret'),
            'refresh_token' => $refreshToken,
        ]);

        if (!empty($out) && empty($out['refresh_token'])) {
            $out['refresh_token'] = $refreshToken;
        }
        return $out;
    }

    public function searchContacts(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        return $this->searchModule($accessToken, $apiDomain, 'Contacts', $query, $limit);
    }

    public function searchCompanies(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        // Zoho calls "company" objects "Accounts".
        return $this->searchModule($accessToken, $apiDomain, 'Accounts', $query, $limit);
    }

    public function searchDeals(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        return $this->searchModule($accessToken, $apiDomain, 'Deals', $query, $limit);
    }

    /**
     * GET /crm/v3/{module}/search?word=... — returns the raw `data` array,
     * or `[]` on failure / 204 No Content (Zoho returns 204 on zero matches).
     */
    private function searchModule(string $accessToken, ?string $apiDomain, string $module, string $query, int $limit): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $base = $this->resolveApiDomain($apiDomain);

        try {
            $res = $this->http->get($base.'/crm/v3/'.$module.'/search', [
                'headers' => [
                    'Authorization' => 'Zoho-oauthtoken '.$accessToken,
                ],
                'query' => [
                    'word'     => $term,
                    'per_page' => max(1, min(200, $limit)),
                ],
            ]);

            $status = $res->getStatusCode();
            if ($status === 204) {
                return [];
            }
            if ($status < 200 || $status >= 300) {
                Log::warning("Zoho search {$module} returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return $body['data'] ?? [];
        } catch (Throwable $e) {
            Log::error("Zoho search {$module} failed: ".$e->getMessage());
            return [];
        }
    }

    private function postToken(array $form): array
    {
        try {
            $res = $this->http->post($this->authHost().self::TOKEN_PATH, [
                'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => $form,
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("Zoho token endpoint returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            if (empty($body['access_token'])) {
                return [];
            }
            return [
                'access_token'  => (string) ($body['access_token']  ?? ''),
                'refresh_token' => (string) ($body['refresh_token'] ?? ''),
                'expires_in'    => (int)    ($body['expires_in']    ?? 3600),
                'api_domain'    => isset($body['api_domain']) ? (string) $body['api_domain'] : null,
            ];
        } catch (Throwable $e) {
            Log::error('Zoho token exchange failed: '.$e->getMessage());
            return [];
        }
    }

    private function authHost(): string
    {
        $host = (string) config('services.zoho.auth_host', 'https://accounts.zoho.com');
        return rtrim($host, '/');
    }

    private function resolveApiDomain(?string $apiDomain): string
    {
        if ($apiDomain !== null && $apiDomain !== '') {
            return rtrim($apiDomain, '/');
        }
        return self::DEFAULT_API;
    }
}
