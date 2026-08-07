<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-client (agency) role: a named bundle of allowed module keys.
 *
 * `is_owner` marks the all-access system role auto-created for each client
 * — it always passes every permission check regardless of `modules`.
 * `modules` is an array of keys from config/modules.php, or ["*"] for all.
 */
class Role extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'name', 'modules', 'is_owner', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'modules'  => 'array',
        'is_owner' => 'boolean',
    ];

    /** All module keys, from the registry. */
    public static function allModuleKeys(): array
    {
        return array_keys((array) config('modules', []));
    }

    /** Does this role grant access to a given module key? */
    public function allowsModule(string $key): bool
    {
        // Dashboard is the universal landing — always reachable so a member
        // can never be locked out on login regardless of their role.
        if ($key === 'dashboard' || $this->is_owner) {
            return true;
        }
        $modules = (array) $this->modules;
        return in_array('*', $modules, true) || in_array($key, $modules, true);
    }

    /** Resolve the effective list of allowed module keys. */
    public function moduleKeys(): array
    {
        if ($this->is_owner) {
            return self::allModuleKeys();
        }
        $modules = (array) $this->modules;
        $keys = in_array('*', $modules, true) ? self::allModuleKeys() : array_values($modules);
        // Dashboard always included.
        return array_values(array_unique(array_merge(['dashboard'], $keys)));
    }
}
