<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A capability or limit a plan can grant. Definition only — the per-plan
 * value lives in {@see PlanFeature}.
 *
 * Two optional links wire a feature into the rest of the app:
 *   module_key — a key from config/modules.php, gated by EnsurePlanFeature
 *   metric_key — a key from config/billing.php `metrics`, enforced as a quota
 *                by UsageLimitService
 */
class Feature extends Model
{
    protected $connection = 'mysql';
    protected $table = 'features';

    public const TYPE_BOOLEAN   = 'boolean';
    public const TYPE_NUMERIC   = 'numeric';
    public const TYPE_UNLIMITED = 'unlimited';
    public const TYPE_TEXT      = 'text';

    protected $fillable = [
        'key', 'name', 'description', 'value_type', 'unit',
        'module_key', 'metric_key', 'group', 'sort_order',
        'is_visible', 'is_headline',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'is_visible'  => 'boolean',
        'is_headline' => 'boolean',
    ];

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class, 'feature_id');
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features', 'feature_id', 'plan_id')
                    ->withPivot(['value', 'is_highlighted', 'sort_order'])
                    ->withTimestamps();
    }

    public function scopeVisible($q)
    {
        return $q->where('is_visible', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('group')->orderBy('sort_order')->orderBy('id');
    }

    public function isNumeric(): bool
    {
        return $this->value_type === self::TYPE_NUMERIC;
    }

    public function isBoolean(): bool
    {
        return $this->value_type === self::TYPE_BOOLEAN;
    }

    /** Does this feature gate an admin module? */
    public function gatesModule(): bool
    {
        return ! empty($this->module_key);
    }

    /** Does this feature cap a metered usage counter? */
    public function capsUsage(): bool
    {
        return ! empty($this->metric_key);
    }
}
