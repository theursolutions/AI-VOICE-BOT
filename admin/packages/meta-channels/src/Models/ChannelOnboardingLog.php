<?php

namespace Msd\MetaChannels\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for a channel onboarding attempt. Steps accumulate as the
 * OAuth flow progresses so failures are diagnosable + retryable.
 */
class ChannelOnboardingLog extends Model
{
    protected $table = 'channel_onboarding_logs';

    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'project_id', 'user_id', 'provider', 'method', 'payload_id',
        'retry_of_id', 'attempt', 'status', 'steps', 'result', 'error', 'error_code',
    ];

    protected $casts = [
        'steps'  => 'array',
        'result' => 'array',
    ];

    /**
     * What the customer should actually DO about a failure.
     *
     * Raw Graph errors ("(#200) Permissions error") tell an operator
     * nothing and a customer less. Each failure class the pipeline can
     * record maps to one concrete next action.
     */
    public function guidance(): ?string
    {
        return match ($this->error_code) {
            'not_configured'      => 'The Meta app is not configured on this server — set META_APP_ID and META_APP_SECRET.',
            'consent_denied'      => 'The Facebook permission screen was cancelled. Start again and choose Continue.',
            'code_exchange_failed'=> 'Meta’s authorization code expired before we could use it (they last only a few minutes). Connect again.',
            'token_exchange_failed' => 'Meta accepted the login but refused to issue a lasting token. Usually temporary — retry.',
            'missing_scopes'      => 'Some permissions were left unticked on the consent screen. Connect again and leave all of them selected.',
            'no_channels'         => 'The account you signed in with has no eligible pages, Instagram accounts or WhatsApp numbers. Check you picked the right business.',
            'graph_error'         => 'Meta’s API returned an error. Often a rate limit or a brief outage — retry in a minute.',
            'import_failed'       => 'Meta’s side succeeded but saving on ours did not. Retry — no need to sign in again.',
            'payload_expired'     => 'The stored credentials have expired. Connect from Meta again.',
            default               => null,
        };
    }

    /** Was this attempt itself a retry of an earlier one? */
    public function isRetry(): bool
    {
        return (int) $this->attempt > 1;
    }

    /** What Meta returned for this attempt — the thing a retry replays. */
    public function payload()
    {
        return $this->belongsTo(ChannelOnboardingPayload::class, 'payload_id');
    }

    /**
     * Can this be retried on our side alone? Anything else means the
     * customer has to go through Meta again, and the UI should say so
     * rather than offering a button that cannot work.
     */
    public function canReplay(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->payload
            && $this->payload->isRetryable();
    }

    /** Append a step to the running log. */
    public function step(string $step, bool $ok, $detail = null): void
    {
        $steps = (array) $this->steps;
        $steps[] = ['step' => $step, 'ok' => $ok, 'detail' => is_scalar($detail) ? $detail : json_encode($detail), 'at' => now()->toDateTimeString()];
        $this->steps = $steps;
        $this->save();
    }

    public function fail(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->save();
    }
}
