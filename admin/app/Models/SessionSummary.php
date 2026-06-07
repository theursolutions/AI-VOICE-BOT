<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionSummary extends Model
{
    protected $connection = 'tenant';
    protected $table = 'session_summaries';
    protected $primaryKey = 'session_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'session_id',
        'project_id',
        'summary',
        'last_message_id',
        'token_count',
        'updated_at',
    ];

    protected $casts = [
        'last_message_id' => 'integer',
        'token_count' => 'integer',
        'updated_at' => 'integer',
    ];

    public $timestamps = false;

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }
}
