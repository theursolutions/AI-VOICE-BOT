<?php

namespace Msd\MailChannel\Services\OAuth;

use GuzzleHttp\Client;

/**
 * "Connect Google" for the email channel — a mailbox-access OAuth app,
 * deliberately separate from any dashboard "log in with Google". Same shape
 * as Msd\MetaChannels\Services\OAuthService: authUrl/exchangeCode/refresh
 * plus a discovery call, each throwing a readable RuntimeException on
 * failure so the controller can surface exactly what broke.
 */
class GoogleMailOAuthService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('mail_channel.google', []);
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['client_id']) && !empty($this->cfg['client_secret']);
    }

    /**
     * access_type=offline + prompt=consent is what guarantees a
     * refresh_token comes back — without prompt=consent, Google only sends
     * one the very first time an account grants this app, and every
     * reconnect after that silently returns none.
     */
    public function authUrl(string $redirectUri, string $state): string
    {
        return $this->cfg['authorize_base'] . '?' . http_build_query([
            'client_id'     => $this->cfg['client_id'],
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => $this->cfg['scopes'],
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /** @return array{access_token:string, refresh_token:?string, expires_in:?int} */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $data = $this->request([
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Google returned no access_token from the code exchange.');
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        ];
    }

    /**
     * @return array{access_token:string, refresh_token:?string, expires_in:?int}
     *
     * Google normally does NOT return a refresh_token here — the caller
     * (OAuthTokenBroker) must keep the existing one when this comes back null.
     */
    public function refresh(string $refreshToken): array
    {
        $data = $this->request([
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Google token refresh returned no access_token — the mailbox likely needs to be reconnected.');
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        ];
    }

    /** The connected mailbox's own address. */
    public function discoverEmail(string $accessToken): string
    {
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp   = $client->get($this->cfg['userinfo_url'], [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        $json = json_decode((string) $resp->getBody(), true);

        if ($resp->getStatusCode() >= 400 || empty($json['email'])) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException('Google userinfo: ' . $msg);
        }

        return (string) $json['email'];
    }

    /** POST against Google's token endpoint; throws with a readable message on error. */
    private function request(array $form): array
    {
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp   = $client->post($this->cfg['token_url'], ['form_params' => $form]);
        $json   = json_decode((string) $resp->getBody(), true);

        if ($resp->getStatusCode() >= 400) {
            $msg = $json['error_description'] ?? ($json['error'] ?? ('HTTP ' . $resp->getStatusCode()));
            throw new \RuntimeException('Google OAuth: ' . $msg);
        }

        return is_array($json) ? $json : [];
    }
}
