<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;

/** A reusable outreach message with {{variables}}. */
class Template extends LeadGenModel
{
    use IntSoftDeletes;

    public const CHANNELS = ['email', 'whatsapp', 'messenger', 'instagram'];

    /** Variables a template may use; anything else is left untouched and flagged. */
    public const VARIABLES = [
        'business_name', 'industry', 'city', 'website',
        'value_proposition', 'sender_name', 'booking_link',
    ];

    protected $table = 'lg_templates';
}
