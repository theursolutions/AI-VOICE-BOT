<?php

use App\Http\Controllers\LeadGen;
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
});
