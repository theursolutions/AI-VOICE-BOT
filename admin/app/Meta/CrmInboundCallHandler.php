<?php

namespace App\Meta;

use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\PythonClient;
use App\Services\Conversation\SessionTokenService;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Contracts\HandlesInboundCall;
use Msd\MetaChannels\Support\InboundCall;

/**
 * Bridges a WhatsApp call's SDP offer to the Python WebRTC media engine.
 * Creates a session, mints the same JWT the WS/phone path uses, relays the
 * offer to the voice-engine, and returns the SDP answer for the package to
 * hand back to Meta (pre_accept + accept).
 */
class CrmInboundCallHandler implements HandlesInboundCall
{
    public function __construct(
        private AgentRouter $router,
        private SessionTokenService $tokens,
        private PythonClient $python,
        private TenantManager $tenants,
    ) {}

    public function answer(InboundCall $c): ?string
    {
        $project = Project::find($c->projectId);
        if (!$project) {
            return null;
        }
        $this->tenants->useFor($project);

        $now = time();
        $session = Session::where('project_id', $c->projectId)
            ->where('channel', 'whatsapp')
            ->where('external_id', $c->callId)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            $session = Session::create([
                'project_id'       => $c->projectId,
                'channel'          => 'whatsapp',
                'channel_account'  => $c->channelExternalId,
                'external_id'      => $c->callId,
                'customer_phone'   => $c->from,
                'status'           => 'active',
                'started_at'       => $now,
                'last_activity_at' => $now,
                'metadata'         => ['whatsapp_call' => [
                    'call_id'         => $c->callId,
                    'phone_number_id' => $c->channelExternalId,
                ]],
                'created_at'       => $now,
                'update_at'        => $now,
            ]);
            $this->router->assignToSession($project, $session);
            $session->refresh();
        }

        $token = $this->tokens->mint($session);

        try {
            return $this->python->whatsappCallOffer($token, $c->callId, $c->sdpOffer);
        } catch (\Throwable $e) {
            Log::error('Meta call: offer relay to voice-engine failed: ' . $e->getMessage());
            return null;
        }
    }

    public function onTerminate(string $callId): void
    {
        try {
            $this->python->whatsappCallTerminate($callId);
        } catch (\Throwable $e) {
            Log::info('Meta call: terminate relay failed: ' . $e->getMessage());
        }
    }
}
