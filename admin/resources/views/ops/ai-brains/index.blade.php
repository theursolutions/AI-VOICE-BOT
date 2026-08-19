@extends('layouts.ops')

@section('content')
<style>
    /* ── page head ─────────────────────────────────────────────────────── */
    .brn-head { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:22px; }
    .brn-head__t { font-size:21px; font-weight:700; color:#0f172a; margin:0 0 6px; letter-spacing:-.01em; }
    .brn-head__d { font-size:13px; color:#64748b; line-height:1.6; max-width:70ch; margin:0; }

    .brn-cta {
        display:inline-flex; align-items:center; gap:8px; flex-shrink:0;
        background:#0b6e5b; color:#fff; border:none; border-radius:9px;
        padding:10px 17px; font:650 13px system-ui,sans-serif; cursor:pointer;
        box-shadow:0 1px 2px rgba(11,110,91,.2), 0 8px 18px -12px rgba(11,110,91,.5);
    }
    .brn-cta:hover { background:#095c4c; }
    .brn-cta svg, .brn-cta i { width:15px; height:15px; }

    /* ── summary strip ─────────────────────────────────────────────────── */
    .brn-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:24px; }
    @media (max-width:900px) { .brn-stats { grid-template-columns:repeat(2,1fr); } }
    .brn-stat { background:#fff; border:1px solid #e6ecf1; border-radius:11px; padding:14px 16px; }
    .brn-stat__l { font:600 10.5px ui-monospace,Menlo,monospace; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; }
    .brn-stat__v { font:700 22px ui-monospace,Menlo,monospace; color:#0f172a; margin-top:6px; line-height:1.1; }
    .brn-stat__v.is-good { color:#15803d; }
    .brn-stat__v.is-none { color:#b91c1c; }
    .brn-stat__s { font-size:11px; color:#94a3b8; margin-top:3px; }

    /* ── section ───────────────────────────────────────────────────────── */
    .brn-sec { margin-bottom:28px; }
    .brn-sec__head { display:flex; align-items:baseline; justify-content:space-between; gap:14px; margin-bottom:13px; flex-wrap:wrap; }
    .brn-sec__t { font-size:14.5px; font-weight:650; color:#0f172a; }
    .brn-sec__n { font-size:11.5px; color:#94a3b8; }

    /* ── brain card ────────────────────────────────────────────────────── */
    .brn-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:14px; }

    .brn-c {
        position:relative; background:#fff; border:1px solid #e6ecf1; border-radius:13px;
        padding:0 0 14px; overflow:hidden; transition:box-shadow .16s, border-color .16s;
    }
    .brn-c:hover { box-shadow:0 2px 4px rgba(15,23,42,.04), 0 14px 30px -18px rgba(15,23,42,.28); }
    .brn-c.is-off { background:#fbfcfd; }
    .brn-c.is-off .brn-c__name { color:#64748b; }
    .brn-c.is-broken { border-color:#f3c6c6; }

    /* status is carried by a stripe as well as a pill, so a glance down the
       grid reads live-vs-not without parsing any text */
    .brn-c__stripe { height:3px; background:#e2e8f0; }
    .brn-c.is-live .brn-c__stripe { background:linear-gradient(to right,#0b6e5b,#3fae93); }
    .brn-c.is-broken .brn-c__stripe { background:#dc2626; }
    .brn-c.is-untested .brn-c__stripe { background:#d19b2b; }

    .brn-c__top { display:flex; align-items:flex-start; gap:11px; padding:14px 16px 0; }
    .brn-c__pos {
        flex-shrink:0; width:26px; height:26px; border-radius:7px;
        background:#f1f5f9; color:#475569; font:700 12px ui-monospace,Menlo,monospace;
        display:flex; align-items:center; justify-content:center; cursor:grab;
    }
    .brn-c.is-live .brn-c__pos { background:#e2f0eb; color:#0b6e5b; }
    .brn-c__id { min-width:0; flex:1; }
    .brn-c__name { font-size:14.5px; font-weight:650; color:#0f172a; line-height:1.3; word-break:break-word; }
    .brn-c__prov { font:11.5px ui-monospace,Menlo,monospace; color:#94a3b8; margin-top:2px; }

    .brn-pill { font:700 9.5px ui-monospace,Menlo,monospace; letter-spacing:.06em; text-transform:uppercase; padding:3px 7px; border-radius:5px; white-space:nowrap; }
    .brn-pill--live { background:#dcfce7; color:#15803d; }
    .brn-pill--off { background:#eef2f6; color:#64748b; }
    .brn-pill--untested { background:#fdf3d7; color:#92400e; }
    .brn-pill--broken { background:#fee2e2; color:#b91c1c; }

    .brn-c__body { padding:12px 16px 0; }
    .brn-kv { display:flex; justify-content:space-between; gap:12px; font-size:12px; padding:5px 0; border-bottom:1px dashed #eef2f6; }
    .brn-kv:last-child { border-bottom:none; }
    .brn-kv__k { color:#94a3b8; flex-shrink:0; }
    .brn-kv__v { color:#334155; font-family:ui-monospace,Menlo,monospace; text-align:right; word-break:break-all; }
    .brn-kv__v.is-tier { color:#0369a1; }

    /* quota as a shape — "8.4M of 10M" is a proportion, not a number */
    .brn-q { padding:12px 16px 0; }
    .brn-q__row { display:flex; justify-content:space-between; font-size:11px; color:#64748b; margin-bottom:5px; }
    .brn-q__row b { color:#0f172a; font-family:ui-monospace,Menlo,monospace; font-weight:650; }
    .brn-q__bar { height:6px; border-radius:4px; background:#eef2f6; overflow:hidden; }
    .brn-q__fill { height:100%; border-radius:4px; background:#0b6e5b; transition:width .3s; }
    .brn-q__fill.is-warn { background:#d19b2b; }
    .brn-q__fill.is-full { background:#dc2626; }
    .brn-q__none { font-size:11.5px; color:#94a3b8; }

    .brn-c__err {
        margin:12px 16px 0; padding:8px 10px; border-radius:7px;
        background:#fef6f6; border:1px solid #f7d9d9; color:#b91c1c;
        font-size:11.5px; line-height:1.45; word-break:break-word;
    }

    .brn-c__acts { display:flex; gap:6px; padding:14px 16px 0; margin-top:12px; border-top:1px solid #f1f5f9; flex-wrap:wrap; }
    .brn-b {
        font:600 11.5px system-ui,sans-serif; padding:6px 11px; border-radius:7px;
        border:1px solid #e2e8f0; background:#fff; color:#334155; cursor:pointer;
    }
    .brn-b:hover { background:#f8fafc; border-color:#cbd5e1; }
    .brn-b--go { background:#0b6e5b; border-color:#0b6e5b; color:#fff; }
    .brn-b--go:hover { background:#095c4c; }
    .brn-b--del { color:#b91c1c; margin-left:auto; }
    .brn-b--del:hover { background:#fef2f2; border-color:#fecaca; }
    .brn-b:disabled { opacity:.45; cursor:not-allowed; }

    /* ── empty state ───────────────────────────────────────────────────── */
    .brn-empty {
        background:#fff; border:1px dashed #d8e2de; border-radius:13px;
        padding:38px 26px; text-align:center;
    }
    .brn-empty h3 { font-size:15px; font-weight:650; color:#0f172a; margin:0 0 7px; }
    .brn-empty p { font-size:13px; color:#64748b; line-height:1.6; max-width:56ch; margin:0 auto 18px; }

    /* ── notes ─────────────────────────────────────────────────────────── */
    .brn-note { padding:12px 14px; border-radius:10px; font-size:12.5px; line-height:1.55; margin-bottom:16px; }
    .brn-note--ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
    .brn-note--err { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
    .brn-note--info { background:#f8fafc; border:1px solid #e6ecf1; color:#475569; }

    /* ── modal ─────────────────────────────────────────────────────────── */
    .brn-ov {
        position:fixed; inset:0; z-index:9998; display:none;
        background:rgba(15,30,36,.55); backdrop-filter:blur(2px);
        align-items:flex-start; justify-content:center; padding:40px 18px; overflow-y:auto;
    }
    .brn-ov.is-open { display:flex; }
    .brn-m {
        background:#fff; border-radius:15px; width:100%; max-width:880px;
        box-shadow:0 24px 60px -18px rgba(15,23,42,.5); overflow:hidden;
    }
    .brn-m__head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:18px 22px; border-bottom:1px solid #e6ecf1; }
    .brn-m__t { font-size:16px; font-weight:650; color:#0f172a; }
    .brn-m__x { background:none; border:none; font-size:22px; line-height:1; color:#94a3b8; cursor:pointer; padding:2px 6px; border-radius:6px; }
    .brn-m__x:hover { background:#f1f5f9; color:#0f172a; }
    .brn-m__body { padding:20px 22px; }
    .brn-m__foot { display:flex; justify-content:flex-end; gap:9px; padding:15px 22px; border-top:1px solid #e6ecf1; background:#fbfcfd; }

    .brn-f { display:grid; grid-template-columns:repeat(2,1fr); gap:15px; }
    @media (max-width:680px) { .brn-f { grid-template-columns:1fr; } }
    .brn-fld { display:flex; flex-direction:column; gap:5px; }
    .brn-fld--wide { grid-column:1/-1; }
    .brn-fld label { font:600 11.5px system-ui,sans-serif; color:#334155; }
    .brn-fld input, .brn-fld select {
        padding:9px 11px; border:1px solid #dfe6ec; border-radius:8px;
        font-size:13px; font-family:inherit; color:#0f172a; background:#fff; width:100%;
    }
    .brn-fld input:focus, .brn-fld select:focus { outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.13); }
    .brn-fld input:disabled { background:#f6f8f9; color:#94a3b8; }
    .brn-fld small { font-size:10.5px; color:#94a3b8; line-height:1.45; }
</style>

<div class="content">

<div class="brn-head mt-6">
    <div>
        <h1 class="brn-head__t">AI Brains</h1>
        <p class="brn-head__d">
            The model backends the platform can use, in the order they are tried. A call goes to the
            first live brain under its quota; when one runs out, the next takes over. Clients who
            bring their own key are served by that instead and never touch this pool.
        </p>
    </div>
    <button class="brn-cta" onclick="brnOpen()">
        <i data-lucide="plus"></i> Add a brain
    </button>
</div>

@if (session('success')) <div class="brn-note brn-note--ok">{{ session('success') }}</div> @endif
@if (session('error'))   <div class="brn-note brn-note--err">{{ session('error') }}</div> @endif
@if ($errors->any())
    <div class="brn-note brn-note--err">{{ $errors->first() }}</div>
@endif

@php
    $live    = $brains->where('is_active', true)->where('is_verified', true);
    $primary = $live->sortBy('priority')->first(fn ($b) => ! $b->isOverQuota());
    $spent   = $brains->filter(fn ($b) => $b->quota_tokens && $b->isOverQuota())->count();
    $tokens30 = $usage->sum('tokens');
@endphp

<div class="brn-stats">
    <div class="brn-stat">
        <div class="brn-stat__l">Serving now</div>
        <div class="brn-stat__v {{ $primary ? 'is-good' : 'is-none' }}" style="font-size:{{ $primary ? '15px' : '22px' }};">
            {{ $primary->name ?? 'Engine default' }}
        </div>
        <div class="brn-stat__s">{{ $primary ? 'first live brain under quota' : 'no brain is live — using voice-engine env' }}</div>
    </div>
    <div class="brn-stat">
        <div class="brn-stat__l">Live in chain</div>
        <div class="brn-stat__v">{{ $live->count() }}<span style="font-size:14px;color:#94a3b8;"> / {{ $brains->count() }}</span></div>
        <div class="brn-stat__s">tested and switched on</div>
    </div>
    <div class="brn-stat">
        <div class="brn-stat__l">Quota spent</div>
        <div class="brn-stat__v {{ $spent ? 'is-none' : '' }}">{{ $spent }}</div>
        <div class="brn-stat__s">{{ $spent ? 'skipped until the window resets' : 'none exhausted' }}</div>
    </div>
    <div class="brn-stat">
        <div class="brn-stat__l">Tokens · 30 days</div>
        <div class="brn-stat__v">{{ $tokens30 >= 1000000 ? round($tokens30 / 1000000, 1) . 'M' : number_format($tokens30) }}</div>
        <div class="brn-stat__s">across every brain</div>
    </div>
</div>

<div class="brn-sec">
    <div class="brn-sec__head">
        <span class="brn-sec__t">Fallback chain</span>
        <span class="brn-sec__n">Drag the number to reorder &middot; position 1 is tried first</span>
    </div>

    @if ($brains->isEmpty())
        <div class="brn-empty">
            <h3>No brains configured yet</h3>
            <p>
                Until one is added, the platform keeps using whatever the voice-engine has in its own
                environment — which works, but gives you no quota control and no usage reporting.
            </p>
            <button class="brn-cta" onclick="brnOpen()"><i data-lucide="plus"></i> Add the first brain</button>
        </div>
    @else
        <div class="brn-grid" id="brnChain">
            @foreach ($brains as $b)
                @php
                    $pct   = $b->quotaPercent();
                    $used  = $usage[$b->id] ?? null;
                    $state = ! $b->is_verified ? ($b->verify_error ? 'broken' : 'untested')
                           : ($b->is_active ? 'live' : 'off');
                @endphp
                <div class="brn-c is-{{ $state }} {{ $b->is_active ? '' : 'is-off' }}"
                     draggable="true" data-id="{{ $b->id }}">
                    <div class="brn-c__stripe"></div>

                    <div class="brn-c__top">
                        <div class="brn-c__pos" title="Drag to reorder">{{ $loop->iteration }}</div>
                        <div class="brn-c__id">
                            <div class="brn-c__name">{{ $b->name }}</div>
                            <div class="brn-c__prov">{{ $b->presetConfig()['label'] ?? $b->preset }}</div>
                        </div>
                        @if ($state === 'live')        <span class="brn-pill brn-pill--live">Live</span>
                        @elseif ($state === 'broken')  <span class="brn-pill brn-pill--broken">Failed</span>
                        @elseif ($state === 'untested')<span class="brn-pill brn-pill--untested">Untested</span>
                        @else                          <span class="brn-pill brn-pill--off">Off</span>
                        @endif
                    </div>

                    <div class="brn-c__body">
                        <div class="brn-kv"><span class="brn-kv__k">Model</span><span class="brn-kv__v">{{ $b->model ?: 'provider default' }}</span></div>
                        @if ($b->keyHint())
                            <div class="brn-kv"><span class="brn-kv__k">Key</span><span class="brn-kv__v">{{ $b->keyHint() }}</span></div>
                        @endif
                        @if ($b->public_label)
                            <div class="brn-kv"><span class="brn-kv__k">Clients see</span><span class="brn-kv__v is-tier">{{ $b->public_label }}</span></div>
                        @endif
                        @if ($used)
                            <div class="brn-kv">
                                <span class="brn-kv__k">Used · 30d</span>
                                <span class="brn-kv__v">
                                    {{ number_format($used->tokens) }} tok · {{ number_format($used->calls) }} calls
                                    @if ($used->failures) <span style="color:#b91c1c;">· {{ $used->failures }} failed</span> @endif
                                </span>
                            </div>
                        @endif
                    </div>

                    <div class="brn-q">
                        @if ($pct === null)
                            <div class="brn-q__none">No quota &mdash; unlimited</div>
                        @else
                            <div class="brn-q__row">
                                <span>{{ $b->quota_window === 'month' ? 'This month' : 'Lifetime' }}</span>
                                <span><b>{{ number_format($b->tokens_used) }}</b> / {{ number_format($b->quota_tokens) }}</span>
                            </div>
                            <div class="brn-q__bar">
                                <div class="brn-q__fill {{ $pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : '') }}" style="width:{{ max(2, $pct) }}%"></div>
                            </div>
                        @endif
                    </div>

                    @if ($b->verify_error)
                        <div class="brn-c__err">{{ $b->verify_error }}</div>
                    @endif

                    <div class="brn-c__acts">
                        @php $edit = $b->only(['id', 'name', 'preset', 'kind', 'base_url', 'model', 'max_tokens', 'priority', 'quota_tokens', 'quota_window', 'public_label']); @endphp
                        <button class="brn-b" onclick='brnEdit(@json($edit))'>Edit</button>
                        <button class="brn-b" onclick="brnTest({{ $b->id }}, this)">Test</button>

                        <form method="POST" action="{{ route('ops.ai-brains.toggle', $b->id) }}" style="display:inline;">
                            @csrf
                            <button class="brn-b {{ $b->is_active ? '' : 'brn-b--go' }}"
                                    @disabled(! $b->is_active && ! $b->is_verified)
                                    title="{{ ! $b->is_active && ! $b->is_verified ? 'Test it first' : '' }}">
                                {{ $b->is_active ? 'Switch off' : 'Go live' }}
                            </button>
                        </form>

                        @if ($b->quota_tokens)
                            <form method="POST" action="{{ route('ops.ai-brains.reset-quota', $b->id) }}" style="display:inline;">
                                @csrf <button class="brn-b">Reset usage</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('ops.ai-brains.destroy', $b->id) }}" style="display:contents;"
                              onsubmit="return confirm('Remove “{{ $b->name }}”? Usage history is kept.');">
                            @csrf @method('DELETE')
                            <button class="brn-b brn-b--del">Remove</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@if ($clientBrains->isNotEmpty())
<div class="brn-sec">
    <div class="brn-sec__head">
        <span class="brn-sec__t">Client-owned brains</span>
        <span class="brn-sec__n">Read-only &middot; their key, their bill</span>
    </div>
    <div class="brn-note brn-note--info">
        These clients serve their own traffic, so it does not appear on your invoice and does not
        consume the quotas above.
    </div>
    <div class="brn-grid">
        @foreach ($clientBrains as $cb)
            <div class="brn-c {{ $cb->is_active && $cb->is_verified ? 'is-live' : 'is-off' }}">
                <div class="brn-c__stripe"></div>
                <div class="brn-c__top">
                    <div class="brn-c__pos">&mdash;</div>
                    <div class="brn-c__id">
                        <div class="brn-c__name">{{ $cb->client->name ?? 'Client #' . $cb->client_id }}</div>
                        <div class="brn-c__prov">{{ $cb->name }} &middot; {{ $cb->presetConfig()['label'] ?? $cb->preset }}</div>
                    </div>
                    @if ($cb->is_active && $cb->is_verified)
                        <span class="brn-pill brn-pill--live">Live</span>
                    @else
                        <span class="brn-pill brn-pill--off">Off</span>
                    @endif
                </div>
                <div class="brn-c__body">
                    <div class="brn-kv"><span class="brn-kv__k">Model</span><span class="brn-kv__v">{{ $cb->model ?: 'provider default' }}</span></div>
                    @if ($cb->keyHint())
                        <div class="brn-kv"><span class="brn-kv__k">Key</span><span class="brn-kv__v">{{ $cb->keyHint() }}</span></div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

</div>{{-- /.content --}}

{{-- ── add-brain modal ───────────────────────────────────────────────── --}}
<div class="brn-ov" id="brnOverlay" role="dialog" aria-modal="true" aria-labelledby="brnModalTitle">
    <div class="brn-m" role="document">
        <form method="POST" action="{{ route('ops.ai-brains.store') }}" id="brnForm">
            @csrf
            {{-- Swapped to PATCH by brnEdit(); Laravel reads _method to route it. --}}
            <input type="hidden" name="_method" id="brnMethod" value="POST">
            <div class="brn-m__head">
                <span class="brn-m__t" id="brnModalTitle">Add a brain</span>
                <button type="button" class="brn-m__x" onclick="brnClose()" aria-label="Close">&times;</button>
            </div>

            <div class="brn-m__body">
                <div class="brn-note brn-note--info" style="margin-bottom:18px;">
                    It starts switched off and must pass a real one-token test before it can go live.
                    An untested brain is skipped by the router, so a mistyped key stays a
                    configuration problem instead of becoming a silent outage.
                </div>

                <div class="brn-f">
                    <div class="brn-fld">
                        <label for="preset">Provider</label>
                        <select name="preset" id="preset" onchange="brnPreset(this.value)" required>
                            @foreach ($presets as $key => $p)
                                <option value="{{ $key }}">{{ $p['label'] }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="kind" id="kind" value="openai_compat">
                        <small>Nearly all of these speak one wire format, so any OpenAI-compatible provider works.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="name">Name <span style="color:#94a3b8;font-weight:400;">(internal)</span></label>
                        <input name="name" id="name" required maxlength="120" placeholder="Platform Flash-Lite">
                        <small>Only staff see this.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="model">Model</label>
                        <input name="model" id="model" list="brnModels" maxlength="120">
                        <datalist id="brnModels"></datalist>
                        <small>Blank uses the provider's default.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="api_key">API key</label>
                        <input name="api_key" id="api_key" maxlength="512" autocomplete="off" placeholder="sk-…">
                        <small id="brnKeyNote">Encrypted at rest. Never shown again after saving.</small>
                    </div>

                    <div class="brn-fld brn-fld--wide">
                        <label for="base_url">Base URL</label>
                        <input name="base_url" id="base_url" maxlength="255">
                        <small>Pre-filled by the provider choice. Only change it for a custom endpoint.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="public_label">Shown to clients as</label>
                        <input name="public_label" id="public_label" maxlength="60" placeholder="Standard">
                        <small>A neutral tier name, so the vendor behind your pricing stays private.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="priority">Priority</label>
                        <input type="number" name="priority" id="priority" value="{{ ($brains->max('priority') ?? 0) + 10 }}" min="1" max="9999" required>
                        <small>Lower is tried first.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="quota_tokens">Token quota</label>
                        <input type="number" name="quota_tokens" id="quota_tokens" min="1000" placeholder="150000000">
                        <small>Blank = unlimited. 150,000,000 is roughly $20/month on Flash-Lite.</small>
                    </div>

                    <div class="brn-fld">
                        <label for="quota_window">Quota resets</label>
                        <select name="quota_window" id="quota_window">
                            <option value="month">Every month</option>
                            <option value="total">Never (lifetime cap)</option>
                        </select>
                    </div>

                    <div class="brn-fld">
                        <label for="max_tokens">Max reply tokens</label>
                        <input type="number" name="max_tokens" id="max_tokens" value="4096" min="256" max="32000" required>
                    </div>
                </div>
            </div>

            <div class="brn-m__foot">
                <button type="button" class="brn-b" onclick="brnClose()">Cancel</button>
                <button class="brn-cta" style="box-shadow:none;" id="brnSubmit">Add brain</button>
            </div>
        </form>
    </div>
</div>

<script>
const BRN_PRESETS = @json($presets);

/* ── modal ──────────────────────────────────────────────────────────────
   Self-contained rather than the theme's modal system: this page is the only
   caller, and a hand-rolled overlay cannot break when the theme's JS changes.
   Focus moves to the first field on open and back to the trigger on close, so
   the form is usable without a mouse. */
let brnLastFocus = null;

const BRN_STORE_URL = '{{ route('ops.ai-brains.store') }}';
const BRN_BASE_URL  = '{{ url('admin/ai-brains') }}';

function brnOpen() {
    brnLastFocus = document.activeElement;
    document.getElementById('brnModalTitle').textContent = 'Add a brain';
    document.getElementById('brnSubmit').textContent = 'Add brain';
    document.getElementById('brnForm').action = BRN_STORE_URL;
    document.getElementById('brnMethod').value = 'POST';
    document.getElementById('brnForm').reset();
    document.getElementById('brnKeyNote').textContent = 'Encrypted at rest. Never shown again after saving.';
    brnShow();
    brnPreset(document.getElementById('preset').value);
}

/* Edit reuses the same modal. The key field stays EMPTY and blank means "leave it
   alone" — the server never sends a stored key back to the browser, so
   pre-filling is impossible and treating empty as "delete" would wipe a working
   credential on every unrelated edit. */
function brnEdit(b) {
    brnLastFocus = document.activeElement;
    document.getElementById('brnModalTitle').textContent = 'Edit ' + b.name;
    document.getElementById('brnSubmit').textContent = 'Save changes';
    document.getElementById('brnForm').action = BRN_BASE_URL + '/' + b.id;
    document.getElementById('brnMethod').value = 'PATCH';

    document.getElementById('preset').value       = b.preset || 'custom';
    brnPreset(b.preset || 'custom');

    document.getElementById('name').value         = b.name || '';
    document.getElementById('kind').value         = b.kind || 'openai_compat';
    document.getElementById('base_url').value     = b.base_url || '';
    document.getElementById('model').value        = b.model || '';
    document.getElementById('max_tokens').value   = b.max_tokens || 4096;
    document.getElementById('priority').value     = b.priority || 10;
    document.getElementById('quota_tokens').value = b.quota_tokens || '';
    document.getElementById('quota_window').value = b.quota_window || 'month';
    document.getElementById('public_label').value = b.public_label || '';
    document.getElementById('api_key').value      = '';
    document.getElementById('brnKeyNote').textContent =
        'Leave blank to keep the current key. Changing it requires testing again.';

    brnShow();
}

function brnShow() {
    document.getElementById('brnOverlay').classList.add('is-open');
    document.body.style.overflow = 'hidden';
    document.getElementById('name').focus();
}

function brnClose() {
    document.getElementById('brnOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
    if (brnLastFocus) brnLastFocus.focus();
}

document.getElementById('brnOverlay').addEventListener('mousedown', e => {
    // Only a click on the backdrop itself closes — mousedown, so a text
    // selection that happens to end outside the panel does not dismiss the form
    // and lose everything typed into it.
    if (e.target.id === 'brnOverlay') brnClose();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('brnOverlay').classList.contains('is-open')) brnClose();
});

/* Pre-fill connection details from the provider choice. Models are a datalist,
   not a select: provider catalogues change faster than this page will, and a
   hard list would eventually block a model that is perfectly valid. */
function brnPreset(key) {
    const p = BRN_PRESETS[key];
    if (!p) return;
    document.getElementById('kind').value = p.kind;
    document.getElementById('base_url').value = p.base_url || '';
    document.getElementById('brnModels').innerHTML =
        (p.models || []).map(m => '<option value="' + m + '"></option>').join('');
    const model = document.getElementById('model');
    if (!model.value && (p.models || []).length) model.value = p.models[0];
    const key_el = document.getElementById('api_key');
    key_el.disabled = !p.needs_key;
    key_el.placeholder = p.needs_key ? 'sk-…' : 'not needed for a local model';
}

/* Test = a real one-token call against the stored credentials. The only check
   that catches a revoked key, a wrong model name, or a base URL off by a path
   segment — none of which client-side validation would ever find. */
function brnTest(id, btn) {
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing…';

    fetch('{{ url('admin/ai-brains') }}/' + id + '/verify', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
    })
    .then(r => r.json())
    .then(d => { alert(d.ok ? 'Passed. ' + d.message : 'Failed.\n\n' + d.message); window.location.reload(); })
    .catch(() => { alert('Could not reach the server to run the test.'); btn.disabled = false; btn.textContent = original; });
}

/* Reorder by dragging the position badge. Order is the whole point of this
   list, so it is editable in place rather than behind a priority field. */
(function () {
    const chain = document.getElementById('brnChain');
    if (!chain) return;
    let dragging = null;

    chain.addEventListener('dragstart', e => {
        dragging = e.target.closest('.brn-c');
        if (dragging) dragging.style.opacity = '.4';
    });

    chain.addEventListener('dragend', () => {
        if (!dragging) return;
        dragging.style.opacity = '';
        dragging = null;
        const order = [...chain.querySelectorAll('.brn-c')].map(c => c.dataset.id);
        fetch('{{ route('ops.ai-brains.reorder') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ order }),
        }).then(() => window.location.reload());
    });

    chain.addEventListener('dragover', e => {
        e.preventDefault();
        if (!dragging) return;
        const over = e.target.closest('.brn-c');
        if (!over || over === dragging) return;
        const r = over.getBoundingClientRect();
        chain.insertBefore(dragging, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
    });
})();

/* The modal is injected after Lucide's initial sweep, so its icons need a
   second pass or the "+" glyphs render as empty <i> tags. */
if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
</script>
@endsection
