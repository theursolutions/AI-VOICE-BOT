<?php

use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\CustomPlanController;
use App\Http\Controllers\Billing\AddonController;
use App\Http\Controllers\Billing\PaymentMethodController;
use App\Http\Controllers\PricingController;
use App\Support\Hashid;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing routes (inside the `web` group — see routes/web.php)
|--------------------------------------------------------------------------
|
| The Stripe WEBHOOK is deliberately NOT here: it needs no session, no CSRF
| and no cookies, so it lives in routes/stripe.php and is registered with no
| middleware group at all (same pattern as routes/crawler.php).
|
| NAMING RULE THROUGHOUT: no form field and no route parameter is called
| `plan_id` / `price_id`. App\Http\Middleware\DecodeHashids rewrites any
| request key matching `*_id` through the hashid decoder, which has already
| caused one production 422. Public selection travels as `plan` (a slug) plus
| `interval`. See SUBSCRIPTION_REQUIREMENTS_ANALYSIS.md §5 C1.
|
*/

// ── Public pricing page ─────────────────────────────────────────────────
Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');

// Pricing page CTA. Auth is NOT required: an unauthenticated visitor gets
// their selection stashed in the session and is sent to register, so the
// funnel never loses the click.
Route::post('/pricing/checkout', [CheckoutController::class, 'start'])
    ->middleware('throttle:20,1')
    ->name('pricing.checkout');

// ── Workspace billing area ──────────────────────────────────────────────
// Scoped exactly like the rest of the admin (/c/{client:slug}/…), but WITHOUT
// `workspace.provisioned` or the module gates: a workspace whose free window
// lapsed must still be able to reach the page that takes their money.
Route::middleware(['auth', 'active.client'])
    ->prefix('c/{client:slug}')
    ->scopeBindings()
    ->group(function () {
        // Plan + usage overview — the page everything links back to.
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');

        // Upgrade / change plan → on-site checkout.
        Route::get('/billing/plans',    [BillingController::class, 'plans'])->name('billing.plans');
        Route::get('/billing/checkout', [CheckoutController::class, 'page'])->name('billing.checkout');

        // Elements flow (JSON). `subscribe` creates the subscription from a
        // tokenised card; `confirm` pulls state forward after a 3-D Secure
        // challenge. Neither marks anyone paid — the webhook does that.
        Route::post('/billing/subscribe', [CheckoutController::class, 'subscribe'])
            ->middleware('throttle:20,1')->name('billing.subscribe');
        Route::post('/billing/confirm',   [CheckoutController::class, 'confirm'])
            ->middleware('throttle:30,1')->name('billing.confirm');

        // Saved cards. Field is `payment_method`, never `payment_method_id` —
        // DecodeHashids rewrites `*_id` keys and would corrupt a pm_… ref.
        Route::post  ('/billing/cards/intent',  [PaymentMethodController::class, 'intent'])->name('billing.cards.intent');
        Route::post  ('/billing/cards',         [PaymentMethodController::class, 'store'])->name('billing.cards.store');
        Route::post  ('/billing/cards/default', [PaymentMethodController::class, 'makeDefault'])->name('billing.cards.default');
        Route::delete('/billing/cards',         [PaymentMethodController::class, 'destroy'])->name('billing.cards.destroy');

        // Add-ons (extra seats / AI agents). Their own page, NOT a step inside
        // plan selection: someone who already pays for a plan and needs one
        // more seat should never be shown the plan ladder again.
        // Field is `addon`, not `addon_id` — DecodeHashids rewrites `*_id`.
        Route::get ('/billing/addons',         [AddonController::class, 'index'])->name('billing.addons');
        Route::post('/billing/addons/preview', [AddonController::class, 'preview'])
            ->middleware('throttle:60,1')->name('billing.addons.preview');
        Route::post('/billing/addons',         [AddonController::class, 'update'])->name('billing.addons.update');

        // Branded invoice. `invoice` is deliberately not an *_id param name.
        Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice'])
            ->where('invoice', '[A-Za-z0-9_]+')->name('billing.invoice');

        // Hosted Stripe Checkout — kept as a fallback path and still used by
        // the public pricing page CTA.
        Route::post('/billing/checkout/start',   [CheckoutController::class, 'store'])->name('billing.checkout.store');
        Route::get ('/billing/checkout/success', [CheckoutController::class, 'success'])->name('billing.checkout.success');
        Route::get ('/billing/checkout/cancel',  [CheckoutController::class, 'cancel'])->name('billing.checkout.cancel');

        Route::post('/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
        // ── Build your own plan ─────────────────────────────────────────
    // For the workspace no published tier fits. Owner-only, and the price is
    // always recomputed server-side from the posted CONFIGURATION — the browser
    // sends what it wants, never what it should cost.
    Route::get ('/billing/custom', [CustomPlanController::class, 'show'])->name('billing.custom');
    Route::post('/billing/custom/quote', [CustomPlanController::class, 'quote'])->name('billing.custom.quote');
    Route::post('/billing/custom', [CustomPlanController::class, 'store'])->name('billing.custom.store');

    // How many AI replies each conversation gets before a human takes over.
    // Lives here rather than in project settings because it decides how far the
    // plan's message allowance stretches, which is a billing decision.
    Route::post('/billing/conversation-budget', [BillingController::class, 'conversationBudget'])
        ->name('billing.conversation-budget');
    Route::post('/billing/change', [BillingController::class, 'change'])->name('billing.change');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    });

// ── Super Admin → Billing ───────────────────────────────────────────────
Route::middleware(['auth', 'super-admin'])
    ->prefix('admin/billing')
    ->name('ops.billing.')
    ->group(function () {
        $plans    = \App\Http\Controllers\SuperAdmin\Billing\PlansController::class;
        $prices   = \App\Http\Controllers\SuperAdmin\Billing\PlanPricesController::class;
        $features = \App\Http\Controllers\SuperAdmin\Billing\FeaturesController::class;
        $subs     = \App\Http\Controllers\SuperAdmin\Billing\SubscriptionsController::class;
        $spaces   = \App\Http\Controllers\SuperAdmin\Billing\WorkspacePlansController::class;

        // Plans
        Route::get ('/plans',              [$plans, 'index'])->name('plans.index');
        Route::get ('/plans/create',       [$plans, 'create'])->name('plans.create');
        Route::post('/plans',              [$plans, 'store'])->name('plans.store');
        Route::post('/plans/reorder',      [$plans, 'reorder'])->name('plans.reorder');
        Route::post('/plans/sync-stripe',  [$plans, 'syncStripe'])->name('plans.sync-stripe');

        Route::get ('/plans/{id}/edit',    [$plans, 'edit'])->where('id', Hashid::ROUTE_PATTERN)->name('plans.edit');
        Route::patch('/plans/{id}',        [$plans, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('plans.update');
        Route::post('/plans/{id}/toggle',  [$plans, 'toggleActive'])->where('id', Hashid::ROUTE_PATTERN)->name('plans.toggle');
        Route::post('/plans/{id}/feature', [$plans, 'feature'])->where('id', Hashid::ROUTE_PATTERN)->name('plans.feature');

        // Prices. `priceId` matches Hashid::isIdKey(), so DecodeHashids will
        // decode it from a hashid to an int before the controller sees it —
        // which is exactly what we want for a route parameter.
        Route::post ('/plans/{id}/prices',                    [$prices, 'store'])->where('id', Hashid::ROUTE_PATTERN)->name('prices.store');
        Route::patch('/plans/{id}/prices/{priceId}',          [$prices, 'update'])->where(['id' => Hashid::ROUTE_PATTERN, 'priceId' => Hashid::ROUTE_PATTERN])->name('prices.update');
        Route::post ('/plans/{id}/prices/{priceId}/activate', [$prices, 'activate'])->where(['id' => Hashid::ROUTE_PATTERN, 'priceId' => Hashid::ROUTE_PATTERN])->name('prices.activate');
        Route::post ('/plans/{id}/prices/{priceId}/deactivate',[$prices, 'deactivate'])->where(['id' => Hashid::ROUTE_PATTERN, 'priceId' => Hashid::ROUTE_PATTERN])->name('prices.deactivate');
        Route::post ('/plans/{id}/prices/{priceId}/sync',     [$prices, 'sync'])->where(['id' => Hashid::ROUTE_PATTERN, 'priceId' => Hashid::ROUTE_PATTERN])->name('prices.sync');
        Route::post ('/plans/{id}/prices/{priceId}/archive',  [$prices, 'archive'])->where(['id' => Hashid::ROUTE_PATTERN, 'priceId' => Hashid::ROUTE_PATTERN])->name('prices.archive');

        // Features & limits matrix
        Route::get   ('/features',           [$features, 'index'])->name('features.index');
        Route::post  ('/features',           [$features, 'store'])->name('features.store');
        Route::post  ('/features/matrix',    [$features, 'updateMatrix'])->name('features.matrix');
        Route::patch ('/features/{id}',      [$features, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('features.update');
        Route::delete('/features/{id}',      [$features, 'destroy'])->where('id', Hashid::ROUTE_PATTERN)->name('features.destroy');

        // Subscriptions & Stripe events
        Route::get ('/subscriptions',                     [$subs, 'index'])->name('subscriptions.index');
        Route::get ('/events',                            [$subs, 'events'])->name('subscriptions.events');
        Route::post('/subscriptions/{id}/extend-free',     [$subs, 'extendFreeWindow'])->where('id', Hashid::ROUTE_PATTERN)->name('subscriptions.extend-free');
        Route::post('/subscriptions/{id}/reconcile',       [$subs, 'reconcile'])->where('id', Hashid::ROUTE_PATTERN)->name('subscriptions.reconcile');
        Route::post('/clients/{clientId}/waive-trial',     [$subs, 'waiveFingerprints'])->where('clientId', Hashid::ROUTE_PATTERN)->name('subscriptions.waive-trial');

        // Workspace plans — assign any plan at no charge, and grant allowances
        // beyond what a plan includes. The operator counterpart to the
        // customer's own billing page.
        Route::get ('/workspaces',                       [$spaces, 'index'])->name('workspaces.index');
        Route::get ('/workspaces/{id}',                  [$spaces, 'show'])->where('id', Hashid::ROUTE_PATTERN)->name('workspaces.show');
        Route::post('/workspaces/{id}/assign',           [$spaces, 'assign'])->where('id', Hashid::ROUTE_PATTERN)->name('workspaces.assign');
        Route::post('/workspaces/{id}/grant',            [$spaces, 'grant'])->where('id', Hashid::ROUTE_PATTERN)->name('workspaces.grant');
        // feature_key is a slug, not an id, so it is NOT run through the hashid
        // pattern — doing so would reject every real key.
        Route::delete('/workspaces/{id}/grant/{featureKey}', [$spaces, 'revoke'])
            ->where(['id' => Hashid::ROUTE_PATTERN, 'featureKey' => '[a-z0-9_]+'])
            ->name('workspaces.revoke');
    });
