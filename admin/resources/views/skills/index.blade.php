@extends('layouts.master')

@section('content')
<style>
    .tva-sk-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-sk-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }
    .tva-sk-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; }
    .tva-sk-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .tva-sk-card__title { font-size:15px; font-weight:600; color:#0f172a; }

    .tva-sk-tile {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
        padding:16px 18px; display:flex; align-items:center; gap:14px;
        transition: all .15s;
    }
    .tva-sk-tile:hover { background:#f1f5f9; }
    .tva-sk-tile.is-default { border-color:#10b981; background:linear-gradient(135deg,#f0fdf4,#ecfdf5); }
    .tva-sk-tile__icon { width:44px; height:44px; border-radius:10px; background:var(--tva-gradient); color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .tva-sk-tile__name { font-size:14px; font-weight:600; color:#0f172a; }
    .tva-sk-tile__meta { font-size:11px; color:#64748b; margin-top:2px; display:flex; gap:10px; flex-wrap:wrap; }
    .tva-sk-tile__chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:600; }
    .tva-sk-tile__chip.is-default { background:#dcfce7; color:#15803d; }
    .tva-sk-tile__chip.is-archived { background:#f1f5f9; color:#94a3b8; }

    html.dark .tva-sk-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-sk-card__head { border-bottom-color:#334155; }
    html.dark .tva-sk-card__title { color:#f1f5f9; }
    html.dark .tva-sk-tile { background:#0f172a; border-color:#334155; }
    html.dark .tva-sk-tile__name { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-sk-hero mt-6">
        <div class="tva-sk-hero__icon">🎯</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Skills</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Routing categories — billing, support, sales. Assign agents to skills, then route calls + chats to the right pool.
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
                    <option value="{{ $p->id }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="tva-sk-card">
        <div class="tva-sk-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#dbeafe; color:#1e40af; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="layers" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <div class="tva-sk-card__title">All skills @if ($project) <span class="text-xs text-slate-500 font-normal">· {{ $project->name }}</span>@endif</div>
            </div>
            <button type="button" class="btn btn-primary" data-tva-modal-open="skill-create">
                <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> Add skill
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            @forelse ($skills as $skill)
                <div class="tva-sk-tile {{ $skill->is_default ? 'is-default' : '' }}">
                    <div class="tva-sk-tile__icon"><i data-lucide="tag" class="w-5 h-5"></i></div>
                    <div class="flex-1 min-w-0">
                        <div class="tva-sk-tile__name">{{ $skill->name }}</div>
                        <div class="tva-sk-tile__meta">
                            @if ($skill->is_default)
                                <span class="tva-sk-tile__chip is-default">DEFAULT</span>
                            @endif
                            @if ($skill->status === 'archived')
                                <span class="tva-sk-tile__chip is-archived">ARCHIVED</span>
                            @endif
                            <span class="tva-sk-tile__chip">{{ $skill->agents_count ?? 0 }} agent(s)</span>
                        </div>
                        @if ($skill->description)
                            <div class="text-xs text-slate-500 mt-1 truncate" title="{{ $skill->description }}">{{ $skill->description }}</div>
                        @endif
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" class="text-primary inline-flex items-center justify-center w-8 h-8 rounded hover:bg-primary/10" data-tva-modal-open="skill-edit-{{ $skill->id }}" title="Edit">
                            <i data-lucide="pencil" class="w-4 h-4"></i>
                        </button>
                        <button type="button" class="text-danger inline-flex items-center justify-center w-8 h-8 rounded hover:bg-danger/10" data-tva-modal-open="skill-delete-{{ $skill->id }}" title="Delete">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                {{-- ── Edit modal ────────────────────────────────────── --}}
                @include('skills._modal', [
                    'modalId'   => "skill-edit-{$skill->id}",
                    'title'     => "Edit skill",
                    'action'    => route('skills.update', ['client' => $client->slug, 'id' => $skill->id]),
                    'method'    => 'PATCH',
                    'projectId' => $projectId,
                    'skill'     => $skill,
                    'showStatus'=> true,
                ])

                {{-- ── Delete modal ──────────────────────────────────── --}}
                <div id="skill-delete-{{ $skill->id }}" class="tva-modal" hidden>
                    <div class="tva-modal__backdrop" data-tva-modal-close></div>
                    <div class="tva-modal__panel" style="max-width:420px;">
                        <div class="tva-modal__head">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-2 inline" style="color:#b91c1c;"></i>
                            Delete skill
                            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
                        </div>
                        <div class="tva-modal__body">
                            <p>Delete <b>{{ $skill->name }}</b>? Agents currently assigned to this skill will be unassigned. Sessions already routed to it remain intact.</p>
                        </div>
                        <form method="POST" action="{{ route('skills.destroy', ['client' => $client->slug, 'id' => $skill->id]) }}" class="tva-modal__foot">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="project_id" value="{{ $projectId }}">
                            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
                            <button type="submit" class="btn btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-10 text-slate-400">
                    <i data-lucide="tag" class="w-10 h-10 inline mb-2"></i>
                    <div class="font-medium">No skills yet.</div>
                    <div class="text-xs mt-1">Click "Add skill" to create one (e.g. Billing, Support, Sales).</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ── Create modal ─────────────────────────────────────────────── --}}
    @include('skills._modal', [
        'modalId'   => 'skill-create',
        'title'     => 'New skill',
        'action'    => route('skills.store', ['client' => $client->slug]),
        'method'    => 'POST',
        'projectId' => $projectId,
        'skill'     => null,
        'showStatus'=> false,
    ])
</div>

@include('skills._modal_css')

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) { if (window.lucide.icons) window.lucide.createIcons({icons:window.lucide.icons}); }

    // Generic modal open/close — used by Skills, Agents, anywhere else.
    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-tva-modal-open]');
        if (open) {
            var id = open.getAttribute('data-tva-modal-open');
            var m = document.getElementById(id);
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
        if (e.key === 'Escape') {
            document.querySelectorAll('.tva-modal:not([hidden])').forEach(function (m) { m.setAttribute('hidden', ''); });
        }
    });
</script>
@endsection
