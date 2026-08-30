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
        $brain = $this->resolve($projectId, $callType);

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

    /**
     * The brain that should serve this project's call of this type.
     *
     * The call type only ever narrows the PLATFORM POOL — see resolveUncached().
     */
    public function resolve(?int $projectId, string $callType = self::CALL_REPLY): ?AiBrain
    {
        $role = self::roleForCall($callType);

        if ($projectId === null) {
            return $this->firstUsable(AiBrain::query()->platform(), $role);
        }

        // Keyed by role, not call type: capture and summary resolve identically,
        // so giving them separate entries would triple the queries behind a cache
        // that exists to stop exactly that.
        $cacheKey = "brain:resolved:{$projectId}:{$role}";

        $id = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($projectId, $role) {
            $brain = $this->resolveUncached($projectId, $role);

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
                ? $this->resolveUncached($projectId, $role)
                : null;
        }

        return $brain;
    }

    /**
     * Which role a call type needs.
     *
     * Only the reply is customer-facing; route, capture and summary are all
     * machinery. An unrecognised call type resolves as a REPLY — the expensive
     * assumption on purpose, because a future call type silently demoted to the
     * cheap model would be a quality regression nobody would think to look for.
     */
    public static function roleForCall(string $callType): string
    {
        // An ALLOW-LIST of machinery, not "anything that is not a reply".
        //
        // Written as `$callType === CALL_REPLY ? REPLY : MACHINERY` this reads
        // the same and behaves the opposite way at the only point that matters:
        // a call type nobody has classified yet lands on the cheap model. That
        // is a quality regression with no error, no log line and no failing
        // request — the reply just gets worse for whichever feature added the
        // call. Listing machinery explicitly makes the default expensive, which
        // is the direction a mistake here should fail in.
        return in_array($callType, [self::CALL_ROUTE, self::CALL_CAPTURE, self::CALL_SUMMARY], true)
            ? AiBrain::SERVES_MACHINERY
            : AiBrain::SERVES_REPLY;
    }

    private function resolveUncached(int $projectId, string $role): ?AiBrain
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
        //
        // NO ROLE SPLIT HERE, and that is the important part. All four calls
        // carry the customer's words — the reply obviously, but tool selection
        // sees the message, extraction sees the transcript, and summarisation
        // sees the whole conversation. Sending some of those to our provider
        // account to save a fraction of a cent is exactly what bring-your-own-key
        // exists to prevent, and the client is paying for their key precisely so
        // their customers' data goes to their vendor and nobody else's.
        //
        // Cost optimisation is a thing we may do to our own pool. It is not a
        // thing we may do with someone else's data.
        if ($brain = $this->firstUsable(AiBrain::query()->forClient($clientId))) {
            return $brain;
        }

        // 3. Our pool: brains that serve this role, specialists ahead of
        //    catch-alls, then in the order the super admin set.
        if ($brain = $this->firstUsable(AiBrain::query()->platform(), $role)) {
            return $brain;
        }

        // 4. Nothing claims this role. Fall back to ANY usable platform brain
        //    rather than to the engine's own default: a machinery call escaping
        //    to the engine is unattributed spend, and ai_brain_usage silently
        //    missing a share of the bill is what makes cost-based pricing wrong.
        return $this->firstUsable(AiBrain::query()->platform());
    }

    /**
     * First usable brain in a scope, in priority order, skipping spent quotas.
     *
     * A role narrows the candidates and orders specialists first; omitting it
     * considers every brain, which is what the final fallback wants.
     */
    private function firstUsable($query, ?string $role = null): ?AiBrain
    {
        if ($role !== null) {
            $query = $query->serving($role);
        }

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

    /**
     * Drop a project's cached decisions, after a brain or choice changes.
     *
     * Every role variant, not just one. A project caches one decision per role,
     * so clearing a single key leaves the other serving the brain that was just
     * switched off — for up to CACHE_TTL, on the half of the traffic nobody
     * thinks to check. Enumerating the roles keeps this honest as roles are
     * added, because a new role that is not in ROLES will not be cleared and
     * that is a bug waiting in a place nobody looks.
     */
    public static function forget(?int $projectId = null): void
    {
        $roles = [AiBrain::SERVES_REPLY, AiBrain::SERVES_MACHINERY];

        if ($projectId) {
            foreach ($roles as $role) {
                Cache::forget("brain:resolved:{$projectId}:{$role}");
            }

            return;
        }

        // A platform brain changed, so every project's decision may be stale.
        // Cheaper and more honest than tracking which projects resolved to it.
        foreach (Project::pluck('id') as $id) {
            foreach ($roles as $role) {
                Cache::forget("brain:resolved:{$id}:{$role}");
            }
        }
    }
}
