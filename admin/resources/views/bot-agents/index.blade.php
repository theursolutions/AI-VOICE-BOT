@extends('layouts.master')

@section('content')
<style>
    .tva-ag-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-ag-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }

    .tva-ag-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; }
    .tva-ag-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .tva-ag-card__title { font-size:15px; font-weight:600; color:#0f172a; }

    .tva-ag-row {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
        padding:14px 16px; display:flex; align-items:center; gap:14px;
        transition: all .15s; margin-bottom:10px;
    }
    .tva-ag-row:hover { background:#f1f5f9; }
    .tva-ag-row.is-default { border-color:#10b981; background:linear-gradient(135deg,#f0fdf4,#ecfdf5); }
    .tva-ag-avatar {
        width:44px; height:44px; border-radius:50%;
        background:var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center;
        font-weight:700; font-size:14px; flex-shrink:0;
    }
    .tva-ag-name { font-size:14px; font-weight:600; color:#0f172a; }
    .tva-ag-meta { font-size:11px; color:#64748b; margin-top:3px; display:flex; flex-wrap:wrap; gap:6px; }
    /* Channels + permissions block in the agent modal. Boxed and set apart
       from the identity fields above it, because these change who gets a
       conversation rather than what the agent is called. */
    .tva-ag-perm { border:1px solid #e2e8f0; border-radius:10px; padding:11px 13px; margin-bottom:12px; background:#f8fafc; }
    html.dark .tva-ag-perm { background:#0f172a; border-color:#334155; }
    .tva-ag-perm__h { font-size:9.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
                      color:#64748b; margin-bottom:4px; }
    .tva-ag-perm__grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:5px 12px; }
    .tva-ag-perm__opt { display:flex; align-items:center; gap:7px; font-size:12px; color:#334155; cursor:pointer; }
    html.dark .tva-ag-perm__opt { color:#cbd5e1; }
    /* @tailwindcss/forms strips the native control, so it is drawn here —
       the same reason the status checkbox needed it. */
    .tva-ag-perm__opt input[type="checkbox"] { appearance:none; -webkit-appearance:none;
        width:16px; height:16px; flex-shrink:0; margin:0; cursor:pointer;
        border:1.5px solid #cbd5e1; border-radius:5px; background:#fff; transition:.12s; }
    .tva-ag-perm__opt input[type="checkbox"]:checked {
        background-color:#4f46e5; border-color:#4f46e5;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none' stroke='%23fff' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 8.5l3.2 3.2L13 5'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:center; background-size:76% 76%; }
    html.dark .tva-ag-perm__opt input[type="checkbox"] { background-color:#1e293b; border-color:#475569; }

    .tva-ag-chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:600; }
    .tva-ag-chip.is-default { background:#dcfce7; color:#15803d; }
    .tva-ag-chip.is-archived { background:#f1f5f9; color:#94a3b8; }
    .tva-ag-chip.is-skill { background:#dbeafe; color:#1e40af; }
    .tva-ag-chip.is-voice { background:#ede9fe; color:#7c3aed; }

    html.dark .tva-ag-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-ag-card__head { border-bottom-color:#334155; }
    html.dark .tva-ag-card__title { color:#f1f5f9; }
    html.dark .tva-ag-row { background:#0f172a; border-color:#334155; }
    html.dark .tva-ag-name { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-ag-hero mt-6">
        <div class="tva-ag-hero__icon">🤖</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Agents</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                AI personas with their own voice + persona + skills. Multiple agents in a skill = load distribution.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <div class="intro-y box p-3 mb-4">
        <form method="GET">
            <label class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Project</label>
            <select name="project_id" class="form-select mt-1 w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="tva-ag-card">
        <div class="tva-ag-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#ede9fe; color:#7c3aed; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="users" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <div class="tva-ag-card__title">All agents @if ($project) <span class="text-xs text-slate-500 font-normal">· {{ $project->name }}</span>@endif</div>
            </div>
            <button type="button" class="btn btn-primary" data-tva-modal-open="agent-create">
                <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> Add agent
            </button>
        </div>

        @forelse ($agents as $agent)
            @php
                $initials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $agent->name) ?: 'A', 0, 2));
            @endphp
            <div class="tva-ag-row {{ $agent->is_default ? 'is-default' : '' }}">
                <div class="tva-ag-avatar">{{ $initials }}</div>
                <div class="flex-1 min-w-0">
                    <div class="tva-ag-name">{{ $agent->name }}</div>
                    <div class="tva-ag-meta">
                        @if ($agent->is_default) <span class="tva-ag-chip is-default">DEFAULT</span> @endif
                        @if ($agent->status === 'archived') <span class="tva-ag-chip is-archived">ARCHIVED</span> @endif
                        @if ($agent->voice)
                            <span class="tva-ag-chip is-voice">🎤 {{ $agent->voice->name }}</span>
                        @else
                            <span class="tva-ag-chip">No voice</span>
                        @endif
                        @foreach ($agent->skills as $s)
                            <span class="tva-ag-chip is-skill">{{ $s->name }}</span>
                        @endforeach
                    </div>
                    @if ($agent->persona)
                        <div class="text-xs text-slate-500 mt-1 truncate" title="{{ $agent->persona }}">
                            {{ \Illuminate\Support\Str::limit($agent->persona, 120) }}
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" class="text-primary inline-flex items-center justify-center w-8 h-8 rounded hover:bg-primary/10" data-tva-modal-open="agent-edit-{{ $agent->id }}" title="Edit">
                        <i data-lucide="pencil" class="w-4 h-4"></i>
                    </button>
                    <button type="button" class="text-danger inline-flex items-center justify-center w-8 h-8 rounded hover:bg-danger/10" data-tva-modal-open="agent-delete-{{ $agent->id }}" title="Delete">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>

            {{-- Edit modal --}}
            @include('bot-agents._modal', [
                'modalId'   => "agent-edit-{$agent->id}",
                'title'     => "Edit agent",
                'action'    => route('bot-agents.update', ['client' => $client->slug, 'id' => $agent->id]),
                'method'    => 'PATCH',
                'projectId' => $projectId,
                'agent'     => $agent,
                'skills'    => $skills,
                'voices'    => $voices,
                'showStatus'=> true,
            ])

            {{-- Delete modal --}}
            <div id="agent-delete-{{ $agent->id }}" class="tva-modal" hidden>
                <div class="tva-modal__backdrop" data-tva-modal-close></div>
                <div class="tva-modal__panel" style="max-width:420px;">
                    <div class="tva-modal__head">
                        <i data-lucide="trash-2" class="w-4 h-4 mr-2 inline" style="color:#b91c1c;"></i>
                        Delete agent
                        <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
                    </div>
                    <div class="tva-modal__body">
                        <p>Delete agent <b>{{ $agent->name }}</b>? Existing sessions keep their assignment.</p>
                    </div>
                    <form method="POST" action="{{ route('bot-agents.destroy', ['client' => $client->slug, 'id' => $agent->id]) }}" class="tva-modal__foot">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="project_id" value="{{ $projectId }}">
                        <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="text-center py-10 text-slate-400">
                <i data-lucide="user-x" class="w-10 h-10 inline mb-2"></i>
                <div class="font-medium">No agents yet.</div>
                <div class="text-xs mt-1">Click "Add agent" to create your first AI persona.</div>
            </div>
        @endforelse
    </div>

    {{-- Create modal --}}
    @include('bot-agents._modal', [
        'modalId'   => 'agent-create',
        'title'     => 'New agent',
        'action'    => route('bot-agents.store', ['client' => $client->slug]),
        'method'    => 'POST',
        'projectId' => $projectId,
        'agent'     => null,
        'skills'    => $skills,
        'voices'    => $voices,
        'showStatus'=> false,
    ])
</div>

@include('skills._modal_css')

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) { if (window.lucide.icons) window.lucide.createIcons({icons:window.lucide.icons}); }

    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-tva-modal-open]');
        if (open) {
            var m = document.getElementById(open.getAttribute('data-tva-modal-open'));
            if (m) m.removeAttribute('hidden');
            return;
        }
        var close = e.target.closest('[data-tva-modal-close]');
        if (close) {
            var modal = close.closest('.tva-modal');
            if (modal) modal.setAttribute('hidden', '');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape')
            document.querySelectorAll('.tva-modal:not([hidden])').forEach(function (m) { m.setAttribute('hidden', ''); });
    });
</script>
@endsection
