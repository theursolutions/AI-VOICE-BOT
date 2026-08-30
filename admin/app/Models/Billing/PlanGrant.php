<?php

namespace App\Models\Billing;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Allowance a super admin has given a workspace outside its plan.
 *
 * Composed into PlanFeatureService::clientLimit() as a third term alongside the
 * plan and purchased add-ons, so every limit check in the product respects it
 * without knowing it exists.
 *
 * @property int         $client_id
 * @property string      $feature_key
 * @property int         $value        -1 means unlimited
 * @property string|null $note
 * @property int|null    $granted_by
 */
class PlanGrant extends Model
{
    public const UNLIMITED = -1;

    protected $fillable = [
        'client_id', 'feature_key', 'value', 'note', 'granted_by', 'expires_at',
    ];

    protected $casts = [
        'client_id'  => 'integer',
        'value'      => 'integer',
        'granted_by' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Grants that are actually in force.
     *
     * An expired grant is kept rather than deleted — the record of what was
     * given and when is the reason this is a table — so every read has to
     * exclude it. Doing that in a scope rather than at each call site is what
     * stops a lapsed grant quietly going on applying somewhere nobody checked.
     */
    public function scopeInForce(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUnlimited(): bool
    {
        return $this->value === self::UNLIMITED;
    }

    /** The feature this tops up, if it still exists. */
    public function feature(): ?Feature
    {
        return Feature::where('key', $this->feature_key)->first();
    }
}
