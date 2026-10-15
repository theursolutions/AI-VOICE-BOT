<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadGen\OfferProfile;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Offer profiles: what is being sold and to whom. Scoring and drafts read
 * from one. An agency keeps one per end client.
 */
class ProfileController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $profiles = OfferProfile::orderBy('name')->get();
        return view('leadgen.profiles.index', compact('client', 'project', 'projects', 'profiles'));
    }

    public function create(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        return view('leadgen.profiles.form', ['client' => $client, 'project' => $project, 'projects' => $projects,
            'profile' => new OfferProfile(['language' => 'en', 'tone' => 'professional'])]);
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        OfferProfile::create($this->validated($request) + ['created_by' => $request->user()->id]);
        return redirect()->route('leadgen.profiles.index', ['client' => $client->slug, 'project_id' => $project->id])->with('success', 'Offer profile saved.');
    }

    public function edit(Request $request, Client $client, int $id): View
    {
        [$project, $projects] = $this->open($request, $client);
        return view('leadgen.profiles.form', ['client' => $client, 'project' => $project, 'projects' => $projects, 'profile' => OfferProfile::findOrFail($id)]);
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        OfferProfile::findOrFail($id)->update($this->validated($request));
        return redirect()->route('leadgen.profiles.index', ['client' => $client->slug, 'project_id' => $project->id])->with('success', 'Offer profile saved.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        OfferProfile::findOrFail($id)->softDelete();
        return back()->with('success', 'Offer profile deleted. Past scores are kept.');
    }

    private function validated(Request $request): array
    {
        $criteria = array_keys(config('leadgen.criteria'));
        $d = $request->validate([
            'name'         => 'required|string|max:120',
            'product'      => 'required|string|max:2000',
            'icp'          => 'required|string|max:2000',
            'value_prop'   => 'required|string|max:2000',
            'exclusions'   => 'nullable|string|max:2000',
            'questions'    => 'nullable|string|max:2000',
            'weights'      => 'nullable|array',
            'weights.*'    => 'nullable|integer|min:0|max:100',
            'sender_name'  => 'nullable|string|max:120',
            'booking_link' => 'nullable|url:https|max:500',
            'language'     => ['required', Rule::in(array_keys(config('leadgen.languages')))],
            'tone'         => 'required|in:professional,friendly,direct',
        ]);
        $lines = fn (?string $s) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $s)))) ?: null;

        $scoring = [];
        foreach (config('leadgen.criteria') as $key => $c) {
            $scoring[$key] = ['label' => $c['label'], 'weight' => (int) ($d['weights'][$key] ?? $c['weight'])];
        }

        return [
            'name' => $d['name'], 'product' => $d['product'], 'icp' => $lines($d['icp']), 'value_prop' => $d['value_prop'],
            'exclusions' => $lines($d['exclusions'] ?? null), 'questions' => $lines($d['questions'] ?? null),
            'scoring' => $scoring, 'sender_name' => $d['sender_name'] ?? null, 'booking_link' => $d['booking_link'] ?? null,
            'language' => $d['language'], 'tone' => $d['tone'],
        ];
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
