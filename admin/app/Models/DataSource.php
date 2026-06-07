<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    protected $table = 'data_sources';

    public const TYPE_WEBSITE       = 'website';
    public const TYPE_DOCUMENT      = 'document';
    // Tier B — structured data snapshot (CSV / JSON / XLSX). Same
    // ingest path as documents but flagged so the UI / RAG prompts
    // can treat it as tabular rather than narrative.
    public const TYPE_DATA_SNAPSHOT = 'data_snapshot';
    // Tier C — webhook tool. The bot calls an HTTP endpoint at the
    // customer when intent matches. Implemented in a follow-up.
    public const TYPE_WEBHOOK       = 'webhook';
    public const TYPE_CRM_OAUTH     = 'crm_oauth';
    // Tier A — live SQL (existing).
    public const TYPE_DATABASE      = 'database';
    public const TYPE_AGENT         = 'agent';

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_EXPIRED  = 'expired';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'project_id',
        'type',
        'name',
        'config',
        'status',
        'last_synced_at',
        'last_error',
    ];

    protected $casts = [
        'config' => 'array',
        'last_synced_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->is_active === 'Yes';
    }
}
