<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in as customer" — operator support tool.
 *
 *   start: stash the operator's user id in session, swap auth() to
 *          the target, redirect to /dashboard. From the moment of
 *          start the active auth()->user() is the *customer*.
 *
 *   stop:  read impersonator_id from session, swap back, clear the
 *          marker, redirect to /admin. Always available to authed
 *          users (no super-admin gate) because by then the active
 *          session is the customer's.
 *
 * Hard rule: impersonating a fellow super-admin is refused — would
 * be redundant and creates a phishing escalation path.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, int $userId): RedirectResponse
    {
        $target = User::findOrFail($userId);
        $operator = Auth::user();

        if ($target->is_super_admin) {
            return back()->withErrors(['user' => 'Cannot impersonate another super-admin.']);
        }

        AuditLog::record('impersonate.start', [
            'actor_id'    => $operator->id,
            'target_type' => 'user',
            'target_id'   => $target->id,
            'payload'     => ['target_email' => $target->email],
        ]);

        // Stash the operator id; we'll restore from this on stop().
        session(['impersonator_id' => $operator->id]);

        // Swap auth. Remember=false so the target's "remember me" cookie
        // isn't issued under the operator's session.
        Auth::login($target);

        // Pick a sensible landing page for the customer's perspective.
        if ($target->active_client_id && $target->activeClient) {
            return redirect()->route('dashboard', ['client' => $target->activeClient->slug])
                ->with('success', "You are now viewing as {$target->name}.");
        }
        return redirect('/dashboard')->with('success', "You are now viewing as {$target->name}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = session('impersonator_id');
        if (!$impersonatorId) {
            return redirect('/dashboard');
        }

        $operator = User::find($impersonatorId);
        $current  = Auth::user();

        AuditLog::record('impersonate.stop', [
            'actor_id'    => $impersonatorId,
            'target_type' => 'user',
            'target_id'   => $current?->id,
            'payload'     => ['target_email' => $current?->email],
        ]);

        session()->forget('impersonator_id');

        if ($operator) {
            Auth::login($operator);
        }

        return redirect()->route('ops.overview')->with('success', 'Exited impersonation.');
    }
}
