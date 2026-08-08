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
    public function isRetryable(): bool
    {
        if ($this->status === self::STATUS_IMPORTED) {
            return false;                       // nothing left to do
        }
        if (! $this->long_lived_token) {
            return false;                       // never got an anchor
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;                       // anchor has lapsed
        }

        return true;
    }

    /** Human-readable reason a retry is not on offer. */
    public function retryBlockedReason(): ?string
    {
        if ($this->status === self::STATUS_IMPORTED)  return 'Already imported.';
        if (! $this->long_lived_token)                return 'No stored credentials — the connection has to be started again from Meta.';
        if ($this->expires_at && $this->expires_at->isPast()) return 'Stored credentials expired — reconnect from Meta.';

        return null;
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
