<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientsController extends Controller
{
    public function index(Request $request): View
    {
        $title = 'Clients';
        $search = trim((string) $request->query('q', ''));
        $withDeleted = (bool) $request->query('with_deleted');
        $perPage = 25;

        $q = Client::query();
        if ($withDeleted) $q->withTrashedRows();
        $q->orderByDesc('id');

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
                if (ctype_digit($search)) $w->orWhere('id', (int) $search);
            });
        }

        $clients = $q->paginate($perPage)->withQueryString();

        $projectCounts = Project::query()->withTrashedRows()
            ->selectRaw('client_id, COUNT(*) as c, SUM(CASE WHEN is_active = "Yes" THEN 1 ELSE 0 END) as active_c')
            ->whereIn('client_id', $clients->pluck('id'))
            ->groupBy('client_id')->get()->keyBy('client_id');

        return view('ops.clients.index', compact('title', 'clients', 'search', 'projectCounts', 'withDeleted'));
    }

    public function suspend(Request $request, int $id): RedirectResponse
    {
        $client = Client::query()->withTrashedRows()->findOrFail($id);
        $client->is_active = 'No';
        $client->save();

        AuditLog::record('client.suspend', [
            'target_type' => 'client', 'target_id' => $client->id,
            'payload' => ['slug' => $client->slug, 'name' => $client->name],
        ]);

        return back()->with('success', "Suspended \"{$client->name}\".");
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        $client = Client::query()->withTrashedRows()->findOrFail($id);
        $client->is_active = 'Yes';
        $client->save();

        AuditLog::record('client.restore', [
            'target_type' => 'client', 'target_id' => $client->id,
            'payload' => ['slug' => $client->slug, 'name' => $client->name],
        ]);

        return back()->with('success', "Restored \"{$client->name}\".");
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $client = Client::query()->withTrashedRows()->findOrFail($id);
        $client->softDelete();

        AuditLog::record('client.soft_delete', [
            'target_type' => 'client', 'target_id' => $client->id,
            'payload' => ['slug' => $client->slug, 'name' => $client->name],
        ]);

        return back()->with('success', "Deleted \"{$client->name}\" (recoverable).");
    }

    public function recover(Request $request, int $id): RedirectResponse
    {
        $client = Client::query()->withTrashedRows()->findOrFail($id);
        $client->restoreSoft();

        AuditLog::record('client.recover', [
            'target_type' => 'client', 'target_id' => $client->id,
            'payload' => ['slug' => $client->slug, 'name' => $client->name],
        ]);

        return back()->with('success', "Recovered \"{$client->name}\".");
    }
}
