<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for /admin/* ops console. Anyone without the super_admin
 * flag (including authed customer users) gets a 404 — we don't want
 * the existence of the ops console to leak.
 *
 * Impersonation note: when a super-admin is impersonating a customer,
 * the active auth() user is the customer. We block /admin/* in that
 * state too — operators have to exit impersonation first.
 */
class IsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_super_admin) {
            abort(404);
        }

        // Defence in depth: if the user is in the middle of an
        // impersonation session, the active auth() id is the *target*,
        // not the operator. Block /admin/* until they exit.
        if (session()->has('impersonator_id')) {
            abort(404);
        }

        return $next($request);
    }
}
