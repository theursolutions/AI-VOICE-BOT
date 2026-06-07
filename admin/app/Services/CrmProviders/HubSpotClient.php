<?php

namespace App\Services\CrmProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper around HubSpot's OAuth + CRM Search API.
 *
 * All public methods are crash-proof — on any Throwable / non-2xx we log,
 * scrub tokens, and return an empty/safe shape so the caller can continue.
 *
 * Docs:
 *   - OAuth:   https://developers.hubspot.com/docs/api/oauth-quickstart-guide
 *   - Search:  https://developers.hubspot.com/docs/api/crm/search
 */
class HubSpotClient
{
    private const AUTHORIZE_URL = 'https://app.hubspot.com/oauth/authorize';
    private const TOKEN_URL     = 'https://api.hubapi.com/oauth/v1/token';
    private const API_BASE      = 'https://api.hubapi.com/';
    private const TIMEOUT       = 15;

    private const SCOPES = [
        'crm.objects.contacts.read',
        'crm.objects.companies.read',
        'crm.objects.deals.read',
        'oauth',
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

    /**
     * Build the authorize URL the user gets redirected to.
     * `state` is the CSRF token we minted server-side.
     */
    public function authorizationUrl(string $state): string
    {
        $params = [
            'client_id'    => (string) config('services.hubspot.client_id'),
            'redirect_uri' => (string) config('services.hubspot.redirect_uri'),
            'scope'        => implode(' ', self::SCOPES),
            'state'        => $state,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query($params);
    }

    /**
     * Trade the ?code= from the callback for an access+refresh token pair.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, hub_id: ?int}|array{}
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->postToken([
            'grant_type'    => 'authorization_code',
            'client_id'     => (string) config('services.hubspot.client_id'),
            'client_secret' => (string) config('services.hubspot.client_secret'),
            'redirect_uri'  => (string) config('services.hubspot.redirect_uri'),
            'code'          => $code,
        ]);
    }

    /**
     * Rotate a stale access token using its refresh token.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, hub_id: ?int}|array{}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->postToken([
            'grant_type'    => 'refresh_token',
            'client_id'     => (string) config('services.hubspot.client_id'),
            'client_secret' => (string) config('services.hubspot.client_secret'),
            'refresh_token' => $refreshToken,
        ]);
    }

    public function searchContacts(string $accessToken, string $query, int $limit = 10): array
    {
        return $this->search($accessToken, 'contacts', $query, $limit, [
            'firstname', 'lastname', 'email', 'phone', 'company', 'lifecyclestage',
        ]);
    }

    public function searchCompanies(string $accessToken, string $query, int $limit = 10): array
    {
        return $this->search($accessToken, 'companies', $query, $limit, [
            'name', 'domain', 'phone', 'industry', 'city', 'country',
        ]);
    }

    public function searchDeals(string $accessToken, string $query, int $limit = 10): array
    {
        return $this->search($accessToken, 'deals', $query, $limit, [
            'dealname', 'amount', 'dealstage', 'pipeline', 'closedate',
        ]);
    }

    /**
     * Shared CRM search call. Returns the raw `results` array (each item has
     * `id` and `properties`), or `[]` on failure.
     */
    private function search(string $accessToken, string $object, string $query, int $limit, array $properties): array
    {
        try {
            $res = $this->http->post(self::API_BASE."crm/v3/objects/{$object}/search", [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'query'      => $query,
                    'limit'      => max(1, min(100, $limit)),
                    'properties' => $properties,
                ],
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("HubSpot search {$object} returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return $body['results'] ?? [];
        } catch (Throwable $e) {
            Log::error("HubSpot search {$object} failed: ".$e->getMessage());
            return [];
        }
    }

    /**
     * POST to the OAuth token endpoint (form-urlencoded).
     */
    private function postToken(array $form): array
    {
        try {
            $res = $this->http->post(self::TOKEN_URL, [
                'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => $form,
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("HubSpot token endpoint returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return [
                'access_token'  => (string) ($body['access_token']  ?? ''),
                'refresh_token' => (string) ($body['refresh_token'] ?? ''),
                'expires_in'    => (int)    ($body['expires_in']    ?? 0),
                'hub_id'        => isset($body['hub_id']) ? (int) $body['hub_id'] : null,
            ];
        } catch (Throwable $e) {
            Log::error('HubSpot token exchange failed: '.$e->getMessage());
            return [];
        }
    }
}
