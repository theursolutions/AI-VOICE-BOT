<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide marketing-site settings (SEO + landing content).
 *
 * Typed key→value store on the master DB. Values are JSON-encoded so
 * strings, booleans, and arrays all round-trip cleanly. Defaults live in
 * config/site.php — a row here overrides the matching default.
 *
 * Read through the static helpers (cached per-request); never query the
 * model directly from views — use the tva_setting() / tva_seo_all()
 * helpers in app/Helpers/Function.php.
 */
class SiteSetting extends Model
{
    protected $connection = 'mysql';
    protected $table = 'site_settings';

    protected $fillable = ['key', 'group', 'value'];

    /** In-request cache of the whole (tiny) table: key => decoded value. */
    protected static ?array $cache = null;

    /** Load (and memoise) every setting. Degrades to [] if the table is absent. */
    protected static function loadAll(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        try {
            foreach (self::query()->get(['key', 'value']) as $row) {
                self::$cache[$row->key] = json_decode((string) $row->value, true);
            }
        } catch (\Throwable $e) {
            // Migration not yet run, or DB unreachable — callers fall back
            // to config/site.php defaults.
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::loadAll();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        $group = explode('.', $key)[0] ?: 'general';

        self::query()->updateOrCreate(
            ['key' => $key],
            ['group' => $group, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            self::set($key, $value);
        }
    }

    /**
     * All stored settings whose key starts with "{$prefix}.", returned
     * with the prefix stripped: group('seo') => ['meta_title' => …].
     */
    public static function group(string $prefix): array
    {
        $all = self::loadAll();
        $needle = $prefix . '.';
        $len = strlen($needle);
        $out = [];

        foreach ($all as $key => $value) {
            if (str_starts_with($key, $needle)) {
                $out[substr($key, $len)] = $value;
            }
        }

        return $out;
    }

    /** Drop the in-request cache (call after a bulk write outside set()). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
