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
    /* The chip doubles as the status control: a real <select> wearing the
       badge's styling, so the thing that shows state is the thing that
       changes it. Native select keeps keyboard + screen-reader behaviour. */
    /* Not Tailwind's `sr-only` — it isn't in this theme's purged build, so the
       label rendered as visible text on top of the row. Defined locally. */
    .tva-sr-only {
        position:absolute; width:1px; height:1px; padding:0; margin:-1px;
        overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; border:0;
    }
    .tva-flow-status { position:relative; display:inline-flex; }
    .tva-flow-status select.tva-flow-row__chip {
        appearance:none; -webkit-appearance:none; border:none; cursor:pointer;
        padding:2px 20px 2px 8px; line-height:1.6; font-family:inherit;
    }
    .tva-flow-status::after {
        content:''; position:absolute; right:7px; top:50%; width:0; height:0;
        margin-top:-1px; pointer-events:none;
        border-left:3px solid transparent; border-right:3px solid transparent;
        border-top:4px solid currentColor; opacity:.55;
    }
    .tva-flow-status.is-active   { color:#15803d; }
    .tva-flow-status.is-draft    { color:#92400e; }
    .tva-flow-status.is-archived { color:#94a3b8; }
    .tva-flow-status select:focus-visible { outline:2px solid #3b82f6; outline-offset:1px; }
    /* A flow that can't go live yet — the option is disabled and the row says why. */
    .tva-flow-row__blocked { color:#b45309; font-size:11px; display:inline-flex; align-items:center; gap:4px; }
    html.dark .tva-flow-row__blocked { color:#fbbf24; }
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

    {{-- Refused activations land here. Without this the guard's explanation
         was thrown away and the status just appeared not to change. --}}
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4 flex items-start">
            <i data-lucide="alert-triangle" class="w-4 h-4 mr-2 mt-0.5 flex-shrink-0"></i>
            <div>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Project picker --}}
    <div class="intro-y box p-3 mb-4">
        <form method="GET">
            <label class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Project</label>
            <select name="project_id" class="form-select mt-1 w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected((int) $projectId === (int) $p->id)>{{ $p->name }}</option>
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
            @php
                $nodeCount = is_array($flow->definition) ? count($flow->definition['nodes'] ?? []) : 0;
                // Computed per row so the Active option can be disabled with a
                // reason, rather than letting the click through to a 422.
                $blockers = $flow->activationErrors();
            @endphp
            <div class="tva-flow-row">
                <div class="tva-flow-row__icon"><i data-lucide="git-branch" class="w-5 h-5"></i></div>
                <div class="flex-1 min-w-0">
                    <div class="tva-flow-row__name">{{ $flow->name }}</div>
                    <div class="tva-flow-row__meta">
                        <form method="POST" class="tva-flow-status is-{{ $flow->status }}"
                              action="{{ route('flows.update', ['client' => $client->slug, 'id' => $flow->id]) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="project_id" value="{{ $project->id }}">
                            <label class="tva-sr-only" for="flow-status-{{ $flow->id }}">Status for {{ $flow->name }}</label>
                            <select id="flow-status-{{ $flow->id }}" name="status"
                                    class="tva-flow-row__chip is-{{ $flow->status }}"
                                    onchange="this.form.submit()">
                                <option value="draft"    @selected($flow->status === 'draft')>draft</option>
                                <option value="active"   @selected($flow->status === 'active')
                                        @disabled($blockers && $flow->status !== 'active')>active</option>
                                <option value="archived" @selected($flow->status === 'archived')>archived</option>
                            </select>
                        </form>
                        @if ($blockers && $flow->status !== 'active')
                            <span class="tva-flow-row__blocked" title="{{ implode(' ', $blockers) }}">
                                <i data-lucide="alert-triangle" class="w-3 h-3"></i> can't activate yet
                            </span>
                        @endif
                        <span>v{{ $flow->version }}</span>
                        <span>{{ $nodeCount }} node(s)</span>
                        <span>lang: {{ $flow->language }}</span>
                        <span class="mono" style="color:#94a3b8;">/{{ $flow->slug }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1">
                    <a href="{{ route('flows.editor', ['client' => $client->slug, 'id' => $flow->id]) }}?project_id={{ hashid($project->id) }}"
                       class="btn btn-primary btn-sm">
                        <i data-lucide="pencil" class="w-3 h-3 inline mr-1"></i> Open editor
                    </a>
                    <form method="POST" action="{{ route('flows.destroy', ['client' => $client->slug, 'id' => $flow->id]) }}"
                          data-confirm="Delete flow &quot;{{ $flow->name }}&quot;? Recoverable from super-admin." class="inline">
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
