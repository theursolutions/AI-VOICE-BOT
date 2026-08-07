<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Custom per-client roles. The owner defines roles and checks which
 * modules each can access (config/modules.php). Managing roles is
 * owner-only; the EnsureModuleAccess gate (module 'team') controls who
 * can even reach these pages.
 */
class RoleWebController extends Controller
{
    public function index(Request $request, Client $client): View
    {
        $this->guardOwner($request, $client);

        $roles   = Role::where('client_id', $client->id)->orderByDesc('is_owner')->orderBy('name')->get();

        // Only modules switched ON platform-wide are assignable — a module a
        // super-admin has disabled shouldn't appear in the permission matrix.
        $modules = array_intersect_key(
            (array) config('modules', []),
            array_flip(\App\Support\Modules::enabledKeys()),
        );

        // member counts per role (for the "in use" hint)
        $counts = DB::table('project_users')
            ->where('client_id', $client->id)
            ->whereNotNull('role_id')
            ->select('role_id', DB::raw('count(distinct user_id) as n'))
            ->groupBy('role_id')->pluck('n', 'role_id');

        return view('roles.index', compact('client', 'roles', 'modules', 'counts'));
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $this->guardOwner($request, $client);

        $data = $request->validate([
            'name'      => 'required|string|max:80',
            'modules'   => 'array',
            'modules.*' => 'string',
        ]);

        Role::create([
            'client_id'  => $client->id,
            'name'       => $data['name'],
            'modules'    => $this->cleanModules($data['modules'] ?? []),
            'is_owner'   => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return back()->with('success', "Role “{$data['name']}” created.");
    }

    public function update(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->guardOwner($request, $client);

        $role = Role::where('client_id', $client->id)->findOrFail($id);
        if ($role->is_owner) {
            return back()->withErrors(['role' => 'The Owner role cannot be edited.']);
        }

        $data = $request->validate([
            'name'      => 'required|string|max:80',
            'modules'   => 'array',
            'modules.*' => 'string',
        ]);

        $role->update([
            'name'       => $data['name'],
            'modules'    => $this->cleanModules($data['modules'] ?? []),
            'updated_at' => time(),
        ]);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, Client $client, int $id): RedirectResponse
    {
        $this->guardOwner($request, $client);

        $role = Role::where('client_id', $client->id)->findOrFail($id);
        if ($role->is_owner) {
            return back()->withErrors(['role' => 'The Owner role cannot be deleted.']);
        }

        $inUse = DB::table('project_users')->where('client_id', $client->id)->where('role_id', $role->id)->exists();
        if ($inUse) {
            return back()->withErrors(['role' => 'This role is assigned to members — reassign them first.']);
        }

        $role->delete();
        return back()->with('success', 'Role deleted.');
    }

    /** Keep only known module keys (drop 'dashboard' — it's always granted). */
    private function cleanModules(array $modules): array
    {
        $valid = array_keys((array) config('modules', []));
        return array_values(array_filter(
            array_unique($modules),
            fn ($m) => in_array($m, $valid, true) && $m !== 'dashboard',
        ));
    }

    private function guardOwner(Request $request, Client $client): void
    {
        $user = $request->user();
        if (!$user || !$user->isOwnerOf($client->id)) {
            abort(403, 'Only the agency owner can manage roles.');
        }
    }
}
