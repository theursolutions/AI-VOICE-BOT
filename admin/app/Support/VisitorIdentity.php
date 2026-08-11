<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How a visitor is identified, in one place.
 *
 * Three callers derive this — the tracking middleware writing the visitor row,
 * and the two lead-capture endpoints stamping `contact_leads.visitor_key`. If
 * any of them computed it differently the join would silently never match, so
 * none of them are allowed their own copy.
 */
final class VisitorIdentity
{
    /**
     * Stable identity for a visitor, with no client-side storage involved:
     * no cookie, no localStorage, nothing to disclose or ask permission for.
     *
     * APP_KEY is mixed in so the digest cannot be reproduced — and an IP
     * therefore confirmed — from a stolen copy of the table alone.
     *
     * Trade-off, by design: two people behind one NAT on the same browser
     * build collapse into one visitor, and one person on wifi then mobile
     * data counts as two. Cookie-based identity would be more accurate and
     * is exactly what we're avoiding.
     */
    public static function key(Request $request): string
    {
        return sha1(
            (self::ip($request) ?? '') . '|' . (string) $request->userAgent() . '|' . config('app.key')
        );
    }

    /**
     * The client IP as we store it — truncated to the network when
     * `visitors.anonymize_ip` is on.
     *
     * Correctness here depends on App\Http\Middleware\TrustProxies: behind
     * Caddy/HAProxy an untrusted setup would report the proxy's address and
     * collapse every visitor into one.
     */
    public static function ip(Request $request): ?string
    {
        $ip = $request->ip();
        if (! $ip) {
            return null;
        }

        if (! config('visitors.anonymize_ip', false)) {
            return $ip;
        }

        // /24 for IPv4, /48 for IPv6 — still good for country and city, no
        // longer pointing at a single household.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);

            return ($p[0] ?? '0') . '.' . ($p[1] ?? '0') . '.' . ($p[2] ?? '0') . '.0';
        }

        return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::';
    }
}
