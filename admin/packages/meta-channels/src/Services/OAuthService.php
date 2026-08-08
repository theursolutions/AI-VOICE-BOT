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
     * Exchange an Embedded Signup code for a token.
     *
     * Differs from exchangeCode() in one detail that costs an afternoon if
     * you miss it: the Embedded Signup popup never used a redirect_uri, so
     * sending one here makes Meta reject the exchange.
     */
    public function exchangeEmbeddedSignupCode(string $code): string
    {
        $data = $this->get('oauth/access_token', [
            'client_id'     => $this->cfg['id'],
            'client_secret' => $this->cfg['secret'],
            'code'          => $code,
        ]);

        $token = $data['access_token'] ?? null;
        if (! $token) {
            throw new \RuntimeException('No access_token in the Embedded Signup token exchange.');
        }

        return $token;
    }

    /**
     * Fetch exactly the WhatsApp Business Account (and optionally the one
     * number) the customer chose in Embedded Signup.
     *
     * The popup tells the browser which WABA and phone number were picked,
     * so this replaces the businesses → WABAs → numbers crawl that
     * discoverWhatsApp() has to do: three round trips become one, and a
     * customer with several businesses gets the one they actually selected
     * rather than everything the token can see.
     *
     * @return array<int, array{provider:string, external_id:string, name:string, access_token:?string, metadata:array}>
     */
    public function discoverWhatsAppByIds(string $wabaId, ?string $phoneNumberId, string $token): array
    {
        $phones = $this->get("{$wabaId}/phone_numbers", [
            'fields'       => 'id,display_phone_number,verified_name,quality_rating,code_verification_status',
            'access_token' => $token,
            'limit'        => 50,
        ])['data'] ?? [];

        $out = [];
        foreach ($phones as $p) {
            if (empty($p['id'])) {
                continue;
            }
            // Honour the customer's pick when the popup named one.
            if ($phoneNumberId && (string) $p['id'] !== (string) $phoneNumberId) {
                continue;
            }
            $out[] = [
                'provider'     => ChannelConnection::PROVIDER_WHATSAPP,
                'external_id'  => (string) $p['id'],
                'name'         => $p['verified_name'] ?? $p['display_phone_number'] ?? 'WhatsApp',
                'access_token' => $token,
                'metadata'     => [
                    'waba_id'             => $wabaId,
                    'display_phone_number' => $p['display_phone_number'] ?? null,
                    'quality_rating'      => $p['quality_rating'] ?? null,
                    'verification_status' => $p['code_verification_status'] ?? null,
                ],
            ];
        }

        if (! $out) {
            throw new \RuntimeException($phoneNumberId
                ? "The selected number ({$phoneNumberId}) is not on WhatsApp Business Account {$wabaId}."
                : "No phone numbers found on WhatsApp Business Account {$wabaId}.");
        }

        return $out;
    }

    /**
     * Subscribe our app to a WABA's webhooks.
     *
     * Without this the connection looks perfectly healthy and simply never
     * receives a message — the single most common "it connected but nothing
     * happens" support ticket in WhatsApp onboarding.
     */
    public function subscribeAppToWaba(string $wabaId, string $token): void
    {
        $this->post("{$wabaId}/subscribed_apps", ['access_token' => $token]);
    }

    /**
     * Register a number for Cloud API messaging.
     *
     * The PIN is the number's two-step verification code. Registration is
     * what actually moves a number onto Cloud API; skipping it leaves the
     * number visible in Graph but unable to send.
     */
    public function registerPhoneNumber(string $phoneNumberId, string $pin, string $token): void
    {
        $this->post("{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin'               => $pin,
            'access_token'      => $token,
        ]);
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

    /** POST against the Graph API; throws with a readable message on error. */
    private function post(string $path, array $form): array
    {
        $url = rtrim($this->cfg['graph_base'], '/') . '/' . $this->cfg['graph_version'] . '/' . ltrim($path, '/');
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);

        $resp = $client->post($url, ['form_params' => $form]);
        $json = json_decode((string) $resp->getBody(), true);

        if ($resp->getStatusCode() >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException("Graph {$path}: {$msg}");
        }

        return is_array($json) ? $json : [];
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
