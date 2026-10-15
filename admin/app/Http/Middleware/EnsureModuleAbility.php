<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Action-level gate inside a module: `module.ability:leadgen,send`.
 *
 * Runs after EnsureModuleAccess, which has already decided the member may
 * open the module at all. This narrows it to one action (Role::allowsAbility).
 * Owners pass. Unlike the module gate it fails CLOSED without a client
 * context — an ability is only ever declared on workspace routes, so a
 * missing client means the route is mis-wired, not that it is public.
 */
class EnsureModuleAbility
{
    public function handle(Request $request, Closure $next, string $module, string $ability)
    {
        $user   = $request->user();
        $client = $request->attributes->get('client');

        if (!$user || !$client || !$user->canAbility($client->id, $module, $ability)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'forbidden', 'message' => 'Your role doesn’t allow this action.'], 403);
            }
            abort(403, 'Your role doesn’t allow this action.');
        }

        return $next($request);
    }
}
