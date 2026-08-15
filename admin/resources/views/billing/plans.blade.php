@extends('layouts.master')

@section('content')
@php
    $intervals = $pricing['intervals'];
    $selected  = $pricing['default_interval'];
    $cards     = collect($pricing['plans'])->reject(fn ($p) => $p['is_enterprise'] || $p['is_free'])->values();
    $ent       = collect($pricing['plans'])->firstWhere('is_enterprise', true);

    $currentSlug     = $currentPlan?->slug;
    $currentInterval = $currentPrice?->interval;

    $bestSave = 0;
    foreach ($cards as $c) {
        foreach ($c['prices'] as $k => $p) {
            if ($k !== 'monthly') $bestSave = max($bestSave, (int) $p['savings_percent']);
        }
    }
@endphp

@include('billing._styles')

<style>
    .pk-head { text-align:center; max-width:620px; margin:0 auto 30px; }
    .pk-head h1 { font-size:29px; font-weight:800; letter-spacing:-.025em; color:#0b1220; margin:0 0 9px; }
    .pk-head p { font-size:14.5px; color:#667085; margin:0; line-height:1.65; }

    /* Segmented control with a sliding pill — reads as one control rather
       than two buttons that happen to sit together. */
    .pk-seg {
        position:relative; display:inline-flex; padding:5px; background:#f2f4f7;
        border:1px solid #e6eaf2; border-radius:999px; margin:0 auto;
    }
    .pk-seg__thumb {
        position:absolute; top:5px; bottom:5px; border-radius:999px; background:#fff;
        box-shadow:0 1px 3px rgba(16,24,40,.14); transition:left .22s cubic-bezier(.4,0,.2,1), width .22s cubic-bezier(.4,0,.2,1);
    }
    .pk-seg a {
        position:relative; z-index:1; display:inline-flex; align-items:center; gap:8px;
        font-size:13.5px; font-weight:650; color:#667085; text-decoration:none;
        padding:9px 22px; border-radius:999px; transition:color .18s; white-space:nowrap;
    }
    .pk-seg a.is-on { color:#0b1220; }
    .pk-seg__save {
        font-size:10.5px; font-weight:800; letter-spacing:.02em; padding:3px 8px;
        border-radius:999px; background:#ecfdf3; color:#067647; border:1px solid #abefc6;
    }

    /* ── Cards ── */
    .pk-grid {
        display:grid; gap:20px; margin-top:34px; align-items:stretch;
        grid-template-columns:repeat(auto-fit,minmax(266px,1fr));
    }
    .pk-card {
        position:relative; display:flex; flex-direction:column; background:#fff;
        border:1px solid #e6eaf2; border-radius:18px; padding:30px 26px 26px;
        transition:border-color .18s, box-shadow .18s, transform .18s;
        box-shadow:0 1px 2px rgba(16,24,40,.04);
    }
    .pk-card:hover { transform:translateY(-3px); box-shadow:0 16px 38px -24px rgba(16,24,40,.42); border-color:#d6dbe7; }

    /* The recommended plan: a taller card with a ribbon, not a louder colour. */
    .pk-card--featured {
        border-color:#c7d2fe; box-shadow:0 1px 2px rgba(16,24,40,.04), 0 22px 46px -30px rgba(99,102,241,.55);
    }
    .pk-card--featured::before {
        content:''; position:absolute; inset:0; border-radius:18px; pointer-events:none;
        background:linear-gradient(180deg,rgba(99,102,241,.055),transparent 34%);
    }
    .pk-card--current { border-color:#a5b4fc; background:#fbfaff; }

    .pk-ribbon {
        position:absolute; top:0; left:50%; transform:translate(-50%,-50%);
        font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;
        padding:6px 15px; border-radius:999px; color:#fff; white-space:nowrap;
        background:linear-gradient(135deg,#6366f1,#8b5cf6);
        box-shadow:0 6px 16px -6px rgba(99,102,241,.8);
    }
    .pk-ribbon--current {
        background:#0b1220; box-shadow:0 6px 16px -6px rgba(11,18,32,.6);
        display:inline-flex; align-items:center; gap:6px;
    }

    .pk-name { font-size:17px; font-weight:800; color:#0b1220; letter-spacing:-.01em; }
    .pk-tag { font-size:12.5px; color:#667085; line-height:1.55; margin:6px 0 22px; min-height:38px; }

    .pk-price { display:flex; align-items:baseline; gap:3px; }
    .pk-price__amt {
        font-size:40px; font-weight:800; letter-spacing:-.035em; color:#0b1220; line-height:1;
        font-variant-numeric:tabular-nums;
    }
    .pk-price__per { font-size:13px; font-weight:600; color:#8b93a7; }
    .pk-price__sub { font-size:12px; color:#8b93a7; margin-top:8px; min-height:17px; }
    .pk-price__local { font-size:12px; color:#6366f1; margin-top:3px; font-variant-numeric:tabular-nums; }
    .pk-price__save {
        display:inline-block; margin-top:10px; font-size:11px; font-weight:750;
        padding:3px 9px; border-radius:999px; background:#ecfdf3; color:#067647; border:1px solid #abefc6;
    }
    .pk-block[hidden] { display:none !important; }

    .pk-cta { margin:22px 0 20px; }
    .pk-cta .bl-btn { width:100%; padding:11px 16px; font-size:13.5px; }

    .pk-feats { list-style:none; margin:0; padding:0; }
    .pk-feats__lead {
        font-size:11.5px; font-weight:700; color:#0b1220; margin:0 0 12px;
        padding-bottom:12px; border-bottom:1px solid #eef1f6;
    }
    .pk-feats li { display:flex; gap:9px; font-size:12.5px; color:#475467; margin-bottom:9px; line-height:1.5; }
    .pk-feats li i { color:#12b76a; flex:none; margin-top:2px; }

    /* ── Enterprise ── */
    .pk-addons {
        margin-top:34px; border:1px solid #e7e9f0; border-radius:16px;
        background:#fbfbfe; padding:22px 24px;
    }
    .pk-addons__head h3 { margin:0 0 5px; font-size:16px; font-weight:800; color:#0b1220; letter-spacing:-.01em; }
    .pk-addons__head p { margin:0 0 16px; font-size:13px; color:#667085; line-height:1.6; max-width:620px; }
    .pk-addons__grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(270px,1fr)); }
    .pk-addon {
        display:flex; align-items:center; gap:14px; flex-wrap:wrap;
        border:1px solid #e7e9f0; border-radius:12px; background:#fff; padding:13px 15px;
    }
    .pk-addon__name { font-size:13.5px; font-weight:700; color:#0b1220; }
    .pk-addon__meta { font-size:12px; color:#667085; margin-top:3px; }
    .pk-addon__meta strong { color:#0b1220; font-weight:700; }
    .pk-addon__form { display:flex; gap:8px; align-items:center; }
    .pk-addon__form input {
        width:70px; text-align:center; border:1px solid #e2e8f0; border-radius:9px;
        padding:7px 6px; font-size:13px; background:#fff; color:#0b1220;
    }
    .pk-addon__hint { font-size:12px; color:#98a2b3; }
    .pk-addons__note { margin:14px 0 0; font-size:11.5px; color:#98a2b3; line-height:1.6; }

    html.dark .pk-addons { background:#0f172a; border-color:#334155; }
    html.dark .pk-addons__head h3 { color:#f8fafc; }
    html.dark .pk-addon { background:#1e293b; border-color:#334155; }
    html.dark .pk-addon__name, html.dark .pk-addon__meta strong { color:#f8fafc; }
    html.dark .pk-addon__form input { background:#0f172a; border-color:#334155; color:#f8fafc; }

    .pk-ent {
        margin-top:22px; display:flex; gap:22px; flex-wrap:wrap; align-items:center;
        background:#0b1220; border-radius:18px; padding:26px 30px; color:#fff;
        background-image:radial-gradient(circle at 88% -20%,rgba(99,102,241,.4),transparent 55%);
    }
    .pk-ent h3 { margin:0 0 6px; font-size:17px; font-weight:800; }
    .pk-ent p { margin:0; font-size:13.5px; color:#a8b0c2; max-width:600px; line-height:1.6; }
    .pk-ent .bl-btn { background:#fff; color:#0b1220; border-color:#fff; }

    .pk-foot { text-align:center; font-size:12px; color:#98a2b3; margin-top:26px; line-height:1.7; }

    html.dark .pk-head h1 { color:#f8fafc; }
    html.dark .pk-card { background:#1e293b; border-color:#334155; }
    html.dark .pk-card--current { background:#1a1a3a; border-color:#4f46e5; }
    html.dark .pk-name, html.dark .pk-price__amt { color:#f8fafc; }
    html.dark .pk-feats li { color:#94a3b8; }
    html.dark .pk-feats__lead { color:#e2e8f0; border-color:#334155; }
    html.dark .pk-seg { background:#0f172a; border-color:#334155; }
    html.dark .pk-seg__thumb { background:#1e293b; }
    html.dark .pk-seg a.is-on { color:#f8fafc; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-6">
    <a href="{{ route('billing.index', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to billing
    </a>
</div>

@if (session('error'))
    <div class="bl-alert bl-alert--err">
        <i data-lucide="alert-octagon" class="w-5 h-5" style="flex:none"></i>
        <div>{{ session('error') }}</div>
    </div>
@endif

<div class="pk-head intro-y">
    <h1>{{ $currentPlan ? 'Change your plan' : 'Choose your plan' }}</h1>
    <p>
        @if ($currentPlan)
            You’re on <strong style="color:#0b1220">{{ $currentPlan->name }}</strong>. Upgrades apply
            straight away and the difference is prorated on your next invoice — never a surprise charge today.
        @else
            Every plan includes voice cloning, 13 languages and automatic lead capture.
            You only pay more for volume and the power features.
        @endif
    </p>
</div>

@if (count($intervals) > 1)
    <div class="intro-y" style="display:flex;justify-content:center;margin-bottom:4px">
        <div class="pk-seg" id="pk-seg">
            <span class="pk-seg__thumb" id="pk-thumb"></span>
            @foreach ($intervals as $iv)
                <a href="{{ request()->fullUrlWithQuery(['billing' => $iv['key']]) }}"
                   data-pk="{{ $iv['key'] }}"
                   class="js-pk-tab {{ $iv['key'] === $selected ? 'is-on' : '' }}">
                    {{ $iv['label'] }}
                    @if ($iv['key'] !== 'monthly' && $bestSave > 0)
                        <span class="pk-seg__save">Save {{ $bestSave }}%</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif

<div class="pk-grid">
    @foreach ($cards as $i => $planCard)
        @php
            $isCurrentPlan = $planCard['slug'] === $currentSlug;
            $previous      = $i > 0 ? $cards[$i - 1]['name'] : null;
        @endphp

        <div class="pk-card intro-y {{ $isCurrentPlan ? 'pk-card--current' : ($planCard['is_featured'] ? 'pk-card--featured' : '') }}">
            @if ($isCurrentPlan)
                <span class="pk-ribbon pk-ribbon--current">
                    <i data-lucide="check" class="w-3 h-3"></i> Your plan
                </span>
            @elseif ($planCard['badge'])
                <span class="pk-ribbon">{{ $planCard['badge'] }}</span>
            @endif

            <div class="pk-name">{{ $planCard['name'] }}</div>
            <p class="pk-tag">{{ $planCard['tagline'] }}</p>

            @foreach ($planCard['prices'] as $key => $price)
                <div class="pk-block" data-pk="{{ $key }}" @if($key !== $selected) hidden @endif>
                    <div class="pk-price">
                        <span class="pk-price__amt">{{ $price['usd'] }}</span>
                        <span class="pk-price__per">/{{ $price['months'] > 1 ? 'yr' : 'mo' }}</span>
                    </div>

                    <div class="pk-price__sub">
                        @if ($price['months'] > 1)
                            {{ $price['effective_monthly'] }} per month, billed {{ $price['interval'] }}
                        @else
                            billed monthly
                        @endif
                    </div>

                    @if ($price['local'])
                        <div class="pk-price__local">≈ {{ $price['local'] }}</div>
                    @endif

                    @if ($price['savings_label'])
                        <span class="pk-price__save">{{ $price['savings_label'] }}</span>
                    @endif

                    @php
                        $sameExact = $isCurrentPlan && $currentInterval === $key;
                        $isUpgrade = $currentPrice && $price['usd_cents'] > $currentPrice->unit_amount;
                    @endphp

                    <div class="pk-cta">
                        @if ($sameExact)
                            <button type="button" class="bl-btn bl-btn--ghost" disabled>
                                <i data-lucide="check" class="w-4 h-4"></i> Current plan
                            </button>
                        @elseif (! $checkoutOpen)
                            <button type="button" class="bl-btn bl-btn--ghost" disabled>Not available yet</button>
                        @else
                            <a class="bl-btn {{ $planCard['is_featured'] || $isUpgrade ? 'bl-btn--primary' : 'bl-btn--ghost' }}"
                               href="{{ route('billing.checkout', ['client' => $client->slug, 'plan' => $planCard['slug'], 'interval' => $key]) }}">
                                @if (! $currentPrice)
                                    Get {{ $planCard['name'] }}
                                @elseif ($isUpgrade)
                                    <i data-lucide="arrow-up-circle" class="w-4 h-4"></i> Upgrade
                                @else
                                    Switch to {{ $planCard['name'] }}
                                @endif
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach

            {{-- "Everything in X, plus" — the standard tiering cue. It stops
                 every card repeating the same baseline features and makes the
                 ladder legible at a glance. --}}
            <ul class="pk-feats">
                <li class="pk-feats__lead" style="display:block">
                    {{ $previous ? 'Everything in ' . $previous . ', plus:' : 'Includes:' }}
                </li>
                @foreach (($planCard['highlights'] ?: []) as $item)
                    <li><i data-lucide="check" class="w-3.5 h-3.5"></i><span>{{ $item['label'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>

{{-- ── Add-ons ──────────────────────────────────────────────────────
     Top up one thing instead of moving up a whole tier. Shown here as well
     as on the billing overview because this is the page people reach when
     they hit a limit. --}}
@if (! empty($addons))
    <div class="pk-addons intro-y">
        <div class="pk-addons__head">
            <div>
                <h3>Need a little more?</h3>
                <p>
                    Add extra capacity to your current plan instead of upgrading.
                    @if ($canBuyAddons)
                        Charged on your existing invoice and prorated from today.
                    @endif
                </p>
            </div>
        </div>

        <div class="pk-addons__grid">
            @foreach ($addons as $item)
                @php $addonPlan = $item['plan']; @endphp
                <div class="pk-addon">
                    <div style="flex:1;min-width:0">
                        <div class="pk-addon__name">{{ $addonPlan->name }}</div>
                        <div class="pk-addon__meta">
                            <strong>{{ $item['price']->formatted() }}</strong>
                            each per {{ $item['price']->months() > 1 ? 'year' : 'month' }}
                            @if ($item['owned'] > 0)
                                <span class="bl-badge bl-badge--blue" style="margin-left:6px">
                                    {{ $item['owned'] }} active
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($canBuyAddons && $checkoutOpen)
                        {{-- Slug + quantity only; the amount is resolved
                             server-side for the subscription's interval. --}}
                        <form method="POST"
                              action="{{ route('billing.addons.update', ['client' => $client->slug]) }}"
                              class="pk-addon__form">
                            @csrf
                            <input type="hidden" name="addon" value="{{ $addonPlan->slug }}">
                            <input type="number" name="quantity" min="0" max="999"
                                   value="{{ $item['owned'] }}" aria-label="Quantity">
                            <button type="submit" class="bl-btn bl-btn--ghost bl-btn--sm">
                                {{ $item['owned'] > 0 ? 'Update' : 'Add' }}
                            </button>
                        </form>
                    @else
                        <span class="pk-addon__hint">
                            {{ $canBuyAddons ? 'Available soon' : 'Choose a plan first' }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($canBuyAddons && $checkoutOpen)
            <p class="pk-addons__note">
                Set a quantity to 0 to remove an add-on — your next invoice is credited for the
                unused part. Add-ons follow your plan’s billing interval.
            </p>
        @endif
    </div>
@endif

@if ($ent)
    <div class="pk-ent intro-y">
        <div style="flex:1;min-width:250px">
            <h3>{{ $ent['name'] }}</h3>
            <p>{{ $ent['tagline'] }}</p>
        </div>
        <a href="{{ $ent['cta_url'] ?: url('/contact') }}" class="bl-btn">{{ $ent['cta_label'] }} →</a>
    </div>
@endif

<p class="pk-foot">
    All plans are charged in USD.
    @if ($pricing['has_local']) Local amounts are approximate, for reference only. @endif
    <br>
    Cancel any time — you keep access until the end of the period you’ve paid for.
</p>

<script>
(function () {
    var tabs  = Array.prototype.slice.call(document.querySelectorAll('.js-pk-tab'));
    var thumb = document.getElementById('pk-thumb');
    if (!tabs.length) return;

    // Park the sliding pill under whichever tab is active.
    function moveThumb(tab) {
        if (!thumb) return;
        thumb.style.left  = tab.offsetLeft + 'px';
        thumb.style.width = tab.offsetWidth + 'px';
    }

    function select(key) {
        document.querySelectorAll('.pk-block').forEach(function (b) {
            b.hidden = b.dataset.pk !== key;
        });
        tabs.forEach(function (t) {
            var on = t.dataset.pk === key;
            t.classList.toggle('is-on', on);
            if (on) moveThumb(t);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) { e.preventDefault(); select(tab.dataset.pk); });
    });

    // Initial position, and again after webfonts settle the widths.
    var active = document.querySelector('.js-pk-tab.is-on') || tabs[0];
    moveThumb(active);
    window.addEventListener('load', function () { moveThumb(document.querySelector('.js-pk-tab.is-on') || tabs[0]); });
    window.addEventListener('resize', function () { moveThumb(document.querySelector('.js-pk-tab.is-on') || tabs[0]); });
})();
</script>
@endsection
