<?php

namespace App\EmailChannel;

use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\ConversationManager;
use App\Services\Conversation\HumanRouter;
use App\Services\Crm\ContactResolver;
use App\Services\Tenant\TenantManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Msd\MailChannel\Contracts\HandlesInboundEmail;
use Msd\MailChannel\Support\InboundEmail;

/**
 * Bridges the mail-channel package to the CRM brain: turns an inbound email
 * into a session turn through ConversationManager (the SAME pipeline every
 * other channel uses, so tools/RAG work automatically) and returns the
 * assistant's reply for the package to send back.
 *
 * Mirrors App\Meta\CrmInboundMessageHandler turn-for-turn — same
 * find-or-create session, same contact resolution, same dedupe-then-reuse
 * shape — with two channel-specific differences: the dedupe key is the
 * RFC822 Message-ID instead of a wamid, and there is no Meta-style 24h
 * service window or opt-out policy to enforce.
 */
class CrmInboundEmailHandler implements HandlesInboundEmail
{
    public function __construct(
        private ConversationManager $conversation,
        private TenantManager $tenants,
        private ContactResolver $contacts,
    ) {}

    public function handle(InboundEmail $m): ?string
    {
        $project = Project::find($m->projectId);
        if (!$project) {
            Log::warning('Email inbound: project not found', ['project_id' => $m->projectId]);
            return null;
        }
        $this->tenants->useFor($project);

        $now = time();

        // Keyed by (project, mailbox, sender) — same convention as WhatsApp:
        // one thread per customer per business channel. A subject line does
        // not fork a new thread; it's stored on the message, not the session.
        $session = Session::where('project_id', $m->projectId)
            ->where('channel', 'email')
            ->where('channel_account', $m->mailbox)
            ->where('external_id', $m->fromEmail)
            ->where('status', 'active')
            ->first();

        if (!$session) {
            $session = Session::create([
                'project_id'       => $m->projectId,
                'channel'          => 'email',
                'channel_account'  => $m->mailbox,
                'external_id'      => $m->fromEmail,
                'customer_name'    => $m->fromName,
                'customer_email'   => $m->fromEmail,
                'status'           => 'active',
                'started_at'       => $now,
                'last_activity_at' => $now,
                'last_inbound_at'  => $now,
                'metadata'         => ['meta' => ['provider' => 'email']],
                'created_at'       => $now,
                'update_at'        => $now,
            ]);
        }

        $contact = $this->contacts->resolve(
            projectId:      $m->projectId,
            channel:        'email',
            externalId:     $m->fromEmail,
            channelAccount: $m->mailbox,
            details:        array_filter([
                'name'  => $m->fromName ?: $session->customer_name,
                'email' => $m->fromEmail,
            ]),
        );

        if ($contact && (int) $session->contact_id !== (int) $contact->id) {
            $session->contact_id = $contact->id;
            $session->save();
        }

        // Dedupe on the RFC822 Message-ID — the email equivalent of a wamid.
        // Reuse the existing row rather than returning early: a retried job
        // that persisted the message and then failed later still needs the
        // rest of this handler to run.
        $existing = $m->messageId
            ? Message::where('session_id', $session->id)
                ->where('metadata->message_id', $m->messageId)
                ->first()
            : null;

        $userMessage = $existing ?: Message::create([
            'session_id' => $session->id,
            'project_id' => $m->projectId,
            'role'       => 'user',
            'content'    => $this->plainText($m),
            'metadata'   => array_filter([
                'source'      => 'email',
                'message_id'  => $m->messageId,
                'in_reply_to' => $m->inReplyTo,
                'references'  => $m->references ?: null,
                'subject'     => $m->subject,
                'to'          => $m->to ?: null,
                'cc'          => $m->cc ?: null,
                'html_body'   => $m->htmlBody,
                'attachments' => $this->attachmentMeta($session, $m) ?: null,
            ]),
            'created_at' => $now,
        ]);

        $session->last_activity_at = $now;
        $session->last_inbound_at  = $now;
        $session->update_at = $now;
        if (!$session->customer_name && $m->fromName) {
            $session->customer_name = $m->fromName;
        }
        $session->save();

        // If a human agent has taken over this conversation, store the
        // inbound message but don't let the bot reply — same rule every
        // other channel follows.
        if (data_get($session->metadata, 'meta.bot_paused')) {
            return null;
        }

        $reply = $this->conversation->handle($session, $userMessage, 'text');

        return $reply->content ?? null;
    }

    /**
     * Body text for the AI: prefer the plain-text part; when a sender only
     * sent HTML, strip tags rather than feeding markup into the model.
     */
    private function plainText(InboundEmail $m): string
    {
        if (trim($m->textBody) !== '') {
            return trim($m->textBody);
        }
        if ($m->htmlBody) {
            return trim(html_entity_decode(strip_tags($m->htmlBody)));
        }
        return '[Email had no readable body]';
    }

    /**
     * Same compact attachment metadata shape CrmInboundMessageHandler uses
     * for WhatsApp media (type/mime/filename/url) — stored locally under the
     * same `chat/{session}/...` public-disk convention as outbound media
     * (ChatController::storeOutboundMedia), so the thread can render/download
     * them without re-fetching from the mailbox.
     */
    private function attachmentMeta(Session $session, InboundEmail $m): array
    {
        return array_values(array_filter(array_map(
            fn (array $att) => array_filter([
                'type'     => 'document',
                'mime'     => $att['mime'] ?? null,
                'filename' => $att['filename'] ?? null,
                'url'      => $this->storeAttachment($session, $att),
            ]),
            $m->attachments,
        )));
    }

    private function storeAttachment(Session $session, array $att): ?string
    {
        try {
            $ext  = pathinfo((string) ($att['filename'] ?? ''), PATHINFO_EXTENSION);
            $name = 'chat/' . $session->id . '/' . uniqid('in-', true) . ($ext ? '.' . $ext : '');
            Storage::disk('public')->put($name, (string) ($att['bytes'] ?? ''));

            return Storage::disk('public')->url($name);
        } catch (\Throwable $e) {
            Log::warning('Email inbound: could not store attachment locally: ' . $e->getMessage());
            return null;
        }
    }
}
