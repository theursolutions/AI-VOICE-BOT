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
 * At every stage candidates are filtered by CHANNEL: an agent configured
 * for WhatsApp only will not be handed a Facebook conversation. An agent
 * with no channels configured handles all of them, so nothing that worked
 * before this existed stops working.
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
    public function pick(Project $project, ?array $routing, int $sessionId, ?string $channel = null): ?BotAgent
    {
        $candidates = $this->candidateIds($project, $routing);
        if (empty($candidates)) {
            return $this->fallback($project, $channel);
        }

        $active = BotAgent::query()
            ->whereIn('id', $candidates)
            ->where('project_id', $project->id)
            ->where('status', BotAgent::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->filter(fn (BotAgent $a) => $a->handlesChannel($channel))
            ->values();

        if ($active->isEmpty()) {
            return $this->fallback($project, $channel);
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
     * No routing config or empty pool → the project's default agent for this
     * channel, else the first active one that handles it.
     *
     * Restricted to AI agents. This is the persona whose prompt the bot
     * speaks with; returning a human here — which the old query happily did
     * whenever a human happened to be `is_default` — meant the AI answered
     * as a person who was not actually present.
     *
     * The channel filter runs in PHP because the assignment lives in the
     * `metadata` JSON column, and MySQL and SQLite disagree enough on JSON
     * path syntax to make a pushed-down query pass in production and fail in
     * the test suite.
     */
    private function fallback(Project $project, ?string $channel = null): ?BotAgent
    {
        $agents = BotAgent::query()
            ->where('project_id', $project->id)
            ->where('status', BotAgent::STATUS_ACTIVE)
            ->where('type', BotAgent::TYPE_AI)
            ->orderByDesc('is_default')     // the project default first…
            ->orderByDesc('id')             // …then the newest
            ->get();

        return $agents->first(fn (BotAgent $a) => $a->handlesChannel($channel))
            // A project whose agents are all restricted to other channels
            // gets the default anyway. Silence is a worse outcome than a
            // slightly wrong persona, and the alternative is a customer
            // messaging a channel nobody covers and hearing nothing at all.
            ?: $agents->first();
    }

    /**
     * Helper for controllers: pick + write to the session in one call.
     * Sets agent_id, skill_id (if from skill routing), and voice_id
     * (cloned from the agent so the JWT minter picks it up).
     */
    public function assignToSession(Project $project, \App\Models\Session $session): ?BotAgent
    {
        $routing = (array) data_get($session->metadata, 'routing');
        // The channel is taken from the session rather than a parameter, so
        // every existing caller — telephony, webchat, all three Meta
        // channels — becomes channel-aware without being touched.
        $agent = $this->pick($project, $routing ?: null, $session->id, $session->channel);
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
