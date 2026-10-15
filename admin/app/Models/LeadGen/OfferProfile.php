<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;

/** What a tenant sells and to whom — the context AI scoring and drafts work from. */
class OfferProfile extends LeadGenModel
{
    use IntSoftDeletes;

    protected $table = 'lg_offer_profiles';

    protected $casts = [
        'icp'        => 'array',
        'exclusions' => 'array',
        'questions'  => 'array',
        'scoring'    => 'array',
    ];
}
