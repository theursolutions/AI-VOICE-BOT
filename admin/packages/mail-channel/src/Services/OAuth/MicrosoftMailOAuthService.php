<?php

namespace Msd\MailChannel\Services\OAuth;

use GuzzleHttp\Client;

/**
 * "Connect Microsoft" for the email channel — Outlook.com and Microsoft 365.
 *
 * This is the path that actually matters: Microsoft has deprecated Basic
 * Auth (a plain password) for IMAP/SMTP on these mailboxes, so for most
 * Outlook accounts this OAuth flow is the ONLY way to connect at all — the
 * manual SMTP/IMAP form on the Channels page will simply fail to
 * authenticate against them.
 *
 * Same shape as GoogleMailOAuthService, with one difference worth knowing:
 * Microsoft ROTATES the refresh token on every use, so refresh() always
 * returns a new one and the caller must persist it — reusing an old refresh
 * token after a rotation eventually gets it revoked.
 */
class MicrosoftMailOAuthService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('mail_channel.microsoft', []);
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['client_id']) && !empty($this->cfg['client_secret']);
    }

    public function authUrl(string $redirectUri, string $state): string
    {
        return rtrim($this->cfg['authority'], '/') . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'     => $this->cfg['client_id'],
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'scope'         => $this->cfg['scopes'],
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
            'scope'         => $this->cfg['scopes'],
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Microsoft returned no access_token from the code exchange.');
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
     * Always returns a fresh refresh_token — see the class docblock.
     */
    public function refresh(string $refreshToken): array
    {
        $data = $this->request([
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
            'scope'         => $this->cfg['scopes'],
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Microsoft token refresh returned no access_token — the mailbox likely needs to be reconnected.');
        }

        return [
            'access_token'  => (string) $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => isset($data['expires_in']) ? (int) $data['expires_in'] : null,
        ];
    }

    /** The connected mailbox's own address, via Microsoft Graph. */
    public function discoverEmail(string $accessToken): string
    {
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp   = $client->get($this->cfg['graph_userinfo_url'], [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);
        $json = json_decode((string) $resp->getBody(), true);

        // A work/school mailbox usually has `mail`; a personal Microsoft
        // account signing in without a licensed mailbox often has that field
        // null and only `userPrincipalName` set — both are valid addresses.
        $email = $json['mail'] ?? $json['userPrincipalName'] ?? null;

        if ($resp->getStatusCode() >= 400 || !$email) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->getStatusCode());
            throw new \RuntimeException('Microsoft Graph /me: ' . $msg);
        }

        return (string) $email;
    }

    /** POST against Microsoft's token endpoint; throws with a readable message on error. */
    private function request(array $form): array
    {
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp   = $client->post(rtrim($this->cfg['authority'], '/') . '/oauth2/v2.0/token', ['form_params' => $form]);
        $json   = json_decode((string) $resp->getBody(), true);

        if ($resp->getStatusCode() >= 400) {
            $msg = $json['error_description'] ?? ($json['error'] ?? ('HTTP ' . $resp->getStatusCode()));
            throw new \RuntimeException('Microsoft OAuth: ' . $msg);
        }

        return is_array($json) ? $json : [];
    }
}
