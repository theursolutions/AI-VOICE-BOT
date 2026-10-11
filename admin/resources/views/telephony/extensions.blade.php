@extends('layouts.master')

@section('content')
<style>
    .tva-ext-hero { background:var(--tva-gradient); color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow:0 10px 30px -10px rgba(0,0,0,.35); display:flex; align-items:center; gap:18px; flex-wrap:wrap; }
    .tva-ext-hero__icon { width:56px; height:56px; border-radius:14px; background:rgba(255,255,255,.18); display:flex;
        align-items:center; justify-content:center; font-size:28px; border:2px solid rgba(255,255,255,.3); flex-shrink:0; }
    .tva-ext-hero a.btn { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3); }
    .tva-ext-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px 22px; margin-bottom:18px; }
    .tva-ext-card__title { font-size:15px; font-weight:600; color:#0f172a; margin-bottom:12px; }
    .tva-ext-table { width:100%; border-collapse:collapse; font-size:13px; }
    .tva-ext-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:8px 10px; border-bottom:1px solid #e2e8f0; }
    .tva-ext-table td { padding:10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .tva-ext-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }
    .tva-ext-kv { display:grid; grid-template-columns:150px 1fr; gap:6px 12px; font-size:13px; }
    .tva-ext-kv code, .tva-ext-secret code { background:#f1f5f9; padding:2px 6px; border-radius:6px; }
    .tva-ext-secret { background:#fefce8; border:1px solid #fde68a; border-radius:8px; padding:6px 10px; display:inline-flex; gap:8px; align-items:center; }
    .tva-ext-grid { display:grid; grid-template-columns:minmax(0,5fr) minmax(0,7fr); gap:20px; align-items:start; }
    @media (max-width: 1100px) { .tva-ext-grid { grid-template-columns:1fr; } }
    .tva-ext-actions form { display:inline; }
    .tva-ext-actions .btn { padding:4px 8px; font-size:12px; }
    html.dark .tva-ext-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-ext-card__title { color:#f1f5f9; }
    html.dark .tva-ext-table td { border-color:#334155; }
    html.dark .tva-ext-kv code, html.dark .tva-ext-secret code { background:#0f172a; }
    html.dark .tva-ext-secret { background:#422006; border-color:#854d0e; }
</style>

<div class="content">
    <div class="tva-ext-hero mt-6">
        <div class="tva-ext-hero__icon">☎️</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Extensions</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Your team's phone extensions. Call each other, and your AI agent, from MicroSIP, Zoiper, a desk phone or the browser.
            </div>
        </div>
        <a href="{{ route('telephony.index', ['client' => $client]) }}" class="btn btn-sm">Numbers</a>
        <a href="{{ route('telephony.web-call', ['client' => $client]) }}" class="btn btn-sm">Website call button</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4">{{ session('success') }}</div>
    @endif
    @if (session('phone_reconfigured'))
        {{-- An open phone window still holds the old login: have it sign in with the new one. --}}
        <script>
            window.addEventListener('load', function () {
                setTimeout(function () { if (window.tvaSoftphone && window.tvaSoftphone.reloadConfig) window.tvaSoftphone.reloadConfig(); }, 1000);
            });
        </script>
    @endif
    @if (session('error'))
        <div class="alert alert-danger-soft show mb-4">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    <div class="tva-ext-grid">
        <div>
            <div class="tva-ext-card">
                <div class="tva-ext-card__title">Add an extension</div>
                <form method="POST" action="{{ route('telephony.extensions.store', ['client' => $client]) }}" class="grid gap-3">
                    @csrf
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="form-label">Number</label>
                            <input type="text" name="extension" class="form-control" required inputmode="numeric" pattern="[1-9][0-9]{1,5}"
                                   value="{{ old('extension') }}" placeholder="2001">
                        </div>
                        <div class="col-span-2">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" required maxlength="100" value="{{ old('name') }}" placeholder="Reception, Ali">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Used on</label>
                        <select name="kind" class="form-select">
                            @foreach (\App\Models\SipExtension::KINDS as $kind => $label)
                                <option value="{{ $kind }}" @selected(old('kind', 'app') === $kind)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div><button class="btn btn-primary">Create extension</button></div>
                    <small class="text-slate-500 text-xs">A strong password is generated for you and shown once. You can see it again or replace it any time.</small>
                </form>
            </div>

            <div class="tva-ext-card">
                <div class="tva-ext-card__title">How to connect</div>
                <div class="tva-ext-kv">
                    <span class="text-slate-500">SIP server</span> <span><code>{{ $server['host'] }}</code></span>
                    <span class="text-slate-500">Port</span> <span><code>{{ $server['port'] }}</code> · UDP or TCP</span>
                    <span class="text-slate-500">Username</span> <span>the extension's login below (e.g. <code>c{{ $client->id }}_2001</code>)</span>
                    <span class="text-slate-500">Domain</span> <span><code>{{ $server['host'] }}</code></span>
                    <span class="text-slate-500">Browser phone</span> <span><code>{{ $server['ws_url'] }}</code></span>
                </div>
                <div class="mt-3 text-sm" style="line-height:1.6">
                    <div>📞 Call a colleague: dial their extension number.</div>
                    <div>🤖 Reach your AI agent:
                        @if ($aiNumbers)
                            dial {!! collect($aiNumbers)->map(fn ($n) => '<code>' . e($n) . '</code>')->implode(' or ') !!}.
                        @else
                            add a number with <b>Connected through: Asterisk</b> on the <a href="{{ route('telephony.index', ['client' => $client]) }}">Numbers</a> page, then dial it.
                        @endif
                    </div>
                    <div>🔁 Test your audio: dial <code>600</code> (echo).</div>
                </div>
            </div>
        </div>

        <div>
            <div class="tva-ext-card">
                <div class="tva-ext-card__title">Your extensions ({{ $extensions->count() }})</div>
                @if ($states === null)
                    <div class="alert alert-warning-soft show mb-3 text-sm">The phone server isn't reachable right now, so who is online is unknown.</div>
                @endif
                @if ($extensions->isEmpty())
                    <div class="text-slate-500 text-sm">No extensions yet. Add your first one on the left.</div>
                @else
                <div class="overflow-x-auto">
                <table class="tva-ext-table">
                    <thead><tr><th>Ext.</th><th>Name</th><th>Login</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($extensions as $ext)
                        @php
                            $state = $states[$ext->sip_username] ?? null;
                            $color = $ext->last_error ? '#ef4444' : ($state === 'online' ? '#10b981' : '#94a3b8');
                            $label = $ext->last_error ? 'Not on server' : ($state === 'online' ? 'Online' : ($state === null ? 'Unknown' : 'Offline'));
                        @endphp
                        <tr>
                            <td><b style="font-size:15px">{{ $ext->extension }}</b></td>
                            <td>
                                {{ $ext->name }}
                                <div class="text-slate-500 text-xs">{{ $ext->kind === 'webrtc' ? 'Browser phone' : 'Phone / app' }}@if ($ext->user) · {{ $ext->user->name }}@endif</div>
                            </td>
                            <td>
                                <code>{{ $ext->sip_username }}</code>
                                @if (isset($reveal[$ext->id]))
                                    <div class="tva-ext-secret mt-1">
                                        <span class="text-xs">Password</span>
                                        <code id="pw-{{ $ext->id }}">{{ $reveal[$ext->id] }}</code>
                                        <button type="button" class="btn btn-secondary" style="padding:2px 6px; font-size:11px"
                                                onclick="navigator.clipboard.writeText(document.getElementById('pw-{{ $ext->id }}').textContent); this.textContent='Copied'">Copy</button>
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span class="tva-ext-dot" style="background:{{ $color }}"></span>{{ $label }}
                                @if ($ext->last_error)<div class="text-xs" style="color:#ef4444; max-width:220px">{{ $ext->last_error }}</div>@endif
                            </td>
                            <td class="tva-ext-actions whitespace-nowrap">
                                @if ($ext->kind === 'webrtc')
                                    <form method="POST" action="{{ route('telephony.extensions.use', [$client, $ext->id]) }}">@csrf
                                        <button class="btn btn-primary" title="Sign your admin-panel phone in as this extension">Use in my browser</button></form>
                                @endif
                                <form method="POST" action="{{ route('telephony.extensions.reveal', [$client, $ext->id]) }}">@csrf
                                    <button class="btn btn-secondary">Show password</button></form>
                                <form method="POST" action="{{ route('telephony.extensions.password', [$client, $ext->id]) }}"
                                      onsubmit="return confirm('Replace the password of {{ $ext->extension }}? Phones using the old one will be signed out.')">@csrf
                                    <button class="btn btn-secondary">New password</button></form>
                                @if ($ext->last_error)
                                    <form method="POST" action="{{ route('telephony.extensions.sync', [$client, $ext->id]) }}">@csrf
                                        <button class="btn btn-warning">Retry</button></form>
                                @endif
                                <form method="POST" action="{{ route('telephony.extensions.destroy', [$client, $ext->id]) }}"
                                      onsubmit="return confirm('Delete extension {{ $ext->extension }}?')">@csrf
                                    <button class="btn btn-danger">Delete</button></form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
