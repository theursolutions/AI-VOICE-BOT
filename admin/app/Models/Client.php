<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use IntSoftDeletes;

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
    ];

    protected $casts = [
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'deleted_at' => 'integer',
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
