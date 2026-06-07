<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentQuery extends Model
{
    protected $table = 'agent_queries';

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE        = 'done';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_TIMEOUT     = 'timeout';

    protected $fillable = [
        'agent_id',
        'request_id',
        'sql',
        'params',
        'max_rows',
        'status',
        'result',
        'error',
        'created_at',
        'picked_at',
        'completed_at',
    ];

    protected $casts = [
        'params'       => 'array',
        'result'       => 'array',
        'max_rows'     => 'integer',
        'created_at'   => 'integer',
        'picked_at'    => 'integer',
        'completed_at' => 'integer',
    ];

    public $timestamps = false;

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }
}
