<?php

namespace Msd\MetaChannels\Services;

use GuzzleHttp\Client;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Facebook Login / OAuth onboarding: build the consent URL, exchange the
 * returned code for a user token, then discover the pages / IG accounts /
 * WhatsApp numbers the user granted — ready to import as ChannelConnections.
 *
 * All discovery methods throw RuntimeException with a clear message on
 * failure so the caller can record exactly which step broke.
 */
class OAuthService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('meta.app');
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['id']) && !empty($this->cfg['secret']);
    }

    /** Build the Facebook OAuth consent URL for a provider. */
    public function authUrl(string $provider, string $redirectUri, string $state): string
    {
        $scope = $this->cfg['scopes'][$provider] ?? 'public_profile';
        $base  = rtrim($this->cfg['login_base'], '/') . '/' . $this->cfg['graph_version'] . '/dialog/oauth';
        return $base . '?' . http_build_query([
            'client_id'     => $this->cfg['id'],
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'response_type' => 'code',
            'scope'         => $scope,
        ]);
    }

    /** Exchange the OAuth code for a user access token. */
    public function exchangeCode(string $code, string $redirectUri): string
    {
        $data = $this->get('oauth/access_token', [
            'client_id'     => $this->cfg['id'],
            'client_secret' => $this->cfg['secret'],
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);
        $token = $data['access_token'] ?? null;
        if (!$token) {
            throw new \RuntimeException('No access_token in token-exchange response.');
        }
        return $token;
    }

    /**
     * Discover the channels the user granted for a provider.
     *
     * @return array<int, array{provider:string, external_id:string, name:string, access_token:?string, metadata:array}>
     */
    public function discover(string $provider, string $userToken): array
    {
        return match ($provider) {
            ChannelConnection::PROVIDER_FACEBOOK_PAGE => $this->discoverPages($userToken),
            ChannelConnection::PROVIDER_INSTAGRAM     => $this->discoverInstagram($userToken),
            ChannelConnection::PROVIDER_WHATSAPP      => $this->discoverWhatsApp($userToken),
            default => throw new \RuntimeException("Unsupported provider: {$provider}"),
        };
    }

    private function discoverPages(string $token): array
    {
        $data = $this->get('me/accounts', ['fields' => 'id,name,access_token', 'access_token' => $token, 'limit' => 100]);
        $out = [];
        foreach ($data['data'] ?? [] as $page) {
            if (empty($page['id'])) continue;
            $out[] = [
                'provider'     => ChannelConnection::PROVIDER_FACEBOOK_PAGE,
                'external_id'  => (string) $page['id'],
                'name'         => $page['name'] ?? ('Page ' . $page['id']),
                'access_token' => $page['access_token'] ?? null,   // page token (used for sending)
                'metadata'     => [],
            ];
        }
        if (!$out) {
            throw new \RuntimeException('No Facebook pages were granted.');
        }
        return $out;
    }

    private function discoverInstagram(string $token): array
    {
        $data = $this->get('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $token, 'limit' => 100,
        ]);
        $out = [];
        foreach ($data['data'] ?? [] as $page) {
            $ig = $page['instagram_business_account'] ?? null;
            if (!$ig || empty($ig['id'])) continue;
            $out[] = [
                'provider'     => ChannelConnection::PROVIDER_INSTAGRAM,
                'external_id'  => (string) $ig['id'],
                'name'         => ($ig['username'] ?? $page['name'] ?? 'Instagram'),
                'access_token' => $page['access_token'] ?? null,   // the linked page token
                'metadata'     => ['page_id' => $page['id'] ?? null],
            ];
        }
        if (!$out) {
            throw new \RuntimeException('No Instagram business accounts linked to the granted pages.');
        }
        return $out;
    }

    private function discoverWhatsApp(string $token): array
    {
        $out = [];
        $businesses = $this->get('me/businesses', ['access_token' => $token, 'limit' => 50])['data'] ?? [];
        foreach ($businesses as $biz) {
            $wabas = $this->get("{$biz['id']}/owned_whatsapp_business_accounts", ['access_token' => $token, 'limit' => 50])['data'] ?? [];
            foreach ($wabas as $waba) {
                $phones = $this->get("{$waba['id']}/phone_numbers", ['fields' => 'id,display_phone_number,verified_name', 'access_token' => $token, 'limit' => 50])['data'] ?? [];
                foreach ($phones as $p) {
                    if (empty($p['id'])) continue;
                    $out[] = [
                        'provider'     => ChannelConnection::PROVIDER_WHATSAPP,
                        'external_id'  => (string) $p['id'],   // phone_number_id
                        'name'         => $p['verified_name'] ?? $p['display_phone_number'] ?? 'WhatsApp',
                        'access_token' => $token,              // see note in controller re: system token
                        'metadata'     => ['waba_id' => $waba['id'], 'business_id' => $biz['id'] ?? null],
                    ];
                }
            }
        }
        if (!$out) {
            throw new \RuntimeException('No WhatsApp numbers found on the granted business accounts.');
        }
        return $out;
    }

    /** GET against the Graph API; throws with a readable message on error. */
    private function get(string $path, array $query): array
    {
        $url = rtrim($this->cfg['graph_base'], '/') . '/' . $this->cfg['graph_version'] . '/' . ltrim($path, '/');
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp = $client->get($url, ['query' => $query]);
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);
        if ($resp->getStatusCode() >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException("Graph {$path}: {$msg}");
        }
        return is_array($json) ? $json : [];
    }
}
