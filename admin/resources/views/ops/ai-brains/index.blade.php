@extends('layouts.ops')

@section('content')
<style>
    .brn-lede { font-size:13px; color:#64748b; max-width:74ch; line-height:1.6; margin:0 0 20px; }

    .brn-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; margin-bottom:16px; }
    .brn-card__head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
    .brn-card__title { font-size:15px; font-weight:650; color:#0f172a; }
    .brn-card__note { font-size:11.5px; color:#94a3b8; }

    /* chain rows — order is meaning here, so the row shows its position */
    .brn-row { display:flex; align-items:center; gap:14px; padding:13px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; margin-bottom:9px; }
    .brn-row.is-off { background:#fafafa; border-style:dashed; }
    .brn-row.is-broken { border-color:#fecaca; background:#fef8f8; }
    .brn-row__pos { font:600 12px ui-monospace,Menlo,monospace; color:#94a3b8; width:26px; flex-shrink:0; text-align:right; cursor:grab; }
    .brn-row__main { min-width:0; flex:1; }
    .brn-row__name { font-size:13.5px; font-weight:650; color:#0f172a; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .brn-row__meta { font:11.5px/1.5 ui-monospace,Menlo,monospace; color:#94a3b8; margin-top:3px; word-break:break-all; }
    .brn-row__err { font-size:11.5px; color:#b91c1c; margin-top:4px; }
    .brn-row__acts { display:flex; align-items:center; gap:6px; flex-shrink:0; flex-wrap:wrap; }

    .brn-pill { font:700 9.5px ui-monospace,Menlo,monospace; letter-spacing:.06em; text-transform:uppercase; padding:2px 7px; border-radius:4px; }
    .brn-pill--live { background:#dcfce7; color:#15803d; }
    .brn-pill--off { background:#e2e8f0; color:#475569; }
    .brn-pill--untested { background:#fef3c7; color:#92400e; }
    .brn-pill--broken { background:#fee2e2; color:#b91c1c; }
    .brn-pill--tier { background:#e0f2fe; color:#0369a1; }

    .brn-btn { font:600 11.5px system-ui,sans-serif; padding:6px 11px; border-radius:7px; border:1px solid #e2e8f0; background:#fff; color:#334155; cursor:pointer; }
    .brn-btn:hover { background:#f8fafc; border-color:#cbd5e1; }
    .brn-btn--go { background:#0b6e5b; border-color:#0b6e5b; color:#fff; }
    .brn-btn--go:hover { background:#095c4c; }
    .brn-btn--del { color:#b91c1c; }
    .brn-btn--del:hover { background:#fef2f2; border-color:#fecaca; }
    .brn-btn:disabled { opacity:.5; cursor:not-allowed; }

    /* quota meter — a bar because "8.4M of 10M" is a shape, not a number */
    .brn-quota { width:132px; flex-shrink:0; }
    .brn-quota__bar { height:5px; border-radius:3px; background:#e2e8f0; overflow:hidden; }
    .brn-quota__fill { height:100%; background:#0b6e5b; }
    .brn-quota__fill.is-warn { background:#b4690e; }
    .brn-quota__fill.is-full { background:#b91c1c; }
    .brn-quota__txt { font:11px ui-monospace,Menlo,monospace; color:#64748b; margin-top:4px; }

    .brn-form { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    @media (max-width:1000px) { .brn-form { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:600px) { .brn-form { grid-template-columns:1fr; } }
    .brn-f { display:flex; flex-direction:column; gap:5px; }
    .brn-f--wide { grid-column:span 2; }
    .brn-f label { font:600 11px system-ui,sans-serif; color:#475569; }
    .brn-f input, .brn-f select { padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; font-family:inherit; color:#0f172a; background:#fff; width:100%; }
    .brn-f input:focus, .brn-f select:focus { outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.14); }
    .brn-f small { font-size:10.5px; color:#94a3b8; line-height:1.45; }

    .brn-note { padding:11px 13px; border-radius:9px; font-size:12.5px; line-height:1.55; margin-bottom:14px; }
    .brn-note--info { background:#f8fafc; border:1px solid #e2e8f0; color:#475569; }
    .brn-note--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }

    .brn-empty { font-size:13px; color:#94a3b8; padding:16px 0; }
</style>

<h1 style="font-size:20px;font-weight:700;margin:0 0 6px;">AI Brains</h1>
<p class="brn-lede">
    The model backends the platform can use, in the order they are tried. A call goes to the first
    live brain that is under its quota; when one runs out, the next takes over. Clients who bring
    their own key are served by that instead, and never touch this pool.
</p>

@if (session('success'))
    <div class="brn-note brn-note--info" style="border-color:#bbf7d0;background:#f0fdf4;color:#15803d;">{{ session('success') }}</div>
@endif
@if (session('error'))
    <div class="brn-note brn-note--warn">{{ session('error') }}</div>
@endif

<div class="brn-card">
    <div class="brn-card__head">
        <span class="brn-card__title">Fallback chain &mdash; {{ $brains->count() }} configured</span>
        <span class="brn-card__note">Drag the number to reorder. Position 1 is tried first.</span>
    </div>

    @if ($brains->isEmpty())
        <p class="brn-empty">
            No brains configured yet. Until one is added, the platform keeps using whatever the
            voice-engine has in its own environment.
        </p>
    @else
        <div id="brnChain">
            @foreach ($brains as $b)
                @php
                    $pct   = $b->quotaPercent();
                    $used  = $usage[$b->id] ?? null;
                    $state = ! $b->is_verified ? ($b->verify_error ? 'broken' : 'untested')
                           : ($b->is_active ? 'live' : 'off');
                @endphp
                <div class="brn-row {{ $b->is_active ? '' : 'is-off' }} {{ $state === 'broken' ? 'is-broken' : '' }}"
                     draggable="true" data-id="{{ $b->id }}">
                    <div class="brn-row__pos" title="Drag to reorder">{{ $loop->iteration }}</div>

                    <div class="brn-row__main">
                        <div class="brn-row__name">
                            {{ $b->name }}
                            @if ($state === 'live')    <span class="brn-pill brn-pill--live">Live</span>
                            @elseif ($state === 'off') <span class="brn-pill brn-pill--off">Off</span>
                            @elseif ($state === 'broken') <span class="brn-pill brn-pill--broken">Failed test</span>
                            @else <span class="brn-pill brn-pill--untested">Untested</span>
                            @endif
                            @if ($b->public_label)
                                <span class="brn-pill brn-pill--tier">Shown as “{{ $b->public_label }}”</span>
                            @endif
                        </div>
                        <div class="brn-row__meta">
                            {{ $b->preset }} &middot; {{ $b->model ?: 'provider default' }}
                            @if ($b->keyHint()) &middot; key {{ $b->keyHint() }} @endif
                            @if ($used) &middot; {{ number_format($used->tokens) }} tokens / {{ number_format($used->calls) }} calls (30d)
                                @if ($used->failures) &middot; <span style="color:#b91c1c;">{{ $used->failures }} failed</span> @endif
                            @endif
                        </div>
                        @if ($b->verify_error)
                            <div class="brn-row__err">{{ $b->verify_error }}</div>
                        @endif
                    </div>

                    <div class="brn-quota">
                        @if ($pct === null)
                            <div class="brn-quota__txt">No quota &mdash; unlimited</div>
                        @else
                            <div class="brn-quota__bar">
                                <div class="brn-quota__fill {{ $pct >= 100 ? 'is-full' : ($pct >= 80 ? 'is-warn' : '') }}"
                                     style="width:{{ $pct }}%"></div>
                            </div>
                            <div class="brn-quota__txt">
                                {{ number_format($b->tokens_used) }} / {{ number_format($b->quota_tokens) }}
                                @if ($pct >= 100) &middot; spent @endif
                            </div>
                        @endif
                    </div>

                    <div class="brn-row__acts">
                        <button class="brn-btn" onclick="brnTest({{ $b->id }}, this)">Test</button>

                        <form method="POST" action="{{ route('ops.ai-brains.toggle', $b->id) }}" style="display:inline;">
                            @csrf
                            <button class="brn-btn {{ $b->is_active ? '' : 'brn-btn--go' }}"
                                    @disabled(! $b->is_active && ! $b->is_verified)
                                    title="{{ ! $b->is_active && ! $b->is_verified ? 'Test it first' : '' }}">
                                {{ $b->is_active ? 'Switch off' : 'Go live' }}
                            </button>
                        </form>

                        @if ($b->quota_tokens)
                            <form method="POST" action="{{ route('ops.ai-brains.reset-quota', $b->id) }}" style="display:inline;">
                                @csrf
                                <button class="brn-btn">Reset usage</button>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('ops.ai-brains.destroy', $b->id) }}" style="display:inline;"
                              onsubmit="return confirm('Remove “{{ $b->name }}”? Usage history is kept.');">
                            @csrf @method('DELETE')
                            <button class="brn-btn brn-btn--del">Remove</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="brn-card">
    <div class="brn-card__head">
        <span class="brn-card__title">Add a brain</span>
    </div>

    <div class="brn-note brn-note--warn">
        A new brain starts switched off and must pass a real one-token test before it can go live.
        That is deliberate: an untested brain is skipped by the router, so a mistyped key stays a
        configuration problem instead of becoming a silent outage on every conversation.
    </div>

    <form method="POST" action="{{ route('ops.ai-brains.store') }}">
        @csrf
        <div class="brn-form">
            <div class="brn-f">
                <label for="preset">Provider</label>
                <select name="preset" id="preset" onchange="brnPreset(this.value)" required>
                    @foreach ($presets as $key => $p)
                        <option value="{{ $key }}">{{ $p['label'] }}</option>
                    @endforeach
                </select>
                <input type="hidden" name="kind" id="kind" value="openai_compat">
                <small>Nearly all of these speak one wire format, so any OpenAI-compatible provider works.</small>
            </div>

            <div class="brn-f">
                <label for="name">Name (internal)</label>
                <input name="name" id="name" required maxlength="120" placeholder="Platform Flash-Lite">
                <small>Only staff see this.</small>
            </div>

            <div class="brn-f">
                <label for="model">Model</label>
                <input name="model" id="model" list="brnModels" maxlength="120" placeholder="gemini-2.5-flash-lite">
                <datalist id="brnModels"></datalist>
                <small>Blank uses the provider's default.</small>
            </div>

            <div class="brn-f">
                <label for="api_key">API key</label>
                <input name="api_key" id="api_key" maxlength="512" autocomplete="off" placeholder="sk-…">
                <small>Encrypted at rest. Never shown again after saving.</small>
            </div>

            <div class="brn-f brn-f--wide">
                <label for="base_url">Base URL</label>
                <input name="base_url" id="base_url" maxlength="255" placeholder="https://api.deepseek.com/v1">
                <small>Pre-filled by the provider choice. Only change it for a custom endpoint.</small>
            </div>

            <div class="brn-f">
                <label for="public_label">Shown to clients as</label>
                <input name="public_label" id="public_label" maxlength="60" placeholder="Standard">
                <small>A neutral tier name, so the vendor behind your pricing stays private.</small>
            </div>

            <div class="brn-f">
                <label for="priority">Priority</label>
                <input type="number" name="priority" id="priority" value="{{ ($brains->max('priority') ?? 0) + 10 }}"
                       min="1" max="9999" required>
                <small>Lower is tried first.</small>
            </div>

            <div class="brn-f">
                <label for="quota_tokens">Token quota</label>
                <input type="number" name="quota_tokens" id="quota_tokens" min="1000" placeholder="10000000">
                <small>Blank = unlimited. 10,000,000 is 10M.</small>
            </div>

            <div class="brn-f">
                <label for="quota_window">Quota resets</label>
                <select name="quota_window" id="quota_window">
                    <option value="month">Every month</option>
                    <option value="total">Never (lifetime cap)</option>
                </select>
            </div>

            <div class="brn-f">
                <label for="max_tokens">Max reply tokens</label>
                <input type="number" name="max_tokens" id="max_tokens" value="4096" min="256" max="32000" required>
            </div>

            <div class="brn-f" style="justify-content:flex-end;">
                <button class="brn-btn brn-btn--go" style="padding:9px 16px;">Add brain</button>
            </div>
        </div>
    </form>
</div>

@if ($clientBrains->isNotEmpty())
<div class="brn-card">
    <div class="brn-card__head">
        <span class="brn-card__title">Client-owned brains &mdash; {{ $clientBrains->count() }}</span>
        <span class="brn-card__note">Read-only. Their key, their bill.</span>
    </div>
    <div class="brn-note brn-note--info">
        These clients are serving their own traffic, so it does not appear on your invoice and does
        not consume the quotas above.
    </div>
    @foreach ($clientBrains as $cb)
        <div class="brn-row {{ $cb->is_active ? '' : 'is-off' }}">
            <div class="brn-row__pos">&mdash;</div>
            <div class="brn-row__main">
                <div class="brn-row__name">
                    {{ $cb->client->name ?? 'Client #' . $cb->client_id }} &mdash; {{ $cb->name }}
                    @if ($cb->is_active && $cb->is_verified)
                        <span class="brn-pill brn-pill--live">Live</span>
                    @else
                        <span class="brn-pill brn-pill--off">Off</span>
                    @endif
                </div>
                <div class="brn-row__meta">
                    {{ $cb->preset }} &middot; {{ $cb->model ?: 'provider default' }}
                    @if ($cb->keyHint()) &middot; key {{ $cb->keyHint() }} @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

<script>
const BRN_PRESETS = @json($presets);

/* Pre-fill the connection details from the provider choice. The model list is a
   datalist rather than a select, because provider catalogues change faster than
   this page will and a hard list would go stale and block a valid model. */
function brnPreset(key) {
    const p = BRN_PRESETS[key];
    if (!p) return;
    document.getElementById('kind').value = p.kind;
    document.getElementById('base_url').value = p.base_url || '';
    const dl = document.getElementById('brnModels');
    dl.innerHTML = (p.models || []).map(m => '<option value="' + m + '"></option>').join('');
    const model = document.getElementById('model');
    if (!model.value && (p.models || []).length) model.value = p.models[0];
    // A local model needs no key; leaving the field enabled invites a pointless one.
    const key_el = document.getElementById('api_key');
    key_el.disabled = !p.needs_key;
    key_el.placeholder = p.needs_key ? 'sk-…' : 'not needed for a local model';
}
brnPreset(document.getElementById('preset').value);

/* Test = a real one-token call against the stored credentials. The only check
   that catches a revoked key, a wrong model name, or a base URL off by a path
   segment — none of which any amount of client-side validation would find. */
function brnTest(id, btn) {
    const original = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Testing…';

    fetch('{{ url('admin/ai-brains') }}/' + id + '/verify', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
    })
    .then(r => r.json())
    .then(d => {
        alert(d.ok ? 'Passed. ' + d.message : 'Failed.\n\n' + d.message);
        window.location.reload();
    })
    .catch(() => {
        alert('Could not reach the server to run the test.');
        btn.disabled = false;
        btn.textContent = original;
    });
}

/* Reorder by dragging the position number. Order is the whole point of this
   list, so it is editable in place rather than behind a priority field. */
(function () {
    const chain = document.getElementById('brnChain');
    if (!chain) return;
    let dragging = null;

    chain.addEventListener('dragstart', e => {
        dragging = e.target.closest('.brn-row');
        if (dragging) dragging.style.opacity = '.4';
    });

    chain.addEventListener('dragend', () => {
        if (!dragging) return;
        dragging.style.opacity = '';
        dragging = null;

        const order = [...chain.querySelectorAll('.brn-row')].map(r => r.dataset.id);
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
        const over = e.target.closest('.brn-row');
        if (!over || over === dragging) return;
        const rect = over.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        chain.insertBefore(dragging, after ? over.nextSibling : over);
    });
})();
</script>
@endsection
