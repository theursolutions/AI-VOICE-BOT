<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-client (agency) role: a named bundle of allowed module keys.
 *
 * `is_owner` marks the all-access system role auto-created for each client
 * — it always passes every permission check regardless of `modules`.
 * `modules` is an array of keys from config/modules.php, or ["*"] for all.
 * `abilities` narrows a module that declares abilities: {"leadgen": ["view"]}.
 */
class Role extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'client_id', 'name', 'modules', 'abilities', 'is_owner', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'modules'   => 'array',
        'abilities' => 'array',
        'is_owner'  => 'boolean',
    ];

    /** Ability keys a module declares (config/modules.php), or [] when it has none. */
    public static function abilityKeys(string $module): array
    {
        return array_keys((array) config("modules.{$module}.abilities", []));
    }

    /**
     * Does this role allow one action inside a module?
     *
     * The module itself must be allowed first. A module with no stored list
     * grants every ability — that is how roles created before abilities
     * existed keep working, and how "all" keeps covering abilities added later.
     */
    public function allowsAbility(string $module, string $ability): bool
    {
        if ($this->is_owner) {
            return true;
        }
        if (!$this->allowsModule($module)) {
            return false;
        }
        $map = (array) $this->abilities;
        if (!array_key_exists($module, $map)) {
            return true;
        }
        return in_array($ability, (array) $map[$module], true);
    }

    /** The effective ability keys for a module (all of them when not narrowed). */
    public function abilitiesFor(string $module): array
    {
        $all = self::abilityKeys($module);
        if ($this->is_owner) {
            return $all;
        }
        if (!$this->allowsModule($module)) {
            return [];
        }
        $map = (array) $this->abilities;
        return array_key_exists($module, $map)
            ? array_values(array_intersect($all, (array) $map[$module]))
            : $all;
    }

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
