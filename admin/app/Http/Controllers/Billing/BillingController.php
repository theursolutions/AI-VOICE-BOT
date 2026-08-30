<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanService;
use App\Services\Billing\PricingPresenter;
use App\Services\Billing\UsageLimitService;
use App\Services\Conversation\ConversationBudget;
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
                ? $this->presenter->renderPrice($price->unit_amount, $price->interval, $request, $price->currency)
                : null,

            // A payment that has just completed, if this page was reached from
            // one. Scoped to the workspace, so a guessed reference cannot show
            // somebody else's receipt.
            'paidCharge'    => $this->justPaid($request, $client),

            'usage'         => $this->usage->summaryFor($client),

            // How the message allowance divides into conversations. Plans are
            // sold in conversations and billed in messages, so this is the
            // number that connects the two — and it is the owner's to set.
            'perConversation' => app(ConversationBudget::class)->limitForClient($client),
            'budgetBounds'    => [
                'min'     => ConversationBudget::MIN_LIMIT,
                'max'     => ConversationBudget::MAX_LIMIT,
                'default' => ConversationBudget::DEFAULT_LIMIT,
            ],
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
            // marketing site can never disagree — except in currency, which is
            // the one thing that MUST differ: this page quotes what this
            // workspace's own gateway will charge, not a dollar figure it would
            // never see on a statement.
            'pricing'        => $this->presenter->build($request, $current?->interval, $client),

            // The picker that decides currency, gateway and price. On this page
            // an owner's choice is stored on the workspace, because it is what
            // checkout will actually route on.
            'country'        => $this->presenter->countryContext($request, $client),
            // The divisor behind every conversation figure on this page, so the
            // note can state the real number rather than a generic "depends".
            'perConversation' => app(ConversationBudget::class)->limitForClient($client),
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

        // Retired while the product owns the whole billing surface. Everything
        // the portal offered is here: cards have their own form, invoices are
        // rendered by us, and billing details are edited below. Refused at the
        // endpoint and not merely unlinked, because an old bookmark is a live
        // request.
        if (config('billing.checkout.in_app_only', true)) {
            return back()->with('info', 'Everything is on this page — payment methods, invoices and '
                . 'billing details. There is nowhere else to go.');
        }

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
        //
        // Straight to the in-app form, carrying the selection. This used to post
        // to checkout.store, which built a hosted Stripe session — so "change
        // plan" was the one action that could still walk a customer off the
        // product, and only for the subset who had never paid before.
        if (! $subscription?->stripe_subscription_ref || ! $subscription->grantsAccess()) {
            return redirect()->route('billing.checkout', [
                'client'   => $client->slug,
                'plan'     => $data['plan'],
                'interval' => $data['interval'],
            ]);
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

    /**
     * Set how many AI replies each conversation gets before a human takes over.
     *
     * Owner-only, like every other write here: it decides how far the plan's
     * message allowance stretches, and how much of the team's time the AI hands
     * back. Not a per-agent preference.
     *
     * Validated AND clamped. The rules reject an out-of-range submit with a
     * message the owner can act on; the clamp is what protects the reply path
     * from a value that arrives any other way — a seeded row, a fixture, a hand
     * edit — because a stored 0 would hand every conversation to a human on its
     * first message and the AI would look completely dead.
     */
    public function conversationBudget(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'messages_per_conversation' => [
                'required', 'integer',
                'min:' . ConversationBudget::MIN_LIMIT,
                'max:' . ConversationBudget::MAX_LIMIT,
            ],
        ], [
            'messages_per_conversation.min' => 'Below ' . ConversationBudget::MIN_LIMIT
                . ' replies the assistant cannot finish a greeting and an answer, so every'
                . ' conversation would go straight to a person.',
            'messages_per_conversation.max' => 'Above ' . ConversationBudget::MAX_LIMIT
                . ' replies a single conversation can consume a whole month of messages.',
        ]);

        $limit = ConversationBudget::clamp($data['messages_per_conversation']);

        $json = is_array($client->json_data) ? $client->json_data : [];
        $json[ConversationBudget::SETTING_KEY] = $limit;
        $client->json_data = $json;
        $client->save();

        // Every project in the workspace, since the setting is workspace-wide
        // and each project caches its own copy.
        ConversationBudget::forgetClient((int) $client->id);

        AuditLog::record('billing.conversation_budget', [
            'target_type' => 'client',
            'target_id'   => $client->id,
            'payload'     => ['messages_per_conversation' => $limit],
        ]);

        return back()->with(
            'success',
            "Saved. Each conversation now gets {$limit} AI replies before a team member takes over."
        );
    }

    /**
     * Billing details — the name, address and tax number that appear on an
     * invoice.
     *
     * This is what replaces the hosted portal. Saved locally first and pushed to
     * Stripe second, in that order deliberately: our own invoice renders from
     * these columns, so the customer's paperwork is correct the moment they press
     * save even if Stripe is unreachable, and even if they have no Stripe
     * customer yet because they have never paid.
     */
    public function updateDetails(Request $request, Client $client): RedirectResponse
    {
        $this->authorizeOwner($request, $client);

        $data = $request->validate([
            'billing_name'    => ['nullable', 'string', 'max:255'],
            'billing_email'   => ['nullable', 'email', 'max:255'],
            // ISO-3166 alpha-2, which is what Stripe wants and what
            // config('billing.country_currency') is keyed on.
            'billing_country' => ['nullable', 'string', 'size:2', 'alpha'],
            // Free text: a GST number, a VAT number, an NTN and an EIN have
            // nothing in common but needing to appear on an invoice, and
            // per-country validation would reject legitimate identifiers long
            // before it caught a typo.
            'billing_tax_id'  => ['nullable', 'string', 'max:60'],
        ]);

        $client->forceFill([
            'billing_name'    => $data['billing_name'] ?: null,
            'billing_email'   => $data['billing_email'] ?: null,
            'billing_country' => $data['billing_country'] ? strtoupper($data['billing_country']) : null,
            'billing_tax_id'  => $data['billing_tax_id'] ?: null,
            'updated_at'      => time(),
        ])->save();

        $this->billing->syncCustomerDetails($client);

        AuditLog::record('billing.details_updated', [
            'target_type' => 'client',
            'target_id'   => $client->id,
            'payload'     => ['country' => $client->billing_country],
        ]);

        return back()->with('success', 'Billing details saved. They will appear on your next invoice.');
    }

    /**
     * A printable receipt for one gateway payment.
     *
     * SEPARATE FROM `invoice()`, which fetches a Stripe invoice by its Stripe
     * id. A Safepay or Paddle payment has no Stripe invoice and never will, so
     * a customer who paid that way had nothing to download at all.
     *
     * Any MEMBER may view it, not only the owner: a receipt is what somebody
     * files with their accounts, and locking it to one person means the one
     * person who cannot produce it is the bookkeeper.
     *
     * Scoped to the workspace, because the reference travels in the URL.
     */
    public function receipt(Request $request, Client $client, string $reference): View
    {
        abort_unless($request->user()?->hasMembership($client->id), 403);

        $charge = \Illuminate\Support\Facades\DB::table('gateway_charges')
            ->where('reference', $reference)
            ->where('client_id', $client->id)
            ->where('status', 'paid')
            ->first();

        // 404 for "not ours" and for "not paid yet" alike — a receipt for an
        // unsettled payment is a document that says something untrue.
        abort_if(! $charge, 404);

        $items = json_decode($charge->line_items ?? '', true) ?: [];
        $tax   = (int) ($items['tax'] ?? 0);

        $plan = $charge->plan_price_id
            ? \App\Models\Billing\PlanPrice::with('plan')->find($charge->plan_price_id)?->plan
            : null;

        $isAddon = ($charge->purpose ?? 'plan') === 'addon';

        $gateway = app(\App\Services\Billing\Gateways\GatewayRegistry::class)->get($charge->gateway);
        $isMor   = app(\App\Services\Billing\TaxService::class)->handledByProvider($gateway);

        return view('billing.receipt', [
            'charge'       => $charge,
            'client'       => $client,
            'brandName'    => tva_setting('content.brand_name', 'Serve AI'),
            'brandAddress' => tva_setting('content.company_address', ''),
            'taxId'        => $client->billing_tax_id ?: null,
            'countryName'  => app(\App\Services\Geo\GeoLocationService::class)
                                ->countryName((string) $client->billing_country) ?: '',
            'description'  => $isAddon
                ? trim(((int) ($items['to'] ?? 0)) . ' × ' . str_replace('addon-', '', (string) ($items['addon_slug'] ?? 'add-on')))
                : ($plan?->name ?? 'Subscription'),
            // Inclusive tax is already inside the total, so the line above it
            // must be the total minus the tax, not the total again.
            'subtotal'     => ($items['tax_mode'] ?? '') === 'inclusive'
                                ? (int) $charge->amount_cents - $tax
                                : (int) $charge->amount_cents - $tax,
            'tax'          => $tax,
            'taxRate'      => (float) ($items['tax_rate'] ?? 0),
            'taxInclusive' => ($items['tax_mode'] ?? '') === 'inclusive',
            'resellerNote' => $isMor
                ? 'This payment was collected by our authorised reseller, who is the merchant of '
                  . 'record for the sale and issues the tax invoice for it.'
                : null,
        ]);
    }

    /**
     * The payment this page was reached from, when it was.
     *
     * SCOPED TO THE WORKSPACE, always. The reference travels in a query string,
     * so it is the customer's to edit — and without the `client_id` check a
     * guessed one would render somebody else's receipt, complete with their
     * plan and what they paid for it.
     *
     * Returns null when the payment has not settled yet. A webhook can trail
     * the redirect by a second or two, and a receipt announcing a payment the
     * records do not yet show is worse than no receipt: the customer screenshots
     * it, and we have no charge to match it against.
     */
    private function justPaid(Request $request, Client $client): ?object
    {
        $reference = trim((string) $request->query('paid', ''));

        if ($reference === '' || strlen($reference) > 64) {
            return null;
        }

        $charge = \Illuminate\Support\Facades\DB::table('gateway_charges')
            ->where('reference', $reference)
            ->where('client_id', $client->id)
            ->where('status', 'paid')
            ->first();

        if (! $charge) {
            return null;
        }

        // Only just. An old reference re-pasted into the URL should not pop a
        // celebration modal for a payment made last month.
        return \Illuminate\Support\Carbon::parse($charge->paid_at)->gt(now()->subHours(6))
            ? $charge
            : null;
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
