<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\Tenant\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProjectApiKey
{
    public function __construct(private TenantManager $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-CLIENT-API-KEY') ?? $request->header('X-Project-Api-Key');

        if (!$key) {
            return response()->json(['error' => 'Missing API key'], 401);
        }

        $project = Project::where('project_api_key', $key)
            ->where('is_active', 'Yes')
            ->first();

        if (!$project) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        // `api_access` is sold from Growth up and was previously enforced
        // nowhere, so any workspace could drive the project API with its key.
        //
        // Scoped to the ROUTES A DEVELOPER CALLS, not the whole middleware:
        // the same key authenticates the embeddable chat widget, and gating
        // that would silently switch off the widget on the sites of every
        // customer below Growth — which is the product working as sold.
        if ($this->isDeveloperApiRoute($request)) {
            $client = \App\Models\Client::find($project->client_id);

            if ($client && ! app(\App\Services\Billing\PlanFeatureService::class)->clientHas($client, 'api_access')) {
                return response()->json([
                    'error'   => 'plan_upgrade_required',
                    'message' => 'API access isn’t included in your current plan.',
                ], 402);
            }
        }

        $this->tenants->useFor($project);
        $request->attributes->set('project', $project);

        return $next($request);
    }

    /**
     * Is this a developer/management API call, as opposed to the runtime path
     * the embeddable widget uses?
     *
     * An explicit ALLOWLIST of gated prefixes, deliberately not a denylist:
     * anything new defaults to ungated. On this middleware the safe direction
     * is open — a mistake here doesn't leak data, it silently stops answering
     * end customers on a paying site, which is far worse than a free workspace
     * getting one extra endpoint for a while.
     *
     *   sessions/*, widget/*  → the widget's own runtime. NEVER gated.
     *   data-sources/*, agents/*, agent/* → management, gated on `api_access`.
     */
    private function isDeveloperApiRoute(Request $request): bool
    {
        foreach (['api/v1/data-sources', 'api/v1/agents', 'api/v1/agent'] as $prefix) {
            if ($request->is($prefix, $prefix . '/*')) {
                return true;
            }
        }

        return false;
    }
}
