@extends('layouts.master')

@section('content')
<style>
    .cb-hero {
        background: var(--tva-gradient); color:#fff; border-radius:14px;
        padding:22px 26px; margin-bottom:22px; display:flex; align-items:center; gap:18px;
        box-shadow:0 10px 30px -10px rgba(0,0,0,.35);
    }
    .cb-hero__icon {
        width:56px; height:56px; border-radius:14px; flex-shrink:0;
        background:rgba(255,255,255,.18); border:2px solid rgba(255,255,255,.3);
        display:flex; align-items:center; justify-content:center; font-size:26px;
    }
    .cb-hero h1 { font-size:19px; font-weight:700; margin:0; }
    .cb-hero p { font-size:12.5px; opacity:.92; margin:4px 0 0; line-height:1.55; max-width:70ch; }

    .cb-sec { margin-bottom:26px; }
    .cb-sec__head { display:flex; align-items:baseline; justify-content:space-between; gap:14px; margin-bottom:12px; flex-wrap:wrap; }
    .cb-sec__t { font-size:14.5px; font-weight:650; color:#0f172a; }
    .cb-sec__n { font-size:11.5px; color:#94a3b8; }

    /* ── current brain, stated once and plainly ─────────────────────────── */
    .cb-now {
        background:#fff; border:1px solid #e6ecf1; border-left:3px solid var(--tva-accent, #0b6e5b);
        border-radius:13px; padding:20px 22px; display:flex; align-items:center; gap:18px; flex-wrap:wrap;
    }
    .cb-now__l { font:600 10.5px ui-monospace,Menlo,monospace; letter-spacing:.1em; text-transform:uppercase; color:#94a3b8; }
    .cb-now__v { font-size:20px; font-weight:700; color:#0f172a; margin-top:5px; }
    .cb-now__s { font-size:12.5px; color:#64748b; margin-top:4px; line-height:1.5; }

    /* ── brain cards ───────────────────────────────────────────────────── */
    .cb-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(268px,1fr)); gap:14px; }

    .cb-c {
        position:relative; background:#fff; border:1.5px solid #e6ecf1; border-radius:14px;
        padding:18px; text-align:left; cursor:pointer; width:100%;
        font-family:inherit; transition:border-color .15s, box-shadow .15s, transform .1s;
    }
    .cb-c:hover { border-color:#cbd5e1; box-shadow:0 2px 4px rgba(15,23,42,.04), 0 14px 28px -18px rgba(15,23,42,.3); }
    .cb-c:active { transform:translateY(1px); }
    .cb-c.is-on { border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.1); }
    .cb-c:focus-visible { outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.22); }

    /* Brand tile: the provider's monogram in its own colour on a 12% wash of the
       same hue, so nine providers sit at one visual weight instead of nine
       saturated blocks competing. */
    .cb-c__tile {
        width:44px; height:44px; border-radius:11px; display:flex; align-items:center;
        justify-content:center; font:700 15px ui-monospace,Menlo,monospace; margin-bottom:13px;
    }
    .cb-c__name { font-size:14.5px; font-weight:650; color:#0f172a; line-height:1.3; word-break:break-word; }
    .cb-c__sub { font-size:12px; color:#94a3b8; margin-top:3px; }

    .cb-c__foot { margin-top:14px; padding-top:12px; border-top:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .cb-tag { font:700 9.5px ui-monospace,Menlo,monospace; letter-spacing:.06em; text-transform:uppercase; padding:3px 8px; border-radius:5px; }
    .cb-tag--on { background:#dcfce7; color:#15803d; }
    .cb-tag--yours { background:#e0f2fe; color:#0369a1; }
    .cb-tag--auto { background:#eef2f6; color:#64748b; }
    .cb-tag--wait { background:#fdf3d7; color:#92400e; }
    .cb-tag--bad { background:#fee2e2; color:#b91c1c; }
    .cb-c__pick { font:600 11.5px system-ui,sans-serif; color:#0b6e5b; }

    .cb-c__err {
        margin-top:12px; padding:8px 10px; border-radius:7px; background:#fef6f6;
        border:1px solid #f7d9d9; color:#b91c1c; font-size:11.5px; line-height:1.45; word-break:break-word;
    }

    .cb-b {
        font:600 11.5px system-ui,sans-serif; padding:6px 11px; border-radius:7px;
        border:1px solid #e2e8f0; background:#fff; color:#334155; cursor:pointer;
    }
    .cb-b:hover { background:#f8fafc; }
    .cb-b--go { background:#0b6e5b; border-color:#0b6e5b; color:#fff; }
    .cb-b--del { color:#b91c1c; }

    /* ── add-your-own ──────────────────────────────────────────────────── */
    .cb-add {
        background:#fff; border:1.5px dashed #d8e2de; border-radius:14px; padding:18px;
        display:flex; flex-direction:column; align-items:flex-start; justify-content:center;
        gap:8px; cursor:pointer; width:100%; font-family:inherit; text-align:left;
        transition:border-color .15s, background .15s;
    }
    .cb-add:hover { border-color:#0b6e5b; background:#f6fbf9; }
    .cb-add__plus {
        width:44px; height:44px; border-radius:11px; background:#e2f0eb; color:#0b6e5b;
        display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:300;
    }
    .cb-add__t { font-size:14px; font-weight:650; color:#0f172a; }
    .cb-add__d { font-size:12px; color:#94a3b8; line-height:1.45; }

    .cb-note { padding:12px 14px; border-radius:10px; font-size:12.5px; line-height:1.55; margin-bottom:16px; }
    .cb-note--ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
    .cb-note--err  { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
    .cb-note--info { background:#f8fafc; border:1px solid #e6ecf1; color:#475569; }

    /* ── modal ─────────────────────────────────────────────────────────── */
    .cb-ov {
        position:fixed; inset:0; z-index:9998; display:none; background:rgba(15,30,36,.55);
        backdrop-filter:blur(2px); align-items:flex-start; justify-content:center;
        padding:40px 18px; overflow-y:auto;
    }
    .cb-ov.is-open { display:flex; }
    .cb-m { background:#fff; border-radius:15px; width:100%; max-width:640px; box-shadow:0 24px 60px -18px rgba(15,23,42,.5); overflow:hidden; }
    .cb-m__head { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid #e6ecf1; }
    .cb-m__t { font-size:16px; font-weight:650; color:#0f172a; }
    .cb-m__x { background:none; border:none; font-size:22px; color:#94a3b8; cursor:pointer; padding:2px 6px; border-radius:6px; }
    .cb-m__x:hover { background:#f1f5f9; color:#0f172a; }
    .cb-m__body { padding:20px 22px; }
    .cb-m__foot { display:flex; justify-content:flex-end; gap:9px; padding:15px 22px; border-top:1px solid #e6ecf1; background:#fbfcfd; }

    .cb-f { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
    @media (max-width:600px) { .cb-f { grid-template-columns:1fr; } }
    .cb-fld { display:flex; flex-direction:column; gap:5px; }
    .cb-fld--wide { grid-column:1/-1; }
    .cb-fld label { font:600 11.5px system-ui,sans-serif; color:#334155; }
    .cb-fld input, .cb-fld select {
        padding:9px 11px; border:1px solid #dfe6ec; border-radius:8px;
        font-size:13px; font-family:inherit; color:#0f172a; background:#fff; width:100%;
    }
    .cb-fld input:focus, .cb-fld select:focus { outline:none; border-color:#0b6e5b; box-shadow:0 0 0 3px rgba(11,110,91,.13); }
    .cb-fld small { font-size:10.5px; color:#94a3b8; line-height:1.45; }
</style>

<div class="content">

<div class="cb-hero mt-6">
    <div class="cb-hero__icon">🧠</div>
    <div>
        <h1>AI Brain</h1>
        <p>
            The AI that reads and answers your customers' messages. We manage one for you by
            default — or connect your own provider account and we will use that instead.
        </p>
    </div>
</div>

@if (session('success')) <div class="cb-note cb-note--ok">{{ session('success') }}</div> @endif
@if (session('error'))   <div class="cb-note cb-note--err">{{ session('error') }}</div> @endif
@if ($errors->any())     <div class="cb-note cb-note--err">{{ $errors->first() }}</div> @endif

<div class="cb-sec">
    <div class="cb-now">
        <div style="flex:1;min-width:200px;">
            <div class="cb-now__l">Answering your customers</div>
            <div class="cb-now__v">
                {{ $serving ? $serving->labelFor($client->id) : 'Managed by us' }}
            </div>
            <div class="cb-now__s">
                @if ($serving && $serving->client_id)
                    Running on your own provider account, so usage is billed to you directly.
                @elseif ($serving)
                    Included in your plan. Nothing to configure.
                @else
                    We are handling this for you. Nothing to configure.
                @endif
            </div>
        </div>
        @if ($chosenId)
            <form method="POST" action="{{ route('brain-settings.choose', ['client' => $client->slug]) }}">
                @csrf
                <input type="hidden" name="project_id" value="{{ $projectId }}">
                <input type="hidden" name="brain" value="">
                <button class="cb-b">Back to automatic</button>
            </form>
        @endif
    </div>
</div>

<div class="cb-sec">
    <div class="cb-sec__head">
        <span class="cb-sec__t">Choose your AI</span>
        <span class="cb-sec__n">Applies to new messages straight away</span>
    </div>

    <div class="cb-grid">
        @foreach ($available as $b)
            @php
                $tile  = $b->brandTile();
                $mine  = $b->client_id !== null;
                $isNow = $serving && $serving->id === $b->id;
                $picked = $chosenId === $b->id;
            @endphp
            <form method="POST" action="{{ route('brain-settings.choose', ['client' => $client->slug]) }}">
                @csrf
                <input type="hidden" name="project_id" value="{{ $projectId }}">
                <input type="hidden" name="brain" value="{{ $b->id }}">
                <button class="cb-c {{ $isNow ? 'is-on' : '' }}" type="submit">
                    <div class="cb-c__tile" style="background:{{ $tile['tint'] }};color:{{ $tile['color'] }};">
                        {{ $tile['mark'] }}
                    </div>
                    <div class="cb-c__name">{{ $b->labelFor($client->id) }}</div>
                    <div class="cb-c__sub">
                        {{ $mine ? ($b->presetConfig()['label'] ?? 'Your provider') . ' · your account' : 'Included in your plan' }}
                    </div>
                    <div class="cb-c__foot">
                        @if ($isNow)      <span class="cb-tag cb-tag--on">In use</span>
                        @elseif ($mine)   <span class="cb-tag cb-tag--yours">Yours</span>
                        @else             <span class="cb-tag cb-tag--auto">Available</span>
                        @endif
                        <span class="cb-c__pick">{{ $isNow ? ($picked ? 'Pinned' : 'Automatic') : 'Use this' }}</span>
                    </div>
                </button>
            </form>
        @endforeach

        <button class="cb-add" onclick="cbOpen()" type="button">
            <div class="cb-add__plus">+</div>
            <div class="cb-add__t">Use your own AI</div>
            <div class="cb-add__d">Connect an OpenAI, DeepSeek, Gemini, Claude or other account with your own API key.</div>
        </button>
    </div>
</div>

@if ($ownBrains->isNotEmpty())
<div class="cb-sec">
    <div class="cb-sec__head">
        <span class="cb-sec__t">Your connected providers</span>
        <span class="cb-sec__n">Billed directly to you</span>
    </div>

    <div class="cb-grid">
        @foreach ($ownBrains as $b)
            @php $tile = $b->brandTile(); @endphp
            <div class="cb-c" style="cursor:default;">
                <div class="cb-c__tile" style="background:{{ $tile['tint'] }};color:{{ $tile['color'] }};">{{ $tile['mark'] }}</div>
                <div class="cb-c__name">{{ $b->name }}</div>
                <div class="cb-c__sub">{{ $b->presetConfig()['label'] ?? $b->preset }} · {{ $b->model ?: 'default model' }}</div>

                @if ($b->verify_error)
                    <div class="cb-c__err">{{ $b->verify_error }}</div>
                @endif

                <div class="cb-c__foot">
                    @if ($b->is_verified && $b->is_active) <span class="cb-tag cb-tag--on">Connected</span>
                    @elseif ($b->verify_error)             <span class="cb-tag cb-tag--bad">Not connected</span>
                    @else                                  <span class="cb-tag cb-tag--wait">Needs testing</span>
                    @endif

                    <span style="display:flex;gap:6px;">
                        <button class="cb-b {{ $b->is_verified ? '' : 'cb-b--go' }}" type="button"
                                onclick="cbTest({{ $b->id }}, this)">
                            {{ $b->is_verified ? 'Re-test' : 'Test &amp; connect' }}
                        </button>
                        <form method="POST" action="{{ route('brain-settings.brains.destroy', ['client' => $client->slug, 'id' => $b->id]) }}"
                              onsubmit="return confirm('Disconnect “{{ $b->name }}”? We will handle your AI again.');">
                            @csrf @method('DELETE')
                            <button class="cb-b cb-b--del">Remove</button>
                        </form>
                    </span>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

</div>{{-- /.content --}}

{{-- ── connect-your-own modal ────────────────────────────────────────── --}}
<div class="cb-ov" id="cbOverlay" role="dialog" aria-modal="true" aria-labelledby="cbTitle">
    <div class="cb-m">
        <form method="POST" action="{{ route('brain-settings.brains.store', ['client' => $client->slug]) }}">
            @csrf
            <input type="hidden" name="project_id" value="{{ $projectId }}">

            <div class="cb-m__head">
                <span class="cb-m__t" id="cbTitle">Use your own AI</span>
                <button type="button" class="cb-m__x" onclick="cbClose()" aria-label="Close">&times;</button>
            </div>

            <div class="cb-m__body">
                <div class="cb-note cb-note--info" style="margin-bottom:18px;">
                    Your key is encrypted and used only for your own conversations. Usage is billed by
                    your provider directly to you, and we never charge for messages it handles.
                </div>

                <div class="cb-f">
                    <div class="cb-fld">
                        <label for="cbPreset">Provider</label>
                        <select name="preset" id="cbPreset" onchange="cbPreset(this.value)" required>
                            @foreach ($presets as $key => $p)
                                @if ($key !== 'ollama')
                                    <option value="{{ $key }}">{{ $p['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <div class="cb-fld">
                        <label for="cbName">Name it</label>
                        <input name="name" id="cbName" required maxlength="120" placeholder="Our OpenAI account">
                        <small>So you recognise it later.</small>
                    </div>

                    <div class="cb-fld cb-fld--wide">
                        <label for="cbKey">API key</label>
                        <input name="api_key" id="cbKey" required maxlength="512" autocomplete="off" placeholder="sk-…">
                        <small>Encrypted immediately. We never show it again, not even to you.</small>
                    </div>

                    <div class="cb-fld">
                        <label for="cbModel">Model <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                        <input name="model" id="cbModel" list="cbModels" maxlength="120">
                        <datalist id="cbModels"></datalist>
                        <small>Leave blank for your provider's default.</small>
                    </div>

                    <div class="cb-fld">
                        <label for="cbBase">Endpoint <span style="color:#94a3b8;font-weight:400;">(optional)</span></label>
                        <input name="base_url" id="cbBase" maxlength="255">
                        <small>Only change this for a custom or self-hosted endpoint.</small>
                    </div>
                </div>
            </div>

            <div class="cb-m__foot">
                <button type="button" class="cb-b" onclick="cbClose()">Cancel</button>
                <button class="cb-b cb-b--go" style="padding:9px 16px;">Add and continue</button>
            </div>
        </form>
    </div>
</div>

<script>
const CB_PRESETS = @json($presets);
let cbLastFocus = null;

function cbOpen() {
    cbLastFocus = document.activeElement;
    document.getElementById('cbOverlay').classList.add('is-open');
    document.body.style.overflow = 'hidden';
    cbPreset(document.getElementById('cbPreset').value);
    document.getElementById('cbName').focus();
}

function cbClose() {
    document.getElementById('cbOverlay').classList.remove('is-open');
    document.body.style.overflow = '';
    if (cbLastFocus) cbLastFocus.focus();
}

/* mousedown, not click: a text selection that happens to end outside the panel
   must not dismiss the form and discard a pasted API key. */
document.getElementById('cbOverlay').addEventListener('mousedown', e => {
    if (e.target.id === 'cbOverlay') cbClose();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('cbOverlay').classList.contains('is-open')) cbClose();
});

function cbPreset(key) {
    const p = CB_PRESETS[key];
    if (!p) return;
    document.getElementById('cbBase').value = p.base_url || '';
    document.getElementById('cbModels').innerHTML =
        (p.models || []).map(m => '<option value="' + m + '"></option>').join('');
    const model = document.getElementById('cbModel');
    if (!model.value && (p.models || []).length) model.value = p.models[0];
}

/* Test and connect in one action. A client has one intent — "use my AI" — and
   splitting it into test-then-enable creates a state where they believe they are
   on their own key and are not. */
function cbTest(id, btn) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = 'Connecting…';

    fetch('{{ url('c/' . $client->slug . '/brain-settings/brains') }}/' + id + '/verify', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
    })
    .then(r => r.json())
    .then(d => { alert(d.ok ? d.message : 'Could not connect.\n\n' + d.message); window.location.reload(); })
    .catch(() => { alert('Could not reach the server.'); btn.disabled = false; btn.innerHTML = original; });
}
</script>
@endsection
