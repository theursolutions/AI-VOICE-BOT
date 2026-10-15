<?php

namespace App\Http\Controllers\LeadGen;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LeadGen\LeadList;
use App\Models\LeadGen\LeadListItem;
use App\Services\LeadGen\LeadGenContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Saved prospect lists — what a campaign is built from. */
class ListController extends Controller
{
    public function __construct(private LeadGenContext $context) {}

    public function index(Request $request, Client $client): View
    {
        [$project, $projects] = $this->open($request, $client);
        $lists = LeadList::withCount('items')->orderByDesc('id')->paginate(30)->withQueryString();
        return view('leadgen.lists.index', compact('client', 'project', 'projects', 'lists'));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $data = $request->validate(['name' => 'required|string|max:160', 'description' => 'nullable|string|max:1000']);
        $list = LeadList::create($data + ['created_by' => $request->user()->id]);
        return redirect()->route('leadgen.prospects.index', ['client' => $client->slug, 'project_id' => $project->id, 'list_id' => $list->id])
            ->with('success', 'List created. Select prospects and use “Add to list”.');
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->open($request, $client);
        $list = LeadList::findOrFail($id);
        $list->update($request->validate(['name' => 'required|string|max:160', 'description' => 'nullable|string|max:1000']));
        return back()->with('success', 'List saved.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        [$project] = $this->open($request, $client);
        $list = LeadList::findOrFail($id);
        LeadListItem::where('list_id', $list->id)->delete();
        $list->softDelete();
        return redirect()->route('leadgen.lists.index', ['client' => $client->slug, 'project_id' => $project->id])
            ->with('success', 'List deleted. The prospects in it are kept.');
    }

    private function open(Request $request, Client $client): array
    {
        $projects = $this->context->projects($request, $client);
        $project  = $this->context->resolve($request, $client);
        abort_unless($project && LeadGenContext::tablesReady(), 404);
        return [$project, $projects];
    }
}
