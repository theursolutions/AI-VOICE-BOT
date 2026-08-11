<?php

use App\Http\Controllers\Billing\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stripe webhook
|--------------------------------------------------------------------------
|
| Registered by RouteServiceProvider with NO middleware group at all — the
| same treatment routes/crawler.php gets, and for related reasons:
|
|   • CSRF     — Stripe has no session and no token. Inside the `web` group
|                every delivery would be rejected with a 419, which Stripe
|                would retry for days. Excluding the URI in VerifyCsrfToken
|                would also work, but keeping the route out of the group is
|                harder to undo by accident.
|   • Session  — a Set-Cookie on a machine endpoint is pure waste.
|   • Bindings — nothing to bind; the payload is raw JSON.
|
| Authentication is the STRIPE SIGNATURE, verified in the controller against
| STRIPE_WEBHOOK_SECRET before any part of the body is trusted. Without that
| check this route would be an unauthenticated "make me a subscriber" API.
|
| No `throttle` either: Stripe legitimately bursts (a batch of renewals all
| fire at once) and rate-limiting real events into 429s would make it retry
| them for days.
|
| Register this exact URL in the Stripe Dashboard:
|   https://<APP_DOMAIN>/stripe/webhook
|
*/

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook');
