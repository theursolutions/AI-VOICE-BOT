<?php

namespace App\Services\CrmProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper around Pipedrive's OAuth + itemSearch API.
 *
 * Pipedrive issues an `api_domain` in the token response (e.g.
 * https://yourcompany.pipedrive.com) — we surface that so the caller
 * can persist it in `config.api_domain` and target the customer's
 * region/company on subsequent API calls.
 *
 * All public methods are crash-proof — non-2xx -> log + [].
 *
 * Docs:
 *   - OAuth:   https://pipedrive.readme.io/docs/marketplace-oauth-authorization
 *   - Search:  https://developers.pipedrive.com/docs/api/v1/ItemSearch
 */
class PipedriveClient
{
    private const AUTHORIZE_URL = 'https://oauth.pipedrive.com/oauth/authorize';
    private const TOKEN_URL     = 'https://oauth.pipedrive.com/oauth/token';
    private const DEFAULT_API   = 'https://api.pipedrive.com';
    private const TIMEOUT       = 15;

    /**
     * Pipedrive scopes mirror the resources we read. `base` is implicit.
     */
    private const SCOPES = [
        'contacts:read',
        'deals:read',
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
            'client_id'     => (string) config('services.pipedrive.client_id'),
            'redirect_uri'  => (string) config('services.pipedrive.redirect_uri'),
            'scope'         => implode(' ', self::SCOPES),
            'state'         => $state,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, api_domain: ?string}|array{}
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->postToken([
            'grant_type'   => 'authorization_code',
            'redirect_uri' => (string) config('services.pipedrive.redirect_uri'),
            'code'         => $code,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, api_domain: ?string}|array{}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->postToken([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function searchContacts(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        return $this->itemSearch($accessToken, $apiDomain, $query, $limit, 'person');
    }

    public function searchCompanies(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        // Pipedrive calls "company" objects "organizations".
        return $this->itemSearch($accessToken, $apiDomain, $query, $limit, 'organization');
    }

    public function searchDeals(string $accessToken, string $query, int $limit = 10, ?string $apiDomain = null): array
    {
        return $this->itemSearch($accessToken, $apiDomain, $query, $limit, 'deal');
    }

    /**
     * Shared GET to /v1/itemSearch. Returns the raw items array, each item
     * has `result_score` + `item` (with id, name, and item-type-specific fields),
     * or `[]` on failure.
     */
    private function itemSearch(string $accessToken, ?string $apiDomain, string $query, int $limit, string $itemType): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $base = $this->resolveApiDomain($apiDomain);

        try {
            $res = $this->http->get($base.'/v1/itemSearch', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                ],
                'query' => [
                    'term'       => $term,
                    'item_types' => $itemType,
                    'limit'      => max(1, min(500, $limit)),
                ],
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("Pipedrive itemSearch {$itemType} returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return $body['data']['items'] ?? [];
        } catch (Throwable $e) {
            Log::error("Pipedrive itemSearch {$itemType} failed: ".$e->getMessage());
            return [];
        }
    }

    private function postToken(array $form): array
    {
        try {
            $res = $this->http->post(self::TOKEN_URL, [
                'headers'     => [
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    // Pipedrive token endpoint uses HTTP Basic with client creds.
                    'Authorization' => 'Basic '.base64_encode(
                        config('services.pipedrive.client_id').':'.
                        config('services.pipedrive.client_secret')
                    ),
                ],
                'form_params' => $form,
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("Pipedrive token endpoint returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return [
                'access_token'  => (string) ($body['access_token']  ?? ''),
                'refresh_token' => (string) ($body['refresh_token'] ?? ''),
                'expires_in'    => (int)    ($body['expires_in']    ?? 3600),
                'api_domain'    => isset($body['api_domain']) ? (string) $body['api_domain'] : null,
            ];
        } catch (Throwable $e) {
            Log::error('Pipedrive token exchange failed: '.$e->getMessage());
            return [];
        }
    }

    private function resolveApiDomain(?string $apiDomain): string
    {
        if ($apiDomain !== null && $apiDomain !== '') {
            return rtrim($apiDomain, '/');
        }
        return self::DEFAULT_API;
    }
}
