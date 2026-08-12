<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\HumanRouter;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Msd\MetaChannels\MetaManager;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Services\GraphClient;

/**
 * Admin omnichannel chat console for the Meta channels (WhatsApp / IG / FB).
 * Lists conversations across every connected number/page, shows a thread,
 * and lets a human agent reply (routed out the SAME connection), toggle the
 * bot per conversation, and respect Meta's 24h service window.
 */
class ChatController extends Controller
{
    private const META_CHANNELS = ['whatsapp', 'instagram', 'facebook', 'messenger'];

    public function __construct(
        private TenantManager $tenants,
        private MetaManager $meta,
        private PythonClient $python,
    ) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name']);
        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        return view('chat.index', compact('client', 'projects', 'project', 'projectId'));
    }

    /** JSON conversation list (used for initial load + polling). */
    public function conversations(Request $request, Client $client): JsonResponse
    {
        $project = $this->guard($client, (int) $request->query('project_id'));
        $now = time();

        $filter = $request->query('filter', 'all');     // all | mine | queue
        $mine = $this->myAgent($project);

        $q = Session::where('project_id', $project->id)
            ->whereIn('channel', self::META_CHANNELS)
            ->orderByDesc('last_activity_at');
        if ($filter === 'mine' && $mine) {
            $q->where('assigned_agent_id', $mine->id);
        } elseif ($filter === 'queue') {
            $q->where('handoff_status', 'queued');
        }
        $sessions = $q->limit(200)->get();

        // Resolve assigned agent names in one query.
        $agentNames = BotAgent::whereIn('id', $sessions->pluck('assigned_agent_id')->filter()->unique())
            ->pluck('name', 'id');

        $out = $sessions->map(function (Session $s) use ($now, $agentNames, $mine) {
            $last = Message::where('session_id', $s->id)->orderByDesc('id')->first(['role', 'content', 'created_at']);
            $meta = (array) data_get($s->metadata, 'meta', []);
            return [
                'id'              => $s->id,
                'channel'         => $s->channel,
                'channel_account' => $s->channel_account,
                'name'            => $s->customer_name ?: $s->external_id,
                'avatar'          => $meta['profile_pic'] ?? null,
                'profile_url'     => $this->profileUrl($s, $meta),
                'last_message'    => $this->preview($last),
                'last_at'         => $s->last_activity_at,
                'window_open'     => $this->meta->serviceWindowOpen($s->last_inbound_at, $now),
                'window_expires'  => $this->meta->serviceWindowExpiresAt($s->last_inbound_at),
                'unread'          => (int) ($s->last_inbound_at ?? 0) > (int) ($meta['read_at'] ?? 0),
                // A dot only said "something new"; a count says how much is
                // waiting, which is what decides who an agent opens first.
                'unread_count'    => Message::where('session_id', $s->id)
                    ->where('role', 'user')
                    ->where('created_at', '>', (int) ($meta['read_at'] ?? 0))
                    ->count(),
                'bot_paused'      => (bool) ($meta['bot_paused'] ?? false),
                'handoff'         => $s->handoff_status ?: 'bot',
                'assigned_to'     => $s->assigned_agent_id ? ($agentNames[$s->assigned_agent_id] ?? 'Agent') : null,
                'mine'            => $mine && (int) $s->assigned_agent_id === (int) $mine->id,
            ];
        })->values();

        return response()->json([
            'conversations' => $out,
            'me'            => $mine ? ['id' => $mine->id, 'presence' => $mine->presence] : null,
        ]);
    }

    /** JSON thread + mark read. */
    public function messages(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $project = $this->guard($client, (int) $request->query('project_id'));
        $session = $this->session($project, $sessionId);

        $after = (int) $request->query('after', 0);
        $msgs = Message::where('session_id', $session->id)
            ->when($after, fn ($q) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'role', 'content', 'metadata', 'created_at']);

        // Mark read.
        $this->mergeMeta($session, ['read_at' => time()]);

        return response()->json([
            'messages' => $msgs->map(fn (Message $m) => $this->shapeMessage($m, $sessionId))->values(),
            'window_open'    => $this->meta->serviceWindowOpen($session->last_inbound_at),
            'window_expires' => $this->meta->serviceWindowExpiresAt($session->last_inbound_at),
            'bot_paused'     => (bool) data_get($session->metadata, 'meta.bot_paused', false),
            'handoff'        => $session->handoff_status ?: 'bot',
            'assigned_to'    => $session->assigned_agent_id ? (BotAgent::find($session->assigned_agent_id)->name ?? 'Agent') : null,
            'is_human_agent' => (bool) $this->myAgent($project),
            'contact'        => [
                'name'        => $session->customer_name ?: $session->external_id,
                'channel'     => $session->channel,
                'account'     => $session->channel_account,
                'avatar'      => data_get($session->metadata, 'meta.profile_pic'),
                'profile_url' => $this->profileUrl($session, (array) data_get($session->metadata, 'meta', [])),
                // Which of your Pages/numbers this conversation arrived on.
                // With several connected channels, "who is this?" is only half
                // the question — "and which of ours did they message?" matters
                // just as much when replying.
                'channel_name' => $this->channelName($session),
                'channel_url'  => $this->channelUrl($session),
            ],
        ]);
    }

    /** Human agent text reply — routed out the conversation's own connection. */
    public function reply(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'text'       => 'required|string|max:4000',
            'reply_to'   => 'nullable|integer',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);

        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn) {
            return response()->json(['error' => 'no_connection', 'message' => 'This channel is no longer connected.'], 422);
        }

        // Resolve a quoted reply target (the original message's provider id).
        $contextWamid = null;
        $replyMeta = null;
        if (!empty($data['reply_to'])) {
            $target = Message::where('session_id', $session->id)->find($data['reply_to']);
            if ($target) {
                $contextWamid = data_get($target->metadata, 'wamid');
                $replyMeta = [
                    'id'      => $target->id,
                    'preview' => mb_substr((string) ($target->content ?: '📎 Attachment'), 0, 90),
                    'author'  => $target->role === 'user' ? 'customer' : (data_get($target->metadata, 'author') === 'agent' ? 'agent' : 'bot'),
                ];
            }
        }

        $graph = new GraphClient($conn->access_token ?: null);
        $open  = $this->meta->serviceWindowOpen($session->last_inbound_at);
        $to    = $session->external_id;

        if ($provider === ChannelConnection::PROVIDER_WHATSAPP) {
            if (!$open) {
                return response()->json([
                    'error'   => 'window_expired',
                    'message' => 'The 24-hour window has closed. Send an approved template to re-open the chat.',
                ], 409);
            }
            $wamid = $graph->sendText($session->channel_account, $to, $data['text'], $contextWamid);
        } else {
            // Messenger/IG: outside 24h the HUMAN_AGENT tag extends to 7 days.
            // (Quoted replies aren't supported by the Messenger send API.)
            $wamid = $graph->sendMessengerText($session->channel_account, $to, $data['text'], $open ? null : 'HUMAN_AGENT');
        }

        if ($wamid === null) {
            return response()->json(['error' => 'send_failed', 'message' => 'Meta rejected the message.'], 502);
        }

        $msg = $this->persistOutbound($session, $data['text'], [], $wamid, $replyMeta);
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /**
     * Edit an agent's own sent text within 15 min (WhatsApp's edit rule).
     * NOTE: the WhatsApp Cloud API has no business message-edit endpoint, so
     * this corrects the console transcript; it does not change the message
     * already delivered to the customer's phone.
     */
    public function editMessage(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'message_id' => 'required|integer',
            'text'       => 'required|string|max:4000',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);

        $msg = Message::where('session_id', $session->id)->findOrFail($data['message_id']);
        if ($msg->role !== 'assistant' || data_get($msg->metadata, 'author') !== 'agent') {
            return response()->json(['error' => 'not_editable', 'message' => 'Only your own agent messages can be edited.'], 422);
        }
        if ((int) $msg->created_at < time() - 900) {
            return response()->json(['error' => 'edit_window', 'message' => 'Messages can only be edited within 15 minutes.'], 422);
        }

        $meta = (array) $msg->metadata;
        $meta['original'] = $meta['original'] ?? $msg->content;
        $meta['edited'] = true;
        $meta['edited_at'] = time();
        $msg->content = $data['text'];
        $msg->metadata = $meta;
        $msg->save();

        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 200);
    }

    /** Agent uploads a file → send as media (WhatsApp: upload+send; IG/FB: by URL — Phase 2). */
    public function sendMedia(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'file'       => 'required|file|max:16384',   // 16MB
            'caption'    => 'nullable|string|max:1024',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn) {
            return response()->json(['error' => 'no_connection'], 422);
        }

        $file = $request->file('file');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $type = $this->mediaType($mime);
        $graph = new GraphClient($conn->access_token ?: null);

        $bytes    = $file->get();
        $filename = $file->getClientOriginalName() ?: 'file';

        // Instagram / Facebook: upload a reusable attachment, then send it.
        if ($provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            $mtype = $type === 'document' ? 'file' : $type;
            $attachmentId = $graph->uploadMessengerAttachment($session->channel_account, $bytes, $mtype, $filename, $mime);
            if (!$attachmentId) {
                return response()->json(['error' => 'upload_failed'], 502);
            }
            $open = $this->meta->serviceWindowOpen($session->last_inbound_at);
            $ok = $graph->sendMessengerAttachmentById($session->channel_account, $session->external_id, $mtype, $attachmentId, $open ? null : 'HUMAN_AGENT');
            if (!$ok) {
                return response()->json(['error' => 'send_failed'], 502);
            }
            $msg = $this->persistOutbound($session, $data['caption'] ?? '', [array_filter([
                'type' => $type, 'mime' => $mime, 'filename' => $filename, 'outbound' => true,
                'url'  => $this->storeOutboundMedia($session, $bytes, $mime, $filename),
            ], fn ($v) => $v !== null)]);
            return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
        }

        // WhatsApp from here.
        if (!$this->meta->serviceWindowOpen($session->last_inbound_at)) {
            return response()->json(['error' => 'window_expired', 'message' => 'Window closed — use a template.'], 409);
        }

        // WhatsApp voice notes must be ogg/opus. Browsers record webm/opus,
        // so remux via the voice-engine. Same codec → fast container swap.
        if ($type === 'audio' && !str_contains($mime, 'ogg')) {
            $ogg = $this->python->transcodeAudio($bytes, $filename, 'ogg');
            if ($ogg) {
                $bytes = $ogg;
                $mime = 'audio/ogg';
                $filename = 'voice-note.ogg';
            } else {
                Log::warning('Chat: voice-note transcode failed; sending original (WhatsApp may reject).');
            }
        }

        $mediaId = $graph->uploadWhatsAppMedia($session->channel_account, $bytes, $mime, $filename);
        if (!$mediaId) {
            return response()->json(['error' => 'upload_failed'], 502);
        }
        $ok = $graph->sendWhatsAppMediaById(
            $session->channel_account, $session->external_id, $type, $mediaId,
            $data['caption'] ?? null, $file->getClientOriginalName(),
        );
        if (!$ok) {
            return response()->json(['error' => 'send_failed'], 502);
        }

        $msg = $this->persistOutbound($session, $data['caption'] ?? '', [array_filter([
            'type'     => $type,
            'mime'     => $mime,
            'filename' => $file->getClientOriginalName(),
            'outbound' => true,
            'media_id' => $mediaId,   // lets the proxy route re-fetch if needed
            'url'      => $this->storeOutboundMedia($session, $bytes, $mime, $filename),
        ], fn ($v) => $v !== null)]);
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /** Send an approved WhatsApp template (re-opens an expired window). */
    public function sendTemplate(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'template'   => 'required|string|max:191',
            'language'   => 'nullable|string|max:16',
            'params'     => 'nullable|array',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn || $provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            return response()->json(['error' => 'whatsapp_only'], 422);
        }

        $components = [];
        if (!empty($data['params'])) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $data['params']),
            ];
        }
        $ok = (new GraphClient($conn->access_token ?: null))->sendTemplate(
            $session->channel_account, $session->external_id, $data['template'], $data['language'] ?? 'en_US', $components,
        );
        if (!$ok) {
            return response()->json(['error' => 'send_failed'], 502);
        }
        $msg = $this->persistOutbound($session, '[template: ' . $data['template'] . ']');
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /** Send interactive reply buttons (capture intent / kick off order). */
    public function sendInteractive(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id'      => 'required|integer',
            'body'            => 'required|string|max:1024',
            'buttons'         => 'required|array|min:1|max:3',
            'buttons.*.id'    => 'required|string|max:191',
            'buttons.*.title' => 'required|string|max:20',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn || $provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            return response()->json(['error' => 'whatsapp_only'], 422);
        }
        if (!$this->meta->serviceWindowOpen($session->last_inbound_at)) {
            return response()->json(['error' => 'window_expired'], 409);
        }
        $ok = (new GraphClient($conn->access_token ?: null))->sendInteractiveButtons(
            $session->channel_account, $session->external_id, $data['body'], $data['buttons'],
        );
        if (!$ok) {
            return response()->json(['error' => 'send_failed'], 502);
        }
        $msg = $this->persistOutbound($session, $data['body'] . "\n[" . implode(' · ', array_column($data['buttons'], 'title')) . ']');
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /** List the WABA's approved templates for the template picker. */
    public function templates(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $project = $this->guard($client, (int) $request->query('project_id'));
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn || $provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            return response()->json(['templates' => []]);
        }
        $waba = data_get($conn->metadata, 'waba_id') ?: config('meta.whatsapp.business_account_id');
        if (!$waba) {
            return response()->json(['templates' => [], 'note' => 'No WABA id on this channel — add it on the Channels page to list templates.']);
        }

        $raw = (new GraphClient($conn->access_token ?: null))->listTemplates((string) $waba);
        $templates = collect($raw)
            ->filter(fn ($t) => ($t['status'] ?? '') === 'APPROVED')
            ->map(fn ($t) => [
                'name'     => $t['name'] ?? '',
                'language' => $t['language'] ?? 'en_US',
                'category' => $t['category'] ?? null,
                'params'   => $this->templateBodyParamCount($t),
            ])->values();

        return response()->json(['templates' => $templates]);
    }

    /** Send a published WhatsApp Flow (in-chat form) to capture data. */
    public function sendFlow(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'flow_id'    => 'required|string|max:191',
            'cta'        => 'required|string|max:20',
            'body'       => 'required|string|max:1024',
            'screen'     => 'nullable|string|max:191',
            'header'     => 'nullable|string|max:60',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn || $provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            return response()->json(['error' => 'whatsapp_only'], 422);
        }
        if (!$this->meta->serviceWindowOpen($session->last_inbound_at)) {
            return response()->json(['error' => 'window_expired'], 409);
        }
        $ok = (new GraphClient($conn->access_token ?: null))->sendFlow(
            $session->channel_account, $session->external_id,
            $data['flow_id'], $data['cta'], $data['body'],
            'sess' . $session->id, $data['screen'] ?? null, $data['header'] ?? null,
        );
        if (!$ok) {
            return response()->json(['error' => 'send_failed'], 502);
        }
        $msg = $this->persistOutbound($session, $data['body'] . "\n[form: {$data['cta']}]");
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /** Send catalog product(s) from a WhatsApp catalog. */
    public function sendProduct(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'catalog_id'   => 'required|string|max:191',
            'retailer_ids' => 'required|array|min:1',
            'retailer_ids.*' => 'string|max:191',
            'body'         => 'nullable|string|max:1024',
            'header'       => 'nullable|string|max:60',
        ]);
        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);
        [$conn, $provider] = $this->connectionFor($session);
        if (!$conn || $provider !== ChannelConnection::PROVIDER_WHATSAPP) {
            return response()->json(['error' => 'whatsapp_only'], 422);
        }
        if (!$this->meta->serviceWindowOpen($session->last_inbound_at)) {
            return response()->json(['error' => 'window_expired'], 409);
        }

        $graph = new GraphClient($conn->access_token ?: null);
        $ids = array_values($data['retailer_ids']);
        if (count($ids) === 1) {
            $ok = $graph->sendProduct($session->channel_account, $session->external_id, $data['catalog_id'], $ids[0], $data['body'] ?? null);
        } else {
            $sections = [['title' => mb_substr($data['header'] ?? 'Products', 0, 24), 'product_items' => array_map(fn ($r) => ['product_retailer_id' => $r], $ids)]];
            $ok = $graph->sendProductList($session->channel_account, $session->external_id, $data['catalog_id'], $data['header'] ?? 'Our products', $data['body'] ?? 'Take a look', $sections);
        }
        if (!$ok) {
            return response()->json(['error' => 'send_failed'], 502);
        }
        $msg = $this->persistOutbound($session, '🛒 ' . ($data['body'] ?? 'Product catalog') . ' (' . count($ids) . ' item' . (count($ids) > 1 ? 's' : '') . ')');
        return response()->json(['message' => $this->shapeMessage($msg, $sessionId)], 201);
    }

    /** Pause/resume the bot for one conversation (human takeover). */
    public function toggleBot(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $project = $this->guard($client, (int) $request->input('project_id'));
        $session = $this->session($project, $sessionId);
        $paused = !data_get($session->metadata, 'meta.bot_paused', false);
        $this->mergeMeta($session, ['bot_paused' => $paused]);
        return response()->json(['bot_paused' => $paused]);
    }

    /** Stream an inbound media attachment (proxies WhatsApp media_id / URLs). */
    public function media(Request $request, Client $client, int $sessionId, int $messageId, int $index): Response
    {
        $project = $this->guard($client, (int) $request->query('project_id'));
        $session = $this->session($project, $sessionId);
        $msg = Message::where('session_id', $session->id)->findOrFail($messageId);
        $att = data_get($msg->metadata, 'attachments.' . $index);
        abort_unless($att, 404);

        [$conn] = $this->connectionFor($session);
        $graph = new GraphClient($conn->access_token ?? null);
        $media = !empty($att['media_id'])
            ? $graph->downloadWhatsAppMedia($att['media_id'])
            : (!empty($att['url']) ? $graph->downloadUrl($att['url'], $att['mime'] ?? null) : null);

        abort_unless($media && !empty($media['bytes']), 404);
        return response($media['bytes'], 200)->header('Content-Type', $media['mime'] ?? 'application/octet-stream');
    }

    /** Set the logged-in human agent's presence (online/away/offline). */
    public function presence(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate(['project_id' => 'required|integer', 'status' => 'required|in:online,away,offline']);
        $project = $this->guard($client, (int) $data['project_id']);
        $mine = $this->myAgent($project);
        if (!$mine) {
            return response()->json(['error' => 'not_agent', 'message' => 'You are not set up as a human agent on this project.'], 422);
        }
        $mine->presence = $data['status'];
        $mine->update_at = time();
        $mine->save();
        if ($data['status'] === 'online') {
            app(HumanRouter::class)->assignQueued($project->id);   // pull waiting chats
        }
        return response()->json(['ok' => true, 'presence' => $mine->presence]);
    }

    /** Take a queued/unassigned chat for myself. */
    public function claim(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $project = $this->guard($client, (int) $request->input('project_id'));
        $session = $this->session($project, $sessionId);
        $mine = $this->myAgent($project);
        if (!$mine) {
            return response()->json(['error' => 'not_agent'], 422);
        }
        $session->assigned_agent_id = $mine->id;
        $session->handoff_status = 'assigned';
        $session->update_at = time();
        $session->save();
        $this->mergeMeta($session, ['bot_paused' => true]);
        return response()->json(['ok' => true]);
    }

    /** Resolve a handled chat — hand control back to the AI + drain the queue. */
    public function resolve(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $project = $this->guard($client, (int) $request->input('project_id'));
        $session = $this->session($project, $sessionId);
        $session->handoff_status = 'resolved';
        $session->update_at = time();
        $session->save();
        $this->mergeMeta($session, ['bot_paused' => false]);
        app(HumanRouter::class)->assignQueued($project->id);
        return response()->json(['ok' => true]);
    }

    // -- helpers ------------------------------------------------------------

    private function myAgent(Project $project): ?BotAgent
    {
        return BotAgent::where('project_id', $project->id)
            ->where('type', BotAgent::TYPE_HUMAN)
            ->where('user_id', auth()->id())
            ->first();
    }

    private function connectionFor(Session $session): array
    {
        $provider = (string) data_get($session->metadata, 'meta.provider', match ($session->channel) {
            'instagram' => 'instagram',
            'facebook', 'messenger' => 'facebook_page',
            default => 'whatsapp',
        });
        return [$this->meta->resolveConnection($provider, (string) $session->channel_account), $provider];
    }

    /**
     * Keep a local copy of outbound media and return its public URL.
     *
     * Outbound attachments used to store only type/mime/filename, so
     * shapeMessage() fell through to the Graph proxy route, which has no
     * media_id to fetch for our own uploads — the agent saw a broken image
     * while the customer received the file perfectly.
     *
     * Storing it ourselves also means the thread still renders months later,
     * after Meta has expired the media and the page token has rotated.
     */
    private function storeOutboundMedia(Session $session, string $bytes, string $mime, string $filename): ?string
    {
        try {
            $ext  = pathinfo($filename, PATHINFO_EXTENSION) ?: $this->extForMime($mime);
            $name = 'chat/' . $session->id . '/' . uniqid('out-', true) . ($ext ? '.' . $ext : '');
            \Illuminate\Support\Facades\Storage::disk('public')->put($name, $bytes);

            return \Illuminate\Support\Facades\Storage::disk('public')->url($name);
        } catch (\Throwable $e) {
            // Never fail a send because we could not keep our own copy — the
            // message has already gone to the customer by this point.
            Log::warning('Chat: could not store outbound media locally: ' . $e->getMessage());

            return null;
        }
    }

    /** Best-effort extension for a mime type, for the stored filename. */
    private function extForMime(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png')  => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif')  => 'gif',
            str_contains($mime, 'ogg')  => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'mp4')  => 'mp4',
            str_contains($mime, 'pdf')  => 'pdf',
            default => '',
        };
    }

    private function persistOutbound(Session $session, string $content, array $attachments = [], ?string $wamid = null, ?array $replyTo = null): Message
    {
        $now = time();
        $msg = Message::create([
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'role'       => 'assistant',
            'content'    => $content !== '' ? $content : null,
            'metadata'   => array_filter([
                'author'      => 'agent',
                'attachments' => $attachments ?: null,
                'wamid'       => $wamid,
                'reply_to'    => $replyTo,
            ]),
            'created_at' => $now,
        ]);
        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();
        return $msg;
    }

    private function shapeMessage(Message $m, int $sessionId): array
    {
        $author = data_get($m->metadata, 'author');
        return [
            'id'          => $m->id,
            'direction'   => $m->role === 'user' ? 'in' : 'out',
            'author'      => $m->role === 'user' ? 'customer' : ($author === 'agent' ? 'agent' : 'bot'),
            'content'     => $m->content,
            'reply'       => data_get($m->metadata, 'reply_to'),
            'edited'      => (bool) data_get($m->metadata, 'edited'),
            'attachments' => array_map(function ($a, $i) use ($sessionId, $m) {
                // Public URLs (Messenger CDN, demo assets) render directly;
                // WhatsApp media_ids must be proxied (auth + no public URL).
                $a['proxy'] = !empty($a['url'])
                    ? $a['url']
                    : route('chat.media', ['client' => request()->route('client'), 'sessionId' => $sessionId, 'messageId' => $m->id, 'index' => $i]);
                return $a;
            }, (array) data_get($m->metadata, 'attachments', []), array_keys((array) data_get($m->metadata, 'attachments', []))),
            'created_at'  => $m->created_at,
        ];
    }

    /** Count {{n}} placeholders in a template's BODY component. */
    private function templateBodyParamCount(array $tpl): int
    {
        foreach (($tpl['components'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'BODY' && !empty($c['text'])) {
                preg_match_all('/\{\{\s*\d+\s*\}\}/', $c['text'], $m);
                return count($m[0]);
            }
        }
        return 0;
    }

    /** Display name of the Page / number this conversation arrived on. */
    private function channelName(Session $session): ?string
    {
        [$conn] = $this->connectionFor($session);

        return $conn?->name ?: $session->channel_account;
    }

    /**
     * Public link to our own Page / number.
     *
     * Unlike a customer's PSID, a Page id IS public and resolvable, so this
     * one can be linked reliably.
     */
    private function channelUrl(Session $session): ?string
    {
        [$conn] = $this->connectionFor($session);
        $account = (string) $session->channel_account;

        return match ($session->channel) {
            'facebook', 'messenger' => $account !== '' ? 'https://facebook.com/' . $account : null,
            'whatsapp' => ($n = data_get($conn?->metadata, 'display_phone_number'))
                ? 'https://wa.me/' . preg_replace('/\D+/', '', (string) $n)
                : null,
            'instagram' => ($u = data_get($conn?->metadata, 'username'))
                ? 'https://instagram.com/' . ltrim((string) $u, '@')
                : null,
            default => null,
        };
    }

    /**
     * A link to the customer's profile, where one genuinely exists.
     *
     * Only WhatsApp can be linked reliably. Messenger and Instagram identify
     * senders by a PSID/IGSID, which is **page-scoped** — a different opaque
     * id per business, deliberately not resolvable to a public profile. There
     * is no URL to build, and guessing one produces a broken link that looks
     * like our bug rather than Meta's design.
     *
     * Instagram becomes linkable once we store the username from the profile
     * lookup; that waits until Instagram onboarding itself works.
     */
    private function profileUrl(Session $s, array $meta): ?string
    {
        return match ($s->channel) {
            'whatsapp' => ($digits = preg_replace('/\D+/', '', (string) $s->external_id)) !== ''
                ? 'https://wa.me/' . $digits
                : null,
            'instagram' => ! empty($meta['username'])
                ? 'https://instagram.com/' . ltrim((string) $meta['username'], '@')
                : null,
            default => null,
        };
    }

    private function preview(?Message $m): string
    {
        if (!$m) {
            return '';
        }
        $t = (string) ($m->content ?? '');
        return $t !== '' ? mb_substr($t, 0, 60) : '📎 Attachment';
    }

    private function mediaType(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'document',
        };
    }

    private function mergeMeta(Session $session, array $patch): void
    {
        $metadata = (array) $session->metadata;
        $metadata['meta'] = array_merge((array) ($metadata['meta'] ?? []), $patch);
        $session->metadata = $metadata;
        $session->save();
    }

    private function session(Project $project, int $sessionId): Session
    {
        $s = Session::where('project_id', $project->id)->whereIn('channel', self::META_CHANNELS)->findOrFail($sessionId);
        return $s;
    }

    private function guard(Client $client, int $projectId): Project
    {
        $project = Project::where('client_id', $client->id)->where('id', $projectId)->firstOrFail();
        $this->tenants->useFor($project);
        return $project;
    }
}
