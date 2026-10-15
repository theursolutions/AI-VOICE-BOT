<?php

namespace App\Services\LeadGen;

/**
 * Is this the same business? Pure comparison of two prospect arrays.
 *
 *   strong    same website host, or same email, or same phone AND a similar
 *             name → treated as one business (the newer record is folded in)
 *   possible  same phone but a different name (a shared office line, a
 *             brokerage switchboard) → flagged for a person, never merged
 *   null      no evidence
 *
 * A similar name alone is never enough: "Al Noor Properties" in Dubai and in
 * Sharjah, or two branches of a franchise, are different customers.
 */
class Deduplicator
{
    public const STRONG   = 'strong';
    public const POSSIBLE = 'possible';

    public static function compare(array $a, array $b): ?string
    {
        if (!empty($a['domain_norm']) && ($a['domain_norm'] ?? null) === ($b['domain_norm'] ?? null)) {
            return self::STRONG;
        }
        if (!empty($a['email']) && ($a['email'] ?? null) === ($b['email'] ?? null)) {
            return self::STRONG;
        }
        if (!empty($a['phone_e164']) && ($a['phone_e164'] ?? null) === ($b['phone_e164'] ?? null)) {
            return self::similarNames((string) ($a['business_name'] ?? ''), (string) ($b['business_name'] ?? ''))
                ? self::STRONG : self::POSSIBLE;
        }
        return null;
    }

    public static function similarNames(string $a, string $b): bool
    {
        $a = ProspectNormalizer::nameKey($a);
        $b = ProspectNormalizer::nameKey($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        // "Acme" vs "Acme Dubai Marina": one name contained in the other.
        if (min(mb_strlen($a), mb_strlen($b)) >= 4 && (str_contains($a, $b) || str_contains($b, $a))) {
            return true;
        }
        similar_text($a, $b, $pct);
        return $pct >= 80;
    }

    /**
     * The strongest match for $item among $candidates (lowest id wins a tie,
     * so the oldest record stays canonical).
     *
     * @param  iterable<array> $candidates arrays with an 'id' key
     * @return array{0: ?string, 1: ?array}
     */
    public static function bestMatch(array $item, iterable $candidates): array
    {
        $best = [null, null];
        foreach ($candidates as $c) {
            $kind = self::compare($item, $c);
            if ($kind === self::STRONG && ($best[0] !== self::STRONG || ($c['id'] ?? PHP_INT_MAX) < ($best[1]['id'] ?? PHP_INT_MAX))) {
                $best = [$kind, $c];
            } elseif ($kind === self::POSSIBLE && $best[0] === null) {
                $best = [$kind, $c];
            }
        }
        return $best;
    }
}
