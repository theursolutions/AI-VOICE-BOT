<?php

namespace App\Meta;

use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\AgentRouter;
use App\Services\Conversation\ConversationManager;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;
use Msd\MetaChannels\Contracts\HandlesInboundMessage;
use Msd\MetaChannels\Services\GraphClient;
use Msd\MetaChannels\Support\InboundMessage;

/**
 * Bridges the meta-channels package to the CRM brain: turns an inbound
 * WhatsApp message into a session turn through ConversationManager (the
 * SAME pipeline web chat + phone use, so tools/RAG work automatically) and
 * returns the assistant's reply for the package to send back.
 */
class CrmInboundMessageHandler implements HandlesInboundMessage
{
    public function __construct(
        private ConversationManager $conversation,
        private AgentRouter $router,
        private TenantManager $tenants,
        private PythonClient $python,
    ) {}

    public function handle(InboundMessage $m): ?string
    {
        $project = Project::find($m->projectId);
        if (!$project) {
            Log::warning('Meta inbound: project not found', ['project_id' => $m->projectId]);
            return null;
        }
        $this->tenants->useFor($project);

        $channel = $this->channelFor($m->provider);

        // Turn any attachments into usable text: voice notes are
        // transcribed; other media becomes a short placeholder the LLM can
        // acknowledge. Refs are kept on the message metadata.
        [$text, $attachmentMeta] = $this->resolveText($m);

        $now = time();
        // Key the thread by (project, channel, business account, customer)
        // so the same customer on two of your numbers gets two threads —
        // each replying out the number they used.
        $session = Session::where('project_id', $m->projectId)
            ->where('channel', $channel)
            ->where('channel_account', $m->channelExternalId)
            ->where('external_id', $m->from)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            $session = Session::create([
                'project_id'       => $m->projectId,
                'channel'          => $channel,
                'channel_account'  => $m->channelExternalId,
                'external_id'      => $m->from,
                'customer_name'    => $m->senderName,
                'customer_phone'   => $m->provider === 'whatsapp' ? $m->from : null,
                'status'           => 'active',
                'started_at'       => $now,
                'last_activity_at' => $now,
                'last_inbound_at'  => $now,
                'metadata'         => ['meta' => array_filter([
                    'provider'    => $m->provider,
                    'channel_id'  => $m->channelExternalId,
                    'profile_pic' => $m->profilePic,
                ])],
                'created_at'       => $now,
                'update_at'        => $now,
            ]);
            $this->router->assignToSession($project, $session);
            $session->refresh();
        }

        $userMessage = Message::create([
            'session_id' => $session->id,
            'project_id' => $m->projectId,
            'role'       => 'user',
            'content'    => $text,
            'metadata'   => array_filter([
                'source'      => $m->provider,
                'wamid'       => $m->messageId,
                'attachments' => $attachmentMeta ?: null,
            ]),
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->last_inbound_at  = $now;   // resets Meta's 24h service window
        $session->update_at = $now;
        // Keep the contact name fresh once enrichment fills it in.
        if (!$session->customer_name && $m->senderName) {
            $session->customer_name = $m->senderName;
        }
        // Same for the avatar. Backfilling here matters because Messenger and
        // Instagram profiles are only fetched from Graph, which was added
        // after some conversations already existed — without this they would
        // stay faceless forever.
        if ($m->profilePic && ! data_get($session->metadata, 'meta.profile_pic')) {
            $meta = (array) $session->metadata;
            $meta['meta'] = array_merge((array) ($meta['meta'] ?? []), [
                'profile_pic' => $m->profilePic,
            ]);
            $session->metadata = $meta;
        }
        $session->save();

        // If a human agent has taken over this conversation, store the
        // inbound message but don't let the bot reply.
        if (data_get($session->metadata, 'meta.bot_paused')) {
            return null;
        }

        $reply = $this->conversation->handle($session, $userMessage, 'text');
        return $reply->content ?? null;
    }

    /** Map a Meta provider to the sessions.channel enum value. */
    private function channelFor(string $provider): string
    {
        return match ($provider) {
            'instagram'                => 'instagram',
            'facebook_page', 'messenger' => 'facebook',
            default                    => 'whatsapp',
        };
    }

    /**
     * Resolve the effective user text + a compact attachment metadata list.
     * Voice notes are transcribed (reusing the voice-engine STT); other
     * media becomes a placeholder so the AI knows something was sent.
     *
     * @return array{0:string, 1:array}
     */
    private function resolveText(InboundMessage $m): array
    {
        $text = $m->text;
        $meta = [];

        foreach ($m->attachments as $att) {
            $type = $att['type'] ?? 'file';
            $meta[] = array_filter([
                'type'     => $type,
                'mime'     => $att['mime'] ?? null,
                'filename' => $att['filename'] ?? null,
                'media_id' => $att['media_id'] ?? null,
                'url'      => $att['url'] ?? null,
            ]);

            if ($type === 'audio') {
                $transcript = $this->transcribeAudio($m, $att);
                if ($transcript) {
                    $text = trim($text === '' ? $transcript : "{$text} {$transcript}");
                    continue;
                }
            }
            if (trim($text) === '') {
                $text = $this->placeholderFor($type, $att);
            }
        }

        if (trim($text) === '') {
            $text = '[Customer sent an attachment]';
        }
        return [$text, $meta];
    }

    private function transcribeAudio(InboundMessage $m, array $att): ?string
    {
        try {
            $graph = new GraphClient($m->accessToken);
            $media = !empty($att['media_id'])
                ? $graph->downloadWhatsAppMedia($att['media_id'])
                : (!empty($att['url']) ? $graph->downloadUrl($att['url'], $att['mime'] ?? null) : null);

            if (!$media || empty($media['bytes'])) {
                return null;
            }
            $ext = $this->extFor($media['mime'] ?? ($att['mime'] ?? ''));
            return $this->python->transcribe($media['bytes'], "voice.{$ext}", null);
        } catch (\Throwable $e) {
            Log::warning('Meta: voice-note transcription failed: ' . $e->getMessage());
            return null;
        }
    }

    private function placeholderFor(string $type, array $att): string
    {
        return match ($type) {
            'image', 'sticker' => '[Customer sent an image]',
            'video'            => '[Customer sent a video]',
            'audio'            => '[Customer sent a voice message]',
            'document'         => '[Customer sent a document' . (!empty($att['filename']) ? ': ' . $att['filename'] : '') . ']',
            default            => '[Customer sent an attachment]',
        };
    }

    private function extFor(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a')  => 'm4a',
            str_contains($mime, 'wav')  => 'wav',
            str_contains($mime, 'amr')  => 'amr',
            default                     => 'ogg',
        };
    }
}
