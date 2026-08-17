@extends('layouts.master')

@section('content')
<style>
    .tva-tpl-hero {
        background: var(--tva-gradient);
        color:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display:flex; align-items:center; gap:18px;
    }
    .tva-tpl-hero__icon {
        width:56px; height:56px; border-radius:14px;
        background:rgba(255,255,255,.18); display:flex; align-items:center;
        justify-content:center; font-size:28px;
        border:2px solid rgba(255,255,255,.3); flex-shrink:0;
    }
    .tva-tpl-card { background:var(--tva-surface,#fff); border:1px solid var(--tva-border,#e2e8f0); border-radius:14px; padding:22px 24px; margin-bottom:18px; }
    .tva-tpl-card__head { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--tva-border,#e2e8f0); }
    .tva-tpl-card__title { font-size:15px; font-weight:600; color:var(--tva-text,#0f172a); }

    .tva-tpl-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:820px) { .tva-tpl-grid { grid-template-columns:1fr; } }

    .tva-tpl-lbl { display:block; font-size:12px; font-weight:650; color:var(--tva-text-2,#475569); margin-bottom:5px; }
    .tva-tpl-hint { font-size:11px; color:var(--tva-text-3,#94a3b8); margin-top:4px; line-height:1.5; }
    .tva-tpl-in, .tva-tpl-sel, .tva-tpl-ta {
        width:100%; padding:9px 11px; border-radius:9px; font-size:13px; font-family:inherit;
        border:1px solid var(--tva-border,#e2e8f0); background:var(--tva-surface,#fff); color:var(--tva-text,#0f172a);
    }
    .tva-tpl-ta { min-height:96px; resize:vertical; }
    .tva-tpl-in:focus, .tva-tpl-sel:focus, .tva-tpl-ta:focus { outline:none; border-color:#25d366; box-shadow:0 0 0 3px rgba(37,211,102,.16); }

    .tva-tpl-btn {
        display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:10px;
        border:none; background:#25d366; color:#fff; font-size:13px; font-weight:650; font-family:inherit; cursor:pointer;
    }
    .tva-tpl-btn:hover { background:#1eb857; }
    .tva-tpl-btn:disabled { opacity:.55; cursor:not-allowed; }

    .tva-tpl-row { display:flex; align-items:flex-start; gap:14px; padding:13px 0; border-bottom:1px solid var(--tva-border-2,#f1f5f9); }
    .tva-tpl-row:last-child { border-bottom:none; }
    .tva-tpl-name { font-size:13.5px; font-weight:650; color:var(--tva-text,#0f172a); font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
    .tva-tpl-body { font-size:12px; color:var(--tva-text-2,#475569); margin-top:3px; line-height:1.55; white-space:pre-wrap; }
    .tva-tpl-meta { font-size:11px; color:var(--tva-text-3,#94a3b8); margin-top:4px; display:flex; gap:9px; flex-wrap:wrap; }

    .tva-tpl-st { font-size:10px; font-weight:700; padding:3px 9px; border-radius:999px; letter-spacing:.02em; flex-shrink:0; }
    .tva-tpl-st--APPROVED { background:#dcfce7; color:#15803d; }
    .tva-tpl-st--PENDING  { background:#fef3c7; color:#92400e; }
    .tva-tpl-st--REJECTED { background:#fee2e2; color:#b91c1c; }
    .tva-tpl-st--UNKNOWN, .tva-tpl-st--PAUSED, .tva-tpl-st--DISABLED { background:#e2e8f0; color:#475569; }

    .tva-tpl-note { padding:12px 14px; border-radius:10px; font-size:12.5px; line-height:1.6; margin-bottom:14px; }
    .tva-tpl-note--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .tva-tpl-note--err  { background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; }
    .tva-tpl-note--ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
    .tva-tpl-note--info { background:var(--tva-surface-3,#f8fafc); border:1px solid var(--tva-border,#e2e8f0); color:var(--tva-text-2,#475569); }
</style>

<div class="tva-tpl-hero">
    <div class="tva-tpl-hero__icon">💬</div>
    <div>
        <h1 style="font-size:19px;font-weight:700;margin:0;">WhatsApp message templates</h1>
        <p style="font-size:12.5px;opacity:.9;margin:4px 0 0;">
            Outside the 24-hour reply window, a template is the only message WhatsApp lets a business send.
            @if ($number) <strong>{{ $number }}</strong> @endif
        </p>
    </div>
</div>

@if ($problem)
    <div class="tva-tpl-note tva-tpl-note--warn">{{ $problem }}</div>
@else

<div class="tva-tpl-card">
    <div class="tva-tpl-card__head">
        <span class="tva-tpl-card__title">Create a template</span>
    </div>

    <div id="tplMsg" style="display:none;"></div>

    <form id="tplForm" autocomplete="off">
        @csrf
        <input type="hidden" name="project_id" value="{{ $projectId }}">

        <div class="tva-tpl-grid">
            <div>
                <label class="tva-tpl-lbl" for="tplName">Name</label>
                <input class="tva-tpl-in" id="tplName" name="name" required maxlength="512"
                       placeholder="order_shipped">
                <div class="tva-tpl-hint">Lowercase letters, numbers and underscores only. Cannot be changed later.</div>
            </div>
            <div>
                <label class="tva-tpl-lbl" for="tplCat">Category</label>
                <select class="tva-tpl-sel" id="tplCat" name="category" required>
                    @foreach ($categories as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                    @endforeach
                </select>
                <div class="tva-tpl-hint">
                    UTILITY for order updates and follow-ups — approved fastest and costs least.
                    MARKETING is reviewed hardest; a promotional message submitted as UTILITY gets rejected.
                </div>
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="tva-tpl-lbl" for="tplBody">Message body</label>
            <textarea class="tva-tpl-ta" id="tplBody" name="body" required maxlength="1024"
                      placeholder="Hi {{ '{{1}}' }}, your order {{ '{{2}}' }} has shipped and arrives in 2–3 days."></textarea>
            <div class="tva-tpl-hint">
                Use <code>{{ '{{1}}' }}</code>, <code>{{ '{{2}}' }}</code> … for values you fill in when sending.
                Sample values are submitted automatically — Meta rejects a template whose placeholders have no examples.
                <span id="tplVars"></span>
            </div>
        </div>

        <div class="tva-tpl-grid" style="margin-top:14px;">
            <div>
                <label class="tva-tpl-lbl" for="tplHeader">Header <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                <input class="tva-tpl-in" id="tplHeader" name="header" maxlength="60" placeholder="Order update">
            </div>
            <div>
                <label class="tva-tpl-lbl" for="tplFooter">Footer <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                <input class="tva-tpl-in" id="tplFooter" name="footer" maxlength="60" placeholder="Reply STOP to opt out">
            </div>
        </div>

        <div style="margin-top:14px;">
            <label class="tva-tpl-lbl">Quick-reply buttons <span style="font-weight:400;color:#94a3b8;">(optional, up to 3)</span></label>
            <div class="tva-tpl-grid" style="grid-template-columns:repeat(3,1fr);">
                <input class="tva-tpl-in" name="buttons[]" maxlength="25" placeholder="Track order">
                <input class="tva-tpl-in" name="buttons[]" maxlength="25" placeholder="Contact support">
                <input class="tva-tpl-in" name="buttons[]" maxlength="25" placeholder="Thanks">
            </div>
        </div>

        <div style="margin-top:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button class="tva-tpl-btn" id="tplSubmit" type="submit">Create template</button>
            <span style="font-size:11.5px;color:var(--tva-text-3,#94a3b8);">
                Submitted to Meta for review — usually minutes for UTILITY.
            </span>
        </div>
    </form>
</div>

<div class="tva-tpl-card">
    <div class="tva-tpl-card__head">
        <span class="tva-tpl-card__title">Templates on this number ({{ count($templates) }})</span>
    </div>

    <div class="tva-tpl-note tva-tpl-note--info">
        Only <strong>APPROVED</strong> templates appear in the chat template picker — WhatsApp refuses to send
        any other status, so showing them there would offer a button that cannot work.
    </div>

    @forelse ($templates as $t)
        <div class="tva-tpl-row">
            <span class="tva-tpl-st tva-tpl-st--{{ $t['status'] }}">{{ $t['status'] }}</span>
            <div style="min-width:0;flex:1;">
                <div class="tva-tpl-name">{{ $t['name'] }}</div>
                @if ($t['body'] !== '')
                    <div class="tva-tpl-body">{{ $t['body'] }}</div>
                @endif
                <div class="tva-tpl-meta">
                    <span>{{ $t['language'] }}</span>
                    @if ($t['category']) <span>· {{ $t['category'] }}</span> @endif
                    @if (!empty($t['rejected']) && $t['rejected'] !== 'NONE')
                        <span style="color:#b91c1c;">· rejected: {{ $t['rejected'] }}</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <p style="font-size:13px;color:var(--tva-text-3,#94a3b8);margin:0;">
            No templates on this WhatsApp Business Account yet. Create the first one above.
        </p>
    @endforelse
</div>

<script>
(function () {
    var form   = document.getElementById('tplForm');
    var submit = document.getElementById('tplSubmit');
    var msgBox = document.getElementById('tplMsg');
    var body   = document.getElementById('tplBody');
    var vars   = document.getElementById('tplVars');
    var URL_   = @json(route('whatsapp.templates.store', ['client' => $client->slug]));

    function note(kind, text) {
        msgBox.className = 'tva-tpl-note tva-tpl-note--' + kind;
        msgBox.textContent = text;
        msgBox.style.display = 'block';
    }

    /* Live placeholder count. Mirrors GraphClient::placeholderCount() —
       DISTINCT indexes, because {{1}} twice is one variable and Meta counts it
       once when matching the examples we submit. */
    function countVars() {
        var seen = {}, n = 0, m, re = /\{\{\s*(\d+)\s*\}\}/g;
        while ((m = re.exec(body.value)) !== null) {
            if (!seen[m[1]]) { seen[m[1]] = 1; n++; }
        }
        vars.textContent = n ? 'Detected ' + n + ' variable' + (n === 1 ? '' : 's') + '.' : '';
    }
    body.addEventListener('input', countVars);
    countVars();

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        submit.disabled = true;
        msgBox.style.display = 'none';

        fetch(URL_, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json' },
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
            if (res.ok && res.d.ok) {
                note('ok', res.d.message);
                /* Reload so the new row appears with its real status straight
                   from Graph, rather than a status we guessed client-side. */
                setTimeout(function () { window.location.reload(); }, 1800);
                return;
            }
            /* Laravel 422 puts field errors under `errors`; our own Graph
               failures come back as `message`. Show whichever is present —
               Meta's rejection reason is the whole point of this box. */
            var m = res.d.message;
            if (!m && res.d.errors) {
                m = Object.keys(res.d.errors).map(function (k) { return res.d.errors[k][0]; }).join(' ');
            }
            note('err', m || 'Could not create the template.');
            submit.disabled = false;
        })
        .catch(function () {
            note('err', 'Network error — the template was not created.');
            submit.disabled = false;
        });
    });
})();
</script>

@endif
@endsection
