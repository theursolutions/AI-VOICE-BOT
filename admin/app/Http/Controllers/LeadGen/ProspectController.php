<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Jobs\LeadGen\EnrichProspect;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\LeadList;
use App\Models\LeadGen\LeadListItem;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\SearchResult;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadGen\CrmSync;
use App\Services\LeadGen\Deduplicator;
use App\Services\LeadGen\LeadGenContext;
use App\Services\LeadGen\ProspectNormalizer;
use App\Services\LeadGen\SuppressionService;
use App\Support\Hashid;
use App\Support\Xlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Every prospect in the project, whatever search or import found it: browse,
 * filter, select in bulk, enrich, list, tag, add to the CRM, export, delete.
 */
class ProspectController extends Controller
{
    /** Most rows one bulk action touches — bigger jobs are a filtered export or a list. */
    private const BULK_MAX = 500;
    private const ENRICH_MAX = 200;

    public function __construct(private LeadGenContext $context) {}

    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $prospects = $this->filtered($request)->with('currentQualification')->orderByDesc('id')->paginate(50)->withQueryString();

        return view('leadgen.prospects.index', [
            'client' => $client, 'project' => $project, 'projects' => $projects, 'prospects' => $prospects,
            'lists'  => LeadList::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['q', 'status', 'has', 'city', 'category', 'list_id', 'tag', 'search_id', 'show', 'enrichment']),
            'statuses' => Prospect::STATUSES,
        ]);
    }

    public function show(Request $request, Client $client, int $id): View
    {
        [$project, $projects] = $this->open($request, $client);
        $p = Prospect::with('lists')->findOrFail($id);

        // Similar names in the same city — shown for a person to judge, never merged.
        $similar = collect();
        if ($p->city) {
            $similar = Prospect::where('id', '!=', $p->id)->where('city', $p->city)->limit(400)
                ->get(['id', 'business_name', 'website', 'phone_e164', 'email', 'domain_norm'])
                ->filter(fn ($o) => Deduplicator::similarNames($p->business_name, $o->business_name))
                ->take(5);
        }
        $possibleOf = $p->duplicate_of ? Prospect::find($p->duplicate_of) : null;
        $foundBy = SearchResult::where('prospect_id', $p->id)->with([])->orderBy('id')->limit(10)->get(['search_id', 'outcome', 'created_at']);
        $events  = Event::where('subject_type', 'prospect')->where('subject_id', $p->id)->orderByDesc('id')->limit(20)->get();
        $members = $this->members($client, $project);

        return view('leadgen.prospects.show', compact('client', 'project', 'projects', 'p', 'similar', 'possibleOf', 'foundBy', 'events', 'members') + [
            'lists' => LeadList::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Edit what a person knows better than the source. */
    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $p = Prospect::findOrFail($id);
        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'category'      => 'nullable|string|max:120',
            'website'       => 'nullable|string|max:500',
            'email'         => 'nullable|email:rfc|max:191',
            'phone'         => 'nullable|string|max:40',
            'city'          => 'nullable|string|max:120',
            'country_code'  => 'nullable|string|size:2|alpha',
            'status'        => ['required', Rule::in(array_diff(Prospect::STATUSES, ['in_crm']))],
            'owner_id'      => 'nullable|integer',
            'tags'          => 'nullable|string|max:300',
            'notes'         => 'nullable|string|max:5000',
        ]);
        $country = isset($data['country_code']) ? strtoupper($data['country_code']) : $p->country_code;

        $meta = (array) $p->field_meta;
        $set = function (string $field, $new, $old) use (&$meta) {
            // A value a person typed or kept is confirmed by them; a removed one loses its record.
            if ($new === null) { unset($meta[$field]); return; }
            if ($new !== $old) { $meta[$field] = ['source' => 'user', 'found_at' => time(), 'verification' => 'user_confirmed']; }
        };

        $website = ProspectNormalizer::website($data['website'] ?? null);
        $email   = ProspectNormalizer::email($data['email'] ?? null);
        $phone   = ProspectNormalizer::phone($data['phone'] ?? null, $country);
        if (!empty($data['phone']) && !$phone) {
            throw ValidationException::withMessages(['phone' => 'Enter the number with its country code (e.g. +971 4 555 1234), or set the country.']);
        }
        $set('website', $website, $p->website);
        $set('email', $email, $p->email);
        $set('phone', $phone, $p->phone_e164);

        if ($data['owner_id'] ?? null) {
            abort_unless($this->members($client, $project)->has((int) $data['owner_id']), 422);
        }

        $p->forceFill([
            'business_name' => $data['business_name'],
            'category'      => $data['category'] ?? null,
            'website'       => $website,
            'domain_norm'   => ProspectNormalizer::domain($website),
            'email'         => $email,
            'phone_e164'    => $phone,
            'city'          => $data['city'] ?? null,
            'country_code'  => $country,
            'status'        => $p->status === 'in_crm' ? 'in_crm' : $data['status'],
            'owner_id'      => $data['owner_id'] ?? null,
            'tags'          => self::parseTags($data['tags'] ?? '') ?: null,
            'notes'         => $data['notes'] ?? null,
            'field_meta'    => $meta ?: null,
        ])->save();
        Event::log('prospect', $p->id, 'prospect.edited', []);

        return back()->with('success', 'Saved.');
    }

    public function enrich(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $p = Prospect::findOrFail($id);
        $p->forceFill(['enrichment_status' => 'queued'])->save();
        EnrichProspect::dispatch($project->id, $p->id);
        return back()->with('success', 'Checking their website. This takes a few seconds.');
    }

    public function addToCrm(Request $request, Client $client, int $id, CrmSync $crm): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $p = Prospect::findOrFail($id);
        if ($p->suppressed_at) {
            return back()->withErrors(['crm' => 'This business is on your do-not-contact list.']);
        }
        try {
            $res = $crm->add($p, $request->user()->id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['crm' => $e->getMessage()]);
        }
        AuditLog::record('leadgen.prospect.added_to_crm', ['target_type' => 'lg_prospect', 'target_id' => $p->id,
            'payload' => ['project_id' => $project->id, 'contact_id' => $res['contact']->id, 'lead_id' => $res['lead']->id]]);
        return back()->with('success', $res['created'] ? 'Added to your CRM as a new lead.' : 'Already in your CRM.');
    }

    /** One endpoint for every bulk action; each checks its own ability. */
    public function bulk(Request $request, Client $client, CrmSync $crm, SuppressionService $suppressions): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $data = $request->validate([
            'action'  => 'required|in:add_to_list,remove_from_list,tag,enrich,approve,reject,add_to_crm,delete,suppress',
            'ids'     => 'required|array|min:1|max:' . self::BULK_MAX,
            'ids.*'   => 'string|max:40',
            'list_id' => 'nullable|integer',
            'new_list'=> 'nullable|string|max:160',
            'tag'     => 'nullable|string|max:60',
            'never_readd' => 'nullable|boolean',
        ]);
        $ability = match ($data['action']) {
            'enrich' => 'search',
            default  => 'edit',
        };
        abort_unless($request->user()->canAbility($client->id, 'leadgen', $ability), 403);

        $ids = collect($data['ids'])->map(fn ($v) => ctype_digit((string) $v) ? (int) $v : Hashid::decode((string) $v))->filter()->unique()->values();
        $prospects = Prospect::whereIn('id', $ids)->get();
        $n = $prospects->count();
        $user = $request->user()->id;

        switch ($data['action']) {
            case 'add_to_list':
                $list = !empty($data['new_list'])
                    ? LeadList::create(['name' => $data['new_list'], 'created_by' => $user])
                    : LeadList::findOrFail((int) ($data['list_id'] ?? 0));
                $rows = $prospects->map(fn ($p) => ['project_id' => $project->id, 'list_id' => $list->id, 'prospect_id' => $p->id,
                                                     'added_by' => $user, 'created_at' => time()])->all();
                LeadListItem::query()->insertOrIgnore($rows);
                $msg = "{$n} added to “{$list->name}”.";
                break;

            case 'remove_from_list':
                $list = LeadList::findOrFail((int) ($data['list_id'] ?? 0));
                $removed = LeadListItem::where('list_id', $list->id)->whereIn('prospect_id', $prospects->pluck('id'))->delete();
                $msg = "Removed {$removed} from “{$list->name}”.";
                break;

            case 'tag':
                $tag = self::parseTags($data['tag'] ?? '')[0] ?? null;
                if (!$tag) {
                    throw ValidationException::withMessages(['tag' => 'Enter a tag.']);
                }
                foreach ($prospects as $p) {
                    $p->forceFill(['tags' => array_values(array_unique(array_merge((array) $p->tags, [$tag])))])->save();
                }
                $msg = "Tagged {$n} with “{$tag}”.";
                break;

            case 'enrich':
                $todo = $prospects->whereNotIn('enrichment_status', ['queued', 'running'])->take(self::ENRICH_MAX);
                foreach ($todo as $p) {
                    $p->forceFill(['enrichment_status' => 'queued'])->save();
                    EnrichProspect::dispatch($project->id, $p->id);
                }
                $msg = "Checking {$todo->count()} websites in the background.";
                break;

            case 'approve':
            case 'reject':
                $to = $data['action'] === 'approve' ? 'approved' : 'rejected';
                Prospect::whereIn('id', $prospects->pluck('id'))->where('status', '!=', 'in_crm')->update(['status' => $to, 'updated_at' => time()]);
                $msg = ucfirst($to) . " {$n}.";
                break;

            case 'add_to_crm':
                $added = $skipped = 0;
                foreach ($prospects->take(100) as $p) {
                    if ($p->suppressed_at) { $skipped++; continue; }
                    try {
                        $crm->add($p, $user)['created'] ? $added++ : $skipped++;
                    } catch (\RuntimeException $e) {
                        return back()->withErrors(['crm' => $e->getMessage()]);
                    }
                }
                AuditLog::record('leadgen.prospects.added_to_crm', ['payload' => ['project_id' => $project->id, 'count' => $added]]);
                $msg = "{$added} added to your CRM" . ($skipped ? ", {$skipped} skipped (already there or do-not-contact)" : '') . '.';
                break;

            case 'suppress':
                foreach ($prospects as $p) {
                    $suppressions->addProspect($p, 'manual', 'user:' . $user);
                }
                AuditLog::record('leadgen.prospects.suppressed', ['payload' => ['project_id' => $project->id, 'count' => $n]]);
                $msg = "{$n} added to your do-not-contact list.";
                break;

            case 'delete':
                foreach ($prospects as $p) {
                    if (!empty($data['never_readd'])) {
                        $suppressions->addProspect($p, 'deleted', 'user:' . $user);
                    }
                    LeadListItem::where('prospect_id', $p->id)->delete();
                    $p->softDelete();
                }
                AuditLog::record('leadgen.prospects.deleted', ['payload' => ['project_id' => $project->id, 'count' => $n, 'never_readd' => !empty($data['never_readd'])]]);
                $msg = "Deleted {$n}." . (!empty($data['never_readd']) ? ' Future searches won’t add them again.' : '');
                break;
        }

        return back()->with('success', $msg ?? 'Done.');
    }

    public function export(Request $request, Client $client): BinaryFileResponse
    {
        [$project] = $this->open($request, $client);
        $rows = $this->filtered($request)->whereNull('suppressed_at')->orderBy('id')->limit(50000)->cursor()
            ->map(fn (Prospect $p) => [
                $p->business_name, $p->category, $p->website, $p->email, $p->verificationOf('email'),
                $p->phone_e164 ?: ($p->field_meta['phone']['raw'] ?? null), $p->verificationOf('phone'),
                $p->city, $p->country_code, $p->status, implode(', ', (array) $p->tags),
                $p->source_provider === 'import' ? 'Your import' : 'Overture Maps', $p->source_license,
                $p->discovered_at ? date('Y-m-d', $p->discovered_at) : null,
            ]);

        $path = Xlsx::build('Prospects',
            ['Business', 'Category', 'Website', 'Email', 'Email checked', 'Phone', 'Phone checked', 'City', 'Country',
             'Status', 'Tags', 'Source', 'Source licence', 'Found on'],
            $rows, [32, 22, 30, 28, 14, 18, 14, 16, 8, 11, 20, 16, 26, 12]);

        AuditLog::record('leadgen.prospects.exported', ['payload' => ['project_id' => $project->id, 'filters' => $request->query()]]);

        return response()->download($path, 'prospects-' . now()->format('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Filters shared by the list, bulk "select all" and export. */
    private function filtered(Request $request): Builder
    {
        $q = Prospect::query();
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(fn ($w) => $w->where('business_name', 'like', $like)->orWhere('website', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('phone_e164', 'like', '%' . preg_replace('/\D+/', '', $search) . '%'));
        }
        if (in_array($request->query('status'), Prospect::STATUSES, true)) {
            $q->where('status', $request->query('status'));
        }
        match ($request->query('has')) {
            'website' => $q->whereNotNull('website'),
            'email'   => $q->whereNotNull('email'),
            'phone'   => $q->whereNotNull('phone_e164'),
            'nothing' => $q->whereNull('email')->whereNull('phone_e164'),
            default   => null,
        };
        if ($city = trim((string) $request->query('city', ''))) {
            $q->where('city', $city);
        }
        if ($cat = trim((string) $request->query('category', ''))) {
            $q->where('category', $cat);
        }
        if ($listId = (int) $request->query('list_id')) {
            $q->whereIn('id', LeadListItem::where('list_id', $listId)->select('prospect_id'));
        }
        if ($searchId = (int) $request->query('search_id')) {
            $q->whereIn('id', SearchResult::where('search_id', $searchId)->select('prospect_id'));
        }
        if ($tag = trim((string) $request->query('tag', ''))) {
            $q->where('tags', 'like', '%"' . str_replace(['%', '_', '"'], ['\%', '\_', ''], $tag) . '"%');
        }
        if (in_array($request->query('enrichment'), ['none', 'done', 'failed', 'blocked'], true)) {
            $q->where('enrichment_status', $request->query('enrichment'));
        }
        match ($request->query('show')) {
            'suppressed' => $q->whereNotNull('suppressed_at'),
            'possible_duplicates' => $q->whereNotNull('duplicate_of'),
            'all' => null,
            default => $q->whereNull('suppressed_at'),
        };
        return $q;
    }

    public static function parseTags(string $raw): array
    {
        return collect(preg_split('/[,;]+/', $raw))
            ->map(fn ($t) => mb_substr(trim(preg_replace('/\s+/', ' ', $t)), 0, 40))
            ->filter()->unique()->take(10)->values()->all();
    }

    /** Workspace members who can see this project — candidates for "owner". */
    private function members(Client $client, Project $project)
    {
        $ids = \DB::table('project_users')->where('client_id', $client->id)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->pluck('user_id')->unique();
        return User::whereIn('id', $ids)->orderBy('name')->pluck('name', 'id');
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
