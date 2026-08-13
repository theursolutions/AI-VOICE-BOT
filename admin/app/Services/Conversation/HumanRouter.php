<?php

namespace App\Services\Conversation;

use App\Models\BotAgent;
use App\Models\Session;

/**
 * Routes an escalated chat to a human agent — auto least-busy, skill-matched,
 * capacity-aware, with a queue when everyone is full.
 *
 * Assumes the tenant connection is already pointed at the project (callers
 * run inside a tenant-scoped request).
 */
class HumanRouter
{
    /**
     * Hand a conversation to a human. Returns the assigned agent, or null
     * when none is available (the session is then queued). Either way the
     * bot is paused so the AI stops replying.
     */
    public function handoff(Session $session): ?BotAgent
    {
        $agent = $this->pickAgent((int) $session->project_id, $session->skill_id);

        $this->pauseBot($session);
        if ($agent) {
            $session->assigned_agent_id = $agent->id;
            $session->handoff_status = 'assigned';
        } else {
            $session->assigned_agent_id = null;
            $session->handoff_status = 'queued';
        }

        // Record that a PERSON is needed, as its own fact rather than
        // something the inbox has to infer.
        //
        // `handoff_status` alone cannot answer this: it becomes `assigned` the
        // instant an agent is picked, so a conversation where the customer
        // asked for a human and nobody has yet replied looks identical to one
        // being actively handled. This flag is set when the bot gives up and
        // cleared when a human actually sends something — see
        // ChatController::persistOutbound.
        $meta = (array) $session->metadata;
        $meta['meta'] = array_merge((array) ($meta['meta'] ?? []), [
            'needs_human'    => true,
            'needs_human_at' => time(),
        ]);
        $session->metadata = $meta;

        $session->update_at = time();
        $session->save();

        return $agent;
    }

    /**
     * Drain the queue for a project — called when an agent comes online or
     * frees up capacity. Assigns oldest-waiting chats until capacity runs out.
     */
    public function assignQueued(int $projectId): int
    {
        $assigned = 0;
        $queued = Session::where('project_id', $projectId)
            ->where('handoff_status', 'queued')
            ->where('status', 'active')
            ->orderBy('last_inbound_at')
            ->get();

        foreach ($queued as $session) {
            $agent = $this->pickAgent($projectId, $session->skill_id);
            if (!$agent) {
                break; // no capacity left
            }
            $session->assigned_agent_id = $agent->id;
            $session->handoff_status = 'assigned';
            $session->update_at = time();
            $session->save();
            $assigned++;
        }
        return $assigned;
    }

    /** Least-busy available human with spare capacity; prefers a skill match. */
    private function pickAgent(int $projectId, $skillId = null): ?BotAgent
    {
        $agents = BotAgent::query()
            ->availableHuman()
            ->where('project_id', $projectId)
            ->with('skills')
            ->get()
            ->map(fn (BotAgent $a) => ['agent' => $a, 'load' => $a->activeChatCount()])
            ->filter(fn ($x) => $x['load'] < max(1, (int) ($x['agent']->max_active_chats ?: 3)));

        if ($agents->isEmpty()) {
            return null;
        }

        // Prefer agents who hold the conversation's skill, if any qualify.
        if ($skillId) {
            $skilled = $agents->filter(fn ($x) => $x['agent']->skills->contains('id', (int) $skillId));
            if ($skilled->isNotEmpty()) {
                $agents = $skilled;
            }
        }

        return $agents->sortBy('load')->first()['agent'];
    }

    private function pauseBot(Session $session): void
    {
        $metadata = (array) $session->metadata;
        $metadata['meta'] = array_merge((array) ($metadata['meta'] ?? []), ['bot_paused' => true]);
        $session->metadata = $metadata;
    }
}
