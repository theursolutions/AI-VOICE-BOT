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

        // The purchase belongs inside the product. Send them to our own Elements
        // page instead of Stripe's hosted one, carrying the selection so they
        // land on a form that already knows what they picked.
        //
        // Redirected rather than refused: every caller of this method is someone
        // who has just chosen a plan and pressed a button, and answering that
        // with an error would be a dead end where a working checkout exists two
        // lines away. The hosted path below stays reachable by flipping
        // BILLING_IN_APP_ONLY, so nothing is deleted — only routed past.
        if (config('billing.checkout.in_app_only', true)) {
            return redirect()->route('billing.checkout', [
                'client'   => $client->slug,
                'plan'     => $plan,
                'interval' => $interval,
            ]);
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
     * The on-site checkout page: order summary + saved cards + Elements form.
     *
     * `plan` and `interval` come in as query params — opaque identifiers only.
     * The amount shown is looked up server-side from the same `plan_prices`
     * row the charge will use, so the page cannot display one price and bill
     * another.
     */
    public function page(Request $request, Client $client)
    {
        $this->authorizeWorkspace($request, $client);

        abort_unless(config('billing.checkout.enabled', false), 404);

        if ($refusal = $this->refusedCountry($client)) {
            return redirect()
                ->route('billing.plans', ['client' => $client->slug])
                ->with('error', $refusal);
        }

        $planSlug = (string) $request->query('plan', '');
        $interval = (string) $request->query('interval', 'monthly');

        // Which providers this customer may choose between, and which one they
        // are looking at. A country can offer more than one — Pakistan offers
        // local methods in rupees AND an international card in dollars — and
        // which suits a given buyer is not something we can know for them.
        $registry  = app(\App\Services\Billing\Gateways\GatewayRegistry::class);
        $available = $registry->availableFor($client->billing_country);

        $via = (string) $request->query('via', '');

        if ($via === '' || ! $registry->isAvailableFor($client->billing_country, $via)) {
            $via = $available[0]?->key() ?? '';
        }

        $chosen = $registry->get($via);

        try {
            $price = $this->plans->resolvePrice(
                $planSlug,
                $interval,
                $client,
                // Priced in what the CHOSEN provider settles, not in what the
                // country would default to.
                $chosen?->currencies()[0] ?? null,
            );
        } catch (\RuntimeException $e) {
            return redirect()
                ->route('billing.plans', ['client' => $client->slug])
                ->with('error', $e->getMessage());
        }

        // A customer whose gateway hosts its own page never sees the Elements
        // form. It is Stripe-specific in every part that matters — the card
        // fields, the payment intent, the confirmation — so rendering it for a
        // Safepay customer would show a card form that cannot take their money,
        // and would hide the bank account, JazzCash and Easypaisa they came to
        // pay with.
        $checkout = app(\App\Services\Billing\GatewayCheckoutService::class);

        if ($chosen && $chosen->key() !== 'stripe') {
            $gateway = $chosen;

            return view('billing.checkout-hosted', [
                'title'        => 'Checkout',
                'client'       => $client,
                'plan'         => $price->plan,
                'price'        => $price,
                'priceDisplay' => $price->formatted(),
                // The same money the other way round, so the figure the
                // customer recognises is always on the page whichever currency
                // we are charging in. Never itself charged.
                'priceAlso'    => $this->secondaryPrice($price, $request),
                'gateway'      => $gateway,
                'subscription' => $client->currentSubscription(),
                // Still changeable here: someone who reaches checkout and finds
                // the wrong currency should be able to fix it on the spot
                // rather than hunt back through the plans page.
                'country'      => app(\App\Services\Billing\PricingPresenter::class)
                                    ->countryContext($request, $client),
                // What switching costs them, when it costs anything. Shown
                // before the button, not discovered afterwards.
                'forfeits'     => $checkout->forfeits($client, $price),
                'isRenewal'    => $checkout->isRenewal($price, $client->currentSubscription()),
                // The other ways this customer could pay, so the choice is on
                // the page rather than decided for them.
                'options'      => $this->payOptions($available, $client, $planSlug, $interval, $via),
                'via'          => $via,
                // Subtotal, tax and total — shown whenever a figure other than
                // the sticker price is about to be charged, because the moment
                // to learn that is before the button, not after.
                'tax'          => app(\App\Services\Billing\TaxService::class)
                                    ->breakdown($client, (int) $price->unit_amount, $gateway),
                // Paddle draws its checkout over this page and never navigates;
                // Safepay is a redirect. The page has to know which, because
                // one of them needs a script and a click handler and the other
                // needs a plain form submit.
                'overlay'      => $gateway instanceof \App\Services\Billing\Gateways\PaddleGateway
                    ? [
                        'js'          => (string) config('billing.paddle.js_url'),
                        // The PUBLIC token. The API key must never appear in a
                        // view — it would be in the page source of every
                        // checkout.
                        'token'       => $gateway->clientToken(),
                        'environment' => $gateway->environment(),
                    ]
                    : null,
            ]);
        }

        $cards = app(\App\Services\Billing\PaymentMethodService::class)->all($client);

        return view('billing.checkout', [
            'title'        => 'Checkout',
            'client'       => $client,
            'plan'         => $price->plan,
            'price'        => $price,
            'priceDisplay' => app(\App\Services\Billing\PricingPresenter::class)
                                ->renderPrice($price->unit_amount, $price->interval, $request, $price->currency),
            'cards'        => $cards,
            'subscription' => $client->currentSubscription(),
            'stripeKey'    => (string) config('billing.stripe.key'),
        ]);
    }

    /**
     * Create the subscription from an Elements-tokenised card. JSON only.
     *
     * Returns whether the browser still has work to do (3-D Secure, or simply
     * confirming a PaymentIntent created against a saved card). This endpoint
     * does NOT mark anyone as paid — the webhook does that.
     */
    public function subscribe(Request $request, Client $client): \Illuminate\Http\JsonResponse
    {
        $this->authorizeWorkspace($request, $client);

        if (! config('billing.checkout.enabled', false)) {
            return response()->json(['message' => 'Plans aren’t available to buy just yet.'], 422);
        }

        $data = $request->validate([
            'plan'           => ['required', 'string', 'max:100'],
            'interval'       => ['required', 'string', 'max:20'],
            // NOT `payment_method_id` — DecodeHashids would mangle it.
            'payment_method' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $this->billing->subscribeWithPaymentMethod(
                client:           $client,
                planSlug:         $data['plan'],
                interval:         $data['interval'],
                paymentMethodRef: $data['payment_method'],
                actor:            $request->user(),
            );

            AuditLog::record('billing.subscribe.attempted', [
                'payload' => [
                    'client_id' => $client->id,
                    'plan'      => $data['plan'],
                    'interval'  => $data['interval'],
                    'status'    => $result['status'],
                ],
            ]);

            return response()->json($result);
        } catch (\Stripe\Exception\CardException $e) {
            // The bank declined. Stripe's message is written for cardholders
            // ("Your card was declined."), so it is safe and useful to show.
            return response()->json(['message' => $e->getError()->message ?? 'Your card was declined.'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('billing.subscribe.failed', [
                'client_id' => $client->id,
                'plan'      => $data['plan'],
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['message' => 'We couldn’t complete your payment. Please try again.'], 500);
        }
    }

    /**
     * Called after the browser finishes a 3-D Secure challenge, to pull the
     * settled state forward rather than making the customer stare at a
     * "pending" screen until the webhook lands. Idempotent; the webhook is
     * still the authority.
     */
    public function confirm(Request $request, Client $client): \Illuminate\Http\JsonResponse
    {
        $this->authorizeWorkspace($request, $client);

        $data = $request->validate([
            'subscription' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->billing->refreshSubscription($client, $data['subscription']);
        } catch (\Throwable $e) {
            Log::info('billing.confirm.sync_skipped', ['error' => $e->getMessage()]);
        }

        $client->forgetSubscription();
        $sub = $client->currentSubscription();

        return response()->json([
            'status'      => $sub?->status,
            'active'      => (bool) $sub?->grantsAccess(),
            'plan'        => $sub?->plan?->name,
            'redirect'    => route('billing.index', ['client' => $client->slug]),
        ]);
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

    /**
     * Leave for the gateway's own checkout page.
     *
     * POST, not GET, and that is not ceremony: this creates a charge record and
     * opens a payment session at a third party. On a GET, a browser prefetch, a
     * back button or a refresh would each raise another one, and the customer
     * would arrive at a page listing payments they never attempted.
     */
    public function pay(Request $request, Client $client)
    {
        $this->authorizeWorkspace($request, $client);

        abort_unless(config('billing.checkout.enabled', false), 404);

        // Checked again, not only on the page: a guard the form can be posted
        // around is not a guard.
        if ($refusal = $this->refusedCountry($client)) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $refusal], 422)
                : redirect()->route('billing.plans', ['client' => $client->slug])->with('error', $refusal);
        }

        // Same two opaque identifiers the Stripe path takes, for the same
        // reason: no request field in this flow carries money. See the class
        // docblock — `plan` and `interval`, never `*_id`.
        $validated = $request->validate([
            'plan'     => ['required', 'string', 'max:64'],
            'interval' => ['required', 'string', 'max:32'],
            // Which provider they picked. Validated against what their country
            // actually offers, below — never trusted as sent.
            'via'      => ['nullable', 'string', 'max:24'],
        ]);

        $registry = app(\App\Services\Billing\Gateways\GatewayRegistry::class);
        $via      = (string) ($validated['via'] ?? '');

        if ($via === '' || ! $registry->isAvailableFor($client->billing_country, $via)) {
            $via = $registry->forClient($client)?->key() ?? '';
        }

        try {
            $price = $this->plans->resolvePrice(
                $validated['plan'],
                $validated['interval'],
                $client,
                $registry->get($via)?->currencies()[0] ?? null,
            );
        } catch (\RuntimeException $e) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $e->getMessage()], 422)
                : redirect()->route('billing.plans', ['client' => $client->slug])->with('error', $e->getMessage());
        }

        $checkout = app(\App\Services\Billing\GatewayCheckoutService::class);

        try {
            $started = $checkout->begin($client, $price, [
                'success_url'  => route('safepay.return'),
                'cancel_url'   => route('billing.checkout', [
                    'client' => $client->slug,
                    'plan' => $validated['plan'], 'interval' => $validated['interval'], 'via' => $via,
                ]),
                'callback_url' => route('safepay.webhook'),
            ], $via);
        } catch (\Throwable $e) {
            Log::error('billing.gateway_checkout_failed', [
                'client_id' => $client->id,
                'plan'      => $validated['plan'],
                'error'     => $e->getMessage(),
            ]);

            $message = $this->checkoutFailureMessage($request, $e);

            // An overlay checkout asked by fetch() and cannot follow a redirect
            // — answering one would leave the button spinning with nothing
            // shown.
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 502)
                : back()->with('error', $message);
        }

        AuditLog::record('billing.checkout.started', [
            'payload' => [
                'client_id' => $client->id,
                'plan'      => $price->plan?->slug,
                'interval'  => $price->interval,
                'gateway'   => $started['gateway'],
                'reference' => $started['reference'],
                'amount'    => $price->unit_amount,
                'currency'  => strtoupper((string) $price->currency),
            ],
        ]);

        $handoff = $started['handoff'];

        // An overlay never navigates: the page that asked stays put and draws
        // the provider's checkout over itself, so the answer is data rather
        // than a destination. Only the transaction id crosses — never an
        // amount, which the browser could edit before using.
        if ($handoff->isOverlay()) {
            return response()->json([
                'ok'          => true,
                'style'       => 'overlay',
                'transaction' => $handoff->reference,
                // OUR reference, so the page can show a receipt for the right
                // payment afterwards. The provider's transaction id means
                // nothing to our own records.
                'reference'   => $started['reference'],
                'token'       => (string) ($handoff->fields['token'] ?? ''),
                'environment' => (string) ($handoff->fields['environment'] ?? 'sandbox'),
            ]);
        }

        if ($handoff->isPost()) {
            // A gateway that wants a signed POST rather than a redirect. The
            // fields are echoed exactly as produced — the signature covers
            // these values.
            return response()->view('billing.gateway-handoff', [
                'action' => $handoff->url,
                'fields' => $handoff->fields,
            ]);
        }

        return redirect()->away($handoff->url);
    }

    /**
     * The price expressed in the other currency, for the line under the total.
     *
     * Exact when there is a sibling price row — a rupee customer seeing the
     * dollar figure is reading another price we set, not an estimate. A live
     * conversion otherwise, and marked approximate by the caller.
     *
     * Null when there is nothing useful to add, which is the ordinary case for
     * a customer already being charged in the platform currency with no local
     * currency of their own.
     *
     * @return array{amount: string, exact: bool}|null
     */
    private function secondaryPrice(\App\Models\Billing\PlanPrice $price, Request $request): ?array
    {
        $base = strtoupper((string) config('billing.currency', 'usd'));

        if (strtoupper((string) $price->currency) !== $base) {
            $platform = $price->plan?->priceFor($price->interval, $base);

            return $platform ? ['amount' => $platform->formatted(), 'exact' => true] : null;
        }

        $geo = app(\App\Services\Geo\GeoLocationService::class)->resolve($request);

        if (! $geo?->hasCurrency() || $geo->isUsd()) {
            return null;
        }

        $local = app(\App\Services\Currency\ExchangeRateService::class)
            ->convertAndFormat($price->unit_amount, $geo->currency);

        // A failed conversion omits the line rather than showing an empty "≈".
        return $local ? ['amount' => $local, 'exact' => false] : null;
    }

    /**
     * What to say when a checkout could not be started.
     *
     * TWO PROBLEMS WITH ONE MESSAGE FOR EVERYTHING, and this fixes both.
     *
     * First, "please try again in a moment" is a lie when the cause is
     * configuration. A missing API key or an unset dashboard option will not
     * resolve itself, and a customer told to retry will retry, fail, and
     * conclude the product is broken — which, for them, it is.
     *
     * Second, the person who CAN fix it learns nothing. A super-admin hitting
     * this sees the same bland sentence as a customer and has to go digging
     * through logs for a message the provider already spelled out. So they get
     * the provider's own words, and everyone else gets an honest apology.
     */
    private function checkoutFailureMessage(Request $request, \Throwable $e): string
    {
        // Wrong on our side and not going to right itself. Recognised by the
        // provider's own vocabulary rather than by exception class, because
        // every one of these arrives as a plain HTTP 400.
        $configFault = (bool) preg_match(
            '/payment link|not configured|no api key|unauthorized|forbidden|invalid.*(key|token|credential)/i',
            $e->getMessage(),
        );

        if ($request->user()?->isSuperAdmin() || config('app.debug')) {
            // The real reason, to the only people who can act on it.
            return 'Checkout could not start: ' . $e->getMessage();
        }

        return $configFault
            // No "try again": it cannot work until somebody changes something.
            ? 'Payments are temporarily unavailable. Our team has been notified — please contact us '
              . 'and we will get you set up right away.'
            : 'We could not reach the payment provider. Please try again in a moment.';
    }

    /**
     * The ways this customer could pay, described by what they would get.
     *
     * Never by provider name — a buyer choosing between "Safepay" and "Paddle"
     * is choosing between two companies they have not heard of. They are
     * choosing between paying in rupees with Easypaisa and paying in dollars
     * with a card, so that is what the options say.
     *
     * The PRICE is resolved per option, because it genuinely differs: the same
     * plan is Rs 22,500 one way and $75 the other, and revealing that after
     * they click would be the worst possible moment.
     *
     * @param  array<int, \App\Services\Billing\Gateways\PaymentGateway>  $available
     * @return array<int, array<string, mixed>>
     */
    private function payOptions(array $available, Client $client, string $plan, string $interval, string $via): array
    {
        if (count($available) < 2) {
            return [];   // no choice to offer
        }

        $out = [];

        foreach ($available as $gateway) {
            $currency = $gateway->currencies()[0] ?? null;

            try {
                $price = $this->plans->resolvePrice($plan, $interval, $client, $currency);
            } catch (\RuntimeException $e) {
                // A provider we cannot price for is not an option we can offer.
                // Skipped silently: the customer does not need to know that one
                // of several routes has no price row yet.
                continue;
            }

            $key   = $gateway->key();
            $local = in_array($key, ['safepay', 'payfast'], true);

            $out[] = [
                'key'      => $key,
                'label'    => $local
                    ? 'Pay in ' . strtoupper((string) $currency)
                    : 'Pay by card in ' . strtoupper((string) $currency),
                'blurb'    => $local
                    ? 'Card, bank account, JazzCash or Easypaisa'
                    : 'Card, PayPal, Apple Pay or Google Pay',
                'methods'  => $local
                    ? ['card', 'bank', 'jazzcash', 'easypaisa']
                    : ['card', 'paypal', 'applepay', 'googlepay'],
                'price'    => $price->formatted(),
                'selected' => $key === $via,
                'url'      => route('billing.checkout', [
                    'client' => $client->slug, 'plan' => $plan, 'interval' => $interval, 'via' => $key,
                ]),
            ];
        }

        return count($out) > 1 ? $out : [];
    }

    /**
     * Refuse a country we have said we do not sell to.
     *
     * ENFORCED HERE, not only in the picker. The Ops → Payments page states that
     * an unselected country "can't reach checkout", and until this existed that
     * was simply untrue: the restriction filtered the dropdown and nothing else,
     * so a workspace whose country was set before the restriction — or by IP —
     * walked straight past it and paid through a provider the operator had
     * deliberately stopped selling with.
     *
     * On BOTH the page and the pay action. A guard on the page alone is a guard
     * anyone can skip by posting the form directly.
     *
     * A workspace with NO country is allowed through: countryAllowed() treats
     * an unknown country as permitted, because failing to detect somebody is our
     * problem and not a reason to refuse their money.
     *
     * @return ?string the message to refuse with, or null to allow
     */
    private function refusedCountry(Client $client): ?string
    {
        if (\App\Support\Payments::countryAllowed($client->billing_country)) {
            return null;
        }

        $name = app(\App\Services\Geo\GeoLocationService::class)
            ->countryName((string) $client->billing_country) ?: $client->billing_country;

        return sprintf(
            'We can’t take payments from %s just yet. Get in touch and we’ll sort something out.',
            $name,
        );
    }

    /** Only a workspace owner may change what the workspace pays. */
    private function authorizeWorkspace(Request $request, Client $client): void
    {
        $user = $request->user();

        abort_unless($user && $user->hasMembership($client->id), 403);
        abort_unless($user->isOwnerOf($client->id), 403, 'Only the workspace owner can change billing.');
    }
}
