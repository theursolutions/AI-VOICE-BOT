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
        $this->app->singleton(TenantManager::class);

        // CRM OAuth plumbing — single Guzzle client / vault per request lifecycle.
        $this->app->singleton(HubSpotClient::class);
        $this->app->singleton(SalesforceClient::class);
        $this->app->singleton(PipedriveClient::class);
        $this->app->singleton(ZohoClient::class);
        $this->app->singleton(TokenVault::class);

        $this->app->singleton(DataSourceRouter::class, function ($app) {
            $router = new DataSourceRouter();
            $router->register($app->make(WebsiteResolver::class));
            $router->register($app->make(DocumentResolver::class));
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
                $picked = (int) $request->query('project_id');
                $query = Project::where('client_id', $client->id);
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

            $view->with('tvaWidget',  $config);
            $view->with('tvaProject', $activeProject);
            $view->with('tvaProfile', $activeProfile);
        });
    }
}
