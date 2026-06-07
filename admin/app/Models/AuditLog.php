<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only super-admin audit trail. Rows are never updated or
 * deleted by app code — use raw SQL on the master DB if you ever
 * need to prune.
 */
class AuditLog extends Model
{
    protected $connection = 'mysql';
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $fillable = [
        'action', 'actor_id', 'target_type', 'target_id',
        'payload', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'integer',
        'actor_id'   => 'integer',
        'target_id'  => 'integer',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Convenience factory — writes a row with timestamp + IP + UA
     * pulled from the current request, if any.
     */
    public static function record(string $action, array $opts = []): self
    {
        $request = request();
        return self::create([
            'action'      => $action,
            'actor_id'    => $opts['actor_id'] ?? optional($request->user())->id,
            'target_type' => $opts['target_type'] ?? null,
            'target_id'   => $opts['target_id']   ?? null,
            'payload'     => $opts['payload']     ?? null,
            'ip'          => $request?->ip(),
            'user_agent'  => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'created_at'  => time(),
        ]);
    }
}
