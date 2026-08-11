<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Starts and finishes Stripe Checkout.
 *
 * THE TRUST BOUNDARY. The form submits exactly two values:
 *
 *     plan     — a plan SLUG   (e.g. "growth")
 *     interval — an interval    (e.g. "annually")
 *
 * Both are opaque identifiers. The amount, the currency and the Stripe Price
 * reference are resolved server-side by PlanService::resolvePrice(). There is
 * no request field anywhere in this flow that carries money, so a tampered
 * price, a tampered currency, or a tampered local-conversion figure has
 * nothing to act on.
 *
 * FIELD NAMING IS LOAD-BEARING: the fields are `plan` and `interval`, NOT
 * `plan_id` / `price_id`. App\Http\Middleware\DecodeHashids rewrites any
 * request key matching `*_id` through the hashid decoder, which has already
 * caused one production 422. See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C1.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly PlanService $plans,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * Pricing page → here. Unauthenticated visitors are sent to register with
     * their choice preserved, so the funnel never loses the selection.
     */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan'     => ['required', 'string', 'max:100'],
            'interval' => ['required', 'string', 'max:20'],
        ]);

        if (! config('billing.checkout.enabled', false)) {
            return back()->with('info', 'Plans aren’t available to buy just yet — we’re putting the finishing touches to billing. Start free in the meantime.');
        }

        if (! $request->user()) {
            // Stash the intent, then resume after registration.
            $request->session()->put('billing.intent', [
                'plan'     => $data['plan'],
                'interval' => $data['interval'],
            ]);

            return redirect()->route('register')
                ->with('status', 'Create your account to continue — takes about a minute.');
        }

        $client = $this->resolveClient($request);

        if (! $client) {
            return redirect()->route('workspace.pick')
                ->with('error', 'Pick a workspace before choosing a plan.');
        }

        return $this->createSession($request, $client, $data['plan'], $data['interval']);
    }

    /** Workspace billing page → here (already authenticated and scoped). */
    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'plan'     => ['required', 'string', 'max:100'],
            'interval' => ['required', 'string', 'max:20'],
        ]);

        $this->authorizeWorkspace($request, $client);

        // Already on a Stripe subscription → this is a plan change, not a new
        // checkout. Routing it through swap() keeps proration and the billing
        // anchor correct instead of creating a second subscription.
        $existing = $client->currentSubscription();

        if ($existing?->stripe_subscription_ref && $existing->grantsAccess()) {
            try {
                $this->billing->swap($client, $data['plan'], $data['interval']);

                AuditLog::record('billing.plan.swapped', [
                    'payload' => ['client_id' => $client->id] + $data,
                ]);

                return back()->with('success', 'Your plan has been updated.');
            } catch (\Throwable $e) {
                Log::error('billing.swap.failed', [
                    'client_id' => $client->id,
                    'error'     => $e->getMessage(),
                ]);

                return back()->with('error', 'We couldn’t change your plan: ' . $e->getMessage());
            }
        }

        return $this->createSession($request, $client, $data['plan'], $data['interval']);
    }

    private function createSession(Request $request, Client $client, string $plan, string $interval): RedirectResponse
    {
        // Master switch. The buttons are already hidden while this is off, but
        // the endpoint has to refuse too — a hidden button in front of a live
        // public POST route is not disabled, and a stray request could take a
        // real payment before anyone is ready to support one.
        if (! config('billing.checkout.enabled', false)) {
            return back()->with('info', 'Plans aren’t available to buy just yet — we’re putting the finishing touches to billing. You can keep using your free workspace in the meantime.');
        }

        if (! $this->billing->isConfigured()) {
            return back()->with('error', 'Payments aren’t configured yet. Please contact support.');
        }

        try {
            $session = $this->billing->checkout(
                client:     $client,
                planSlug:   $plan,
                interval:   $interval,
                actor:      $request->user(),
                successUrl: route('billing.checkout.success', ['client' => $client->slug]) . '?session_id={CHECKOUT_SESSION_ID}',
                cancelUrl:  route('billing.index', ['client' => $client->slug]),
            );

            AuditLog::record('billing.checkout.started', [
                'payload' => [
                    'client_id' => $client->id,
                    'plan'      => $plan,
                    'interval'  => $interval,
                ],
            ]);

            // away() — Stripe Checkout is off-site, so this must not be a
            // route-aware redirect.
            return redirect()->away($session->url);
        } catch (\RuntimeException $e) {
            // Thrown by resolvePrice for an unknown/unsellable/unsynced
            // selection. Safe to show: it names no secrets.
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.checkout.failed', [
                'client_id' => $client->id,
                'plan'      => $plan,
                'interval'  => $interval,
                'error'     => $e->getMessage(),
            ]);

            return back()->with('error', 'We couldn’t start checkout. Please try again.');
        }
    }

    /**
     * Stripe's success redirect.
     *
     * IMPORTANT: this does NOT activate the subscription. Anyone can visit
     * this URL, and a customer can close the tab before ever reaching it —
     * the webhook is the only thing that writes paid state. This page exists
     * purely to reassure, and to nudge the state along if the webhook is a
     * second or two behind.
     */
    public function success(Request $request, Client $client): View|RedirectResponse
    {
        $sessionId = $request->query('session_id');

        if (is_string($sessionId) && $sessionId !== '' && $this->billing->isConfigured()) {
            try {
                $session = $this->billing->retrieveSession($sessionId);

                // Belt-and-braces reconcile so the page doesn't say "free
                // trial" straight after a successful payment. Idempotent, and
                // the webhook remains authoritative.
                if ($session->subscription) {
                    $subscription = is_string($session->subscription)
                        ? null
                        : $session->subscription->toArray();

                    if ($subscription) {
                        $this->subscriptions->syncFromStripe($subscription, $client);
                    }
                }
            } catch (\Throwable $e) {
                // Never block the thank-you page on a Stripe read.
                Log::info('billing.checkout.success_sync_skipped', ['error' => $e->getMessage()]);
            }
        }

        $client->forgetSubscription();

        return view('billing.success', [
            'title'        => 'You’re all set',
            'client'       => $client,
            'subscription' => $client->currentSubscription(),
        ]);
    }

    public function cancel(Request $request, Client $client): RedirectResponse
    {
        return redirect()
            ->route('billing.index', ['client' => $client->slug])
            ->with('info', 'Checkout cancelled — no charge was made.');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function resolveClient(Request $request): ?Client
    {
        $user = $request->user();

        return $user?->activeClient ?: $user?->clients()->first();
    }

    /** Only a workspace owner may change what the workspace pays. */
    private function authorizeWorkspace(Request $request, Client $client): void
    {
        $user = $request->user();

        abort_unless($user && $user->hasMembership($client->id), 403);
        abort_unless($user->isOwnerOf($client->id), 403, 'Only the workspace owner can change billing.');
    }
}
