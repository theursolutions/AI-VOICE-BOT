@extends('layouts.master')

@section('content')
<style>
    .tva-ch-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-ch-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }
    .tva-ch-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; }
    .tva-ch-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .tva-ch-card__title { font-size:15px; font-weight:600; color:#0f172a; }
    /* Guaranteed 3-up grid (don't rely on Tailwind responsive utils — purged here) */
    .tva-ch-grid { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:14px; }
    @media (max-width:1100px) { .tva-ch-grid { grid-template-columns:repeat(2, minmax(0,1fr)); } }
    @media (max-width:680px)  { .tva-ch-grid { grid-template-columns:1fr; } }
    .tva-ch-del { width:34px; height:34px; border-radius:8px; border:1px solid #e2e8f0; background:#fff; color:#dc2626; display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; }
    .tva-ch-del:hover { background:#fef2f2; border-color:#fecaca; }
    html.dark .tva-ch-del { background:#1e293b; border-color:#334155; }
    .tva-ch-tile { background:#fff; border:1px solid #e2e8f0; border-top:3px solid #cbd5e1; border-radius:13px; padding:15px 16px 11px; display:flex; flex-direction:column; gap:9px; transition:box-shadow .15s; }
    .tva-ch-tile:hover { box-shadow:0 8px 22px -10px rgba(0,0,0,.22); }
    .tva-ch-tile.is-off { opacity:.6; }
    .tva-ch-tile__icon { width:46px; height:46px; border-radius:12px; background:#64748b; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 3px 9px -3px rgba(0,0,0,.3); }
    .tva-ch-tile__name { font-size:14px; font-weight:600; color:#0f172a; }
    .tva-ch-tile__foot { display:flex; align-items:center; gap:10px; padding-top:10px; border-top:1px solid #f1f5f9; }
    .tva-ch-chip { font-size:10px; padding:2px 8px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:700; letter-spacing:.02em; }
    .tva-ch-chip.is-on { background:#dcfce7; color:#15803d; }
    .tva-ch-chip.is-off { background:#fee2e2; color:#b91c1c; }

    /* per-platform identity */
    .tva-ch-tile--whatsapp { border-top-color:#25d366; }
    .tva-ch-tile--whatsapp .tva-ch-tile__icon { background:#25d366; }
    .tva-ch-tile--instagram { border-top-color:#dc2743; }
    .tva-ch-tile--instagram .tva-ch-tile__icon { background:linear-gradient(45deg,#f09433 0%,#e6683c 25%,#dc2743 50%,#cc2366 75%,#bc1888 100%); }
    .tva-ch-tile--facebook_page, .tva-ch-tile--messenger { border-top-color:#1877f2; }
    .tva-ch-tile--facebook_page .tva-ch-tile__icon, .tva-ch-tile--messenger .tva-ch-tile__icon { background:#1877f2; }

    /* toggle switch */
    .tva-switch { display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:12px; font-weight:600; color:#475569; }
    .tva-switch input { display:none; }
    .tva-switch__tr { width:38px; height:20px; border-radius:999px; background:#cbd5e1; position:relative; transition:.15s; flex-shrink:0; }
    .tva-switch input:checked + .tva-switch__tr { background:#22c55e; }
    .tva-switch__tr::after { content:''; position:absolute; top:2px; left:2px; width:16px; height:16px; border-radius:50%; background:#fff; transition:.15s; }
    .tva-switch input:checked + .tva-switch__tr::after { transform:translateX(18px); }

    html.dark .tva-ch-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-ch-card__head { border-bottom-color:#334155; }
    html.dark .tva-ch-card__title { color:#f1f5f9; }
    html.dark .tva-ch-tile { background:#1e293b; border-color:#334155; }
    html.dark .tva-ch-tile__foot { border-top-color:#334155; }
    html.dark .tva-ch-tile__name { color:#f1f5f9; }
    html.dark .tva-switch__tr { background:#475569; }
</style>

<div class="content">
    <div class="tva-ch-hero mt-6">
        <div class="tva-ch-hero__icon">🔌</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Channels</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Connect the Meta channels your AI talks on — WhatsApp number, Instagram, Facebook page.
                Enable or disable each without removing it.
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

    @if ($project)
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="text-sm text-slate-500 mr-auto">Connect a platform — sign in with Facebook in a popup and pick what to link.</div>
        <button type="button" onclick="openConnect('facebook')" class="btn text-white" style="background:#1877f2;">
            <i data-lucide="facebook" class="w-4 h-4 mr-2 inline"></i> Connect Facebook
        </button>
        <button type="button" onclick="openConnect('instagram')" class="btn text-white" style="background:linear-gradient(45deg,#f09433,#dc2743,#bc1888);">
            <i data-lucide="instagram" class="w-4 h-4 mr-2 inline"></i> Connect Instagram
        </button>
        <button type="button" onclick="openConnect('whatsapp')" class="btn text-white" style="background:#25d366;">
            <i data-lucide="message-circle" class="w-4 h-4 mr-2 inline"></i> Connect WhatsApp
        </button>
        <button type="button" class="btn btn-secondary" data-tva-modal-open="channel-create">
            <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> Connect channel
        </button>
    </div>
    @endif

    <div class="tva-ch-card">
        <div class="tva-ch-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#dbeafe; color:#1e40af; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="share-2" class="w-4 h-4"></i>
            </div>
            <div class="flex-1">
                <div class="tva-ch-card__title">Connected channels @if ($project) <span class="text-xs text-slate-500 font-normal">· {{ $project->name }}</span>@endif</div>
            </div>
        </div>

        <div class="tva-ch-grid">
            @php
                $icons = ['whatsapp' => 'message-circle', 'instagram' => 'instagram', 'facebook_page' => 'facebook', 'messenger' => 'messages-square'];
            @endphp
            @forelse ($connections as $conn)
                <div class="tva-ch-tile tva-ch-tile--{{ $conn->provider }} {{ $conn->isEnabled() ? '' : 'is-off' }}">
                    <div class="flex items-center gap-3">
                        <div class="tva-ch-tile__icon"><i data-lucide="{{ $icons[$conn->provider] ?? 'plug' }}" class="w-5 h-5"></i></div>
                        <div class="flex-1 min-w-0">
                            <div class="tva-ch-tile__name truncate">{{ $conn->name ?: ($providers[$conn->provider] ?? $conn->provider) }}</div>
                            <div class="text-xs text-slate-500">{{ $providers[$conn->provider] ?? $conn->provider }}</div>
                        </div>
                        <span class="tva-ch-chip {{ $conn->isEnabled() ? 'is-on' : 'is-off' }}">{{ $conn->isEnabled() ? 'ACTIVE' : 'INACTIVE' }}</span>
                    </div>
                    @if ($conn->external_id)
                        <div class="text-[11px] text-slate-400 truncate" title="{{ $conn->external_id }}">ID: {{ $conn->external_id }}</div>
                    @endif
                    <div class="tva-ch-tile__foot">
                        <form method="POST" action="{{ route('channels.toggle', ['client' => $client->slug, 'id' => $conn->id]) }}" class="flex-1">
                            @csrf
                            <input type="hidden" name="project_id" value="{{ $projectId }}">
                            <label class="tva-switch" title="{{ $conn->isEnabled() ? 'Disable this channel' : 'Enable this channel' }}">
                                <input type="checkbox" onchange="this.form.submit()" @checked($conn->isEnabled())>
                                <span class="tva-switch__tr"></span>
                                <span>{{ $conn->isEnabled() ? 'Active' : 'Inactive' }}</span>
                            </label>
                        </form>
                        <button type="button" class="tva-ch-del" data-tva-modal-open="channel-delete-{{ $conn->id }}" title="Delete channel">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>

                {{-- Delete modal --}}
                <div id="channel-delete-{{ $conn->id }}" class="tva-modal" hidden>
                    <div class="tva-modal__backdrop" data-tva-modal-close></div>
                    <div class="tva-modal__panel" style="max-width:420px;">
                        <div class="tva-modal__head">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-2 inline" style="color:#b91c1c;"></i>
                            Remove channel
                            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
                        </div>
                        <div class="tva-modal__body">
                            <p>Remove <b>{{ $conn->name ?: ($providers[$conn->provider] ?? $conn->provider) }}</b>? Inbound messages on this channel will stop being handled.</p>
                        </div>
                        <form method="POST" action="{{ route('channels.destroy', ['client' => $client->slug, 'id' => $conn->id]) }}" class="tva-modal__foot">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="project_id" value="{{ $projectId }}">
                            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
                            <button type="submit" class="btn btn-danger">Remove</button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="text-center py-10 text-slate-400" style="grid-column:1/-1;">
                    <i data-lucide="share-2" class="w-10 h-10 inline mb-2"></i>
                    <div class="font-medium">No channels connected yet.</div>
                    <div class="text-xs mt-1">Click "Connect channel" to onboard a WhatsApp number, Instagram or Facebook page.</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ── Onboarding activity (logs) ──────────────────────────────────── --}}
    @if ($project)
    @php $retryKey = ['facebook_page' => 'facebook', 'instagram' => 'instagram', 'whatsapp' => 'whatsapp', 'messenger' => 'facebook']; @endphp
    <div class="tva-ch-card mt-4">
        <div class="tva-ch-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#f1f5f9; color:#475569; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="history" class="w-4 h-4"></i>
            </div>
            <div class="flex-1"><div class="tva-ch-card__title">Onboarding activity</div></div>
        </div>
        @php $stepLabels = ['redirect_to_facebook'=>'Redirected to Facebook','consent'=>'User consent','token_exchange'=>'Token exchange','discover'=>'Discovered channels','import'=>'Imported channels','error'=>'Error']; @endphp
        @forelse ($onboardingLogs as $log)
            <div class="flex items-center gap-3 py-2" style="border-bottom:1px solid #f1f5f9;">
                <span class="tva-ch-chip">{{ $providers[$log->provider] ?? $log->provider }}</span>
                @php $sc = ['success' => 'is-on', 'failed' => 'is-off', 'started' => '']; @endphp
                <span class="tva-ch-chip {{ $sc[$log->status] ?? '' }}" style="{{ $log->status==='started' ? 'background:#fef3c7;color:#92400e;' : '' }}">{{ strtoupper($log->status) }}</span>
                <span class="text-xs text-slate-500 flex-1 truncate">
                    @if ($log->status === 'success') Imported {{ data_get($log->result, 'count', 0) }} channel(s): {{ implode(', ', (array) data_get($log->result, 'channels', [])) }}
                    @elseif ($log->status === 'failed') <span class="text-danger">{{ $log->error }}</span>
                    @else In progress… @endif
                </span>
                <span class="text-[11px] text-slate-400">{{ $log->created_at?->diffForHumans() }}</span>
                <button type="button" class="btn btn-sm btn-secondary" data-tva-modal-open="channel-log-{{ $log->id }}">
                    <i data-lucide="file-text" class="w-3.5 h-3.5 mr-1 inline"></i> View log
                </button>
                @if ($log->status === 'failed')
                    <button type="button" onclick="openConnect('{{ $retryKey[$log->provider] ?? 'facebook' }}')" class="btn btn-sm btn-primary">Retry</button>
                @endif
            </div>

            {{-- Per-attempt log modal: human-readable, color-coded steps --}}
            <div id="channel-log-{{ $log->id }}" class="tva-modal" hidden>
                <div class="tva-modal__backdrop" data-tva-modal-close></div>
                <div class="tva-modal__panel" style="max-width:540px;">
                    <div class="tva-modal__head">
                        <i data-lucide="history" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
                        Onboarding log · {{ $providers[$log->provider] ?? $log->provider }}
                        <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
                    </div>
                    <div class="tva-modal__body" style="max-height:62vh; overflow:auto;">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="tva-ch-chip {{ $sc[$log->status] ?? '' }}" style="{{ $log->status==='started' ? 'background:#fef3c7;color:#92400e;' : '' }}">{{ strtoupper($log->status) }}</span>
                            <span class="text-xs text-slate-400">{{ $log->created_at?->format('M j, Y H:i') }}</span>
                        </div>
                        @forelse ((array) $log->steps as $s)
                            <div class="flex items-start gap-3 py-2" style="border-bottom:1px solid #f1f5f9;">
                                <span style="font-size:15px; line-height:1.2; color:{{ ($s['ok'] ?? false) ? '#16a34a' : '#dc2626' }};">{{ ($s['ok'] ?? false) ? '✓' : '✗' }}</span>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium" style="color:{{ ($s['ok'] ?? false) ? '#0f172a' : '#dc2626' }};">{{ $stepLabels[$s['step'] ?? ''] ?? ($s['step'] ?? 'step') }}</div>
                                    @if (!empty($s['detail']))
                                        <div class="text-xs text-slate-500 break-words">{{ $s['detail'] }}</div>
                                    @endif
                                </div>
                                <span class="text-[10px] text-slate-400 whitespace-nowrap">{{ $s['at'] ?? '' }}</span>
                            </div>
                        @empty
                            <div class="text-xs text-slate-400">No steps recorded.</div>
                        @endforelse
                        @if ($log->error)
                            <div class="mt-3 p-3 rounded" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; font-size:12.5px;">
                                <b>Error:</b> {{ $log->error }}
                            </div>
                        @endif
                    </div>
                    <div class="tva-modal__foot">
                        <button type="button" class="btn btn-secondary" data-tva-modal-close>Close</button>
                        @if ($log->status === 'failed')
                            <button type="button" onclick="openConnect('{{ $retryKey[$log->provider] ?? 'facebook' }}')" class="btn btn-primary">Retry onboarding</button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-xs text-slate-400 py-3">No onboarding attempts yet.</div>
        @endforelse
    </div>
    @endif

    {{-- ── Connect channel modal ─────────────────────────────────────── --}}
    <div id="channel-create" class="tva-modal" hidden>
        <div class="tva-modal__backdrop" data-tva-modal-close></div>
        <form method="POST" action="{{ route('channels.store', ['client' => $client->slug]) }}" class="tva-modal__panel">
            @csrf
            <input type="hidden" name="project_id" value="{{ $projectId }}">
            <div class="tva-modal__head">
                <i data-lucide="plus-circle" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
                Connect a channel
                <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div class="tva-modal__body">
                <div class="mb-3">
                    <label class="form-label">Platform <span class="text-danger">*</span></label>
                    <select name="provider" required class="form-select">
                        @foreach ($providers as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Display name</label>
                    <input type="text" name="name" maxlength="191" class="form-control" value="{{ old('name') }}" placeholder="e.g. Support line +1 555 010 1234">
                </div>
                <div class="mb-3">
                    <label class="form-label">Channel ID</label>
                    <input type="text" name="external_id" maxlength="191" class="form-control" value="{{ old('external_id') }}" placeholder="WhatsApp phone_number_id / IG account id / FB page id">
                    <small class="text-slate-500 text-xs">For WhatsApp this is the <b>phone_number_id</b> — inbound messages are routed to this project by matching it.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">WhatsApp Business Account ID <span class="text-xs text-slate-400">(optional)</span></label>
                    <input type="text" name="waba_id" maxlength="191" class="form-control" value="{{ old('waba_id') }}" placeholder="WABA id">
                </div>
                <div class="mb-1">
                    <label class="form-label">Access token</label>
                    <input type="text" name="access_token" maxlength="4096" class="form-control" placeholder="Stored encrypted">
                    <small class="text-slate-500 text-xs">WhatsApp: optional (falls back to the app token). <b>Instagram / Facebook: required</b> — paste the Page access token.</small>
                </div>
            </div>
            <div class="tva-modal__foot">
                <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save" class="w-3 h-3 mr-1 inline"></i> Connect
                </button>
            </div>
        </form>
    </div>
</div>

@includeIf('skills._modal_css')

<script>
    if (window.lucide) try { window.lucide.createIcons(); } catch (_) {}
    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-tva-modal-open]');
        if (open) { var m = document.getElementById(open.getAttribute('data-tva-modal-open')); if (m) m.removeAttribute('hidden'); return; }
        var close = e.target.closest('[data-tva-modal-close]');
        if (close) { var modal = close.closest('.tva-modal'); if (modal) modal.setAttribute('hidden', ''); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.querySelectorAll('.tva-modal:not([hidden])').forEach(function (m){ m.setAttribute('hidden',''); });
    });

    @if ($project)
    // Open Facebook OAuth in a popup (Facebook can't be iframed). The popup
    // finishes onboarding server-side, reloads this page with the result,
    // and closes itself.
    var CONNECT_URLS = {
        facebook:  '{{ route('channels.connect', ['client' => $client->slug, 'provider' => 'facebook',  'project_id' => $projectId]) }}',
        instagram: '{{ route('channels.connect', ['client' => $client->slug, 'provider' => 'instagram', 'project_id' => $projectId]) }}',
        whatsapp:  '{{ route('channels.connect', ['client' => $client->slug, 'provider' => 'whatsapp',  'project_id' => $projectId]) }}'
    };
    function openConnect(provider) {
        var url = CONNECT_URLS[provider];
        if (!url) return;
        var w = 600, h = 720;
        var x = window.screenX + (window.outerWidth - w) / 2;
        var y = window.screenY + (window.outerHeight - h) / 2;
        var popup = window.open(url, 'metaconnect', 'popup,width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
        if (!popup) { window.location.href = url; }  // popup blocked → full redirect
    }
    @endif
</script>
@endsection
