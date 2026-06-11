@extends('layouts.master')

@section('content')
<style>
    .tva-tel-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-tel-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }

    .tva-tel-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px;
        padding:20px 22px; margin-bottom:18px;
    }
    .tva-tel-card__head {
        display:flex; align-items:center; gap:10px;
        margin-bottom:14px; padding-bottom:12px;
        border-bottom:1px solid #e2e8f0;
    }
    .tva-tel-card__title { font-size:15px; font-weight:600; color:#0f172a; }
    .tva-tel-card__subtitle { font-size:11px; color:#64748b; margin-top:2px; }

    .tva-num-tile {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
        padding:14px 16px; margin-bottom:8px;
        display:flex; align-items:center; gap:12px;
    }
    .tva-num-tile.is-disabled { opacity: .55; }
    .tva-num-tile__icon {
        width:36px; height:36px; border-radius:8px;
        background:var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .tva-num-tile__phone {
        font-family: ui-monospace, monospace; font-size:14px; font-weight:700; color:#0f172a;
    }
    .tva-num-tile__meta { font-size:11px; color:#64748b; margin-top:2px; display:flex; gap:6px; flex-wrap:wrap; }
    .tva-num-tile__chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:600; }
    .tva-num-tile__chip.is-ok      { background:#dcfce7; color:#15803d; }
    .tva-num-tile__chip.is-off     { background:#fee2e2; color:#b91c1c; }
    .tva-num-tile__chip.is-skill   { background:#dbeafe; color:#1e40af; }
    .tva-num-tile__chip.is-agents  { background:#ede9fe; color:#7c3aed; }

    .tva-webhook-block {
        background:#0f172a; color:#e2e8f0; border-radius:10px;
        padding:12px 14px; font-family: ui-monospace, monospace;
        font-size:11px; line-height:1.5; position:relative;
        margin-bottom: 8px;
    }
    .tva-webhook-block__label { color:#94a3b8; font-size:10px; text-transform:uppercase; letter-spacing:.05em; font-weight:600; margin-bottom:4px; }
    .tva-webhook-block__url { word-break:break-all; }
    .tva-webhook-copy {
        position:absolute; top:6px; right:6px;
        background:rgba(255,255,255,.1); color:#fff;
        border:none; border-radius:6px;
        padding:3px 8px; font-size:10px; font-weight:600; cursor:pointer;
    }
    .tva-webhook-copy.is-copied { background:#10b981; }

    .tva-routing-tabs {
        display:flex; gap:4px; background:#f1f5f9; padding:4px; border-radius:8px;
        margin-bottom:14px;
    }
    .tva-routing-tab {
        flex:1; text-align:center; padding:8px 12px; border-radius:6px;
        font-size:12px; font-weight:600; color:#64748b; cursor:pointer;
        transition: all .15s;
    }
    .tva-routing-tab.is-selected { background:#fff; color:#3730a3; box-shadow: 0 1px 3px rgba(0,0,0,.1); }

    html.dark .tva-tel-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-tel-card__head { border-bottom-color:#334155; }
    html.dark .tva-tel-card__title { color:#f1f5f9; }
    html.dark .tva-num-tile { background:#0f172a; border-color:#334155; }
    html.dark .tva-num-tile__phone { color:#f1f5f9; }
    html.dark .tva-routing-tabs { background:#0f172a; }
    html.dark .tva-routing-tab.is-selected { background:#1e293b; color:#c7d2fe; }

    /* Modal toggle/checkbox rows — neutral surface that adapts to
       both themes so the label text stays readable. Old inline
       `background:#f8fafc` made white text on white in dark mode. */
    .tva-num-modal-row {
        display:flex; align-items:center; gap:10px; cursor:pointer;
        padding:9px 12px; border-radius:8px;
        background:#f8fafc; border:1px solid #e2e8f0;
        color:#0f172a;
    }
    .tva-num-modal-row:hover { border-color:#c7d2fe; }
    .tva-num-modal-row .text-sm { line-height:1.3; }
    html.dark .tva-num-modal-row {
        background:#0f172a; border-color:#334155; color:#f1f5f9;
    }
    html.dark .tva-num-modal-row:hover { border-color:#6366f1; }

    /* Make the modal's form-controls + form-selects properly themed
       in dark mode so the dropdown text stays readable. Scoped to
       the modal body so it doesn't leak into other forms. */
    html.dark .tva-modal__body .form-control,
    html.dark .tva-modal__body .form-select {
        background:#0f172a !important;
        color:#f1f5f9 !important;
        border-color:#334155 !important;
    }
    html.dark .tva-modal__body .form-control::placeholder { color:#64748b; }
    html.dark .tva-modal__body .form-select option {
        background:#1e293b; color:#f1f5f9;
    }
    html.dark .tva-modal__body .form-label,
    html.dark .tva-modal__body label { color:#cbd5e1; }
    html.dark .tva-modal__body small,
    html.dark .tva-modal__body .text-slate-500 { color:#94a3b8 !important; }
</style>

<div class="content">
    <div class="tva-tel-hero mt-6">
        <div class="tva-tel-hero__icon">📞</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Telephony</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Twilio phone numbers per project. Each number routes calls to a pool of agents or a whole skill.
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

    {{-- Webhook URLs --}}
    <div class="tva-tel-card">
        <div class="tva-tel-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#fff5d1; color:#92400e; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="link-2" class="w-4 h-4"></i>
            </div>
            <div>
                <div class="tva-tel-card__title">Webhook URLs for Twilio Console</div>
                <div class="tva-tel-card__subtitle">Paste into the "A CALL COMES IN" and "CALL STATUS CHANGES" fields for each Twilio number. Same URLs for all numbers — routing is by phone number.</div>
            </div>
        </div>
        @if ($webhookUrls['voice'])
            <div class="tva-webhook-block">
                <button type="button" class="tva-webhook-copy" data-copy="{{ $webhookUrls['voice'] }}">Copy</button>
                <div class="tva-webhook-block__label">A CALL COMES IN  (POST)</div>
                <div class="tva-webhook-block__url">{{ $webhookUrls['voice'] }}</div>
            </div>
            <div class="tva-webhook-block">
                <button type="button" class="tva-webhook-copy" data-copy="{{ $webhookUrls['status'] }}">Copy</button>
                <div class="tva-webhook-block__label">CALL STATUS CHANGES  (POST)</div>
                <div class="tva-webhook-block__url">{{ $webhookUrls['status'] }}</div>
            </div>
        @else
            <div class="text-sm text-slate-500">
                Set <code>TWILIO_WEBHOOK_BASE</code> in <code>admin/.env</code> to see the URLs here.
            </div>
        @endif
    </div>

    {{-- Per-project number lists --}}
    @forelse ($projects as $project)
        @php
            $numbers = (array) data_get($project->json_data, 'telephony.numbers', []);
            $agents = $perProject[$project->id]['agents'] ?? collect();
            $skills = $perProject[$project->id]['skills'] ?? collect();
            $flows  = $perProject[$project->id]['flows']  ?? collect();
        @endphp
        <div class="tva-tel-card">
            <div class="tva-tel-card__head">
                <div style="width:36px; height:36px; border-radius:10px; background:#dbeafe; color:#1e40af; display:flex; align-items:center; justify-content:center;">
                    <i data-lucide="folder" class="w-4 h-4"></i>
                </div>
                <div class="flex-1">
                    <div class="tva-tel-card__title">{{ $project->name }}</div>
                    <div class="tva-tel-card__subtitle">{{ count($numbers) }} number(s) · {{ $agents->count() }} agent(s) · {{ $skills->count() }} skill(s)</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-tva-modal-open="num-create-{{ $project->id }}">
                    <i data-lucide="plus" class="w-3 h-3 mr-1 inline"></i> Add number
                </button>
            </div>

            @forelse ($numbers as $idx => $n)
                @php
                    $isOn = !empty($n['enabled']);
                    $rtype = $n['routing_type'] ?? 'agents';
                @endphp
                <div class="tva-num-tile {{ !$isOn ? 'is-disabled' : '' }}">
                    <div class="tva-num-tile__icon"><i data-lucide="phone" class="w-4 h-4"></i></div>
                    <div class="flex-1 min-w-0">
                        <div class="tva-num-tile__phone">{{ $n['phone_number'] ?? '' }}</div>
                        <div class="tva-num-tile__meta">
                            <span class="tva-num-tile__chip {{ $isOn ? 'is-ok' : 'is-off' }}">{{ $isOn ? 'Active' : 'Disabled' }}</span>
                            @if ($rtype === 'flow')
                                @php $flow = $flows->firstWhere('id', (int) ($n['flow_id'] ?? 0)); @endphp
                                <span class="tva-num-tile__chip is-flow" style="background:#ecfeff; color:#0e7490;">FLOW · {{ $flow->name ?? 'unset' }}</span>
                            @elseif ($rtype === 'skill')
                                @php $skill = $skills->firstWhere('id', (int) ($n['skill_id'] ?? 0)); @endphp
                                <span class="tva-num-tile__chip is-skill">SKILL · {{ $skill->name ?? 'unset' }}</span>
                            @else
                                @php
                                    $aids = $n['agent_ids'] ?? [];
                                    $names = $agents->whereIn('id', $aids)->pluck('name')->all();
                                @endphp
                                <span class="tva-num-tile__chip is-agents">
                                    AGENTS · {{ empty($names) ? 'unset' : implode(', ', $names) }}
                                </span>
                            @endif
                            <span class="tva-num-tile__chip">Polly · {{ $n['welcome_voice'] ?? 'Matthew' }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" class="text-primary inline-flex items-center justify-center w-8 h-8 rounded hover:bg-primary/10" data-tva-modal-open="num-edit-{{ $project->id }}-{{ $idx }}" title="Edit">
                            <i data-lucide="pencil" class="w-4 h-4"></i>
                        </button>
                        <form method="POST" action="{{ route('telephony.delete-number', ['client' => $client->slug]) }}" onsubmit="return confirm('Remove this number?');" class="inline">
                            @csrf
                            <input type="hidden" name="project_id" value="{{ $project->id }}">
                            <input type="hidden" name="number_index" value="{{ $idx }}">
                            <button type="submit" class="text-danger inline-flex items-center justify-center w-8 h-8 rounded hover:bg-danger/10" title="Remove">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Edit modal --}}
                @include('telephony._number_modal', [
                    'modalId'     => "num-edit-{$project->id}-{$idx}",
                    'title'       => 'Edit number',
                    'project'     => $project,
                    'numberIndex' => $idx,
                    'number'      => $n,
                    'agents'      => $agents,
                    'skills'      => $skills,
                    'flows'       => $flows,
                ])
            @empty
                <div class="text-center py-6 text-slate-400">
                    <div class="text-sm">No numbers assigned. Click <b>Add number</b> to bind a Twilio number to this project.</div>
                </div>
            @endforelse

            {{-- Create modal --}}
            @include('telephony._number_modal', [
                'modalId'     => "num-create-{$project->id}",
                'title'       => 'Add number',
                'project'     => $project,
                'numberIndex' => '__new__',
                'number'      => null,
                'agents'      => $agents,
                'skills'      => $skills,
                'flows'       => $flows,
            ])
        </div>
    @empty
        <div class="text-center py-8 text-slate-400">No projects yet.</div>
    @endforelse

    @if ($envDefault)
        <div class="text-xs text-slate-500 mt-2">
            <i data-lucide="info" class="w-3 h-3 inline -mt-0.5"></i>
            Env fallback <code>{{ $envDefault }}</code> routes to the first project when no project owns the dialed number.
        </div>
    @endif
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

    // Webhook copy buttons
    document.querySelectorAll('.tva-webhook-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            navigator.clipboard.writeText(b.dataset.copy).then(function () {
                b.textContent = 'Copied!'; b.classList.add('is-copied');
                setTimeout(function () { b.textContent = 'Copy'; b.classList.remove('is-copied'); }, 1500);
            });
        });
    });

    // Routing tabs inside each number modal — toggles which fields show.
    document.querySelectorAll('.tva-routing-tabs').forEach(function (tabs) {
        var hiddenInput = tabs.querySelector('input[name="routing_type"]');
        tabs.querySelectorAll('.tva-routing-tab').forEach(function (t) {
            t.addEventListener('click', function () {
                var v = t.dataset.routing;
                hiddenInput.value = v;
                tabs.querySelectorAll('.tva-routing-tab').forEach(function (x) { x.classList.remove('is-selected'); });
                t.classList.add('is-selected');
                var modal = tabs.closest('.tva-modal');
                modal.querySelectorAll('[data-routing-block]').forEach(function (b) {
                    b.style.display = (b.dataset.routingBlock === v) ? '' : 'none';
                });
            });
        });
    });
</script>
@endsection
