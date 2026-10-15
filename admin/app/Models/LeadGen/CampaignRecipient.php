<?php

namespace App\Models\LeadGen;

class CampaignRecipient extends LeadGenModel
{
    public const STATES = ['pending', 'excluded', 'active', 'replied', 'opted_out', 'bounced', 'completed', 'stopped'];

    protected $table = 'lg_campaign_recipients';

    protected $casts = [
        'current_step'   => 'integer',
        'next_action_at' => 'integer',
        'last_sent_at'   => 'integer',
        'replied_at'     => 'integer',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }
}
