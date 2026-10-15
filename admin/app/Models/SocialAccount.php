<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A publishing account outside Meta — a YouTube channel today.
 *
 * Meta accounts are ChannelConnection rows (shared with the inbox); this is
 * the equivalent for platforms that are only ever posted TO. Exposes the same
 * small surface the Social Posts page reads from a ChannelConnection
 * (provider, name, isEnabled(), missingPublishScopes()) so the two can sit in
 * one account list.
 */
class SocialAccount extends Model
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const STATUS_ENABLED   = 'enabled';
    /** Google refused the refresh token: revoked access, changed password, or a 7-day "Testing" app token. */
    public const STATUS_RECONNECT = 'reconnect';

    protected $fillable = [
        'project_id', 'provider', 'external_id', 'name', 'avatar_url',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes',
        'status', 'last_error', 'metadata',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes'           => 'array',
        'metadata'         => 'array',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * Scopes the upload needs that this account was not granted. Google lets
     * people untick individual scopes on the consent screen, so a connected
     * channel can still be unable to upload.
     *
     * @return string[]|null  null when not recorded
     */
    public function missingPublishScopes(): ?array
    {
        if (! is_array($this->scopes) || $this->scopes === []) {
            return null;
        }

        return in_array('https://www.googleapis.com/auth/youtube.upload', $this->scopes, true)
            ? []
            : ['youtube.upload'];
    }

    public function markReconnect(string $reason): void
    {
        $this->status     = self::STATUS_RECONNECT;
        $this->last_error = mb_substr($reason, 0, 2000);
        $this->save();
    }
}
