<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Skill extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'skills';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'project_id', 'name', 'description', 'sla_seconds',
        'is_default', 'status', 'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'metadata'   => 'array',
        'sla_seconds'=> 'integer',
        'created_at' => 'integer',
        'update_at'  => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    public function agents()
    {
        return $this->belongsToMany(BotAgent::class, 'agent_skills', 'skill_id', 'agent_id')
                    ->withPivot('priority');
    }

    // -- Actions (webhook tools) this skill grants -------------------------
    //
    // The pivot `skill_actions` lives in the tenant DB but references
    // `data_sources.id` in the shared (app) DB, so we use the query builder
    // on the tenant connection directly rather than a cross-connection
    // belongsToMany (which Eloquent can't span cleanly).

    /** data_source IDs (webhook tools) linked to this skill. @return int[] */
    public function actionIds(): array
    {
        $conn = DB::connection('tenant');
        if (!$conn->getSchemaBuilder()->hasTable('skill_actions')) {
            return [];
        }
        return $conn->table('skill_actions')
            ->where('skill_id', $this->id)
            ->pluck('data_source_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Replace this skill's linked actions with the given data_source IDs. */
    public function syncActions(array $dataSourceIds): void
    {
        $conn = DB::connection('tenant');
        $conn->table('skill_actions')->where('skill_id', $this->id)->delete();

        $now  = time();
        $rows = [];
        foreach (array_unique(array_map('intval', $dataSourceIds)) as $id) {
            if ($id <= 0) continue;
            $rows[] = ['skill_id' => $this->id, 'data_source_id' => $id, 'created_at' => $now];
        }
        if ($rows) {
            $conn->table('skill_actions')->insert($rows);
        }
    }

    /**
     * Resolve which webhook tools an agent may invoke this turn.
     *
     * A tool linked to ≥1 skill is "gated" — only agents holding one of
     * those skills may use it. A tool linked to NO skill stays global
     * (available to every agent), so existing project webhooks keep
     * working unchanged.
     *
     * Returns both sets so callers can apply the rule:
     *   allowed-for-agent  =  (tools not in `gated`)  ∪  `allowed`
     *
     * Assumes the tenant connection already points at the right project.
     *
     * @return array{gated: int[], allowed: int[]}
     */
    public static function toolGatingForAgent(?int $agentId): array
    {
        $conn = DB::connection('tenant');

        // Degrade to "no gating" on tenant DBs that haven't run the
        // skill_actions migration yet — otherwise every turn would 500.
        if (!$conn->getSchemaBuilder()->hasTable('skill_actions')) {
            return ['gated' => [], 'allowed' => []];
        }

        $gated = $conn->table('skill_actions')
            ->distinct()->pluck('data_source_id')
            ->map(fn ($v) => (int) $v)->all();

        if (!$agentId || empty($gated)) {
            return ['gated' => $gated, 'allowed' => []];
        }

        $skillIds = $conn->table('agent_skills')
            ->where('agent_id', $agentId)
            ->pluck('skill_id')->all();

        $allowed = empty($skillIds) ? [] : $conn->table('skill_actions')
            ->whereIn('skill_id', $skillIds)
            ->distinct()->pluck('data_source_id')
            ->map(fn ($v) => (int) $v)->all();

        return ['gated' => $gated, 'allowed' => $allowed];
    }

    /**
     * Apply the gating rule to a single tool id.
     * A tool is permitted when it isn't gated, or it's explicitly allowed.
     *
     * @param array{gated: int[], allowed: int[]} $gating
     */
    public static function toolPermitted(int $toolId, array $gating): bool
    {
        $gated = $gating['gated'] ?? [];
        if (!in_array($toolId, $gated, true)) {
            return true; // global tool — available to everyone
        }
        return in_array($toolId, $gating['allowed'] ?? [], true);
    }
}
