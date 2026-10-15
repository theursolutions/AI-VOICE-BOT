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

    Route::get ('/lead-generation/prospects',            [LeadGen\ProspectController::class, 'index'])->name('leadgen.prospects.index');
    Route::get ('/lead-generation/prospects/{id}',       [LeadGen\ProspectController::class, 'show'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.prospects.show');
    // Bulk actions check their own ability (enrich = search, the rest = edit).
    Route::post('/lead-generation/prospects/bulk',       [LeadGen\ProspectController::class, 'bulk'])->middleware('throttle:30,1')->name('leadgen.prospects.bulk');
    Route::get ('/lead-generation/lists',                [LeadGen\ListController::class, 'index'])->name('leadgen.lists.index');
    Route::get ('/lead-generation/review',               [LeadGen\QualificationController::class, 'index'])->name('leadgen.review.index');
    Route::get ('/lead-generation/drafts/{id}',          [LeadGen\DraftController::class, 'edit'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.drafts.edit');
});

// Offer profiles and templates shape every score and message — `settings`.
Route::middleware('module.ability:leadgen,settings')->group(function () {
    Route::get   ('/lead-generation/profiles',           [LeadGen\ProfileController::class, 'index'])->name('leadgen.profiles.index');
    Route::get   ('/lead-generation/profiles/new',       [LeadGen\ProfileController::class, 'create'])->name('leadgen.profiles.create');
    Route::post  ('/lead-generation/profiles',           [LeadGen\ProfileController::class, 'store'])->name('leadgen.profiles.store');
    Route::get   ('/lead-generation/profiles/{id}',      [LeadGen\ProfileController::class, 'edit'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.profiles.edit');
    Route::patch ('/lead-generation/profiles/{id}',      [LeadGen\ProfileController::class, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.profiles.update');
    Route::delete('/lead-generation/profiles/{id}',      [LeadGen\ProfileController::class, 'destroy'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.profiles.destroy');
    Route::get   ('/lead-generation/templates',          [LeadGen\TemplateController::class, 'index'])->name('leadgen.templates.index');
    Route::get   ('/lead-generation/templates/new',      [LeadGen\TemplateController::class, 'create'])->name('leadgen.templates.create');
    Route::post  ('/lead-generation/templates',          [LeadGen\TemplateController::class, 'store'])->name('leadgen.templates.store');
    Route::post  ('/lead-generation/templates/preview',  [LeadGen\TemplateController::class, 'preview'])->name('leadgen.templates.preview');
    Route::get   ('/lead-generation/templates/{id}',     [LeadGen\TemplateController::class, 'edit'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.templates.edit');
    Route::patch ('/lead-generation/templates/{id}',     [LeadGen\TemplateController::class, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.templates.update');
    Route::delete('/lead-generation/templates/{id}',     [LeadGen\TemplateController::class, 'destroy'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.templates.destroy');
});

Route::middleware('module.ability:leadgen,edit')->group(function () {
    Route::patch ('/lead-generation/prospects/{id}',     [LeadGen\ProspectController::class, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.prospects.update');
    Route::post  ('/lead-generation/prospects/{id}/crm', [LeadGen\ProspectController::class, 'addToCrm'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.prospects.crm');
    Route::post  ('/lead-generation/prospects/{id}/drafts', [LeadGen\DraftController::class, 'store'])->where('id', Hashid::ROUTE_PATTERN)->middleware('throttle:20,1')->name('leadgen.drafts.store');
    Route::patch ('/lead-generation/drafts/{id}',        [LeadGen\DraftController::class, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.drafts.update');
    Route::delete('/lead-generation/drafts/{id}',        [LeadGen\DraftController::class, 'destroy'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.drafts.destroy');
    Route::post  ('/lead-generation/review/{id}',        [LeadGen\QualificationController::class, 'override'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.review.override');
    Route::post  ('/lead-generation/lists',              [LeadGen\ListController::class, 'store'])->name('leadgen.lists.store');
    Route::patch ('/lead-generation/lists/{id}',         [LeadGen\ListController::class, 'update'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.lists.update');
    Route::delete('/lead-generation/lists/{id}',         [LeadGen\ListController::class, 'destroy'])->where('id', Hashid::ROUTE_PATTERN)->name('leadgen.lists.destroy');
});

Route::middleware('module.ability:leadgen,export')->group(function () {
    Route::get('/lead-generation/prospects-export', [LeadGen\ProspectController::class, 'export'])->middleware('throttle:10,1')->name('leadgen.prospects.export');
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
    // Scoring uses the AI brain (metered) — same ability as other enrichment.
    Route::post('/lead-generation/prospects/{id}/qualify',  [LeadGen\QualificationController::class, 'qualifyOne'])->where('id', Hashid::ROUTE_PATTERN)->middleware('throttle:30,1')->name('leadgen.qualify.one');
    Route::post('/lead-generation/qualify',                 [LeadGen\QualificationController::class, 'queue'])->middleware('throttle:10,1')->name('leadgen.qualify.queue');
    Route::post('/lead-generation/prospects/{id}/enrich',   [LeadGen\ProspectController::class, 'enrich'])->where('id', Hashid::ROUTE_PATTERN)->middleware('throttle:60,1')->name('leadgen.prospects.enrich');
});

Route::middleware('module.ability:leadgen,import')->group(function () {
    Route::get ('/lead-generation/import',               [LeadGen\ImportController::class, 'create'])->name('leadgen.imports.create');
    Route::post('/lead-generation/import',               [LeadGen\ImportController::class, 'upload'])->middleware('throttle:10,1')->name('leadgen.imports.upload');
    Route::get ('/lead-generation/import/{token}',       [LeadGen\ImportController::class, 'map'])->where('token', '[A-Za-z0-9]{32}')->name('leadgen.imports.map');
    Route::post('/lead-generation/import/{token}',       [LeadGen\ImportController::class, 'start'])->where('token', '[A-Za-z0-9]{32}')->name('leadgen.imports.start');
});
