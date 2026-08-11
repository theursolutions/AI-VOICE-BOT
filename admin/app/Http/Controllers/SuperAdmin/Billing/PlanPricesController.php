<?php

namespace App\Http\Controllers\SuperAdmin\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Billing\Plan;
use App\Models\Billing\PlanPrice;
use App\Services\Billing\PlanService;
use App\Services\Billing\StripeClientFactory;
use App\Services\Billing\StripeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Super Admin → Billing → Plans → Prices.
 *
 * THE RULE THIS CONTROLLER ENFORCES, and the reason it isn't a plain CRUD
 * resource: changing a price must never alter what existing subscribers pay.
 *
 *   • `store`  adds a price for an interval that has none yet.
 *   • `update` NEVER edits an amount in place. It delegates to
 *     PlanService::changePrice(), which creates a new row + a new Stripe
 *     Price and archives the old one. Existing `subscriptions.plan_price_id`
 *     values keep pointing at the archived row, so subscribers are
 *     grandfathered automatically rather than by an operator remembering to.
 *
 * That asymmetry is what makes launching at a low price safe: raising it later
 * cannot touch anyone who already signed up.
 *
 * Amounts are entered in DOLLARS in the form and stored as integer CENTS.
 */
class PlanPricesController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly StripeSyncService $stripeSync,
        private readonly StripeClientFactory $stripe,
    ) {
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $plan = Plan::query()->findOrFail($id);

        $data = $request->validate([
            'interval' => ['required', 'string', 'in:' . implode(',', (array) config('billing.intervals.supported'))],
            // Dollars, up to 2dp. Converted to integer cents below — money is
            // never stored as a float (the legacy payment_plans.price float
            // column is exactly the mistake being avoided here).
            'amount'   => ['required', 'numeric', 'min:0', 'max:999999'],
            'compare_at' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);

        if ($plan->isFree()) {
            return back()->with('error', 'A free plan cannot have a price. Change its type first.');
        }

        try {
            $price = $this->plans->addPrice(
                $plan,
                $data['interval'],
                $this->toCents($data['amount']),
                array_filter([
                    'compare_at_amount' => isset($data['compare_at']) && $data['compare_at'] !== null
                        ? $this->toCents($data['compare_at'])
                        : null,
                ], fn ($v) => $v !== null),
            );

            AuditLog::record('billing.price.created', [
                'payload' => [
                    'plan'     => $plan->slug,
                    'interval' => $price->interval,
                    'amount'   => $price->unit_amount,
                    'stripe'   => $price->stripe_price_ref,
                ],
            ]);

            return back()->with('success', $price->isSyncedToStripe()
                ? "{$price->intervalLabel()} price {$price->formatted()} added and created in Stripe."
                : "{$price->intervalLabel()} price {$price->formatted()} added. Sync to Stripe before selling it.");
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.price.create_failed', ['plan' => $plan->slug, 'error' => $e->getMessage()]);

            return back()->with('error', 'Price saved locally but Stripe sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Change the amount. Creates a NEW price + Stripe Price; grandfathers
     * every existing subscriber.
     */
    public function update(Request $request, int $id, int $priceId): RedirectResponse
    {
        $plan  = Plan::query()->findOrFail($id);
        $price = PlanPrice::query()->where('plan_id', $plan->id)->findOrFail($priceId);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        $newCents = $this->toCents($data['amount']);

        if ($newCents === $price->unit_amount) {
            return back()->with('info', 'That is already the current price.');
        }

        $subscriberCount = \App\Models\Billing\Subscription::query()
            ->where('plan_price_id', $price->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->count();

        try {
            $new = $this->plans->changePrice($price, $newCents);

            AuditLog::record('billing.price.changed', [
                'payload' => [
                    'plan'             => $plan->slug,
                    'interval'         => $price->interval,
                    'from'             => $price->unit_amount,
                    'to'               => $new->unit_amount,
                    'old_stripe'       => $price->stripe_price_ref,
                    'new_stripe'       => $new->stripe_price_ref,
                    'grandfathered'    => $subscriberCount,
                ],
            ]);

            $note = "New {$new->intervalLabel()} price is {$new->formatted()}.";

            if ($subscriberCount > 0) {
                $note .= " {$subscriberCount} existing subscriber(s) stay on {$price->formatted()} until you migrate them.";
            }

            return back()->with('success', $note);
        } catch (\Throwable $e) {
            Log::error('billing.price.change_failed', [
                'plan'  => $plan->slug,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Price change failed: ' . $e->getMessage());
        }
    }

    /** Stop selling a price. Existing subscriptions on it keep renewing. */
    public function deactivate(Request $request, int $id, int $priceId): RedirectResponse
    {
        $price = PlanPrice::query()->where('plan_id', $id)->findOrFail($priceId);

        $this->plans->deactivatePrice($price);

        AuditLog::record('billing.price.deactivated', [
            'payload' => ['price' => $price->id, 'stripe' => $price->stripe_price_ref],
        ]);

        return back()->with('success', 'Price hidden from new signups. Existing subscribers are unaffected.');
    }

    public function activate(Request $request, int $id, int $priceId): RedirectResponse
    {
        $price = PlanPrice::query()->where('plan_id', $id)->findOrFail($priceId);

        $this->plans->activatePrice($price);

        AuditLog::record('billing.price.activated', ['payload' => ['price' => $price->id]]);

        return back()->with('success', 'Price is on sale again. Any other price for this interval was deactivated.');
    }

    /** Create the Stripe Price for a row that doesn't have one yet. */
    public function sync(Request $request, int $id, int $priceId): RedirectResponse
    {
        if (! $this->stripe->isConfigured()) {
            return back()->with('error', 'Stripe keys are not configured.');
        }

        $price = PlanPrice::query()->with('plan')->where('plan_id', $id)->findOrFail($priceId);

        try {
            $synced = $this->stripeSync->syncPrice($price);

            AuditLog::record('billing.price.synced', [
                'payload' => ['price' => $price->id, 'stripe' => $synced->stripe_price_ref],
            ]);

            return back()->with('success', 'Stripe price created: ' . $synced->stripe_price_ref);
        } catch (\Throwable $e) {
            return back()->with('error', 'Stripe sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Archive in Stripe. Blocks new checkouts; existing subscriptions on this
     * price continue to renew at that amount.
     */
    public function archive(Request $request, int $id, int $priceId): RedirectResponse
    {
        $price = PlanPrice::query()->where('plan_id', $id)->findOrFail($priceId);

        $this->stripeSync->archivePrice($price);
        $this->plans->deactivatePrice($price);

        AuditLog::record('billing.price.archived', [
            'payload' => ['price' => $price->id, 'stripe' => $price->stripe_price_ref],
        ]);

        return back()->with('success', 'Price archived in Stripe. Existing subscribers keep renewing on it.');
    }

    /**
     * Dollars → integer cents.
     *
     * Via string formatting rather than (int) round($x * 100): float
     * multiplication makes 19.99 * 100 = 1998.9999…, and casting that
     * truncates to 1998. Getting a price wrong by a cent in the customer's
     * favour is a rounding bug; in ours it is a billing dispute.
     */
    private function toCents(float|string $dollars): int
    {
        return (int) round((float) $dollars * 100);
    }
}
