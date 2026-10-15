<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Jobs\LeadGen\RunSearch;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\Search;
use App\Models\LeadGen\SearchResult;
use App\Models\Project;
use App\Services\LeadGen\LeadGenContext;
use App\Services\LeadGen\Providers\ImportSource;
use App\Services\LeadGen\Providers\OvertureProvider;
use App\Services\LeadGen\Providers\PlacesClient;
use App\Services\LeadGen\Providers\ProviderException;
use App\Services\LeadGen\Providers\ProviderRegistry;
use App\Services\LeadGen\Providers\SearchCriteria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Discovery searches: define → run in the background → watch → review.
 * Controllers stay thin: the work is in RunSearch and the provider classes.
 */
class SearchController extends Controller
{
    /** Concurrent searches/imports per project — protects the worker and the queue. */
    private const MAX_ACTIVE = 3;

    public function __construct(
        private LeadGenContext $context,
        private ProviderRegistry $registry,
    ) {}

    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $tab = $request->query('tab') === 'saved' ? 'saved' : 'all';

        $searches = Search::query()
            ->when($tab === 'saved', fn ($q) => $q->where('saved', true))
            ->orderByDesc('id')
            ->paginate(20)->withQueryString();

        return view('leadgen.searches.index', compact('client', 'project', 'projects', 'searches', 'tab'));
    }

    public function create(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);

        $source = null;
        if ($rerun = $request->query('from_id')) {
            $source = Search::find((int) $rerun);
        }

        return view('leadgen.searches.create', [
            'client'    => $client,
            'project'   => $project,
            'projects'  => $projects,
            'sources'   => $this->registry->all(),
            'countries' => self::COUNTRIES,
            'prefill'   => $source && $source->provider !== ImportSource::KEY ? $source->criteria : null,
            'prefillName' => $source?->name,
        ]);
    }

    /** City / district lookup for the area picker. */
    public function geocode(Request $request, Client $client, PlacesClient $places): JsonResponse
    {
        $this->open($request, $client);
        $data = $request->validate([
            'country' => 'required|string|size:2|alpha',
            'q'       => 'nullable|string|max:80',
        ]);

        return $this->proxy(fn () => $places->geocode(strtoupper($data['country']), (string) ($data['q'] ?? ''), 8));
    }

    /** Load an area (if needed) and list the categories present in it. */
    public function categories(Request $request, Client $client, PlacesClient $places): JsonResponse
    {
        $this->open($request, $client);
        $bbox = $this->bboxFromRequest($request);

        return $this->proxy(function () use ($places, $bbox) {
            $area = $places->area($bbox);
            if (($area['status'] ?? null) !== 'ready') {
                return $area;
            }
            return $places->categories((string) $area['area_id'], 300) + ['total' => $area['total'] ?? null];
        });
    }

    /** How many results the current criteria would return (area must be loaded). */
    public function estimate(Request $request, Client $client): JsonResponse
    {
        $this->open($request, $client);
        $criteria = $this->criteria($request);

        return $this->proxy(function () use ($criteria) {
            $n = $this->registry->get($criteria->source)->estimate($criteria);
            return $n === null ? ['status' => 'loading'] : ['status' => 'ready', 'estimate' => $n];
        });
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $criteria = $this->criteria($request);
        $source = $this->registry->get($criteria->source);

        if (!$source->isConfigured()) {
            throw ValidationException::withMessages(['source' => $source->label() . ' is not available yet.']);
        }
        $this->guardActiveLimit();

        $request->validate(['name' => 'nullable|string|max:160', 'saved' => 'nullable|boolean']);

        $search = Search::create([
            'name'       => $request->input('name') ?: $this->defaultName($criteria),
            'provider'   => $criteria->source,
            'criteria'   => $criteria->toArray(),
            'status'     => Search::QUEUED,
            'saved'      => $request->boolean('saved'),
            'progress'   => ['phase' => 'queued'],
            'rerun_of'   => $request->input('rerun_id') ? (int) $request->input('rerun_id') : null,
            'created_by' => $request->user()->id,
        ]);

        Event::log('search', $search->id, 'search.created', ['criteria' => $criteria->toArray()]);
        AuditLog::record('leadgen.search.started', ['target_type' => 'lg_search', 'target_id' => $search->id,
            'payload' => ['project_id' => $project->id, 'provider' => $search->provider]]);

        RunSearch::dispatch($project->id, $search->id);

        return redirect()->route('leadgen.searches.show', ['client' => $client->slug, 'id' => $search->id, 'project_id' => $project->id]);
    }

    public function show(Request $request, Client $client, int $id): View
    {
        [$project, $projects] = $this->open($request, $client);
        $search = Search::findOrFail($id);

        $outcome = in_array($request->query('outcome'), [SearchResult::NEW, SearchResult::DUPLICATE, SearchResult::EXISTING_CRM], true)
            ? $request->query('outcome') : null;

        $results = SearchResult::with('prospect')
            ->where('search_id', $search->id)
            ->when($outcome, fn ($q) => $q->where('outcome', $outcome))
            ->orderBy('id')
            ->paginate(50)->withQueryString();

        $events = Event::where('subject_type', 'search')->where('subject_id', $search->id)
            ->orderByDesc('id')->limit(20)->get();

        $attribution = $search->provider === OvertureProvider::KEY ? OvertureProvider::ATTRIBUTION : null;

        return view('leadgen.searches.show', compact('client', 'project', 'projects', 'search', 'results', 'outcome', 'events', 'attribution'));
    }

    /** Polled by the details page while a search runs. */
    public function progress(Request $request, Client $client, int $id): JsonResponse
    {
        $this->open($request, $client);
        $s = Search::findOrFail($id);
        return response()->json(['status' => $s->status, 'progress' => $s->progress, 'error' => $s->error]);
    }

    public function pause(Request $request, Client $client, int $id): RedirectResponse
    {
        return $this->transition($request, $client, $id, [Search::QUEUED, Search::RUNNING], Search::PAUSED, 'search.paused');
    }

    public function resume(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $this->guardActiveLimit();
        $back = $this->transition($request, $client, $id, [Search::PAUSED], Search::QUEUED, 'search.resumed');
        if (Search::whereKey($id)->value('status') === Search::QUEUED) {
            RunSearch::dispatch($project->id, $id);
        }
        return $back;
    }

    public function cancel(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $search = Search::findOrFail($id);
        // A paused search has no job running to notice "cancelling" — finish it here.
        $target = $search->status === Search::PAUSED ? Search::CANCELLED : Search::CANCELLING;
        $back = $this->transition($request, $client, $id, [Search::QUEUED, Search::RUNNING, Search::PAUSED], $target, 'search.cancel_requested');
        if ($target === Search::CANCELLED) {
            Search::whereKey($id)->update(['finished_at' => time()]);
        }
        return $back;
    }

    public function toggleSave(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        $search = Search::findOrFail($id);
        $search->forceFill(['saved' => !$search->saved])->save();
        return back()->with('success', $search->saved ? 'Search saved.' : 'Removed from saved searches.');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{0: Project, 1: \Illuminate\Support\Collection} */
    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }

    private function transition(Request $request, Client $client, int $id, array $from, string $to, string $event): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $search = Search::findOrFail($id);

        $moved = Search::whereKey($search->id)->whereIn('status', $from)->update(['status' => $to, 'updated_at' => time()]);
        if (!$moved) {
            return back()->withErrors(['search' => 'This search can no longer be ' . str_replace('search.', '', $event) . '.']);
        }
        Event::log('search', $search->id, $event, []);
        AuditLog::record('leadgen.' . $event, ['target_type' => 'lg_search', 'target_id' => $search->id, 'payload' => ['project_id' => $project->id]]);

        return back()->with('success', 'Search ' . str_replace(['search.', '_requested'], '', $event) . '.');
    }

    private function guardActiveLimit(): void
    {
        $active = Search::whereIn('status', [Search::QUEUED, Search::RUNNING, Search::CANCELLING])->count();
        if ($active >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['search' => 'You already have ' . self::MAX_ACTIVE . ' searches running. Wait for one to finish or pause it.']);
        }
    }

    private function bboxFromRequest(Request $request): array
    {
        $data = $request->validate(['bbox' => 'required|array|size:4', 'bbox.*' => 'numeric']);
        try {
            SearchCriteria::assertBbox($data['bbox']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['bbox' => $e->getMessage() === 'area too large'
                ? 'That area is too large. Pick a city or district, or use a radius of up to ' . SearchCriteria::MAX_RADIUS_KM . ' km.'
                : 'Choose an area first.']);
        }
        return array_map('floatval', $data['bbox']);
    }

    private function criteria(Request $request): SearchCriteria
    {
        $data = $request->validate([
            'source'          => 'required|string|in:' . implode(',', array_keys($this->registry->all())),
            'area_mode'       => 'required|in:place,radius',
            'area_label'      => 'nullable|string|max:160',
            'bbox'            => 'required_if:area_mode,place|nullable|array|size:4',
            'bbox.*'          => 'numeric',
            'lat'             => 'required_if:area_mode,radius|nullable|numeric|between:-90,90',
            'lng'             => 'required_if:area_mode,radius|nullable|numeric|between:-180,180',
            'radius_km'       => 'required_if:area_mode,radius|nullable|numeric|min:0.5|max:' . SearchCriteria::MAX_RADIUS_KM,
            'country_code'    => 'nullable|string|size:2|alpha',
            'categories'      => 'nullable|array|max:50',
            'categories.*'    => 'string|max:120|regex:/^[a-z0-9_]+$/',
            'keywords'        => 'nullable|string|max:120',
            'require_website' => 'nullable|boolean',
            'require_phone'   => 'nullable|boolean',
            'require_email'   => 'nullable|boolean',
            'require_social'  => 'nullable|boolean',
            'min_confidence'  => 'nullable|numeric|between:0,1',
            'exclude_closed'  => 'nullable|boolean',
            'exclude_existing_crm' => 'nullable|boolean',
            'max_results'     => 'nullable|integer|min:1|max:' . SearchCriteria::MAX_RESULTS_CEILING,
        ]);

        $radius = $data['area_mode'] === 'radius';
        try {
            return SearchCriteria::fromArray([
                'source'          => $data['source'],
                'bbox'            => $radius
                    ? SearchCriteria::bboxForRadius((float) $data['lng'], (float) $data['lat'], (float) $data['radius_km'])
                    : $data['bbox'],
                'center'          => $radius ? [(float) $data['lng'], (float) $data['lat']] : null,
                'radius_km'       => $radius ? (float) $data['radius_km'] : null,
                'area_label'      => $data['area_label'] ?? ($radius ? sprintf('%.1f km around %.4f, %.4f', $data['radius_km'], $data['lat'], $data['lng']) : ''),
                'country_code'    => $data['country_code'] ?? null,
                'categories'      => $data['categories'] ?? [],
                'keywords'        => $data['keywords'] ?? '',
                'require_website' => $request->boolean('require_website'),
                'require_phone'   => $request->boolean('require_phone'),
                'require_email'   => $request->boolean('require_email'),
                'require_social'  => $request->boolean('require_social'),
                'min_confidence'  => (float) ($data['min_confidence'] ?? 0),
                'exclude_closed'  => $request->boolean('exclude_closed', true),
                'exclude_existing_crm' => $request->boolean('exclude_existing_crm', true),
                'max_results'     => (int) ($data['max_results'] ?? 500),
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['bbox' => $e->getMessage() === 'area too large'
                ? 'That area is too large. Pick a city or district instead.'
                : 'Choose a valid area.']);
        }
    }

    private function defaultName(SearchCriteria $c): string
    {
        $what = $c->categories ? str_replace('_', ' ', implode(', ', array_slice($c->categories, 0, 2))) : ($c->keywords ?: 'Businesses');
        return mb_substr(ucfirst($what) . ($c->areaLabel ? ' in ' . $c->areaLabel : ''), 0, 160);
    }

    /** Worker calls for the form: errors become JSON the page can show. */
    private function proxy(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (ProviderException $e) {
            $status = match ($e->kind) {
                ProviderException::NOT_CONFIGURED => 503,
                ProviderException::UNSUPPORTED    => 422,
                default                           => 502,
            };
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $status);
        }
    }

    /** Countries offered in the area picker (ISO code => name). */
    public const COUNTRIES = [
        'AE' => 'United Arab Emirates', 'PK' => 'Pakistan', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
        'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman', 'EG' => 'Egypt', 'IN' => 'India',
        'BD' => 'Bangladesh', 'GB' => 'United Kingdom', 'IE' => 'Ireland', 'US' => 'United States',
        'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'DE' => 'Germany', 'FR' => 'France',
        'ES' => 'Spain', 'IT' => 'Italy', 'NL' => 'Netherlands', 'TR' => 'Turkey', 'MY' => 'Malaysia',
        'SG' => 'Singapore', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya',
    ];
}
