@extends('layouts.master')

@section('content')
<style>
    .tva-flow-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-flow-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }
    .tva-flow-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; }
    .tva-flow-row {
        display:flex; align-items:center; gap:14px;
        padding:14px 16px; background:#f8fafc; border:1px solid #e2e8f0;
        border-radius:12px; margin-bottom:10px;
    }
    .tva-flow-row:hover { background:#f1f5f9; }
    .tva-flow-row__icon {
        width:44px; height:44px; border-radius:10px;
        background:var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .tva-flow-row__name { font-size:15px; font-weight:600; color:#0f172a; }
    .tva-flow-row__meta { font-size:11px; color:#64748b; margin-top:3px; display:flex; gap:8px; flex-wrap:wrap; }
    .tva-flow-row__chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:600; text-transform:uppercase; letter-spacing:.04em; }
    .tva-flow-row__chip.is-active   { background:#dcfce7; color:#15803d; }
    .tva-flow-row__chip.is-draft    { background:#fef3c7; color:#92400e; }
    .tva-flow-row__chip.is-archived { background:#f1f5f9; color:#94a3b8; }
    html.dark .tva-flow-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-flow-row  { background:#0f172a; border-color:#334155; }
    html.dark .tva-flow-row__name { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-flow-hero mt-6">
        <div class="tva-flow-hero__icon">🪢</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Flow builder</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Visual IVR + AI flows. Drag nodes to build menus ("Press 1 for billing"), branch on speech, or hand control to the AI agent at any point.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif

    {{-- Project picker --}}
    <div class="intro-y box p-3 mb-4">
        <form method="GET">
            <label class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Project</label>
            <select name="project_id" class="form-select mt-1 w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int) $projectId === (int) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if ($project)
    <div class="tva-flow-card">
        <div class="flex items-center mb-4">
            <h2 class="font-medium text-base">
                <i data-lucide="git-branch" class="w-4 h-4 inline -mt-0.5 mr-1"></i>
                Flows · {{ $project->name }}
            </h2>
            <button type="button" class="btn btn-primary ml-auto" data-tva-modal-open="flow-create">
                <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> New flow
            </button>
        </div>

        @forelse ($flows as $flow)
            @php $nodeCount = is_array($flow->definition) ? count($flow->definition['nodes'] ?? []) : 0; @endphp
            <div class="tva-flow-row">
                <div class="tva-flow-row__icon"><i data-lucide="git-branch" class="w-5 h-5"></i></div>
                <div class="flex-1 min-w-0">
                    <div class="tva-flow-row__name">{{ $flow->name }}</div>
                    <div class="tva-flow-row__meta">
                        <span class="tva-flow-row__chip is-{{ $flow->status }}">{{ $flow->status }}</span>
                        <span>v{{ $flow->version }}</span>
                        <span>{{ $nodeCount }} node(s)</span>
                        <span>lang: {{ $flow->language }}</span>
                        <span class="mono" style="color:#94a3b8;">/{{ $flow->slug }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <a href="{{ route('flows.editor', ['client' => $client->slug, 'id' => $flow->id]) }}?project_id={{ $project->id }}"
                       class="btn btn-primary btn-sm">
                        <i data-lucide="pencil" class="w-3 h-3 inline mr-1"></i> Open editor
                    </a>
                    <form method="POST" action="{{ route('flows.destroy', ['client' => $client->slug, 'id' => $flow->id]) }}"
                          onsubmit="return confirm('Delete flow &quot;{{ $flow->name }}&quot;? Recoverable from super-admin.');" class="inline">
                        @csrf @method('DELETE')
                        <input type="hidden" name="project_id" value="{{ $project->id }}">
                        <button type="submit" class="text-danger inline-flex items-center justify-center w-8 h-8 rounded hover:bg-danger/10" title="Delete">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </div>
        @empty
            <div class="text-center py-10 text-slate-400">
                <i data-lucide="git-branch" class="w-10 h-10 inline mb-2"></i>
                <div class="font-medium">No flows yet.</div>
                <div class="text-xs mt-1">Click <b>New flow</b> to design your first IVR + AI flow.</div>
            </div>
        @endforelse
    </div>
    @endif
</div>

{{-- Create-flow modal --}}
<div id="flow-create" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <form method="POST" action="{{ route('flows.store', ['client' => $client->slug]) }}" class="tva-modal__panel" style="max-width:520px;">
        @csrf
        <input type="hidden" name="project_id" value="{{ $projectId }}">
        <div class="tva-modal__head">
            <i data-lucide="git-branch" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
            New flow
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="tva-modal__body">
            <div class="mb-3">
                <label class="form-label">Flow name <span class="text-danger">*</span></label>
                <input type="text" name="name" required maxlength="160" class="form-control"
                       placeholder="Billing IVR · Hindi greeting · After-hours support">
            </div>
            <div class="mb-3">
                <label class="form-label">Primary language</label>
                <select name="language" class="form-select">
                    <option value="en">English</option>
                    <option value="ur">Urdu</option>
                    <option value="hi">Hindi</option>
                    <option value="es">Spanish</option>
                    <option value="ar">Arabic</option>
                    <option value="fr">French</option>
                    <option value="de">German</option>
                </select>
                <small class="text-slate-500 text-xs">Used by TTS for Say-nodes that don't specify a per-node language.</small>
            </div>
        </div>
        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Create + open editor</button>
        </div>
    </form>
</div>

@include('skills._modal_css')

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-tva-modal-open]');
        if (open) { var m = document.getElementById(open.dataset.tvaModalOpen); if (m) m.removeAttribute('hidden'); return; }
        var close = e.target.closest('[data-tva-modal-close]');
        if (close) { var modal = close.closest('.tva-modal'); if (modal) modal.setAttribute('hidden',''); }
    });
</script>
@endsection
