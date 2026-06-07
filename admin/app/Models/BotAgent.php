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

    protected $fillable = [
        'project_id', 'name', 'voice_id', 'persona',
        'is_default', 'status', 'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata'   => 'array',
        'voice_id'   => 'integer',
        'created_at' => 'integer',
        'update_at'  => 'integer',
        'deleted_at' => 'integer',
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
}
