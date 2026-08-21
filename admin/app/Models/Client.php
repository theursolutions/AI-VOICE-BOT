<?php

namespace App\Models;

use App\Models\Concerns\Billable;
use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use IntSoftDeletes;

    /**
     * The workspace is the BILLABLE entity — subscriptions, Stripe customer,
     * plan entitlements and usage quotas all hang off it. See the trait.
     */
    use Billable;

    // Lives in the master DB. Pin explicitly so cross-connection relations
    // from tenant models don't try to read `tenant.clients`.
    protected $connection = 'mysql';

    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'client_api_key',
        'logo',
        'description',
        // ── Billing (see App\Models\Concerns\Billable) ────────────────
        'stripe_customer_ref',
        'billing_email',
        'billing_name',
        'billing_country',
        'pm_type',
        'pm_last_four',
        'current_plan_id',
        'billing_status',
        'access_state',
        'billing_synced_at',
    ];

    protected $casts = [
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',

        // Billing columns are proper datetimes even though this legacy table
        // keeps its own created/updated as integer unix stamps. The two
        // conventions coexist deliberately — see the migration's note.
        'billing_synced_at' => 'datetime',
        'current_plan_id'   => 'integer',

        // The workspace settings bag, same convention as projects.json_data.
        //
        // The column has always been json and the cast was simply never added,
        // so reads came back as a raw string and data_get() silently found
        // nothing in it — a stored setting read as absent and fell through to
        // its default with no error anywhere. Nothing else in the codebase
        // touched this column, which is why it went unnoticed.
        'json_data'         => 'array',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->getTimestamp();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users', 'client_id', 'user_id')
                    ->withPivot('project_id', 'assigned_by', 'assigned_at')
                    ->distinct();
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
