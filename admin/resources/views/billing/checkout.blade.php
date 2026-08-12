@extends('layouts.master')

@section('content')
@php
    $default   = collect($cards)->firstWhere('is_default', true) ?: ($cards[0] ?? null);
    $isChange  = (bool) $subscription?->stripe_subscription_ref;
    $brandName = tva_setting('content.brand_name', 'Serve AI');
@endphp

@include('billing._styles')

<style>
    .ck-wrap { display:grid; gap:20px; grid-template-columns:1fr; max-width:1040px; margin:0 auto; }
    @media (min-width:980px) { .ck-wrap { grid-template-columns:1.25fr .9fr; align-items:start; } }

    .ck-pm {
        display:flex; align-items:center; gap:13px; padding:14px 15px; cursor:pointer;
        border:1.5px solid #e2e8f0; border-radius:12px; margin-bottom:10px; background:#fff;
        transition:border-color .12s, background .12s;
    }
    .ck-pm:hover { border-color:#c7d2fe; }
    .ck-pm input { accent-color:#6366f1; width:17px; height:17px; flex:none; }
    .ck-pm.is-on { border-color:#6366f1; background:#eef2ff; }
    .ck-pm__brand {
        width:44px; height:30px; border-radius:6px; flex:none; display:flex; align-items:center;
        justify-content:center; background:#0f172a; color:#fff; font-size:9.5px; font-weight:800;
        text-transform:uppercase;
    }
    .ck-pm__num { font-size:13.5px; font-weight:650; color:#0f172a; font-variant-numeric:tabular-nums; }
    .ck-pm__exp { font-size:11.5px; color:#94a3b8; margin-top:2px; }

    .ck-newcard { border:1.5px dashed #cbd5e1; border-radius:12px; padding:16px; background:#f8fafc; }
    .ck-newcard.is-on { border-style:solid; border-color:#6366f1; background:#fff; }
    #ck-element { border:1px solid #e2e8f0; border-radius:10px; padding:13px 14px; background:#fff; }

    .ck-sum__row { display:flex; justify-content:space-between; gap:14px; font-size:13.5px; color:#475569; margin-bottom:10px; }
    .ck-sum__row--total {
        border-top:1px solid #e2e8f0; margin-top:14px; padding-top:14px;
        font-size:17px; font-weight:800; color:#0f172a;
    }
    .ck-plan { display:flex; gap:12px; align-items:flex-start; margin-bottom:16px; }
    .ck-plan__icon {
        width:42px; height:42px; border-radius:11px; flex:none; display:flex; align-items:center;
        justify-content:center; color:#fff; background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6));
    }
    .ck-plan__name { font-size:16px; font-weight:800; color:#0f172a; }
    .ck-plan__meta { font-size:12.5px; color:#64748b; margin-top:2px; }

    .ck-trust { display:flex; gap:8px; font-size:11.5px; color:#94a3b8; line-height:1.6; margin-top:14px; }
    .ck-err {
        display:none; margin-top:12px; font-size:13px; color:#b91c1c; background:#fef2f2;
        border:1px solid #fecaca; border-radius:10px; padding:11px 13px;
    }

    html.dark .ck-pm, html.dark #ck-element { background:#0f172a; border-color:#334155; }
    html.dark .ck-pm.is-on { background:#312e81; border-color:#6366f1; }
    html.dark .ck-pm__num, html.dark .ck-plan__name, html.dark .ck-sum__row--total { color:#f1f5f9; }
    html.dark .ck-newcard { background:#0f172a; border-color:#334155; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-5" style="max-width:1040px;margin-left:auto;margin-right:auto">
    <div class="mr-auto">
        <h2 class="text-lg font-medium">{{ $isChange ? 'Confirm your plan change' : 'Complete your subscription' }}</h2>
        <p style="font-size:13px;color:#64748b;margin-top:3px">Secure payment, processed by Stripe.</p>
    </div>
    <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back
    </a>
</div>

<div class="ck-wrap">
    {{-- ── Payment ───────────────────────────────────────────────── --}}
    <div class="bl-card intro-y">
        <div class="bl-card__head">
            <i data-lucide="credit-card" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="bl-card__title">Payment method</div>
        </div>

        {{-- Saved cards, default pre-selected. --}}
        @foreach ($cards as $card)
            <label class="ck-pm js-pm {{ $default && $card['id'] === $default['id'] ? 'is-on' : '' }}">
                <input type="radio" name="ck_pm" value="{{ $card['id'] }}"
                       @checked($default && $card['id'] === $default['id'])>
                <div class="ck-pm__brand">{{ \App\Services\Billing\PaymentMethodService::brandLabel($card['brand']) }}</div>
                <div>
                    <div class="ck-pm__num">•••• {{ $card['last4'] }}</div>
                    <div class="ck-pm__exp">
                        Expires {{ str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT) }}/{{ $card['exp_year'] }}
                        @if ($card['expired'])
                            <span class="bl-badge bl-badge--red" style="margin-left:5px">Expired</span>
                        @elseif ($card['is_default'])
                            <span class="bl-badge bl-badge--blue" style="margin-left:5px">Default</span>
                        @endif
                    </div>
                </div>
            </label>
        @endforeach

        {{-- New card. Pre-selected when there's nothing saved. --}}
        <label class="ck-pm js-pm {{ empty($cards) ? 'is-on' : '' }}" style="margin-bottom:12px">
            <input type="radio" name="ck_pm" value="__new__" @checked(empty($cards))>
            <div class="ck-pm__brand" style="background:#6366f1"><i data-lucide="plus" class="w-4 h-4"></i></div>
            <div>
                <div class="ck-pm__num">{{ empty($cards) ? 'Add your card' : 'Use a different card' }}</div>
                <div class="ck-pm__exp">Visa, Mastercard, Amex and more</div>
            </div>
        </label>

        <div id="ck-newcard" class="ck-newcard {{ empty($cards) ? 'is-on' : '' }}" @if(! empty($cards)) hidden @endif>
            <label style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;display:block;margin-bottom:8px">
                Card details
            </label>
            {{-- Stripe's iframe mounts here: the number, expiry and CVC are
                 never part of our DOM, so raw card data cannot reach us. --}}
            <div id="ck-element"></div>

            <label style="display:flex;gap:8px;align-items:center;margin-top:11px;font-size:12.5px;color:#475569">
                <input type="checkbox" id="ck-save" checked style="accent-color:#6366f1">
                Save this card for future payments
            </label>
        </div>

        <div id="ck-error" class="ck-err"></div>

        <button type="button" id="ck-pay" class="bl-btn bl-btn--primary" style="width:100%;margin-top:16px;padding:13px">
            <span class="js-label">
                <i data-lucide="lock" class="w-4 h-4"></i>
                Pay {{ $priceDisplay['usd'] }} and {{ $isChange ? 'switch plan' : 'subscribe' }}
            </span>
            <span class="js-busy" hidden>Processing…</span>
        </button>

        <div class="ck-trust">
            <i data-lucide="shield-check" class="w-4 h-4" style="flex:none;color:#16a34a"></i>
            <span>
                Payments are processed by Stripe. Your card details never touch {{ $brandName }}’s servers.
                Cancel any time — you keep access until the end of the period you’ve paid for.
            </span>
        </div>
    </div>

    {{-- ── Order summary ─────────────────────────────────────────── --}}
    <div class="bl-card intro-y">
        <div class="bl-card__head">
            <i data-lucide="shopping-bag" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="bl-card__title">Order summary</div>
        </div>

        <div class="ck-plan">
            <div class="ck-plan__icon"><i data-lucide="zap" class="w-5 h-5"></i></div>
            <div>
                <div class="ck-plan__name">{{ $plan->name }}</div>
                <div class="ck-plan__meta">{{ $price->intervalLabel() }} subscription</div>
            </div>
        </div>

        <div class="ck-sum__row">
            <span>{{ $plan->name }} ({{ strtolower($price->intervalLabel()) }})</span>
            <span class="bl-amt">{{ $price->formatted() }}</span>
        </div>

        @if ($price->months() > 1)
            <div class="ck-sum__row">
                <span>Equivalent monthly</span>
                <span>{{ $price->formattedEffectiveMonthly() }}/mo</span>
            </div>
        @endif

        @php $monthly = $plan->priceFor('monthly'); @endphp
        @if ($monthly && $price->savingsCentsAgainst($monthly) > 0)
            <div class="ck-sum__row" style="color:#15803d;font-weight:650">
                <span>You save</span>
                <span>${{ number_format($price->savingsCentsAgainst($monthly) / 100, 2) }}</span>
            </div>
        @endif

        <div class="ck-sum__row ck-sum__row--total">
            <span>Total due today</span>
            <span>{{ $priceDisplay['usd'] }}</span>
        </div>

        @if ($priceDisplay['local'])
            <div style="text-align:right;font-size:12px;color:#6366f1;margin-top:-4px">
                ≈ {{ $priceDisplay['local'] }}
            </div>
        @endif

        <p class="bl-note">
            Charged in USD.
            @if ($priceDisplay['local']) The local amount is approximate — your card is charged the USD figure. @endif
            Renews {{ strtolower($price->intervalLabel()) }} until cancelled.
            @if ($isChange) Any difference from your current plan is prorated on your next invoice. @endif
        </p>
    </div>
</div>

{{-- ── Result modals ─────────────────────────────────────────────── --}}
@include('skills._modal_css')

<div id="ck-success" class="tva-modal" hidden>
    <div class="tva-modal__backdrop"></div>
    <div class="tva-modal__panel" style="max-width:430px;text-align:center;">
        <div class="tva-modal__body" style="padding:38px 32px">
            <div style="width:78px;height:78px;margin:0 auto 20px;border-radius:50%;background:#dcfce7;
                        display:flex;align-items:center;justify-content:center;">
                <i data-lucide="check" class="w-10 h-10" style="color:#16a34a"></i>
            </div>
            <h3 style="font-size:21px;font-weight:800;color:#0f172a;margin-bottom:9px">Payment successful</h3>
            <p style="font-size:14px;color:#64748b;line-height:1.65;margin-bottom:24px">
                You’re now on <strong id="ck-success-plan">{{ $plan->name }}</strong>.
                Everything unlocked instantly — your agent is live on all the channels your plan includes.
            </p>
            <a href="{{ route('billing.index', ['client' => $client->slug]) }}" class="bl-btn bl-btn--primary" style="width:100%">
                Go to billing
            </a>
            <a href="{{ route('dashboard', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost" style="width:100%;margin-top:9px">
                Back to dashboard
            </a>
        </div>
    </div>
</div>

<div id="ck-failed" class="tva-modal" hidden>
    <div class="tva-modal__backdrop" data-tva-modal-close></div>
    <div class="tva-modal__panel" style="max-width:430px;text-align:center;">
        <div class="tva-modal__body" style="padding:38px 32px">
            <div style="width:78px;height:78px;margin:0 auto 20px;border-radius:50%;background:#fee2e2;
                        display:flex;align-items:center;justify-content:center;">
                <i data-lucide="x" class="w-10 h-10" style="color:#dc2626"></i>
            </div>
            <h3 style="font-size:21px;font-weight:800;color:#0f172a;margin-bottom:9px">Payment didn’t go through</h3>
            <p id="ck-failed-msg" style="font-size:14px;color:#64748b;line-height:1.65;margin-bottom:8px"></p>
            <p style="font-size:12.5px;color:#94a3b8;margin-bottom:22px">
                You have not been charged. Try again, or use a different card.
            </p>
            <button type="button" class="bl-btn bl-btn--primary" style="width:100%" data-tva-modal-close>
                Try again
            </button>
        </div>
    </div>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
    var stripeKey = @json($stripeKey);
    if (!stripeKey || typeof Stripe === 'undefined') return;

    var stripe   = Stripe(stripeKey);
    var elements = stripe.elements();
    var card     = elements.create('card', {
        style: {
            base: { fontSize: '14px', color: '#0f172a', fontFamily: 'inherit',
                    '::placeholder': { color: '#94a3b8' } },
            invalid: { color: '#b91c1c', iconColor: '#b91c1c' }
        }
    });

    var newCardBox = document.getElementById('ck-newcard');
    var payBtn     = document.getElementById('ck-error') && document.getElementById('ck-pay');
    var errBox     = document.getElementById('ck-error');
    var mounted    = false;
    var busy       = false;

    function mountIfNeeded() {
        if (!mounted) { card.mount('#ck-element'); mounted = true; }
    }
    if (!newCardBox.hasAttribute('hidden')) mountIfNeeded();

    // ── Payment-method selection ───────────────────────────────────
    function selected() {
        var el = document.querySelector('input[name="ck_pm"]:checked');
        return el ? el.value : null;
    }

    document.querySelectorAll('input[name="ck_pm"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.js-pm').forEach(function (l) {
                l.classList.toggle('is-on', l.contains(radio) && radio.checked);
            });
            var isNew = selected() === '__new__';
            newCardBox.hidden = !isNew;
            newCardBox.classList.toggle('is-on', isNew);
            if (isNew) mountIfNeeded();
            showError('');
        });
    });

    function showError(msg) {
        errBox.textContent = msg;
        errBox.style.display = msg ? 'block' : 'none';
    }
    function setBusy(v) {
        busy = v;
        payBtn.disabled = v;
        payBtn.querySelector('.js-label').hidden = v;
        payBtn.querySelector('.js-busy').hidden = !v;
    }
    function openModal(id) { document.getElementById(id).removeAttribute('hidden'); }

    document.querySelectorAll('[data-tva-modal-close]').forEach(function (el) {
        el.addEventListener('click', function () {
            var m = el.closest('.tva-modal');
            if (m) m.setAttribute('hidden', '');
        });
    });

    function fail(msg) {
        setBusy(false);
        document.getElementById('ck-failed-msg').textContent = msg || 'Something went wrong.';
        openModal('ck-failed');
    }

    // ── Pay ────────────────────────────────────────────────────────
    payBtn.addEventListener('click', function () {
        if (busy) return;
        setBusy(true);
        showError('');

        var choice = selected();

        // A saved card is already a payment-method id; a new one has to be
        // tokenised first. Either way only an id is ever POSTed to us.
        var getPaymentMethod = choice === '__new__'
            ? stripe.createPaymentMethod({ type: 'card', card: card })
                    .then(function (r) {
                        if (r.error) throw new Error(r.error.message);
                        return r.paymentMethod.id;
                    })
            : Promise.resolve(choice);

        getPaymentMethod
            .then(function (pmId) {
                return fetch(@json(route('billing.subscribe', ['client' => $client->slug])), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        plan: @json($plan->slug),
                        interval: @json($price->interval),
                        payment_method: pmId
                    })
                }).then(function (r) {
                    return r.json().then(function (b) { return { ok: r.ok, body: b, pmId: pmId }; });
                });
            })
            .then(function (res) {
                if (!res.ok) throw new Error(res.body.message || 'Payment failed.');

                // Stripe may need the browser: 3-D Secure, or simply confirming
                // a PaymentIntent raised against a saved card.
                if (res.body.requires_action && res.body.client_secret) {
                    return stripe.confirmCardPayment(res.body.client_secret, {
                        payment_method: res.pmId
                    }).then(function (result) {
                        if (result.error) throw new Error(result.error.message);
                        return res.body.subscription_ref;
                    });
                }
                return res.body.subscription_ref;
            })
            .then(function (subscriptionRef) {
                // Pull the settled state forward so the page doesn't sit on
                // "pending" waiting for the webhook. The webhook is still what
                // makes it official.
                return fetch(@json(route('billing.confirm', ['client' => $client->slug])), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ subscription: subscriptionRef })
                }).then(function (r) { return r.json(); });
            })
            .then(function (state) {
                if (state && state.plan) {
                    document.getElementById('ck-success-plan').textContent = state.plan;
                }
                setBusy(false);
                openModal('ck-success');
            })
            .catch(function (err) { fail(err.message); });
    });
})();
</script>
@endsection
