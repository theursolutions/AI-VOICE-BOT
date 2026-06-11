<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

/**
 * Uploaded audio file referenced by a Say-node in a Flow.
 *
 * The flow definition JSON stores asset_id; the runtime resolves that
 * to storage_path and TwiML <Play> uses the public URL.
 *
 * Project-scoped — a Say-node in project A cannot reference an asset
 * in project B, even though both live in their own tenant DB.
 */
class FlowAsset extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'flow_assets';

    protected $fillable = [
        'project_id', 'flow_id', 'label', 'language',
        'mime', 'storage_path', 'duration_ms', 'size_bytes',
    ];

    protected $casts = [
        'duration_ms' => 'integer',
        'size_bytes'  => 'integer',
        'created_at'  => 'integer',
        'update_at'   => 'integer',
        'deleted_at'  => 'integer',
    ];

    public $timestamps = false;

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }
}
