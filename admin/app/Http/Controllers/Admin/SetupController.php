<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientProjectUser;
use App\Models\Project;
use App\Services\Tenant\TenantProvisioner;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * First-time workspace setup. A newly-signed-up user lands here
 * because they have a Client (workspace) row but no provisioned
 * Project — every other workspace route redirects them here via
 * the `workspace.provisioned` middleware.
 *
 * The form is one screen: project name + a couple of optional
 * profile fields. On submit we:
 *   1. Create the Project row (is_active = 'No' until provisioning)
 *   2. Call TenantProvisioner — CREATE DATABASE + run migrations
 *   3. Flip is_active = 'Yes' on success
 *   4. If provisioning failed → delete the half-baked Project row,
 *      drop the empty DB (best effort), surface the error
 */
class SetupController extends Controller
{
    public function show(Request $request, Client $client)
    {
        // If they already have a provisioned project, bounce out —
        // the setup screen exists only for the no-project state.
        $existing = Project::where('client_id', $client->id)
            ->where('is_active', 'Yes')
            ->exists();
        if ($existing) {
            return redirect()->route('dashboard', ['client' => $client->slug]);
        }

        return view('setup.index', compact('client'));
    }

    public function store(Request $request, Client $client, TenantProvisioner $prov): RedirectResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'website'   => 'nullable|url|max:255',
            'industry'  => 'nullable|string|max:120',
            'about'     => 'nullable|string|max:1000',
        ]);

        // Idempotency guard — if a stuck Project row exists, finish
        // provisioning it instead of creating a new one. Lets the
        // user click "Try again" without ending up with 3 projects.
        $project = Project::where('client_id', $client->id)
            ->where('is_active', 'No')
            ->orderByDesc('id')
            ->first();

        if (! $project) {
            $now = time();
            $project = Project::create([
                'name'               => $data['name'],
                'client_id'          => $client->id,
                'url'                => $data['website'] ?? null,
                'project_api_key'    => Str::random(32),
                'project_api_secret' => Str::random(32),
                'is_active'          => 'No',                // becomes 'Yes' on success
                'json_data'          => [
                    'profile' => [
                        'website'  => $data['website']  ?? '',
                        'industry' => $data['industry'] ?? '',
                        'about'    => $data['about']    ?? '',
                        'timezone' => 'UTC',
                        'language' => 'en',
                    ],
                ],
                'created_at'         => $now,
                'updated_at'         => $now,
                'updated_by'         => Auth::id(),
            ]);

            // Attach the user to the new project (mirrors RegisterController's
            // pattern of client-level membership now extended to project).
            ClientProjectUser::where('user_id', Auth::id())
                ->where('client_id', $client->id)
                ->whereNull('project_id')
                ->update(['project_id' => $project->id, 'assigned_at' => $now]);
        } else {
            // Update name/profile from the (possibly edited) retry submission.
            $project->name = $data['name'];
            $project->url  = $data['website'] ?? null;
            $json = is_array($project->json_data) ? $project->json_data : [];
            $json['profile'] = array_merge((array) ($json['profile'] ?? []), [
                'website'  => $data['website']  ?? '',
                'industry' => $data['industry'] ?? '',
                'about'    => $data['about']    ?? '',
            ]);
            $project->json_data = $json;
            $project->save();
        }

        // ── Provisioning step ──────────────────────────────────────
        $ok = $prov->provision($project);

        if (! $ok) {
            // Leave the Project row in place (is_active='No') so the
            // user can retry without re-entering the form — the next
            // POST will pick up this row by the idempotency guard.
            return back()->withInput()->withErrors([
                'name' => 'We couldn\'t set up your workspace database right now. '.
                          'Please try again — if it keeps failing, contact support.',
            ]);
        }

        return redirect()
            ->route('dashboard', ['client' => $client->slug])
            ->with('success', "Welcome aboard! \"{$project->name}\" is ready.");
    }
}
