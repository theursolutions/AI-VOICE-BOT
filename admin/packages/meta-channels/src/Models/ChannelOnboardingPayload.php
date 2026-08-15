<?php

namespace Msd\MetaChannels\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Everything Meta returned during one onboarding attempt, stored so our own
 * side can be retried without sending the customer back to Facebook.
 *
 * The status ladder mirrors the work, and each rung is durable:
 *
 *   received    the code (or Embedded Signup handoff) landed
 *   tokenized   short-lived token exchanged for a long-lived one
 *   discovered  Graph told us which pages / numbers / accounts were granted
 *   imported    ChannelConnection rows written — terminal, credentials purged
 *   failed      something broke; `error_code` says what, and a retry resumes
 *               from the highest rung already reached
 *
 * Retryable() is the honest question to ask before offering a retry button:
 * the long-lived token is the anchor, and once it expires (~60 days) there
 * is genuinely nothing left to replay and the customer must revisit Meta.
 */
class ChannelOnboardingPayload extends Model
{
    protected $table = 'channel_onboarding_payloads';

    public const STATUS_RECEIVED   = 'received';
    public const STATUS_TOKENIZED  = 'tokenized';
    public const STATUS_DISCOVERED = 'discovered';
    public const STATUS_IMPORTED   = 'imported';
    public const STATUS_FAILED     = 'failed';

    public const METHOD_REDIRECT        = 'redirect';
    public const METHOD_EMBEDDED_SIGNUP = 'embedded_signup';
    /** Instagram API with Instagram Login — a different OAuth host entirely. */
    public const METHOD_INSTAGRAM_LOGIN = 'instagram_login';

    /** Is this attempt on the Instagram-Login path rather than Facebook Login? */
    public function isInstagramLogin(): bool
    {
        return $this->method === self::METHOD_INSTAGRAM_LOGIN;
    }

    protected $fillable = [
        'project_id', 'user_id', 'log_id', 'provider', 'method',
        'auth_code', 'redirect_uri', 'short_lived_token', 'long_lived_token',
        'token_expires_at', 'token_scopes', 'waba_id', 'phone_number_id',
        'discovery', 'status', 'error_code', 'error', 'attempts',
        'expires_at', 'consumed_at',
    ];

    protected $casts = [
        // Credentials never sit in the database as plaintext. `discovery` is
        // encrypted too — page and Instagram entries each carry their own
        // access token inside that payload.
        'auth_code'         => 'encrypted',
        'short_lived_token' => 'encrypted',
        'long_lived_token'  => 'encrypted',
        'discovery'         => 'encrypted:array',
        'token_scopes'      => 'array',
        'token_expires_at'  => 'datetime',
        'expires_at'        => 'datetime',
        'consumed_at'       => 'datetime',
    ];

    /** The token later steps should use — long-lived if we got that far. */
    public function usableToken(): ?string
    {
        return $this->long_lived_token ?: $this->short_lived_token;
    }

    /**
     * Can our side be replayed without the customer returning to Meta?
     *
     * Deliberately does NOT count `auth_code`: it is single-use and dies in
     * about ten minutes, so a retry built on it would fail confusingly.
     */
    /**
     * A short-lived token is worth retrying from for about an hour.
     *
     * Deliberately under Meta's stated 1–2 hours: a retry that fails because
     * the credential died three minutes ago is worse than not offering one.
     */
    private const SHORT_LIVED_RETRY_SECONDS = 3000;   // 50 minutes

    public function isRetryable(): bool
    {
        if ($this->status === self::STATUS_IMPORTED) {
            return false;                       // nothing left to do
        }
        if ($this->long_lived_token) {
            // The anchor case: retryable until the credential itself lapses.
            return ! ($this->expires_at && $this->expires_at->isPast());
        }

        // Falling at the long-lived exchange used to be terminal — the
        // customer had to go back through consent because only a long-lived
        // token counted. But a SHORT-lived token is a perfectly good basis for
        // a retry inside its own lifetime, and that exchange is exactly the
        // step most likely to fail transiently. Not offering a retry there
        // sent people back to Meta for a problem that was ours.
        return $this->short_lived_token && $this->shortLivedStillFresh();
    }

    /** Human-readable reason a retry is not on offer. */
    public function retryBlockedReason(): ?string
    {
        if ($this->status === self::STATUS_IMPORTED) {
            return 'Already imported.';
        }
        if ($this->long_lived_token) {
            return ($this->expires_at && $this->expires_at->isPast())
                ? 'Stored credentials expired — reconnect from Meta.'
                : null;
        }
        if ($this->short_lived_token) {
            return $this->shortLivedStillFresh()
                ? null
                : 'The short-lived Meta token has expired — reconnect from Meta.';
        }

        return 'No stored credentials — the connection has to be started again from Meta.';
    }

    private function shortLivedStillFresh(): bool
    {
        $issued = $this->created_at?->getTimestamp() ?? 0;

        return $issued > 0 && (time() - $issued) < self::SHORT_LIVED_RETRY_SECONDS;
    }

    /** Record a failure without losing what we already collected. */
    public function markFailed(string $code, string $message): void
    {
        $this->status     = self::STATUS_FAILED;
        $this->error_code = $code;
        $this->error      = $message;
        $this->save();
    }

    /**
     * Import succeeded: drop the credentials. They exist only to enable a
     * retry, so holding them after that is storage risk with no upside —
     * the working token now lives on the ChannelConnection.
     */
    public function markImported(): void
    {
        $this->status            = self::STATUS_IMPORTED;
        $this->consumed_at       = now();
        $this->auth_code         = null;
        $this->short_lived_token = null;
        $this->long_lived_token  = null;
        $this->discovery         = null;
        $this->error_code        = null;
        $this->error             = null;
        $this->save();
    }

    public function scopeReplayable($query)
    {
        return $query->whereIn('status', [
            self::STATUS_RECEIVED, self::STATUS_TOKENIZED,
            self::STATUS_DISCOVERED, self::STATUS_FAILED,
        ]);
    }
}
