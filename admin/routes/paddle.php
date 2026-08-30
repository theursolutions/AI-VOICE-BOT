<?php

use App\Http\Controllers\Billing\PaddleWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Paddle webhook
|--------------------------------------------------------------------------
|
| Registered by RouteServiceProvider with NO middleware group, exactly as
| routes/stripe.php and routes/safepay.php are, and for the same reasons:
|
|   • CSRF     — Paddle has no session and no token. Inside the `web` group
|                every delivery would be a 419, which they would retry for
|                days. Keeping the route out of the group is harder to undo
|                by accident than an entry in VerifyCsrfToken's exception list.
|   • Session  — a Set-Cookie on a machine endpoint is waste.
|   • Throttle — Paddle bills every renewal itself, so a month boundary
|                legitimately delivers a burst; turning real events into 429s
|                makes them retry for days.
|
| Authentication is the SIGNATURE — `Paddle-Signature: ts=…;h1=…`, an
| HMAC-SHA256 over "{ts}:{raw body}" under the notification secret — checked
| before any part of the body is believed. Without it this is an
| unauthenticated "make me a subscriber" API.
|
| THERE IS NO RETURN ROUTE, and that is the point of the overlay: the
| customer never leaves. Paddle.js fires `checkout.completed` in their
| browser and the page navigates itself back to billing — but that event
| grants nothing. Only this endpoint does.
|
| REGISTER THIS URL IN THE PADDLE DASHBOARD (Developer Tools →
| Notifications), subscribed to at least:
|
|   transaction.completed      the first payment and every renewal
|   subscription.created       links Paddle's subscription to ours
|   subscription.updated       keeps the renewal date in step
|   subscription.canceled      stops the auto-renewal, keeps paid time
|
|   https://<APP_DOMAIN>/billing/paddle/webhook
|
*/

Route::post('/billing/paddle/webhook', [PaddleWebhookController::class, 'webhook'])
    ->name('paddle.webhook');
