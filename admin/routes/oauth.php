<?php

use App\Http\Controllers\OAuth\HubSpotOAuthController;
use App\Http\Controllers\OAuth\OAuthController;
use Illuminate\Support\Facades\Route;

/*
| Browser-facing OAuth callbacks for CRM connectors.
|
| Wired into web.php via:  require __DIR__.'/oauth.php';
| Web middleware group is inherited (session/cookies/CSRF), and we layer
| `auth + active.client` so we know the user's workspace before creating
| a data_sources row.
*/

// HubSpot keeps its dedicated controller so existing links/routes are stable.
Route::middleware(['auth', 'active.client'])->prefix('oauth/hubspot')->group(function () {
    Route::get('start',    [HubSpotOAuthController::class, 'start'])->name('oauth.hubspot.start');
    Route::get('callback', [HubSpotOAuthController::class, 'callback'])->name('oauth.hubspot.callback');
});

// Generic OAuth dance for newer providers (salesforce, pipedrive, zoho).
Route::middleware(['auth', 'active.client'])->prefix('oauth/{provider}')->group(function () {
    Route::get('start',    [OAuthController::class, 'start'])
        ->whereIn('provider', ['salesforce', 'pipedrive', 'zoho'])
        ->name('oauth.start');
    Route::get('callback', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['salesforce', 'pipedrive', 'zoho'])
        ->name('oauth.callback');
});
