<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        // One URL per page: 301 /about/ → /about before anything else runs.
        // nginx (production) does not do this for us the way Apache's
        // .htaccess does locally, so both spellings returned 200 in prod.
        \App\Http\Middleware\RedirectTrailingSlash::class,
        // noindex header on the authenticated app (see the class docblock).
        \App\Http\Middleware\NoIndexPrivateAreas::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            // Decode hashed ids in route params + query/body BEFORE binding
            // resolution and controllers run. Dual-mode: raw ints pass through.
            \App\Http\Middleware\DecodeHashids::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureActiveAccount::class,
            // Passive visitor analytics for the public pages. Runs last and
            // records only after the response exists; it self-limits to
            // successful HTML GETs outside config('visitors.ignore_paths'),
            // so the admin console and APIs are untouched.
            \App\Http\Middleware\TrackVisitor::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class.':api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'active.account' => \App\Http\Middleware\EnsureActiveAccount::class,
        'project.apikey' => \App\Http\Middleware\ProjectApiKey::class,
        'internal.webhook' => \App\Http\Middleware\InternalWebhook::class,
        'twilio.signature' => \App\Http\Middleware\TwilioSignature::class,
        'active.client' => \App\Http\Middleware\EnsureActiveClient::class,
        'workspace.provisioned' => \App\Http\Middleware\EnsureWorkspaceProvisioned::class,
        'module.access' => \App\Http\Middleware\EnsureModuleAccess::class,
        'module.enabled' => \App\Http\Middleware\EnsureModuleEnabled::class,
        'email.verified.gate' => \App\Http\Middleware\EnsureEmailVerified::class,
        'super-admin' => \App\Http\Middleware\IsSuperAdmin::class,
        // Billing gates. Order in the workspace group matters:
        //   module.enabled → subscribed → plan.feature → module.access
        // i.e. platform switch, then "is this workspace paid up", then "does
        // their plan include it", then "does this member's role allow it".
        'subscribed'   => \App\Http\Middleware\EnsureSubscribed::class,
        'plan.feature' => \App\Http\Middleware\EnsurePlanFeature::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'precognitive' => \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ];
}
