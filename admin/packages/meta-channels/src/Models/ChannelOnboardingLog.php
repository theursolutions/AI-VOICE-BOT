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
        'project_id', 'user_id', 'provider', 'status', 'steps', 'result', 'error',
    ];

    protected $casts = [
        'steps'  => 'array',
        'result' => 'array',
    ];

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
