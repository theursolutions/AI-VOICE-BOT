<?php

use App\Http\Controllers\Admin\DataSourceWebController;
use App\Http\Controllers\BotChatController;
use App\Http\Controllers\ConfigureAgentVoicesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspacePickerController;
use Illuminate\Support\Facades\Route;

// ── Public / pre-workspace ───────────────────────────────────────────────
Route::get('/', fn () => view('index'))->name('home');
Route::get('/v2', fn () => view('welcome-v2'))->name('home.v2');
Route::get('/voice-bot', fn () => view('voice-chat'));
Route::post('/send-voice', [ConfigureAgentVoicesController::class, 'process'])->name('voice.send');

// Landing "Call me now" capture (rate-limited, no auth).
Route::post('/api/demo-call', [App\Http\Controllers\PublicLandingController::class, 'demoCall'])
    ->name('demo-call');
// Remaining demo-call allowance for this IP, so the page can render the
// button already disabled instead of only failing on submit.
Route::get('/api/demo-call/status', [App\Http\Controllers\PublicLandingController::class, 'demoCallStatus'])
    ->name('demo-call.status');

// Contact-page form → contact_leads (rate-limited + honeypot, no auth).
Route::post('/api/contact', [App\Http\Controllers\PublicContactController::class, 'store'])
    ->name('contact.store');

// Crawler files (/robots.txt, /sitemap.xml) live in routes/crawler.php —
// they are registered without the `web` middleware group so a bot fetch
// doesn't mint a session. See that file for why.

// ── Public marketing + legal pages (footer links) ───────────────────────
Route::view('/about',          'pages.about')->name('about');
Route::view('/contact',        'pages.contact')->name('contact');
Route::view('/privacy',        'pages.privacy')->name('privacy');
Route::view('/terms',          'pages.terms')->name('terms');

// ── Data deletion ────────────────────────────────────────────────────────
// Required by Meta for any app holding messaging permissions, and a hard
// blocker on App Review. Three distinct URLs that are easy to confuse:
//
//   /data-deletion                     the human instructions page
//                                      → "Data Deletion Instructions URL"
//   /meta/data-deletion                the machine callback (POST, signed)
//                                      → "Data Deletion Request URL"
//   /meta/data-deletion/status/{code}  where the callback's reply points
//
// Meta pings the callback when you save it in the dashboard, so it must be
// live before the field will accept the URL.
Route::get('/data-deletion', [App\Http\Controllers\DataDeletionController::class, 'instructions'])
    ->name('data-deletion');
Route::get('/meta/data-deletion/status/{code}', [App\Http\Controllers\DataDeletionController::class, 'status'])
    ->where('code', '[A-Za-z0-9]+')->name('data-deletion.status');
// Server-to-server: no session, no CSRF token — authorisation is the HMAC on
// `signed_request` and nothing else.
Route::post('/meta/data-deletion', [App\Http\Controllers\DataDeletionController::class, 'callback'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('data-deletion.callback');
Route::view('/refund-policy',  'pages.refund')->name('refund-policy');
Route::view('/cookies',        'pages.cookies')->name('cookies');
Route::view('/security',       'pages.security')->name('security.page');

// ── Blog (public) ───────────────────────────────────────────────────────
// The site's compounding SEO layer. Posts are managed at /admin/blog and
// appear in /sitemap.xml automatically (App\Models\BlogPost::sitemapEntries).
Route::get('/blog',         [App\Http\Controllers\BlogController::class, 'index'])->name('blog.index');
// Slug pattern excludes slashes and dots so it can never shadow a real file
// or swallow a deeper path.
Route::get('/blog/{slug}',  [App\Http\Controllers\BlogController::class, 'show'])
    ->where('slug', '[A-Za-z0-9\-_]+')->name('blog.show');

// ── QR channel handoff (public, but signed + short-lived) ───────────────
// Scanned from the Channels page on desktop and finished on the phone, which
// is where the customer's WhatsApp actually lives. No session is available
// there, so authorisation rides entirely on Laravel's signed URL: 15-minute
// expiry, tamper-proof, and dead once the attempt completes.
Route::middleware('signed')->group(function () {
    Route::get('/connect/{log}',     [App\Http\Controllers\Admin\ChannelOnboardController::class, 'handoffOpen'])
        ->where('log', '[0-9]+')->name('channels.handoff.open');
    Route::get('/connect/{log}/go',  [App\Http\Controllers\Admin\ChannelOnboardController::class, 'handoffGo'])
        ->where('log', '[0-9]+')->name('channels.handoff.go');
});

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
// Public /pricing, the workspace billing area, and Super Admin → Billing.
// The Stripe webhook is NOT here — it needs no session or CSRF, so it lives
// in routes/stripe.php with no middleware group (see RouteServiceProvider).
require __DIR__.'/billing.php';

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

        // Homepage testimonial carousel — rows, so a CRUD screen of its own
        // rather than more content.* keys. The section heading/lead still
        // live in /admin/content.
        // Passive visitor analytics for the public site.
        Route::get ('/visitors',          [App\Http\Controllers\SuperAdmin\VisitorsController::class, 'index'])->name('visitors.index');
        Route::get ('/visitors/{id}',     [App\Http\Controllers\SuperAdmin\VisitorsController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('visitors.show');
        Route::post('/visitors/geo',      [App\Http\Controllers\SuperAdmin\VisitorsController::class, 'resolveGeo'])->name('visitors.geo');

        // ── Blog / articles: the public /blog section ──────────────────────
        Route::get ('/blog',                      [App\Http\Controllers\SuperAdmin\BlogController::class, 'index'])->name('blog.index');
        Route::get ('/blog/create',               [App\Http\Controllers\SuperAdmin\BlogController::class, 'create'])->name('blog.create');
        Route::post('/blog',                      [App\Http\Controllers\SuperAdmin\BlogController::class, 'store'])->name('blog.store');
        Route::get ('/blog/{id}/edit',            [App\Http\Controllers\SuperAdmin\BlogController::class, 'edit'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('blog.edit');
        Route::post('/blog/{id}',                 [App\Http\Controllers\SuperAdmin\BlogController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('blog.update');
        Route::post('/blog/{id}/toggle',          [App\Http\Controllers\SuperAdmin\BlogController::class, 'toggle'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('blog.toggle');
        Route::post('/blog/{id}/feature',         [App\Http\Controllers\SuperAdmin\BlogController::class, 'feature'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('blog.feature');
        Route::post('/blog/{id}/delete',          [App\Http\Controllers\SuperAdmin\BlogController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('blog.delete');

        Route::get ('/testimonials',              [App\Http\Controllers\SuperAdmin\TestimonialsController::class, 'index'])->name('testimonials.index');
        Route::post('/testimonials',              [App\Http\Controllers\SuperAdmin\TestimonialsController::class, 'store'])->name('testimonials.store');
        Route::post('/testimonials/{id}',         [App\Http\Controllers\SuperAdmin\TestimonialsController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('testimonials.update');
        Route::post('/testimonials/{id}/toggle',  [App\Http\Controllers\SuperAdmin\TestimonialsController::class, 'toggle'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('testimonials.toggle');
        Route::post('/testimonials/{id}/delete',  [App\Http\Controllers\SuperAdmin\TestimonialsController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('testimonials.delete');

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

// Instagram Login callback — a DIFFERENT endpoint from the one above, not a
// duplicate. Instagram Login exchanges its code on api.instagram.com and the
// redirect_uri is part of that exchange, so the two flows cannot share a URL.
// Register this under: App dashboard → Instagram → API setup with Instagram
// login → "OAuth redirect URIs".
Route::middleware('auth')
    ->get('/meta/instagram/callback', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'instagramCallback'])
    ->name('meta.instagram.callback');

// Instagram calls these two server-to-server, with no session and no CSRF
// token — authorisation is the HMAC on `signed_request` and nothing else.
// Both must be registered in the same Instagram product settings.
Route::post('/meta/instagram/deauthorize', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'instagramDeauthorize'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('meta.instagram.deauthorize');

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
        //    four gates, in this order:
        //
        //      module.enabled — platform-wide super-admin switch. Off → the
        //                       "under development" page, for everyone.
        //      subscribed     — is this workspace paid up? A lapsed free week
        //                       or exhausted dunning degrades to READ-ONLY
        //                       (reads pass, writes redirect to /billing) so
        //                       nobody is ever locked away from their own data.
        //      plan.feature   — does their PLAN include this module? Owners do
        //                       NOT bypass: being the owner says nothing about
        //                       what the workspace paid for.
        //      module.access  — does this MEMBER's role allow it? Owners bypass.
        //
        //    The last two share config/modules.php's route→module mapping via
        //    App\Support\Modules, so entitlements and permissions can't drift.
        //
        //    Note /billing itself is registered in routes/billing.php OUTSIDE
        //    this group: a paywall you cannot pay through is just an outage.
        Route::middleware(['workspace.provisioned', 'module.enabled', 'subscribed', 'plan.feature', 'module.access', 'email.verified.gate'])->group(function () {

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
        // Each project brings its own Twilio account; .env is the demo only.
        Route::post  ('/telephony/credentials',            [App\Http\Controllers\Admin\TelephonyWebController::class, 'saveCredentials'])->name('telephony.save-credentials');
        Route::post  ('/telephony/credentials/delete',     [App\Http\Controllers\Admin\TelephonyWebController::class, 'deleteCredentials'])->name('telephony.delete-credentials');
        // "Bring your own Twilio" setup wizard.

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

        // Contact profile — the person behind the conversation, across every
        // channel they have ever used.
        Route::get  ('/chat/{sessionId}/contact', [App\Http\Controllers\Admin\ChatController::class, 'contact'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.contact');
        Route::post ('/chat/contacts/{id}',       [App\Http\Controllers\Admin\ChatController::class, 'updateContact'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.contact.update');
        Route::post ('/chat/contacts/{id}/merge', [App\Http\Controllers\Admin\ChatController::class, 'mergeContact'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.contact.merge');

        // Conversation statuses — customer-defined labels, managed from the
        // inbox itself rather than a separate settings page: they are only
        // ever edited while looking at the conversations they describe.
        Route::get   ('/chat/statuses',       [App\Http\Controllers\Admin\ChatController::class, 'statuses'])->name('chat.statuses');
        Route::post  ('/chat/statuses',       [App\Http\Controllers\Admin\ChatController::class, 'storeStatus'])->name('chat.statuses.store');
        Route::patch ('/chat/statuses/{id}',  [App\Http\Controllers\Admin\ChatController::class, 'updateStatus'])->where('id', '[0-9]+')->name('chat.statuses.update');
        Route::delete('/chat/statuses/{id}',  [App\Http\Controllers\Admin\ChatController::class, 'destroyStatus'])->where('id', '[0-9]+')->name('chat.statuses.destroy');
        Route::post  ('/chat/{sessionId}/status', [App\Http\Controllers\Admin\ChatController::class, 'setStatus'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.set-status');
        // Manual hand-off to a named agent, or back to the AI with a null id.
        Route::post  ('/chat/{sessionId}/transfer', [App\Http\Controllers\Admin\ChatController::class, 'transfer'])->where('sessionId', \App\Support\Hashid::ROUTE_PATTERN)->name('chat.transfer');
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
        // WhatsApp Embedded Signup — Meta's popup posts its code back here.
        Route::post  ('/channels/embedded-signup',    [App\Http\Controllers\Admin\ChannelOnboardController::class, 'embeddedSignup'])->name('channels.embedded-signup');
        // QR handoff: desktop mints the code, then polls while the phone finishes.
        Route::get   ('/channels/handoff/{provider}', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'handoff'])->name('channels.handoff');
        Route::get   ('/channels/handoff/{log}/status', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'handoffStatus'])->where('log', '[0-9]+')->name('channels.handoff.status');
        // Replay a failed attempt from the stored Meta payload — no consent screen.
        Route::post  ('/channels/onboarding/{log}/retry', [App\Http\Controllers\Admin\ChannelOnboardController::class, 'retry'])->where('log', '[0-9]+')->name('channels.onboarding.retry');
        Route::post  ('/channels',             [App\Http\Controllers\Admin\ChannelWebController::class, 'store'])->name('channels.store');
        Route::post  ('/channels/{id}/toggle', [App\Http\Controllers\Admin\ChannelWebController::class, 'toggle'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('channels.toggle');
        Route::delete('/channels/{id}',        [App\Http\Controllers\Admin\ChannelWebController::class, 'destroy'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('channels.destroy');

        // Conversation Flow builder (per-project)
        Route::get   ('/flows',                            [App\Http\Controllers\Admin\FlowWebController::class, 'index'])->name('flows.index');
        Route::post  ('/flows',                            [App\Http\Controllers\Admin\FlowWebController::class, 'store'])->name('flows.store');
        // AI flow builder. Declared before /flows/{id} so "ai" is never
        // swallowed by the id pattern.
        Route::post  ('/flows/ai/plan',                    [App\Http\Controllers\Admin\FlowWebController::class, 'aiPlan'])->name('flows.ai-plan');
        Route::post  ('/flows/ai/create',                  [App\Http\Controllers\Admin\FlowWebController::class, 'aiCreate'])->name('flows.ai-create');
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

        // Contacts — one row per PERSON, across every channel they use.
        // Distinct from Leads, which is one row per captured opportunity.
        Route::get   ('/contacts',                         [App\Http\Controllers\Admin\ContactWebController::class, 'index'])->name('contacts.index');
        // The common detail page — opened from the contacts list, from a
        // lead, and from the inbox.
        // Hashid::ROUTE_PATTERN, not [0-9]+ — ids are hashid-encoded in URLs
        // and DecodeHashids turns them back into integers before the
        // controller sees them. A numeric-only constraint simply never
        // matched, which is a 404 rather than an error.
        Route::get   ('/contacts/{id}',                    [App\Http\Controllers\Admin\ContactWebController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('contacts.show');

        // Leads (extracted from conversations)
        Route::get   ('/leads',                            [App\Http\Controllers\Admin\LeadWebController::class, 'index'])->name('leads.index');
        Route::get   ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'show'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('leads.show');
        Route::patch ('/leads/{id}',                       [App\Http\Controllers\Admin\LeadWebController::class, 'update'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('leads.update');
        // Kanban drag-and-drop. Answers JSON, so it is its own endpoint rather
        // than a mode of leads.update, which redirects.
        Route::patch ('/leads/{id}/status',                [App\Http\Controllers\Admin\LeadWebController::class, 'updateStatus'])->where('id', \App\Support\Hashid::ROUTE_PATTERN)->name('leads.status');

        }); // end workspace.provisioned group
    });
