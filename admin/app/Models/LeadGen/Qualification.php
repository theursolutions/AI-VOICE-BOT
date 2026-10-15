<?php

namespace App\Models\LeadGen;

/** One AI scoring of a prospect against an offer profile (history is kept). */
class Qualification extends LeadGenModel
{
    public const BANDS = ['high', 'medium', 'low', 'needs_review'];

    protected $table = 'lg_qualifications';

    protected $casts = [
        'score'      => 'integer',
        'evidence'   => 'array',
        'hypotheses' => 'array',
        'is_current' => 'boolean',
    ];

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function profile()
    {
        return $this->belongsTo(OfferProfile::class, 'profile_id');
    }

    /** The band a person settled on, else the model's. */
    public function effectiveBand(): string
    {
        return $this->override_band ?: $this->band;
    }
}
