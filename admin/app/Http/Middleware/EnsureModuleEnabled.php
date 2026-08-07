<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;

/**
 * Platform-wide module switch. When a super-admin has switched a module
 * OFF (Ops Console → Modules), a direct hit on any of that module's routes
 * shows the friendly "under development" page instead of the real section.
 *
 * Runs ahead of the per-role gate (EnsureModuleAccess): a disabled module
 * is off for everyone, owners included. Utility/shared routes that map to
 * no module pass straight through.
 *
 * Module ↔ route-name mapping + the on/off state live in App\Support\Modules.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $module = Modules::moduleForRoute(optional($request->route())->getName() ?? '');

        if ($module === null || Modules::isEnabled($module)) {
            return $next($request);
        }

        // Customer-facing "coming soon" page (in-app chrome). 503 keeps the
        // section out of search indexes and signals "temporarily off".
        return response()->view('errors.module-disabled', [
            'moduleLabel' => Modules::label($module),
        ], 503);
    }
}
