<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has a valid active workspace,
 * keeps `users.active_client_id` in sync with the URL prefix, and
 * sets URL::defaults so `route('dashboard')` etc. don't need a
 * `{client}` arg.
 *
 * Resolution order:
 *   1. If route carries `{client}` (slug), validate membership →
 *      use that client. Update DB if it changed.
 *   2. Else, fall back to `users.active_client_id`.
 *   3. 0 memberships → flash error.
 *   4. 1 membership → auto-pick.
 *   5. 2+ never picked → redirect to /workspace/pick.
 */
class EnsureActiveClient
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user) {
            return $next($request);
        }

        // Super-admins belong in the /admin ops console, not a customer
        // workspace. Funnel them there on the generic dashboard / picker
        // (i.e. when the URL isn't an explicit /c/{slug} workspace link, so
        // they can still open a specific workspace by URL when they need to).
        // During impersonation the authenticated user is the CUSTOMER, so
        // is_super_admin is false here — impersonation is unaffected.
        if ($user->is_super_admin
            && !$request->route('client')
            && !$request->routeIs('ops.*')
            && !$request->is('logout')) {
            return redirect()->route('ops.overview');
        }

        $memberships = $user->clients()->orderBy('clients.name')->get();
        $memberIds = $memberships->pluck('id')->all();

        if (empty($memberIds)) {
            if (!$request->is('workspace/*') && !$request->is('logout')) {
                return redirect('/')->with('warning', 'You do not belong to any workspace yet.');
            }
            return $next($request);
        }

        $current = null;

        // 1) URL-scoped: /c/{slug}/...
        $routeClient = $request->route('client');
        if ($routeClient) {
            $slug = $routeClient instanceof Client ? $routeClient->slug : (string) $routeClient;
            $current = $memberships->firstWhere('slug', $slug);
            if (!$current) {
                abort(403, 'You are not a member of this workspace.');
            }
        }

        // 2) Single-membership auto-pick
        if (!$current && count($memberIds) === 1) {
            $current = $memberships->first();
        }

        // 3) Stored active client
        if (!$current && $user->active_client_id && in_array($user->active_client_id, $memberIds, true)) {
            $current = $memberships->firstWhere('id', $user->active_client_id);
        }

        // 4) 2+ memberships, never picked → picker
        if (!$current) {
            if (!$request->is('workspace/*')) {
                return redirect()->route('workspace.pick');
            }
            return $next($request);
        }

        // Persist if changed
        if ($user->active_client_id !== $current->id) {
            $user->forceFill([
                'active_client_id' => $current->id,
                'last_picked_at'   => time(),
            ])->save();
        }

        // Auto-fill {client} for every route() call this request.
        URL::defaults(['client' => $current->slug]);

        // Expose on the request for controllers/views that want it.
        $request->attributes->set('client', $current);

        return $next($request);
    }
}
