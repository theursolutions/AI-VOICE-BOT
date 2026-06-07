<?php

namespace App\Services\Conversation;

use App\Models\BotAgent;
use App\Models\Project;
use App\Models\Skill;

/**
 * Picks ONE BotAgent to handle a session, based on:
 *
 *   1. Telephony per-number routing config (routing_type=agents|skill)
 *      - agents → round-robin from the explicit agent_ids list
 *      - skill  → round-robin from active agents in that skill
 *   2. Webchat (no routing config) → project default agent
 *   3. Anything missing/empty → first active project agent
 *   4. Nothing at all → null (caller falls back to project defaults)
 *
 * Round-robin is intentionally crude: `id % count` keyed on session id.
 * Good enough for early-stage load distribution; can swap for an
 * agent_load_counter in a later pass without breaking callers.
 *
 * IMPORTANT: this service assumes the caller has already pointed the
 * `tenant` connection at the right project DB (BotAgent / Skill live
 * in the tenant DB). It does NOT switch connections itself, so it can
 * be called from inside controllers that have already done so.
 */
class AgentRouter
{
    /**
     * @param  array{routing_type?:string, agent_ids?:array, skill_id?:int|null}|null  $routing
     */
    public function pick(Project $project, ?array $routing, int $sessionId): ?BotAgent
    {
        $candidates = $this->candidateIds($project, $routing);
        if (empty($candidates)) {
            return $this->fallback($project);
        }

        $active = BotAgent::query()
            ->whereIn('id', $candidates)
            ->where('project_id', $project->id)
            ->where('status', BotAgent::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        if ($active->isEmpty()) {
            return $this->fallback($project);
        }

        // Deterministic per-session pick → same session always lands
        // on the same agent (idempotent), but different sessions spread
        // across the pool.
        $idx = abs($sessionId) % $active->count();
        return $active[$idx];
    }

    /**
     * Resolve the list of agent IDs eligible for this session based on
     * routing config. Returns [] when no routing was supplied — caller
     * uses the fallback path.
     */
    private function candidateIds(Project $project, ?array $routing): array
    {
        if (!$routing) {
            return [];
        }

        $type = $routing['routing_type'] ?? 'agents';

        if ($type === 'skill' && !empty($routing['skill_id'])) {
            $skill = Skill::query()
                ->where('id', (int) $routing['skill_id'])
                ->where('project_id', $project->id)
                ->where('status', Skill::STATUS_ACTIVE)
                ->first();
            if (!$skill) return [];
            return $skill->agents()
                ->where('agents.project_id', $project->id)
                ->where('agents.status', BotAgent::STATUS_ACTIVE)
                ->pluck('agents.id')->all();
        }

        // routing_type=agents (or anything we don't recognise)
        return array_map('intval', (array) ($routing['agent_ids'] ?? []));
    }

    /**
     * No routing config or empty pool → pick the project's default
     * agent (is_default=true), or fall back to the first active one.
     */
    private function fallback(Project $project): ?BotAgent
    {
        $default = BotAgent::query()
            ->where('project_id', $project->id)
            ->where('status', BotAgent::STATUS_ACTIVE)
            ->where('is_default', true)
            ->first();
        if ($default) return $default;

        return BotAgent::query()
            ->where('project_id', $project->id)
            ->where('status', BotAgent::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Helper for controllers: pick + write to the session in one call.
     * Sets agent_id, skill_id (if from skill routing), and voice_id
     * (cloned from the agent so the JWT minter picks it up).
     */
    public function assignToSession(Project $project, \App\Models\Session $session): ?BotAgent
    {
        $routing = (array) data_get($session->metadata, 'routing');
        $agent = $this->pick($project, $routing ?: null, $session->id);
        if (!$agent) return null;

        $session->agent_id = $agent->id;
        if (($routing['routing_type'] ?? null) === 'skill' && !empty($routing['skill_id'])) {
            $session->skill_id = (int) $routing['skill_id'];
        }
        // Use the agent's voice unless the session already had one
        // explicitly set (e.g. via API request).
        if (!$session->voice_id && $agent->voice_id) {
            $session->voice_id = $agent->voice_id;
        }
        $session->save();

        return $agent;
    }
}
