<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A contact request captured from the public marketing site (e.g. the
 * "Call me now" widget). Lives in the central DB; surfaced to super-admins
 * in the ops console at /admin/contacts.
 */
class ContactLead extends Model
{
    protected $table = 'contact_leads';

    protected $fillable = [
        'name', 'email', 'phone', 'subject', 'message',
        'source', 'status', 'ip', 'user_agent', 'referrer',
        'visitor_key',
    ];

    public const STATUSES = ['new', 'contacted', 'closed'];

    /**
     * The tracked visitor this lead came from, when there was one — null for
     * leads with no web visit behind them (phone-in, imported list).
     */
    public function visitor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_key', 'visitor_key');
    }
}
