<?php

namespace App\Http\Middleware;

use App\Models\Billing\Subscription;
use Closure;
use Illuminate\Http\Request;

/**
 * Subscription state gate.
 *
 * Sits BETWEEN the platform module switchboard (EnsureModuleEnabled) and the
 * per-role RBAC gate (EnsureModuleAccess):
 *
 *   module.enabled  → is this feature switched on for the platform at all?
 *   subscription    → is this workspace paid up?            ← here
 *   plan.feature    → does their plan include this feature?
 *   module.access   → does this member's role allow it?
 *
 * DEGRADED ACCESS, NOT LOCKOUT. When the free window lapses or dunning is
 * exhausted, the workspace goes READ-ONLY: the owner keeps their login, their
 * dashboard, their leads, their transcripts and their export. Only writes and
 * the customer-facing widget stop. Locking someone out of a product that is
 * answering their customers' calls converts a lapsed signup into a support
 * ticket and a bad review — and it blocks the data export they're entitled to.
 *
 * Billing routes are always reachable: a paywall you cannot pay through is
 * just an outage.
 */
class EnsureSubscribed
{
    /**
     * Route-name prefixes that stay open in every degraded state, so the
     * customer can always reach the page that fixes the problem.
     */
    private const ALWAYS_ALLOWED = [
        'billing',
        'setup',
        'workspace',
        'profile',
        'logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $client = $request->attributes->get('client');

        if (! $client) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed(optional($request->route())->getName() ?? '')) {
            return $next($request);
        }

        $subscription = $client->currentSubscription();

        // No subscription row at all: a workspace that predates billing.
        // Fail OPEN. The billing backfill gives everyone an explicit row, and
        // locking out existing customers on deploy day would be the worst
        // possible failure mode for this middleware.
        if (! $subscription) {
            return $next($request);
        }

        if ($subscription->grantsAccess()) {
            return $next($request);
        }

        // ── Degraded ─────────────────────────────────────────────────
        $behaviour = (string) config('billing.free.on_expiry', 'read_only');
        $message   = $this->messageFor($subscription);
        $isRead    = in_array($request->method(), ['GET', 'HEAD'], true);

        // Read-only / widget-only: let reads through, flagged, so the layout
        // can render a persistent upgrade banner and views can disable their
        // write controls. Only writes are actually stopped.
        if ($behaviour !== 'lockout' && $isRead) {
            $request->attributes->set('billing_read_only', true);
            $request->attributes->set('billing_message', $message);

            view()->share('billingReadOnly', true);
            view()->share('billingMessage', $message);

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error'       => 'subscription_required',
                'message'     => $message,
                'billing_url' => route('billing.index', ['client' => $client->slug]),
            ], 402);   // 402 Payment Required — the one case where it's literally correct
        }

        return redirect()
            ->route('billing.index', ['client' => $client->slug])
            ->with('billing_warning', $message);
    }

    private function messageFor(Subscription $subscription): string
    {
        return match (true) {
            $subscription->isExpired() && $subscription->free_ends_at !== null
                => 'Your free trial has ended. Choose a plan to switch your agent back on — your data is safe.',
            $subscription->isPastDue()
                => 'We couldn’t take your last payment. Update your card to keep your agent answering.',
            $subscription->isCanceled()
                => 'Your subscription has been cancelled. Reactivate any time — your data is still here.',
            default
                => 'Your workspace needs an active plan to continue.',
        };
    }

    private function isAlwaysAllowed(string $routeName): bool
    {
        if ($routeName === '') {
            return false;
        }

        foreach (self::ALWAYS_ALLOWED as $prefix) {
            if ($routeName === $prefix || str_starts_with($routeName, $prefix . '.')) {
                return true;
            }
        }

        return false;
    }
}
