<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\CrmProviders\HubSpotClient;
use App\Services\CrmProviders\TokenVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Browser-facing OAuth dance for HubSpot.
 *
 *   /oauth/hubspot/start?project_id=…&name=…
 *     -> mint state, stash in session, 302 to HubSpot's authorize URL.
 *
 *   /oauth/hubspot/callback?code=…&state=…
 *     -> verify state, exchange code, encrypt tokens, persist a
 *        new data_sources row, redirect to dashboard.
 *
 * Both endpoints require `auth + active.client` so we can scope the new
 * source to the user's active workspace.
 */
class HubSpotOAuthController extends Controller
{
    public function __construct(
        private HubSpotClient $hubspot,
        private TokenVault $vault,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'nullable|string|max:255',
        ]);

        $project = $this->projectForUser($request, (int) $data['project_id']);
        if (!$project) {
            return redirect()->route('dashboard')->with('error', 'Project not found in your workspace.');
        }

        $state = Str::random(40);

        $request->session()->put('hubspot_oauth', [
            'state'      => $state,
            'project_id' => $project->id,
            'name'       => $data['name'] ?? 'HubSpot',
            'created_at' => time(),
        ]);

        return redirect()->away($this->hubspot->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $stash = $request->session()->pull('hubspot_oauth');

        if ($request->filled('error')) {
            return redirect()->route('dashboard')
                ->with('error', 'HubSpot authorization was cancelled: '.$request->string('error'));
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if (!$stash || !hash_equals((string) ($stash['state'] ?? ''), $state) || $code === '') {
            return redirect()->route('dashboard')
                ->with('error', 'HubSpot OAuth state mismatch — please try connecting again.');
        }

        $project = $this->projectForUser($request, (int) $stash['project_id']);
        if (!$project) {
            return redirect()->route('dashboard')->with('error', 'Project no longer accessible.');
        }

        $token = $this->hubspot->exchangeCodeForToken($code);
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            return redirect()->route('dashboard')
                ->with('error', 'HubSpot did not return valid tokens. Check your client credentials.');
        }

        $now = time();
        $config = [
            'provider'      => 'hubspot',
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at'    => $now + (int) ($token['expires_in'] ?? 0),
            'scopes'        => [
                'crm.objects.contacts.read',
                'crm.objects.companies.read',
                'crm.objects.deals.read',
                'oauth',
            ],
            'hub_id'        => $token['hub_id'] ?? null,
        ];

        DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_CRM_OAUTH,
            'name'       => $stash['name'] ?? 'HubSpot',
            'config'     => $this->vault->encryptConfig($config),
            'status'     => DataSource::STATUS_ACTIVE,
            'is_active'  => 'Yes',
            'created_at' => $now,
            'update_at'  => $now,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'HubSpot connected. The assistant can now ground answers in your CRM.');
    }

    /**
     * Resolve a project the current user is allowed to attach a source to:
     * must belong to their active client/workspace.
     */
    private function projectForUser(Request $request, int $projectId): ?Project
    {
        $user = $request->user();
        if (!$user || !$user->active_client_id) {
            return null;
        }

        return Project::whereHas('clients', function ($q) use ($user) {
                $q->where('clients.id', $user->active_client_id);
            })
            ->where('id', $projectId)
            ->first();
    }
}
