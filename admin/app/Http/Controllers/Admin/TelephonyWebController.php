<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BotAgent;
use App\Models\Client;
use App\Models\Flow;
use App\Models\Project;
use App\Models\ProjectTwilioAccount;
use App\Models\Skill;
use App\Services\Telephony\WelcomeAudioService;
use App\Services\Tenant\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Per-project telephony settings — multiple phone numbers per project,
 * each routed to either a pool of specific agents or to a whole skill.
 *
 * Storage shape (projects.json_data['telephony']):
 *
 *   {
 *     "numbers": [
 *       {
 *         "phone_number":  "+12346352160",
 *         "enabled":       true,
 *         "welcome_voice": "Polly.Matthew",     // Polly fallback
 *         "routing_type":  "agents" | "skill",
 *         "agent_ids":     [1, 3],              // used when routing_type=agents
 *         "skill_id":      5                    // used when routing_type=skill
 *       },
 *       ...
 *     ]
 *   }
 */
class TelephonyWebController extends Controller
{
    public function __construct(private TenantManager $tenants) {}

    public function index(Request $request, Client $client): View
    {
        $projects = Project::where('client_id', $client->id)
            ->orderBy('name')
            ->get(['id', 'name', 'json_data']);

        // Build agents + skills lookup per project so the modals can
        // populate their dropdowns without N extra queries client-side.
        $perProject = [];
        foreach ($projects as $p) {
            $this->tenants->useFor($p);
            $perProject[$p->id] = [
                // `voice` is eager-loaded so the number modal can show WHICH
                // cloned voice each agent answers in. Without it the only
                // voice control on this screen was the Polly fallback, which
                // reads as "telephony has no cloned-voice setting at all".
                'agents' => BotAgent::with('voice:id,name')
                    ->where('project_id', $p->id)
                    ->where('status', BotAgent::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->get(['id', 'name', 'voice_id']),
                'skills' => Skill::where('project_id', $p->id)
                    ->where('status', Skill::STATUS_ACTIVE)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                // Only active flows are bindable — drafts/archived ones
                // can't answer real calls anyway, no point exposing them.
                'flows'  => Flow::where('project_id', $p->id)
                    ->where('status', Flow::STATUS_ACTIVE)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get(['id', 'name', 'language']),
            ];
        }

        $base = rtrim((string) config('services.twilio.webhook_base', ''), '/');
        $webhookUrls = [
            'voice'  => $base ? $base . '/api/telephony/twilio/voice'  : null,
            'status' => $base ? $base . '/api/telephony/twilio/status' : null,
        ];

        // Per-project Twilio connection state. Deliberately an array, not
        // the model: the token is $hidden, but a plain array is the safer
        // contract for something a Blade view receives.
        $twilio = [];
        foreach ($projects as $p) {
            $acc = ProjectTwilioAccount::where('project_id', $p->id)->first();
            $twilio[$p->id] = $acc ? [
                'account_sid'   => $acc->account_sid,
                'token_hint'    => $acc->auth_token_hint,
                'friendly_name' => $acc->friendly_name,
                'is_trial'      => $acc->isTrial(),
                'verified_at'   => $acc->verified_at?->diffForHumans(),
            ] : null;
        }

        return view('telephony.index', [
            'client'      => $client,
            'projects'    => $projects,
            'perProject'  => $perProject,
            'webhookUrls' => $webhookUrls,
            'envDefault'  => trim((string) config('services.twilio.phone_number', '')),
            'twilio'      => $twilio,
        ]);
    }

    /**
     * POST /telephony/credentials — save a project's own Twilio credentials.
     *
     * The credentials are checked against Twilio before being stored. That
     * single round-trip is worth it: an unverified typo doesn't fail here, it
     * fails days later as a customer call that gets a 403 from our signature
     * check — invisible to them and to us.
     */
    public function saveCredentials(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'  => 'required|integer',
            'account_sid' => 'required|string|max:64',
            'auth_token'  => 'required|string|max:64',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);
        $sid     = trim($data['account_sid']);
        $token   = trim($data['auth_token']);

        // Catch the two mistakes people actually make — pasting an API Key
        // (SK…) instead of the Account SID, or swapping the two fields — with
        // a message that says what to do, rather than a bare 401 from Twilio.
        if (! preg_match('/^AC[0-9a-f]{32}$/i', $sid)) {
            return back()->withErrors(['account_sid' => str_starts_with(strtoupper($sid), 'SK')
                ? 'That looks like an API Key SID. We need the Account SID — it starts with "AC".'
                : 'That Account SID doesn\'t look right. It starts with "AC" and is 34 characters long.',
            ])->withInput();
        }

        $check = $this->verifyTwilioCredentials($sid, $token);
        if (! $check['ok']) {
            return back()->withErrors(['auth_token' => $check['error']])->withInput();
        }

        $account = ProjectTwilioAccount::firstOrNew(['project_id' => $project->id]);
        $account->fill([
            'account_sid'   => $sid,
            'friendly_name' => $check['friendly_name'],
            'account_type'  => $check['type'],
            'status'        => ProjectTwilioAccount::STATUS_CONNECTED,
            'last_error'    => null,
            'verified_at'   => now(),
        ]);
        $account->setToken($token);
        $account->save();

        // The SID identifies an account and is safe to record; the token
        // never is.
        AuditLog::record('telephony.credentials_saved', [
            'target_type' => 'project',
            'target_id'   => $project->id,
            'payload'     => ['account_sid' => $sid, 'type' => $check['type']],
        ]);

        $msg = "Twilio account connected for {$project->name}.";
        if (strcasecmp((string) $check['type'], 'Trial') === 0) {
            $msg .= ' Note: this is a Trial account — it can only call numbers verified in your Twilio console.';
        }

        return back()->with('success', $msg);
    }

    /** POST /telephony/credentials/delete — forget a project's credentials. */
    public function deleteCredentials(Request $request, Client $client): RedirectResponse
    {
        $data    = $request->validate(['project_id' => 'required|integer']);
        $project = $this->guard($client, (int) $data['project_id']);

        ProjectTwilioAccount::where('project_id', $project->id)->delete();

        AuditLog::record('telephony.credentials_removed', [
            'target_type' => 'project', 'target_id' => $project->id,
        ]);

        // The numbers stay on the project deliberately: removing credentials
        // is usually a token rotation, and silently unrouting live phone
        // numbers would take a customer's phone line down.
        return back()->with('success', 'Twilio credentials removed. Calls to this project\'s numbers will be rejected until you add them again.');
    }

    /**
     * One GET against Twilio to confirm the credentials work and read back
     * what kind of account they belong to.
     *
     * @return array{ok:bool,error:?string,friendly_name:?string,type:?string}
     */
    private function verifyTwilioCredentials(string $sid, string $token): array
    {
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            return ['ok' => false, 'error' => 'Could not reach Twilio to check those credentials. Try again in a moment.',
                    'friendly_name' => null, 'type' => null];
        }

        $body = $raw ? (json_decode($raw, true) ?: []) : [];

        if ($code === 401) {
            return ['ok' => false, 'error' => 'Twilio rejected those credentials. Check the Auth Token — copy it with the button in the console, and note it changes if you rotate it.',
                    'friendly_name' => null, 'type' => null];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => $body['message'] ?? "Twilio returned HTTP {$code}.",
                    'friendly_name' => null, 'type' => null];
        }

        return [
            'ok'            => true,
            'error'         => null,
            'friendly_name' => $body['friendly_name'] ?? null,
            'type'          => $body['type'] ?? null,
        ];
    }

    /**
     * POST /telephony/numbers — add OR update a number on a project.
     * The form sends `number_index` of `__new__` for additions, or a
     * numeric index for edits.
     */
    public function saveNumber(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'    => 'required|integer',
            'number_index'  => 'required|string',        // numeric or "__new__"
            'phone_number'  => 'required|string|max:32',
            'enabled'       => 'nullable|boolean',
            'welcome_voice' => 'nullable|string|max:60',
            'routing_type'  => 'required|in:agents,skill,flow',
            'agent_ids'     => 'nullable|array',
            'agent_ids.*'   => 'integer',
            'skill_id'      => 'nullable|integer',
            'flow_id'       => 'nullable|integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        // Normalise phone number to E.164-ish.
        $number = preg_replace('/\s+/', '', $data['phone_number']);
        if (!str_starts_with($number, '+')) {
            $number = '+' . ltrim($number, '0');
        }

        $json = is_array($project->json_data) ? $project->json_data : [];
        $numbers = (array) data_get($json, 'telephony.numbers', []);

        // Conflict check — refuse to assign the same E.164 number twice
        // within the workspace.
        foreach (Project::where('client_id', $client->id)->get(['id', 'name', 'json_data']) as $otherP) {
            foreach ((array) data_get($otherP->json_data, 'telephony.numbers', []) as $idx => $n) {
                $sameProj = ($otherP->id === $project->id);
                $sameIdx  = ($data['number_index'] !== '__new__' && (int) $data['number_index'] === $idx);
                if ($sameProj && $sameIdx) continue;
                if (($n['phone_number'] ?? '') === $number) {
                    return back()->withErrors([
                        'phone_number' => "Number {$number} is already assigned to {$otherP->name}.",
                    ])->withInput();
                }
            }
        }

        $entry = [
            'phone_number'  => $number,
            'enabled'       => $request->boolean('enabled'),
            'welcome_voice' => $data['welcome_voice'] ?: 'Polly.Matthew',
            'routing_type'  => $data['routing_type'],
            'agent_ids'     => array_values($data['agent_ids'] ?? []),
            'skill_id'      => $data['routing_type'] === 'skill' ? (int) ($data['skill_id'] ?? 0) ?: null : null,
            'flow_id'       => $data['routing_type'] === 'flow'  ? (int) ($data['flow_id']  ?? 0) ?: null : null,
        ];

        if ($data['number_index'] === '__new__') {
            $numbers[] = $entry;
        } else {
            $idx = (int) $data['number_index'];
            if (isset($numbers[$idx])) {
                $numbers[$idx] = $entry;
            } else {
                $numbers[] = $entry;
            }
        }

        $json['telephony'] = array_merge((array) ($json['telephony'] ?? []), [
            'numbers' => array_values($numbers),
        ]);
        $project->json_data = $json;
        $project->save();

        // Wipe cached welcome wav — fallback Polly voice may have changed.
        try { app(WelcomeAudioService::class)->invalidateForProject($project->id); } catch (\Throwable $e) {}

        return redirect()
            ->route('telephony.index', ['client' => $client->slug])
            ->with('success', "Number {$number} saved for {$project->name}.");
    }

    public function deleteNumber(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'number_index' => 'required|integer',
        ]);

        $project = $this->guard($client, (int) $data['project_id']);

        $json = is_array($project->json_data) ? $project->json_data : [];
        $numbers = (array) data_get($json, 'telephony.numbers', []);
        $idx = (int) $data['number_index'];
        if (!isset($numbers[$idx])) {
            return back();
        }
        $removed = $numbers[$idx]['phone_number'] ?? '';
        unset($numbers[$idx]);

        $json['telephony'] = array_merge((array) ($json['telephony'] ?? []), [
            'numbers' => array_values($numbers),
        ]);
        $project->json_data = $json;
        $project->save();

        return redirect()
            ->route('telephony.index', ['client' => $client->slug])
            ->with('success', "Number {$removed} removed.");
    }

    private function guard(Client $client, int $projectId): Project
    {
        return Project::where('client_id', $client->id)
            ->where('id', $projectId)
            ->firstOrFail();
    }
}
