<?php

namespace App\Services\Conversation;

use App\Models\AiBrain;
use App\Models\AiBrainUsage;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Picks which brain serves a call, and records what it spent.
 *
 * Selection lives here, in PHP, rather than in the voice-engine, because this
 * side owns the database the priorities and quotas are stored in. The engine
 * already accepts `provider` / `model` / `max_tokens` per request, so routing is
 * a matter of handing it the right values — its own env-derived fallback chain
 * stays underneath as the last-resort net for a provider that dies mid-call.
 *
 * Order of preference:
 *
 *   1. the brain the project explicitly chose, if still usable
 *   2. the client's own brains, by priority        (their key, their bill)
 *   3. platform brains, by priority, skipping any over quota
 *   4. nothing — the caller omits overrides and the engine uses its own default
 *
 * A client's own key always wins over our ordering, because they are paying for
 * it. Our ordering governs our own pool.
 */
class BrainResolver
{
    /** Call types, for usage accounting. */
    public const CALL_ROUTE   = 'route';
    public const CALL_REPLY   = 'reply';
    public const CALL_CAPTURE = 'capture';
    public const CALL_SUMMARY = 'summary';

    /**
     * Resolution is cached briefly.
     *
     * Three calls per customer message would otherwise each re-run the same
     * ordered query. Sixty seconds is short enough that switching a brain in the
     * UI feels immediate, and long enough that a busy conversation is not
     * re-resolving constantly.
     */
    private const CACHE_TTL = 60;

    /**
     * Options to merge into a PythonClient call, or [] to let the engine decide.
     *
     * Returning [] rather than throwing is deliberate: no configured brain must
     * never mean no reply. An install that has not set any of this up keeps
     * working exactly as it did before, on the engine's own configuration.
     *
     * @return array{provider?:string, model?:string, api_key?:string, base_url?:string, max_tokens?:int, brain_id?:int}
     */
    public function optionsFor(?int $projectId, string $callType = self::CALL_REPLY): array
    {
        $brain = $this->resolve($projectId);

        if (! $brain) {
            return [];
        }

        return array_filter([
            'provider'   => $this->providerFor($brain),
            'model'      => $brain->model,
            'api_key'    => $brain->api_key,
            'base_url'   => $brain->base_url,
            'max_tokens' => $brain->max_tokens,
            // Not sent to the engine — stripped by PythonClient and used to
            // attribute the usage that comes back.
            'brain_id'   => $brain->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** The brain that should serve this project right now. */
    public function resolve(?int $projectId): ?AiBrain
    {
        if ($projectId === null) {
            return $this->firstUsable(AiBrain::query()->platform());
        }

        $cacheKey = "brain:resolved:{$projectId}";

        $id = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($projectId) {
            $brain = $this->resolveUncached($projectId);

            // Cache the miss too, as 0. Otherwise a project with nothing
            // configured re-runs three queries per message forever.
            return $brain?->id ?? 0;
        });

        if (! $id) {
            return null;
        }

        $brain = AiBrain::find($id);

        // Re-check quota on the way out. The cached decision is 60s old and a
        // brain can cross its limit inside that window; the check is a single
        // integer comparison, so paying it per call is cheaper than a shorter
        // TTL would be.
        if (! $brain || ! $brain->is_active || $brain->isOverQuota()) {
            Cache::forget($cacheKey);

            return $brain && $brain->isOverQuota()
                ? $this->resolveUncached($projectId)
                : null;
        }

        return $brain;
    }

    private function resolveUncached(int $projectId): ?AiBrain
    {
        $project = Project::find($projectId);

        if (! $project) {
            return null;
        }

        $clientId = (int) $project->client_id;

        // 1. An explicit choice on the project, honoured only while it remains
        //    usable and belongs to this client or to the platform. The ownership
        //    check is what stops one client pointing at another client's key.
        $chosenId = (int) data_get($project->metadata, 'brain_id', 0);

        if ($chosenId) {
            $chosen = AiBrain::query()
                ->usable()
                ->where('id', $chosenId)
                ->where(fn ($q) => $q->whereNull('client_id')->orWhere('client_id', $clientId))
                ->first();

            if ($chosen && ! $chosen->isOverQuota()) {
                return $chosen;
            }
        }

        // 2. The client's own brains. Theirs come first because they are paying.
        if ($brain = $this->firstUsable(AiBrain::query()->forClient($clientId))) {
            return $brain;
        }

        // 3. Our pool, in the order the super admin set.
        return $this->firstUsable(AiBrain::query()->platform());
    }

    /** First usable brain in a scope, in priority order, skipping spent quotas. */
    private function firstUsable($query): ?AiBrain
    {
        $candidates = $query->usable()->orderBy('priority')->orderBy('id')->get();

        foreach ($candidates as $brain) {
            if (! $brain->isOverQuota()) {
                return $brain;
            }
        }

        return null;
    }

    /**
     * The engine-side provider name for a brain.
     *
     * `openai_compat` is not a provider the engine knows by name — it is the
     * wire format. Sending base_url + api_key alongside is what makes it
     * concrete, and the engine builds a generic client from those.
     */
    private function providerFor(AiBrain $brain): string
    {
        return match ($brain->kind) {
            AiBrain::KIND_ANTHROPIC => 'anthropic',
            AiBrain::KIND_OLLAMA    => 'ollama',
            default                 => 'openai_compat',
        };
    }

    /**
     * Record what a call spent, against the brain that served it.
     *
     * Called after the fact, because token counts only exist once the provider
     * has answered. That means a quota can be exceeded by at most one call —
     * accepted deliberately: the alternative is estimating tokens before the
     * call and refusing on a guess, which would reject legitimate work.
     *
     * Never allowed to throw. Accounting failing must not fail a reply the
     * customer is waiting for.
     */
    public function record(?int $brainId, ?int $projectId, string $callType, ?int $tokensIn, ?int $tokensOut): void
    {
        if (! $brainId) {
            return;
        }

        try {
            $in  = max(0, (int) $tokensIn);
            $out = max(0, (int) $tokensOut);

            // A call that produced no tokens failed. Counted separately, because
            // a dead brain and an unused brain look identical on token counts
            // alone and need opposite responses.
            $failed = ($in + $out) === 0;

            AiBrainUsage::accumulate($brainId, $projectId, $callType, $in, $out, $failed);

            if (! $failed) {
                AiBrain::whereKey($brainId)->increment('tokens_used', $in + $out);
            }
        } catch (\Throwable $e) {
            Log::warning('BrainResolver: could not record usage', [
                'brain_id' => $brainId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /** Drop a project's cached decision, after a brain or choice changes. */
    public static function forget(?int $projectId = null): void
    {
        if ($projectId) {
            Cache::forget("brain:resolved:{$projectId}");

            return;
        }

        // A platform brain changed, so every project's decision may be stale.
        // Cheaper and more honest than tracking which projects resolved to it.
        foreach (Project::pluck('id') as $id) {
            Cache::forget("brain:resolved:{$id}");
        }
    }
}
