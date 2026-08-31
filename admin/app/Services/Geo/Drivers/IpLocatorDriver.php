<?php

namespace App\Services\Geo\Drivers;

use App\Support\IpLocator;

/**
 * Country lookup delegated to the app's existing {@see IpLocator}.
 *
 * WHY DELEGATE RATHER THAN ADD A SECOND MAXMIND READER: IpLocator already
 * ships with the visitor-analytics feature, already reads a local GeoLite2
 * file, already handles both the City and Country editions, and already caches
 * per IP. config/visitors.php and config/billing.php share the same
 * GEOIP_DATABASE_PATH, so one downloaded .mmdb serves both. Maintaining a
 * parallel reader would mean two caches, two code paths and two places to fix
 * a bug.
 *
 * THE ONE IMPORTANT DIFFERENCE — the offline guard:
 *
 * IpLocator::locate() falls back to a free JSON endpoint when no local
 * database is present. That is right for its own use (a queued backfill), but
 * wrong for /pricing: it would add a synchronous third-party round-trip to a
 * page a buyer is waiting on, and free endpoints are rate-limited, so a burst
 * of traffic would stall the very page we most want to be fast.
 *
 * So this driver asks first — canResolveOffline() — and only reaches for the
 * network when there is no local database to read. With one installed, no
 * visitor IP ever leaves this server and no page waits on anyone else's API.
 *
 * Without one, it falls back to a free keyless HTTP lookup rather than
 * returning nothing: an install with no .mmdb is the common case, and silently
 * quoting every visitor the platform currency is a worse failure than a cached
 * two-second call. See countryFor().
 *
 * To force the HTTP path on a dev box, set GEOIP_DRIVER=http.
 */
class IpLocatorDriver implements GeoLocationDriver
{
    public function __construct(
        private readonly IpLocator $locator,
        private readonly ?GeoLocationDriver $fallback = null,
    ) {
    }

    public function name(): string
    {
        return 'iplocator';
    }

    public function countryFor(string $ip): ?string
    {
        // NO LOCAL DATABASE: ask over HTTP rather than give up.
        //
        // This used to return null, on the reasoning that a synchronous
        // third-party call has no place in front of a page a buyer is waiting
        // on. That reasoning was right about the cost and wrong about the
        // alternative — the alternative is not "a fast page", it is "a
        // Pakistani visitor quoted dollars they will never be charged". A
        // wrong price is worse than a slow one.
        //
        // The cost is bounded rather than accepted: the lookup is cached per IP
        // for a day by GeoLocationService, the HTTP driver's timeout is two
        // seconds, and a failure returns null so the page renders in the
        // platform currency — which is always correct, just less useful.
        //
        // Install the GeoLite2 file (`php artisan geoip:update`) and this path
        // is never taken: the local read is faster, free, and sends no visitor
        // IP anywhere.
        if (! $this->locator->canResolveOffline($ip)) {
            return $this->fallback?->countryFor($ip);
        }

        try {
            $result = $this->locator->locate($ip);
        } catch (\Throwable) {
            // The interface forbids throwing: a geo failure must degrade to
            // USD-only pricing, never to an error page.
            return null;
        }

        $code = $result['country_code'] ?? null;

        return (is_string($code) && strlen($code) === 2) ? strtoupper($code) : null;
    }
}
