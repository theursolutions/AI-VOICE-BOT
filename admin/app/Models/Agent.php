<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $table = 'agents';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'project_id',
        'name',
        'agent_uid',
        'enrollment_token',
        'enrollment_token_expires_at',
        'token_hash',
        'enrolled_at',
        'last_seen_at',
        'client_version',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'enrollment_token_expires_at' => 'integer',
        'enrolled_at' => 'integer',
        'last_seen_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function queries()
    {
        return $this->hasMany(AgentQuery::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_active === 'Yes';
    }
}
