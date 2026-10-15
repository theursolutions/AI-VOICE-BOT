<?php

namespace App\Services\LeadGen;

/**
 * Turns one source record into the common prospect shape, and normalises the
 * fields deduplication keys on. Pure functions, no I/O.
 *
 * Normalisation is conservative on purpose — a value we can't normalise with
 * confidence stays raw and simply doesn't take part in matching:
 *
 *   phone   E.164 only when the country is known or the number already has
 *           a + / 00 prefix. No guessing a country from digit counts.
 *   domain  the full host minus "www." — NOT the registrable domain. Two
 *           agents on agent1.realty-platform.ae / agent2.realty-platform.ae
 *           are different businesses and must not merge.
 *           Hosts shared by many businesses (social sites, link-in-bio,
 *           site builders' default domains) never become a dedup key.
 *   email   lowercased and syntax-checked; nothing else is inferred.
 */
class ProspectNormalizer
{
    /** The keys every source item is turned into. */
    public const FIELDS = [
        'business_name', 'category', 'website', 'domain_norm', 'phone_e164', 'email',
        'address', 'city', 'country_code', 'lat', 'lng', 'socials',
        'source_provider', 'source_record_id', 'source_url', 'source_license', 'source_meta',
        'field_meta',
    ];

    /** Calling codes for the markets in scope; extend as needed. */
    private const CALLING_CODES = [
        'AE' => '971', 'PK' => '92', 'SA' => '966', 'QA' => '974', 'KW' => '965', 'BH' => '973',
        'OM' => '968', 'EG' => '20', 'IN' => '91', 'BD' => '880', 'GB' => '44', 'IE' => '353',
        'US' => '1', 'CA' => '1', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33',
        'ES' => '34', 'IT' => '39', 'NL' => '31', 'TR' => '90', 'MY' => '60', 'SG' => '65',
        'ZA' => '27', 'NG' => '234', 'KE' => '254',
    ];

    /** Hosts many unrelated businesses share — never a dedup key. */
    private const SHARED_HOSTS = [
        'facebook.com', 'fb.com', 'm.facebook.com', 'instagram.com', 'twitter.com', 'x.com',
        'linkedin.com', 'youtube.com', 'tiktok.com', 'wa.me', 'whatsapp.com', 'api.whatsapp.com',
        'linktr.ee', 'bio.link', 'beacons.ai', 'google.com', 'maps.google.com', 'goo.gl',
        'business.site', 'sites.google.com', 'wixsite.com', 'wix.com', 'weebly.com',
        'wordpress.com', 'blogspot.com', 'squarespace.com', 'godaddysites.com', 'webflow.io',
        'yelp.com', 'tripadvisor.com', 'bayut.com', 'propertyfinder.ae', 'dubizzle.com',
        'zameen.com', 'olx.com.pk', 'bit.ly', 't.me', 'telegram.me',
    ];

    public static function phone(?string $raw, ?string $country): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $hasPlus = str_starts_with($raw, '+');
        $digits  = preg_replace('/\D+/', '', $raw);

        if ($hasPlus) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } else {
            $code = self::CALLING_CODES[strtoupper((string) $country)] ?? null;
            if (!$code) {
                return null;
            }
            // Already international without the plus (e.g. 971501234567).
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 6) {
                $e164 = $digits;
            } else {
                $e164 = $code . ltrim($digits, '0');
            }
        }

        return (strlen($e164) >= 8 && strlen($e164) <= 15) ? '+' . $e164 : null;
    }

    public static function email(?string $raw): ?string
    {
        $e = strtolower(trim((string) $raw));
        $e = preg_replace('/^mailto:/', '', $e);
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    /** Canonical website URL (scheme + host + path) or null. */
    public static function website(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $raw)) {
            $raw = 'https://' . $raw;
        }
        $p = parse_url($raw);
        if (!$p || empty($p['host']) || !str_contains($p['host'], '.')) {
            return null;
        }
        $host = strtolower($p['host']);
        $path = rtrim($p['path'] ?? '', '/');
        return strtolower($p['scheme']) . '://' . $host . $path;
    }

    /** Dedup host for a website, or null for shared/unusable hosts. */
    public static function domain(?string $website): ?string
    {
        $url = self::website($website);
        if (!$url) {
            return null;
        }
        $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
        foreach (self::SHARED_HOSTS as $shared) {
            if ($host === $shared || str_ends_with($host, '.' . $shared)) {
                return null;
            }
        }
        return $host ?: null;
    }

    /** Is this URL a social profile? Returns the platform key or null. */
    public static function socialPlatform(string $url): ?string
    {
        $host = strtolower((string) parse_url(self::website($url) ?? '', PHP_URL_HOST));
        $host = preg_replace('/^(www|m)\./', '', $host);
        return match (true) {
            in_array($host, ['facebook.com', 'fb.com'], true) => 'facebook',
            $host === 'instagram.com' => 'instagram',
            $host === 'linkedin.com' => 'linkedin',
            in_array($host, ['twitter.com', 'x.com'], true) => 'x',
            $host === 'tiktok.com' => 'tiktok',
            $host === 'youtube.com' => 'youtube',
            in_array($host, ['wa.me', 'api.whatsapp.com'], true) => 'whatsapp',
            default => null,
        };
    }

    /** Lowercase, accent-free, punctuation-free, legal suffixes dropped — for name similarity. */
    public static function nameKey(string $name): string
    {
        $n = mb_strtolower($name);
        $n = function_exists('transliterator_transliterate')
            ? (transliterator_transliterate('Any-Latin; Latin-ASCII', $n) ?: $n)
            : $n;
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);
        $n = preg_replace('/\b(llc|l l c|fze|fzco|fz llc|ltd|limited|pvt|private|co|company|inc|est|establishment|real estate brokers?|properties)\b/u', ' ', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }

    /**
     * Build a prospect array. $field_meta is filled for every contact field
     * with where it came from and "unverified" — sources' contact data is a
     * claim, not a fact, until enrichment or a person confirms it.
     */
    public static function make(array $in, string $provider, string $sourceLabel): array
    {
        $country = isset($in['country_code']) ? strtoupper((string) $in['country_code']) : null;
        $website = self::website($in['website'] ?? null);
        $phone   = self::phone($in['phone'] ?? null, $country);
        $email   = self::email($in['email'] ?? null);
        $now     = time();

        $socials = [];
        foreach ((array) ($in['socials'] ?? []) as $url) {
            $url = self::website((string) $url);
            if ($url && ($platform = self::socialPlatform($url))) {
                $socials[] = ['platform' => $platform, 'url' => $url];
            }
        }

        $meta = [];
        foreach (['website' => $website, 'phone' => $phone, 'email' => $email] as $field => $value) {
            if ($value) {
                $meta[$field] = ['source' => $sourceLabel, 'found_at' => $now, 'verification' => 'unverified'];
            }
        }
        // A number we couldn't put in E.164 is kept for a person to read, but
        // never used for matching or sending.
        $rawPhone = trim((string) ($in['phone'] ?? ''));
        if (!$phone && $rawPhone !== '') {
            $meta['phone'] = ['source' => $sourceLabel, 'found_at' => $now, 'verification' => 'unverified', 'raw' => mb_substr($rawPhone, 0, 64)];
        }
        if ($email) {
            $meta['email']['verification'] = 'syntax_ok';
        }

        return [
            'business_name'    => mb_substr(trim((string) ($in['business_name'] ?? '')), 0, 255),
            'category'         => isset($in['category']) ? mb_substr((string) $in['category'], 0, 120) : null,
            'website'          => $website ? mb_substr($website, 0, 500) : null,
            'domain_norm'      => self::domain($website),
            'phone_e164'       => $phone,
            'email'            => $email,
            'address'          => $in['address'] ?? null,
            'city'             => isset($in['city']) ? mb_substr((string) $in['city'], 0, 120) : null,
            'country_code'     => $country && strlen($country) === 2 ? $country : null,
            'lat'              => isset($in['lat']) ? (float) $in['lat'] : null,
            'lng'              => isset($in['lng']) ? (float) $in['lng'] : null,
            'socials'          => $socials ?: null,
            'source_provider'  => $provider,
            'source_record_id' => mb_substr((string) $in['source_record_id'], 0, 191),
            'source_url'       => isset($in['source_url']) ? mb_substr((string) $in['source_url'], 0, 500) : null,
            'source_license'   => isset($in['source_license']) ? mb_substr((string) $in['source_license'], 0, 80) : null,
            'source_meta'      => $in['source_meta'] ?? null,
            'field_meta'       => $meta ?: null,
        ];
    }
}
