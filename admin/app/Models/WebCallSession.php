<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One website "Call us" click: the one-call SIP account issued for it,
 * where it came from, and whether the call happened. The per-IP limits
 * (calls a day, hours between calls) count the rows that started.
 */
class WebCallSession extends Model
{
    protected $connection = 'mysql';

    public const ISSUED  = 'issued';
    public const STARTED = 'started';
    public const ENDED   = 'ended';
    public const EXPIRED = 'expired';

    /** Guest account names start with this (the voice engine checks it too). */
    public const PREFIX = 'w';

    protected $fillable = ['project_id', 'client_id', 'ip', 'origin', 'page_url', 'sip_username',
                           'secret', 'status', 'counts_toward_limit', 'session_id', 'expires_at',
                           'started_at', 'ended_at'];

    protected $casts = [
        'counts_toward_limit' => 'boolean',
        'secret'     => 'encrypted',
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    protected $hidden = ['secret'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
