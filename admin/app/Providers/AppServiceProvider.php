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

            $view->with('tvaWidget',  $config);
            $view->with('tvaProject', $activeProject);
            $view->with('tvaProfile', $activeProfile);
            $view->with('tvaModules', $tvaModules);
        });
    }
}
