<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Daily token usage per brain, per project, per call type.
 *
 * The audit trail behind ai_brains.tokens_used, and the answer to "what does
 * this client actually cost us" — which nothing could answer before.
 */
class AiBrainUsage extends Model
{
    protected $table = 'ai_brain_usage';

    protected $fillable = [
        'brain_id', 'project_id', 'usage_date', 'call_type',
        'tokens_in', 'tokens_out', 'calls', 'failures', 'updated_at',
    ];

    protected $casts = [
        'brain_id'   => 'integer',
        'project_id' => 'integer',
        'tokens_in'  => 'integer',
        'tokens_out' => 'integer',
        'calls'      => 'integer',
        'failures'   => 'integer',
        'updated_at' => 'integer',
    ];

    public $timestamps = false;

    /**
     * Add a call to today's row, creating it if this is the first one.
     *
     * A single atomic upsert rather than read-modify-write. Three calls per
     * message across several queue workers means concurrent writes to the same
     * row are the normal case, not an edge case — and a lost update here is a
     * quota that never trips.
     */
    public static function accumulate(
        int $brainId,
        ?int $projectId,
        string $callType,
        int $tokensIn,
        int $tokensOut,
        bool $failed = false,
    ): void {
        $now = time();

        // ON DUPLICATE KEY UPDATE against the unique index, so the increment
        // happens inside the database rather than across two round trips.
        DB::table('ai_brain_usage')->upsert(
            [[
                'brain_id'   => $brainId,
                // 0 rather than null: MySQL treats NULLs in a unique index as
                // distinct, so a null project_id would insert a new row per call
                // instead of accumulating into one.
                'project_id' => $projectId ?? 0,
                'usage_date' => date('Y-m-d', $now),
                'call_type'  => $callType,
                'tokens_in'  => $tokensIn,
                'tokens_out' => $tokensOut,
                'calls'      => 1,
                'failures'   => $failed ? 1 : 0,
                'updated_at' => $now,
            ]],
            ['brain_id', 'project_id', 'usage_date', 'call_type'],
            [
                'tokens_in'  => DB::raw('ai_brain_usage.tokens_in + VALUES(tokens_in)'),
                'tokens_out' => DB::raw('ai_brain_usage.tokens_out + VALUES(tokens_out)'),
                'calls'      => DB::raw('ai_brain_usage.calls + 1'),
                'failures'   => DB::raw('ai_brain_usage.failures + VALUES(failures)'),
                'updated_at' => DB::raw('VALUES(updated_at)'),
            ],
        );
    }

    public function brain()
    {
        return $this->belongsTo(AiBrain::class, 'brain_id');
    }

    /**
     * Tokens and calls for a project over the last N days, split by call type.
     *
     * The per-call-type split is the point: a customer message is three calls
     * with very different profiles, and knowing which dominates is what tells
     * you where the next optimisation belongs.
     */
    public static function summaryForProject(int $projectId, int $days = 30): array
    {
        return static::query()
            ->where('project_id', $projectId)
            ->where('usage_date', '>=', date('Y-m-d', strtotime("-{$days} days")))
            ->selectRaw('call_type, SUM(tokens_in) AS tin, SUM(tokens_out) AS tout, SUM(calls) AS n, SUM(failures) AS bad')
            ->groupBy('call_type')
            ->get()
            ->keyBy('call_type')
            ->map(fn ($r) => [
                'tokens_in'  => (int) $r->tin,
                'tokens_out' => (int) $r->tout,
                'calls'      => (int) $r->n,
                'failures'   => (int) $r->bad,
            ])
            ->all();
    }
}
