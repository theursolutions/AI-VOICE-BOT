@extends('layouts.master')

@section('content')
@php
    // The currency an ADD-ON will be charged in — which is not necessarily the
    // currency the existing subscription is in. A workspace that bought a plan
    // in rupees and has since moved country is quoted new purchases in dollars
    // while still holding a rupee subscription; both figures appear on this
    // page and each must say what it actually is.
    $addonCurrency = app(\App\Services\Billing\Gateways\GatewayRegistry::class)->currencyFor($client);
    $addonSymbol   = app(\App\Services\Currency\ExchangeRateService::class)->symbolFor($addonCurrency);
    $addonDecimals = (int) (config("billing.currencies.{$addonCurrency}.decimals") ?? 2);

    // What the SUBSCRIPTION is denominated in. Its own currency, never the
    // page's — rendering Rs 22,500 as "$22,500.00" was exactly this mistake.
    $planCurrency = strtoupper((string) ($subscription->currency ?: $addonCurrency));

    $money    = fn (int $cents) => tva_money($cents, $addonCurrency, false);
    $per      = $subscription->interval === 'annually' ? 'year' : 'month';
    $perShort = $subscription->interval === 'annually' ? 'yr' : 'mo';
@endphp

@include('billing._styles')

<style>
    .ad-billed {
        display:flex; gap:10px; align-items:flex-start; margin:0 0 18px;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;
        padding:12px 15px; font-size:12.5px; color:#475569; line-height:1.6;
    }
    .ad-billed strong { color:#0f172a; font-weight:750; }
    .ad-billed a { color:#4f46e5; font-weight:650; text-decoration:none; }
    .ad-billed a:hover { text-decoration:underline; }

    html.dark .ad-billed { background:#0f172a; border-color:#334155; color:#94a3b8; }
    html.dark .ad-billed strong { color:#e2e8f0; }
</style>

<style>
    /* 32px top matches the mt-8 every other billing page opens with — without
       it the heading sits flush against the top chrome. */
    .ad-wrap { max-width:1040px; margin:32px auto 0; }

    .ad-head { margin-bottom:24px; }
    .ad-head h1 { font-size:26px; font-weight:800; letter-spacing:-.025em; color:#0b1220; margin:0 0 7px; }
    .ad-head p  { font-size:14px; color:#667085; margin:0; line-height:1.65; max-width:640px; }
    .ad-chip {
        display:inline-flex; align-items:center; gap:7px; margin-bottom:14px; padding:6px 13px;
        border-radius:999px; background:#eef2ff; border:1px solid #c7d2fe;
        font-size:12.5px; font-weight:650; color:#4338ca;
    }

    /* Interval switch.
       Both intervals are shown because both exist and both are real prices, but
       only the one matching the plan can be BOUGHT: Stripe requires every line
       on a subscription to share one billing interval. So this compares rather
       than selects, and says so. */
    .ad-seg { display:inline-flex; padding:4px; background:#f2f4f7; border:1px solid #e6eaf2; border-radius:999px; margin-bottom:6px; }
    .ad-seg button {
        border:0; background:transparent; cursor:pointer; border-radius:999px;
        padding:7px 16px; font:650 12.5px system-ui,sans-serif; color:#667085;
    }
    .ad-seg button.is-on { background:#fff; color:#0b1220; box-shadow:0 1px 3px rgba(16,24,40,.14); }
    .ad-seg button:disabled { cursor:not-allowed; opacity:.55; }
    .ad-seg__note { font-size:11.5px; color:#98a2b3; margin:0 0 18px; line-height:1.55; }
    .ad-alt { font-size:11.5px; color:#98a2b3; margin-top:5px; }
    .ad-alt b { color:#475467; font-weight:650; }

    .ad-grid { display:grid; grid-template-columns:1fr 330px; gap:22px; align-items:start; }
    @media (max-width:900px) { .ad-grid { grid-template-columns:1fr; } }

    /* ── Add-on rows ─────────────────────────────────────────── */
    .ad-card {
        border:1px solid #e7e9f0; border-radius:15px; background:#fff;
        padding:20px 22px; margin-bottom:14px;
        display:flex; gap:18px; align-items:center; flex-wrap:wrap;
    }
    .ad-card__body { flex:1; min-width:200px; }
    .ad-name { font-size:15.5px; font-weight:750; color:#0b1220; letter-spacing:-.01em; }
    .ad-desc { font-size:12.5px; color:#667085; line-height:1.55; margin-top:4px; }
    .ad-unit { font-size:12.5px; color:#0b1220; font-weight:650; margin-top:8px; }
    .ad-unit span { color:#8b93a7; font-weight:500; }

    .ad-owned {
        display:inline-block; margin-left:7px; padding:2px 9px; border-radius:999px;
        background:#eef2ff; color:#4338ca; font-size:11.5px; font-weight:700;
    }

    /* Stepper */
    .ad-step { display:flex; align-items:center; gap:0; border:1px solid #e2e8f0; border-radius:11px; overflow:hidden; }
    .ad-step button {
        width:38px; height:40px; border:0; background:#f8fafc; cursor:pointer;
        font-size:17px; font-weight:700; color:#475467; line-height:1;
    }
    .ad-step button:hover { background:#eef2ff; color:#4338ca; }
    .ad-step button:disabled { opacity:.4; cursor:not-allowed; }
    .ad-step input {
        width:58px; height:40px; border:0; border-left:1px solid #e2e8f0; border-right:1px solid #e2e8f0;
        text-align:center; font-size:14.5px; font-weight:700; color:#0b1220; background:#fff;
        -moz-appearance:textfield;
    }
    .ad-step input::-webkit-outer-spin-button,
    .ad-step input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }

    .ad-line { min-width:92px; text-align:right; font-size:14px; font-weight:750; color:#0b1220; font-variant-numeric:tabular-nums; }
    .ad-line small { display:block; font-weight:500; color:#98a2b3; font-size:11px; margin-top:2px; }

    /* ── Summary ─────────────────────────────────────────────── */
    .ad-sum {
        border:1px solid #e7e9f0; border-radius:15px; background:#fff;
        padding:20px 20px 22px; position:sticky; top:18px;
    }
    .ad-sum h2 { font-size:14px; font-weight:800; color:#0b1220; margin:0 0 14px; letter-spacing:-.01em; }
    .ad-sum__row { display:flex; justify-content:space-between; gap:12px; font-size:13px; color:#475467; padding:7px 0; }
    .ad-sum__row b { color:#0b1220; font-weight:700; font-variant-numeric:tabular-nums; }
    .ad-sum__row--muted { color:#98a2b3; font-size:12px; }
    .ad-sum__rule { height:1px; background:#eef0f5; margin:10px 0; }
    .ad-sum__due { display:flex; justify-content:space-between; align-items:baseline; gap:12px; margin-top:4px; }
    .ad-sum__due span { font-size:13.5px; font-weight:750; color:#0b1220; }
    .ad-sum__due b { font-size:22px; font-weight:800; color:#0b1220; letter-spacing:-.02em; font-variant-numeric:tabular-nums; }
    .ad-sum__note { font-size:11.5px; color:#98a2b3; line-height:1.6; margin:10px 0 0; }

    .ad-pm {
        display:flex; align-items:center; gap:10px; margin-top:14px; padding:11px 13px;
        border:1px solid #e7e9f0; border-radius:11px; background:#fbfbfe; font-size:12.5px; color:#475467;
    }
    .ad-pm b { color:#0b1220; font-weight:700; }

    .ad-empty { font-size:13px; color:#98a2b3; padding:26px; text-align:center; border:1px dashed #e2e8f0; border-radius:14px; }

    html.dark .ad-head h1 { color:#f8fafc; }
    html.dark .ad-card, html.dark .ad-sum { background:#1e293b; border-color:#334155; }
    html.dark .ad-name, html.dark .ad-unit, html.dark .ad-line, html.dark .ad-sum h2,
    html.dark .ad-sum__row b, html.dark .ad-sum__due b, html.dark .ad-sum__due span { color:#f8fafc; }
    html.dark .ad-step { border-color:#334155; }
    html.dark .ad-step button { background:#0f172a; color:#94a3b8; }
    html.dark .ad-step input { background:#1e293b; color:#f8fafc; border-color:#334155; }
    html.dark .ad-pm { background:#0f172a; border-color:#334155; }
</style>

<div class="ad-wrap">

    <div class="ad-head intro-y">
        <div class="ad-chip">
            <i data-lucide="check-circle-2" class="w-3.5 h-3.5"></i>
            You’re on {{ $plan?->name ?? 'a plan' }} · billed {{ $per }}ly
        </div>
        <h1>Add extra capacity</h1>
        <p>
            Top up what your plan includes without changing plan. Add-ons are billed on your
            existing invoice, renew with your subscription, and can be removed at any time.
        </p>
    </div>

    {{--
        Why there is no country or payment choice on this page, said rather than
        left as a puzzle. An add-on rides on the plan you already have and is
        re-charged with it, so it has to use the same currency and the same
        provider — a dollar seat on a rupee subscription would make the renewal
        that adds them together wrong by the exchange rate.

        Changing it is possible; it just happens where it can actually take
        effect, which is on the plan.
    --}}
    @if (! empty($billedIn))
        <div class="ad-billed intro-y">
            <i data-lucide="info" class="w-4 h-4" style="flex:none;color:#6366f1"></i>
            <div>
                Billed in <strong>{{ $billedIn }}</strong>, the same as your plan, and
                {{ $billedVia }}.
                <a href="{{ route('billing.plans', ['client' => $client->slug]) }}">
                    Change country or payment method
                </a>
                — it applies from your next plan change.
            </div>
        </div>
    @endif

    @include('billing._flash')

    {{-- The page renders for anyone on a paid plan, but completing the purchase
         needs a live Stripe subscription for the add-on to be a line on. Saying
         which of those is missing, here, beats the old behaviour of redirecting
         to the plan ladder and telling a paying customer to choose a plan. --}}
    @if (! ($canBuy ?? true))
        <div class="bl-alert bl-alert--info" style="margin-bottom:18px">
            <i data-lucide="info" class="w-5 h-5" style="flex:none"></i>
            <div>
                <strong>Add-ons aren’t available yet</strong>
                {{ $blockedWhy }}
                @if (! empty($blockedAction))
                    <div style="margin-top:9px">
                        <a href="{{ $blockedAction[0] }}" class="bl-btn bl-btn--primary bl-btn--sm">
                            {{ $blockedAction[1] }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @php
        // Which intervals any add-on is actually sold on.
        $adIntervals = collect($addons)->flatMap(fn ($i) => array_keys($i['prices'] ?? []))->unique()->values();
        $adLabels    = ['monthly' => 'Monthly', 'annually' => 'Annual'];
    @endphp

    @if ($adIntervals->count() > 1)
        <div class="ad-seg" role="group" aria-label="Billing interval">
            @foreach ($adIntervals as $iv)
                <button type="button" class="js-ad-iv {{ $iv === $subscription->interval ? 'is-on' : '' }}"
                        data-iv="{{ $iv }}">
                    {{ $adLabels[$iv] ?? ucfirst($iv) }}
                </button>
            @endforeach
        </div>
        <p class="ad-seg__note">
            Your plan is billed {{ $per }}ly, so add-ons are too — every line on one subscription has
            to share a billing interval. Switch here to compare;
            <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" style="color:#6366f1;font-weight:650">
                change your plan's interval</a>
            to buy on the other one.
        </p>
    @endif

    <div class="ad-grid">

        {{-- ── Choose quantities ──────────────────────────────── --}}
        <div>
            @forelse ($addons as $item)
                @php
                    $addonPlan = $item['plan'];
                    $unit      = (int) $item['price']->unit_amount;
                @endphp

                <form method="POST" action="{{ route('billing.addons.update', ['client' => $client->slug]) }}"
                      class="ad-card intro-y js-addon"
                      data-slug="{{ $addonPlan->slug }}"
                      data-unit="{{ $unit }}"
                      data-owned="{{ (int) $item['owned'] }}">
                    @csrf
                    <input type="hidden" name="addon" value="{{ $addonPlan->slug }}">

                    <div class="ad-card__body">
                        <div class="ad-name">
                            {{ $addonPlan->name }}
                            @if ($item['owned'] > 0)
                                <span class="ad-owned">{{ $item['owned'] }} active</span>
                            @endif
                        </div>
                        @if ($addonPlan->tagline)
                            <div class="ad-desc">{{ $addonPlan->tagline }}</div>
                        @endif
                        <div class="ad-unit">
                            {{ $money($unit) }} <span>per unit, per {{ $per }}</span>
                        </div>
                        {{-- The other interval's figure, so the comparison is on
                             the card rather than only in the toggle. --}}
                        @foreach (($item['prices'] ?? []) as $iv => $ivPrice)
                            @continue ($iv === $subscription->interval)
                            <div class="ad-alt js-ad-alt" data-iv="{{ $iv }}">
                                <b>{{ $money($ivPrice->unit_amount) }}</b>
                                on {{ $adLabels[$iv] ?? $iv }} billing
                            </div>
                        @endforeach
                        <div class="ad-desc" style="margin-top:6px;color:#98a2b3;font-size:11.5px">
                            Billed {{ $per }}ly, on the same invoice as your plan.
                        </div>
                    </div>

                    <div class="ad-step">
                        <button type="button" class="js-dec" aria-label="Remove one">−</button>
                        <input type="number" name="quantity" min="0" max="999"
                               value="{{ (int) $item['owned'] }}" aria-label="Quantity">
                        <button type="button" class="js-inc" aria-label="Add one">+</button>
                    </div>

                    <div class="ad-line js-line">
                        {{ $money($unit * (int) $item['owned']) }}
                        <small>per {{ $perShort }}</small>
                    </div>

                    @if ($checkoutOpen && ($canBuy ?? true))
                        {{-- "Pay", not "Save". A top-up is a purchase that
                             happens now — the old wording promised a settings
                             change and then took money, which is the wrong way
                             round to surprise somebody.

                             Starts disabled and is enabled by the stepper once
                             the quantity differs from what they hold, so it is
                             never offered for a no-op. The amount is filled in
                             by the same script that quotes the proration. --}}
                        <button type="submit" class="bl-btn bl-btn--primary bl-btn--sm js-submit" disabled>
                            <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                            <span class="js-submit-label">Pay</span>
                        </button>
                    @elseif (! ($canBuy ?? true))
                        <span style="font-size:12px;color:#98a2b3">Unlocks when active</span>
                    @else
                        <span style="font-size:12px;color:#98a2b3">Available soon</span>
                    @endif
                </form>
            @empty
                <div class="ad-empty">No add-ons are available for your billing interval.</div>
            @endforelse
        </div>

        {{-- ── Summary ────────────────────────────────────────── --}}
        <div class="ad-sum intro-y">
            <h2>Summary</h2>

            <div class="ad-sum__row">
                <span>{{ $plan?->name ?? 'Plan' }}</span>
                <b>{{ tva_money((int) ($subscription->unit_amount ?? 0), $planCurrency, false) }}<span style="font-weight:500;color:#98a2b3">/{{ $perShort }}</span></b>
            </div>

            <div class="ad-sum__row">
                <span>Add-ons currently active</span>
                <b>{{ $money($addonTotal) }}<span style="font-weight:500;color:#98a2b3">/{{ $perShort }}</span></b>
            </div>

            <div class="ad-sum__rule"></div>

            {{-- Filled in by the live preview once a quantity is changed. The
                 prorated figure comes from Stripe, never from arithmetic here:
                 unused-time credits, discounts and tax all move it. --}}
            <div id="ad-preview" hidden>
                <div class="ad-sum__row"><span id="ad-change-label">Change</span><b id="ad-change"></b></div>
                <div class="ad-sum__due">
                    <span>Due today</span>
                    <b id="ad-due">—</b>
                </div>
                <p class="ad-sum__note" id="ad-note">
                    Prorated for the rest of your current billing period.
                </p>
            </div>

            <div id="ad-idle" class="ad-sum__row ad-sum__row--muted">
                Choose a quantity to see what it costs.
            </div>

            @if ($defaultCard)
                <div class="ad-pm">
                    <i data-lucide="credit-card" class="w-4 h-4" style="color:#6366f1"></i>
                    <div>
                        Charged to <b>{{ ucfirst($defaultCard['brand'] ?? 'card') }} ····&nbsp;{{ $defaultCard['last4'] ?? '' }}</b><br>
                        <a href="{{ route('billing.index', ['client' => $client->slug]) }}" style="color:#6366f1;font-weight:600">Change card</a>
                    </div>
                </div>
            @else
                <div class="ad-pm">
                    <i data-lucide="alert-circle" class="w-4 h-4" style="color:#f59e0b"></i>
                    <div>
                        No saved card.
                        <a href="{{ route('billing.index', ['client' => $client->slug]) }}" style="color:#6366f1;font-weight:600">Add one</a>
                        before buying add-ons.
                    </div>
                </div>
            @endif

            <p class="ad-sum__note">
                Set a quantity to 0 to remove an add-on — your next invoice is credited for the
                unused part.
            </p>
        </div>
    </div>

    <p style="text-align:center;margin-top:26px">
        <a href="{{ route('billing.index', ['client' => $client->slug]) }}"
           style="font-size:13px;color:#667085;text-decoration:none">← Back to billing</a>
    </p>
</div>

<script>
(function () {
    var previewUrl = @json(route('billing.addons.preview', ['client' => $client->slug]));
    var token      = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    var box       = document.getElementById('ad-preview');
    var idle      = document.getElementById('ad-idle');
    var dueEl     = document.getElementById('ad-due');
    var changeEl  = document.getElementById('ad-change');
    var labelEl   = document.getElementById('ad-change-label');
    var noteEl    = document.getElementById('ad-note');

    // Same currency the server rendered with, handed over rather than assumed —
    // the two must agree or the page contradicts itself the moment a quantity
    // changes.
    var CURRENCY = { symbol: @json($addonSymbol), decimals: @json($addonDecimals) };

    function money(cents) {
        var sign = cents < 0 ? '−' : '';
        return sign + CURRENCY.symbol + (Math.abs(cents) / 100).toFixed(CURRENCY.decimals);
    }

    var timer = null;

    /* Interval switch.
       COMPARISON ONLY — it swaps which figure is emphasised and never touches
       the quantity, the line total or the submit state. Stripe requires every
       line on a subscription to share one billing interval, so pretending the
       other one is purchasable here would produce a form that fails at the API
       with an error the customer cannot act on. The plan page is where the
       interval actually changes, and the note above links to it. */
    (function () {
        var buttons = document.querySelectorAll('.js-ad-iv');
        if (buttons.length < 2) return;

        var planIv = @json($subscription->interval);

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var iv = btn.dataset.iv;

                buttons.forEach(function (b) { b.classList.toggle('is-on', b === btn); });

                // On the plan's own interval the alternates are hints; on the
                // other one the alternate for THAT interval becomes the headline
                // and the plan's own figure is the hint.
                document.querySelectorAll('.js-addon').forEach(function (card) {
                    card.querySelectorAll('.js-ad-alt').forEach(function (alt) {
                        alt.style.fontWeight = alt.dataset.iv === iv ? '650' : '';
                        alt.style.color      = alt.dataset.iv === iv ? '#0b1220' : '';
                    });
                    var unit = card.querySelector('.ad-unit');
                    if (unit) unit.style.opacity = (iv === planIv) ? '' : '.5';
                });
            });
        });
    })();

    document.querySelectorAll('.js-addon').forEach(function (form) {
        var input  = form.querySelector('input[name="quantity"]');
        var line   = form.querySelector('.js-line');
        var submit = form.querySelector('.js-submit');
        var payLbl = form.querySelector('.js-submit-label');

        // The button says what it will take. "Pay" alone is a button somebody
        // presses to find out.
        function setPayLabel(cents) {
            if (!payLbl) return;
            payLbl.textContent = (cents === null || typeof cents === 'undefined')
                ? 'Pay'
                : 'Pay ' + money(cents);
        }
        var unit   = parseInt(form.dataset.unit, 10);
        var owned  = parseInt(form.dataset.owned, 10);

        function clamp(v) { return Math.max(0, Math.min(999, isNaN(v) ? 0 : v)); }

        function sync() {
            var qty = clamp(parseInt(input.value, 10));
            input.value = qty;

            // Recurring cost is our own price × quantity — exact, no call needed.
            line.firstChild.nodeValue = money(unit * qty) + ' ';

            var changed = qty !== owned;
            if (submit) { submit.disabled = !changed; }

            if (!changed) {
                box.hidden = true;
                idle.hidden = false;
                return;
            }

            idle.hidden = true;
            box.hidden = false;
            labelEl.textContent = (qty > owned ? 'Adding ' + (qty - owned) : 'Removing ' + (owned - qty))
                                + ' × ' + form.querySelector('.ad-name').childNodes[0].nodeValue.trim();
            changeEl.textContent = money(unit * (qty - owned)) + '/' + @json($perShort);
            dueEl.textContent = '…';

            clearTimeout(timer);
            timer = setTimeout(function () { fetchPreview(form.dataset.slug, qty); }, 350);
        }

        function fetchPreview(slug, qty) {
            fetch(previewUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify({ addon: slug, quantity: qty }),
            })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (d) {
                if (d.due_today === null || typeof d.due_today === 'undefined') {
                    // The provider could not be asked. Say so rather than guess.
                    dueEl.textContent = '—';
                    noteEl.textContent = 'The exact prorated amount will appear on your next invoice.';
                    setPayLabel(null);
                    return;
                }

                dueEl.textContent = money(d.due_today);

                if (d.immediate) {
                    // A purchase, happening now. The button carries the amount
                    // so nobody presses it without knowing what leaves their
                    // account.
                    setPayLabel(d.chargeable ? d.due_today : null);

                    noteEl.textContent = d.chargeable
                        ? 'Charged now, separately from your plan. Covers the rest of this billing period'
                          + (d.covers_to ? ' — until ' + d.covers_to + '.' : '.')
                        : (d.reason || 'Nothing to pay for that change.');

                    if (submit) { submit.disabled = !d.chargeable; }
                    return;
                }

                setPayLabel(null);
                noteEl.textContent = d.due_today < 0
                    ? 'Credited against your next invoice.'
                    : 'Prorated for the rest of your current billing period.';
            })
            .catch(function () {
                dueEl.textContent = '—';
                noteEl.textContent = 'The exact prorated amount will appear on your next invoice.';
                setPayLabel(null);
            });
        }

        form.querySelector('.js-inc').addEventListener('click', function () {
            input.value = clamp(parseInt(input.value, 10) + 1); sync();
        });
        form.querySelector('.js-dec').addEventListener('click', function () {
            input.value = clamp(parseInt(input.value, 10) - 1); sync();
        });
        input.addEventListener('input', sync);

        sync();
    });
})();
</script>
@endsection
