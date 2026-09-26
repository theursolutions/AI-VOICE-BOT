<?php

use App\Http\Controllers\Billing\SafepayWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Safepay webhook and redirect (Payments 2.0)
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
| Authentication happens in the controller, and differs per route:
|
|   /billing/safepay/webhook            X-SFPY-SIGNATURE — HMAC-SHA512 over
|                                       the whole event, under
|                                       SAFEPAY_WEBHOOK_SECRET
|   /billing/safepay/return/{ref}       nothing to verify: 2.0 does not sign
|                                       the return, so it only prompts a
|                                       lookup of the session stored when
|                                       checkout began
|
| REGISTER THE WEBHOOK IN THE SAFEPAY DASHBOARD (Developers → Endpoints), in
| each environment separately, and subscribe it to the 2.0.0 events:
|
|   https://<APP_DOMAIN>/billing/safepay/webhook
|
| The return URL is not registered anywhere — it is sent per checkout as
| `redirect_url` with our reference in the path, so it follows the charge.
| The bare /billing/safepay/return still answers, for a customer who left for
| Safepay under the v1 integration and comes back after the deploy.
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

// Safepay sends the customer back here, by GET or POST.
Route::match(['get', 'post'], '/billing/safepay/return/{reference?}', [SafepayWebhookController::class, 'returned'])
    ->where('reference', '[A-Za-z0-9_\-]+')
    ->name('safepay.return');
