<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tiny soft-deletes implementation tailored to our schema, where
 * `deleted_at` is an INTEGER unix timestamp (not Laravel's default
 * datetime). The built-in SoftDeletes trait writes Carbon instances
 * to the column, which would break our int cast.
 *
 * Behaviour:
 *   - A global scope hides rows where deleted_at IS NOT NULL from
 *     every query. Customer-facing pages don't have to change a
 *     single line — soft-deleted rows simply disappear.
 *   - Super-admin (ops console) can opt into seeing deleted rows
 *     via `Model::query()->withTrashedRows()` — a scope macro we
 *     register here.
 *   - softDelete() / restoreSoft() flip the column.
 *
 * Why not the built-in SoftDeletes trait?
 *   It expects deleted_at to be a datetime column and writes Carbon
 *   timestamps. Our migrations across master + tenant DBs store
 *   deleted_at as an unsigned integer. Forcing Carbon there causes
 *   query mismatches and silent integer truncation.
 */
trait IntSoftDeletes
{
    public static function bootIntSoftDeletes(): void
    {
        // Global scope — every query gets `WHERE deleted_at IS NULL`.
        // Use the qualified column so joins don't collide.
        static::addGlobalScope('not_soft_deleted', function (Builder $q) {
            $q->whereNull($q->getModel()->getTable() . '.deleted_at');
        });

        // Scope helper — ops controllers call ->withTrashedRows() to
        // see everything including deleted.
        Builder::macro('withTrashedRows', function () {
            return $this->withoutGlobalScope('not_soft_deleted');
        });
        Builder::macro('onlyTrashedRows', function () {
            return $this->withoutGlobalScope('not_soft_deleted')
                ->whereNotNull($this->getModel()->getTable() . '.deleted_at');
        });
    }

    public function initializeIntSoftDeletes(): void
    {
        // Make sure deleted_at is treated as int. (Already cast on
        // most of our models; this is a belt-and-braces.)
        if (! isset($this->casts['deleted_at'])) {
            $this->casts['deleted_at'] = 'integer';
        }
    }

    public function softDelete(): bool
    {
        $this->deleted_at = time();
        return $this->save();
    }

    public function restoreSoft(): bool
    {
        $this->deleted_at = null;
        return $this->save();
    }

    public function isSoftDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
