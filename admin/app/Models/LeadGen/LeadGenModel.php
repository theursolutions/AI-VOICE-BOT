<?php

namespace App\Models\LeadGen;

use App\Services\Tenant\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Base for every Lead Generation model (tables `lg_*`, tenant DB).
 *
 * Two layers of isolation. The tenant connection already points at one
 * project's database; on top of that every query is scoped to the project
 * TenantManager has switched to, and a row can't be created for any other
 * project. With no project switched in, queries throw instead of running —
 * a job that forgot `useForProjectId()` fails loudly rather than reading
 * whatever database the worker last touched.
 *
 * Timestamps are unix ints, like the rest of the tenant schema.
 */
abstract class LeadGenModel extends Model
{
    protected $connection = 'tenant';
    protected $dateFormat = 'U';
    protected $guarded = ['id', 'project_id'];

    protected static function booted(): void
    {
        static::addGlobalScope('lg_project', function (Builder $q) {
            $q->where($q->getModel()->getTable() . '.project_id', static::currentProjectId());
        });

        static::creating(function (LeadGenModel $m) {
            $current = static::currentProjectId();
            if ($m->project_id !== null && (int) $m->project_id !== $current) {
                throw new LogicException('Lead Generation record belongs to another project.');
            }
            $m->project_id = $current;
        });
    }

    /** The project TenantManager has switched to. Throws when none is. */
    public static function currentProjectId(): int
    {
        $id = app(TenantManager::class)->currentProjectId();
        if (!$id) {
            throw new LogicException('Lead Generation queried with no project selected (call TenantManager::useFor first).');
        }
        return $id;
    }

    /** Integer casts for the timestamp columns, merged into each model's own casts. */
    public function getCasts()
    {
        $casts = parent::getCasts();
        foreach (['created_at', 'updated_at', 'deleted_at'] as $col) {
            $casts[$col] ??= 'integer';
        }
        return $casts;
    }
}
