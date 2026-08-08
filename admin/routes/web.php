<?php

use App\Http\Controllers\Admin\DataSourceWebController;
use App\Http\Controllers\BotChatController;
use App\Http\Controllers\ConfigureAgentVoicesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspacePickerController;
use Illuminate\Support\Facades\Route;

// ── Public / pre-workspace ───────────────────────────────────────────────
Route::get('/', fn () => view('welcome'))->name('home');
Route::get('/v2', fn () => view('welcome-v2'))->name('home.v2');
Route::get('/voice-bot', fn () => view('voice-chat'));
Route::post('/send-voice', [ConfigureAgentVoicesController::class, 'process'])->name('voice.send');

// Landing "Call me now" capture (rate-limited, no auth).
Route::post('/api/demo-call', [App\Http\Controllers\PublicLandingController::class, 'demoCall'])
    ->name('demo-call');

// Contact-page form → contact_leads (rate-limited + honeypot, no auth).
Route::post('/api/contact', [App\Http\Controllers\PublicContactController::class, 'store'])
    ->name('contact.store');

// ── Public marketing + legal pages (footer links) ───────────────────────
Route::view('/about',          'pages.about')->name('about');
Route::view('/contact',        'pages.contact')->name('contact');
Route::view('/privacy',        'pages.privacy')->name('privacy');
Route::view('/terms',          'pages.terms')->name('terms');
Route::view('/refund-policy',  'pages.refund')->name('refund-policy');
Route::view('/cookies',        'pages.cookies')->name('cookies');
Route::view('/security',       'pages.security')->name('security.page');

// ── User-scoped (cross-workspace) ────────────────────────────────────────
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get ('/workspace/pick',   [WorkspacePickerController::class, 'show'])->name('workspace.pick');
    Route::post('/workspace/select', [WorkspacePickerController::class, 'select'])->name('workspace.select');
});

// Breeze auth (login / register / password reset / verification). This is the
// ONLY auth scaffolding — the laravel/ui `Auth::routes()` call that used to sit
// below it was removed: it re-declared password.request, password.email,
// password.reset, password.update and password.confirm, which made
// `php artisan route:cache` throw and left the app running on uncached routes.
require __DIR__.'/auth.php';
require __DIR__.'/oauth.php';
require __DIR__.'/invitations.php';

// ── Super-admin Ops Console (internal staff only) ────────────────────────
Route::middleware(['auth', 'super-admin'])
    ->prefix('admin')
    ->name('ops.')
    ->group(function () {
        Route::get('/', [App\Http\Controllers\SuperAdmin\OverviewController::class, 'index'])->name('overview');

        Route::get('/analytics',               [App\Http\Controllers\SuperAdmin\AnalyticsController::class, 'index'])->name('analytics.index');

        // Platform module switchboard — turn admin modules on/off for everyone.
        Route::get ('/modules', [App\Http\Controllers\SuperAdmin\ModulesController::class, 'index'])->name('modules.index');
        Route::post('/modules', [App\Http\Controllers\SuperAdmin\ModulesController::class, 'update'])->name('modules.update');

        Route::get('/clients',                  [App\Http\Controllers\SuperAdmin\ClientsController::class, 'index'])->name('clients.index');
        Route::post('/clients/{id}/suspend',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'suspend'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('clients.suspend');
        Route::post('/clients/{id}/restore',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'restore'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('clients.restore');
        Route::post('/clients/{id}/delete',     [App\Http\Controllers\SuperAdmin\ClientsController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('clients.delete');
        Route::post('/clients/{id}/recover',    [App\Http\Controllers\SuperAdmin\ClientsController::class, 'recover'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('clients.recover');

        Route::get('/projects',                 [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'index'])->name('projects.index');
        Route::post('/projects/{id}/reprovision', [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'reprovision'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('projects.reprovision');
        Route::post('/projects/{id}/disable',   [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'disable'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('projects.disable');
        Route::post('/projects/{id}/enable',    [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'enable'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('projects.enable');
        Route::post('/projects/{id}/delete',    [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('projects.delete');
        Route::post('/projects/{id}/recover',   [App\Http\Controllers\SuperAdmin\ProjectsController::class, 'recover'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('projects.recover');

        Route::get('/users',                    [App\Http\Controllers\SuperAdmin\UsersController::class, 'index'])->name('users.index');
        Route::post('/users/{id}/disable',      [App\Http\Controllers\SuperAdmin\UsersController::class, 'disable'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('users.disable');
        Route::post('/users/{id}/enable',       [App\Http\Controllers\SuperAdmin\UsersController::class, 'enable'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('users.enable');
        Route::post('/users/{id}/delete',       [App\Http\Controllers\SuperAdmin\UsersController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('users.delete');
        Route::post('/users/{id}/recover',      [App\Http\Controllers\SuperAdmin\UsersController::class, 'restore'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('users.recover');

        // Tenant row soft-delete / restore (sessions, leads, voices, agents, skills).
        Route::post('/tenant/{type}/{projectId}/{id}/delete',  [App\Http\Controllers\SuperAdmin\TenantResourceController::class, 'destroy'])->where(['projectId' => \App\Support\Hashid::ROUTE_PATTERN, 'id' => \App\Support\Hashid::ROUTE_PATTERN])->name('tenant.delete');
        Route::post('/tenant/{type}/{projectId}/{id}/restore', [App\Http\Controllers\SuperAdmin\TenantResourceController::class, 'restore'])->where(['projectId' => \App\Support\Hashid::ROUTE_PATTERN, 'id' => \App\Support\Hashid::ROUTE_PATTERN])->name('tenant.restore');

        // Cross-tenant resource browsers — every session/lead/voice/number
        // across every project, with project + owner columns.
        Route::get('/sessions',                [App\Http\Controllers\SuperAdmin\OpsSessionsController::class, 'index'])->name('sessions.index');
        Route::get('/sessions/{projectId}/{id}', [App\Http\Controllers\SuperAdmin\OpsSessionsController::class, 'show'])->where(['projectId' => \App\Support\Hashid::ROUTE_PATTERN, 'id' => \App\Support\Hashid::ROUTE_PATTERN])->name('sessions.show');
        Route::get('/leads',                   [App\Http\Controllers\SuperAdmin\OpsLeadsController::class, 'index'])->name('leads.index');
        Route::get('/voices',                  [App\Http\Controllers\SuperAdmin\OpsVoicesController::class, 'index'])->name('voices.index');
        Route::get('/telephony',               [App\Http\Controllers\SuperAdmin\OpsTelephonyController::class, 'index'])->name('telephony.index');

        Route::get('/audit',                   [App\Http\Controllers\SuperAdmin\AuditController::class, 'index'])->name('audit.index');

        // Website contacts — "Call me now" / contact captures from the marketing site.
        Route::get ('/contacts',               [App\Http\Controllers\SuperAdmin\ContactsController::class, 'index'])->name('contacts.index');
        Route::post('/contacts/{id}/status',   [App\Http\Controllers\SuperAdmin\ContactsController::class, 'updateStatus'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('contacts.status');

        // ── Marketing site: SEO + page content (drives the public website) ──
        Route::get ('/seo',              [App\Http\Controllers\SuperAdmin\SeoController::class, 'index'])->name('seo.index');
        Route::post('/seo',              [App\Http\Controllers\SuperAdmin\SeoController::class, 'update'])->name('seo.update');
        Route::post('/seo/ping',         [App\Http\Controllers\SuperAdmin\SeoController::class, 'ping'])->name('seo.ping');

        Route::get ('/content',          [App\Http\Controllers\SuperAdmin\SiteContentController::class, 'index'])->name('content.index');
        Route::post('/content',          [App\Http\Controllers\SuperAdmin\SiteContentController::class, 'update'])->name('content.update');
        Route::post('/content/reset',    [App\Http\Controllers\SuperAdmin\SiteContentController::class, 'reset'])->name('content.reset');

        // Impersonation (starts here; ending lives outside super-admin
        // middleware because by the time the operator hits /admin/exit
        // they're already logged in AS the customer).
        Route::post('/impersonate/{userId}',   [App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'start'])->where('userId', \App\Support\Hashid::ROUTE_PATTERN)->name('impersonate.start');
    });

// Exit-impersonation lives outside the super-admin gate because the
// active session is the customer's during impersonation — only the
// `impersonator_id` in session marks them. Auth-only.
Route::middleware('auth')
    ->post('/impersonate/exit', [App\Http\Controllers\SuperAdmin\ImpersonationController::class, 'stop'])
    ->name('ops.impersonate.exit');

// Meta OAuth callback — fixed redirect URI (no {client} prefix); client +
// project + provider travel in the encrypted `state`. Register this exact
// URL in the Meta app's "Valid OAuth Redirect URIs".
Route::middleware('auth')
    ->get('/meta/oauth/callback', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'callback'])
    ->name('meta.oauth.callback');

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

        // ── Everything below requires a provisioned workspace, then passes
        //    two module gates: `module.enabled` (platform-wide super-admin
        //    switch — off → "under development" page for everyone) runs
        //    first, then `module.access` (per-role RBAC; owners bypass).
        //    See config/modules.php + App\Support\Modules.
        Route::middleware(['workspace.provisioned', 'module.enabled', 'module.access', 'email.verified.gate'])->group(function () {

        Route::get('/dashboard', [App\Http\Controllers\HomeController::class, 'index'])
            ->name('dashboard');

        Route::get ('/agent-voices', [ConfigureAgentVoicesController::class, 'index'])
            ->name('agent-voices.index');
        Route::post('/agent-voices', [ConfigureAgentVoicesController::class, 'store'])
            ->name('agent-voices.store');

        Route::get('/chat-bot/{id}', [BotChatController::class, 'index'])
            ->where('id', \App\Support\Hashid::ROUTE_PATTERN)
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
        Route::get   ('/data-sources/{id}',                [DataSourceWebController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.show');
        Route::post  ('/data-sources/{id}/resync',         [DataSourceWebController::class, 'resync'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.resync');
        // Owner control: toggle whether customers (web chat + voice) may use this source
        Route::post  ('/data-sources/{id}/visibility',     [DataSourceWebController::class, 'setVisibility'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.visibility');
        Route::post  ('/data-sources/{id}/test-webhook',   [DataSourceWebController::class, 'testWebhook'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.test-webhook');
        Route::post  ('/data-sources/{id}/test-query',     [DataSourceWebController::class, 'testQuery'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.test-query');
        Route::post  ('/data-sources/{id}/disable',        [DataSourceWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.destroy');
        // Per-table + per-column AI access control (privacy gate)
        Route::get   ('/data-sources/{id}/access',         [DataSourceWebController::class, 'access'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.access');
        Route::post  ('/data-sources/{id}/access',         [DataSourceWebController::class, 'updateAccess'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('data-sources.update-access');

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
        Route::patch ('/skills/{id}',                      [App\Http\Controllers\Admin\SkillWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('skills.update');
        Route::delete('/skills/{id}',                      [App\Http\Controllers\Admin\SkillWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('skills.destroy');
        // Add an action to a skill from the prebuilt tool library (config/tool_templates.php)
        Route::post  ('/skills/{id}/actions/from-template', [App\Http\Controllers\Admin\SkillWebController::class, 'addActionFromTemplate'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('skills.actions.from-template');

        // Omnichannel chat console (Meta: WhatsApp / Instagram / Facebook)
        Route::get ('/chat',                            [App\Http\Controllers\Admin\ChatController::class, 'index'])->name('chat.index');
        Route::get ('/chat/conversations',              [App\Http\Controllers\Admin\ChatController::class, 'conversations'])->name('chat.conversations');
        Route::get ('/chat/{sessionId}/messages',       [App\Http\Controllers\Admin\ChatController::class, 'messages'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.messages');
        Route::post('/chat/{sessionId}/reply',          [App\Http\Controllers\Admin\ChatController::class, 'reply'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.reply');
        Route::post('/chat/{sessionId}/edit',            [App\Http\Controllers\Admin\ChatController::class, 'editMessage'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.edit');
        Route::post('/chat/{sessionId}/media',          [App\Http\Controllers\Admin\ChatController::class, 'sendMedia'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.media-send');
        Route::get ('/chat/{sessionId}/templates',      [App\Http\Controllers\Admin\ChatController::class, 'templates'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.templates');
        Route::post('/chat/{sessionId}/template',        [App\Http\Controllers\Admin\ChatController::class, 'sendTemplate'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.template');
        Route::post('/chat/{sessionId}/interactive',     [App\Http\Controllers\Admin\ChatController::class, 'sendInteractive'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.interactive');
        Route::post('/chat/{sessionId}/flow',            [App\Http\Controllers\Admin\ChatController::class, 'sendFlow'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.flow');
        Route::post('/chat/{sessionId}/product',         [App\Http\Controllers\Admin\ChatController::class, 'sendProduct'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.product');
        Route::post('/chat/{sessionId}/toggle-bot',     [App\Http\Controllers\Admin\ChatController::class, 'toggleBot'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.toggle-bot');
        Route::post('/chat/presence',                   [App\Http\Controllers\Admin\ChatController::class, 'presence'])->name('chat.presence');
        Route::post('/chat/{sessionId}/claim',          [App\Http\Controllers\Admin\ChatController::class, 'claim'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.claim');
        Route::post('/chat/{sessionId}/resolve',        [App\Http\Controllers\Admin\ChatController::class, 'resolve'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.resolve');
        Route::get ('/chat/{sessionId}/media/{messageId}/{index}', [App\Http\Controllers\Admin\ChatController::class, 'media'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->where('messageId', \App\Support\Hashid::ROUTE_PATTERN)->whereNumber('index')->name('chat.media');

        // Compute mesh — live scaling visualization
        Route::get('/compute',         [App\Http\Controllers\Admin\ComputeController::class, 'index'])->name('compute.index');
        Route::get('/compute/metrics', [App\Http\Controllers\Admin\ComputeController::class, 'metrics'])->name('compute.metrics');

        // Channels (Meta: WhatsApp / Instagram / Facebook onboarding)
        Route::get   ('/channels',             [App\Http\Controllers\Admin\ChannelWebController::class, 'index'])->name('channels.index');
        // Facebook Login onboarding — start (button) redirects to Meta.
        Route::get   ('/channels/connect/{provider}', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'start'])->name('channels.connect');
        Route::post  ('/channels',             [App\Http\Controllers\Admin\ChannelWebController::class, 'store'])->name('channels.store');
        Route::post  ('/channels/{id}/toggle', [App\Http\Controllers\Admin\ChannelWebController::class, 'toggle'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('channels.toggle');
        Route::delete('/channels/{id}',        [App\Http\Controllers\Admin\ChannelWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('channels.destroy');

        // Conversation Flow builder (per-project)
        Route::get   ('/flows',                            [App\Http\Controllers\Admin\FlowWebController::class, 'index'])->name('flows.index');
        Route::post  ('/flows',                            [App\Http\Controllers\Admin\FlowWebController::class, 'store'])->name('flows.store');
        Route::get   ('/flows/{id}/editor',                [App\Http\Controllers\Admin\FlowWebController::class, 'editor'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.editor');
        Route::get   ('/flows/{id}/definition',            [App\Http\Controllers\Admin\FlowWebController::class, 'definition'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.definition');
        Route::put   ('/flows/{id}/definition',            [App\Http\Controllers\Admin\FlowWebController::class, 'saveDefinition'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.save-definition');
        Route::patch ('/flows/{id}',                       [App\Http\Controllers\Admin\FlowWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.update');
        Route::delete('/flows/{id}',                       [App\Http\Controllers\Admin\FlowWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.destroy');
        // Editor live-test runner — sandboxed sessions tagged channel='test'
        Route::post  ('/flows/{id}/test/start',            [App\Http\Controllers\Admin\FlowWebController::class, 'testStart'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.test-start');
        Route::post  ('/flows/{id}/test/step',             [App\Http\Controllers\Admin\FlowWebController::class, 'testStep'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('flows.test-step');

        // Project profile (business identity — logo, name, website, etc.)
        Route::get   ('/project-profile',                  [App\Http\Controllers\Admin\ProjectProfileController::class, 'index'])->name('project-profile.index');
        Route::post  ('/project-profile',                  [App\Http\Controllers\Admin\ProjectProfileController::class, 'update'])->name('project-profile.update');

        // Team — RBAC: custom roles + member access (owner-only; module 'team').
        Route::get   ('/roles',            [App\Http\Controllers\Admin\RoleWebController::class, 'index'])->name('roles.index');
        Route::post  ('/roles',            [App\Http\Controllers\Admin\RoleWebController::class, 'store'])->name('roles.store');
        Route::patch ('/roles/{id}',       [App\Http\Controllers\Admin\RoleWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('roles.update');
        Route::delete('/roles/{id}',       [App\Http\Controllers\Admin\RoleWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('roles.destroy');
        Route::get   ('/members',          [App\Http\Controllers\Admin\MemberWebController::class, 'index'])->name('members.index');
        Route::post  ('/members',          [App\Http\Controllers\Admin\MemberWebController::class, 'store'])->name('members.store');
        Route::post  ('/members/import',   [App\Http\Controllers\Admin\MemberWebController::class, 'import'])->name('members.import');
        Route::get   ('/members/template', [App\Http\Controllers\Admin\MemberWebController::class, 'template'])->name('members.template');
        Route::patch ('/members/{userId}', [App\Http\Controllers\Admin\MemberWebController::class, 'update'])->where('userId', \App\Support\Hashid::ROUTE_PATTERN)->name('members.update');

        // Phase 2 — internal Team Assistant (in-admin AI, RBAC project-scoped).
        Route::get ('/assistant',          [App\Http\Controllers\Admin\AssistantController::class, 'index'])->name('assistant.index');
        Route::post('/assistant/ask',      [App\Http\Controllers\Admin\AssistantController::class, 'ask'])->name('assistant.ask');
        Route::post('/assistant/navigate', [App\Http\Controllers\Admin\AssistantController::class, 'navigate'])->name('assistant.navigate');
        Route::post('/assistant/voice-session', [App\Http\Controllers\Admin\AssistantController::class, 'voiceSession'])->name('assistant.voice-session');

        // Bot agents (AI personas with voice + persona + skills)
        Route::get   ('/bot-agents',                       [App\Http\Controllers\Admin\BotAgentWebController::class, 'index'])->name('bot-agents.index');
        Route::post  ('/bot-agents',                       [App\Http\Controllers\Admin\BotAgentWebController::class, 'store'])->name('bot-agents.store');
        Route::patch ('/bot-agents/{id}',                  [App\Http\Controllers\Admin\BotAgentWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('bot-agents.update');
        Route::delete('/bot-agents/{id}',                  [App\Http\Controllers\Admin\BotAgentWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('bot-agents.destroy');

        // Voices (per-project speaker references + language)
        Route::get   ('/voices',                           [App\Http\Controllers\Admin\VoiceWebController::class, 'index'])->name('voices.index');
        Route::post  ('/voices',                           [App\Http\Controllers\Admin\VoiceWebController::class, 'store'])->name('voices.store');
        Route::post  ('/voices/{id}/default',              [App\Http\Controllers\Admin\VoiceWebController::class, 'setDefault'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('voices.default');
        Route::get   ('/voices/{id}/audio',                [App\Http\Controllers\Admin\VoiceWebController::class, 'audio'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('voices.audio');
        Route::delete('/voices/{id}',                      [App\Http\Controllers\Admin\VoiceWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('voices.destroy');

        // Conversations (sessions + messages)
        Route::get   ('/sessions',                         [App\Http\Controllers\Admin\SessionWebController::class, 'index'])->name('sessions.index');
        Route::get   ('/sessions/{id}',                    [App\Http\Controllers\Admin\SessionWebController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('sessions.show');

        // Leads (extracted from conversations)
        Route::get   ('/leads',                            [App\Http\Controllers\Admin\LeadWebController::class, 'index'])->name('leads.index');
        Route::get   ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('leads.show');
        Route::patch ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('leads.update');

        }); // end workspace.provisioned group
    });
