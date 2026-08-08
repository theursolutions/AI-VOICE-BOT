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
        <button type="button" onclick="connectWhatsApp()" class="btn text-white" style="background:#25d366;">
            <i data-lucide="message-circle" class="w-4 h-4 mr-2 inline"></i> Connect WhatsApp
        </button>
        {{-- The customer's WhatsApp lives on their phone, not the machine
             they administer from. Scanning hands the flow to the device that
             can actually complete it. --}}
        <button type="button" onclick="openHandoff('whatsapp')" class="btn btn-secondary" title="Finish on your phone by scanning a QR code">
            <i data-lucide="qr-code" class="w-4 h-4 mr-1 inline"></i> Scan with phone
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
                @if ($log->isRetry())
                    <span class="tva-ch-chip" title="Attempt number">#{{ $log->attempt }}</span>
                @endif
                <span class="text-[11px] text-slate-400">{{ $log->created_at?->diffForHumans() }}</span>
                <button type="button" class="btn btn-sm btn-secondary" data-tva-modal-open="channel-log-{{ $log->id }}">
                    <i data-lucide="file-text" class="w-3.5 h-3.5 mr-1 inline"></i> View log
                </button>
                @if ($log->status === 'failed')
                    @if ($log->canReplay())
                        {{-- The whole point of storing the payload: Meta already
                             authorised us, so this replays our side only. --}}
                        <form method="POST" action="{{ route('channels.onboarding.retry', ['client' => $client->slug, 'log' => $log->id]) }}" class="inline">
                            @csrf
                            <input type="hidden" name="project_id" value="{{ $projectId }}">
                            <button type="submit" class="btn btn-sm btn-primary" title="Uses the authorisation Meta already gave us — no sign-in needed">
                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5 mr-1 inline"></i> Retry
                            </button>
                        </form>
                    @else
                        {{-- Nothing replayable stored (consent was denied, or the
                             saved credentials lapsed) — this genuinely has to
                             start at Meta again, and the tooltip says why. --}}
                        <button type="button" onclick="openConnect('{{ $retryKey[$log->provider] ?? 'facebook' }}')"
                                class="btn btn-sm btn-secondary"
                                title="{{ $log->payload?->retryBlockedReason() ?? 'Nothing stored to replay — sign in again.' }}">
                            <i data-lucide="external-link" class="w-3.5 h-3.5 mr-1 inline"></i> Reconnect
                        </button>
                    @endif
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
                        <div class="flex items-center gap-2 mb-3 flex-wrap">
                            <span class="tva-ch-chip {{ $sc[$log->status] ?? '' }}" style="{{ $log->status==='started' ? 'background:#fef3c7;color:#92400e;' : '' }}">{{ strtoupper($log->status) }}</span>
                            {{-- Which entry point was used: the three flows fail in
                                 different places, so this is the first thing worth
                                 knowing when reading a failure. --}}
                            <span class="tva-ch-chip">{{ str_replace('_', ' ', $log->method ?: 'redirect') }}</span>
                            @if ($log->isRetry())<span class="tva-ch-chip">attempt {{ $log->attempt }}</span>@endif
                            <span class="text-xs text-slate-400">{{ $log->created_at?->format('M j, Y H:i') }}</span>
                        </div>

                        {{-- Plain-language next action, keyed off the failure class
                             the pipeline recorded — rather than leaving the operator
                             to interpret a raw Graph error string. --}}
                        @if ($log->guidance())
                            <div class="mb-3 p-3 rounded" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; font-size:12.5px;">
                                <b>What to do:</b> {{ $log->guidance() }}
                            </div>
                        @endif

                        @if ($log->payload && $log->status === 'failed')
                            <div class="mb-3 text-xs" style="color:#475569;">
                                @if ($log->payload->isRetryable())
                                    ✅ Meta’s authorisation is saved (until
                                    {{ $log->payload->expires_at?->format('M j, Y') ?? 'expiry' }}) — Retry replays
                                    our side only, no sign-in needed. Stopped at
                                    <b>{{ $log->payload->status }}</b>.
                                @else
                                    ⚠️ {{ $log->payload->retryBlockedReason() }}
                                @endif
                            </div>
                        @endif
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
                    <input type="text" name="waba" maxlength="191" class="form-control" value="{{ old('waba') }}" placeholder="WABA id">
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

{{-- ── QR handoff modal ──────────────────────────────────────────────
     Desktop shows the code; the phone completes the flow. This panel just
     polls the same onboarding log the phone is writing to, so the operator
     sees it land without refreshing. --}}
<div id="channel-handoff" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <div class="tva-modal__panel" style="max-width:440px;">
        <div class="tva-modal__head">
            <i data-lucide="qr-code" class="w-4 h-4 mr-2 inline" style="color:#25d366;"></i>
            Finish on your phone
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="tva-modal__body" style="text-align:center;">
            <p class="text-sm text-slate-500" style="margin:0 0 16px;">
                Scan with your phone camera. Your WhatsApp lives there, so it's the quickest place to finish.
            </p>

            <div id="handoffQrBox" style="display:inline-block; padding:12px; background:#fff; border:1px solid #e2e8f0; border-radius:14px; min-height:284px; min-width:284px;">
                <div id="handoffLoading" class="text-xs text-slate-400" style="padding:120px 0;">Generating…</div>
                <img id="handoffQr" alt="QR code to continue on your phone" width="260" height="260" hidden>
            </div>

            <div id="handoffState" class="text-sm" style="margin-top:14px; color:#64748b;">
                Waiting for you to scan…
            </div>

            <div class="text-xs text-slate-400" style="margin-top:10px;">
                Expires in <span id="handoffTtl">15:00</span>. Can't scan?
                <a href="#" id="handoffFallback" style="color:#2563eb;">Continue on this computer instead</a>.
            </div>
        </div>
        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Close</button>
        </div>
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

    /* ── WhatsApp: Embedded Signup when available, redirect otherwise ─────
       Embedded Signup is Meta's own popup — the customer never leaves this
       page and is done in about two minutes. It needs a configuration id
       from the Meta app (META_WA_CONFIG_ID) and an approved Tech Provider
       app, so until that exists we fall back to the redirect flow, which
       needs neither. */
    var WA_CONFIG_ID = @json((string) config('meta.app.wa_config_id', ''));
    var META_APP_ID  = @json((string) config('meta.app.id', ''));
    var ES_POST_URL  = '{{ route('channels.embedded-signup', ['client' => $client->slug]) }}';
    var CSRF         = '{{ csrf_token() }}';

    function connectWhatsApp() {
        if (!WA_CONFIG_ID || !META_APP_ID || typeof FB === 'undefined') {
            return openConnect('whatsapp');       // graceful, not an error
        }
        FB.login(function (response) {
            var code = response && response.authResponse && response.authResponse.code;
            if (!code) { return; }                // user closed the popup

            var body = new FormData();
            body.append('_token', CSRF);
            body.append('project_id', '{{ $projectId }}');
            body.append('code', code);
            // Field names deliberately WITHOUT the _id suffix — the
            // DecodeHashids middleware rewrites any *_id request key to an
            // integer, which would mangle Meta's numeric string ids.
            if (window.__waSignup) {
                body.append('waba', window.__waSignup.waba_id || '');
                body.append('phone_number', window.__waSignup.phone_number_id || '');
            }

            fetch(ES_POST_URL, { method: 'POST', body: body, headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { window.location.reload(); }
                    else { alert(d.message || 'WhatsApp signup failed.'); window.location.reload(); }
                })
                .catch(function () { window.location.reload(); });
        }, {
            config_id: WA_CONFIG_ID,
            response_type: 'code',
            override_default_response_type: true,
            extras: { setup: {} }
        });
    }

    /* Meta posts the chosen WABA + number to the opener via postMessage —
       it is NOT in the FB.login response, so it has to be captured here and
       read back above. */
    window.addEventListener('message', function (event) {
        if (!/facebook\.com$/.test(new URL(event.origin).hostname)) return;
        try {
            var data = JSON.parse(event.data);
            if (data.type === 'WA_EMBEDDED_SIGNUP' && data.event === 'FINISH') {
                window.__waSignup = data.data || {};
            }
        } catch (_) { /* Meta also posts non-JSON chatter; ignore it */ }
    });

    /* ── QR handoff ─────────────────────────────────────────────────── */
    var HANDOFF_URL = '{{ url("/c/{$client->slug}/channels/handoff") }}';
    var handoffTimer = null, handoffTtlTimer = null;

    function openHandoff(provider) {
        var modal = document.getElementById('channel-handoff');
        var img   = document.getElementById('handoffQr');
        var load  = document.getElementById('handoffLoading');
        var state = document.getElementById('handoffState');

        img.hidden = true; load.hidden = false;
        state.textContent = 'Waiting for you to scan…';
        state.style.color = '#64748b';
        modal.removeAttribute('hidden');

        fetch(HANDOFF_URL + '/' + provider + '?project_id={{ $projectId }}', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.message || 'Could not create the handoff link.');
                img.src = d.qr; img.hidden = false; load.hidden = true;
                document.getElementById('handoffFallback').onclick = function (e) {
                    e.preventDefault(); closeHandoff(); openConnect(provider);
                };
                startHandoffPolling(d.log_id);
                startHandoffTtl(d.expires_in);
            })
            .catch(function (e) {
                load.textContent = e.message || 'Could not generate the QR code.';
            });
    }

    function startHandoffPolling(logId) {
        clearInterval(handoffTimer);
        var url = HANDOFF_URL + '/' + logId + '/status?project_id={{ $projectId }}';
        // 3s: fast enough to feel live while the customer is watching, slow
        // enough not to hammer the app for the full 15-minute window.
        handoffTimer = setInterval(function () {
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var state = document.getElementById('handoffState');
                    if (d.status === 'success') {
                        clearInterval(handoffTimer); clearInterval(handoffTtlTimer);
                        state.textContent = '✅ Connected — reloading…';
                        state.style.color = '#16a34a';
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else if (d.status === 'failed') {
                        clearInterval(handoffTimer); clearInterval(handoffTtlTimer);
                        state.textContent = '✗ ' + (d.guidance || d.error || 'Onboarding failed.');
                        state.style.color = '#dc2626';
                    } else if ((d.steps || []).some(function (s) { return s.step === 'phone_continue'; })) {
                        state.textContent = '📱 Scanned — finishing on your phone…';
                        state.style.color = '#2563eb';
                    }
                })
                .catch(function () { /* transient network blip — keep polling */ });
        }, 3000);
    }

    function startHandoffTtl(seconds) {
        clearInterval(handoffTtlTimer);
        var left = seconds || 900;
        var el = document.getElementById('handoffTtl');
        handoffTtlTimer = setInterval(function () {
            left -= 1;
            if (left <= 0) {
                clearInterval(handoffTtlTimer); clearInterval(handoffTimer);
                document.getElementById('handoffState').textContent = 'This code expired — close and try again.';
                el.textContent = '0:00';
                return;
            }
            el.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
        }, 1000);
    }

    function closeHandoff() {
        clearInterval(handoffTimer); clearInterval(handoffTtlTimer);
        var m = document.getElementById('channel-handoff');
        if (m) m.setAttribute('hidden', '');
    }
    // Stop polling whenever the modal is dismissed, however it was dismissed.
    document.addEventListener('click', function (e) {
        if (e.target.closest('#channel-handoff [data-tva-modal-close]')) closeHandoff();
    });
    @endif
</script>

@if ($project && config('meta.app.wa_config_id') && config('meta.app.id'))
{{-- Meta's JS SDK — only loaded when Embedded Signup is actually configured,
     so an unconfigured install ships no third-party script at all. --}}
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
<script>
    window.fbAsyncInit = function () {
        FB.init({
            appId: @json((string) config('meta.app.id')),
            autoLogAppEvents: true,
            xfbml: false,
            version: @json((string) config('meta.app.graph_version', 'v21.0')),
        });
    };
</script>
@endif
@endsection
