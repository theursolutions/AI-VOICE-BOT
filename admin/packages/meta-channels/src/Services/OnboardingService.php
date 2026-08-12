<?php

namespace Msd\MetaChannels\Services;

use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Msd\MetaChannels\Models\ChannelOnboardingPayload;

/**
 * The onboarding pipeline, written so every stage is durable and the whole
 * thing can be resumed from wherever it broke.
 *
 * The shape that matters: PERSIST FIRST, THEN PROCESS. The moment anything
 * arrives from Meta it is written to a ChannelOnboardingPayload, before we
 * attempt a single thing with it. Everything after that point is our own
 * infrastructure — Graph calls, database writes — and all of it can be
 * replayed from that row without the customer ever seeing Facebook again.
 *
 *      received ──▶ tokenized ──▶ discovered ──▶ imported
 *          │            │              │
 *          └────────────┴──────────────┴──▶ failed (resume from last rung)
 *
 * Each ensure*() method is idempotent: it returns immediately if its rung
 * is already climbed. That is what makes retry() safe to press repeatedly —
 * a retry after a failed import does not re-exchange tokens, and a retry
 * cannot produce duplicate connections.
 */
class OnboardingService
{
    /** Failure classes the UI turns into an actionable message. */
    public const ERR_NOT_CONFIGURED = 'not_configured';
    public const ERR_CONSENT_DENIED = 'consent_denied';
    public const ERR_CODE_EXCHANGE  = 'code_exchange_failed';
    public const ERR_TOKEN_EXCHANGE = 'token_exchange_failed';
    public const ERR_MISSING_SCOPES = 'missing_scopes';
    public const ERR_NO_CHANNELS    = 'no_channels';
    public const ERR_GRAPH          = 'graph_error';
    public const ERR_IMPORT         = 'import_failed';
    public const ERR_EXPIRED        = 'payload_expired';

    public function __construct(
        private OAuthService $oauth,
        private TokenService $tokens,
    ) {}

    // ── Intake ───────────────────────────────────────────────────────

    /**
     * Store what came back from the OAuth redirect. Nothing is attempted
     * here beyond writing the row — if the process below explodes, this
     * record is already safely on disk.
     */
    public function ingestCode(
        int $projectId,
        ?int $userId,
        string $provider,
        string $code,
        string $redirectUri,
        ChannelOnboardingLog $log,
        string $method = ChannelOnboardingPayload::METHOD_REDIRECT,
        array $extra = [],
    ): ChannelOnboardingPayload {
        $payload = ChannelOnboardingPayload::create([
            'project_id'      => $projectId,
            'user_id'         => $userId,
            'log_id'          => $log->id,
            'provider'        => $provider,
            'method'          => $method,
            'auth_code'       => $code,
            'redirect_uri'    => $redirectUri,
            'waba_id'         => $extra['waba_id'] ?? null,
            'phone_number_id' => $extra['phone_number_id'] ?? null,
            'status'          => ChannelOnboardingPayload::STATUS_RECEIVED,
        ]);

        $log->payload_id = $payload->id;
        $log->method     = $method;
        $log->save();
        $log->step('payload_stored', true, 'payload #' . $payload->id);

        return $payload;
    }

    /**
     * Intake for a flow that already holds a token (Embedded Signup can
     * return one directly). Same durability guarantee.
     */
    public function ingestToken(
        int $projectId,
        ?int $userId,
        string $provider,
        string $token,
        ChannelOnboardingLog $log,
        string $method,
        array $extra = [],
    ): ChannelOnboardingPayload {
        $payload = ChannelOnboardingPayload::create([
            'project_id'        => $projectId,
            'user_id'           => $userId,
            'log_id'            => $log->id,
            'provider'          => $provider,
            'method'            => $method,
            'short_lived_token' => $token,
            'waba_id'           => $extra['waba_id'] ?? null,
            'phone_number_id'   => $extra['phone_number_id'] ?? null,
            'status'            => ChannelOnboardingPayload::STATUS_RECEIVED,
        ]);

        $log->payload_id = $payload->id;
        $log->method     = $method;
        $log->save();
        $log->step('payload_stored', true, 'payload #' . $payload->id);

        return $payload;
    }

    // ── Pipeline ─────────────────────────────────────────────────────

    /**
     * Drive the payload as far as it will go. Safe to call on a fresh
     * payload or a half-finished one.
     *
     * @return array<int,string> names of the channels imported
     * @throws \RuntimeException with the failure already recorded on both
     *         the payload and the log
     */
    public function process(ChannelOnboardingPayload $payload, ChannelOnboardingLog $log): array
    {
        $this->ensureToken($payload, $log);
        $this->ensureDiscovery($payload, $log);

        return $this->ensureImport($payload, $log);
    }

    /**
     * received → tokenized. Exchanges the code if we still only have one,
     * then trades the short-lived token for a long-lived one.
     */
    protected function ensureToken(ChannelOnboardingPayload $payload, ChannelOnboardingLog $log): void
    {
        if ($payload->long_lived_token) {
            $log->step('token', true, 'already held (resumed)');
            return;
        }

        // Code → short-lived token, unless a flow handed us one directly.
        if (! $payload->short_lived_token) {
            if (! $payload->auth_code) {
                $this->abort($payload, $log, self::ERR_EXPIRED, 'No authorization code or token stored — the customer has to reconnect from Meta.');
            }
            try {
                $short = $this->oauth->exchangeCode($payload->auth_code, (string) $payload->redirect_uri);
            } catch (\Throwable $e) {
                // Codes are single-use and die in ~10 minutes; say so plainly
                // rather than surfacing "invalid code" and leaving the
                // operator to guess.
                $this->abort($payload, $log, self::ERR_CODE_EXCHANGE, 'Could not exchange the Meta authorization code (it is single-use and expires within minutes): ' . $e->getMessage());
            }
            $payload->short_lived_token = $short;
            $payload->save();
            $log->step('code_exchange', true);
        }

        // Short-lived → long-lived. This is the step that makes everything
        // downstream retryable, so it happens as early as possible.
        try {
            $long = $this->tokens->exchangeForLongLived($payload->short_lived_token);
        } catch (\Throwable $e) {
            $this->abort($payload, $log, self::ERR_TOKEN_EXCHANGE, 'Could not upgrade to a long-lived token: ' . $e->getMessage());
        }

        $inspect = $this->tokens->inspect($long['token']);

        $payload->long_lived_token = $long['token'];
        $payload->token_expires_at = $long['expires_at'] ?? $inspect['expires_at'];
        $payload->token_scopes     = $inspect['scopes'];
        // Retry stays available for as long as the credential itself lives.
        // No expiry recorded means a permanent token — cap the retry window
        // at 60 days anyway so stored secrets don't linger indefinitely.
        $payload->expires_at       = $payload->token_expires_at ?? now()->addDays(60);
        $payload->status           = ChannelOnboardingPayload::STATUS_TOKENIZED;
        $payload->save();

        $log->step('long_lived_token', true, $payload->token_expires_at
            ? 'expires ' . $payload->token_expires_at->toDateTimeString()
            : 'no expiry');

        // Users can untick individual permissions on the consent screen.
        // Catching it here names them, instead of failing later with an
        // opaque "(#200) Permissions error".
        $missing = array_values(array_diff(
            array_filter(explode(',', (string) (config('meta.app.scopes')[$payload->provider] ?? ''))),
            $inspect['scopes'],
        ));
        if ($missing) {
            $log->step('scopes', false, 'missing: ' . implode(', ', $missing));
            $this->abort($payload, $log, self::ERR_MISSING_SCOPES, 'These permissions were not granted: ' . implode(', ', $missing) . '. Reconnect and leave every permission ticked.');
        }
        $log->step('scopes', true, implode(', ', $inspect['scopes']));
    }

    /** tokenized → discovered. Asks Graph what was actually granted. */
    protected function ensureDiscovery(ChannelOnboardingPayload $payload, ChannelOnboardingLog $log): void
    {
        if (! empty($payload->discovery)) {
            $log->step('discover', true, count($payload->discovery) . ' channel(s) (resumed)');
            return;
        }

        try {
            // Embedded Signup already told us exactly which WABA and number
            // the customer picked, so fetch those directly instead of
            // crawling every business the token can see.
            $channels = ($payload->waba_id && $payload->provider === ChannelConnection::PROVIDER_WHATSAPP)
                ? $this->oauth->discoverWhatsAppByIds($payload->waba_id, $payload->phone_number_id, $payload->usableToken())
                : $this->oauth->discover($payload->provider, $payload->usableToken());
        } catch (\Throwable $e) {
            $this->abort($payload, $log, self::ERR_GRAPH, $e->getMessage());
        }

        if (! $channels) {
            $this->abort($payload, $log, self::ERR_NO_CHANNELS, 'Meta returned no ' . $payload->provider . ' accounts for this login. Check the right business account was selected.');
        }

        $payload->discovery = $channels;
        $payload->status    = ChannelOnboardingPayload::STATUS_DISCOVERED;
        $payload->save();

        $log->step('discover', true, count($channels) . ' channel(s)');
    }

    /** discovered → imported. Writes ChannelConnections, then purges secrets. */
    protected function ensureImport(ChannelOnboardingPayload $payload, ChannelOnboardingLog $log): array
    {
        $imported = [];

        try {
            foreach ((array) $payload->discovery as $ch) {
                // updateOrCreate on the unique (project, provider, external_id)
                // key: a retry re-runs this safely instead of duplicating.
                ChannelConnection::updateOrCreate(
                    [
                        'project_id'  => $payload->project_id,
                        'provider'    => $ch['provider'],
                        'external_id' => $ch['external_id'],
                    ],
                    [
                        'name'              => $ch['name'],
                        // Page/IG entries carry their own never-expiring page
                        // token; WhatsApp falls back to the long-lived user
                        // token we just minted.
                        'access_token'      => $ch['access_token'] ?: $payload->long_lived_token,
                        'short_lived_token' => $payload->short_lived_token,
                        'token_obtained_at' => now(),
                        'token_expires_at'  => $ch['access_token'] ? null : $payload->token_expires_at,
                        'token_scopes'      => $payload->token_scopes,
                        'status'            => ChannelConnection::STATUS_ENABLED,
                        'metadata'          => $ch['metadata'] ?? [],
                    ],
                );
                // Subscribe our app to this Page's messaging webhooks. Without
                // it the connection looks perfectly healthy and Meta never
                // delivers a message — there is no error anywhere, because
                // nothing failed; we just never asked to be told.
                //
                // Non-fatal on purpose: the connection is still usable for
                // sending, and `php artisan meta:subscribe` repairs it without
                // dragging the customer back through consent.
                $this->subscribePage($ch, $log);

                $imported[] = $ch['name'];
            }
        } catch (\Throwable $e) {
            Log::warning('Channel import failed', ['payload' => $payload->id, 'error' => $e->getMessage()]);
            $this->abort($payload, $log, self::ERR_IMPORT, 'Saving the connection failed: ' . $e->getMessage());
        }

        $payload->markImported();

        $log->step('import', true, $imported);
        $log->status     = ChannelOnboardingLog::STATUS_SUCCESS;
        $log->error      = null;
        $log->error_code = null;
        $log->result     = ['count' => count($imported), 'channels' => $imported];
        $log->save();

        return $imported;
    }

    // ── Retry ────────────────────────────────────────────────────────

    /**
     * Replay our side of a failed attempt. Opens a fresh log chained to the
     * original so the history of what was tried stays intact.
     *
     * @return array{log:ChannelOnboardingLog, imported:array}
     */
    public function retry(ChannelOnboardingPayload $payload, ?int $userId): array
    {
        if (! $payload->isRetryable()) {
            throw new \RuntimeException($payload->retryBlockedReason() ?? 'This attempt cannot be retried.');
        }

        $previous = ChannelOnboardingLog::find($payload->log_id);

        $log = ChannelOnboardingLog::create([
            'project_id'  => $payload->project_id,
            'user_id'     => $userId,
            'provider'    => $payload->provider,
            'method'      => $payload->method,
            'payload_id'  => $payload->id,
            'retry_of_id' => $previous?->id,
            'attempt'     => ($previous?->attempt ?? 1) + 1,
            'status'      => ChannelOnboardingLog::STATUS_STARTED,
        ]);
        $log->step('retry', true, 'resuming from "' . $payload->status . '" using stored credentials');

        $payload->attempts = $payload->attempts + 1;
        $payload->save();

        return ['log' => $log, 'imported' => $this->process($payload, $log)];
    }

    /**
     * Subscribe the app to a Page's webhooks for a freshly imported channel.
     *
     * Facebook Pages subscribe themselves; Instagram subscribes the Page it
     * is linked to, because IG messaging via Facebook Login is delivered
     * through that Page.
     *
     * @param array{provider:string, external_id:string, name:string, access_token:?string, metadata:array} $ch
     */
    protected function subscribePage(array $ch, ChannelOnboardingLog $log): void
    {
        $pageId = match ($ch['provider']) {
            ChannelConnection::PROVIDER_FACEBOOK_PAGE => $ch['external_id'],
            ChannelConnection::PROVIDER_INSTAGRAM     => (string) ($ch['metadata']['page_id'] ?? ''),
            default                                   => '',
        };

        // WhatsApp subscribes at the WABA level, not here.
        if ($pageId === '' || empty($ch['access_token'])) {
            return;
        }

        try {
            $this->oauth->subscribeAppToPage($pageId, $ch['access_token']);
            $log->step('subscribe_page', true, $ch['name'] . ' (page ' . $pageId . ')');
        } catch (\Throwable $e) {
            $log->step('subscribe_page', false, $e->getMessage()
                . ' — the channel will not receive messages until this succeeds. Run: php artisan meta:subscribe');
            Log::warning('Meta: page webhook subscription failed', [
                'page'  => $pageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ── internals ────────────────────────────────────────────────────

    /** Record the failure on both records, then stop. */
    private function abort(ChannelOnboardingPayload $payload, ChannelOnboardingLog $log, string $code, string $message): never
    {
        $payload->markFailed($code, $message);

        $log->error_code = $code;
        $log->step($code, false, $message);
        $log->fail($message);

        throw new \RuntimeException($message);
    }
}
