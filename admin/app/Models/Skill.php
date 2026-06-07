<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'skills';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'project_id', 'name', 'description', 'sla_seconds',
        'is_default', 'status', 'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata'   => 'array',
        'sla_seconds'=> 'integer',
        'created_at' => 'integer',
        'update_at'  => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function agents()
    {
        return $this->belongsToMany(BotAgent::class, 'agent_skills', 'skill_id', 'agent_id')
                    ->withPivot('priority');
    }
}
