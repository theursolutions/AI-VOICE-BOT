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
 * So this driver asks first — canResolveOffline() — and returns null rather
 * than reaching for the network. The caller then shows USD only, which is
 * always a correct page. Local currency is a nicety; page speed on the pricing
 * page is not.
 *
 * To use the HTTP path deliberately on a dev box, set GEOIP_DRIVER=http.
 */
class IpLocatorDriver implements GeoLocationDriver
{
    public function __construct(private readonly IpLocator $locator)
    {
    }

    public function name(): string
    {
        return 'iplocator';
    }

    public function countryFor(string $ip): ?string
    {
        // Never block a page render on someone else's API.
        if (! $this->locator->canResolveOffline($ip)) {
            return null;
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
