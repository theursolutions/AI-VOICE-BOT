@extends('layouts.master')

@section('content')
@php
    $isChange  = (bool) $subscription?->plan_id;
    $brandName = tva_setting('content.brand_name', 'Serve AI');
    $currency  = strtoupper((string) ($price->currency ?: 'usd'));
    $each      = config("billing.intervals.labels.{$price->interval}", ucfirst($price->interval));
    $key       = $gateway?->key();

    // WHO PROCESSES THE MONEY IS NOT NAMED. The customer chose this product,
    // not a payment company they have never heard of, and putting one between
    // them and the buy button only invites the question "who are they?". It
    // also changes: the copy would be wrong the day the routing does.
    //
    // What IS named is what they can pay with, which is the thing they are
    // actually deciding about.
    $methods = match ($key) {
        'paddle', 'stripe' => ['card', 'paypal', 'applepay', 'googlepay'],
        default            => ['card', 'bank', 'jazzcash', 'easypaisa'],
    };

    // Merchant of Record: the processor is the seller on the invoice and
    // carries the tax. Not named, but the CONSEQUENCE is stated — tax handled,
    // price is the price — because that part is the customer's business.
    $isMor = $key === 'paddle';

    // Paddle bills the renewal itself; Safepay cannot, and a customer who
    // assumes otherwise finds out by losing access.
    $autoRenews = (bool) $gateway?->supports(\App\Services\Billing\Gateways\PaymentGateway::CAP_RECURRING);
@endphp

@include('billing._styles')

<style>
    .hk-wrap { display:grid; gap:20px; grid-template-columns:1fr; max-width:900px; margin:0 auto; }
    @media (min-width:900px) { .hk-wrap { grid-template-columns:1.1fr .9fr; align-items:start; } }

    .hk-plan { display:flex; gap:12px; align-items:flex-start; margin-bottom:18px; }
    .hk-plan__icon {
        width:44px; height:44px; border-radius:12px; flex:none; display:flex; align-items:center;
        justify-content:center; color:#fff; background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
    }
    .hk-plan__name { font-size:16px; font-weight:800; color:#0f172a; }
    .hk-plan__meta { font-size:12.5px; color:#64748b; margin-top:2px; line-height:1.5; }

    .hk-row { display:flex; justify-content:space-between; gap:14px; font-size:13.5px; color:#475569; margin-bottom:10px; }
    .hk-row--total {
        border-top:1px solid #e2e8f0; margin-top:14px; padding-top:14px;
        font-size:19px; font-weight:800; color:#0f172a;
    }
    .hk-row__usd { font-size:12px; color:#94a3b8; font-weight:600; }

    .hk-lede { font-size:13.5px; color:#475569; line-height:1.65; margin:0; }

    .hk-methods { display:flex; flex-wrap:wrap; gap:8px; margin:16px 0 4px; }
    .hk-method {
        display:flex; align-items:center; gap:7px; border:1px solid #e2e8f0; border-radius:10px;
        padding:9px 12px; font-size:12.5px; font-weight:650; color:#334155; background:#fff;
    }
    .hk-method i { color:#6366f1; flex:none; }

    .hk-steps { list-style:none; padding:0; margin:18px 0 0; counter-reset:s; }
    .hk-steps li {
        counter-increment:s; position:relative; padding:0 0 13px 32px;
        font-size:13px; color:#475569; line-height:1.55;
    }
    .hk-steps li::before {
        content:counter(s); position:absolute; left:0; top:-1px; width:22px; height:22px;
        border-radius:50%; background:#eef2ff; color:#4f46e5; font-size:11.5px; font-weight:800;
        display:flex; align-items:center; justify-content:center;
    }
    .hk-steps li:last-child { padding-bottom:0; }

    .hk-choose { border:1px solid #e2e8f0; border-radius:16px; background:#fff; padding:16px 18px; }
    .hk-choose__title { font-size:14px; font-weight:750; color:#0f172a; margin-bottom:12px; }
    .hk-choose__row { display:grid; gap:10px; grid-template-columns:1fr; }
    @media (min-width:700px) { .hk-choose__row { grid-template-columns:1fr 1fr; } }
    .hk-choose__note { font-size:11.5px; color:#94a3b8; margin-top:11px; line-height:1.5; }

    .hk-opt {
        display:flex; align-items:center; gap:11px; border:1.5px solid #e2e8f0;
        border-radius:12px; padding:12px 14px; text-decoration:none;
        transition:border-color .12s, background .12s;
    }
    .hk-opt:hover { border-color:#c7d2fe; }
    .hk-opt.is-on { border-color:#6366f1; background:#eef2ff; }
    .hk-opt__tick {
        width:19px; height:19px; border-radius:50%; flex:none; border:1.5px solid #cbd5e1;
        display:flex; align-items:center; justify-content:center; color:#fff;
    }
    .hk-opt.is-on .hk-opt__tick { background:#6366f1; border-color:#6366f1; }
    .hk-opt__label { display:block; font-size:13.5px; font-weight:700; color:#0f172a; }
    .hk-opt__blurb { display:block; font-size:11.5px; color:#64748b; margin-top:2px; }
    .hk-opt__price { margin-left:auto; font-size:14px; font-weight:800; color:#0f172a; white-space:nowrap; }

    html.dark .hk-choose, html.dark .hk-opt { background:#1e293b; border-color:#334155; }
    html.dark .hk-opt.is-on { background:#312e81; border-color:#6366f1; }
    html.dark .hk-choose__title, html.dark .hk-opt__label, html.dark .hk-opt__price { color:#f1f5f9; }

    .hk-pay { width:100%; justify-content:center; font-size:14.5px; padding:13px 18px; }
    .hk-pay[disabled] { opacity:.65; cursor:default; }
    .hk-exvat {
        display:flex; gap:7px; align-items:flex-start; margin-top:9px;
        font-size:11.5px; color:#92400e; background:#fffbeb; border:1px solid #fde68a;
        border-radius:9px; padding:8px 10px; line-height:1.5;
    }
    .hk-trust { display:flex; gap:8px; font-size:11.5px; color:#94a3b8; line-height:1.6; margin-top:14px; }
    .hk-err {
        display:none; margin-top:12px; font-size:13px; color:#b91c1c; background:#fef2f2;
        border:1px solid #fecaca; border-radius:10px; padding:11px 13px;
    }

    html.dark .hk-method { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .hk-plan__name, html.dark .hk-row--total { color:#f1f5f9; }
    html.dark .hk-steps li, html.dark .hk-lede { color:#94a3b8; }
    html.dark .hk-row--total { border-top-color:#334155; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-5" style="max-width:900px;margin-left:auto;margin-right:auto">
    <div class="mr-auto">
        <h2 class="text-lg font-medium">{{ $isChange ? 'Confirm your plan change' : 'Complete your subscription' }}</h2>
        <p style="font-size:13px;color:#64748b;margin-top:3px">Secure payment — your card details never reach us.</p>
    </div>
    <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
    </a>
</div>

@if (session('error'))
    <div class="bl-alert bl-alert--err intro-y" style="max-width:900px;margin:0 auto 18px">
        <i data-lucide="alert-circle" class="w-4 h-4" style="flex:none"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

<div class="intro-y" style="max-width:900px;margin:0 auto 18px">
    @include('billing._country-picker', [
        'countryAction'   => $country['action'],
        'countryCurrent'  => $country['current'],
        'countryDetected' => $country['detected'],
        'countryCurrency' => $country['currency'],
    ])
</div>

{{-- ── How they'd like to pay ─────────────────────────────────── --}}
@if (! empty($options))
    <div class="intro-y" style="max-width:900px;margin:0 auto 18px">
        <div class="hk-choose">
            <div class="hk-choose__title">How would you like to pay?</div>

            <div class="hk-choose__row">
                @foreach ($options as $opt)
                    {{-- A LINK, not a radio. Choosing re-renders the page
                         server-side, because the amount, the currency and the
                         methods below all change with it — and a form that
                         updated only some of them would show a price nobody is
                         about to be charged. --}}
                    <a href="{{ $opt['url'] }}" class="hk-opt {{ $opt['selected'] ? 'is-on' : '' }}">
                        <span class="hk-opt__tick">
                            @if ($opt['selected'])
                                <i data-lucide="check" class="w-3 h-3"></i>
                            @endif
                        </span>
                        <span style="min-width:0">
                            <span class="hk-opt__label">{{ $opt['label'] }}</span>
                            <span class="hk-opt__blurb">{{ $opt['blurb'] }}</span>
                        </span>
                        <span class="hk-opt__price">{{ $opt['price'] }}</span>
                    </a>
                @endforeach
            </div>

            <div class="hk-choose__note">
                Both are secure. The amount differs because each is settled in its own currency.
            </div>
        </div>
    </div>
@endif

<div class="hk-wrap">
    {{-- ── How this works ────────────────────────────────────────── --}}
    <div class="bl-card intro-y">
        <div class="bl-card__head">
            <i data-lucide="wallet" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="bl-card__title">How you'll pay</div>
        </div>

        <p class="hk-lede">
            @if ($isMor)
                Payment is handled by our authorised reseller, who appears as the seller on your
                invoice and takes care of any sales tax or VAT due in your country — so the price
                you see is the price you pay.
            @else
                Payment is handled by a State Bank of Pakistan licensed processor. Pick whichever
                method suits you — no card details ever reach {{ $brandName }}.
            @endif
        </p>

        <div style="margin:16px 0 4px">
            @include('billing._payment-methods', ['methods' => $methods])
        </div>

        <ol class="hk-steps">
            @if ($overlay)
                <li>A secure payment window opens right here — you won't leave {{ $brandName }}.</li>
                <li>Pay by card, PayPal, Apple Pay or Google Pay.</li>
                <li>Your plan is active the moment it clears, usually within seconds.</li>
            @else
                <li>We open a secure checkout with the amount below already filled in.</li>
                <li>You pay by card, bank account, JazzCash or Easypaisa.</li>
                <li>You come straight back here and your plan is active — usually within seconds.</li>
            @endif
        </ol>

        {{--
            Renewal is spelled out because it differs by provider, and the
            difference is the one thing a customer would otherwise get wrong.
            Paddle bills the next period itself; Safepay cannot, so a customer
            who assumes it will finds out by losing access.
        --}}
        <div class="bl-alert bl-alert--info" style="margin:18px 0 0">
            <i data-lucide="info" class="w-4 h-4" style="flex:none"></i>
            <div>
                @if ($isRenewal)
                    <strong>This adds another {{ strtolower($each) }} to your plan.</strong>
                    Your new date is {{ $subscription->current_period_end?->copy()->addMonths($price->months())->format('j M Y') }}
                    — the days you've already paid for are kept.
                @elseif ($autoRenews)
                    <strong>Renews automatically every {{ strtolower(rtrim($each, 'ly')) === 'annual' ? 'year' : 'month' }}.</strong>
                    You can cancel any time from this page, and you'll keep access until the
                    period you've paid for runs out.
                @else
                    <strong>One payment, for one {{ strtolower($each) }} period.</strong>
                    We'll email and message you before it ends so you can renew — nothing is
                    charged automatically.
                @endif
            </div>
        </div>

        {{--
            Switching away from a plan that is still running forfeits the rest
            of it. Said here, before the button — a customer who finds this out
            from the invoice was misled by our silence.
        --}}
        @if ($forfeits)
            <div class="bl-alert bl-alert--warn" style="margin:12px 0 0">
                <i data-lucide="alert-triangle" class="w-4 h-4" style="flex:none"></i>
                <div>
                    <strong>Your current plan is paid up to {{ $forfeits->format('j M Y') }}.</strong>
                    Switching now starts the new plan today and the remaining time isn't
                    refunded or carried over. If you'd rather wait, come back nearer that
                    date — nothing is lost by leaving it.
                </div>
            </div>
        @endif
    </div>

    {{-- ── Order summary ─────────────────────────────────────────── --}}
    <div class="bl-card intro-y">
        <div class="bl-card__head">
            <i data-lucide="file-text" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="bl-card__title">Order summary</div>
        </div>

        <div class="hk-plan">
            <div class="hk-plan__icon"><i data-lucide="zap" class="w-5 h-5"></i></div>
            <div>
                <div class="hk-plan__name">{{ $plan->name }}</div>
                <div class="hk-plan__meta">{{ $plan->tagline ?: 'Billed ' . strtolower($each) }}</div>
            </div>
        </div>

        <div class="hk-row">
            <span>{{ $plan->name }} — {{ $each }}</span>
            <span>{{ tva_money($tax['subtotal'], $price->currency, false) }}</span>
        </div>
        @if ($tax['show'])
            {{-- A rate WE apply, so the figures are ours to state exactly. --}}
            <div class="hk-row">
                <span>
                    {{ $tax['mode'] === 'inclusive' ? 'Includes tax' : 'Tax' }}
                    <span style="color:#94a3b8">({{ rtrim(rtrim(number_format($tax['rate'], 2), '0'), '.') }}%)</span>
                </span>
                <span>{{ tva_money($tax['tax'], $price->currency, false) }}</span>
            </div>
        @else
            {{--
                The provider computes the figure from an address we have not been
                given yet, so no amount can be shown. But WHETHER it is added is
                already decided by the tax setting, and that is the part the
                customer needs before they press the button — "Included" when it
                is not is the one wrong thing this line could say.
            --}}
            <div class="hk-row">
                <span>{{ $tax['mode'] === 'inclusive' ? 'Tax' : 'VAT / sales tax' }}</span>
                <span>{{ $tax['mode'] === 'inclusive' ? 'Included' : 'Added at checkout' }}</span>
            </div>
        @endif

        <div class="hk-row hk-row--total">
            <span>Due today</span>
            <span>{{ tva_money($tax['total'], $price->currency, false) }}</span>
        </div>

        {{-- Said plainly under the total, because a customer who reads only the
             big number and then pays more than it has been misled by us. --}}
        @if ($tax['mode'] !== 'inclusive')
            <div class="hk-exvat">
                <i data-lucide="info" class="w-3.5 h-3.5" style="flex:none"></i>
                <span>
                    Excludes VAT or sales tax, which is added at checkout based on your
                    billing location.
                </span>
            </div>
        @endif

        {{-- The same amount in the other currency. Never what is charged, and
             the "≈" is dropped only when it is another real price of ours
             rather than a live conversion. --}}
        @if ($priceAlso)
            <div class="hk-row" style="margin-top:-4px;justify-content:flex-end">
                <span class="hk-row__usd">
                    {{ $priceAlso['exact'] ? '' : '≈ ' }}{{ $priceAlso['amount'] }}
                    {{ $priceAlso['exact'] ? '' : 'at today’s rate' }}
                </span>
            </div>
        @endif

        {{--
            Two opaque identifiers and nothing else. The figure above is
            display; the charge is resolved server-side from this plan slug and
            interval, so a tampered amount in the DOM has nothing to act on.
            Field names are `plan` and `interval` — never `*_id`, which
            DecodeHashids would rewrite.
        --}}
        <form method="POST" action="{{ route('billing.checkout.pay', ['client' => $client->slug]) }}"
              style="margin-top:18px" id="hk-form"
              @if ($overlay) data-overlay="1" @endif>
            @csrf
            <input type="hidden" name="plan" value="{{ $plan->slug }}">
            <input type="hidden" name="interval" value="{{ $price->interval }}">
            {{-- Without this, paying silently reverts to the country's default
                 provider and charges the other currency. --}}
            <input type="hidden" name="via" value="{{ $via }}">

            <button type="submit" class="bl-btn bl-btn--primary hk-pay" id="hk-pay">
                <i data-lucide="lock" class="w-4 h-4"></i>
                Pay {{ tva_money($tax['total'], $price->currency, false) }}{{ $tax['mode'] === 'inclusive' ? '' : ' + tax' }}
            </button>
        </form>

        <div class="hk-err" id="hk-err"></div>

        <div class="hk-trust">
            <i data-lucide="shield-check" class="w-4 h-4" style="flex:none;color:#94a3b8"></i>
            <div>
                Charged in {{ $currency }}. {{ $brandName }} never sees or stores your card number.
            </div>
        </div>
    </div>
</div>

@if ($overlay)
    {{-- Paddle.js draws the overlay. Only the PUBLIC token is rendered here. --}}
    <script src="{{ $overlay['js'] }}"></script>
@endif

<script>
    (function () {
        var form = document.getElementById('hk-form');
        var btn  = document.getElementById('hk-pay');
        var err  = document.getElementById('hk-err');
        if (!form || !btn) return;

        var label = btn.innerHTML;
        var reference   = '';   // ours, set when a payment is started
        var transaction = '';   // the provider's, for confirming it afterwards

        function fail(message) {
            err.textContent = message;
            err.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = label;
        }

        function busy(text) {
            btn.disabled = true;
            btn.textContent = text;
            err.style.display = 'none';
        }

        @if ($overlay)
            // ── Overlay checkout ────────────────────────────────────────
            // The page never navigates. The server creates the transaction —
            // so the amount is decided there and the browser only ever holds
            // an opaque id — and Paddle draws its own checkout over this page.
            try {
                Paddle.Environment.set(@json($overlay['environment']));
                Paddle.Initialize({
                    token: @json($overlay['token']),
                    eventCallback: function (event) {
                        // The webhook is what actually grants the plan; this
                        // only decides what the person is shown. Treating a
                        // browser event as proof of payment would let anyone
                        // grant themselves a subscription from the console.
                        if (event.name === 'checkout.completed') {
                            // Sent to a route that ASKS the provider whether
                            // this really completed, rather than straight to a
                            // receipt on the browser's word. It grants the plan
                            // if the answer is yes, then shows the receipt.
                            var txn = (event.data && event.data.transaction_id) || transaction || '';

                            window.location = @json(route('billing.paddle.confirm', ['client' => $client->slug])) +
                                '?reference=' + encodeURIComponent(reference || '') +
                                '&transaction=' + encodeURIComponent(txn);
                        }
                    }
                });
            } catch (e) {
                // Paddle.js blocked or failed to load. Say so plainly rather
                // than letting the button do nothing when pressed.
                btn.disabled = true;
                btn.textContent = 'Checkout unavailable';
                fail('The payment window could not load. Please disable any ad blocker for this page and refresh.');
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                busy('Opening secure checkout…');

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': form.querySelector('[name=_token]').value,
                    },
                    body: new FormData(form),
                })
                .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                .then(function (res) {
                    if (!res.ok || !res.data.transaction) {
                        fail(res.data.message || 'We could not start the payment. Please try again.');
                        return;
                    }

                    reference   = res.data.reference || '';
                    transaction = res.data.transaction || '';

                    Paddle.Checkout.open({ transactionId: transaction });

                    // Restored rather than left spinning: the customer may
                    // close the overlay without paying, and a dead button
                    // would leave them stuck on a page they cannot retry from.
                    btn.disabled = false;
                    btn.innerHTML = label;
                })
                .catch(function () {
                    fail('We could not reach the payment provider. Please check your connection and try again.');
                });
            });
        @else
            // ── Redirect checkout ───────────────────────────────────────
            // One submission only. A double-click would open a second payment
            // session and leave an orphan pending charge behind.
            form.addEventListener('submit', function () {
                busy('Opening secure checkout…');
            });
        @endif
    })();
</script>
@endsection
