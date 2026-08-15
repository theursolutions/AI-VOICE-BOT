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
            <button type="button" class="btn btn-secondary ml-auto" data-tva-modal-open="flow-create">
                <i data-lucide="plus" class="w-4 h-4 mr-1 inline"></i> New flow
            </button>
            <button type="button" class="btn btn-primary ml-2" data-tva-modal-open="flow-ai">
                <i data-lucide="wand" class="w-4 h-4 mr-1 inline"></i> Build with AI
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
                    {{-- Change an existing flow in plain language: "add option 4
                         for careers". The current graph is sent along, so the AI
                         edits it rather than starting over. --}}
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-ai-edit="{{ $flow->id }}"
                            data-ai-name="{{ $flow->name }}"
                            data-ai-language="{{ $flow->language }}">
                        <i data-lucide="wand" class="w-3 h-3 inline mr-1"></i> Edit with AI
                    </button>
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

{{-- ── Build with AI ────────────────────────────────────────────────
     Two steps on purpose: generate → review → create. The customer sees
     the summary, the assumptions and (critically) what could NOT be built
     before anything is written. Creating sends back the exact graph that
     was previewed, so the flow they approved is the flow they get. --}}
<div id="flow-ai" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <div class="tva-modal__panel" style="max-width:720px;">
        <div class="tva-modal__head">
            <i data-lucide="wand" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
            <span id="ai-title">Build a flow with AI</span>
            <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
        </div>
        <div class="tva-modal__body">
            <div class="aiv aiv--info" id="ai-editing-note" hidden>
                <i data-lucide="pencil" class="w-3.5 h-3.5 inline -mt-0.5"></i>
                Editing <b id="ai-editing-name"></b>. Describe only what should change — everything
                else stays as it is.
            </div>

            <div class="mb-3" id="ai-name-row">
                <label class="form-label">Flow name <span class="text-danger">*</span></label>
                <input type="text" id="ai-name" required maxlength="160" class="form-control"
                       placeholder="After-hours support">
            </div>

            <div class="mb-3" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="form-label">Where does this run? <span class="text-danger">*</span></label>
                    <select id="ai-channel" class="form-select">
                        <option value="chat">Chat — website widget, WhatsApp, Messenger, Instagram</option>
                        <option value="voice">Voice — inbound phone calls</option>
                    </select>
                    <small class="text-slate-500 text-xs">
                        Phone calls support fewer step types. Choosing correctly is what lets the AI
                        warn you instead of building something that would drop the call.
                    </small>
                </div>
                <div id="ai-language-row">
                    <label class="form-label">Primary language</label>
                    <select id="ai-language" class="form-select">
                        <option value="en">English</option>
                        <option value="ur">Urdu</option>
                        <option value="hi">Hindi</option>
                        <option value="es">Spanish</option>
                        <option value="ar">Arabic</option>
                        <option value="fr">French</option>
                        <option value="de">German</option>
                    </select>
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" id="ai-brief-label">Describe the flow <span class="text-danger">*</span></label>
                <textarea id="ai-brief" class="form-control" rows="6" maxlength="4000"></textarea>
                <small class="text-slate-500 text-xs" id="ai-brief-hint">
                    Write it the way you'd explain it to a new receptionist. Say what should happen
                    when someone picks nothing or goes quiet.
                </small>
            </div>

            {{-- One click fills the box with a worked example. Staring at an
                 empty textarea is the main reason this kind of feature goes
                 unused. --}}
            <div class="mb-3" id="ai-examples" style="display:flex;flex-wrap:wrap;gap:6px;"></div>

            <div id="ai-result" hidden></div>
        </div>
        <div class="tva-modal__foot">
            <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
            <button type="button" class="btn btn-secondary" id="ai-generate">
                <i data-lucide="wand" class="w-4 h-4 mr-1 inline"></i> Generate
            </button>
            <button type="button" class="btn btn-primary" id="ai-create" hidden></button>
        </div>
    </div>
</div>

<style>
    .aiv { border-radius:10px; padding:12px 14px; margin-bottom:10px; font-size:12.5px; line-height:1.55; }
    .aiv--ok   { background:#f0fdf4; border:1px solid #bbf7d0; color:#14532d; }
    .aiv--warn { background:#fffbeb; border:1px solid #fde68a; color:#78350f; }
    .aiv--err  { background:#fef2f2; border:1px solid #fecaca; color:#7f1d1d; }
    .aiv--info { background:#f8fafc; border:1px solid #e2e8f0; color:#334155; }
    .aiv h4 { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; margin:0 0 6px; }
    .aiv ol, .aiv ul { margin:0; padding-left:18px; }
    .aiv li { margin-bottom:3px; }
    .aiv__gap { padding:8px 0; border-bottom:1px dashed rgba(0,0,0,.12); }
    .aiv__gap:last-child { border-bottom:none; }
    .aiv__gap b { display:block; }
    .aiv__gap span { display:block; opacity:.85; }
    html.dark .aiv--ok   { background:#052e16; border-color:#166534; color:#bbf7d0; }
    html.dark .aiv--warn { background:#3b2503; border-color:#92400e; color:#fde68a; }
    html.dark .aiv--err  { background:#450a0a; border-color:#991b1b; color:#fecaca; }
    html.dark .aiv--info { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

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

@if ($project)
<script>
/* ── AI flow builder ──────────────────────────────────────────────────
   Two modes through one modal:
     create — describe a flow → preview → save as a new draft
     revise — "add option 4 for careers" → preview → overwrite the graph
   Both go: generate, show what was built AND what could not be, then
   write. Nothing is saved until the customer has read the report. */
(function () {
    var CSRF      = '{{ csrf_token() }}';
    var PROJECT   = '{{ hashid($project->id) }}';
    var PLAN_URL  = '{{ route('flows.ai-plan',   ['client' => $client->slug]) }}';
    var CREATE_URL= '{{ route('flows.ai-create', ['client' => $client->slug]) }}';
    var SAVE_TPL  = '{{ route('flows.save-definition', ['client' => $client->slug, 'id' => 'FLOWID']) }}';
    var EDITOR_TPL= '{{ route('flows.editor', ['client' => $client->slug, 'id' => 'FLOWID']) }}?project_id=' + PROJECT;

    var modal   = document.getElementById('flow-ai');
    if (!modal) return;

    var elTitle = document.getElementById('ai-title');
    var elNote  = document.getElementById('ai-editing-note');
    var elNoteNm= document.getElementById('ai-editing-name');
    var elNameRow = document.getElementById('ai-name-row');
    var elLangRow = document.getElementById('ai-language-row');
    var elName  = document.getElementById('ai-name');
    var elChan  = document.getElementById('ai-channel');
    var elLang  = document.getElementById('ai-language');
    var elBrief = document.getElementById('ai-brief');
    var elLabel = document.getElementById('ai-brief-label');
    var elHint  = document.getElementById('ai-brief-hint');
    var elEx    = document.getElementById('ai-examples');
    var elResult= document.getElementById('ai-result');
    var btnGen  = document.getElementById('ai-generate');
    var btnGo   = document.getElementById('ai-create');

    var mode = 'create';        // 'create' | 'revise'
    var flowId = null;
    var plan = null;            // last previewed plan — what gets saved

    /* Re-render icons after injecting markup. Icons only exist as <i
       data-lucide="…"> until createIcons() swaps them for <svg>, so anything
       written with innerHTML after page load needs this call.

       Passing the icon set explicitly rather than calling createIcons() bare:
       the UMD global loaded by the theme defaults it, but lucide's ESM entry
       throws without it, so this works either way.

       NOTE: the icon name must exist in the theme's compiled bundle
       (public/assets/dist/js/app.js — 585 icons, NOT the full lucide set).
       A missing name silently leaves an empty <i>, which is how `sparkles`
       rendered as nothing here. Check before using a new one:
         grep -o -E 'icons/[a-z0-9-]+\.js' public/assets/dist/js/app.js */
    function ricons() {
        try {
            var L = window.lucide;
            if (L && L.createIcons) L.createIcons({ icons: (L.icons || {}), nameAttr: 'data-lucide' });
        } catch (_) {}
    }

    var EXAMPLES = {
        create: [
            ['Phone menu', 'Greet the caller warmly. Then offer a menu: 1 for sales, 2 for support, 3 for opening hours. Sales and support hand over to the AI agent. For opening hours, read them out and end the call politely. If they press nothing, repeat the menu once, then say goodbye.'],
            ['Capture a lead', 'Say hello and explain we can send a brochure on WhatsApp. Ask for their name, then their WhatsApp number. Send them the brochure on WhatsApp, confirm it has been sent, and end.'],
            ['Book a callback', 'Greet them, ask what they need help with, then take their name and phone number and tell them a member of the team will call back within one business hour.'],
            ['Out of hours', 'Tell the caller we are closed right now and give our opening hours. Offer: press 1 to leave a callback number, or 2 to speak to the AI assistant instead.']
        ],
        revise: [
            ['Add a menu option', 'Add a fourth option to the main menu: press 4 for careers, which reads out our jobs page address and ends the call.'],
            ['Friendlier wording', 'Keep the structure exactly the same but make all the wording warmer and less formal.'],
            ['Handle timeouts', 'If the caller presses nothing on the menu, repeat it once and then transfer to the AI agent instead of hanging up.'],
            ['Translate', 'Keep the same structure but rewrite every message in Urdu.']
        ]
    };

    function renderExamples() {
        elEx.innerHTML = '';
        EXAMPLES[mode].forEach(function (pair) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-secondary btn-sm';
            b.style.fontSize = '11px';
            b.textContent = pair[0];
            b.addEventListener('click', function () { elBrief.value = pair[1]; elBrief.focus(); });
            elEx.appendChild(b);
        });
    }

    function setMode(next, opts) {
        mode = next;
        plan = null;
        flowId = opts && opts.flowId ? opts.flowId : null;
        elResult.hidden = true;
        elResult.innerHTML = '';
        btnGo.hidden = true;
        elBrief.value = '';

        var revising = mode === 'revise';
        elTitle.textContent = revising ? 'Edit this flow with AI' : 'Build a flow with AI';
        elNote.hidden       = !revising;
        elNameRow.hidden    = revising;   // renaming is a separate action
        elLangRow.hidden    = revising;
        elLabel.innerHTML   = revising
            ? 'What should change? <span class="text-danger">*</span>'
            : 'Describe the flow <span class="text-danger">*</span>';
        elHint.textContent  = revising
            ? 'Plain language is fine — "add option 4 for careers", or "make the greeting shorter".'
            : "Write it the way you'd explain it to a new receptionist. Say what should happen when someone picks nothing or goes quiet.";
        btnGen.innerHTML    = '<i data-lucide="wand" class="w-4 h-4 mr-1 inline"></i> '
                            + (revising ? 'Preview changes' : 'Generate');
        // innerHTML, not textContent — textContent would strip the icon markup
        // before ricons() ever saw it.
        btnGo.innerHTML     = revising
            ? '<i data-lucide="check" class="w-4 h-4 mr-1 inline"></i> Apply changes'
            : '<i data-lucide="arrow-right" class="w-4 h-4 mr-1 inline"></i> Create flow + open editor';

        if (revising && opts) {
            elNoteNm.textContent = opts.name || 'this flow';
            if (opts.language) elLang.value = opts.language;
        }

        renderExamples();
        ricons();
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function block(kind, title, inner) {
        return '<div class="aiv aiv--' + kind + '"><h4>' + esc(title) + '</h4>' + inner + '</div>';
    }

    function list(items) {
        return '<ul>' + items.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
    }

    /* The result panel is the whole point of the feature: it says what was
       built, what was assumed, and — most importantly — what could NOT be
       built and what to do instead. */
    function render(p) {
        var html = '';

        if (p.errors && p.errors.length) {
            html += block('err', "Couldn't build this flow", list(p.errors)
                + '<div style="margin-top:6px;">Try describing it differently, or split it into a simpler flow.</div>');
        }

        if (p.ok) {
            var body = p.summary ? '<div style="margin-bottom:6px;">' + esc(p.summary) + '</div>' : '';
            if (p.steps && p.steps.length) {
                body += '<ol>' + p.steps.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ol>';
            }
            html += block('ok', mode === 'revise' ? 'Updated flow' : 'Flow ready', body);
        }

        if (p.gaps && p.gaps.length) {
            html += block('warn', "What couldn't be done — and what to do instead",
                p.gaps.map(function (g) {
                    return '<div class="aiv__gap"><b>' + esc(g.cannot) + '</b>'
                        + (g.because ? '<span>Why: ' + esc(g.because) + '</span>' : '')
                        + (g.instead ? '<span><b>Instead:</b> ' + esc(g.instead) + '</span>' : '')
                        + '</div>';
                }).join(''));
        }

        if (p.assumptions && p.assumptions.length) {
            html += block('info', 'Assumptions made', list(p.assumptions));
        }

        if (p.warnings && p.warnings.length) {
            html += block('warn', 'Worth checking', list(p.warnings));
        }

        elResult.innerHTML = html || block('info', 'No result', '<div>The AI returned nothing to show.</div>');
        elResult.hidden = false;
        btnGo.hidden = !p.ok;
        ricons();
    }

    function busy(btn, on, label) {
        btn.disabled = on;
        if (on) { btn.dataset.prev = btn.innerHTML; btn.textContent = label; }
        else if (btn.dataset.prev) { btn.innerHTML = btn.dataset.prev; }
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().catch(function () { return {}; })
                .then(function (b) { return { status: r.status, body: b }; });
        });
    }

    btnGen.addEventListener('click', function () {
        var brief = elBrief.value.trim();
        if (brief.length < 10) {
            elResult.innerHTML = block('err', 'Tell us a bit more',
                '<div>Describe the flow in a sentence or two so the AI has something to work from.</div>');
            elResult.hidden = false;
            return;
        }
        if (mode === 'create' && !elName.value.trim()) {
            elName.focus();
            return;
        }

        busy(btnGen, true, 'Thinking… this can take a moment');
        btnGo.hidden = true;
        elResult.hidden = true;

        post(PLAN_URL, {
            project_id: PROJECT,
            brief: brief,
            channel: elChan.value,
            flow_id: mode === 'revise' ? flowId : null
        }).then(function (r) {
            busy(btnGen, false);
            plan = r.body && r.body.definition ? r.body : null;
            render(r.body || { errors: ['No response from the server.'] });
        }).catch(function () {
            busy(btnGen, false);
            render({ errors: ['Could not reach the server. Please try again.'] });
        });
    });

    btnGo.addEventListener('click', function () {
        if (!plan || !plan.definition) return;
        busy(btnGo, true, 'Saving…');

        if (mode === 'revise') {
            // Same endpoint the visual editor saves through — an AI edit is
            // just another way of producing a graph, not a separate path.
            post(SAVE_TPL.replace('FLOWID', flowId), {
                project_id: PROJECT,
                definition: plan.definition
            }).then(function (r) {
                if (r.status === 200 && r.body && r.body.ok) {
                    window.location = EDITOR_TPL.replace('FLOWID', flowId);
                } else {
                    busy(btnGo, false);
                    render({ errors: ['Could not save the changes. Please try again.'] });
                }
            });
            return;
        }

        post(CREATE_URL, {
            project_id: PROJECT,
            name: elName.value.trim(),
            brief: elBrief.value.trim(),
            channel: elChan.value,
            language: elLang.value,
            summary: plan.summary || '',
            definition: plan.definition        // save exactly what was previewed
        }).then(function (r) {
            if (r.status === 200 && r.body && r.body.editor_url) {
                window.location = r.body.editor_url;
            } else {
                busy(btnGo, false);
                render(r.body || { errors: ['Could not create the flow.'] });
            }
        });
    });

    document.addEventListener('click', function (e) {
        var edit = e.target.closest('[data-ai-edit]');
        if (edit) {
            setMode('revise', {
                flowId: edit.dataset.aiEdit,
                name: edit.dataset.aiName,
                language: edit.dataset.aiLanguage
            });
            modal.removeAttribute('hidden');
            setTimeout(function () { elBrief.focus(); }, 50);
            return;
        }
        var open = e.target.closest('[data-tva-modal-open="flow-ai"]');
        if (open) setMode('create');
    });

    setMode('create');
})();
</script>
@endif
@endsection
