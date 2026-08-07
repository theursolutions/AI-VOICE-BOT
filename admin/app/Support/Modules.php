<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Platform-wide module switchboard.
 *
 * Super-admins can switch any admin module ON/OFF for the whole platform
 * from the Ops Console (ops.modules.*). The disabled set is stored as a
 * single JSON array in `site_settings` under the key `modules.disabled`.
 *
 * A module that's switched OFF:
 *   • is hidden from the customer sidebar (AppServiceProvider composer),
 *   • is hidden from the Roles & Permissions matrix (RoleWebController),
 *   • returns the "under development" page on a direct hit
 *     (EnsureModuleEnabled middleware).
 *
 * This is independent of (and runs ahead of) the per-role RBAC gate in
 * EnsureModuleAccess — a disabled module is off for everyone, owners
 * included.
 */
class Modules
{
    /** Settings key holding the array of disabled module keys. */
    public const SETTING_KEY = 'modules.disabled';

    /**
     * Modules that can never be switched off — disabling them would lock
     * users out of the workspace entirely (Dashboard is every member's
     * landing page and RBAC always grants it).
     */
    public const ALWAYS_ON = ['dashboard'];

    /** The full module registry (config/modules.php): key => [label, routes]. */
    public static function all(): array
    {
        return (array) config('modules', []);
    }

    /** All registry keys. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Keys an operator may toggle (everything except the always-on set). */
    public static function toggleableKeys(): array
    {
        return array_values(array_diff(self::keys(), self::ALWAYS_ON));
    }

    /** Keys currently switched OFF platform-wide (always-on can't appear here). */
    public static function disabledKeys(): array
    {
        $stored = SiteSetting::get(self::SETTING_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        // Keep only real registry keys, and never report an always-on
        // module as disabled.
        return array_values(array_intersect(
            array_diff($stored, self::ALWAYS_ON),
            self::keys(),
        ));
    }

    /** Keys currently switched ON. */
    public static function enabledKeys(): array
    {
        return array_values(array_diff(self::keys(), self::disabledKeys()));
    }

    /** Is the given module switched on platform-wide? Unknown keys = on. */
    public static function isEnabled(string $key): bool
    {
        return ! in_array($key, self::disabledKeys(), true);
    }

    /** Persist the disabled set (sanitised to toggleable registry keys). */
    public static function setDisabled(array $keys): void
    {
        $clean = array_values(array_intersect(
            array_unique(array_map('strval', $keys)),
            self::toggleableKeys(),
        ));

        SiteSetting::set(self::SETTING_KEY, $clean);
    }

    /**
     * Map a route NAME to its owning module key, or null for utility/shared
     * routes that don't belong to a gated module. Shared by both the RBAC
     * gate and the module-enabled gate so the two never drift.
     */
    public static function moduleForRoute(string $routeName): ?string
    {
        if ($routeName === '') {
            return null;
        }

        foreach (self::all() as $key => $cfg) {
            foreach (($cfg['routes'] ?? []) as $prefix) {
                if ($routeName === $prefix || str_starts_with($routeName, $prefix . '.')) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** Human label for a module key (falls back to the key itself). */
    public static function label(string $key): string
    {
        return (string) data_get(self::all(), "{$key}.label", $key);
    }
}
