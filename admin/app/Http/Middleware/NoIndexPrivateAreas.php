<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps `X-Robots-Tag: noindex, nofollow` on the authenticated app.
 *
 * robots.txt already asks crawlers to stay out of /admin, /c/{workspace}
 * and friends, but robots.txt is a crawl instruction, not an index
 * instruction: a URL someone links to can still land in the index with no
 * snippet. This header is the belt to that braces, and unlike a meta tag
 * it also covers JSON endpoints, file downloads and redirects.
 *
 * The list is deliberately an explicit DENY list rather than an allow-list
 * of public pages. The two failure modes are not symmetric: forgetting to
 * noindex a new admin route costs nothing, while accidentally noindexing a
 * marketing page removes it from Google entirely.
 */
class NoIndexPrivateAreas
{
    /** URL prefixes that are authenticated, machine-only, or both. */
    protected const PRIVATE_PREFIXES = [
        'admin',
        'c',            // /c/{workspace}/… — the whole customer console
        'dashboard',
        'profile',
        'workspace',
        'api',
        'invitations',
        'auth',         // social-login redirects
        'oauth',
        'meta',         // Meta channel OAuth callbacks
        'webhooks',
        'up',           // health check
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isPrivate($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow', true);
        }

        return $response;
    }

    protected function isPrivate(Request $request): bool
    {
        $first = strtolower((string) explode('/', trim($request->getPathInfo(), '/'))[0]);

        return $first !== '' && in_array($first, self::PRIVATE_PREFIXES, true);
    }
}
