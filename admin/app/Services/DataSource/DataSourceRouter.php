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
     * Pass $context['customer_facing'] = true for public web-chat / voice
     * turns: only sources the owner has marked customer_visible are then
     * consulted. The internal "Ask AI" assistant omits the flag and sees
     * every source.
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
            // Audience gate: on customer-facing turns, only sources the
            // owner opted in are visible. Internal "Ask AI" omits the flag
            // and sees everything. Deny-by-default — nothing opted in means
            // the customer bot answers general questions only.
            ->when(!empty($context['customer_facing']), fn ($q) => $q->where('customer_visible', true))
            ->get();

        // Flow-scoped sources: a Flow "Data Source" node can pin the
        // conversation to specific source(s) for a branch (e.g. the Customer
        // Support KB). When the caller passes such a scope, use exactly those
        // (still honoring the enable toggle) and skip the smart router. An
        // empty/absent scope falls through to normal behaviour.
        $scope = array_values(array_filter(array_map(
            'intval',
            (array) ($context['source_ids'] ?? []),
        )));

        if (!empty($scope)) {
            $sources = $sources->whereIn('id', $scope)->values();
        } elseif ($sources->count() > 1) {
            // Smart routing: with a capable reasoning model configured, narrow
            // the candidates to the source(s) actually relevant to this
            // question instead of querying every source. Returns null
            // (undecided / no reasoning model) → keep all; an array → explicit.
            try {
                $selected = app(\App\Services\Conversation\SourceRouter::class)
                    ->select($userQuery, $sources->all());
                if (is_array($selected)) {
                    $sources = $sources->whereIn('id', $selected)->values();
                }
            } catch (Throwable $e) {
                Log::warning('DataSourceRouter: routing failed, using all sources: ' . $e->getMessage());
            }
        }

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
