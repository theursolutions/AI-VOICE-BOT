<?php

namespace Msd\MetaChannels\Services;

use GuzzleHttp\Client;
use Msd\MetaChannels\Models\ChannelConnection;

/**
 * Instagram API with Instagram Login.
 *
 * The sibling of OAuthService, kept separate rather than folded into it
 * because the two flows share no host, no credential and no scope name —
 * see the comment block in config/meta.php. Merging them produced a class
 * where every method was a conditional, and the failure mode of getting the
 * condition wrong is an opaque "Invalid platform app" from Meta.
 *
 * The flow, end to end:
 *
 *   1. authUrl()      → instagram.com consent screen
 *   2. exchangeCode() → api.instagram.com, gives a 1-hour token + user_id
 *   3. longLived()    → graph.instagram.com, trades it for a 60-day token
 *   4. discover()     → the account's own profile (exactly one channel)
 *   5. subscribe()    → tells Meta to deliver this account's messages to us
 *
 * Step 5 is the one that is silently fatal if skipped: everything looks
 * connected and no message ever arrives, with no error anywhere, because
 * nothing failed — we simply never asked to be told.
 */
class InstagramLoginService
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('meta.instagram', []);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->cfg['app_id']) && ! empty($this->cfg['app_secret']);
    }

    /** Scopes we ask for, as an array. */
    public function scopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->cfg['scopes'] ?? '')),
        )));
    }

    // ── 1. Consent ───────────────────────────────────────────────────

    /**
     * The Instagram consent URL.
     *
     * Note this is instagram.com, not facebook.com — sending the user to the
     * Facebook dialog with an Instagram app id returns a bare "Invalid
     * platform app" page with no indication of what is wrong.
     */
    public function authUrl(string $redirectUri, string $state): string
    {
        return rtrim($this->cfg['authorize_base'], '/') . '/oauth/authorize?' . http_build_query([
            'client_id'     => $this->cfg['app_id'],
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(',', $this->scopes()),
            'state'         => $state,
        ]);
    }

    // ── 2. Code → short-lived token ──────────────────────────────────

    /**
     * Exchange the authorization code for a short-lived (1 hour) token.
     *
     * Two quirks worth knowing, both of which produce confusing errors:
     *
     *  - This is a POST to api.instagram.com, NOT a GET to graph.*. The
     *    Facebook-style GET returns 400 with no useful body.
     *  - Instagram appends `#_` to the code in the redirect. Left in place
     *    the exchange fails with "Invalid authorization code", so it is
     *    stripped here rather than at every call site.
     *
     * @return array{token:string, user_id:string, permissions:array}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $data = $this->request('POST', rtrim($this->cfg['api_base'], '/') . '/oauth/access_token', [
            'form_params' => [
                'client_id'     => $this->cfg['app_id'],
                'client_secret' => $this->cfg['app_secret'],
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
                'code'          => preg_replace('/#_$/', '', $code),
            ],
        ]);

        // Meta documents this response wrapped — {"data":[{access_token,…}]} —
        // but is returning it flat in practice. Both shapes are accepted
        // rather than betting on either: a wrapper appearing (or vanishing)
        // would otherwise break onboarding with "returned no access_token"
        // while Meta was in fact returning one.
        $node = isset($data['data'][0]) && is_array($data['data'][0])
            ? $data['data'][0]
            : $data;

        if (empty($node['access_token'])) {
            throw new \RuntimeException('Instagram returned no access_token from the code exchange.');
        }

        // `permissions` comes back as an array on newer versions and a
        // comma-joined string on older ones.
        $perms = $node['permissions'] ?? [];
        if (is_string($perms)) {
            $perms = array_values(array_filter(array_map('trim', explode(',', $perms))));
        }

        return [
            'token'       => (string) $node['access_token'],
            'user_id'     => (string) ($node['user_id'] ?? ''),
            'permissions' => (array) $perms,
        ];
    }

    // ── 3. Short-lived → long-lived ──────────────────────────────────

    /**
     * Trade the 1-hour token for a 60-day one.
     *
     * Skipping this is not a subtle bug: the connection works perfectly for
     * an hour, and every customer who connects in the morning is broken by
     * lunchtime with no error anyone sees until a reply fails to send.
     *
     * @return array{token:string, expires_at:?\Illuminate\Support\Carbon}
     */
    public function longLived(string $shortLived): array
    {
        // Meta documents this as an UNVERSIONED GET on graph.instagram.com,
        // and that is tried first. The versioned path is tried second because
        // the documented one returns "Unsupported request - method type: get"
        // on some apps — Meta's router treats an unrecognised root path as a
        // node lookup, which is why the error talks about the method rather
        // than the path. Trying both costs one extra request on the failure
        // path and nothing on the happy one.
        $data = $this->tokenRequest('access_token', [
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => $this->cfg['app_secret'],
            'access_token'  => $shortLived,
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Instagram long-lived token exchange returned no access_token.');
        }

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;

        return [
            'token'      => (string) $data['access_token'],
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
        ];
    }

    /**
     * Refresh a long-lived token for another 60 days.
     *
     * Only works on a token that is at least 24 hours old and not yet
     * expired — an expired one cannot be refreshed and the customer has to
     * reconnect, which is why the refresh sweep runs well before the
     * deadline rather than on it.
     *
     * @return array{token:string, expires_at:?\Illuminate\Support\Carbon}
     */
    public function refresh(string $longLived): array
    {
        $data = $this->tokenRequest('refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $longLived,
        ]);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('Instagram token refresh returned no access_token.');
        }

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : null;

        return [
            'token'      => (string) $data['access_token'],
            'expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
        ];
    }

    // ── 4. Discovery ─────────────────────────────────────────────────

    /**
     * The connected account, in the shape OnboardingService imports.
     *
     * Always exactly one channel — Instagram Login authorises a single
     * business account, unlike Facebook Login which can return a whole list
     * of Pages.
     *
     * @return array<int, array{provider:string, external_id:string, name:string, access_token:?string, metadata:array}>
     */
    public function discover(string $token, ?string $userId = null): array
    {
        $me = $this->request('GET', $this->graph('me'), [
            'query' => [
                'fields'       => 'user_id,username,name,profile_picture_url,account_type,followers_count',
                'access_token' => $token,
            ],
        ]);

        // `user_id` is the IGSID the webhook addresses; `id` is the app-scoped
        // id, which is NOT what arrives in entry[].id. Getting these the wrong
        // way round means inbound messages resolve to no connection at all.
        $igId = (string) ($me['user_id'] ?? $userId ?? '');
        if ($igId === '') {
            throw new \RuntimeException('Instagram did not return a user_id for the connected account.');
        }

        $username = $me['username'] ?? null;

        return [[
            'provider'     => ChannelConnection::PROVIDER_INSTAGRAM,
            'external_id'  => $igId,
            'name'         => $username ? '@' . $username : ($me['name'] ?? 'Instagram'),
            'access_token' => $token,
            'metadata'     => array_filter([
                // The discriminator every downstream caller keys off to know
                // it must talk to graph.instagram.com rather than
                // graph.facebook.com. Without it a connection onboarded this
                // way looks identical to a Facebook-Login one and every send
                // fails with an unhelpful OAuth error.
                'login'          => 'instagram',
                'username'       => $username,
                'name'           => $me['name'] ?? null,
                'profile_pic'    => $me['profile_picture_url'] ?? null,
                'account_type'   => $me['account_type'] ?? null,
                'followers'      => $me['followers_count'] ?? null,
            ], fn ($v) => $v !== null),
        ]];
    }

    // ── 5. Webhook subscription ──────────────────────────────────────

    /**
     * Subscribe our app to this account's webhooks.
     *
     * Instagram Login subscribes the IG account directly. (The Facebook-Login
     * path subscribes the linked Page instead — see
     * OAuthService::subscribeAppToPage.)
     */
    public function subscribe(string $igUserId, string $token, ?array $fields = null): void
    {
        $fields ??= array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->cfg['webhook_fields'] ?? 'messages')),
        )));

        $this->request('POST', $this->graph("{$igUserId}/subscribed_apps"), [
            'form_params' => [
                'subscribed_fields' => implode(',', $fields),
                'access_token'      => $token,
            ],
        ]);
    }

    /**
     * What we are currently subscribed to — the direct answer to "why isn't
     * this account delivering messages?".
     *
     * @return array<int,string>
     */
    public function subscribedFields(string $igUserId, string $token): array
    {
        $data = $this->request('GET', $this->graph("{$igUserId}/subscribed_apps"), [
            'query' => ['access_token' => $token],
        ])['data'] ?? [];

        foreach ($data as $app) {
            $fields = $app['subscribed_fields'] ?? null;
            if ($fields !== null) {
                // Sometimes objects ({name:…}), sometimes bare strings.
                return array_map(
                    fn ($f) => is_array($f) ? (string) ($f['name'] ?? '') : (string) $f,
                    (array) $fields,
                );
            }
        }

        return [];
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * A token endpoint call, tried unversioned then versioned.
     *
     * Both are GETs to graph.instagram.com and differ only in whether the
     * path carries an API version. Meta documents the unversioned form, but
     * returns "Unsupported request - method type: get" for it on some apps —
     * a message about the METHOD when the actual problem is the PATH, which
     * is why the first failure here was so hard to place.
     *
     * The failure of the second attempt carries both URLs, so the next person
     * to hit this can see exactly what was tried.
     */
    private function tokenRequest(string $path, array $query): array
    {
        $unversioned = $this->graph($path, false);

        try {
            return $this->request('GET', $unversioned, ['query' => $query]);
        } catch (\Throwable $first) {
            $versioned = $this->graph($path, true);

            try {
                return $this->request('GET', $versioned, ['query' => $query]);
            } catch (\Throwable $second) {
                throw new \RuntimeException(
                    $first->getMessage()
                    . ' [tried ' . $unversioned . ' and ' . $versioned . ']'
                );
            }
        }
    }

    /** Build a graph.instagram.com URL, versioned unless told otherwise. */
    private function graph(string $path, bool $versioned = true): string
    {
        $base = rtrim($this->cfg['graph_base'], '/');
        // The token endpoints are unversioned and 404 if a version is added.
        $prefix = $versioned ? '/' . $this->cfg['graph_version'] : '';

        return $base . $prefix . '/' . ltrim($path, '/');
    }

    /** One request, with Meta's error message surfaced verbatim. */
    private function request(string $method, string $url, array $options): array
    {
        $client = new Client(['timeout' => 20, 'connect_timeout' => 8, 'http_errors' => false]);
        $resp   = $client->request($method, $url, $options);
        $body   = (string) $resp->getBody();
        $json   = json_decode($body, true);

        if ($resp->getStatusCode() >= 400) {
            // Instagram uses two error shapes depending on the host:
            // api.instagram.com returns {error_message}, graph.instagram.com
            // returns Facebook's {error:{message}}.
            $msg = $json['error']['message']
                ?? $json['error_message']
                ?? ('HTTP ' . $resp->getStatusCode());

            // The path is part of the diagnosis, not noise. Meta's messages
            // routinely describe the wrong thing — "Unsupported request -
            // method type: get" is really "I do not recognise this path" —
            // and without the URL there is nothing to check it against.
            throw new \RuntimeException(
                'Instagram: ' . $msg . ' (' . $method . ' ' . strtok($url, '?') . ')'
            );
        }

        return is_array($json) ? $json : [];
    }
}
