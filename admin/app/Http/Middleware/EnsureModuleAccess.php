<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;

/**
 * Role-based module gate. Blocks a request when the current member's role
 * doesn't grant the module the route belongs to.
 *
 *  - No client context / no user  → pass (other middleware handles it).
 *  - Owner role                   → pass (all-access).
 *  - Route maps to no module      → pass (utility/shared endpoints).
 *  - Otherwise                    → 403 unless the role allows the module.
 *
 * Module ↔ route-name mapping lives in config/modules.php.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user   = $request->user();
        $client = $request->attributes->get('client');

        if (!$user || !$client) {
            return $next($request);
        }
        if ($user->isOwnerOf($client->id)) {
            return $next($request);
        }

        $module = Modules::moduleForRoute(optional($request->route())->getName() ?? '');
        if ($module === null || $user->canModule($client->id, $module)) {
            return $next($request);
        }

        abort(403, 'You don’t have access to this section.');
    }
}
