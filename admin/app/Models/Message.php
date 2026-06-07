<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    // No soft-delete trait — messages don't carry deleted_at. Hiding
    // them happens transitively when the parent Session is soft-deleted
    // (its UI never re-fetches messages once hidden).
    protected $connection = 'tenant';
    protected $table = 'messages';

    protected $fillable = [
        'session_id',
        'project_id',
        'role',
        'content',
        'audio_url',
        'tokens_in',
        'tokens_out',
        'latency_ms',
        'model_used',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'tokens_in' => 'integer',
        'tokens_out' => 'integer',
        'latency_ms' => 'integer',
        'created_at' => 'integer',
    ];

    public $timestamps = false;

    public const UPDATED_AT = null;

    public function session()
    {
        return $this->belongsTo(Session::class);
    }
}
