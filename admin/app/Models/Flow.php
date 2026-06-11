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
