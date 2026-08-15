<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One handle a contact is known by — a WhatsApp number, an IGSID, a PSID.
 *
 * Kept separate from `contacts` so that merging two people is a matter of
 * repointing rows here, leaving every session and message exactly where it
 * is. That is what makes a merge cheap and, if it ever needs to be, undone
 * by hand.
 */
class ContactIdentity extends Model
{
    protected $connection = 'tenant';
    protected $table = 'contact_identities';

    protected $fillable = [
        'project_id', 'contact_id', 'channel', 'external_id', 'channel_account', 'created_at',
    ];

    protected $casts = [
        'contact_id' => 'integer',
        'created_at' => 'integer',
    ];

    public $timestamps = false;

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** How this handle should read in the UI. */
    public function label(): string
    {
        return match ($this->channel) {
            'whatsapp' => '+' . preg_replace('/\D+/', '', $this->external_id),
            // PSIDs and IGSIDs are page-scoped and opaque — showing the raw
            // 16-digit number tells an agent nothing, so name the channel
            // instead and keep the id for support.
            'instagram' => 'Instagram account',
            'facebook', 'messenger' => 'Facebook account',
            'web'   => 'Web chat visitor',
            'phone' => '+' . preg_replace('/\D+/', '', $this->external_id),
            default => ucfirst($this->channel),
        };
    }
}
