<?php

use App\Http\Controllers\Billing\SafepayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Safepay webhook and redirect
|--------------------------------------------------------------------------
|
| Registered by RouteServiceProvider with NO middleware group, exactly as
| routes/stripe.php is, and for the same reasons:
|
|   • CSRF     — Safepay has no session and no token. Inside the `web` group
|                every delivery would be a 419, which they would retry for
|                days. Keeping the route out of the group is harder to undo
|                by accident than an entry in VerifyCsrfToken's exception list.
|   • Session  — a Set-Cookie on a machine endpoint is waste.
|   • Throttle — a batch of renewals legitimately fires at once, and turning
|                real events into 429s makes them retry for days.
|
| Authentication is the SIGNATURE, checked before any part of the body is
| believed. Without it these would be an unauthenticated "make me a
| subscriber" API. The two routes are signed differently and that is not
| interchangeable:
|
|   /billing/safepay/webhook   X-SFPY-SIGNATURE over the RAW body,
|                              under SAFEPAY_WEBHOOK_SECRET
|   /billing/safepay/return    `sig` over the tracker,
|                              under SAFEPAY_V1_SECRET
|
| REGISTER THIS URL IN THE SAFEPAY DASHBOARD:
|
|   https://<APP_DOMAIN>/billing/safepay/webhook
|
| The return URL is not registered anywhere — it is sent per checkout as
| `redirect_url`, so it follows the workspace rather than being global.
|
| The webhook is the source of truth. The redirect is the customer's browser
| coming back, and a customer who closes the tab mid-payment never sends it;
| the webhook still arrives. Anything that must happen on payment belongs on
| the webhook side, with the redirect only deciding what the customer is
| shown next.
|
*/

Route::post('/billing/safepay/webhook', [SafepayWebhookController::class, 'webhook'])
    ->name('safepay.webhook');

// Safepay POSTs the customer back here with tracker + sig.
Route::match(['get', 'post'], '/billing/safepay/return', [SafepayWebhookController::class, 'returned'])
    ->name('safepay.return');
