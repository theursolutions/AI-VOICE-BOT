<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\Project;
use App\Services\CrmProviders\PipedriveClient;
use App\Services\CrmProviders\SalesforceClient;
use App\Services\CrmProviders\TokenVault;
use App\Services\CrmProviders\ZohoClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Generic browser-facing OAuth dance for CRM connectors that share the
 * same start -> redirect -> callback shape.
 *
 *   /oauth/{provider}/start?project_id=…&name=…
 *     -> mint state, stash in session, 302 to provider authorize URL.
 *
 *   /oauth/{provider}/callback?code=…&state=…
 *     -> verify state, exchange code, encrypt tokens, persist a
 *        new data_sources row, redirect to dashboard.
 *
 * HubSpot is intentionally NOT routed here — it keeps its own controller
 * to avoid disturbing existing routes/links. New providers (salesforce,
 * pipedrive, zoho) all flow through this single class.
 */
class OAuthController extends Controller
{
    private const PROVIDERS = ['salesforce', 'pipedrive', 'zoho'];

    /** Display names used in flash messages / data_sources.name defaults. */
    private const LABELS = [
        'salesforce' => 'Salesforce',
        'pipedrive'  => 'Pipedrive',
        'zoho'       => 'Zoho CRM',
    ];

    public function __construct(
        private SalesforceClient $salesforce,
        private PipedriveClient $pipedrive,
        private ZohoClient $zoho,
        private TokenVault $vault,
    ) {}

    public function start(Request $request, string $provider): RedirectResponse
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return redirect()->route('dashboard')
                ->with('error', "Unsupported OAuth provider '{$provider}'.");
        }

        $data = $request->validate([
            'project_id' => 'required|integer',
            'name'       => 'nullable|string|max:255',
        ]);

        $project = $this->projectForUser($request, (int) $data['project_id']);
        if (!$project) {
            return redirect()->route('dashboard')->with('error', 'Project not found in your workspace.');
        }

        $client = $this->clientFor($provider);
        $state  = Str::random(40);

        $request->session()->put($this->sessionKey($provider), [
            'state'      => $state,
            'project_id' => $project->id,
            'name'       => $data['name'] ?? self::LABELS[$provider],
            'created_at' => time(),
        ]);

        return redirect()->away($client->authorizationUrl($state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return redirect()->route('dashboard')
                ->with('error', "Unsupported OAuth provider '{$provider}'.");
        }

        $label = self::LABELS[$provider];
        $stash = $request->session()->pull($this->sessionKey($provider));

        if ($request->filled('error')) {
            return redirect()->route('dashboard')
                ->with('error', $label.' authorization was cancelled: '.$request->string('error'));
        }

        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if (!$stash || !hash_equals((string) ($stash['state'] ?? ''), $state) || $code === '') {
            return redirect()->route('dashboard')
                ->with('error', $label.' OAuth state mismatch — please try connecting again.');
        }

        $project = $this->projectForUser($request, (int) $stash['project_id']);
        if (!$project) {
            return redirect()->route('dashboard')->with('error', 'Project no longer accessible.');
        }

        $client = $this->clientFor($provider);
        $token  = $client->exchangeCodeForToken($code);

        // Zoho can legitimately omit refresh_token if access_type wasn't `offline`,
        // but our authorize URL forces offline+consent, so we still require both.
        if (empty($token['access_token']) || empty($token['refresh_token'])) {
            return redirect()->route('dashboard')
                ->with('error', $label.' did not return valid tokens. Check your client credentials.');
        }

        $now    = time();
        $config = $this->buildConfig($provider, $token, $now);

        DataSource::create([
            'project_id' => $project->id,
            'type'       => DataSource::TYPE_CRM_OAUTH,
            'name'       => $stash['name'] ?? $label,
            'config'     => $this->vault->encryptConfig($config),
            'status'     => DataSource::STATUS_ACTIVE,
            'is_active'  => 'Yes',
            'created_at' => $now,
            'update_at'  => $now,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('dashboard')
            ->with('success', $label.' connected. The assistant can now ground answers in your CRM.');
    }

    /**
     * Build the per-provider `data_sources.config` payload prior to encryption.
     */
    private function buildConfig(string $provider, array $token, int $now): array
    {
        $base = [
            'provider'      => $provider,
            'access_token'  => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at'    => $now + (int) ($token['expires_in'] ?? 0),
        ];

        return match ($provider) {
            'salesforce' => $base + [
                'scopes'       => ['api', 'refresh_token', 'offline_access'],
                'instance_url' => $token['instance_url'] ?? null,
            ],
            'pipedrive' => $base + [
                'scopes'     => ['contacts:read', 'deals:read'],
                'api_domain' => $token['api_domain'] ?? null,
            ],
            'zoho' => $base + [
                'scopes' => [
                    'ZohoCRM.modules.contacts.READ',
                    'ZohoCRM.modules.accounts.READ',
                    'ZohoCRM.modules.deals.READ',
                    'ZohoCRM.users.READ',
                    'offline_access',
                ],
                'api_domain' => $token['api_domain'] ?? null,
            ],
            default => $base,
        };
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

    private function clientFor(string $provider): SalesforceClient|PipedriveClient|ZohoClient
    {
        return match ($provider) {
            'salesforce' => $this->salesforce,
            'pipedrive'  => $this->pipedrive,
            'zoho'       => $this->zoho,
        };
    }

    private function sessionKey(string $provider): string
    {
        return $provider.'_oauth';
    }
}
