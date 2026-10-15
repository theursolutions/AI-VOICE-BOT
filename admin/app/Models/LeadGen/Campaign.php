<?php

namespace App\Models\LeadGen;

use App\Models\Concerns\IntSoftDeletes;

class Campaign extends LeadGenModel
{
    use IntSoftDeletes;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'running', 'paused', 'stopped', 'completed'];

    public const LAWFUL_BASES = ['consent', 'legitimate_interest', 'existing_customer'];

    protected $table = 'lg_campaigns';

    protected $casts = [
        'steps'       => 'array',
        'schedule'    => 'array',
        'stats'       => 'array',
        'daily_cap'   => 'integer',
        'approved_at' => 'integer',
        'launched_at' => 'integer',
        'stopped_at'  => 'integer',
    ];

    public function recipients()
    {
        return $this->hasMany(CampaignRecipient::class, 'campaign_id');
    }

    public function messages()
    {
        return $this->hasMany(OutreachMessage::class, 'campaign_id');
    }

    public function list()
    {
        return $this->belongsTo(LeadList::class, 'list_id');
    }

    public function profile()
    {
        return $this->belongsTo(OfferProfile::class, 'profile_id');
    }
}
