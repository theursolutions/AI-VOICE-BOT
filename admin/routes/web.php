<?php

use App\Http\Controllers\Admin\DataSourceWebController;
use App\Http\Controllers\BotChatController;
use App\Http\Controllers\ConfigureAgentVoicesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspacePickerController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// ── Public / pre-workspace ───────────────────────────────────────────────
Route::get('/', fn () => view('welcome'))->name('home');
Route::get('/v2', fn () => view('welcome-v2'))->name('home.v2');
Route::get('/voice-bot', fn () => view('voice-chat'));
Route::post('/send-voice', [ConfigureAgentVoicesController::class, 'process'])->name('voice.send');

// Landing "Call me now" capture (rate-limited, no auth).
Route::post('/api/demo-call', [App\Http\Controllers\PublicLandingController::class, 'demoCall'])
    ->name('demo-call');

// ── User-scoped (cross-workspace) ────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get ('/workspace/pick',   [WorkspacePickerController::class, 'show'])->name('workspace.pick');
    Route::post('/workspace/select', [WorkspacePickerController::class, 'select'])->name('workspace.select');
});

require __DIR__.'/auth.php';
require __DIR__.'/oauth.php';
require __DIR__.'/invitations.php';

Auth::routes();

// ── Super-admin Ops Console (internal staff only) ────────────────────────
Route::middleware(['auth', 'super-admin'])
    ->prefix('admin')
    ->name('ops.')
    ->group(function () {
        Route::get('/', [App\Http\Controllers\SuperAdmin\OverviewController::class, 'index'])->name('overview');

        Route::get('/analytics',               [App\Http\Controllers\SuperAdmin\AnalyticsController::class, 'index'])->name('analytics.index');

        Route::get('/clients',                  [App\Http\Controllers\SuperAdmin\ClientsController::class, 'index'])->name('clients.index');
        Route::post('/clients/{id}/suspend',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'suspend'])->whereNumber('id')->name('clients.suspend');
        Route::post('/clients/{id}/restore',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'restore'])->whereNumber('id')->name('clients.restore');
        Route::post('/clients/{id}/delete',     [App\Http\Controllers\SuperAdmin\ClientsController::class, 'destroy'])->whereNumber('id')->name('clients.delete');
        Route::post('/clients/{id}/recover',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'recover'])->whereNumber('id')->name('clients.recover');

        Route::get('/projects',                 [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'index'])->name('projects.index');
        Route::post('/projects/{id}/reprovision', [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'reprovision'])->whereNumber('id')->name('projects.reprovision');
        Route::post('/projects/{id}/disable',   [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'disable'])->whereNumber('id')->name('projects.disable');
        Route::post('/projects/{id}/enable',    [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'enable'])->whereNumber('id')->name('projects.enable');
        Route::post('/projects/{id}/delete',    [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'destroy'])->whereNumber('id')->name('projects.delete');
        Route::post('/projects/{id}/recover',   [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'recover'])->whereNumber('id')->name('projects.recover');

        Route::get('/users',                    [App\Http\Controllers\SuperAdmin\UsersController::class, 'index'])->name('users.index');
        Route::post('/users/{id}/disable',      [App\Http\Controllers\SuperAdmin\UsersController::class, 'disable'])->whereNumber('id')->name('users.disable');
        Route::post('/users/{id}/enable',       [App\Http\Controllers\SuperAdmin\UsersController::class, 'enable'])->whereNumber('id')->name('users.enable');
        Route::post('/users/{id}/delete',       [App\Http\Controllers\SuperAdmin\UsersController::class, 'destroy'])->whereNumber('id')->name('users.delete');
        Route::post('/users/{id}/recover',      [App\Http\Controllers\SuperAdmin\UsersController::class, 'restore'])->whereNumber('id')->name('users.recover');

        // Tenant row soft-delete / restore (sessions, leads, voices, agents, skills).
        Route::post('/tenant/{type}/{projectId}/{id}/delete',  [App\Http\Controllers\SuperAdmin\TenantResourceController::class, 'destroy'])->whereNumber(['projectId','id'])->name('tenant.delete');
        Route::post('/tenant/{type}/{projectId}/{id}/restore', [App\Http\Controllers\SuperAdmin\TenantResourceController::class, 'restore'])->whereNumber(['projectId','id'])->name('tenant.restore');

        // Cross-tenant resource browsers — every session/lead/voice/number
        // across every project, with project + owner columns.
        Route::get('/sessions',                [App\Http\Controllers\SuperAdmin\OpsSessionsController::class, 'index'])->name('sessions.index');
        Route::get('/sessions/{projectId}/{id}', [App\Http\Controllers\SuperAdmin\OpsSessionsController::class, 'show'])->whereNumber(['projectId','id'])->name('sessions.show');
        Route::get('/leads',                   [App\Http\Controllers\SuperAdmin\OpsLeadsController::class, 'index'])->name('leads.index');
        Route::get('/voices',                  [App\Http\Controllers\SuperAdmin\OpsVoicesController::class, 'index'])->name('voices.index');
        Route::get('/telephony',               [App\Http\Controllers\SuperAdmin\OpsTelephonyController::class, 'index'])->name('telephony.index');

        Route::get('/audit',                   [App\Http\Controllers\SuperAdmin\AuditController::class, 'index'])->name('audit.index');

        // Impersonation (starts here; ending lives outside super-admin
        // middleware because by the time the operator hits /admin/exit
        // they're already logged in AS the customer).
        Route::post('/impersonate/{userId}',   [App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'start'])->whereNumber('userId')->name('impersonate.start');
    });

// Exit-impersonation lives outside the super-admin gate because the
// active session is the customer's during impersonation — only the
// `impersonator_id` in session marks them. Auth-only.
Route::middleware('auth')
    ->post('/impersonate/exit', [App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'stop'])
    ->name('ops.impersonate.exit');

// ── Backward-compat: top-level /dashboard → /c/{active-slug}/dashboard ───
Route::middleware(['auth', 'active.client'])->get('/dashboard', function () {
    $user = auth()->user();
    if ($user->activeClient) {
        return redirect()->route('dashboard', ['client' => $user->activeClient->slug]);
    }
    return redirect()->route('workspace.pick');
});

// ── Workspace-scoped routes under /c/{client:slug}/ ──────────────────────
Route::middleware(['auth', 'active.client'])
    ->prefix('c/{client:slug}')
    ->scopeBindings()
    ->group(function () {

        // ── Setup wizard (lives outside workspace.provisioned, otherwise
        //    we'd infinitely redirect to ourselves). Fresh signups go
        //    here first; everything else stays blocked until a Project
        //    is created + its tenant DB provisioned.
        Route::get ('/setup', [App\Http\Controllers\Admin\SetupController::class, 'show'])->name('setup');
        Route::post('/setup', [App\Http\Controllers\Admin\SetupController::class, 'store'])->name('setup.store');

        // ── Everything below requires a provisioned workspace ────────
        Route::middleware('workspace.provisioned')->group(function () {

        Route::get('/dashboard', [App\Http\Controllers\HomeController::class, 'index'])
            ->name('dashboard');

        Route::get ('/agent-voices', [ConfigureAgentVoicesController::class, 'index'])
            ->name('agent-voices.index');
        Route::post('/agent-voices', [ConfigureAgentVoicesController::class, 'store'])
            ->name('agent-voices.store');

        Route::get('/chat-bot/{id}', [BotChatController::class, 'index'])
            ->whereNumber('id')
            ->name('chat-bot');
        Route::post('/chat-query-ajax', [BotChatController::class, 'handleQuery'])
            ->name('chat-bot.hat-query-ajax');

        // Data sources
        Route::get   ('/data-sources',                     [DataSourceWebController::class, 'index'])->name('data-sources.index');
        Route::get   ('/data-sources/create',              [DataSourceWebController::class, 'create'])->name('data-sources.create');
        Route::post  ('/data-sources/website',             [DataSourceWebController::class, 'storeWebsite'])->name('data-sources.store.website');
        Route::post  ('/data-sources/documents',           [DataSourceWebController::class, 'storeDocuments'])->name('data-sources.store.documents');
        Route::post  ('/data-sources/database',            [DataSourceWebController::class, 'storeDatabase'])->name('data-sources.store.database');
        Route::post  ('/data-sources/webhook',             [DataSourceWebController::class, 'storeWebhook'])->name('data-sources.store.webhook');
        Route::get   ('/data-sources/{id}',                [DataSourceWebController::class, 'show'])->whereNumber('id')->name('data-sources.show');
        Route::post  ('/data-sources/{id}/resync',         [DataSourceWebController::class, 'resync'])->whereNumber('id')->name('data-sources.resync');
        Route::post  ('/data-sources/{id}/test-webhook',   [DataSourceWebController::class, 'testWebhook'])->whereNumber('id')->name('data-sources.test-webhook');
        Route::post  ('/data-sources/{id}/test-query',     [DataSourceWebController::class, 'testQuery'])->whereNumber('id')->name('data-sources.test-query');
        Route::post  ('/data-sources/{id}/disable',        [DataSourceWebController::class, 'destroy'])->whereNumber('id')->name('data-sources.destroy');

        // Widget customization (colors, greeting, position, embed snippet)
        Route::get   ('/widget-settings',                  [App\Http\Controllers\Admin\WidgetSettingsController::class, 'index'])->name('widget-settings.index');
        Route::patch ('/widget-settings',                  [App\Http\Controllers\Admin\WidgetSettingsController::class, 'update'])->name('widget-settings.update');

        // Bot knowledge strategy (per-tier toggles)
        Route::get   ('/bot-strategy',                     [App\Http\Controllers\Admin\BotStrategyController::class, 'index'])->name('bot-strategy.index');
        Route::patch ('/bot-strategy',                     [App\Http\Controllers\Admin\BotStrategyController::class, 'update'])->name('bot-strategy.update');

        // Telephony (per-project Twilio numbers — multiple numbers per project)
        Route::get   ('/telephony',                        [App\Http\Controllers\Admin\TelephonyWebController::class, 'index'])->name('telephony.index');
        Route::post  ('/telephony/numbers',                [App\Http\Controllers\Admin\TelephonyWebController::class, 'saveNumber'])->name('telephony.save-number');
        Route::post  ('/telephony/numbers/delete',         [App\Http\Controllers\Admin\TelephonyWebController::class, 'deleteNumber'])->name('telephony.delete-number');

        // Brain & compute settings (LLM provider + CPU/GPU)
        Route::get   ('/brain-settings',                   [App\Http\Controllers\Admin\BrainSettingsController::class, 'index'])->name('brain-settings.index');
        Route::patch ('/brain-settings',                   [App\Http\Controllers\Admin\BrainSettingsController::class, 'update'])->name('brain-settings.update');
        Route::post  ('/brain-settings/reload',            [App\Http\Controllers\Admin\BrainSettingsController::class, 'reload'])->name('brain-settings.reload');
        Route::post  ('/brain-settings/toggle-brain',      [App\Http\Controllers\Admin\BrainSettingsController::class, 'toggleBrain'])->name('brain-settings.toggle-brain');
        Route::post  ('/brain-settings/toggle-device',     [App\Http\Controllers\Admin\BrainSettingsController::class, 'toggleDevice'])->name('brain-settings.toggle-device');

        // Skills (call-center routing categories)
        Route::get   ('/skills',                           [App\Http\Controllers\Admin\SkillWebController::class, 'index'])->name('skills.index');
        Route::post  ('/skills',                           [App\Http\Controllers\Admin\SkillWebController::class, 'store'])->name('skills.store');
        Route::patch ('/skills/{id}',                      [App\Http\Controllers\Admin\SkillWebController::class, 'update'])->whereNumber('id')->name('skills.update');
        Route::delete('/skills/{id}',                      [App\Http\Controllers\Admin\SkillWebController::class, 'destroy'])->whereNumber('id')->name('skills.destroy');

        // Project profile (business identity — logo, name, website, etc.)
        Route::get   ('/project-profile',                  [App\Http\Controllers\Admin\ProjectProfileController::class, 'index'])->name('project-profile.index');
        Route::post  ('/project-profile',                  [App\Http\Controllers\Admin\ProjectProfileController::class, 'update'])->name('project-profile.update');

        // Bot agents (AI personas with voice + persona + skills)
        Route::get   ('/bot-agents',                       [App\Http\Controllers\Admin\BotAgentWebController::class, 'index'])->name('bot-agents.index');
        Route::post  ('/bot-agents',                       [App\Http\Controllers\Admin\BotAgentWebController::class, 'store'])->name('bot-agents.store');
        Route::patch ('/bot-agents/{id}',                  [App\Http\Controllers\Admin\BotAgentWebController::class, 'update'])->whereNumber('id')->name('bot-agents.update');
        Route::delete('/bot-agents/{id}',                  [App\Http\Controllers\Admin\BotAgentWebController::class, 'destroy'])->whereNumber('id')->name('bot-agents.destroy');

        // Voices (per-project speaker references + language)
        Route::get   ('/voices',                           [App\Http\Controllers\Admin\VoiceWebController::class, 'index'])->name('voices.index');
        Route::post  ('/voices',                           [App\Http\Controllers\Admin\VoiceWebController::class, 'store'])->name('voices.store');
        Route::post  ('/voices/{id}/default',              [App\Http\Controllers\Admin\VoiceWebController::class, 'setDefault'])->whereNumber('id')->name('voices.default');
        Route::get   ('/voices/{id}/audio',                [App\Http\Controllers\Admin\VoiceWebController::class, 'audio'])->whereNumber('id')->name('voices.audio');
        Route::delete('/voices/{id}',                      [App\Http\Controllers\Admin\VoiceWebController::class, 'destroy'])->whereNumber('id')->name('voices.destroy');

        // Conversations (sessions + messages)
        Route::get   ('/sessions',                         [App\Http\Controllers\Admin\SessionWebController::class, 'index'])->name('sessions.index');
        Route::get   ('/sessions/{id}',                    [App\Http\Controllers\Admin\SessionWebController::class, 'show'])->whereNumber('id')->name('sessions.show');

        // Leads (extracted from conversations)
        Route::get   ('/leads',                            [App\Http\Controllers\Admin\LeadWebController::class, 'index'])->name('leads.index');
        Route::get   ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'show'])->whereNumber('id')->name('leads.show');
        Route::patch ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'update'])->whereNumber('id')->name('leads.update');

        }); // end workspace.provisioned group
    });
