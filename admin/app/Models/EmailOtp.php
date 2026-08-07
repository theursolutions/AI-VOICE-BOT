<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A hashed, time-limited email verification code. See EmailOtpService for
 * the generate/verify lifecycle.
 */
class EmailOtp extends Model
{
    protected $table = 'email_otps';

    protected $fillable = [
        'user_id',
        'code_hash',
        'attempts',
        'expires_at',
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'attempts'   => 'integer',
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
