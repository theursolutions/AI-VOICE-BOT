<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'sessions';

    protected $fillable = [
        'project_id',
        'channel',
        'external_id',
        // Business account the customer messaged (WhatsApp phone_number_id /
        // FB page id / IG id). With `channel` this identifies the inbound
        // connection, so a reply routes back out the same number/page and
        // multi-number conversations stay separate threads.
        'channel_account',
        'customer_name',
        'customer_phone',
        'customer_email',
        'voice_id',
        'status',
        'started_at',
        'ended_at',
        'last_activity_at',
        // Last time the CUSTOMER messaged (distinct from last_activity_at,
        // which includes our replies). Drives Meta's 24h service window.
        'last_inbound_at',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'integer',
        'ended_at' => 'integer',
        'last_activity_at' => 'integer',
        'last_inbound_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function summary()
    {
        return $this->hasOne(SessionSummary::class, 'session_id');
    }

    public function lead()
    {
        return $this->hasOne(Lead::class);
    }

    public function voice()
    {
        return $this->belongsTo(Voice::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Is this conversation with a CUSTOMER (public web chat / voice /
     * messaging) rather than an internal team member?
     *
     * The internal "Ask AI" assistant creates sessions with
     * channel = 'internal'; everything else is a customer-facing channel.
     * Drives the data-source audience gate (DataSource::customer_visible):
     * customer turns only see sources the owner has opted in.
     */
    public function isCustomerFacing(): bool
    {
        return $this->channel !== 'internal';
    }
}
