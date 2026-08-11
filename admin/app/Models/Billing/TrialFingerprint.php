<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

/**
 * Records an identity that has already consumed a free window / trial.
 *
 * Values are stored as sha256 hashes: we only ever compare for equality, and
 * a plaintext table of every signup email and card fingerprint would be a
 * liability with no upside.
 */
class TrialFingerprint extends Model
{
    protected $connection = 'mysql';
    protected $table = 'trial_fingerprints';

    public const KIND_USER   = 'user';
    public const KIND_EMAIL  = 'email';
    public const KIND_CARD   = 'card';
    public const KIND_DOMAIN = 'domain';

    public const FOR_FREE_WINDOW = 'free_window';
    public const FOR_TRIAL       = 'trial';

    protected $fillable = [
        'kind', 'value_hash', 'client_id', 'user_id',
        'consumed_for', 'consumed_at', 'is_waived', 'waived_by', 'waived_at',
    ];

    protected $casts = [
        'client_id'   => 'integer',
        'user_id'     => 'integer',
        'is_waived'   => 'boolean',
        'consumed_at' => 'datetime',
        'waived_at'   => 'datetime',
    ];

    public static function hash(string $value): string
    {
        return hash('sha256', mb_strtolower(trim($value)));
    }

    /**
     * Normalise an email so alias tricks collapse to one identity:
     *   Me+test@Gmail.com  →  me@gmail.com
     *   m.e+x@googlemail.com → me@gmail.com
     *
     * Dot-stripping is applied ONLY to Google-family domains, where dots are
     * genuinely ignored. Doing it everywhere would wrongly merge distinct
     * addresses on providers that treat dots as significant.
     */
    public static function normaliseEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        // Strip +tag — supported by Gmail, Outlook, Fastmail, Proton, others.
        if (($plus = strpos($local, '+')) !== false) {
            $local = substr($local, 0, $plus);
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local  = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local . '@' . $domain;
    }

    /** Registrable-ish domain from a URL or bare host. Best-effort. */
    public static function normaliseDomain(string $urlOrHost): ?string
    {
        $host = parse_url($urlOrHost, PHP_URL_HOST) ?: $urlOrHost;
        $host = mb_strtolower(trim((string) $host));
        $host = preg_replace('/^www\./', '', $host);

        return ($host === '' || ! str_contains($host, '.')) ? null : $host;
    }
}
