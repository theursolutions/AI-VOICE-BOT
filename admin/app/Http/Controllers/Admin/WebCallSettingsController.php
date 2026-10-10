<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\WebCallSession;
use App\Services\Telephony\SipServer;
use App\Services\Telephony\WebCalls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Telephony → Website call button: per project, a "Call us" button for the
 * customer's own website that connects visitors to the project's AI agent
 * in the browser, with per-visitor limits.
 */
class WebCallSettingsController extends Controller
{
    public function __construct(private WebCalls $calls, private SipServer $server) {}

    public function index(Client $client): View
    {
        $projects = Project::where('client_id', $client->id)->orderBy('name')
            ->get(['id', 'name', 'json_data', 'project_api_key']);

        $recent = WebCallSession::where('client_id', $client->id)
            ->whereNotNull('started_at')
            ->orderByDesc('started_at')
            ->limit(25)
            ->get(['id', 'project_id', 'ip', 'origin', 'page_url', 'status', 'started_at', 'ended_at']);

        return view('telephony.web-call', [
            'title'     => 'Website call button',
            'client'    => $client,
            'projects'  => $projects,
            'settings'  => $projects->mapWithKeys(fn ($p) => [$p->id => $this->calls->settings($p)]),
            'recent'    => $recent,
            'scriptUrl' => url('/webcall/call.js'),
            'serverReady' => str_starts_with($this->server->wsUrl(), 'wss://') || app()->environment('local'),
        ]);
    }

    public function save(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'      => 'required|integer',
            'enabled'         => 'nullable|boolean',
            'allowed_origins' => 'nullable|string|max:1000',
            'max_seconds'     => 'required|integer|min:30|max:900',
            'calls_per_day'   => 'required|integer|min:1|max:50',
            'gap_hours'       => 'required|numeric|min:0|max:24',
            'button_label'    => 'required|string|max:40',
        ]);

        $project = Project::where('client_id', $client->id)->findOrFail($data['project_id']);

        // "serveai.com.pk, https://www.example.com/" → ["serveai.com.pk", "www.example.com"]
        $origins = collect(preg_split('/[\s,]+/', (string) ($data['allowed_origins'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($o) => strtolower(parse_url(str_contains($o, '://') ? $o : "https://{$o}", PHP_URL_HOST) ?: ''))
            ->filter()->unique()->values()->all();

        $this->calls->save($project, [
            'enabled'         => (bool) ($data['enabled'] ?? false),
            'allowed_origins' => $origins,
            'max_seconds'     => (int) $data['max_seconds'],
            'calls_per_day'   => (int) $data['calls_per_day'],
            'gap_hours'       => (float) $data['gap_hours'],
            'button_label'    => trim($data['button_label']),
        ]);

        return back()->with('success', "Website call button saved for {$project->name}.");
    }
}
