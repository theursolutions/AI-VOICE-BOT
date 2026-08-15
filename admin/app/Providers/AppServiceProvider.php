<?php

namespace App\Providers;

use App\Services\CrmProviders\HubSpotClient;
use App\Services\CrmProviders\PipedriveClient;
use App\Services\CrmProviders\SalesforceClient;
use App\Services\CrmProviders\TokenVault;
use App\Services\CrmProviders\ZohoClient;
use App\Services\DataSource\DataSourceRouter;
use App\Services\DataSource\Resolvers\AgentResolver;
use App\Services\DataSource\Resolvers\CrmOauthResolver;
use App\Services\DataSource\Resolvers\DatabaseResolver;
use App\Services\DataSource\Resolvers\DataSnapshotResolver;
use App\Services\DataSource\Resolvers\DocumentResolver;
use App\Services\DataSource\Resolvers\WebhookResolver;
use App\Services\DataSource\Resolvers\WebsiteResolver;
use App\Http\Controllers\Admin\WidgetSettingsController;
use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Replace the framework URL generator with one that hashes id-like
        // route parameters (see App\Routing\HashidUrlGenerator). Mirrors
        // Illuminate\Routing\RoutingServiceProvider::registerUrlGenerator so
        // the session/key/route rebindings stay wired.
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            $url = new \App\Routing\HashidUrlGenerator(
                $routes,
                $app->rebinding('request', function ($app, $request) {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url']
            );

            $url->setSessionResolver(function () use ($app) {
                return $app['session'] ?? null;
            });
            $url->setKeyResolver(function () use ($app) {
                return $app->make('config')->get('app.key');
            });

            $app->rebinding('routes', function ($app, $routes) {
                $app['url']->setRoutes($routes);
            });

            return $url;
        });

        $this->app->singleton(TenantManager::class);

        // CRM OAuth plumbing — single Guzzle client / vault per request lifecycle.
        $this->app->singleton(HubSpotClient::class);
        $this->app->singleton(SalesforceClient::class);
        $this->app->singleton(PipedriveClient::class);
        $this->app->singleton(ZohoClient::class);
        $this->app->singleton(TokenVault::class);

        // meta-channels package → CRM brain bridge. The package calls these
        // contracts; here we point them at the CRM implementations.
        $this->app->bind(
            \Msd\MetaChannels\Contracts\HandlesInboundMessage::class,
            \App\Meta\CrmInboundMessageHandler::class,
        );
        $this->app->bind(
            \Msd\MetaChannels\Contracts\HandlesInboundCall::class,
            \App\Meta\CrmInboundCallHandler::class,
        );
        $this->app->bind(
            \Msd\MetaChannels\Contracts\HandlesMessageStatus::class,
            \App\Meta\CrmMessageStatusHandler::class,
        );

        $this->app->singleton(DataSourceRouter::class, function ($app) {
            $router = new DataSourceRouter();
            $router->register($app->make(WebsiteResolver::class));
            $router->register($app->make(DocumentResolver::class));
            $router->register($app->make(DataSnapshotResolver::class));
            $router->register($app->make(WebhookResolver::class));
            $router->register($app->make(CrmOauthResolver::class));
            $router->register($app->make(DatabaseResolver::class));
            $router->register($app->make(AgentResolver::class));
            return $router;
        });
    }

    public function boot(): void
    {
        // ── Plans section ($pricing) ─────────────────────────────────
        //
        // partials/pricing-plans is included by the HOMEPAGE (#pricing) and by
        // /pricing, and needs a fully-built view model (plans, prices, the
        // visitor's approximate local currency, the comparison matrix).
        //
        // A composer rather than route-level injection: the homepage route is
        // a plain closure that several things render, and the partial should
        // work wherever it is dropped in without every caller remembering to
        // pass data. It also keeps all pricing lookups in ONE place, so the
        // homepage and /pricing can never show different numbers.
        //
        // Costs nothing on pages that don't include the partial — a composer
        // only fires for the views it is bound to. Degrades to rendering
        // nothing if the plan tables are missing or empty (a fresh install
        // before BillingSeeder), so the homepage can never 500 on billing.
        View::composer(['index', 'welcome-v2', 'pages.pricing'], function ($view) {
            if (array_key_exists('pricing', $view->getData())) {
                return;   // an explicit value from the controller wins
            }

            try {
                $view->with(
                    'pricing',
                    app(\App\Services\Billing\PricingPresenter::class)
                        ->build(request(), request()->query('billing'))
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('pricing.section.unavailable', [
                    'error' => $e->getMessage(),
                ]);

                $view->with('pricing', null);
            }
        });

        // Share the active client's widget config (colors + branding)
        // with EVERY view so the admin layout can apply the same theme
        // across dashboard, sessions, leads, voices, etc.
        View::composer('layouts.master', function ($view) {
            $config = WidgetSettingsController::DEFAULTS;
            $request = request();
            $client = $request ? $request->attributes->get('client') : null;

            $activeProject = null;
            $activeProfile = null;
            if ($client) {
                // Prefer ?project_id when the page used the project picker
                // (telephony / brain-settings / leads / sessions / etc.).
                // Otherwise fall back to the first project alphabetically.
                // Scope to the projects this member may access (owner =
                // all). A member can't force ?project_id onto a project
                // outside their assignment — it falls back to one they can see.
                $picked = (int) $request->query('project_id');
                $query = Project::where('client_id', $client->id)
                    ->accessibleBy($request->user(), (int) $client->id);
                $activeProject = $picked
                    ? (clone $query)->where('id', $picked)->first(['id', 'name', 'json_data'])
                    : null;
                if (!$activeProject) {
                    $activeProject = $query->orderBy('name')->first(['id', 'name', 'json_data']);
                }

                if ($activeProject) {
                    $stored = data_get($activeProject->json_data, 'widget', []);
                    if (is_array($stored)) {
                        $config = array_merge($config, $stored);
                    }
                    $activeProfile = (array) data_get($activeProject->json_data, 'profile', []);
                }
            }

            // Module visibility for the sidebar — the current member's role
            // decides which sections show. Owners (and non-client contexts)
            // get everything. Drives @if(in_array('key',$tvaModules)) gates.
            $user = $request ? $request->user() : null;
            $tvaModules = ($user && $client && method_exists($user, 'allowedModules'))
                ? $user->allowedModules($client->id)
                : array_keys((array) config('modules', []));

            // Then drop anything a super-admin has switched OFF platform-wide
            // (Ops Console → Modules), so disabled sections vanish from the
            // sidebar for everyone — owners included.
            $tvaModules = array_values(array_intersect(
                $tvaModules,
                \App\Support\Modules::enabledKeys(),
            ));

            // Finally drop anything the workspace's PLAN doesn't include, so the
            // menu matches what they actually bought. Without this a Free
            // workspace sees Telephony, Channels and Flow Builder in the
            // sidebar and only discovers they aren't included by clicking and
            // hitting the 402 upsell page — a menu full of dead ends.
            //
            // This mirrors EnsurePlanFeature exactly (same clientHasModule
            // call, same config/modules.php keys), so the sidebar and the route
            // gate can never disagree about what is reachable.
            //
            // Fails OPEN on a module no feature declares, and on a workspace
            // with no plan resolved (pre-billing / grandfathered) — the same
            // direction the middleware fails, so nothing an existing customer
            // could see yesterday disappears today.
            //
            // Set billing.settings.hide_locked_modules = false to keep locked
            // sections visible instead and let the upsell page do the selling.
            if ($client && config('billing.settings.hide_locked_modules', true)) {
                try {
                    $planFeatures = app(\App\Services\Billing\PlanFeatureService::class);

                    $tvaModules = array_values(array_filter(
                        $tvaModules,
                        fn (string $key) => $planFeatures->clientHasModule($client, $key),
                    ));
                } catch (\Throwable $e) {
                    // Billing tables missing (fresh install) — leave the menu
                    // exactly as RBAC left it rather than hiding the product.
                    \Illuminate\Support\Facades\Log::warning('sidebar.plan_filter_skipped', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Has this workspace been provisioned yet? Mirrors the check in
            // Admin\SetupController::show. The sidebar needs it because every
            // module link below is gated by `workspace.provisioned` — before
            // setup they all bounce straight back to /setup, so they're shown
            // veiled rather than as a menu full of dead ends.
            $tvaWorkspaceReady = ! $client || Project::where('client_id', $client->id)
                ->where('is_active', 'Yes')
                ->exists();

            $view->with('tvaWidget',  $config);
            $view->with('tvaProject', $activeProject);
            $view->with('tvaProfile', $activeProfile);
            $view->with('tvaModules', $tvaModules);
            $view->with('tvaWorkspaceReady', $tvaWorkspaceReady);
        });
    }
}
