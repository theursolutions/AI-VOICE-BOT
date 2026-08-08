<?php

namespace Msd\MetaChannels\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Meta channel (WhatsApp number / Instagram / Facebook page) a project
 * has onboarded. Managed from the host app's Channels page; read by the
 * webhook to resolve the owning project and enabled flag.
 *
 * Deliberately holds `project_id` as a plain attribute (no belongsTo) so
 * the package stays decoupled from the host app's models.
 */
class ChannelConnection extends Model
{
    protected $table = 'channel_connections';

    public const PROVIDER_WHATSAPP      = 'whatsapp';
    public const PROVIDER_INSTAGRAM     = 'instagram';
    public const PROVIDER_FACEBOOK_PAGE = 'facebook_page';
    public const PROVIDER_MESSENGER     = 'messenger';

    public const PROVIDERS = [
        self::PROVIDER_WHATSAPP      => 'WhatsApp',
        self::PROVIDER_INSTAGRAM     => 'Instagram',
        self::PROVIDER_FACEBOOK_PAGE => 'Facebook Page',
        self::PROVIDER_MESSENGER     => 'Messenger',
    ];

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'project_id', 'provider', 'external_id', 'name',
        'access_token', 'short_lived_token', 'token_obtained_at',
        'token_expires_at', 'token_scopes', 'status', 'metadata',
    ];

    protected $casts = [
        'access_token'      => 'encrypted',
        'short_lived_token' => 'encrypted',
        'token_obtained_at' => 'datetime',
        'token_expires_at'  => 'datetime',
        'token_scopes'      => 'array',
        'metadata'          => 'array',
    ];

    /**
     * Is the stored credential still good? NULL expiry means a permanent
     * token (page tokens from a long-lived user token, system-user tokens),
     * which is the desirable end state.
     */
    public function tokenIsValid(): bool
    {
        return ! $this->token_expires_at || $this->token_expires_at->isFuture();
    }

    /** Days until the token lapses; null when it never does. */
    public function tokenExpiresInDays(): ?int
    {
        return $this->token_expires_at ? (int) now()->diffInDays($this->token_expires_at, false) : null;
    }

    /** Connections whose token lapses within $days — for a refresh sweep. */
    public function scopeTokenExpiringWithin($query, int $days = 7)
    {
        return $query->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays($days));
    }

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}
