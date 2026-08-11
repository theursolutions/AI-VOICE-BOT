<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Billing\Plan;
use App\Models\Billing\StripeEvent;
use App\Models\Billing\Subscription;
use App\Models\Billing\TrialFingerprint;
use App\Models\Client;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → Billing → Subscriptions.
 *
 * Operational visibility (who is on what, what's failing, what Stripe told us)
 * plus the two overrides support actually needs: extend a free window, and
 * grant a fresh one to someone the fingerprint check caught unfairly.
 *
 * Deliberately NO "set this workspace to active" button. Paid state comes from
 * Stripe and only from Stripe; a manual override would create a workspace with
 * access and no money behind it, and nothing would ever reconcile it back.
 * Comping a customer is done properly — a 100%-off coupon or a private plan.
 */
class SubscriptionsController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $planId = (int) $request->query('plan', 0);
        $search = trim((string) $request->query('q', ''));

        $query = Subscription::query()
            ->with(['client', 'plan', 'planPrice'])
            ->latest('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($planId > 0) {
            $query->where('plan_id', $planId);
        }

        if ($search !== '') {
            $clientIds = Client::query()
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->pluck('id');

            $query->where(function ($q) use ($clientIds, $search) {
                $q->whereIn('client_id', $clientIds)
                  ->orWhere('stripe_subscription_ref', 'like', "%{$search}%")
                  ->orWhere('stripe_customer_ref', 'like', "%{$search}%");
            });
        }

        return view('ops.billing.subscriptions.index', [
            'title'         => 'Subscriptions',
            'subscriptions' => $query->paginate(40)->withQueryString(),
            'plans'         => Plan::query()->ordered()->get(),
            'statuses'      => $this->statusCounts(),
            'filters'       => ['status' => $status, 'plan' => $planId, 'q' => $search],
            'mrrCents'      => $this->estimatedMrrCents(),
            'failedEvents'  => StripeEvent::failed()->count(),
        ]);
    }

    /** Recent webhook events — the first place to look when state looks wrong. */
    public function events(Request $request): View
    {
        $status = (string) $request->query('status', '');

        $query = StripeEvent::query()->latest('id');

        if ($status !== '') {
            $query->where('status', $status);
        }

        return view('ops.billing.subscriptions.events', [
            'title'  => 'Stripe events',
            'events' => $query->paginate(50)->withQueryString(),
            'filter' => $status,
        ]);
    }

    /**
     * Give a workspace more free days. The honest support tool: it moves the
     * clock and clears the degraded flags, but never fabricates paid state.
     */
    public function extendFreeWindow(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        $subscription = Subscription::query()->with('client')->findOrFail($id);

        // Extend from whichever is later: now, or the existing end date. Adding
        // to a date already in the past would grant fewer days than promised.
        $base = $subscription->free_ends_at && $subscription->free_ends_at->isFuture()
            ? $subscription->free_ends_at->copy()
            : now();

        $subscription->forceFill([
            'status'          => Subscription::STATUS_FREE,
            'free_ends_at'    => $base->addDays((int) $data['days']),
            'read_only_since' => null,
            'purge_after'     => null,
        ])->save();

        if ($client = $subscription->client) {
            $client->forgetSubscription();
            $this->subscriptions->syncClientCache($client);
        }

        AuditLog::record('billing.free_window.extended', [
            'payload' => [
                'client_id' => $subscription->client_id,
                'days'      => $data['days'],
                'new_end'   => $subscription->free_ends_at?->toDateTimeString(),
            ],
        ]);

        return back()->with('success', "Free access extended to {$subscription->free_ends_at->format('j M Y')}.");
    }

    /**
     * Waive the fingerprint block so someone can start a free window again.
     *
     * The fingerprint check is a heuristic — shared offices, family emails and
     * agencies onboarding clients all trip it legitimately — so it must have a
     * documented, audited escape hatch rather than a support dead end.
     */
    public function waiveFingerprints(Request $request, int $clientId): RedirectResponse
    {
        $client = Client::query()->findOrFail($clientId);

        $count = TrialFingerprint::query()
            ->where('client_id', $client->id)
            ->where('is_waived', false)
            ->update([
                'is_waived' => true,
                'waived_by' => $request->user()?->getKey(),
                'waived_at' => now(),
            ]);

        AuditLog::record('billing.trial_fingerprints.waived', [
            'payload' => ['client_id' => $client->id, 'count' => $count],
        ]);

        return back()->with('success', "{$count} trial block(s) waived for “{$client->name}”.");
    }

    /** Re-pull a subscription from Stripe when local state looks stale. */
    public function reconcile(Request $request, int $id): RedirectResponse
    {
        $subscription = Subscription::query()->with('client')->findOrFail($id);

        if (! $subscription->stripe_subscription_ref) {
            return back()->with('error', 'This subscription has no Stripe object (free window).');
        }

        try {
            $stripe = app(\App\Services\Billing\StripeClientFactory::class)->make();
            $remote = $stripe->subscriptions->retrieve($subscription->stripe_subscription_ref, []);

            $this->subscriptions->syncFromStripe($remote->toArray(), $subscription->client);

            AuditLog::record('billing.subscription.reconciled', [
                'payload' => ['client_id' => $subscription->client_id],
            ]);

            return back()->with('success', 'Re-synced from Stripe.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Stripe read failed: ' . $e->getMessage());
        }
    }

    // ── Aggregates ───────────────────────────────────────────────────

    private function statusCounts(): array
    {
        return Subscription::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    /**
     * Rough MRR in cents: every paying subscription's amount normalised to a
     * month. Annual plans divide by 12, so this is committed monthly revenue
     * rather than cash received — which is the number worth watching.
     */
    private function estimatedMrrCents(): int
    {
        $rows = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->whereNotNull('unit_amount')
            ->get(['unit_amount', 'interval', 'quantity']);

        $total = 0;

        foreach ($rows as $row) {
            $months = max(1, (int) config("billing.intervals.months.{$row->interval}", 1));
            $total += (int) round(($row->unit_amount * max(1, (int) $row->quantity)) / $months);
        }

        return $total;
    }
}
