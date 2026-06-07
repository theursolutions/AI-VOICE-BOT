<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class UsersController extends Controller
{
    public function index(Request $request): View
    {
        $title = 'Users';
        $search       = trim((string) $request->query('q', ''));
        $withDeleted  = (bool) $request->query('with_deleted');
        $perPage      = 25;

        // Default scope hides soft-deleted; ?with_deleted=1 reveals them.
        $q = User::query();
        if ($withDeleted) $q->withTrashedRows();
        $q->orderByDesc('id');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('email','like', "%{$search}%");
                if (ctype_digit($search)) $w->orWhere('id', (int) $search);
            });
        }

        $users = $q->paginate($perPage)->withQueryString();

        $memberCounts = DB::table('project_users')
            ->selectRaw('user_id, COUNT(DISTINCT client_id) as workspace_count')
            ->whereIn('user_id', $users->pluck('id'))
            ->groupBy('user_id')->get()->keyBy('user_id');

        $activeClients = Client::whereIn('id', $users->pluck('active_client_id')->filter()->unique())
            ->get(['id', 'name', 'slug'])->keyBy('id');

        return view('ops.users.index', compact('title', 'users', 'search', 'memberCounts', 'activeClients', 'withDeleted'));
    }

    public function disable(Request $request, int $id): RedirectResponse
    {
        $user = User::query()->withTrashedRows()->findOrFail($id);
        abort_if($user->is_super_admin, 403, 'Cannot disable a super-admin from the UI.');
        $user->is_disabled = true;
        $user->save();

        AuditLog::record('user.disable', [
            'target_type' => 'user', 'target_id' => $user->id,
            'payload' => ['email' => $user->email],
        ]);

        return back()->with('success', "Disabled {$user->email}.");
    }

    public function enable(Request $request, int $id): RedirectResponse
    {
        $user = User::query()->withTrashedRows()->findOrFail($id);
        $user->is_disabled = false;
        $user->save();

        AuditLog::record('user.enable', [
            'target_type' => 'user', 'target_id' => $user->id,
            'payload' => ['email' => $user->email],
        ]);

        return back()->with('success', "Enabled {$user->email}.");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $user = User::query()->withTrashedRows()->findOrFail($id);
        abort_if($user->is_super_admin, 403, 'Cannot delete a super-admin from the UI.');
        $user->softDelete();

        AuditLog::record('user.soft_delete', [
            'target_type' => 'user', 'target_id' => $user->id,
            'payload' => ['email' => $user->email],
        ]);

        return back()->with('success', "Deleted {$user->email} (recoverable).");
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $user = User::query()->withTrashedRows()->findOrFail($id);
        $user->restoreSoft();

        AuditLog::record('user.restore', [
            'target_type' => 'user', 'target_id' => $user->id,
            'payload' => ['email' => $user->email],
        ]);

        return back()->with('success', "Restored {$user->email}.");
    }
}
