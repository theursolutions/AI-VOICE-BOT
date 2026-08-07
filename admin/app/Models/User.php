<?php

namespace App\Models;

use App\Models\Concerns\IntSoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, Notifiable, IntSoftDeletes;

    /**
     * Verification uses a 6-digit OTP we email (see EmailOtpService), not
     * Laravel's default signed-link notification. Overriding this routes
     * both the post-registration Registered event and the "resend" action
     * through the OTP flow.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(\App\Services\Auth\EmailOtpService::class)->send($this);
    }

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

    public function attachMembership(int $clientId, ?int $projectId = null, ?int $assignedBy = null, ?int $roleId = null): void
    {
        $q = \DB::table('project_users')
            ->where('user_id', $this->id)
            ->where('client_id', $clientId);

        $projectId
            ? $q->where('project_id', $projectId)
            : $q->whereNull('project_id');

        if ($existing = $q->first()) {
            // Keep the role in sync if a new one is supplied.
            if ($roleId !== null && (int) ($existing->role_id ?? 0) !== $roleId) {
                \DB::table('project_users')->where('id', $existing->id)->update([
                    'role_id' => $roleId, 'updated_at' => time(),
                ]);
            }
            return;
        }

        \DB::table('project_users')->insert([
            'user_id'     => $this->id,
            'client_id'   => $clientId,
            'project_id'  => $projectId,
            'role_id'     => $roleId,
            'assigned_by' => $assignedBy,
            'assigned_at' => time(),
            'created_at'  => time(),
            'updated_at'  => time(),
        ]);
    }

    // ── RBAC helpers ─────────────────────────────────────────────────
    // Permissions are per-client (agency): a member's role on a client
    // grants a set of modules; their membership rows scope which projects
    // they can see (a null project_id row = all of the client's projects).

    /** The role this user holds on the given client (null if none). */
    public function roleForClient(int $clientId): ?\App\Models\Role
    {
        $row = \DB::table('project_users')
            ->where('user_id', $this->id)
            ->where('client_id', $clientId)
            ->whereNotNull('role_id')
            ->first();
        return $row ? \App\Models\Role::find($row->role_id) : null;
    }

    /** Is this user the all-access owner of the client? */
    public function isOwnerOf(int $clientId): bool
    {
        $role = $this->roleForClient($clientId);
        return $role ? (bool) $role->is_owner : false;
    }

    /** Module keys this user may access on the client. Owner = all. */
    public function allowedModules(int $clientId): array
    {
        $role = $this->roleForClient($clientId);
        return $role ? $role->moduleKeys() : [];
    }

    /** Can this user open a given module on the client? */
    public function canModule(int $clientId, string $key): bool
    {
        $role = $this->roleForClient($clientId);
        return $role ? $role->allowsModule($key) : false;
    }

    /**
     * Project ids this user is scoped to on the client.
     * null = ALL projects (a client-wide membership row exists);
     * []   = none; [..] = the specific assigned projects.
     */
    public function allowedProjectIds(int $clientId): ?array
    {
        $rows = \DB::table('project_users')
            ->where('user_id', $this->id)
            ->where('client_id', $clientId)
            ->get(['project_id']);

        if ($rows->isEmpty()) {
            return [];
        }
        // Any client-wide row (null project) → access to everything.
        if ($rows->contains(fn ($r) => $r->project_id === null)) {
            return null;
        }
        return $rows->pluck('project_id')->filter()->map(fn ($v) => (int) $v)->values()->all();
    }
}
