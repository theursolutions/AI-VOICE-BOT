<?php

use Illuminate\Support\Facades\Route;
use Msd\MetaChannels\Http\Controllers\WebhookController;

/*
| Meta webhook endpoints. Loaded by MetaChannelsServiceProvider with no
| middleware group: Meta authenticates via the verify token (GET) and the
| X-Hub-Signature-256 HMAC (POST), and must not be subject to CSRF.
|
| Same callback URL handles WhatsApp messages AND calls (Meta sends both
| on the configured webhook; the controller branches on the change field).
*/
Route::get('api/whatsapp/webhook',  [WebhookController::class, 'verify']);
Route::post('api/whatsapp/webhook', [WebhookController::class, 'webhook']);
