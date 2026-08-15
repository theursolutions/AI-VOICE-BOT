<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\Session;
use App\Services\Crm\ContactResolver;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everyone who has ever contacted this business — the customer list the
 * inbox never had.
 *
 * One row per person, not per conversation, which is the whole difference
 * between this and the Messages page: someone who wrote on WhatsApp in March
 * and Instagram in August appears once, with both.
 */
class ContactWebController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects  = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name']);
        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project   = $projects->firstWhere('id', $projectId);

        $contacts  = collect();
        $available = false;
        $counts    = ['total' => 0, 'with_contact' => 0, 'multi_channel' => 0];

        if ($project) {
            $this->tenants->useFor($project);
            $available = ContactResolver::available();

            // The customer sees a plain "no contacts yet". The actionable
            // detail belongs to whoever can act on it, which is never them.
            if (! $available) {
                \Illuminate\Support\Facades\Log::warning(
                    'Contacts tables missing for project — run: php artisan tenant:migrate '
                    . '&& php artisan contacts:backfill',
                    ['project_id' => $project->id, 'project' => $project->name],
                );
            }
        }

        if ($available && $project) {
            $search = trim((string) $request->query('q', ''));

            $query = Contact::where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->when($search !== '', function ($q) use ($search) {
                    $like = '%' . $search . '%';
                    // Phone is matched on digits alone so "+92 300" finds a
                    // number stored as 923001234567.
                    $digits = preg_replace('/\D+/', '', $search);
                    $q->where(function ($w) use ($like, $digits) {
                        $w->where('name', 'like', $like)
                          ->orWhere('email', 'like', $like);
                        if ($digits !== '') {
                            $w->orWhere('phone', 'like', '%' . $digits . '%');
                        }
                    });
                })
                ->orderByDesc('last_seen_at')
                ->orderByDesc('id');

            $contacts = $query->paginate(self::PER_PAGE)->withQueryString();

            // Enrich the page in a handful of queries rather than per row —
            // 30 contacts × 4 lookups would be 120 queries a page.
            $ids = collect($contacts->items())->pluck('id');

            $identities = ContactIdentity::whereIn('contact_id', $ids)
                ->get(['contact_id', 'channel'])
                ->groupBy('contact_id');

            $sessionsByContact = Session::whereIn('contact_id', $ids)
                ->selectRaw('contact_id, COUNT(*) as n, MAX(last_activity_at) as last_at')
                ->groupBy('contact_id')
                ->get()
                ->keyBy('contact_id');

            $messageCounts = Message::whereIn('session_id',
                    Session::whereIn('contact_id', $ids)->pluck('id'))
                ->selectRaw('session_id, COUNT(*) as n')
                ->groupBy('session_id')
                ->pluck('n', 'session_id');

            $sessionOwner = Session::whereIn('contact_id', $ids)->pluck('contact_id', 'id');

            $leadCounts = Lead::whereIn('contact_id', $ids)
                ->selectRaw('contact_id, COUNT(*) as n')
                ->groupBy('contact_id')
                ->pluck('n', 'contact_id');

            $messagesPerContact = [];
            foreach ($messageCounts as $sessionId => $n) {
                $owner = $sessionOwner[$sessionId] ?? null;
                if ($owner) {
                    $messagesPerContact[$owner] = ($messagesPerContact[$owner] ?? 0) + $n;
                }
            }

            foreach ($contacts as $c) {
                $c->channels_list = ($identities[$c->id] ?? collect())->pluck('channel')->unique()->values()->all();
                $c->session_count = (int) ($sessionsByContact[$c->id]->n ?? 0);
                $c->message_count = (int) ($messagesPerContact[$c->id] ?? 0);
                $c->lead_count    = (int) ($leadCounts[$c->id] ?? 0);
                // The most recent conversation, so a row can open straight
                // into the thread rather than into a dead end.
                $c->latest_session = Session::where('contact_id', $c->id)
                    ->orderByDesc('last_activity_at')->value('id');
            }

            $counts['total'] = Contact::where('project_id', $project->id)->whereNull('deleted_at')->count();
            $counts['with_contact'] = Contact::where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('phone'))
                ->count();
            $counts['multi_channel'] = ContactIdentity::where('project_id', $project->id)
                ->selectRaw('contact_id')
                ->groupBy('contact_id')
                ->havingRaw('COUNT(DISTINCT channel) > 1')
                ->get()
                ->count();
        }

        return view('contacts.index', compact(
            'client', 'projects', 'project', 'projectId', 'contacts', 'available', 'counts'
        ));
    }

    /**
     * One contact, everything about them.
     *
     * The common detail page: reached from the contacts list, from a lead,
     * and from the inbox. Left column is who they are, right column is what
     * has happened — because "who" is reference material you scan once and
     * "what happened" is what you actually read.
     */
    public function show(Request $request, Client $client, int $id): View
    {
        $projects  = Project::where('client_id', $client->id)->orderBy('name')->get(['id', 'name']);
        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project   = $projects->firstWhere('id', $projectId);

        abort_unless($project, 404);
        $this->tenants->useFor($project);
        abort_unless(ContactResolver::available(), 404);

        $contact = Contact::where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $profile = app(\App\Services\Crm\ContactProfile::class)->build($contact->load('identities'));

        // The activity timeline. Conversations newest-first, each with its
        // first and last message, so the page reads as a history rather than
        // a list of ids.
        $sessions = Session::where('contact_id', $contact->id)
            ->orderByDesc('last_activity_at')
            ->get(['id', 'channel', 'channel_account', 'status', 'handoff_status',
                   'started_at', 'last_activity_at']);

        $previews = [];
        foreach ($sessions as $s) {
            $previews[$s->id] = [
                'count' => Message::where('session_id', $s->id)->count(),
                'last'  => Message::where('session_id', $s->id)
                    ->orderByDesc('id')->value('content'),
            ];
        }

        $leads = Lead::where('contact_id', $contact->id)
            ->orderByDesc('id')
            ->get(['id', 'session_id', 'status', 'confidence', 'fields', 'notes', 'created_at']);

        return view('contacts.show', compact(
            'client', 'projects', 'project', 'projectId',
            'contact', 'profile', 'sessions', 'previews', 'leads'
        ));
    }
}
