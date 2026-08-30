<?php

namespace Msd\MailChannel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A mailbox (SMTP + IMAP credentials) a project sends/receives through.
 *
 * Deliberately holds `project_id` as a plain attribute (no belongsTo) so the
 * package stays decoupled from the host app's models — same convention as
 * Msd\MetaChannels\Models\ChannelConnection.
 */
class EmailAccount extends Model
{
    protected $table = 'email_accounts';

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'project_id', 'label', 'from_name', 'from_email',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'oauth_provider', 'oauth_token', 'oauth_refresh_token', 'oauth_expires_at',
        'status', 'last_uid', 'last_polled_at', 'last_error', 'metadata',
    ];

    protected $casts = [
        'imap_password'        => 'encrypted',
        'smtp_password'         => 'encrypted',
        'oauth_token'           => 'encrypted',
        'oauth_refresh_token'   => 'encrypted',
        'oauth_expires_at'      => 'datetime',
        'last_polled_at'        => 'datetime',
        'metadata'              => 'array',
    ];

    protected $hidden = [
        'imap_password', 'smtp_password', 'oauth_token', 'oauth_refresh_token',
    ];

    public function isEnabled(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function displayName(): string
    {
        return $this->label ?: ($this->from_name ? "{$this->from_name} <{$this->from_email}>" : $this->from_email);
    }
}
