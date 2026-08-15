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
        private ContactAvatars $avatars,
        private ComplianceGuard $guard,
        private \App\Services\Crm\ContactResolver $contacts,
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
                    'provider'   => $m->provider,
                    'channel_id' => $m->channelExternalId,
                ])],
                'created_at'       => $now,
                'update_at'        => $now,
            ]);
            $this->router->assignToSession($project, $session);
            $session->refresh();
        }

        // Who is this, across every channel? Resolved on EVERY message, not
        // just on session creation, because the linking moment is usually
        // later — the customer gives an email mid-conversation and that is
        // what reveals they already exist under a WhatsApp number.
        $contact = $this->contacts->resolve(
            projectId:      $m->projectId,
            channel:        $channel,
            externalId:     $m->from,
            channelAccount: $m->channelExternalId,
            details:        array_filter([
                'name'  => $m->senderName ?: $session->customer_name,
                'phone' => $session->customer_phone,
                'email' => $session->customer_email,
            ]),
        );

        if ($contact && (int) $session->contact_id !== (int) $contact->id) {
            $session->contact_id = $contact->id;
            $session->save();
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
                // Resolved to a preview at write time rather than at render
                // time. The alternative is a lookup per message every time
                // the thread is opened, to reconstruct something that can
                // never change once written.
                'reply_to'    => $this->resolveQuoted($session->id, $m->replyToExternalId),
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
        // The avatar is DOWNLOADED, not linked. Meta's profile_pic is a signed
        // CDN URL that expires within days, so the obvious implementation —
        // store the URL, render it later — gives you an inbox of broken images
        // that all worked on the day the conversation started.
        //
        // Cheap in practice: needsRefresh() compares the URL path with the
        // signature stripped, so the daily profile lookup re-signing the same
        // photo does not trigger a re-download.
        $metaBag = (array) data_get($session->metadata, 'meta', []);

        if ($this->avatars->needsRefresh($metaBag, $m->profilePic)) {
            $stored = $this->avatars->store($m->profilePic, $m->provider, $m->from);
            if ($stored) {
                $meta = (array) $session->metadata;
                $meta['meta'] = array_merge($metaBag, [
                    'avatar'     => $stored,
                    'avatar_src' => $m->profilePic,
                    'avatar_at'  => $now,
                ]);
                $session->metadata = $meta;
            }
        }
        $session->save();

        // ── Compliance, before anything is sent ──────────────────────
        //
        // Applied here because every Meta channel funnels through this
        // method, so WhatsApp, Messenger and Instagram get identical
        // treatment without three copies of the rules.

        // 1. "STOP" outranks everything, including a human agent's session.
        //    Continuing to message someone who asked you to stop is the
        //    fastest route to a block and a policy violation in its own right.
        if ($this->guard->isOptOut($text)) {
            $this->mergeSessionMeta($session, ['opted_out' => true, 'opted_out_at' => $now]);
            Log::info('Meta: contact opted out', ['session' => $session->id, 'channel' => $channel]);

            // One confirmation, then silence. Saying nothing leaves the
            // customer unsure it worked, and an unsure customer blocks the
            // number to make certain.
            return $this->guard->optOutConfirmation();
        }

        // 2. Coming back is always allowed — opt-out must be reversible.
        if ($this->guard->isOptedOut($session)) {
            if ($this->guard->isOptIn($text)) {
                $this->mergeSessionMeta($session, ['opted_out' => false, 'opted_out_at' => null]);
            } else {
                // Stay silent. The message is still stored, so an agent can
                // see it, but nothing is sent back.
                return null;
            }
        }

        // If a human agent has taken over this conversation, store the
        // inbound message but don't let the bot reply.
        if (data_get($session->metadata, 'meta.bot_paused')) {
            return null;
        }

        // 3. Hand to a human when asked, or when the bot has clearly stopped
        //    helping. Meta requires a "prompt, clear and direct escalation
        //    path", and a customer held in a loop is the one who blocks.
        if ($this->guard->shouldEscalate($session, $text)) {
            $this->mergeSessionMeta($session, ['bot_turns' => 0]);
            app(\App\Services\Conversation\HumanRouter::class)->handoff($session);

            return 'Let me get a colleague to help — one moment.';
        }

        $reply = $this->conversation->handle($session, $userMessage, 'text');

        // Count consecutive bot turns so rule 3 can fire before frustration
        // does. Reset whenever a person takes over (see ChatController).
        $this->mergeSessionMeta($session, ['bot_turns' => $this->guard->botTurns($session) + 1]);

        return $reply->content ?? null;
    }

    /** Merge keys into `metadata.meta` without clobbering the rest. */
    private function mergeSessionMeta(Session $session, array $values): void
    {
        $meta = (array) $session->metadata;
        $meta['meta'] = array_merge((array) ($meta['meta'] ?? []), $values);
        $session->metadata = $meta;
        $session->save();
    }

    /**
     * Turn a quoted provider id into the preview the thread renders.
     *
     * Scoped to the session and bounded, because a wamid is only meaningful
     * inside the conversation it belongs to, and an unbounded scan would grow
     * with the thread. Returns null when the quoted message predates us —
     * common, and not worth reporting.
     *
     * @return array{id:int, preview:string, author:string}|null
     */
    private function resolveQuoted(int $sessionId, ?string $externalId): ?array
    {
        if (! $externalId) {
            return null;
        }

        $quoted = Message::where('session_id', $sessionId)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'role', 'content', 'metadata'])
            ->first(fn (Message $m) => data_get($m->metadata, 'wamid') === $externalId);

        if (! $quoted) {
            return null;
        }

        return [
            'id'      => $quoted->id,
            'preview' => mb_substr((string) ($quoted->content ?: '📎 Attachment'), 0, 90),
            'author'  => $quoted->role === 'user' ? 'customer' : 'agent',
        ];
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
            $graph = new GraphClient($m->accessToken, $m->graphBase);
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
