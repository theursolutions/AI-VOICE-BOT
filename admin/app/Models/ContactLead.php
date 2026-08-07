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
    ];

    public const STATUSES = ['new', 'contacted', 'closed'];
}
