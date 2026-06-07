<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, IntSoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'active_client_id',
        'last_picked_at',
        'is_super_admin',
        'role',
        'is_disabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'active_client_id'  => 'integer',
        'last_picked_at'    => 'integer',
        'is_super_admin'    => 'boolean',
        'is_disabled'       => 'boolean',
        'deleted_at'        => 'integer',
    ];

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function canAuthenticate(): bool
    {
        return !$this->is_disabled && $this->deleted_at === null;
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_users', 'user_id', 'project_id')
                    ->withPivot('client_id', 'assigned_by', 'assigned_at');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'project_users', 'user_id', 'client_id')
                    ->withPivot('project_id', 'assigned_by', 'assigned_at')
                    ->distinct();
    }

    public function activeClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'active_client_id');
    }

    public function hasMembership(int $clientId): bool
    {
        return $this->clients()->where('clients.id', $clientId)->exists();
    }

    public function attachMembership(int $clientId, ?int $projectId = null, ?int $assignedBy = null): void
    {
        $q = \DB::table('project_users')
            ->where('user_id', $this->id)
            ->where('client_id', $clientId);

        $projectId
            ? $q->where('project_id', $projectId)
            : $q->whereNull('project_id');

        if ($q->exists()) {
            return;
        }

        \DB::table('project_users')->insert([
            'user_id'     => $this->id,
            'client_id'   => $clientId,
            'project_id'  => $projectId,
            'assigned_by' => $assignedBy,
            'assigned_at' => time(),
            'created_at'  => time(),
            'updated_at'  => time(),
        ]);
    }
}
