<?php

namespace App\Support;

/**
 * Phone-number normalisation for public lead capture.
 *
 * Both public entry points (the homepage "Call me now" bar and the contact
 * page form) write to the same `contact_leads.phone` column, so they have to
 * agree on the format — otherwise the same visitor lands twice, once as
 * "+15550100100" and once as "(555) 010-0100", and ops search misses one.
 *
 * This is deliberately NOT a full E.164 parser (no country inference, no
 * libphonenumber): it strips formatting and guarantees a leading '+', which
 * is all a human triaging the ops list needs.
 */
final class Phone
{
    public static function e164ish(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $raw);
        // Keep only a leading '+' — any others are formatting noise.
        $digits = ($digits !== '' && $digits[0] === '+' ? '+' : '') . str_replace('+', '', $digits);

        if ($digits === '' || $digits === '+') {
            return null;
        }

        if (! str_starts_with($digits, '+')) {
            // A leading 0 is a national trunk prefix; it never belongs after '+'.
            $digits = '+' . ltrim($digits, '0');
        }

        return $digits === '+' ? null : $digits;
    }
}
