<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Per-project conversation flow.
 *
 * `definition` holds the full editor graph (nodes + edges + settings)
 * as a single JSON document. It's the same shape React Flow consumes
 * client-side, so loading + saving is just a passthrough.
 *
 * Statuses:
 *   draft    — editor only, not assigned to any phone number yet
 *   active   — can be assigned to telephony.numbers[].flow_id
 *   archived — kept for history but cannot be invoked
 */
class Flow extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'flows';

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_ARCHIVED];

    protected $fillable = [
        'project_id', 'name', 'slug', 'status',
        'definition', 'version', 'language', 'description',
    ];

    protected $casts = [
        'definition' => 'array',
        'version'    => 'integer',
        'created_at' => 'integer',
        'update_at'  => 'integer',
        'deleted_at' => 'integer',
    ];

    public $timestamps = false;

    /**
     * Stable, URL-safe identifier used in the editor route. Auto-derived
     * from name if not set explicitly. NOT a primary key — the integer
     * `id` is still authoritative; slug is for nicer URLs.
     */
    public static function generateSlug(string $name, int $projectId): string
    {
        $base = Str::slug($name) ?: 'flow';
        $slug = $base;
        $i = 2;
        while (self::query()
            ->where('project_id', $projectId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Why this flow may NOT go live, as human-readable reasons. Empty array
     * means it's safe to activate.
     *
     * An active flow is wired into real conversations: it intercepts calls and
     * chats before the plain LLM answer does. A broken one therefore doesn't
     * fail loudly — it silently swallows customer traffic and dead-ends it,
     * which is strictly worse than leaving the flow in draft. So activation is
     * gated on the graph being able to actually *do* something.
     *
     * Deliberately NOT a full graph validation (unreachable branches, missing
     * node config): those degrade a flow, these three make it inert.
     *
     * @return array<int,string>
     */
    public function activationErrors(): array
    {
        $def   = is_array($this->definition) ? $this->definition : [];
        $nodes = is_array($def['nodes'] ?? null) ? $def['nodes'] : [];
        $edges = is_array($def['edges'] ?? null) ? $def['edges'] : [];

        if ($nodes === []) {
            return ['The flow is empty — add at least a start node and one step before activating.'];
        }

        $starts = array_values(array_filter(
            $nodes,
            fn ($n) => is_array($n) && ($n['type'] ?? null) === 'start'
        ));

        if ($starts === []) {
            return ['The flow has no start node, so nothing would ever trigger it.'];
        }

        $errors = [];

        if (count($starts) > 1) {
            $errors[] = 'The flow has ' . count($starts) . ' start nodes — keep exactly one so the entry point is unambiguous.';
        }

        // A start node with nothing wired to it is functionally an empty flow:
        // it would capture the conversation and then have nowhere to go.
        $startIds  = array_column($starts, 'id');
        $hasOutlet = false;
        foreach ($edges as $edge) {
            if (is_array($edge) && in_array($edge['source'] ?? null, $startIds, true)) {
                $hasOutlet = true;
                break;
            }
        }

        if (! $hasOutlet) {
            $errors[] = 'The start node isn\'t connected to anything — the flow would answer and then stall.';
        }

        return $errors;
    }

    /** Convenience wrapper for callers that only need a yes/no. */
    public function canActivate(): bool
    {
        return $this->activationErrors() === [];
    }

    /**
     * Default empty graph the editor opens on a brand-new flow. One
     * Start node so the canvas isn't completely blank.
     */
    public static function emptyDefinition(): array
    {
        return [
            'nodes' => [[
                'id'       => 'start',
                'type'     => 'start',
                'data'     => ['label' => 'Call connects'],
                'position' => ['x' => 80, 'y' => 60],
            ]],
            'edges'    => [],
            'settings' => [
                'language'     => 'en',
                'timeout_secs' => 8,
                'max_retries'  => 2,
            ],
        ];
    }
}
