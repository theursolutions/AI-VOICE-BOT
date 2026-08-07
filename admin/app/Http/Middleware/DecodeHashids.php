<?php

namespace App\Http\Middleware;

use App\Support\Hashid;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverses HashidUrlGenerator: decodes id-like values (route params named
 * `id`/`*_id`/`*Id`, and matching query/body/JSON keys) from their URL hash
 * back to a plain integer BEFORE controllers, validation, or model binding
 * read them — so every `int $id` / `request('project_id')` sees a real int.
 *
 * Dual-mode: a value that is already a plain integer (or that doesn't decode)
 * is left exactly as-is, so raw-id links and non-id inputs keep working.
 *
 * Scoped to the web admin/ops groups only — the public API keeps raw ids.
 */
class DecodeHashids
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->decodeRouteParameters($request);
        $this->decodeInput($request);

        return $next($request);
    }

    private function decodeRouteParameters(Request $request): void
    {
        $route = $request->route();
        if (! $route) {
            return;
        }

        foreach ($route->parameters() as $name => $value) {
            if (! is_string($value) || ! Hashid::isIdKey($name)) {
                continue;
            }
            $decoded = Hashid::decode($value);
            if ($decoded !== null) {
                $route->setParameter($name, $decoded);
            }
        }
    }

    /**
     * Decode id-like keys across the active input source (query string, form
     * body, or JSON body) plus the query bag, so both request('x') and
     * request->query('x') see the integer.
     */
    private function decodeInput(Request $request): void
    {
        $replacements = [];

        foreach ($request->all() as $key => $value) {
            if (! is_string($value) || ! Hashid::isIdKey($key)) {
                continue;
            }
            $decoded = Hashid::decode($value);
            if ($decoded !== null) {
                $replacements[$key] = $decoded;
            }
        }

        if (empty($replacements)) {
            return;
        }

        // merge() writes into the active input source (query for GET, request
        // bag for forms, json bag for JSON requests).
        $request->merge($replacements);

        // Keep the raw query bag in sync for code that reads ->query() directly.
        foreach ($replacements as $key => $value) {
            if ($request->query->has($key)) {
                $request->query->set($key, $value);
            }
        }
    }
}
