@extends('layouts.master')

@section('content')
@include('billing._styles')

{{--
    Build your own plan.

    The price updates as the form changes, but it is ADVISORY: the figure shown
    here comes from a quote endpoint and is recomputed server-side from the
    posted configuration when the plan is built. The form never submits a price.

    Every input is a plain number field rather than a slider. A slider looks
    better and is worse here — these are amounts someone has a figure in mind
    for ("about 800 conversations"), and dragging to a specific number is
    strictly harder than typing it.
--}}

<style>
    .cf-wrap { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:22px; align-items:start; }
    @media (max-width:1000px) { .cf-wrap { grid-template-columns:1fr; } }

    .cf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:16px; }
    .cf-fld label { display:block; font:650 12px system-ui,sans-serif; color:#334155; margin-bottom:5px; }
    .cf-fld input {
        width:100%; padding:10px 12px; border:1px solid #e2e8f0; border-radius:9px;
        font:14px ui-monospace,Menlo,monospace; color:#0f172a; background:#fff;
    }
    .cf-fld input:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12); }
    .cf-fld small { display:block; font-size:11px; color:#94a3b8; margin-top:4px; line-height:1.5; }
    .cf-fld--bad input { border-color:#f3c6c6; }

    .cf-sum {
        position:sticky; top:16px; background:#fff; border:1px solid #e6ecf1;
        border-radius:14px; padding:22px; box-shadow:0 1px 2px rgba(15,23,42,.04);
    }
    .cf-sum__price { font:800 38px/1 ui-monospace,Menlo,monospace; color:#0f172a; letter-spacing:-.03em; }
    .cf-sum__per   { font-size:12.5px; color:#94a3b8; margin-top:6px; }
    .cf-sum__local { font-size:12.5px; color:#6366f1; margin-top:4px; }
    .cf-sum__rows  { margin:18px 0 0; border-top:1px solid #f1f5f9; padding-top:14px; }
    .cf-row { display:flex; justify-content:space-between; gap:12px; font-size:12.5px; padding:5px 0; }
    .cf-row span:first-child { color:#94a3b8; }
    .cf-row span:last-child  { color:#334155; font-family:ui-monospace,Menlo,monospace; }
    .cf-row--total { border-top:1px dashed #eef2f6; margin-top:6px; padding-top:9px; font-weight:650; }
    .cf-note { font-size:11.5px; color:#94a3b8; line-height:1.6; margin-top:14px; }
    .cf-basis {
        display:inline-flex; align-items:center; gap:6px; font:600 10.5px ui-monospace,Menlo,monospace;
        letter-spacing:.05em; text-transform:uppercase; padding:3px 8px; border-radius:5px; margin-bottom:12px;
    }
    .cf-basis--measured  { background:#dcfce7; color:#15803d; }
    .cf-basis--estimated { background:#eef2f6; color:#64748b; }
    .cf-err { font-size:12px; color:#b91c1c; margin-top:10px; line-height:1.5; }
    .cf-busy { opacity:.5; transition:opacity .15s; }

    html.dark .cf-sum { background:#1e293b; border-color:#334155; }
    html.dark .cf-sum__price { color:#f8fafc; }
    html.dark .cf-fld input { background:#0f172a; border-color:#334155; color:#f8fafc; }
    html.dark .cf-fld label { color:#cbd5e1; }
</style>

<div class="intro-y flex flex-wrap items-center gap-3 mt-8 mb-2">
    <h2 class="text-lg font-medium mr-auto">Build your own plan</h2>
    <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Standard plans
    </a>
</div>

<p style="font-size:13.5px;color:#64748b;line-height:1.7;max-width:70ch;margin:0 0 22px;">
    If none of the standard plans is the right shape — a small number of conversations but a
    large team, or the other way round — set out what you actually need and we will price it.
</p>

@include('billing._flash')

@if ($existing)
    <div class="bl-alert bl-alert--info intro-y">
        <i data-lucide="info" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>You already have a custom plan</strong>
            “{{ $existing->name }}” is set up. Changing anything below replaces it.
        </div>
    </div>
@endif

<form method="POST" action="{{ route('billing.custom.store', ['client' => $client->slug]) }}" id="cfForm">
    @csrf
    <div class="cf-wrap intro-y">
        <div class="bl-card">
            <div class="bl-card__head">
                <i data-lucide="sliders-horizontal" class="w-4 h-4" style="color:#6366f1"></i>
                <div class="bl-card__title">What you need</div>
            </div>

            <div class="cf-grid" style="padding:6px 0 2px;">
                @php
                    $fields = [
                        'conversations'            => ['Conversations a month', 'Distinct chats your AI handles.'],
                        'replies_per_conversation' => ['AI replies each', 'Then it hands over to a person.'],
                        'seats'                    => ['Team seats', 'People who can log in and reply.'],
                        'agents'                   => ['AI agents', 'Separate personas, voices and knowledge.'],
                        'phone_numbers'            => ['Phone numbers', 'Leave at 0 for no phone line.'],
                        'phone_minutes'            => ['Call minutes a month', 'Only if you take phone calls.'],
                    ];
                @endphp

                @foreach ($fields as $name => [$label, $hint])
                    <div class="cf-fld @error($name) cf-fld--bad @enderror">
                        <label for="{{ $name }}">{{ $label }}</label>
                        <input type="number" id="{{ $name }}" name="{{ $name }}"
                               value="{{ old($name, $defaults[$name]) }}"
                               min="{{ $bounds[$name][0] }}" max="{{ $bounds[$name][1] }}" required>
                        <small>
                            {{ $hint }}
                            {{ $bounds[$name][0] }}&ndash;{{ number_format($bounds[$name][1]) }}
                        </small>
                        @error($name)<div class="cf-err">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>
        </div>

        <div class="cf-sum" id="cfSum">
            <span class="cf-basis cf-basis--{{ $basis }}">
                <i data-lucide="{{ $basis === 'measured' ? 'activity' : 'calculator' }}" style="width:11px;height:11px"></i>
                {{ $basis === 'measured' ? 'From real usage' : 'Estimated' }}
            </span>

            <div class="cf-sum__price" id="cfPrice">—</div>
            <div class="cf-sum__per">per month · USD</div>
            <div class="cf-sum__local" id="cfLocal"></div>

            <div class="cf-sum__rows">
                <div class="cf-row"><span>AI messages</span><span id="cfMsgs">—</span></div>
                <div class="cf-row"><span>Seats included</span><span id="cfSeats">—</span></div>
                <div class="cf-row"><span>AI agents included</span><span id="cfAgents">—</span></div>
                <div class="cf-row" id="cfExtraRow" hidden><span>Tier minimum applies</span><span id="cfExtra">—</span></div>
                <div class="cf-row cf-row--total"><span>Billed yearly</span><span id="cfAnnual">—</span></div>
            </div>

            <button type="submit" class="bl-btn bl-btn--primary" style="width:100%;margin-top:18px;justify-content:center;">
                Use this plan
            </button>

            <p class="cf-note" id="cfNote">
                Charged in USD. You can change any of this later. Your allowance is measured in
                messages — the conversation figure is just how those divide up — and seats and AI
                agents come bundled with the volume you choose rather than charged separately.
            </p>
        </div>
    </div>
</form>

<script>
(function () {
    const form   = document.getElementById('cfForm');
    const sum    = document.getElementById('cfSum');
    const url    = @json(route('billing.custom.quote', ['client' => $client->slug]));
    const token  = @json(csrf_token());
    const fields = @json(array_keys($fields));

    const el = {
        price: document.getElementById('cfPrice'),
        local: document.getElementById('cfLocal'),
        msgs:  document.getElementById('cfMsgs'),
        seats: document.getElementById('cfSeats'),
        agents:document.getElementById('cfAgents'),
        extra: document.getElementById('cfExtra'),
        extraRow: document.getElementById('cfExtraRow'),
        annual: document.getElementById('cfAnnual'),
    };

    let timer = null;
    let inFlight = null;

    function markOver(field, wanted, allowed) {
        const wrap = document.getElementById(field).closest('.cf-fld');
        if (!wrap) return;
        wrap.classList.toggle('cf-fld--bad', wanted > allowed);
        let note = wrap.querySelector('.cf-over');
        if (wanted > allowed) {
            if (!note) {
                note = document.createElement('div');
                note.className = 'cf-err cf-over';
                wrap.appendChild(note);
            }
            note.textContent = 'This volume includes up to ' + allowed + '. Raise conversations for more.';
        } else if (note) {
            note.remove();
        }
    }

    function payload() {
        const out = {};
        fields.forEach(f => { out[f] = document.getElementById(f).value; });
        return out;
    }

    async function refresh() {
        // Abort the previous request rather than racing it. Typing "1500" fires
        // four times, and without this the answer shown is whichever reply
        // happens to land last — which is not necessarily the last question.
        if (inFlight) inFlight.abort();
        inFlight = new AbortController();

        sum.classList.add('cf-busy');

        try {
            const res = await fetch(url, {
                method: 'POST',
                signal: inFlight.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload()),
            });

            if (!res.ok) { sum.classList.remove('cf-busy'); return; }

            const data = await res.json();
            const q = data.quote;

            el.price.textContent  = data.formatted.usd;
            el.local.textContent  = data.formatted.local ? '≈ ' + data.formatted.local : '';
            el.msgs.textContent   = q.messages.toLocaleString();
            el.seats.textContent  = q.included_seats.toLocaleString();
            el.agents.textContent = q.included_agents.toLocaleString();
            el.annual.textContent = data.annual.usd;

            // Seats and agents are bundled with volume, so the summary shows what
            // the volume ALLOWS rather than a line item. The tier minimum is
            // surfaced when it is what set the price, so the number is
            // explainable rather than looking rounded up for no reason.
            const flooredByTier = q.breakdown.tier_floor > 0
                && q.breakdown.usage < q.breakdown.tier_floor;
            el.extraRow.hidden = !flooredByTier;
            el.extra.textContent = '$' + q.breakdown.tier_floor.toFixed(0);

            // Flag a team larger than the volume allows: the server will refuse
            // it and the reason is not obvious from the fields alone.
            markOver('seats', q.seats, q.included_seats);
            markOver('agents', q.agents, q.included_agents);

            sum.classList.remove('cf-busy');
        } catch (e) {
            if (e.name !== 'AbortError') sum.classList.remove('cf-busy');
        }
    }

    // Debounced, so holding a spinner or typing a four-digit number is one
    // request rather than one per digit.
    form.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(refresh, 280);
    });

    refresh();
})();
</script>
@endsection
