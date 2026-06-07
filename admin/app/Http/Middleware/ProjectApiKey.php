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

        $this->tenants->useFor($project);
        $request->attributes->set('project', $project);

        return $next($request);
    }
}
