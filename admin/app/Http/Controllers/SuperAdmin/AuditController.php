<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $title = 'Audit log';
        $perPage = 50;

        $action = $request->query('action');
        $q = AuditLog::query()->orderByDesc('id');
        if ($action) $q->where('action', $action);

        $entries = $q->paginate($perPage)->withQueryString();

        $actors = User::whereIn('id', $entries->pluck('actor_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $actions = AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action');

        return view('ops.audit.index', compact('title', 'entries', 'actors', 'actions', 'action'));
    }
}
