<?php

namespace App\Services\Telephony;

use App\Models\Project;

/**
 * Numbers and extensions bound to projects, from
 * `projects.json_data.telephony.numbers[]`.
 *
 * Each entry carries `provider`:
 *   - "twilio"   (default) — an E.164 Twilio number, e.g. +12346352160
 *   - "asterisk" — a DID or extension exactly as Asterisk dials it
 *                  (e.g. 1122, 0424432233), from a telco trunk, a PBX
 *                  such as a Grandstream UCM, or a WebRTC client
 * plus `transfer_to`: where "talk to a person" sends the caller (an
 * extension or queue, e.g. 1000). Empty = the AI never transfers.
 */
class TelephonyNumbers
{
    public const TWILIO   = 'twilio';
    public const ASTERISK = 'asterisk';

    /**
     * The project that actually owns $number — no first-project fallback.
     *
     * @return array{0:Project,1:?array}|null  [Project, numberConfig]
     */
    public function owner(string $number, string $provider = self::TWILIO): ?array
    {
        $number = trim($number);
        if ($number === '') {
            return null;
        }

        foreach (Project::all(['id', 'name', 'json_data']) as $p) {
            foreach ((array) data_get($p->json_data, 'telephony.numbers', []) as $n) {
                if (trim((string) ($n['phone_number'] ?? '')) === $number && self::providerOf($n) === $provider) {
                    return [$p, $n];
                }
            }

            // Legacy shape — a single Twilio phone_number string.
            $legacy = data_get($p->json_data, 'telephony.phone_number');
            if ($provider === self::TWILIO && $legacy && trim((string) $legacy) === $number) {
                return [$p, null];
            }
        }

        // Dev fallback: the number in TWILIO_PHONE_NUMBER belongs to the
        // first project. Keeps single-tenant local testing alive.
        $envDefault = trim((string) config('services.twilio.phone_number', ''));
        if ($provider === self::TWILIO && $envDefault !== '' && $envDefault === $number) {
            $p = Project::orderBy('id')->first();
            return $p ? [$p, null] : null;
        }

        return null;
    }

    public static function providerOf(?array $number): string
    {
        return ($number['provider'] ?? null) === self::ASTERISK ? self::ASTERISK : self::TWILIO;
    }

    /**
     * Twilio numbers are stored E.164. Asterisk numbers are stored exactly
     * as Asterisk dials them — "1122" must not become "+1122".
     */
    public static function normalize(string $number, string $provider): string
    {
        $number = preg_replace('/\s+/', '', $number);

        if ($provider === self::ASTERISK) {
            return $number;
        }

        return str_starts_with($number, '+') ? $number : '+' . ltrim($number, '0');
    }
}
