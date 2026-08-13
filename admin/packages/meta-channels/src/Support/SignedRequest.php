<?php

namespace Msd\MetaChannels\Support;

/**
 * Meta's `signed_request` — the payload format used by the deauthorize and
 * data-deletion callbacks (nothing else in the platform uses it any more).
 *
 * Format: `<base64url signature>.<base64url json payload>`, where the
 * signature is HMAC-SHA256 of the *encoded payload string* using the app
 * secret. Signing the decoded JSON instead is the classic mistake and
 * produces a mismatch on every request.
 *
 * Verification is not optional here. These endpoints are unauthenticated and
 * public by necessity, and they disable channels and erase conversations —
 * an unverified parse would let anyone who knows a customer's IGSID wipe
 * their inbox.
 */
class SignedRequest
{
    /**
     * Decode and verify, returning the payload or null if it fails.
     *
     * @param array<int,string> $secrets app secrets to try — an app using
     *        both Facebook Login and Instagram Login has two, and Meta does
     *        not say which one signed a given callback
     */
    public static function parse(string $signed, array $secrets): ?array
    {
        if (! str_contains($signed, '.')) {
            return null;
        }

        [$encodedSig, $encodedPayload] = explode('.', $signed, 2);

        $sig     = self::b64($encodedSig);
        $payload = self::b64($encodedPayload);

        if ($sig === null || $payload === null) {
            return null;
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return null;
        }

        if (strtoupper((string) ($data['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        foreach (array_filter($secrets) as $secret) {
            // hash_equals, not ===, so a wrong secret can't be recovered a
            // byte at a time from response timing.
            if (hash_equals(hash_hmac('sha256', $encodedPayload, (string) $secret, true), $sig)) {
                return $data;
            }
        }

        return null;
    }

    /** The app secrets this installation might have signed with. */
    public static function secrets(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('meta.app.secret'),
            (string) config('meta.instagram.app_secret'),
            (string) config('meta.whatsapp.app_secret'),
        ])));
    }

    /** Meta uses base64url (`-_`, no padding), which base64_decode won't take. */
    private static function b64(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
