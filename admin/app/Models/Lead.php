<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'leads';

    protected $fillable = [
        'session_id',
        'project_id',
        'fields',
        'confidence',
        'status',
        'assigned_to',
        'notes',
    ];

    protected $casts = [
        'fields' => 'array',
        'confidence' => 'float',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
