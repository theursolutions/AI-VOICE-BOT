<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id',
        'invited_by',
        'email',
        'role_id',
        'token',
        'expires_at',
        'accepted_at',
        'accepted_by_user_id',
        'revoked_at',
        'created_at',
        'update_at',
        'created_by',
        'updated_by',
        'deleted_at',
        'deleted_by',
        'is_active',
    ];

    protected $casts = [
        'client_id'           => 'integer',
        'invited_by'          => 'integer',
        'expires_at'          => 'integer',
        'accepted_at'         => 'integer',
        'accepted_by_user_id' => 'integer',
        'revoked_at'          => 'integer',
        'created_at'          => 'integer',
        'update_at'           => 'integer',
        'created_by'          => 'integer',
        'updated_by'          => 'integer',
        'deleted_at'          => 'integer',
        'deleted_by'          => 'integer',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->getTimestamp();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && (int) $this->expires_at > time();
    }

    public function isAcceptable(): bool
    {
        return $this->isPending();
    }

    /**
     * 40-char URL-safe hex token (160 bits of entropy).
     */
    public static function mintToken(): string
    {
        return bin2hex(random_bytes(20));
    }
}
