<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard kick for authed users whose account has been disabled or
 * soft-deleted while they were logged in. Runs on every authed
 * request — the next page load after the operator clicks "Disable"
 * lands the customer on the login form with an explanation.
 *
 * The IntSoftDeletes global scope means User::find($id) already
 * returns null for soft-deleted rows. Auth's session resolver
 * therefore can't rehydrate the user → Auth::user() is null →
 * we just log them out cleanly.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && !$user->canAuthenticate()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been disabled. Contact support if you believe this is an error.']);
        }

        return $next($request);
    }
}
