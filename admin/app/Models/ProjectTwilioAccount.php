<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One project's Twilio credentials.
 *
 * The token is encrypted at rest by the `encrypted` cast, so reading
 * `$account->auth_token` decrypts transparently and nothing else in the app
 * needs to know. It is also `$hidden`, because this model gets passed around
 * and a single `@json($account)` in a Blade view would put a customer's live
 * Twilio credential into the page source.
 */
class ProjectTwilioAccount extends Model
{
    protected $connection = 'mysql';
    protected $table = 'project_twilio_accounts';

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_INVALID   = 'invalid';

    protected $fillable = [
        'project_id', 'account_sid', 'auth_token', 'auth_token_hint',
        'friendly_name', 'account_type', 'status', 'last_error', 'verified_at',
    ];

    protected $casts = [
        'auth_token'  => 'encrypted',
        'verified_at' => 'datetime',
    ];

    protected $hidden = ['auth_token'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Trial accounts can only call numbers verified in the Twilio console —
     * the most common reason a customer's first live call fails.
     */
    public function isTrial(): bool
    {
        return strcasecmp((string) $this->account_type, 'Trial') === 0;
    }

    /** Store a token together with the masked hint the UI shows. */
    public function setToken(string $token): void
    {
        $this->auth_token      = $token;
        $this->auth_token_hint = strlen($token) > 4 ? substr($token, -4) : '';
    }

    /**
     * The project that owns a given Twilio account, or null if we've never
     * seen that SID. Callers must treat null as "refuse" — falling back to
     * the platform token would let anyone who found our webhook URL point
     * their own Twilio account at it and be trusted.
     */
    public static function findBySid(string $accountSid): ?self
    {
        return $accountSid === ''
            ? null
            : self::query()->where('account_sid', $accountSid)->first();
    }
}
