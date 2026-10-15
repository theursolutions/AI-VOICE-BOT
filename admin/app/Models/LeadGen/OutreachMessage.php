<?php

namespace App\Models\LeadGen;

/**
 * One outbound message. `idempotency_key` (unique) is what stops a retried
 * job or an overlapping scheduler tick from sending twice; `unknown` marks a
 * send whose outcome we couldn't see and that must never be retried blindly.
 */
class OutreachMessage extends LeadGenModel
{
    public const STATUSES = [
        'draft', 'approved', 'queued', 'sending', 'accepted', 'delivered',
        'read', 'replied', 'bounced', 'failed', 'unknown',
    ];

    /** Delivery progress order; a status event never moves a message backwards. */
    public const PROGRESS = ['accepted' => 1, 'delivered' => 2, 'read' => 3, 'replied' => 4];

    protected $table = 'lg_outreach_messages';

    protected $casts = [
        'step'          => 'integer',
        'attempts'      => 'integer',
        'ai_generated'  => 'boolean',
        'scheduled_for' => 'integer',
        'queued_at'     => 'integer',
        'sent_at'       => 'integer',
        'delivered_at'  => 'integer',
        'read_at'       => 'integer',
        'replied_at'    => 'integer',
    ];

    public function prospect()
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function recipient()
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }
}
