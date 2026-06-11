<?php

use App\Http\Controllers\Api\AgentApiController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\DataSourceController;
use App\Http\Controllers\Api\InternalTurnController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\TurnController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| Public, project-scoped API consumed by widgets / channels.
| Authenticates with X-CLIENT-API-KEY (project_api_key column).
*/
Route::middleware('project.apikey')->prefix('v1')->group(function () {
    // Public widget config — the embedded widget calls this on boot
    // with its project API key to fetch branding (colors, greeting,
    // position, etc.) configured in the admin Widget Settings page.
    Route::get('widget/config', function (Request $request) {
        $project = $request->attributes->get('project');
        $config = array_merge(
            \App\Http\Controllers\Admin\WidgetSettingsController::DEFAULTS,
            (array) data_get($project->json_data, 'widget', [])
        );
        return response()->json([
            'project_id' => $project->id,
            'config'     => $config,
        ]);
    });

    Route::post('sessions',                 [SessionController::class, 'start']);
    Route::get ('sessions/{id}',            [SessionController::class, 'show']);
    Route::post('sessions/{id}/end',        [SessionController::class, 'end']);
    Route::post('sessions/{id}/turn',       [TurnController::class,    'send']);

    // Webchat Flow runtime — Phase 2.
    // /flow/start is reserved for re-bootstrapping a flow mid-session
    // (rarely needed; SessionController::start already kicks off the
    // first run). /flow/step is the main event: widget POSTs a menu
    // choice or free-text reply, runner advances + returns next batch.
    Route::post('sessions/{id}/flow/step',  [\App\Http\Controllers\Api\WidgetFlowController::class, 'step']);
    Route::post('sessions/{id}/flow/restart', [\App\Http\Controllers\Api\WidgetFlowController::class, 'restart']);

    Route::get   ('data-sources',                       [DataSourceController::class, 'index']);
    Route::post  ('data-sources',                       [DataSourceController::class, 'store']);
    Route::post  ('data-sources/upload-documents',      [DataSourceController::class, 'uploadDocuments']);
    Route::get   ('data-sources/{id}',                  [DataSourceController::class, 'show']);
    Route::get   ('data-sources/{id}/status',           [DataSourceController::class, 'status']);
    Route::post  ('data-sources/{id}/resync',           [DataSourceController::class, 'resync']);
    Route::delete('data-sources/{id}',                  [DataSourceController::class, 'destroy']);

    // Agent admin (Tier 3b): create / list / regenerate / revoke
    Route::get   ('agents',                       [AgentController::class, 'index']);
    Route::post  ('agents',                       [AgentController::class, 'store']);
    Route::post  ('agents/{id}/regenerate-token', [AgentController::class, 'regenerate']);
    Route::post  ('agents/{id}/revoke',           [AgentController::class, 'revoke']);
});

/*
| Endpoints called BY the customer-hosted query-agent itself.
| Throttled separately from the project-scoped API. Auth is per-route:
|   /enroll uses the one-time enrollment_token in the body
|   /poll and /result use Bearer <long-lived token>
*/
Route::prefix('v1/agent')->group(function () {
    Route::post('enroll', [AgentApiController::class, 'enroll']);
    Route::get ('poll',   [AgentApiController::class, 'poll']);
    Route::post('result', [AgentApiController::class, 'result']);
});

/*
| Internal webhook from the Python worker after streaming completes.
| Secured by a shared secret in X-Internal-Secret header.
*/
// Twilio voice webhooks. Twilio signs requests with HMAC-SHA1; we
// verify the signature in TelephonyController itself rather than via
// middleware so failures still return TwiML the caller can hear.
Route::middleware('twilio.signature')->group(function () {
    Route::post('telephony/twilio/voice',        [\App\Http\Controllers\Api\TelephonyController::class, 'voiceWebhook']);
    Route::post('telephony/twilio/status',       [\App\Http\Controllers\Api\TelephonyController::class, 'statusWebhook']);
    // Flow Builder runtime callbacks. flow-step is the Gather/redirect
    // callback walking through IVR nodes; flow-handoff is hit when the
    // flow reaches a transfer_ai node and we need to open the WS stream.
    Route::post('telephony/twilio/flow-step',    [\App\Http\Controllers\Api\TelephonyController::class, 'flowStep']);
    Route::post('telephony/twilio/flow-handoff', [\App\Http\Controllers\Api\TelephonyController::class, 'flowHandoff']);
});
Route::get ('telephony/twilio/diagnose',   [\App\Http\Controllers\Api\TelephonyController::class, 'diagnose'])->name('telephony.diagnose');

Route::middleware('internal.webhook')->prefix('internal')->group(function () {
    Route::post('turn-completed',   [InternalTurnController::class, 'turnCompleted']);
    Route::post('resolve-context',  [InternalTurnController::class, 'resolveContext']);
    Route::post('session-ended',    [InternalTurnController::class, 'sessionEnded']);
});
