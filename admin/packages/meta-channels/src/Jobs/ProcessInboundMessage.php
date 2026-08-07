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

        $reply = $handler->handle($this->message);

        if ($reply === null || trim($reply) === '') {
            Log::info('MetaChannels: handler returned no reply', ['from' => $this->message->from]);
            return;
        }

        $graph = new GraphClient($this->message->accessToken);

        if ($this->message->provider === ChannelConnection::PROVIDER_WHATSAPP) {
            // WhatsApp Cloud API
            $graph->sendText($this->message->channelExternalId, $this->message->from, $reply);
        } else {
            // Messenger Platform (Facebook Page / Instagram)
            $graph->sendMessengerText($this->message->channelExternalId, $this->message->from, $reply);
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
        $profile = Cache::remember(
            "meta:profile:{$m->provider}:{$m->from}",
            now()->addHours(24),
            fn () => (new GraphClient($m->accessToken))->getUserProfile($m->from, $m->provider) ?? [],
        );
        if (!empty($profile['name'])) {
            $m->senderName = $profile['name'];
        }
        if (!empty($profile['profile_pic'])) {
            $m->profilePic = $profile['profile_pic'];
        }
    }
}
