<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value a specific plan grants for a specific feature.
 *
 * A MISSING ROW MEANS NOT GRANTED. Adding a feature to the catalogue never
 * silently hands it to existing plans — the safe direction for billing to
 * fail in.
 */
class PlanFeature extends Model
{
    protected $connection = 'mysql';
    protected $table = 'plan_features';

    /** Sentinel stored in `value` to mean "no ceiling". NULL means the same. */
    public const UNLIMITED = '-1';

    protected $fillable = [
        'plan_id', 'feature_id', 'value', 'is_highlighted', 'sort_order',
    ];

    protected $casts = [
        'plan_id'        => 'integer',
        'feature_id'     => 'integer',
        'is_highlighted' => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'feature_id');
    }

    public function isUnlimited(): bool
    {
        return $this->value === null
            || $this->value === self::UNLIMITED
            || strtolower((string) $this->value) === 'unlimited';
    }

    /** Numeric ceiling, or null for unlimited. */
    public function numericValue(): ?int
    {
        return $this->isUnlimited() ? null : (int) $this->value;
    }

    public function booleanValue(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }
}
