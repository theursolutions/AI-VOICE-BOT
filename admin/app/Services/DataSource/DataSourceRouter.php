<?php

namespace App\Services\DataSource;

use App\Http\Controllers\Admin\BotStrategyController;
use App\Models\DataSource;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Throwable;

class DataSourceRouter
{
    /** @var array<string, ResolverInterface> */
    private array $resolvers = [];

    public function register(ResolverInterface $resolver): void
    {
        $this->resolvers[$resolver->type()] = $resolver;
    }

    public function resolverFor(string $type): ?ResolverInterface
    {
        return $this->resolvers[$type] ?? null;
    }

    public function hasResolver(string $type): bool
    {
        return isset($this->resolvers[$type]);
    }

    /**
     * Run the user's query against every usable data source for the
     * project and return all results. Caller decides how to merge
     * them into LLM context.
     *
     * @return ResolverResult[]
     */
    public function resolve(int $projectId, string $userQuery, array $context = []): array
    {
        // Honor the per-tier strategy toggle stored in
        // projects.json_data['data_strategy']. Missing entries default
        // to "on" so existing projects keep their current behaviour.
        $strategy = BotStrategyController::DEFAULTS;
        $project = Project::find($projectId);
        if ($project) {
            $stored = (array) data_get($project->json_data, 'data_strategy', []);
            $strategy = array_merge($strategy, array_intersect_key($stored, BotStrategyController::DEFAULTS));
        }
        $enabledTypes = array_keys(array_filter($strategy));

        $sources = DataSource::where('project_id', $projectId)
            ->where('status', DataSource::STATUS_ACTIVE)
            ->where('is_active', 'Yes')
            ->whereIn('type', $enabledTypes)
            ->get();

        $results = [];

        foreach ($sources as $source) {
            $resolver = $this->resolvers[$source->type] ?? null;

            if (!$resolver) {
                Log::warning("No resolver registered for source type '{$source->type}' (source #{$source->id})");
                continue;
            }

            try {
                $results[] = $resolver->resolve($userQuery, $source, $context);
            } catch (Throwable $e) {
                Log::error("Resolver {$source->type} threw for source #{$source->id}: ".$e->getMessage());
                $results[] = ResolverResult::error($source->id, $source->type, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Filter to only usable (non-empty, non-error) results.
     *
     * @param  ResolverResult[]  $results
     * @return ResolverResult[]
     */
    public function onlyUsable(array $results): array
    {
        return array_values(array_filter($results, fn (ResolverResult $r) => $r->isUsable()));
    }
}
