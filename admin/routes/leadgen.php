<?php

use App\Http\Controllers\LeadGen;
use App\Support\Hashid;
use Illuminate\Support\Facades\Route;

/*
| Lead Generation & Outreach (module `leadgen`).
|
| Included from routes/web.php inside the workspace group, so every route here
| already runs behind: active.client → workspace.provisioned → module.enabled
| → subscribed → plan.feature → module.access. Route names start with
| `leadgen.` — that prefix is what maps them to the module.
|
| Actions inside the module add `module.ability:leadgen,<ability>`
| (config/modules.php → leadgen.abilities).
*/

Route::middleware('module.ability:leadgen,view')->group(function () {
    Route::get('/lead-generation', [LeadGen\DashboardController::class, 'index'])->name('leadgen.index');

    Route::get('/lead-generation/searches',               [LeadGen\SearchController::class, 'index'])->name('leadgen.searches.index');
    Route::get('/lead-generation/searches/{id}',          [LeadGen\SearchController::class, 'show'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.show');
    Route::get('/lead-generation/searches/{id}/progress', [LeadGen\SearchController::class, 'progress'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.progress');
});

Route::middleware('module.ability:leadgen,search')->group(function () {
    Route::get ('/lead-generation/searches/new',            [LeadGen\SearchController::class, 'create'])->name('leadgen.searches.create');
    Route::post('/lead-generation/searches',                [LeadGen\SearchController::class, 'store'])->middleware('throttle:10,1')->name('leadgen.searches.store');
    // The area picker talks to the places worker; throttled because each call can start a remote scan.
    Route::get ('/lead-generation/places/geocode',          [LeadGen\SearchController::class, 'geocode'])->middleware('throttle:60,1')->name('leadgen.places.geocode');
    Route::post('/lead-generation/places/categories',       [LeadGen\SearchController::class, 'categories'])->middleware('throttle:30,1')->name('leadgen.places.categories');
    Route::post('/lead-generation/places/estimate',         [LeadGen\SearchController::class, 'estimate'])->middleware('throttle:30,1')->name('leadgen.places.estimate');
    Route::post('/lead-generation/searches/{id}/pause',     [LeadGen\SearchController::class, 'pause'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.pause');
    Route::post('/lead-generation/searches/{id}/resume',    [LeadGen\SearchController::class, 'resume'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.resume');
    Route::post('/lead-generation/searches/{id}/cancel',    [LeadGen\SearchController::class, 'cancel'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.cancel');
    Route::post('/lead-generation/searches/{id}/save',      [LeadGen\SearchController::class, 'toggleSave'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.searches.save');
});

Route::middleware('module.ability:leadgen,import')->group(function () {
    Route::get ('/lead-generation/import',               [LeadGen\ImportController::class, 'create'])->name('leadgen.imports.create');
    Route::post('/lead-generation/import',               [LeadGen\ImportController::class, 'upload'])->middleware('throttle:10,1')->name('leadgen.imports.upload');
    Route::get ('/lead-generation/import/{token}',       [LeadGen\ImportController::class, 'map'])->where('token', '[A-Za-z0-9]{32}')->name('leadgen.imports.map');
    Route::post('/lead-generation/import/{token}',       [LeadGen\ImportController::class, 'start'])->where('token', '[A-Za-z0-9]{32}')->name('leadgen.imports.start');
});
