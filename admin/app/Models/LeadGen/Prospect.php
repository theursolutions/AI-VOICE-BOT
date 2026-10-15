<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;
use App\Models\Contact;

/**
 * A business found by a search or import. Not a CRM contact until someone
 * adds it (contact_id). `field_meta` records, per field, where the value came
 * from and how far it has been verified — the UI shows that, and nothing
 * unverified is ever presented as verified.
 */
class Prospect extends LeadGenModel
{
    use IntSoftDeletes;

    public const STATUSES = ['new', 'reviewing', 'approved', 'rejected', 'in_crm'];

    /** Verification levels, weakest first. */
    public const VERIFICATION = ['unverified', 'syntax_ok', 'mx_ok', 'found_on_site', 'user_confirmed'];

    protected $table = 'lg_prospects';

    protected $casts = [
        'address'          => 'array',
        'socials'          => 'array',
        'source_meta'      => 'array',
        'field_meta'       => 'array',
        'tags'             => 'array',
        'lat'              => 'float',
        'lng'              => 'float',
        'discovered_at'    => 'integer',
        'last_verified_at' => 'integer',
        'suppressed_at'    => 'integer',
    ];

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function qualifications()
    {
        return $this->hasMany(Qualification::class, 'prospect_id');
    }

    public function currentQualification()
    {
        return $this->hasOne(Qualification::class, 'prospect_id')->where('is_current', true);
    }

    public function lists()
    {
        return $this->belongsToMany(LeadList::class, 'lg_list_items', 'prospect_id', 'list_id');
    }

    /** Verification level recorded for one field ("unverified" when unknown). */
    public function verificationOf(string $field): string
    {
        return (string) ($this->field_meta[$field]['verification'] ?? 'unverified');
    }
}
