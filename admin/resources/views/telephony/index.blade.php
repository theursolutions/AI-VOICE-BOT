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

    /* ── Setup guide ── */
    .tva-guide {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px;
        margin-bottom:18px; overflow:hidden;
    }
    .tva-guide__summary {
        display:flex; align-items:center; gap:14px; padding:18px 22px;
        cursor:pointer; list-style:none;
    }
    .tva-guide__summary::-webkit-details-marker { display:none; }
    .tva-guide__icon { font-size:22px; }
    .tva-guide__title { display:block; font-size:15px; font-weight:700; color:#0f172a; }
    .tva-guide__sub { display:block; font-size:12.5px; color:#64748b; margin-top:2px; }
    .tva-guide__chev { color:#94a3b8; font-size:14px; transition:transform .2s; }
    .tva-guide[open] .tva-guide__chev { transform:rotate(180deg); }

    .tva-steps { list-style:none; margin:0; padding:0 22px 20px; }
    .tva-step { display:flex; gap:14px; padding:16px 0; border-top:1px solid #eef0f3; }
    .tva-step__n {
        flex:0 0 28px; height:28px; border-radius:50%;
        background:var(--tva-gradient,#3b82f6); color:#fff;
        display:flex; align-items:center; justify-content:center;
        font-size:13px; font-weight:700;
    }
    .tva-step__body { flex:1; min-width:0; }
    .tva-step__title { font-size:14px; font-weight:700; color:#0f172a; margin-bottom:5px; }
    .tva-step__body p { font-size:13px; color:#475569; line-height:1.6; margin:0 0 10px; }
    .tva-step__note { font-size:12px; color:#64748b; margin-top:9px !important; }
    .tva-step__note--warn {
        background:#fffaeb; border:1px solid #fedf89; color:#92400e;
        border-radius:8px; padding:9px 12px;
    }
    .tva-step__map { list-style:none; margin:0 0 10px; padding:0; }
    .tva-step__map li {
        font-size:12.5px; color:#475569; padding:5px 0;
        border-bottom:1px dashed #eef0f3;
    }
    .tva-step__map li:last-child { border-bottom:none; }
    .tva-step__map span {
        display:inline-block; min-width:150px; font-weight:600; color:#0f172a;
    }
    .tva-step code {
        background:#f1f5f9; padding:1px 6px; border-radius:5px;
        font-size:11.5px; color:#0f172a;
    }

    /* ── Per-project Twilio credentials ── */
    .tva-creds {
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
        padding:12px 14px; margin-bottom:12px;
    }
    .tva-creds.is-connected { background:#f0fdf4; border-color:#bbf7d0; }
    .tva-creds__row { display:flex; align-items:center; gap:12px; }
    .tva-creds__dot {
        width:9px; height:9px; border-radius:50%; background:#16a34a; flex-shrink:0;
        box-shadow:0 0 0 3px rgba(22,163,74,.15);
    }
    .tva-creds__dot.is-off { background:#94a3b8; box-shadow:0 0 0 3px rgba(148,163,184,.15); }
    .tva-creds__title { font-size:13px; font-weight:700; color:#0f172a; }
    .tva-creds__meta { font-size:11.5px; color:#64748b; margin-top:2px; }
    .tva-creds__meta code { background:rgba(15,23,42,.06); padding:1px 5px; border-radius:4px; font-size:11px; }
    .tva-creds__warn {
        margin-top:10px; font-size:12px; background:#fffaeb; border:1px solid #fedf89;
        color:#92400e; border-radius:8px; padding:9px 12px;
    }

    html.dark .tva-creds { background:#0f172a; border-color:#334155; }
    html.dark .tva-creds.is-connected { background:#052e16; border-color:#166534; }
    html.dark .tva-creds__title { color:#f1f5f9; }
    html.dark .tva-creds__meta { color:#94a3b8; }
    html.dark .tva-creds__meta code { background:rgba(255,255,255,.08); }
    html.dark .tva-creds__warn { background:#3b2503; border-color:#92400e; color:#fde68a; }

    html.dark .tva-guide { background:#1e293b; border-color:#334155; }
    html.dark .tva-guide__title, html.dark .tva-step__title { color:#f1f5f9; }
    html.dark .tva-step { border-top-color:#334155; }
    html.dark .tva-step__body p, html.dark .tva-step__map li { color:#cbd5e1; }
    html.dark .tva-step__map span { color:#f1f5f9; }
    html.dark .tva-step__map li { border-bottom-color:#334155; }
    html.dark .tva-step code { background:#0f172a; color:#f1f5f9; }
    html.dark .tva-step__note--warn { background:#3b2503; border-color:#92400e; color:#fde68a; }

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

    {{-- ── Setup guide ──────────────────────────────────────────────────
         Everything a customer has to do, in the order they have to do it,
         with a link straight to the right Twilio screen at each step.
         Collapsed once they have a number so it stops taking up the page. --}}
    @php
        $tvaHasNumber = collect($projects)->contains(
            fn ($p) => count((array) data_get($p->json_data, 'telephony.numbers', [])) > 0
        );
    @endphp
    <details class="tva-guide" {{ $tvaHasNumber ? '' : 'open' }}>
        <summary class="tva-guide__summary">
            <span class="tva-guide__icon">🚀</span>
            <span class="flex-1">
                <span class="tva-guide__title">How to get a phone number</span>
                <span class="tva-guide__sub">Five steps, about ten minutes. You'll need a card for Twilio.</span>
            </span>
            <span class="tva-guide__chev">▾</span>
        </summary>

        <ol class="tva-steps">
            <li class="tva-step">
                <span class="tva-step__n">1</span>
                <div class="tva-step__body">
                    <div class="tva-step__title">Create a Twilio account</div>
                    <p>Twilio is the phone network behind your calls. The number is bought and billed there,
                       in your own name — we never see your card.</p>
                    <a href="https://www.twilio.com/try-twilio" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        Sign up at Twilio ↗
                    </a>
                    <p class="tva-step__note">Already have one? <a href="https://console.twilio.com/" target="_blank" rel="noopener">Log in</a> and skip to step 2.</p>
                </div>
            </li>

            <li class="tva-step">
                <span class="tva-step__n">2</span>
                <div class="tva-step__body">
                    <div class="tva-step__title">Buy a phone number</div>
                    <p>Choose your country, tick <b>Voice</b> under Capabilities, and buy. A local number is
                       typically about $1–2 per month.</p>
                    <a href="https://console.twilio.com/us1/develop/phone-numbers/manage/search"
                       target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                        <i data-lucide="shopping-cart" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> Buy a number on Twilio ↗
                    </a>
                    <p class="tva-step__note">
                        <b>Trial accounts can only call numbers you've verified</b> with Twilio. Upgrade before
                        going live, or your agent will only reach your own phone.
                    </p>
                </div>
            </li>

            <li class="tva-step">
                <span class="tva-step__n">3</span>
                <div class="tva-step__body">
                    <div class="tva-step__title">Point the number at us</div>
                    <p>In Twilio, open the number you just bought and scroll to <b>Voice Configuration</b>.
                       Copy the two URLs below into these fields — both set to <b>HTTP POST</b>:</p>
                    <ul class="tva-step__map">
                        <li><span>A call comes in</span> → the <b>voice</b> URL below</li>
                        <li><span>Call status changes</span> → the <b>status</b> URL below</li>
                    </ul>
                    <a href="https://console.twilio.com/us1/develop/phone-numbers/manage/incoming"
                       target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        Open my Twilio numbers ↗
                    </a>
                    <p class="tva-step__note">Save at the bottom of the Twilio page, or the change won't stick.</p>
                </div>
            </li>

            <li class="tva-step">
                <span class="tva-step__n">4</span>
                <div class="tva-step__body">
                    <div class="tva-step__title">Send us your Twilio keys</div>
                    <p>On the Twilio console home page, find <b>Account Info</b> at the bottom. You need two values:</p>
                    <ul class="tva-step__map">
                        <li><span>Account SID</span> → starts with <code>AC</code>, 34 characters</li>
                        <li><span>Auth Token</span> → hidden until you press <b>Show</b></li>
                    </ul>
                    <a href="https://console.twilio.com/" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        Open Twilio Account Info ↗
                    </a>
                    <p class="tva-step__note">
                        Paste both into the <b>Twilio account</b> box on your project below. We check them with
                        Twilio straight away, so you'll know immediately if something was mistyped.
                    </p>
                    <p class="tva-step__note tva-step__note--warn">
                        Your Auth Token can spend money and read your call recordings — treat it like a password.
                        We encrypt it before storing, never show it again, and never put it in a log.
                        Don't paste it into email or chat.
                    </p>
                </div>
            </li>

            <li class="tva-step">
                <span class="tva-step__n">5</span>
                <div class="tva-step__body">
                    <div class="tva-step__title">Add the number here</div>
                    <p>Press <b>Add number</b> on your project below, paste the number in
                       <code>+</code>country-code format (e.g. <code>+14155551234</code>), and choose which
                       agent, skill or flow answers it.</p>
                    <p class="tva-step__note">Call the number to test. If it rings and then hangs up, re-check step 3.</p>
                </div>
            </li>
        </ol>
    </details>

    {{-- Webhook URLs --}}
    <div class="tva-tel-card">
        <div class="tva-tel-card__head">
            <div style="width:36px; height:36px; border-radius:10px; background:#fff5d1; color:#92400e; display:flex; align-items:center; justify-content:center;">
                <i data-lucide="link-2" class="w-4 h-4"></i>
            </div>
            <div>
                <div class="tva-tel-card__title">Webhook URLs for Twilio Console</div>
                <div class="tva-tel-card__subtitle">Step 3 above. Paste into the "A CALL COMES IN" and "CALL STATUS CHANGES" fields for each Twilio number. Same URLs for all numbers — routing is by phone number.</div>
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

            {{-- Twilio account for THIS project. Each customer brings their
                 own — the credentials in .env belong to the Serve AI demo. --}}
            @php $tw = $twilio[$project->id] ?? null; @endphp
            <div class="tva-creds {{ $tw ? 'is-connected' : '' }}">
                @if ($tw)
                    <div class="tva-creds__row">
                        <span class="tva-creds__dot"></span>
                        <div class="flex-1 min-w-0">
                            <div class="tva-creds__title">
                                Twilio account connected{{ $tw['friendly_name'] ? ' — ' . $tw['friendly_name'] : '' }}
                            </div>
                            <div class="tva-creds__meta">
                                <code>{{ $tw['account_sid'] }}</code>
                                · token ends <code>…{{ $tw['token_hint'] }}</code>
                                @if ($tw['verified_at']) · checked {{ $tw['verified_at'] }} @endif
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" data-tva-modal-open="creds-{{ $project->id }}">
                            Replace
                        </button>
                        <form method="POST" action="{{ route('telephony.delete-credentials', ['client' => $client->slug]) }}"
                              class="inline" data-confirm="Remove the Twilio credentials for {{ $project->name }}? Calls to its numbers will stop working until you add them again.">
                            @csrf
                            <input type="hidden" name="project_id" value="{{ $project->id }}">
                            <button type="submit" class="btn btn-secondary btn-sm">Remove</button>
                        </form>
                    </div>
                    @if ($tw['is_trial'])
                        <div class="tva-creds__warn">
                            <b>This is a Twilio Trial account.</b> It can only call numbers you've verified in the
                            Twilio console. Upgrade it before going live, or your agent will only reach your own phone.
                        </div>
                    @endif
                @else
                    <div class="tva-creds__row">
                        <span class="tva-creds__dot is-off"></span>
                        <div class="flex-1">
                            <div class="tva-creds__title">No Twilio account connected</div>
                            <div class="tva-creds__meta">
                                Calls to this project's numbers can't be accepted until you add its
                                Account SID and Auth Token — see step 4 in the guide above.
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm" data-tva-modal-open="creds-{{ $project->id }}">
                            <i data-lucide="key" class="w-3 h-3 mr-1 inline"></i> Add Twilio keys
                        </button>
                    </div>
                @endif
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
                        <form method="POST" action="{{ route('telephony.delete-number', ['client' => $client->slug]) }}" data-confirm="Remove this number?" class="inline">
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

            {{-- Twilio credentials modal --}}
            <div id="creds-{{ $project->id }}" class="tva-modal" hidden>
                <div class="tva-modal__backdrop" data-tva-modal-close></div>
                <form method="POST" action="{{ route('telephony.save-credentials', ['client' => $client->slug]) }}"
                      class="tva-modal__panel" style="max-width:560px;" autocomplete="off">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $project->id }}">
                    <div class="tva-modal__head">
                        <i data-lucide="key" class="w-4 h-4 mr-2 inline" style="color:#6366f1;"></i>
                        Twilio account for {{ $project->name }}
                        <button type="button" data-tva-modal-close class="ml-auto"><i data-lucide="x" class="w-4 h-4"></i></button>
                    </div>
                    <div class="tva-modal__body">
                        <p class="text-sm text-slate-500 mb-3">
                            From the Twilio console home page, under <b>Account Info</b>.
                            <a href="https://console.twilio.com/" target="_blank" rel="noopener">Open it ↗</a>
                        </p>
                        <div class="mb-3">
                            <label class="form-label">Account SID <span class="text-danger">*</span></label>
                            <input type="text" name="account_sid" class="form-control" required
                                   maxlength="64" spellcheck="false" autocomplete="off"
                                   placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   value="{{ old('account_sid', $twilio[$project->id]['account_sid'] ?? '') }}">
                            <small class="text-slate-500 text-xs">Starts with <code>AC</code>, 34 characters.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Auth Token <span class="text-danger">*</span></label>
                            {{-- type=password so it isn't shoulder-surfed or
                                 captured by a screen recording, and never
                                 pre-filled: we can't show a stored token back. --}}
                            <input type="password" name="auth_token" class="form-control" required
                                   maxlength="64" spellcheck="false" autocomplete="new-password"
                                   placeholder="{{ isset($twilio[$project->id]) ? 'Enter again to replace' : '32 characters' }}">
                            <small class="text-slate-500 text-xs">
                                Hidden in Twilio until you press <b>Show</b>. We encrypt it before storing and never display it again.
                            </small>
                        </div>
                        <div class="text-xs" style="background:#fffaeb;border:1px solid #fedf89;color:#92400e;border-radius:8px;padding:9px 12px;">
                            We'll check these with Twilio when you save, so a mistyped value is caught now
                            rather than on your first real call.
                        </div>
                    </div>
                    <div class="tva-modal__foot">
                        <button type="button" class="btn btn-secondary" data-tva-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary">Save &amp; verify</button>
                    </div>
                </form>
            </div>
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
