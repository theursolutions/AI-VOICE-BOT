<?php

namespace App\Services\CrmProviders;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin HTTP wrapper around Salesforce's OAuth + Parameterized Search API.
 *
 * Per-instance host: prod ($login_host = login.salesforce.com), sandbox
 * (test.salesforce.com). The token response includes `instance_url` —
 * we surface that so the caller can persist it in `config.instance_url`
 * and reuse it for subsequent /services/data/... calls.
 *
 * All public methods are crash-proof — on any Throwable / non-2xx we log
 * (without tokens) and return an empty/safe shape.
 *
 * Docs:
 *   - OAuth:   https://help.salesforce.com/s/articleView?id=sf.remoteaccess_oauth_web_server_flow.htm
 *   - Search:  https://developer.salesforce.com/docs/atlas.en-us.api_rest.meta/api_rest/resources_search_parameterized.htm
 */
class SalesforceClient
{
    private const AUTHORIZE_PATH = '/services/oauth2/authorize';
    private const TOKEN_PATH     = '/services/oauth2/token';
    private const API_VERSION    = 'v59.0';
    private const TIMEOUT        = 15;

    private const SCOPES = [
        'api',
        'refresh_token',
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
            'client_id'     => (string) config('services.salesforce.client_id'),
            'redirect_uri'  => (string) config('services.salesforce.redirect_uri'),
            'scope'         => implode(' ', self::SCOPES),
            'state'         => $state,
        ];

        return $this->loginHost().self::AUTHORIZE_PATH.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, instance_url: ?string}|array{}
     */
    public function exchangeCodeForToken(string $code): array
    {
        return $this->postToken([
            'grant_type'    => 'authorization_code',
            'client_id'     => (string) config('services.salesforce.client_id'),
            'client_secret' => (string) config('services.salesforce.client_secret'),
            'redirect_uri'  => (string) config('services.salesforce.redirect_uri'),
            'code'          => $code,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, instance_url: ?string}|array{}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->postToken([
            'grant_type'    => 'refresh_token',
            'client_id'     => (string) config('services.salesforce.client_id'),
            'client_secret' => (string) config('services.salesforce.client_secret'),
            'refresh_token' => $refreshToken,
        ]);
    }

    public function searchContacts(string $accessToken, string $query, int $limit = 10, ?string $instanceUrl = null): array
    {
        return $this->parameterizedSearch($accessToken, $instanceUrl, $query, $limit, 'Contact', [
            'Id', 'FirstName', 'LastName', 'Email', 'Phone', 'Account.Name',
        ]);
    }

    public function searchCompanies(string $accessToken, string $query, int $limit = 10, ?string $instanceUrl = null): array
    {
        return $this->parameterizedSearch($accessToken, $instanceUrl, $query, $limit, 'Account', [
            'Id', 'Name', 'Website', 'Phone', 'Industry', 'BillingCity', 'BillingCountry',
        ]);
    }

    public function searchDeals(string $accessToken, string $query, int $limit = 10, ?string $instanceUrl = null): array
    {
        return $this->parameterizedSearch($accessToken, $instanceUrl, $query, $limit, 'Opportunity', [
            'Id', 'Name', 'Amount', 'StageName', 'CloseDate', 'Account.Name',
        ]);
    }

    /**
     * Runs Salesforce's /parameterizedSearch/ scoped to a single sobject.
     * Returns the raw `searchRecords` array (each item has `attributes` + flat fields),
     * or `[]` on failure.
     */
    private function parameterizedSearch(string $accessToken, ?string $instanceUrl, string $query, int $limit, string $sobject, array $fields): array
    {
        $base = $this->resolveInstanceUrl($instanceUrl);
        if ($base === '') {
            Log::warning("Salesforce search {$sobject} skipped: no instance_url available");
            return [];
        }

        $term = trim($query);
        if ($term === '') {
            return [];
        }

        try {
            $res = $this->http->post($base.'/services/data/'.self::API_VERSION.'/parameterizedSearch/', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'q'        => $term,
                    'sobjects' => [[
                        'name'   => $sobject,
                        'fields' => $fields,
                    ]],
                    'fields'   => $fields,
                    'in'       => 'ALL',
                    'overallLimit' => max(1, min(200, $limit)),
                ],
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("Salesforce search {$sobject} returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return $body['searchRecords'] ?? [];
        } catch (Throwable $e) {
            Log::error("Salesforce search {$sobject} failed: ".$e->getMessage());
            return [];
        }
    }

    private function postToken(array $form): array
    {
        try {
            $res = $this->http->post($this->loginHost().self::TOKEN_PATH, [
                'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => $form,
            ]);

            $status = $res->getStatusCode();
            if ($status < 200 || $status >= 300) {
                Log::warning("Salesforce token endpoint returned HTTP {$status}");
                return [];
            }

            $body = json_decode((string) $res->getBody(), true) ?: [];
            return [
                'access_token'  => (string) ($body['access_token']  ?? ''),
                'refresh_token' => (string) ($body['refresh_token'] ?? ''),
                'expires_in'    => (int)    ($body['expires_in']    ?? 7200),
                'instance_url'  => isset($body['instance_url']) ? (string) $body['instance_url'] : null,
            ];
        } catch (Throwable $e) {
            Log::error('Salesforce token exchange failed: '.$e->getMessage());
            return [];
        }
    }

    private function loginHost(): string
    {
        $host = (string) config('services.salesforce.login_host', 'https://login.salesforce.com');
        return rtrim($host, '/');
    }

    private function resolveInstanceUrl(?string $instanceUrl): string
    {
        return $instanceUrl !== null ? rtrim($instanceUrl, '/') : '';
    }
}
