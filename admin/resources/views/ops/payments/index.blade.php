@extends('layouts.ops')

@section('content')
{{-- This page uses the billing button/badge/card classes, and the ops layout
     does not carry them — without this the Save button renders as bare text. --}}
@include('billing._styles')

<style>
    .pay-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:14px; }
    @media (max-width:960px) { .pay-grid { grid-template-columns:1fr; } }

    .pay-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px;
        display:flex; flex-direction:column; gap:12px; transition:border-color .15s;
    }
    .pay-card.is-off { background:#fafafa; border-style:dashed; }
    .pay-card.is-broken { border-color:#fca5a5; }

    .pay-card__top { display:flex; align-items:flex-start; gap:13px; }
    .pay-card__icon {
        width:42px; height:42px; border-radius:11px; flex:none; display:flex;
        align-items:center; justify-content:center; background:#eef2ff; color:#4f46e5;
    }
    .pay-card.is-off .pay-card__icon { background:#f1f5f9; color:#94a3b8; }
    .pay-card__name { font-size:15px; font-weight:750; color:#0f172a; }
    .pay-card__label { font-size:12px; color:#64748b; margin-top:2px; }
    .pay-card__blurb { font-size:12.5px; color:#64748b; line-height:1.6; margin:0; }

    .pay-facts { display:flex; flex-wrap:wrap; gap:6px; }
    .pay-fact {
        font-size:10.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase;
        padding:3px 8px; border-radius:999px; background:#f1f5f9; color:#475569;
    }
    .pay-fact--ok   { background:#dcfce7; color:#166534; }
    .pay-fact--bad  { background:#fee2e2; color:#991b1b; }
    .pay-fact--info { background:#e0e7ff; color:#3730a3; }

    .pay-missing {
        font-size:11.5px; color:#991b1b; background:#fef2f2; border:1px solid #fecaca;
        border-radius:9px; padding:9px 11px; line-height:1.6;
    }
    .pay-missing code { font-family:ui-monospace,monospace; font-size:11px; }

    /* Toggle switch — same control as the module switchboard. */
    .pay-switch { position:relative; display:inline-block; width:46px; height:26px; flex:none; margin-left:auto; }
    .pay-switch input { opacity:0; width:0; height:0; }
    .pay-switch .track {
        position:absolute; inset:0; cursor:pointer; background:#cbd5e1;
        border-radius:999px; transition:background .2s;
    }
    .pay-switch .track::before {
        content:''; position:absolute; height:20px; width:20px; left:3px; top:3px;
        background:#fff; border-radius:50%; transition:transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.3);
    }
    .pay-switch input:checked + .track { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); }
    .pay-switch input:checked + .track::before { transform:translateX(20px); }

    .pay-section { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-top:20px; }
    .pay-section__head { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
    .pay-section__title { font-size:15px; font-weight:750; color:#0f172a; }
    .pay-section__note { font-size:12.5px; color:#64748b; line-height:1.65; margin:0 0 16px; }

    .pay-countries {
        display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:6px 14px;
        max-height:340px; overflow-y:auto; padding:14px; border:1px solid #e2e8f0;
        border-radius:11px; background:#f8fafc;
    }
    .pay-country { display:flex; align-items:center; gap:8px; font-size:12.5px; color:#334155; }
    .pay-country input { accent-color:#6366f1; width:15px; height:15px; flex:none; }
    .pay-country__cur { margin-left:auto; font-size:10.5px; color:#94a3b8; font-weight:650; }

    .pay-tools { display:flex; gap:8px; margin:12px 0 0; flex-wrap:wrap; }

    .pay-tax { display:grid; gap:10px; grid-template-columns:1fr; }
    @media (min-width:860px) { .pay-tax { grid-template-columns:repeat(3, 1fr); } }
    .pay-tax__opt {
        display:flex; gap:10px; align-items:flex-start; border:1.5px solid #e2e8f0;
        border-radius:12px; padding:13px 15px; cursor:pointer; background:#fff;
        transition:border-color .12s, background .12s;
    }
    .pay-tax__opt:hover { border-color:#c7d2fe; }
    .pay-tax__opt.is-on { border-color:#6366f1; background:#eef2ff; }
    .pay-tax__opt input { accent-color:#6366f1; width:16px; height:16px; flex:none; margin-top:2px; }
    .pay-tax__label { display:block; font-size:13.5px; font-weight:700; color:#0f172a; }
    .pay-tax__blurb { display:block; font-size:12px; color:#64748b; line-height:1.55; margin-top:4px; }

    .pay-rate {
        display:flex; align-items:center; gap:11px; padding:11px 0;
        border-bottom:1px solid #f1f5f9;
    }
    .pay-rate:last-child { border-bottom:0; }
    .pay-rate__flag { font-size:19px; line-height:1; flex:none; }
    .pay-rate__name { font-size:13.5px; font-weight:650; color:#0f172a; margin-right:auto; }
    .pay-rate__via { display:block; font-size:11.5px; color:#94a3b8; font-weight:500; margin-top:1px; }
    .pay-rate__field { display:flex; align-items:center; gap:6px; flex:none; }
    .pay-rate__field input {
        width:88px; border:1px solid #e2e8f0; border-radius:9px; padding:7px 10px;
        font-size:13.5px; text-align:right; background:#f8fafc; color:#0f172a;
    }
    .pay-rate__field span { font-size:13px; color:#64748b; font-weight:650; }

    html.dark .pay-tax__opt { background:#0f172a; border-color:#334155; }
    html.dark .pay-tax__opt.is-on { background:#312e81; }
    html.dark .pay-tax__label, html.dark .pay-rate__name { color:#f1f5f9; }
    html.dark .pay-rate__field input { background:#0f172a; border-color:#334155; color:#e2e8f0; }
    html.dark .pay-rate { border-bottom-color:#0f172a; }

    html.dark .pay-card, html.dark .pay-section { background:#1e293b; border-color:#334155; }
    html.dark .pay-card__name, html.dark .pay-section__title { color:#f1f5f9; }
    html.dark .pay-countries { background:#0f172a; border-color:#334155; }
    html.dark .pay-country { color:#cbd5e1; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-2">
    <div class="mr-auto">
        <h2 class="text-lg font-medium">Payments</h2>
        <p style="font-size:13px;color:#64748b;margin-top:3px">
            Which providers may take money, and which countries we sell to.
        </p>
    </div>
</div>

@foreach (['success' => 'check-circle', 'warning' => 'alert-triangle', 'error' => 'alert-octagon'] as $flash => $icon)
    @if (session($flash))
        <div class="intro-y" style="margin:14px 0;padding:13px 16px;border-radius:12px;display:flex;gap:11px;font-size:13.5px;line-height:1.55;
            @if ($flash === 'success') background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;
            @elseif ($flash === 'warning') background:#fffbeb;border:1px solid #fde68a;color:#92400e;
            @else background:#fef2f2;border:1px solid #fecaca;color:#991b1b; @endif">
            <i data-lucide="{{ $icon }}" class="w-5 h-5" style="flex:none"></i>
            <div>{{ session($flash) }}</div>
        </div>
    @endif
@endforeach

<form method="POST" action="{{ route('ops.payments.update') }}">
    @csrf

    {{-- ── Providers ──────────────────────────────────────────────── --}}
    <div class="pay-grid intro-y" style="margin-top:16px">
        @foreach ($gateways as $g)
            @php
                // Enabled but with no credentials: the state that looks fine
                // here and fails at the customer's checkout.
                $broken = $g['enabled'] && ! $g['configured'];
            @endphp

            <div class="pay-card {{ $g['enabled'] ? '' : 'is-off' }} {{ $broken ? 'is-broken' : '' }}">
                <div class="pay-card__top">
                    <div class="pay-card__icon"><i data-lucide="credit-card" class="w-5 h-5"></i></div>
                    <div style="min-width:0">
                        <div class="pay-card__name">{{ $g['name'] }}</div>
                        <div class="pay-card__label">{{ $g['countries'] }} · {{ implode(', ', $g['currencies']) ?: 'any currency' }}</div>
                    </div>

                    <label class="pay-switch">
                        <input type="checkbox" name="gateways[]" value="{{ $g['key'] }}" @checked($g['enabled'])>
                        <span class="track"></span>
                    </label>
                </div>

                <p class="pay-card__blurb">{{ $g['blurb'] }}</p>

                <div class="pay-facts">
                    {{-- Permission and capability are shown apart on purpose:
                         a provider needs both, and conflating them hides which
                         one is missing. --}}
                    <span class="pay-fact {{ $g['enabled'] ? 'pay-fact--ok' : '' }}">
                        {{ $g['enabled'] ? 'Switched on' : 'Switched off' }}
                    </span>
                    <span class="pay-fact {{ $g['configured'] ? 'pay-fact--ok' : 'pay-fact--bad' }}">
                        {{ $g['configured'] ? 'Credentials set' : 'No credentials' }}
                    </span>
                    @if ($g['recurring'])
                        <span class="pay-fact pay-fact--info">Bills renewals itself</span>
                    @else
                        <span class="pay-fact">We chase renewals</span>
                    @endif
                </div>

                @if ($broken)
                    <div class="pay-missing">
                        <strong>Switched on but unusable.</strong> Customers routed here will be
                        skipped. Set {!! implode(', ', array_map(fn ($k) => '<code>' . e($k) . '</code>', $g['env_keys'])) !!}
                        in the environment.
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ── Countries ──────────────────────────────────────────────── --}}
    <div class="pay-section intro-y">
        <div class="pay-section__head">
            <i data-lucide="globe" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="pay-section__title">Where we sell</div>
        </div>

        <p class="pay-section__note">
            A country that isn't selected can't be chosen on the pricing page and can't reach
            checkout. Leave the restriction off to sell everywhere we have a currency for —
            which is the normal setting, and the one to keep unless there's a reason not to.
        </p>

        {{--
            The restriction is a separate switch from the list, because "sell
            everywhere" and "sell to nowhere" would otherwise both be an empty
            list. They are opposite intentions and must not share a state.
        --}}
        <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;font-weight:650;color:#0f172a;margin-bottom:14px;cursor:pointer">
            <input type="checkbox" name="restrict" value="1" id="pay-restrict"
                   @checked(! $unrestricted) style="accent-color:#6366f1;width:16px;height:16px">
            Only sell to specific countries
        </label>

        <div id="pay-country-box" @if ($unrestricted) style="display:none" @endif>
            <div class="pay-tools">
                <button type="button" class="bl-btn bl-btn--ghost bl-btn--sm" data-pay-all>Select all</button>
                <button type="button" class="bl-btn bl-btn--ghost bl-btn--sm" data-pay-none>Clear</button>
                <span style="font-size:12px;color:#94a3b8;align-self:center" id="pay-count"></span>
            </div>

            <div class="pay-countries" style="margin-top:12px">
                @foreach ($countries as $code => $c)
                    <label class="pay-country">
                        <input type="checkbox" name="countries[]" value="{{ $code }}"
                               @checked($unrestricted || in_array($code, $allowed ?? [], true))>
                        <span>{{ $c['flag'] }}</span>
                        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $c['name'] }}</span>
                        <span class="pay-country__cur">{{ $c['currency'] }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Tax ────────────────────────────────────────────────────── --}}
    <div class="pay-section intro-y">
        <div class="pay-section__head">
            <i data-lucide="percent" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="pay-section__title">Tax</div>
        </div>

        <p class="pay-section__note">
            Whether the price on the pricing page is the total, or whether tax is added on top at
            checkout. This is about how you QUOTE — who calculates and remits the tax is decided by
            the provider, and is shown per option below.
        </p>

        <div class="pay-tax">
            @foreach ([
                'inclusive' => ['Prices include tax', 'The price is the total. A customer pays exactly what the page says, and the tax is carved out of it for your records.'],
                'exclusive' => ['Tax added at checkout', 'The customer pays more than the price shown. Common in the US, where sales tax is added at the till.'],
                'auto'      => ['Automatic, by country', 'Inclusive in the UK, EU and other VAT countries — where quoting a consumer an ex-tax price is not done — and exclusive elsewhere. The right answer if you sell in both.'],
            ] as $value => [$label, $blurb])
                <label class="pay-tax__opt {{ $taxMode === $value ? 'is-on' : '' }}">
                    <input type="radio" name="tax_mode" value="{{ $value }}" @checked($taxMode === $value)>
                    <span>
                        <span class="pay-tax__label">
                            {{ $label }}
                            @if ($value === 'auto')<span class="pay-fact pay-fact--info" style="margin-left:6px">Recommended</span>@endif
                        </span>
                        <span class="pay-tax__blurb">{{ $blurb }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        {{--
            Rates only where WE are the seller. A Merchant of Record computes and
            remits its own in every country it sells into; a rate entered against
            one of those would sit here looking authoritative and never be used.
        --}}
        <div class="pay-section__head" style="margin:24px 0 6px">
            <i data-lucide="landmark" class="w-4 h-4" style="color:#6366f1"></i>
            <div class="pay-section__title" style="font-size:14px">Rates you charge yourself</div>
        </div>

        <p class="pay-section__note">
            Only for countries where you are the seller of record. Everywhere else the payment
            provider works the tax out, applies it and files the return — so there is nothing to set,
            and setting one would charge the customer twice.
        </p>

        @forelse ($taxSelfServed as $code => $c)
            <div class="pay-rate">
                <span class="pay-rate__flag">{{ \App\Support\Payments::flag($code) }}</span>
                <span class="pay-rate__name">
                    {{ $c['name'] }}
                    <span class="pay-rate__via">you are the seller · settled by {{ $c['gateway'] }}</span>
                </span>
                <span class="pay-rate__field">
                    <input type="number" step="0.01" min="0" max="100"
                           name="tax_rates[{{ $code }}]"
                           value="{{ $taxRates[$code] ?? '' }}" placeholder="0">
                    <span>%</span>
                </span>
            </div>
        @empty
            <div style="font-size:12.5px;color:#94a3b8;padding:10px 0">
                No country is currently served by a provider where you are the seller of record.
            </div>
        @endforelse
    </div>

    <div style="margin-top:20px;display:flex;gap:10px">
        <button type="submit" class="bl-btn bl-btn--primary">
            <i data-lucide="save" class="w-4 h-4"></i> Save payment settings
        </button>
    </div>
</form>

<script>
    (function () {
        var restrict = document.getElementById('pay-restrict');
        var box      = document.getElementById('pay-country-box');
        var count    = document.getElementById('pay-count');
        if (!restrict || !box) return;

        var boxes = function () { return box.querySelectorAll('input[name="countries[]"]'); };

        function tally() {
            var on = 0;
            boxes().forEach(function (b) { if (b.checked) on++; });
            count.textContent = on + ' of ' + boxes().length + ' selected';
        }

        restrict.addEventListener('change', function () {
            box.style.display = restrict.checked ? '' : 'none';
        });

        document.querySelector('[data-pay-all]').addEventListener('click', function () {
            boxes().forEach(function (b) { b.checked = true; });
            tally();
        });

        document.querySelector('[data-pay-none]').addEventListener('click', function () {
            boxes().forEach(function (b) { b.checked = false; });
            tally();
        });

        box.addEventListener('change', tally);
        tally();

        // The chosen tax mode is highlighted, so the card and the radio never
        // disagree about which one is selected.
        document.querySelectorAll('input[name="tax_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('.pay-tax__opt').forEach(function (opt) {
                    opt.classList.toggle('is-on', opt.contains(radio) && radio.checked);
                });
            });
        });
    })();
</script>
@endsection
