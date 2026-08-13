<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer-defined label for where a conversation stands — "Waiting on
 * customer", "Escalated", "Won" — as opposed to sessions.status, which is
 * machine state owned by the engine.
 *
 * See the migration for why this is a table and not a widened enum.
 */
class ConversationStatus extends Model
{
    use IntSoftDeletes;

    protected $connection = 'tenant';
    protected $table = 'conversation_statuses';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * The palette the UI offers.
     *
     * A fixed set rather than a colour picker: every one of these clears
     * contrast against both the light and dark chat themes, which free-text
     * hex does not — a customer picking #f8fafc would produce an invisible
     * status and read it as a bug.
     */
    public const PALETTE = [
        '#64748b', // slate
        '#4f46e5', // indigo
        '#0ea5e9', // sky
        '#059669', // emerald
        '#d97706', // amber
        '#dc2626', // red
        '#c026d3', // fuchsia
        '#0d9488', // teal
    ];

    /**
     * What a project starts with.
     *
     * Seeded on first use rather than shipped in a seeder, so an existing
     * tenant gets them the first time someone opens the inbox instead of
     * facing an empty dropdown and no clue what to do with it. Chosen to be a
     * usable handover pipeline out of the box — and to be renamed.
     */
    public const DEFAULTS = [
        ['name' => 'Open',                'color' => '#0ea5e9', 'is_default' => true,  'is_closing' => false],
        ['name' => 'Waiting on customer', 'color' => '#d97706', 'is_default' => false, 'is_closing' => false],
        ['name' => 'Escalated',           'color' => '#dc2626', 'is_default' => false, 'is_closing' => false],
        ['name' => 'Resolved',            'color' => '#059669', 'is_default' => false, 'is_closing' => true],
    ];

    protected $fillable = [
        'project_id', 'name', 'color', 'sort_order',
        'is_default', 'is_closing', 'status',
    ];

    protected $casts = [
        'is_default'  => 'boolean',
        'is_closing'  => 'boolean',
        'sort_order'  => 'integer',
        'created_at'  => 'integer',
        'update_at'   => 'integer',
        'deleted_at'  => 'integer',
    ];

    public $timestamps = false;

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Is this tenant DB migrated for statuses?
     *
     * Tenant migrations run per project and can lag a deploy, so every entry
     * point checks. Without this, opening any conversation on an un-migrated
     * tenant would 500 on a missing table — taking the whole inbox down over
     * a feature that is meant to be optional.
     */
    public static function available(): bool
    {
        try {
            $schema = \Illuminate\Support\Facades\DB::connection('tenant')->getSchemaBuilder();

            return $schema->hasTable('conversation_statuses')
                && $schema->hasColumn('sessions', 'conversation_status_id');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** This project's statuses, seeding the defaults on first call. */
    public static function forProject(int $projectId)
    {
        if (! static::available()) {
            return collect();
        }

        $rows = static::where('project_id', $projectId)->active()
            ->orderBy('sort_order')->orderBy('id')->get();

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        foreach (self::DEFAULTS as $i => $d) {
            static::create($d + [
                'project_id' => $projectId,
                'sort_order' => $i,
                'created_at' => time(),
            ]);
        }

        return static::where('project_id', $projectId)->active()
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Only one default per project — enforced here, not by a constraint. */
    public function makeSoleDefault(): void
    {
        static::where('project_id', $this->project_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);
    }
}
