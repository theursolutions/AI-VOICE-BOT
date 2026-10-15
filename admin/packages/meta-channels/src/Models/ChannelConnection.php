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

    /** Providers that can carry posts (WhatsApp/Messenger cannot). */
    public function canCarryPosts(): bool
    {
        return in_array($this->provider, [self::PROVIDER_FACEBOOK_PAGE, self::PROVIDER_INSTAGRAM], true);
    }

    /** Was this account onboarded through Instagram Login (graph.instagram.com)? */
    public function viaInstagramLogin(): bool
    {
        return data_get($this->metadata, 'login') === 'instagram';
    }

    /**
     * The scopes publishing needs on this connection, from config.
     *
     * pages_read_engagement is required for a Page's posts but is not a
     * publishing permission as such — it is asked for alongside so the
     * permalink lookup after a publish does not fail on its own.
     *
     * @return string[]
     */
    public function publishScopes(): array
    {
        $raw = match (true) {
            ! $this->canCarryPosts()   => '',
            $this->viaInstagramLogin() => (string) config('meta.instagram.publish_scopes', ''),
            default                    => (string) (config('meta.app.publish_scopes')[$this->provider] ?? ''),
        };

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Publishing scopes this connection was NOT granted, or null when we
     * cannot tell (a manually-pasted token records no scopes) — the caller
     * then just tries, and Meta's error explains the rest.
     *
     * @return string[]|null
     */
    public function missingPublishScopes(): ?array
    {
        if (! is_array($this->token_scopes) || $this->token_scopes === []) {
            return null;
        }

        return array_values(array_diff($this->publishScopes(), $this->token_scopes));
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
