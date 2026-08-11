<?php

namespace App\Http\Middleware;

use App\Models\Visitor;
use App\Models\VisitorPageView;
use App\Support\IpLocator;
use App\Support\UserAgent;
use App\Support\VisitorIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records every public page open, using only what the browser already sent:
 * IP, User-Agent, Accept-Language, Referer. No script runs in the visitor's
 * browser and no cookie or other client storage is touched.
 *
 * Runs AFTER the response is generated and only for successful HTML GETs, so
 * a redirect, a 404 or a JSON call never lands in the analytics table.
 *
 * The whole body is wrapped in a try/catch on purpose: analytics is the least
 * important thing this app does, and must never be the reason a marketing
 * page 500s. A failure is logged and swallowed.
 */
class TrackVisitor
{
    public function __construct(private IpLocator $locator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($this->shouldTrack($request, $response)) {
                $this->record($request);
            }
        } catch (\Throwable $e) {
            Log::warning('TrackVisitor: failed to record visit', [
                'path' => $request->path(), 'err' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! config('visitors.enabled', true)) {
            return false;
        }

        // A page *open* is a GET that returned a page. HEAD is a probe,
        // POST is a form, 302 is a redirect to the page that counts, and
        // JSON/XHR responses aren't pages at all.
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }
        if ($request->ajax() || $request->expectsJson() || $request->isJson()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return false;
        }

        return ! $this->isIgnoredPath($request->path());
    }

    private function isIgnoredPath(string $path): bool
    {
        $path    = trim($path, '/');
        $segment = strtolower(explode('/', $path)[0] ?? '');

        // '' is the homepage — the single most important page to record.
        if ($segment === '') {
            return false;
        }

        foreach ((array) config('visitors.ignore_paths', []) as $ignored) {
            if ($segment === strtolower(trim((string) $ignored, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function record(Request $request): void
    {
        $ua = (string) $request->userAgent();
        $ff = UserAgent::parse($ua);

        if ($ff['is_bot'] && ! config('visitors.track_bots', true)) {
            return;
        }

        $ip   = VisitorIdentity::ip($request);
        $path = '/' . trim($request->path(), '/');
        $ref  = (string) $request->headers->get('referer', '');

        // Our own pages aren't referrers — otherwise every internal click
        // overwrites the acquisition source that actually brought them in.
        $refHost = $ref !== '' ? (parse_url($ref, PHP_URL_HOST) ?: null) : null;
        if ($refHost && $this->isOwnHost($refHost, $request)) {
            $ref = '';
            $refHost = null;
        }

        $key = VisitorIdentity::key($request);
        $now = now();

        // firstOrCreate + update rather than upsert(): we need to know whether
        // this is a first sight to decide what counts as the landing page and
        // the acquisition referrer, which an upsert can't tell us.
        $visitor = Visitor::firstOrNew(['visitor_key' => $key]);

        if (! $visitor->exists) {
            $visitor->fill([
                'ip'              => $ip,
                'user_agent'      => mb_substr($ua, 0, 500),
                'browser'         => $ff['browser'],
                'browser_version' => $ff['browser_version'],
                'os'              => $ff['os'],
                'device_type'     => $ff['device_type'],
                'is_bot'          => $ff['is_bot'],
                'language'        => UserAgent::language($request->headers->get('accept-language')),
                'landing_path'    => mb_substr($path, 0, 500),
                'referrer'        => $ref !== '' ? mb_substr($ref, 0, 500) : null,
                'referrer_host'   => $refHost ? mb_substr($refHost, 0, 120) : null,
                'utm_source'      => $this->param($request, 'utm_source'),
                'utm_medium'      => $this->param($request, 'utm_medium'),
                'utm_campaign'    => $this->param($request, 'utm_campaign'),
                'first_seen_at'   => $now,
                'page_views'      => 0,
            ]);

            // Resolve inline only when it costs nothing: a local GeoLite2 file
            // makes it a microsecond array lookup, and a private/loopback
            // address needs no lookup at all. Otherwise the row stays
            // `pending` — a visitor never waits on a third-party API to see
            // the page they asked for.
            if ($this->locator->canResolveOffline((string) $ip)) {
                $this->applyGeo($visitor, $ip);
            }
        }

        $visitor->last_path    = mb_substr($path, 0, 500);
        $visitor->last_seen_at = $now;
        $visitor->save();

        // Atomic increment — two concurrent page opens from one visitor would
        // otherwise both write the same read-then-add value and lose a count.
        Visitor::whereKey($visitor->id)->increment('page_views');

        VisitorPageView::create([
            'visitor_id' => $visitor->id,
            'path'       => mb_substr($path, 0, 500),
            'referrer'   => $ref !== '' ? mb_substr($ref, 0, 500) : null,
            'created_at' => $now,
        ]);
    }

    /** Fill the geo columns on a visitor from its IP. */
    private function applyGeo(Visitor $visitor, ?string $ip): void
    {
        $geo = $this->locator->locate((string) $ip);

        $visitor->geo_status      = $geo['status'];
        $visitor->continent       = $geo['continent'];
        $visitor->country         = $geo['country'];
        $visitor->country_code    = $geo['country_code'];
        $visitor->region          = $geo['region'];
        $visitor->city            = $geo['city'];
        $visitor->postal          = $geo['postal'];
        $visitor->timezone        = $geo['timezone'];
        $visitor->org             = $geo['org'];
        $visitor->asn             = $geo['asn'];
        $visitor->connection_type = $geo['connection_type'];
        $visitor->latitude        = $geo['latitude'];
        $visitor->longitude       = $geo['longitude'];
    }

    private function isOwnHost(string $host, Request $request): bool
    {
        return strcasecmp($host, (string) $request->getHost()) === 0;
    }

    private function param(Request $request, string $key): ?string
    {
        $v = trim((string) $request->query($key, ''));

        return $v !== '' ? mb_substr($v, 0, 120) : null;
    }
}
