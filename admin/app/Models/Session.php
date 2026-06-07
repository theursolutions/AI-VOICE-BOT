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
        'customer_name',
        'customer_phone',
        'customer_email',
        'voice_id',
        'status',
        'started_at',
        'ended_at',
        'last_activity_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'started_at' => 'integer',
        'ended_at' => 'integer',
        'last_activity_at' => 'integer',
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
}
