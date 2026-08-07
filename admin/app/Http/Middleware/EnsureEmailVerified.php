<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace gate for unverified emails.
 *
 * A logged-in user whose email isn't verified yet may use only the
 * Dashboard and the Ask AI (assistant) module. Every other workspace route
 * bounces them to the OTP verification screen. Verified users and
 * super-admins pass straight through.
 *
 * Pairs with the sidebar/mobile-menu, which hide everything but Dashboard +
 * Ask AI for the same users, so the menu and access rules stay in lock-step.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->hasVerifiedEmail() || $user->isSuperAdmin()) {
            return $next($request);
        }

        $route = optional($request->route())->getName() ?? '';

        // Unverified users keep access to the Dashboard + Ask AI only.
        $allowed = in_array($route, ['dashboard', 'onboard'], true)
            || str_starts_with($route, 'dashboard.')
            || str_starts_with($route, 'onboard.')
            || str_starts_with($route, 'assistant.');

        if ($allowed) {
            return $next($request);
        }

        return redirect()->route('verification.notice')->with(
            'warning',
            'Please verify your email to unlock the rest of your workspace.'
        );
    }
}
