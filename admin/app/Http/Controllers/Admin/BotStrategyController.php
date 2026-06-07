<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DataSource;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-project Bot Knowledge Strategy: which data tiers the bot is
 * allowed to consult per turn. Persisted to
 * projects.json_data['data_strategy'] = { website: bool, document: bool,
 * data_snapshot: bool, webhook: bool, crm_oauth: bool, database: bool }.
 *
 * Default: every tier enabled. DataSourceRouter::resolve() filters its
 * source list by this map before iterating.
 */
class BotStrategyController extends Controller
{
    /** Default — every tier on. */
    public const DEFAULTS = [
        'website'       => true,
        'document'      => true,
        'data_snapshot' => true,
        'webhook'       => true,
        'crm_oauth'     => true,
        'database'      => true,
    ];

    /** Tier presentation metadata used by both the index view and the form. */
    public const TIER_META = [
        'data_snapshot' => [
            'label'  => 'Data snapshot',
            'tier'   => 'B · SAFEST',
            'icon'   => 'database-zap',
            'color'  => '#15803d',
            'bg'     => '#dcfce7',
            'desc'   => 'CSV / JSON exports indexed via RAG. No live DB access.',
        ],
        'webhook' => [
            'label'  => 'Webhook tools',
            'tier'   => 'C · MEDIUM',
            'icon'   => 'zap',
            'color'  => '#b45309',
            'bg'     => '#fef3c7',
            'desc'   => 'Bot calls your HTTP endpoint when intent matches. You control queries.',
        ],
        'database' => [
            'label'  => 'Live database',
            'tier'   => 'A · ADVANCED',
            'icon'   => 'database',
            'color'  => '#b91c1c',
            'bg'     => '#fee2e2',
            'desc'   => 'SQL generated on the fly. SELECT-only, row + timeout capped.',
        ],
        'document' => [
            'label'  => 'Knowledge documents',
            'tier'   => 'RAG',
            'icon'   => 'file-text',
            'color'  => '#6366f1',
            'bg'     => '#eef2ff',
            'desc'   => 'PDFs, DOCX, TXT chunks indexed for retrieval.',
        ],
        'website' => [
            'label'  => 'Website crawl',
            'tier'   => 'RAG',
            'icon'   => 'globe',
            'color'  => '#0ea5e9',
            'bg'     => '#e0f2fe',
            'desc'   => 'Crawled pages from your public site.',
        ],
        'crm_oauth' => [
            'label'  => 'HubSpot CRM',
            'tier'   => 'CRM',
            'icon'   => 'link-2',
            'color'  => '#7c3aed',
            'bg'     => '#ede9fe',
            'desc'   => 'OAuth-connected CRM data (contacts, deals, notes).',
        ],
    ];

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'json_data']);

        $projectId = (int) ($request->query('project_id') ?: optional($projects->first())->id);
        $project = $projects->firstWhere('id', $projectId);

        $strategy = self::DEFAULTS;
        if ($project) {
            $stored = (array) data_get($project->json_data, 'data_strategy', []);
            $strategy = array_merge($strategy, array_intersect_key($stored, self::DEFAULTS));
        }

        // For each tier, count how many ACTIVE sources of that type the
        // project has, so the user knows the toggle isn't theoretical.
        $counts = [];
        if ($project) {
            foreach (array_keys(self::DEFAULTS) as $type) {
                $counts[$type] = DataSource::where('project_id', $project->id)
                    ->where('type', $type)
                    ->where('status', DataSource::STATUS_ACTIVE)
                    ->where('is_active', 'Yes')
                    ->count();
            }
        }

        $tierMeta = self::TIER_META;

        return view('bot-strategy.index', compact(
            'client', 'projects', 'project', 'projectId',
            'strategy', 'counts', 'tierMeta',
        ));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'strategy'   => 'nullable|array',
        ]);

        $project = Project::where('client_id', $client->id)
            ->where('id', $data['project_id'])
            ->firstOrFail();

        $next = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $type) {
            $next[$type] = !empty($data['strategy'][$type]);
        }

        $json = is_array($project->json_data) ? $project->json_data : [];
        $json['data_strategy'] = $next;
        $project->json_data = $json;
        $project->save();

        return redirect()
            ->route('bot-strategy.index', ['client' => $client->slug])
            ->withInput(['project_id' => $project->id])
            ->with('success', 'Bot knowledge strategy saved.');
    }
}
