<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\ConversationStatus;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Conversation\HumanRouter;
use App\Services\Conversation\PythonClient;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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

    /**
     * How much of Meta's 24h window counts as "expiring soon".
     *
     * Two hours because that is roughly the shortest notice on which a human
     * can still realistically pick a conversation up and answer it — a
     * shorter warning is just a nicer-looking way of telling you it is
     * already too late.
     */
    private const EXPIRING_SOON_SECONDS = 2 * 3600;

    /**
     * How long Messenger/Instagram replies keep working after the 24h window,
     * using Meta's HUMAN_AGENT tag: 7 days from the customer's last message.
     *
     * WhatsApp has no equivalent — there, closed is closed.
     */
    private const HUMAN_AGENT_WINDOW_SECONDS = 7 * 86400;

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

        // Everything the console can filter on, parsed once.
        $f = $this->parseFilters($request);

        // Facets are counted over the UNFILTERED set, deliberately. A count
        // that shrank to zero as you narrowed would tell you nothing — the
        // point of showing "Instagram 12" is to answer "is it worth clicking?"
        // before you click. Selecting only scalar columns keeps this cheap:
        // no per-session message lookups happen here.
        $facetRows = Session::where('project_id', $project->id)
            ->whereIn('channel', self::META_CHANNELS)
            ->orderByDesc('last_activity_at')
            ->limit(1000)
            // conversation_status_id only when the tenant DB has it: naming a
            // column that does not exist throws, and this feature must degrade
            // rather than take the whole inbox down.
            ->get(array_merge(
                ['id', 'channel', 'channel_account', 'status', 'handoff_status',
                 'assigned_agent_id', 'last_inbound_at', 'last_activity_at', 'metadata'],
                ConversationStatus::available() ? ['conversation_status_id'] : [],
            ));

        $q = Session::where('project_id', $project->id)
            ->whereIn('channel', self::META_CHANNELS)
            ->orderByDesc('last_activity_at');

        if ($filter === 'mine' && $mine) {
            $q->where('assigned_agent_id', $mine->id);
        } elseif ($filter === 'queue') {
            $q->where('handoff_status', 'queued');
        }

        $this->applyFilters($q, $f, $now);

        // Fetched a little over the display cap because the read/unread test
        // reads a JSON key, which is not portable to push into SQL — it is
        // applied below, after the rows are in memory.
        $sessions = $q->limit(300)->get()
            ->filter(fn (Session $s) => $this->matchesReadState($s, $f['read']))
            ->filter(fn (Session $s) => ! $f['needs'] || data_get($s->metadata, 'meta.needs_human'))
            ->take(200)
            ->values();

        // Resolve assigned agent names in one query.
        $agentNames = BotAgent::whereIn('id', $sessions->pluck('assigned_agent_id')->filter()->unique())
            ->pluck('name', 'id');

        // Statuses keyed by id, so each row's pill costs no extra query.
        $statuses = collect($this->statusList($project))->keyBy('id');

        $out = $sessions->map(function (Session $s) use ($now, $agentNames, $mine, $statuses) {
            $last = Message::where('session_id', $s->id)->orderByDesc('id')->first(['role', 'content', 'created_at']);
            $meta = (array) data_get($s->metadata, 'meta', []);
            return [
                'id'              => $s->id,
                'channel'         => $s->channel,
                'channel_account' => $s->channel_account,
                'name'            => $this->contactName($s),
                'avatar'          => $this->avatarUrl($meta),
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
                'state'           => $this->conversationState($s, $now),
                'kind'            => $this->conversationKind($s),
                // Who is on this right now — the AI, or a named person.
                'handler'         => $this->handlerFor($s, $agentNames),
                // The customer asked for a person and none has replied yet.
                'needs_human'     => (bool) ($meta['needs_human'] ?? false),
                'status'          => $statuses[$s->getAttribute('conversation_status_id')] ?? null,
            ];
        })->values();

        return response()->json([
            'conversations' => $out,
            'me'            => $mine ? ['id' => $mine->id, 'presence' => $mine->presence] : null,
            'facets'        => $this->facets($facetRows, $mine, $now),
            'accounts'      => $this->accountOptions($project),
            // The full list, not just the statuses in view — the filter has to
            // offer one the current results do not contain, or it can never be
            // used to find them.
            'statuses'      => $statuses->values()->all(),
        ]);
    }

    /**
     * Who is handling this conversation.
     *
     * The distinction the inbox needs is "is a PERSON on this?", which the
     * columns only answer together: `handoff_status` says whether a handoff
     * happened, `assigned_agent_id` says to whom, and a queued conversation
     * has neither a person nor the bot — the customer is simply waiting.
     *
     * @param \Illuminate\Support\Collection $agentNames id => name
     * @return array{type:string, name:string}
     */
    private function handlerFor(Session $s, $agentNames): array
    {
        if ($s->handoff_status === 'assigned' && $s->assigned_agent_id) {
            return ['type' => 'agent', 'name' => $agentNames[$s->assigned_agent_id] ?? 'Agent'];
        }
        if ($s->handoff_status === 'queued') {
            return ['type' => 'queued', 'name' => 'Waiting for an agent'];
        }
        if ($s->handoff_status === 'resolved') {
            return ['type' => 'resolved', 'name' => 'Resolved'];
        }

        // Bot paused with nobody assigned means a person took it over ad hoc
        // (an owner replying directly) — claiming the AI is handling it would
        // be wrong.
        if (data_get($s->metadata, 'meta.bot_paused')) {
            return ['type' => 'human', 'name' => 'Handled by a person'];
        }

        return ['type' => 'bot', 'name' => 'AI agent'];
    }

    // ── Who is looking, and what may they do ─────────────────────────

    /**
     * Is the signed-in user the workspace owner?
     *
     * The owner is not necessarily a human AGENT — they hold seats for a team
     * rather than taking chats themselves. So every "can I do this?" check
     * here is `agent OR owner`, not `agent`: the person who pays for the
     * workspace being locked out of its inbox is not a defensible default.
     */
    private function isOwner(Client $client): bool
    {
        return (bool) auth()->user()?->isOwnerOf($client->id);
    }

    /**
     * Whether a free-form reply is possible right now, and if not, why.
     *
     * Computed server-side deliberately. Meta's rules differ per channel and
     * change; duplicating them in JavaScript guarantees the two drift, and the
     * failure mode is a composer that looks usable and then throws a 409 after
     * the agent has typed a paragraph.
     *
     *   whatsapp     24h. Closed means closed — only an approved template
     *                reopens it.
     *   messenger/ig 24h free-form, then the HUMAN_AGENT tag extends replies
     *                to 7 DAYS from the customer's last message. Past 7 days
     *                nothing will send.
     *
     * @return array{allowed:bool, mode:string, reason:?string, expires_at:?int}
     */
    private function replyPolicy(Session $session): array
    {
        $open = $this->meta->serviceWindowOpen($session->last_inbound_at);
        $last = (int) ($session->last_inbound_at ?? 0);

        if ($open) {
            return [
                'allowed'    => true,
                'mode'       => 'free',
                'reason'     => null,
                'expires_at' => $this->meta->serviceWindowExpiresAt($session->last_inbound_at),
            ];
        }

        if (in_array($session->channel, ['facebook', 'messenger', 'instagram'], true)) {
            $humanAgentUntil = $last > 0 ? $last + self::HUMAN_AGENT_WINDOW_SECONDS : 0;

            if ($humanAgentUntil > time()) {
                return [
                    'allowed'    => true,
                    'mode'       => 'human_agent',
                    'reason'     => 'The 24-hour window closed. Replies are still delivered under Meta’s human-agent allowance, which runs out 7 days after the customer’s last message.',
                    'expires_at' => $humanAgentUntil,
                ];
            }

            return [
                'allowed'    => false,
                'mode'       => 'blocked',
                'reason'     => 'More than 7 days since the customer last wrote. Meta will not deliver any reply until they message again.',
                'expires_at' => null,
            ];
        }

        return [
            'allowed'    => false,
            'mode'       => 'template',
            'reason'     => 'The 24-hour window closed. Send an approved template to reopen the conversation.',
            'expires_at' => null,
        ];
    }

    // ── Transfer ─────────────────────────────────────────────────────

    /**
     * Human agents this conversation can be handed to.
     *
     * Includes the current assignee so the menu shows where it is now, and
     * carries each agent's presence and current load — handing a chat to
     * someone offline with six open threads is the mistake this data exists
     * to prevent.
     */
    private function agentOptions(Project $project, Session $session): array
    {
        $agents = BotAgent::where('project_id', $project->id)
            ->where('type', BotAgent::TYPE_HUMAN)
            ->orderBy('name')
            ->get(['id', 'name', 'presence', 'max_active_chats', 'user_id']);

        if ($agents->isEmpty()) {
            return [];
        }

        $load = Session::where('project_id', $project->id)
            ->whereIn('assigned_agent_id', $agents->pluck('id'))
            ->where('handoff_status', 'assigned')
            ->selectRaw('assigned_agent_id, COUNT(*) as n')
            ->groupBy('assigned_agent_id')
            ->pluck('n', 'assigned_agent_id');

        return $agents->map(fn (BotAgent $a) => [
            'id'       => (int) $a->id,
            'name'     => $a->name,
            'presence' => $a->presence,
            'load'     => (int) ($load[$a->id] ?? 0),
            'max'      => $a->max_active_chats ? (int) $a->max_active_chats : null,
            'current'  => (int) $session->assigned_agent_id === (int) $a->id,
            'me'       => (int) $a->user_id === (int) auth()->id(),
        ])->values()->all();
    }

    /**
     * Hand a conversation to another agent (or back to the AI with a null id).
     *
     * Allowed for any human agent on the project and for the owner. A note is
     * written into the thread itself, not just the session row: "who had this
     * before me and why" is the first question on picking up a transferred
     * chat, and a silently-reassigned conversation cannot answer it.
     */
    public function transfer(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'agent'      => 'nullable|integer',
            'note'       => 'nullable|string|max:280',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);

        $mine  = $this->myAgent($project);
        $owner = $this->isOwner($client);

        if (! $mine && ! $owner) {
            return response()->json([
                'error'   => 'not_permitted',
                'message' => 'Only a human agent on this project, or the workspace owner, can transfer a conversation.',
            ], 403);
        }

        $fromAgent = $session->assigned_agent_id ? BotAgent::find($session->assigned_agent_id) : null;
        $from = $fromAgent
            ? ['type' => 'agent', 'name' => $fromAgent->name]
            : ['type' => 'bot', 'name' => 'AI agent'];

        // Null means hand it back to the bot — the honest way to undo a
        // transfer, and the only route back for an owner who picked it up.
        if (empty($data['agent'])) {
            $session->assigned_agent_id = null;
            $session->handoff_status    = 'bot';
            $to = ['type' => 'bot', 'name' => 'AI agent'];
        } else {
            $target = BotAgent::where('project_id', $project->id)
                ->where('type', BotAgent::TYPE_HUMAN)
                ->find((int) $data['agent']);

            if (! $target) {
                return response()->json(['error' => 'unknown_agent', 'message' => 'That agent is not on this project.'], 422);
            }

            $session->assigned_agent_id = $target->id;
            $session->handoff_status    = 'assigned';
            $to = ['type' => 'agent', 'name' => $target->name];
        }

        $session->update_at = time();
        $session->save();

        // Pause the bot when a human owns it; resume when handed back.
        $this->mergeMeta($session, ['bot_paused' => ! empty($data['agent'])]);

        $by = $mine?->name ?: (auth()->user()->name ?? 'the owner');

        // Structured, not a sentence. The thread renders this as a separator
        // with an avatar for a person and a glyph for the AI, which it can only
        // do if the participants survive as data rather than being flattened
        // into prose the front end would have to parse back out.
        $this->systemNote($session, "Transferred from {$from['name']} to {$to['name']} by {$by}", [
            'event' => 'transfer',
            'from'  => $from,
            'to'    => $to,
            'by'    => $by,
            'note'  => $data['note'] ?? null,
        ]);

        return response()->json(['ok' => true, 'assigned_to' => $to['name']]);
    }

    /**
     * An internal note in the thread.
     *
     * Stored with role `system`, which the send path never touches, so it is
     * visible to staff and never delivered to the customer.
     */
    /**
     * How to label an outbound message.
     *
     * Owner-without-a-seat is the case that needs its own label: they are
     * replying as the business, not as a rostered agent, and the team should
     * be able to see that at a glance in the thread. An owner who IS also an
     * agent is just an agent.
     */
    private function outboundAuthor(Session $session): string
    {
        $client = request()->route('client');
        $clientId = $client instanceof Client ? $client->id : null;

        if ($clientId && auth()->user()?->isOwnerOf($clientId)
            && ! BotAgent::where('project_id', $session->project_id)
                ->where('type', BotAgent::TYPE_HUMAN)
                ->where('user_id', auth()->id())->exists()) {
            return 'owner';
        }

        return 'agent';
    }

    private function systemNote(Session $session, string $text, array $extra = []): Message
    {
        return Message::create([
            'session_id' => $session->id,
            'project_id' => $session->project_id,
            'role'       => 'system',
            // `content` is the fallback wording, used if the front end does not
            // recognise the event — a note that renders as nothing would be
            // worse than one that renders plainly.
            'content'    => $text,
            'metadata'   => array_filter(['author' => 'system', 'internal' => true] + $extra),
            'created_at' => time(),
        ]);
    }

    // ── Conversation status (customer-defined) ───────────────────────

    /** @return array<int,array{id:int,name:string,color:string,is_closing:bool}> */
    private function statusList(Project $project): array
    {
        return ConversationStatus::forProject($project->id)
            ->map(fn (ConversationStatus $s) => [
                'id'         => (int) $s->id,
                'name'       => $s->name,
                'color'      => $s->color,
                'is_closing' => (bool) $s->is_closing,
            ])->values()->all();
    }

    /** Set (or clear, with a null id) the status on one conversation. */
    public function setStatus(Request $request, Client $client, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'status'     => 'nullable|integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $session = $this->session($project, $sessionId);

        if (! ConversationStatus::available()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Conversation statuses need a database update on this workspace. Run: php artisan tenant:migrate',
            ], 422);
        }

        $status = null;
        if (! empty($data['status'])) {
            // Scoped to the project: a status id from another workspace must
            // not be settable by editing the request.
            $status = ConversationStatus::where('project_id', $project->id)
                ->where('id', (int) $data['status'])->active()->first();

            if (! $status) {
                return response()->json(['ok' => false, 'message' => 'Unknown status.'], 422);
            }
        }

        $session->conversation_status_id = $status?->id;

        // A closing status ends the conversation for real, rather than just
        // colouring a chip — otherwise "Resolved" would leave the thread in
        // the open queue and the label would be a lie.
        if ($status?->is_closing) {
            $session->handoff_status = 'resolved';
        } elseif ($session->handoff_status === 'resolved') {
            // Moving off a closing status reopens it, so a mis-click is one
            // click to undo.
            $session->handoff_status = 'bot';
        }
        $session->update_at = time();
        $session->save();

        return response()->json(['ok' => true, 'status_id' => $status?->id]);
    }

    /** List / create / update / delete the project's statuses. */
    public function statuses(Request $request, Client $client): JsonResponse
    {
        $project = $this->guard($client, (int) $request->query('project_id'));

        return response()->json(['statuses' => $this->statusList($project)]);
    }

    public function storeStatus(Request $request, Client $client): JsonResponse
    {
        $data = $this->validateStatus($request);
        $project = $this->guard($client, (int) $data['project_id']);

        // Without this the insert hits a missing table and returns a 500 HTML
        // error page, which the browser reports only as "not ok" — the most
        // likely reason adding a status appears to do nothing at all.
        if ($error = $this->statusesUnavailable()) {
            return $error;
        }

        $status = ConversationStatus::create([
            'project_id' => $project->id,
            'name'       => $data['name'],
            'color'      => $this->safeColor($data['color'] ?? null),
            'is_closing' => (bool) ($data['is_closing'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'sort_order' => (int) ConversationStatus::where('project_id', $project->id)->max('sort_order') + 1,
            'status'     => ConversationStatus::STATUS_ACTIVE,
            'created_at' => time(),
        ]);

        if ($status->is_default) {
            $status->makeSoleDefault();
        }

        return response()->json(['ok' => true, 'statuses' => $this->statusList($project)]);
    }

    public function updateStatus(Request $request, Client $client, int $id): JsonResponse
    {
        $data = $this->validateStatus($request);
        $project = $this->guard($client, (int) $data['project_id']);

        if ($error = $this->statusesUnavailable()) {
            return $error;
        }

        $status = ConversationStatus::where('project_id', $project->id)->where('id', $id)->firstOrFail();
        $status->fill([
            'name'       => $data['name'],
            'color'      => $this->safeColor($data['color'] ?? null),
            'is_closing' => (bool) ($data['is_closing'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);
        $status->update_at = time();
        $status->save();

        if ($status->is_default) {
            $status->makeSoleDefault();
        }

        return response()->json(['ok' => true, 'statuses' => $this->statusList($project)]);
    }

    /**
     * Archive a status.
     *
     * Archived, not deleted: conversations already carrying it would
     * otherwise point at nothing, and their history would silently lose the
     * label someone deliberately applied. Conversations keep the status; it
     * just stops being offered for new ones.
     */
    public function destroyStatus(Request $request, Client $client, int $id): JsonResponse
    {
        $project = $this->guard($client, (int) $request->input('project_id'));

        if ($error = $this->statusesUnavailable()) {
            return $error;
        }

        $status = ConversationStatus::where('project_id', $project->id)->where('id', $id)->firstOrFail();
        $status->status = ConversationStatus::STATUS_ARCHIVED;
        $status->update_at = time();
        $status->save();

        return response()->json(['ok' => true, 'statuses' => $this->statusList($project)]);
    }

    /**
     * A ready-made 422 when this tenant DB has not been migrated yet.
     *
     * Returned rather than thrown so each caller stays a plain early return,
     * and the message names the exact command — an operator seeing this needs
     * to run something, not to read about a schema.
     */
    private function statusesUnavailable(): ?JsonResponse
    {
        if (ConversationStatus::available()) {
            return null;
        }

        return response()->json([
            'ok'      => false,
            'message' => 'Conversation statuses are not set up on this workspace yet. '
                       . 'Run: php artisan tenant:migrate',
        ], 422);
    }

    private function validateStatus(Request $request): array
    {
        return $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'required|string|max:60',
            'color'      => 'nullable|string|max:9',
            'is_closing' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ]);
    }

    /** Only palette colours, so a status can never be styled invisible. */
    private function safeColor(?string $color): string
    {
        return in_array($color, ConversationStatus::PALETTE, true)
            ? $color
            : ConversationStatus::PALETTE[0];
    }

    // ── Header metrics ───────────────────────────────────────────────

    /**
     * The four numbers worth putting in a conversation header.
     *
     * Chosen because each one changes what the agent does next:
     *
     *   window_expires_at   how long until a free-form reply becomes
     *                       impossible — the only hard deadline in the inbox
     *   started_at          how long this has been going on
     *   first_response      how fast the first answer was, the metric a
     *                       customer actually feels
     *   conversion_rate     project-wide leads → converted, the same figure
     *                       the dashboard reports, for context on whether
     *                       this inbox is working
     */
    private function conversationMetrics(Session $session, Project $project): array
    {
        return [
            'started_at'        => $session->started_at ? (int) $session->started_at : null,
            'window_expires_at' => $this->meta->serviceWindowExpiresAt($session->last_inbound_at),
            'window_seconds'    => MetaManager::SERVICE_WINDOW_SECONDS,
            'first_response'    => $this->firstResponseSeconds($session),
            'lead'              => $this->leadSummary($session),
            'conversion_rate'   => $this->conversionRate($project),
        ];
    }

    /**
     * Seconds between the customer's first message and our first reply.
     *
     * Measured from the FIRST inbound, not the most recent, because this is a
     * fixed historical fact about the conversation — recomputing it against
     * the latest message would make a number that is supposed to be a record
     * drift every time anyone spoke.
     *
     * Counts the bot's reply as a response, because from the customer's side
     * it is one.
     */
    private function firstResponseSeconds(Session $session): ?int
    {
        $firstInbound = Message::where('session_id', $session->id)
            ->where('role', 'user')->orderBy('id')->value('created_at');

        if (! $firstInbound) {
            return null;
        }

        $firstReply = Message::where('session_id', $session->id)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $firstInbound)
            ->orderBy('id')->value('created_at');

        return $firstReply ? max(0, (int) $firstReply - (int) $firstInbound) : null;
    }

    /** The lead this conversation produced, if any. */
    private function leadSummary(Session $session): ?array
    {
        $lead = Lead::where('session_id', $session->id)->orderByDesc('id')->first(['id', 'status', 'confidence']);

        return $lead ? [
            'status'     => $lead->status,
            'confidence' => $lead->confidence !== null ? (int) round($lead->confidence * 100) : null,
        ] : null;
    }

    /**
     * Project-wide leads → converted, as a percentage.
     *
     * Deliberately the SAME formula the workspace dashboard uses
     * (HomeController: converted / all leads), so the two cannot disagree —
     * a header quoting a different success rate than the dashboard would
     * make both untrustworthy.
     *
     * Cached briefly: it is identical for every conversation in the project,
     * and it would otherwise run two COUNTs on every thread open.
     */
    private function conversionRate(Project $project): ?int
    {
        return Cache::remember("chat:convrate:{$project->id}", now()->addMinutes(5), function () use ($project) {
            $total = Lead::where('project_id', $project->id)->count();
            if ($total === 0) {
                return null;   // no leads yet — 0% would imply failure
            }
            $converted = Lead::where('project_id', $project->id)->where('status', 'converted')->count();

            return (int) round($converted / $total * 100);
        });
    }

    // ── Filtering ────────────────────────────────────────────────────

    /**
     * Read the filter state off the query string.
     *
     * Everything is multi-select except `read` and `date`, which are
     * genuinely exclusive — a conversation cannot be both read and unread,
     * and overlapping date ranges would just mean the wider one.
     *
     * @return array{read:?string, date:?string, states:array, channels:array, accounts:array, kinds:array}
     */
    private function parseFilters(Request $request): array
    {
        $list = fn (string $key) => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $request->query($key, '')),
        )));

        return [
            'read'     => in_array($request->query('read'), ['read', 'unread'], true) ? $request->query('read') : null,
            'date'     => in_array($request->query('date'), ['today', '7d', '30d'], true) ? $request->query('date') : null,
            'states'   => array_intersect($list('states'), ['active', 'expiring', 'expired', 'closed']),
            'channels' => array_intersect($list('channels'), self::META_CHANNELS),
            'accounts' => $list('accounts'),
            'kinds'    => array_intersect($list('kinds'), ['dm', 'comment']),
            'handlers' => array_intersect($list('handlers'), ['bot', 'agent', 'queued']),
            'statuses' => array_values(array_filter(array_map('intval', $list('conv_statuses')))),
            // Applied in PHP alongside read/unread: it lives in the metadata
            // JSON for the same portability reason.
            'needs'    => $request->query('needs_human') === '1',
        ];
    }

    /** Push everything that can be expressed in SQL into the query. */
    private function applyFilters($q, array $f, int $now): void
    {
        if ($f['channels']) {
            // Messenger and Facebook are one channel to a human. Selecting
            // "Facebook" must match both spellings or half the inbox vanishes.
            $channels = $f['channels'];
            if (in_array('facebook', $channels, true)) {
                $channels[] = 'messenger';
            }
            $q->whereIn('channel', array_unique($channels));
        }

        if ($f['accounts']) {
            $q->whereIn('channel_account', $f['accounts']);
        }

        if ($f['statuses'] && ConversationStatus::available()) {
            $q->whereIn('conversation_status_id', $f['statuses']);
        }

        if ($f['handlers']) {
            $q->where(function ($outer) use ($f) {
                foreach ($f['handlers'] as $handler) {
                    $outer->orWhere(function ($w) use ($handler) {
                        match ($handler) {
                            'agent'  => $w->where('handoff_status', 'assigned')->whereNotNull('assigned_agent_id'),
                            'queued' => $w->where('handoff_status', 'queued'),
                            // The AI is only really handling it when no handoff
                            // has happened at all.
                            default  => $w->whereIn('handoff_status', ['bot', ''])->orWhereNull('handoff_status'),
                        };
                    });
                }
            });
        }

        if ($f['date']) {
            $q->where('last_activity_at', '>=', match ($f['date']) {
                'today' => now()->startOfDay()->getTimestamp(),
                '7d'    => now()->subDays(7)->getTimestamp(),
                default => now()->subDays(30)->getTimestamp(),
            });
        }

        if ($f['states']) {
            $windowOpensAfter = $now - MetaManager::SERVICE_WINDOW_SECONDS;
            $soonThreshold    = $now - MetaManager::SERVICE_WINDOW_SECONDS + self::EXPIRING_SOON_SECONDS;

            $q->where(function ($outer) use ($f, $windowOpensAfter, $soonThreshold) {
                foreach ($f['states'] as $state) {
                    $outer->orWhere(function ($w) use ($state, $windowOpensAfter, $soonThreshold) {
                        match ($state) {
                            // Closed is decided by us, not by Meta's clock, so
                            // it is checked first and independently — a resolved
                            // conversation is closed whether or not its reply
                            // window happens to still be open.
                            'closed'   => $w->where(fn ($x) => $x->where('status', '!=', 'active')
                                                                 ->orWhere('handoff_status', 'resolved')),
                            'expiring' => $w->where('status', 'active')->where('handoff_status', '!=', 'resolved')
                                            ->where('last_inbound_at', '>', $windowOpensAfter)
                                            ->where('last_inbound_at', '<=', $soonThreshold),
                            'expired'  => $w->where('status', 'active')->where('handoff_status', '!=', 'resolved')
                                            ->where(fn ($x) => $x->whereNull('last_inbound_at')
                                                                 ->orWhere('last_inbound_at', '<=', $windowOpensAfter)),
                            default    => $w->where('status', 'active')->where('handoff_status', '!=', 'resolved')
                                            ->where('last_inbound_at', '>', $soonThreshold),
                        };
                    });
                }
            });
        }
    }

    /**
     * Read/unread, applied in PHP.
     *
     * "Unread" means the customer has said something since the last time an
     * agent opened the thread — `read_at` lives in the metadata JSON, and
     * MySQL and SQLite disagree enough on JSON path syntax that pushing this
     * into SQL would break the test suite against one of them.
     */
    private function matchesReadState(Session $s, ?string $want): bool
    {
        if (! $want) {
            return true;
        }
        $unread = (int) ($s->last_inbound_at ?? 0) > (int) data_get($s->metadata, 'meta.read_at', 0);

        return $want === 'unread' ? $unread : ! $unread;
    }

    /**
     * Where a conversation stands, as one word an agent can act on.
     *
     *   active    the customer wrote recently; reply freely
     *   expiring  under EXPIRING_SOON_SECONDS of the 24h window left — the
     *             one state worth interrupting someone for, because after it
     *             passes a free reply is no longer possible
     *   expired   window shut; only an approved template will reopen it
     *   closed    resolved or ended on our side
     */
    private function conversationState(Session $s, int $now): string
    {
        if ($s->status !== 'active' || $s->handoff_status === 'resolved') {
            return 'closed';
        }
        if (! $this->meta->serviceWindowOpen($s->last_inbound_at, $now)) {
            return 'expired';
        }

        return ($this->meta->serviceWindowExpiresAt($s->last_inbound_at) - $now) <= self::EXPIRING_SOON_SECONDS
            ? 'expiring'
            : 'active';
    }

    /**
     * A direct message or a comment on a post.
     *
     * NOTE: nothing sets `comment` yet — the `feed` webhook field is neither
     * subscribed nor handled, so no comment ever reaches the inbox. The
     * filter is wired end to end so it works the day ingestion lands; until
     * then selecting it correctly returns nothing rather than silently
     * showing DMs.
     */
    private function conversationKind(Session $s): string
    {
        return data_get($s->metadata, 'meta.kind') === 'comment' ? 'comment' : 'dm';
    }

    /**
     * How many conversations sit in each bucket.
     *
     * @param \Illuminate\Support\Collection<int,Session> $rows
     */
    private function facets($rows, $mine, int $now): array
    {
        $counts = [
            'all' => $rows->count(), 'mine' => 0, 'queue' => 0,
            'unread' => 0, 'read' => 0, 'needs_reply' => 0, 'needs_human' => 0,
            'states' => ['active' => 0, 'expiring' => 0, 'expired' => 0, 'closed' => 0],
            'channels' => [], 'accounts' => [], 'kinds' => ['dm' => 0, 'comment' => 0],
            'handlers' => ['bot' => 0, 'agent' => 0, 'queued' => 0],
            'statuses' => [],
        ];

        foreach ($rows as $s) {
            if (data_get($s->metadata, 'meta.needs_human')) {
                $counts['needs_human']++;
            }

            $handler = $this->handlerFor($s, collect())['type'];
            if (isset($counts['handlers'][$handler])) {
                $counts['handlers'][$handler]++;
            }

            if ($sid = $s->getAttribute('conversation_status_id')) {
                $counts['statuses'][$sid] = ($counts['statuses'][$sid] ?? 0) + 1;
            }

            $unread = (int) ($s->last_inbound_at ?? 0) > (int) data_get($s->metadata, 'meta.read_at', 0);
            $state  = $this->conversationState($s, $now);

            $counts[$unread ? 'unread' : 'read']++;
            $counts['states'][$state]++;

            // The headline number: a customer is waiting AND we can still
            // answer without a template. That is the queue that actually
            // costs money to ignore.
            if ($unread && in_array($state, ['active', 'expiring'], true)) {
                $counts['needs_reply']++;
            }

            if ($mine && (int) $s->assigned_agent_id === (int) $mine->id) {
                $counts['mine']++;
            }
            if ($s->handoff_status === 'queued') {
                $counts['queue']++;
            }

            $channel = $s->channel === 'messenger' ? 'facebook' : $s->channel;
            $counts['channels'][$channel] = ($counts['channels'][$channel] ?? 0) + 1;

            $account = (string) $s->channel_account;
            if ($account !== '') {
                $counts['accounts'][$account] = ($counts['accounts'][$account] ?? 0) + 1;
            }

            $counts['kinds'][$this->conversationKind($s)]++;
        }

        return $counts;
    }

    /**
     * The Pages / numbers this project has connected, for the account filter.
     *
     * Named, never raw ids: "The UR Solutions" is a choice someone can make;
     * "102938…" is not.
     */
    private function accountOptions(Project $project): array
    {
        return ChannelConnection::where('project_id', $project->id)
            ->orderBy('provider')
            ->get(['provider', 'external_id', 'name', 'metadata'])
            ->map(fn (ChannelConnection $c) => [
                'id'      => (string) $c->external_id,
                'name'    => $c->name ?: data_get($c->metadata, 'display_phone_number') ?: 'Channel',
                'channel' => $c->provider === 'facebook_page' ? 'facebook' : $c->provider,
            ])
            ->values()
            ->all();
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
            'is_owner'       => $this->isOwner($client),
            'reply_policy'   => $this->replyPolicy($session),
            'agents'         => $this->agentOptions($project, $session),
            'statuses'       => $this->statusList($project),
            // Guarded because the column may not exist on a tenant DB whose
            // migrations have not caught up — reading a missing attribute is
            // safe, but being explicit documents that this can be absent.
            'status_id'      => ($sid = $session->getAttribute('conversation_status_id')) ? (int) $sid : null,
            'metrics'        => $this->conversationMetrics($session, $project),
            'contact'        => [
                'name'        => $this->contactName($session),
                'channel'     => $session->channel,
                'avatar'      => $this->avatarUrl((array) data_get($session->metadata, 'meta', [])),
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

        $graph = GraphClient::forConnection($conn);
        $open  = $this->meta->serviceWindowOpen($session->last_inbound_at);
        $to    = $session->external_id;

        // One gate for every channel, from the same rules the UI renders — so
        // a disabled composer and a rejected send can never disagree. Blocks
        // WhatsApp past 24h AND Messenger/Instagram past the 7-day
        // human-agent allowance, which previously reached Meta and came back
        // as an opaque "Meta rejected the message".
        $policy = $this->replyPolicy($session);
        if (! $policy['allowed']) {
            return response()->json([
                'error'   => $policy['mode'] === 'template' ? 'window_expired' : 'window_blocked',
                'message' => $policy['reason'],
            ], 409);
        }

        if ($provider === ChannelConnection::PROVIDER_WHATSAPP) {
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
        $graph = GraphClient::forConnection($conn);

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
        $ok = GraphClient::forConnection($conn)->sendTemplate(
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
        $ok = GraphClient::forConnection($conn)->sendInteractiveButtons(
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

        $raw = GraphClient::forConnection($conn)->listTemplates((string) $waba);
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
        $ok = GraphClient::forConnection($conn)->sendFlow(
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

        $graph = GraphClient::forConnection($conn);
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
                // 'owner' when the workspace owner replies without holding an
                // agent seat. The distinction is worth recording: the team
                // needs to know the boss answered this one, and "agent" for
                // someone who is not on the roster would be misleading.
                'author'      => $this->outboundAuthor($session),
                'author_name' => auth()->user()->name ?? null,
                'attachments' => $attachments ?: null,
                'wamid'       => $wamid,
                'reply_to'    => $replyTo,
            ]),
            'created_at' => $now,
        ]);
        $session->last_activity_at = $now;
        $session->update_at = $now;
        $session->save();

        // A real person has now answered, so the "needs a human" flag has done
        // its job. Cleared here rather than on assignment: being assigned is a
        // promise, sending a message is the delivery.
        if (data_get($session->metadata, 'meta.needs_human')) {
            $this->mergeMeta($session, ['needs_human' => false]);
        }

        return $msg;
    }

    private function shapeMessage(Message $m, int $sessionId): array
    {
        $author = data_get($m->metadata, 'author');

        // `system` rows are internal notes (transfers). They are never sent to
        // the customer, so they must not render as an outbound bubble.
        $who = match (true) {
            $m->role === 'user'   => 'customer',
            $m->role === 'system' => 'system',
            $author === 'owner'   => 'owner',
            $author === 'agent'   => 'agent',
            default               => 'bot',
        };

        return [
            'id'          => $m->id,
            'direction'   => $m->role === 'user' ? 'in' : 'out',
            'author'      => $who,
            'author_name' => data_get($m->metadata, 'author_name'),
            // Present only on internal events (currently transfers), so the
            // thread can render a separator instead of a bubble.
            'event'       => data_get($m->metadata, 'event'),
            'from'        => data_get($m->metadata, 'from'),
            'to'          => data_get($m->metadata, 'to'),
            'by'          => data_get($m->metadata, 'by'),
            'note'        => data_get($m->metadata, 'note'),
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

    /**
     * What to call this customer.
     *
     * Never the raw external_id. A PSID/IGSID is a 16-digit opaque number
     * that tells the agent nothing, cannot be dialled, cannot be searched,
     * and reads as a bug — showing it is strictly worse than admitting we do
     * not have the name.
     *
     * A WhatsApp id is the exception: it IS the phone number, so it is
     * genuinely useful and gets formatted rather than hidden.
     */
    private function contactName(Session $session): string
    {
        if ($name = trim((string) $session->customer_name)) {
            return $name;
        }

        if ($session->channel === 'whatsapp') {
            $digits = preg_replace('/\D+/', '', (string) ($session->customer_phone ?: $session->external_id));
            if ($digits !== '') {
                return '+' . $digits;
            }
        }

        return match ($session->channel) {
            'instagram'             => 'Instagram user',
            'facebook', 'messenger' => 'Facebook user',
            'whatsapp'              => 'WhatsApp user',
            default                 => 'Guest',
        };
    }

    /**
     * The customer's photo, as a URL we serve.
     *
     * Deliberately ignores the legacy `profile_pic` key. That held Meta's
     * signed CDN URL, which expires within days — every one of those stored
     * before ContactAvatars existed is now a broken image, and rendering it
     * looks worse than rendering the placeholder.
     */
    private function avatarUrl(array $meta): ?string
    {
        return $meta['avatar'] ?? null;
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
