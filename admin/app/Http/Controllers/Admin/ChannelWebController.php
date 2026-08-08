<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use Msd\MetaChannels\Models\ChannelConnection;
use Msd\MetaChannels\Models\ChannelOnboardingLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Channels onboarding — connect/list/enable/disable the Meta channels
 * (WhatsApp number, Instagram, Facebook page) a project talks on.
 *
 * Project-scoped. ChannelConnection lives in the app DB, so no tenant
 * switch is needed here.
 */
class ChannelWebController extends Controller
{
    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project   = $projects->firstWhere('id', $projectId);

        $connections = collect();
        $onboardingLogs = collect();
        if ($project) {
            $connections = ChannelConnection::where('project_id', $project->id)
                ->orderBy('provider')
                ->orderBy('name')
                ->get();
            // Eager-load the payload: the view asks every failed attempt
            // whether it can be replayed from stored credentials, and doing
            // that lazily would be a query per row.
            $onboardingLogs = ChannelOnboardingLog::with('payload')
                ->where('project_id', $project->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get();
        }

        $providers = ChannelConnection::PROVIDERS;

        return view('channels.index', compact(
            'client', 'projects', 'project', 'projectId', 'connections', 'providers', 'onboardingLogs'
        ));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'provider'     => 'required|in:' . implode(',', array_keys(ChannelConnection::PROVIDERS)),
            'name'         => 'nullable|string|max:191',
            'external_id'  => 'nullable|string|max:191',
            'access_token' => 'nullable|string|max:4096',
            // Not `waba_id`: DecodeHashids rewrites every *_id request key to
            // an integer, so a numeric WABA id would fail this string rule.
            'waba'         => 'nullable|string|max:191',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        ChannelConnection::create([
            'project_id'   => $project->id,
            'provider'     => $data['provider'],
            'name'         => $data['name'] ?: null,
            'external_id'  => $data['external_id'] ?: null,
            'access_token' => $data['access_token'] ?: null,
            'status'       => ChannelConnection::STATUS_ENABLED,
            'metadata'     => array_filter(['waba_id' => $data['waba'] ?? null]),
        ]);

        $label = ChannelConnection::PROVIDERS[$data['provider']] ?? $data['provider'];
        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', "{$label} channel connected.");
    }

    public function toggle(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);

        $conn = ChannelConnection::findOrFail($id);
        abort_unless((int) $conn->project_id === $project->id, 404);

        $conn->status = $conn->isEnabled()
            ? ChannelConnection::STATUS_DISABLED
            : ChannelConnection::STATUS_ENABLED;
        $conn->save();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', $conn->isEnabled() ? 'Channel enabled.' : 'Channel disabled.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $data = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);

        $conn = ChannelConnection::findOrFail($id);
        abort_unless((int) $conn->project_id === $project->id, 404);

        $conn->delete();

        return back()
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Channel removed.');
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
    }
}
