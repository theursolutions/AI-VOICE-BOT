<?php

namespace Msd\MetaChannels\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Contracts\HandlesInboundMessage;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;
use Msd\MetaChannels\Support\InboundMessage;

/**
 * Run one inbound message through the host app's handler, then send the
 * reply back via the Graph API. Queued so the webhook answers Meta fast.
 *
 * The package never imports the CRM brain — it calls the bound
 * HandlesInboundMessage contract and sends whatever text it returns.
 */
class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 5;

    public function __construct(public InboundMessage $message) {}

    public function handle(HandlesInboundMessage $handler): void
    {
        $this->enrichSenderProfile();

        $graph = new GraphClient($this->message->accessToken, $this->message->graphBase);

        // Show "typing…" BEFORE running the handler, not after.
        //
        // The handler is the slow part — RAG lookups and an LLM call take a
        // few seconds, which is exactly the silence that makes a customer
        // wonder whether anything received their message. Sent here rather
        // than on webhook receipt because by this point we know a reply is
        // actually being produced, which is Meta's stated condition for
        // showing it at all.
        $this->showTyping($graph);

        $reply = $handler->handle($this->message);

        if ($reply === null || trim($reply) === '') {
            Log::info('MetaChannels: handler returned no reply', ['from' => $this->message->from]);
            return;
        }

        if ($this->message->provider === ChannelConnection::PROVIDER_WHATSAPP) {
            // WhatsApp Cloud API
            $graph->sendText($this->message->channelExternalId, $this->message->from, $reply);
        } else {
            // Messenger Platform (Facebook Page / Instagram)
            $graph->sendMessengerText($this->message->channelExternalId, $this->message->from, $reply);
        }
    }

    /**
     * Best-effort typing indicator. Never allowed to break the reply.
     *
     * WhatsApp clears it automatically after 25 seconds or when we send;
     * Messenger after 20. Neither needs turning off, so there is no cleanup
     * path to get wrong if the handler throws.
     */
    private function showTyping(GraphClient $graph): void
    {
        try {
            if ($this->message->provider === ChannelConnection::PROVIDER_WHATSAPP) {
                // Doubles as the read receipt — Meta requires status:read on
                // the same call, so the customer gets blue ticks too.
                $graph->sendTypingIndicator($this->message->channelExternalId, (string) $this->message->messageId);
            } else {
                $graph->sendMessengerTyping($this->message->channelExternalId, $this->message->from);
            }
        } catch (\Throwable $e) {
            Log::info('MetaChannels: typing indicator failed: ' . $e->getMessage());
        }
    }

    /**
     * Messenger/Instagram webhooks don't carry the sender's name (WhatsApp
     * does). Look it up via the Graph API and cache it so we don't refetch
     * on every message from the same user.
     */
    private function enrichSenderProfile(): void
    {
        $m = $this->message;
        if ($m->provider === ChannelConnection::PROVIDER_WHATSAPP || $m->senderName) {
            return;
        }
        $key = "meta:profile:{$m->provider}:{$m->from}";

        $profile = Cache::get($key);
        if ($profile === null) {
            $profile = (new GraphClient($m->accessToken, $m->graphBase))->getUserProfile($m->from, $m->provider) ?? [];

            // Short TTL on a miss so the inbox heals by itself once the
            // permission lands, instead of showing bare ids for another day.
            Cache::put($key, $profile, empty($profile['name']) && empty($profile['profile_pic'])
                ? now()->addMinutes(10)
                : now()->addHours(24));
        }
        if (!empty($profile['name'])) {
            $m->senderName = $profile['name'];
        }
        if (!empty($profile['profile_pic'])) {
            $m->profilePic = $profile['profile_pic'];
        }
    }
}
