<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

/**
 * AI Agent persona — the entity that "talks" to the customer in chat
 * or on a call. Each project can have many; each one has its own
 * voice (cloned WAV), persona/system-prompt, and a set of skills it
 * handles. Routing picks one of these per session.
 *
 * Distinct from App\Models\Agent (Tier 3b "Query Agent" — that's a
 * customer-hosted infrastructure agent that runs SQL on their box).
 * Same word, different concept. This one lives in the tenant DB.
 */
class BotAgent extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'agents';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    // An agent is either an AI persona or a real person who takes over chats.
    public const TYPE_AI    = 'ai';
    public const TYPE_HUMAN = 'human';

    public const PRESENCE_ONLINE  = 'online';
    public const PRESENCE_AWAY    = 'away';
    public const PRESENCE_OFFLINE = 'offline';

    protected $fillable = [
        'project_id', 'name', 'voice_id', 'persona',
        'is_default', 'status', 'metadata',
        'type', 'user_id', 'presence', 'max_active_chats',
    ];

    protected $casts = [
        'is_default'       => 'boolean',
        'metadata'         => 'array',
        'voice_id'         => 'integer',
        'user_id'          => 'integer',
        'max_active_chats' => 'integer',
        'created_at'       => 'integer',
        'update_at'        => 'integer',
        'deleted_at'       => 'integer',
    ];

    public $timestamps = false;

    public function voice()
    {
        return $this->belongsTo(Voice::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'agent_skills', 'agent_id', 'skill_id')
                    ->withPivot('priority');
    }

    public function isHuman(): bool
    {
        return $this->type === self::TYPE_HUMAN;
    }

    /** Human agents that are active + online and can receive a chat. */
    public function scopeAvailableHuman($query)
    {
        return $query->where('type', self::TYPE_HUMAN)
            ->where('status', self::STATUS_ACTIVE)
            ->where('presence', self::PRESENCE_ONLINE);
    }

    /** Count of chats this human is actively handling right now. */
    public function activeChatCount(): int
    {
        return Session::where('assigned_agent_id', $this->id)
            ->where('handoff_status', 'assigned')
            ->where('status', 'active')
            ->count();
    }

    public function atCapacity(): bool
    {
        return $this->activeChatCount() >= max(1, (int) ($this->max_active_chats ?: 3));
    }
}
