<?php

namespace App\Services\Telephony;

use App\Models\BotAgent;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Tenant\TenantManager;

/**
 * Opens the session for a new phone call — the same for every provider
 * (Twilio webhook, Asterisk ARI), so a call looks the same in the inbox,
 * the transcript, lead extraction and billing wherever it came from.
 */
class CallSessionService
{
    public function __construct(
        private TenantManager $tenants,
        private AgentRouter $router,
    ) {}

    /**
     * @param  array|null  $numberConfig  The dialed number's entry (routing, transfer_to), or null.
     * @param  array  $providerMeta  Provider ids, stored under metadata[$provider].
     */
    public function start(
        Project $project,
        ?array $numberConfig,
        string $provider,
        string $externalId,
        string $from,
        array $providerMeta,
    ): Session {
        // Session/Message/Lead live in the project's tenant DB, not the master.
        $this->tenants->useFor($project);

        $now = time();
        $session = Session::create([
            'project_id'       => $project->id,
            'channel'          => 'phone',
            'external_id'      => $externalId,
            'customer_name'    => null,
            'customer_phone'   => $from,
            'voice_id'         => null,
            'status'           => 'active',
            'started_at'       => $now,
            'last_activity_at' => $now,
            'metadata'         => [
                $provider => $providerMeta,
                // Carry the per-number routing config forward so the
                // session-token + memory-builder can pick the agent (or
                // skill pool) the admin assigned to this number, and the
                // voice engine knows where a transfer goes.
                'routing' => $numberConfig ? [
                    'routing_type' => $numberConfig['routing_type'] ?? 'agents',
                    'agent_ids'    => (array) ($numberConfig['agent_ids'] ?? []),
                    'skill_id'     => $numberConfig['skill_id'] ?? null,
                    'flow_id'      => $numberConfig['flow_id'] ?? null,
                    'transfer_to'  => ($numberConfig['transfer_to'] ?? null) ?: null,
                ] : null,
            ],
            'created_at'       => $now,
            'update_at'        => $now,
        ]);

        // Pick the BotAgent before the caller can branch anywhere. Sets
        // session.agent_id, session.skill_id and session.voice_id (cloned
        // from the agent), so the greeting and every reply use one voice.
        $this->router->assignToSession($project, $session);
        $session->refresh();

        // The routed agent's call language decides what the AI listens for
        // and how it replies: metadata.call for the prompt and greeting,
        // metadata.language ("en", "ur", "es" … or "auto") for listening.
        $agent = $session->agent_id ? BotAgent::find($session->agent_id) : null;
        $call  = ($agent ?? new BotAgent())->callSettings();
        $meta  = (array) $session->metadata;
        $meta['call']     = $call;
        $meta['language'] = BotAgent::sttLanguage($call);
        $session->metadata = $meta;
        $session->save();

        return $session;
    }
}
