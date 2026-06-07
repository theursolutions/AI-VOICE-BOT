<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every workspace page behind "this Client has at least one active
 * (provisioned) Project". Until that's true, the user is bounced to
 * /c/{slug}/setup — the first-time setup wizard.
 *
 * Sidebar nav, dashboard, leads, sessions, every settings page —
 * all sit behind this. Without a tenant DB they'd 500 on first query.
 */
class EnsureWorkspaceProvisioned
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->route('client');
        // If the route doesn't have a {client} param, this middleware
        // shouldn't interfere — let it pass.
        if (! $client instanceof Client) {
            return $next($request);
        }

        $hasActive = Project::where('client_id', $client->id)
            ->where('is_active', 'Yes')
            ->exists();

        if (! $hasActive) {
            // Never trap the setup page in its own redirect loop.
            if ($request->routeIs('setup', 'setup.store')) {
                return $next($request);
            }
            return redirect()->route('setup', ['client' => $client->slug]);
        }

        return $next($request);
    }
}
