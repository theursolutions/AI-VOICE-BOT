<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanService;
use App\Services\Billing\PricingPresenter;
use App\Services\Billing\UsageLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The customer's billing area, at /c/{client}/billing.
 *
 * DIVISION WITH THE STRIPE CUSTOMER PORTAL: we own everything that depends on
 * OUR plan catalogue (which plan, which interval, usage against allowances,
 * upgrade/downgrade, cancel/resume) because Stripe knows nothing about our
 * features or limits. We hand off everything payment-instrument-shaped —
 * cards, addresses, tax ids, invoice PDFs, SCA — to Stripe's hosted portal
 * rather than rebuilding PCI-adjacent UI we would then have to maintain.
 *
 * Reads are open to any member who can see the module; every WRITE requires
 * the workspace OWNER.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly PlanService $plans,
        private readonly UsageLimitService $usage,
        private readonly PricingPresenter $presenter,
    ) {
    }

    public function index(Request $request, Client $client): View
    {
        $subscription = $client->currentSubscription();
        $plan         = $subscription?->plan;
        $price        = $subscription?->planPrice;

        return view('billing.index', [
            'title'        => 'Billing',
            'client'       => $client,
            'subscription' => $subscription,
            'plan'         => $plan,
            'price'        => $price,

            // Rendered through the same presenter as /pricing so the amount
            // and its approximate local equivalent are formatted identically
            // in both places.
            'priceDisplay' => $price
                ? $this->presenter->renderPrice($price->unit_amount, $price->interval, $request)
                : null,

            'usage'         => $this->usage->summaryFor($client),
            'invoices'      => $this->billing->invoices($client),
            'paymentMethod' => $this->billing->paymentMethod($client),
            'cards'         => app(\App\Services\Billing\PaymentMethodService::class)->all($client),
            'addons'        => app(\App\Services\Billing\AddonService::class)->available($client),
            'addonTotal'    => app(\App\Services\Billing\AddonService::class)->monthlyTotalCents($client),

            // Upgrade/downgrade options, priced for this visitor.
            'pricing'       => $this->presenter->build($request, $price?->interval),

            'isOwner'       => (bool) $request->user()?->isOwnerOf($client->id),
            'stripeReady'   => $this->billing->isConfigured(),
        ]);
    }

    /**
     * Choose / upgrade plan. The current plan is pre-selected so the page
     * reads as "you are here, move from here" rather than a cold price list.
     */
    public function plans(Request $request, Client $client): View
    {
        $this->authorizeOwner($request, $client);

        $subscription = $client->currentSubscription();
        $current      = $subscription?->planPrice;

        return view('billing.plans', [
            'title'          => 'Choose a plan',
            'client'         => $client,
            'subscription'   => $subscription,
            'currentPlan'    => $subscription?->plan,
            'currentPrice'   => $current,
            // Same presenter as /pricing, so the numbers here and on the
            // marketing site can never disagree.
            'pricing'        => $this->presenter->build($request, $current?->interval),
            'checkoutOpen'   => (bool) config('billing.checkout.enabled', false),

            // Extra seats / AI agents are bought here too, not only from the
            // billing overview: this is the page someone lands on when they
            // hit a limit, and "upgrade the whole plan" is the wrong answer
            // when all they need is one more seat.
            'addons'         => app(\App\Services\Billing\AddonService::class)->available($client),
            'canBuyAddons'   => (bool) $subscription?->stripe_subscription_ref
                                && (bool) $subscription?->grantsAccess(),
        ]);
    }

    /**
     * A branded invoice we render ourselves.
     *
     * Stripe already produces a PDF, and we link to it — but that one carries
     * Stripe's layout, not ours, and it isn't reachable without leaving the
     * app. This is the in-product version: same numbers, our identity, and
     * printable.
     *
     * The invoice id comes from the URL, so it is re-fetched from Stripe and
     * checked against THIS workspace's customer before anything is rendered —
     * otherwise an `in_…` id would read another tenant's invoice.
     */
    public function invoice(Request $request, Client $client, string $invoice): View
    {
        abort_unless($request->user()?->hasMembership($client->id), 403);

        $data = $this->billing->invoice($client, $invoice);

        abort_if($data === null, 404);

        return view('billing.invoice', [
            'title'   => 'Invoice ' . ($data['number'] ?: $invoice),
            'client'  => $client,
            'invoice' => $data,
        ]);
    }

    /** Redirect into Stripe's hosted portal for cards, addresses and invoices. */
    public function portal(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        if (! $client->hasStripeCustomer()) {
            return back()->with('info', 'You’ll be able to manage payment details after your first subscription.');
        }

        try {
            $url = $this->billing->portalUrl(
                $client,
                route('billing.index', ['client' => $client->slug])
            );

            return redirect()->away($url);
        } catch (\Throwable $e) {
            Log::error('billing.portal.failed', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return back()->with('error', 'We couldn’t open the billing portal. Please try again.');
        }
    }

    /**
     * Cancel at period end by default — the customer keeps what they already
     * paid for. Immediate cancellation would destroy value they bought, and
     * Stripe does not refund the remainder on its own.
     */
    public function cancel(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        $immediately = $request->boolean('immediately');

        try {
            $this->billing->cancel($client, atPeriodEnd: ! $immediately);

            AuditLog::record('billing.subscription.canceled', [
                'payload' => ['client_id' => $client->id, 'immediately' => $immediately],
            ]);

            // forgetSubscription() returns void — drop the memo, then re-read.
            $client->forgetSubscription();
            $endsAt = $client->currentSubscription()?->ends_at;

            return back()->with('success', $immediately
                ? 'Your subscription has been cancelled.'
                : 'Your subscription will end on ' . ($endsAt?->format('j M Y') ?? 'your renewal date') .
                  '. You keep full access until then.');
        } catch (\Throwable $e) {
            Log::error('billing.cancel.failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'We couldn’t cancel your subscription. Please contact support.');
        }
    }

    /** Undo a pending cancellation while still inside the paid period. */
    public function resume(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        try {
            $this->billing->resume($client);

            AuditLog::record('billing.subscription.resumed', [
                'payload' => ['client_id' => $client->id],
            ]);

            return back()->with('success', 'Welcome back — your subscription will keep renewing.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.resume.failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'We couldn’t resume your subscription. Please contact support.');
        }
    }

    /**
     * Change plan or interval on an existing paid subscription.
     *
     * Same trust boundary as checkout: `plan` + `interval` only, resolved
     * server-side. Field names avoid `*_id` because DecodeHashids rewrites
     * those keys (ANALYSIS §5 C1).
     */
    public function change(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        // Same master switch as checkout: while billing is informational only,
        // an existing subscriber must not be able to swap plans either.
        if (! config('billing.checkout.enabled', false)) {
            return back()->with('info', 'Plan changes aren’t available just yet. Your current plan is unaffected.');
        }

        $data = $request->validate([
            'plan'     => ['required', 'string', 'max:100'],
            'interval' => ['required', 'string', 'max:20'],
        ]);

        $subscription = $client->currentSubscription();

        // No live Stripe subscription (free window, expired, cancelled) → this
        // has to be a fresh checkout, not a swap.
        if (! $subscription?->stripe_subscription_ref || ! $subscription->grantsAccess()) {
            return redirect()->route('billing.checkout.store', ['client' => $client->slug])
                ->withInput($data);
        }

        try {
            $this->billing->swap($client, $data['plan'], $data['interval']);

            AuditLog::record('billing.plan.changed', [
                'payload' => ['client_id' => $client->id] + $data,
            ]);

            return back()->with('success', 'Your plan has been updated. Any difference is prorated on your next invoice.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('billing.change.failed', [
                'client_id' => $client->id,
                'error'     => $e->getMessage(),
            ]);

            return back()->with('error', 'We couldn’t change your plan. Please try again.');
        }
    }

    private function authorizeOwner(Request $request, Client $client): void
    {
        abort_unless(
            $request->user()?->isOwnerOf($client->id),
            403,
            'Only the workspace owner can manage billing.'
        );
    }
}
