<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Voice extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'voices';

    protected $fillable = [
        'project_id',
        'provider',
        'name',
        'reference_url',
        'external_id',
        'language',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
