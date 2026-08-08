<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 301s /about/ → /about.
 *
 * Laravel routes match with or without a trailing slash, so every public
 * page is currently reachable at two URLs that both return 200. In
 * development Apache's .htaccess quietly redirected one to the other; the
 * production container runs nginx, which does not — so the duplicate was
 * live only in production, where it matters.
 *
 * GET/HEAD only: redirecting a POST would turn it into a GET and silently
 * drop the body (form submissions, webhooks). The query string is carried
 * over so ad traffic keeps its parameters.
 */
class RedirectTrailingSlash
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->getPathInfo();

        if (
            in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $path !== '/'
            && str_ends_with($path, '/')
        ) {
            $target = $request->getSchemeAndHttpHost() . rtrim($path, '/');
            if ($query = $request->getQueryString()) {
                $target .= '?' . $query;
            }

            return redirect($target, 301);
        }

        return $next($request);
    }
}
