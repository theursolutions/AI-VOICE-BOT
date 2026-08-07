<?php

namespace App\Support;

/**
 * Reversible, keyed obfuscation of integer IDs for use in URLs.
 *
 * encode(1) -> "k7Qm3a"  (always starts with the PREFIX letter, so it can
 * never be confused with a raw integer). decode() reverses it, and ALSO
 * accepts a plain integer string unchanged (dual-mode) so existing
 * raw-id links keep working during/after the migration.
 *
 * This is OBFUSCATION, not security: it hides the sequential nature of IDs
 * but is not a substitute for the tenant/ownership authorization checks the
 * controllers already perform. Anyone with this file can reverse it.
 *
 * Implementation: a bijective transform y = ((x * K) mod M) XOR S over a
 * 2^31 id space (keeps every multiplication within 64-bit range), then
 * base62 with a short minimum length and a fixed leading letter.
 */
final class Hashid
{
    private const PREFIX   = 'k';
    private const M        = 2147483648;          // 2^31 — id ceiling (~2.1B)
    private const K        = 1956089327;          // odd => coprime to 2^31
    private const S        = 1597710279;          // XOR diffusion salt (< M)
    private const MIN_BODY = 5;
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Route-constraint regex matching a raw int OR an encoded hash. */
    public const ROUTE_PATTERN = '[0-9]+|' . self::PREFIX . '[A-Za-z0-9]+';

    private static ?int $kinv = null;

    /** Encode an integer id to its hash. Out-of-range ids pass through raw. */
    public static function encode(int $id): string
    {
        if ($id < 0 || $id >= self::M) {
            return (string) $id;   // beyond our space — leave as-is
        }
        $y = (($id * self::K) % self::M) ^ self::S;
        return self::PREFIX . self::toBase62($y);
    }

    /**
     * Decode a hash (or a raw integer string) back to an int.
     * Returns null when the value is neither a valid hash nor an integer.
     */
    public static function decode(string $value): ?int
    {
        // Dual-mode: a plain integer string is accepted unchanged.
        if (ctype_digit($value)) {
            return (int) $value;
        }
        if ($value === '' || $value[0] !== self::PREFIX) {
            return null;
        }

        $y = self::fromBase62(substr($value, 1));
        if ($y === null || $y >= self::M) {
            return null;
        }
        $pre = $y ^ self::S;
        $x   = ($pre * self::kinv()) % self::M;

        // Round-trip guard: reject anything that doesn't re-encode exactly.
        return self::encode($x) === $value ? $x : null;
    }

    /** True when a parameter/input key should carry a hashed id. */
    public static function isIdKey(string $key): bool
    {
        return $key === 'id'
            || str_ends_with($key, '_id')
            || (str_ends_with($key, 'Id') && $key !== 'Id');
    }

    /** Round-trip self-check across a range — used by the verify step. */
    public static function selfTest(int $upTo = 100000): bool
    {
        foreach ([1, 2, 7, 42, 1000, 999999, $upTo] as $n) {
            for ($i = max(1, $n - 3); $i <= $n; $i++) {
                if (self::decode(self::encode($i)) !== $i) {
                    return false;
                }
            }
        }
        // Raw passthrough must hold too.
        return self::decode('5') === 5 && self::decode('garbage') === null;
    }

    // ── internals ────────────────────────────────────────────────────────

    private static function kinv(): int
    {
        if (self::$kinv === null) {
            self::$kinv = self::modInverse(self::K, self::M);
        }
        return self::$kinv;
    }

    /** Modular inverse via extended Euclid (intermediates stay < M < 2^31). */
    private static function modInverse(int $a, int $m): int
    {
        $g = $m;
        $x = 0;
        $x1 = 1;
        $a %= $m;
        while ($a > 1) {
            $q = intdiv($a, $g);
            [$a, $g] = [$g, $a - $q * $g];
            [$x1, $x] = [$x, $x1 - $q * $x];
        }
        return ($x1 % $m + $m) % $m;
    }

    private static function toBase62(int $n): string
    {
        $out = '';
        do {
            $out = self::ALPHABET[$n % 62] . $out;
            $n = intdiv($n, 62);
        } while ($n > 0);
        return str_pad($out, self::MIN_BODY, self::ALPHABET[0], STR_PAD_LEFT);
    }

    private static function fromBase62(string $s): ?int
    {
        if ($s === '') {
            return null;
        }
        $n = 0;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $s[$i]);
            if ($pos === false) {
                return null;
            }
            $n = $n * 62 + $pos;
        }
        return $n;
    }
}
