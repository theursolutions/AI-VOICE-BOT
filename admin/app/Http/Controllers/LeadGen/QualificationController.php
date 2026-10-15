<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Jobs\LeadGen\QualifyProspects;
use App\Models\Client;
use App\Models\LeadGen\Event;
use App\Models\LeadGen\OfferProfile;
use App\Models\LeadGen\Prospect;
use App\Models\LeadGen\Qualification;
use App\Services\LeadGen\Ai\Qualifier;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Scoring prospects against an offer profile, and the review queue. */
class QualificationController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    /** The review queue: current scores for one profile, best first. */
    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $profiles = OfferProfile::orderBy('name')->get(['id', 'name']);
        $profile  = $request->query('profile_id') ? $profiles->firstWhere('id', (int) $request->query('profile_id')) : $profiles->first();
        $band     = in_array($request->query('band'), Qualification::BANDS, true) ? $request->query('band') : null;

        $rows = collect();
        if ($profile) {
            $rows = Qualification::with('prospect')
                ->where('profile_id', $profile->id)->where('is_current', true)
                ->when($band, fn ($q) => $q->where(fn ($w) => $w->where('override_band', $band)
                    ->orWhere(fn ($x) => $x->whereNull('override_band')->where('band', $band))))
                ->whereIn('prospect_id', Prospect::whereNotIn('status', ['rejected'])->select('id'))
                ->orderByDesc('score')->orderBy('id')
                ->paginate(30)->withQueryString();
        }

        return view('leadgen.review.index', compact('client', 'project', 'projects', 'profiles', 'profile', 'band', 'rows'));
    }

    /** Score one prospect now (the prospect page's button). */
    public function qualifyOne(Request $request, Client $client, int $id, Qualifier $qualifier): RedirectResponse
    {
        $this->open($request, $client);
        $p = Prospect::findOrFail($id);
        $profile = OfferProfile::findOrFail((int) $request->validate(['profile_id' => 'required|integer'])['profile_id']);
        try {
            $q = $qualifier->qualify($p, $profile);
        } catch (\Throwable $e) {
            report($e);
            return back()->withErrors(['qualify' => 'Scoring failed: the AI service didn’t answer. Try again shortly.']);
        }
        return back()->with('success', "Scored {$q->score} — " . str_replace('_', ' ', $q->band) . '.');
    }

    /** Queue scoring for many prospects (bulk action). */
    public function queue(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $d = $request->validate(['profile_id' => 'required|integer', 'ids' => 'required|array|max:500', 'ids.*' => 'string|max:40']);
        $profile = OfferProfile::findOrFail($d['profile_id']);
        $ids = collect($d['ids'])->map(fn ($v) => ctype_digit((string) $v) ? (int) $v : \App\Support\Hashid::decode((string) $v))->filter();
        $ids = Prospect::whereIn('id', $ids)->whereNull('suppressed_at')->pluck('id')->all();

        foreach (array_chunk($ids, QualifyProspects::CHUNK) as $chunk) {
            QualifyProspects::dispatch($project->id, $profile->id, $chunk, time());
        }
        return redirect()->route('leadgen.review.index', ['client' => $client->slug, 'project_id' => $project->id, 'profile_id' => $profile->id])
            ->with('success', 'Scoring ' . count($ids) . ' prospects against “' . $profile->name . '”. Results appear here as they finish.');
    }

    /** A person disagrees with the score: record their band and why. */
    public function override(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        $q = Qualification::findOrFail($id);
        $d = $request->validate([
            'override_band'   => ['nullable', Rule::in(Qualification::BANDS)],
            'override_reason' => 'nullable|required_with:override_band|string|max:500',
        ]);
        $q->forceFill(['override_band' => $d['override_band'] ?? null, 'override_reason' => $d['override_reason'] ?? null,
            'reviewed_by' => $request->user()->id])->save();
        Event::log('prospect', $q->prospect_id, 'qualification.overridden', ['band' => $d['override_band'] ?? null]);
        return back()->with('success', 'Saved your review.');
    }

    private function open(Request $request, Client $client): array
    {
        return $this->context->open($request, $client);
    }
}
