<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Platform-wide payment switchboard.
 *
 * Which providers may take money, and which countries we will take it from.
 * Both live in `site_settings` and are edited from Ops → Payments, so turning
 * a provider on or off is an operator action rather than a deploy.
 *
 * ENABLED IS STORED EXPLICITLY, unlike the module switchboard which stores what
 * is DISABLED. The asymmetry is deliberate and is about money: with a disabled
 * list, adding a new gateway to the registry would silently switch it on for
 * every customer the moment it shipped. With an enabled list, a new gateway is
 * off until somebody says otherwise — the direction you want to be wrong in.
 *
 * A gateway with no credentials is never enabled whatever the setting says.
 * "Enabled" is permission, not capability, and offering a customer a provider
 * that cannot be reached is worse than not offering it.
 *
 * THE COUNTRY LIST HAS THREE STATES, not two:
 *
 *   never set  →  every country we have a currency for      (the default)
 *   a list     →  exactly those countries
 *   an empty list → nobody, deliberately (a kill switch)
 *
 * `null` and `[]` therefore mean opposite things, which is why the unset case
 * is checked explicitly rather than being coalesced away.
 */
class Payments
{
    /** Gateways an operator has switched on. Absent = "not decided yet". */
    public const SETTING_GATEWAYS = 'payments.gateways.enabled';

    /** Countries we accept payment from. Absent = all of them. */
    public const SETTING_COUNTRIES = 'payments.countries.allowed';

    /** How the sticker price relates to tax. See taxMode(). */
    public const SETTING_TAX_MODE = 'payments.tax.mode';

    /** Percentage we add ourselves, where no provider does it for us. */
    public const SETTING_TAX_RATES = 'payments.tax.rates';

    /**
     * Tax modes, in the vocabulary of the people who set them.
     *
     *   inclusive  the price IS the total. Tax is carved out of it for
     *              reporting, and the customer pays exactly what the card says.
     *   exclusive  tax is ADDED at checkout, so the customer pays more than the
     *              sticker price.
     *   auto       whichever the customer's country expects — inclusive in the
     *              UK and EU where quoting ex-VAT to a consumer is unlawful,
     *              exclusive in the US where sales tax is added at the till.
     *
     * `auto` is the honest default for anyone selling internationally, because
     * a single choice is wrong somewhere: an American shown a tax-inclusive
     * price thinks you are expensive, and a German shown an ex-VAT one thinks
     * you are cheap until the invoice arrives.
     */
    public const TAX_MODES = ['inclusive', 'exclusive', 'auto'];

    /**
     * Every gateway the application knows how to talk to, in preference order
     * within a country. Keys match PaymentGateway::key().
     */
    public const KNOWN = ['safepay', 'payfast', 'paddle', 'stripe'];

    /**
     * Which gateway serves which country.
     *
     * A country listed here is served by that gateway; everything else falls to
     * the international one. Pakistan is separate because Safepay settles PKR
     * and takes JazzCash and Easypaisa, none of which an international
     * processor will do.
     */
    public const LOCAL_COUNTRIES = ['PK' => ['safepay', 'payfast']];

    /** Gateways used for every country not named above. */
    public const INTERNATIONAL = ['paddle', 'stripe'];

    // ── Gateways ─────────────────────────────────────────────────────

    /**
     * Gateway keys an operator has switched on.
     *
     * When nothing has been saved this returns every KNOWN key, so a fresh
     * install behaves as it did before the switchboard existed — the
     * configured-credentials check downstream is what actually decides. Once an
     * operator saves anything, their list is the whole truth.
     */
    public static function enabledGateways(): array
    {
        $stored = SiteSetting::get(self::SETTING_GATEWAYS);

        if (! is_array($stored)) {
            return self::KNOWN;
        }

        return array_values(array_intersect($stored, self::KNOWN));
    }

    public static function gatewayEnabled(string $key): bool
    {
        return in_array($key, self::enabledGateways(), true);
    }

    /** @param array<int, string> $keys */
    public static function setEnabledGateways(array $keys): void
    {
        SiteSetting::set(
            self::SETTING_GATEWAYS,
            array_values(array_intersect(array_map('strval', $keys), self::KNOWN)),
        );
    }

    // ── Countries ────────────────────────────────────────────────────

    /**
     * Countries we will take payment from, or null when unrestricted.
     *
     * Null rather than "all of them" so callers can tell an operator who has
     * never touched this from one who has deliberately narrowed it — the UI
     * shows those differently, and an empty saved list must not read as "no
     * restriction".
     *
     * @return array<int, string>|null
     */
    public static function allowedCountries(): ?array
    {
        $stored = SiteSetting::get(self::SETTING_COUNTRIES);

        if (! is_array($stored)) {
            return null;
        }

        return array_values(array_unique(array_map(
            fn ($c) => strtoupper(trim((string) $c)),
            $stored,
        )));
    }

    /** Is this country one we can sell to? Unknown/blank countries are allowed. */
    public static function countryAllowed(?string $code): bool
    {
        $allowed = self::allowedCountries();

        if ($allowed === null) {
            return true;
        }

        $code = strtoupper(trim((string) $code));

        // A visitor we could not place is not a visitor we should refuse: the
        // detection failing is our problem, not theirs, and they still see the
        // international gateway.
        return $code === '' || in_array($code, $allowed, true);
    }

    /** @param array<int, string> $codes */
    public static function setAllowedCountries(array $codes): void
    {
        $known = array_keys((array) config('billing.country_currency', []));

        SiteSetting::set(self::SETTING_COUNTRIES, array_values(array_intersect(
            array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes)),
            $known,
        )));
    }

    /** Remove the restriction entirely, which is not the same as saving none. */
    public static function allowAllCountries(): void
    {
        SiteSetting::set(self::SETTING_COUNTRIES, null);
    }

    // ── Tax ──────────────────────────────────────────────────────────

    /** inclusive | exclusive | auto. Defaults to inclusive — see TAX_MODES. */
    public static function taxMode(): string
    {
        $stored = SiteSetting::get(self::SETTING_TAX_MODE);

        return in_array($stored, self::TAX_MODES, true) ? $stored : 'inclusive';
    }

    public static function setTaxMode(string $mode): void
    {
        SiteSetting::set(
            self::SETTING_TAX_MODE,
            in_array($mode, self::TAX_MODES, true) ? $mode : 'inclusive',
        );
    }

    /**
     * The rate WE apply, for a country, where no provider applies one for us.
     *
     * Only ever used for gateways where we are the seller of record — a
     * Merchant of Record computes its own rates in forty jurisdictions and
     * adding ours on top would charge tax twice.
     *
     * Stored as a percentage (16 means 16%), because that is how an operator
     * reads a tax rate and how the law states it.
     */
    public static function taxRateFor(?string $country): float
    {
        $rates = SiteSetting::get(self::SETTING_TAX_RATES);

        if (! is_array($rates)) {
            return 0.0;
        }

        $code = strtoupper(trim((string) $country));

        // A country's own rate, then a blanket default, then nothing. Nothing
        // is the safe end: charging no tax is a bookkeeping problem, charging
        // an invented one is a refund and an apology.
        return (float) ($rates[$code] ?? $rates['*'] ?? 0);
    }

    /** @return array<string, float> country code (or `*`) => percentage */
    public static function taxRates(): array
    {
        $rates = SiteSetting::get(self::SETTING_TAX_RATES);

        return is_array($rates) ? array_map('floatval', $rates) : [];
    }

    /** @param array<string, float|string> $rates */
    public static function setTaxRates(array $rates): void
    {
        $clean = [];

        foreach ($rates as $code => $rate) {
            $code = strtoupper(trim((string) $code));
            $rate = (float) $rate;

            // A rate of zero is the same as no rate, and keeping the row would
            // only make the settings page claim a policy nobody set.
            if ($code === '' || $rate <= 0 || $rate > 100) {
                continue;
            }

            $clean[$code] = round($rate, 3);
        }

        ksort($clean);

        SiteSetting::set(self::SETTING_TAX_RATES, $clean);
    }

    // ── Selling ──────────────────────────────────────────────────────

    /**
     * Every country we can sell to, as [code => ['name' => …, 'currency' => …]].
     *
     * Sorted by name, because this is what fills a picker and a list ordered by
     * ISO code is a list nobody can find their own country in.
     */
    public static function sellableCountries(): array
    {
        $map     = (array) config('billing.country_currency', []);
        $allowed = self::allowedCountries();

        // Names come from the geo service, which prefers ext-intl and falls
        // back to its own table. Duplicating that here would give the picker
        // and the price line two different names for the same country.
        $geo = app(\App\Services\Geo\GeoLocationService::class);

        $out = [];

        foreach ($map as $code => $currency) {
            $code = strtoupper($code);

            if ($allowed !== null && ! in_array($code, $allowed, true)) {
                continue;
            }

            $out[$code] = [
                'code'     => $code,
                'name'     => $geo->countryName($code) ?: $code,
                'currency' => strtoupper((string) $currency),
                'flag'     => self::flag($code),
            ];
        }

        uasort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * The country's flag as a regional-indicator emoji pair.
     *
     * Derived rather than stored: every ISO-3166 alpha-2 code maps to its flag
     * by a fixed offset, so there is no asset to ship, nothing to 404, and no
     * list to keep in step with the currency map.
     *
     * Windows renders these as the two letters rather than a flag, which is
     * legible enough to be an acceptable fallback — the picker shows the
     * country name beside it either way.
     */
    public static function flag(string $code): string
    {
        $code = strtoupper(trim($code));

        if (strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '';
        }

        // 0x1F1E6 is REGIONAL INDICATOR SYMBOL LETTER A.
        return mb_chr(0x1F1E6 + (ord($code[0]) - 65), 'UTF-8')
             . mb_chr(0x1F1E6 + (ord($code[1]) - 65), 'UTF-8');
    }
}
