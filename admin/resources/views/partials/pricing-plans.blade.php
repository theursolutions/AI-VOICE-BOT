{{--
    Plans section — the whole thing, self-contained.

    Included by BOTH the homepage (#pricing) and /pricing, so the two can
    never disagree about what a plan costs or contains. Everything comes from
    the database via App\Services\Billing\PricingPresenter; there are no
    hardcoded prices, plan names, limits or features anywhere in this file.

    $pricing is supplied by the view composer in AppServiceProvider, so this
    renders wherever it is included without the route having to know about it.

    Styling deliberately reuses the homepage design tokens (--panel, --line,
    --neon, --text-dim …) which are defined by both the homepage <style> block
    and layouts/public, so the section inherits whichever page hosts it.

    SECURITY NOTE: the checkout form posts ONLY `plan` (a slug) and `interval`
    (a name). No amount, currency or converted local figure leaves the browser
    — the server resolves the price. Nothing here is worth tampering with.
--}}
@php
    $pricing ??= null;
@endphp

@if ($pricing && ! empty($pricing['plans']))
@php
    $intervals = $pricing['intervals'];
    $selected  = $pricing['default_interval'];
    $cards     = collect($pricing['plans']);
    $paid      = $cards->reject(fn ($p) => $p['is_enterprise']);
    $ent       = $cards->firstWhere('is_enterprise', true);
    $geo       = $pricing['geo'];
    $hasLocal  = $pricing['has_local'];

    // Master switch (config/billing.php → checkout.enabled). While false the
    // cards are INFORMATION ONLY: every price, limit and feature still renders,
    // but paid plans get no call to action and the checkout endpoints refuse.
    // Free signup and the Enterprise contact link are unaffected — neither
    // takes money.
    $canBuy = (bool) config('billing.checkout.enabled', false);

    // Best saving across all plans, for the toggle badge.
    $bestSave = 0;
    foreach ($paid as $p) {
        foreach ($p['prices'] as $key => $pr) {
            if ($key !== 'monthly') {
                $bestSave = max($bestSave, (int) $pr['savings_percent']);
            }
        }
    }
@endphp

<style>
    /* ── Billing interval toggle ── */
    .pp-toggle-wrap { display:flex; justify-content:center; margin: 0 0 14px; }
    .pp-toggle {
        display:inline-flex; gap:4px; padding:4px;
        background: var(--panel-2, rgba(20,28,46,.85));
        border:1px solid var(--line, rgba(120,180,220,.12));
        border-radius:999px;
    }
    .pp-toggle a {
        cursor:pointer; text-decoration:none;
        color: var(--text-dim, #8b96a8);
        font-size:13.5px; font-weight:600;
        padding:9px 20px; border-radius:999px;
        display:inline-flex; align-items:center; gap:8px;
        transition: background .15s, color .15s;
    }
    .pp-toggle a:hover { color: var(--text, #e6edf3); }
    .pp-toggle a.is-active {
        background: var(--neon-btn, #2563eb); color:#fff;
        box-shadow:0 0 20px rgba(59,130,246,.4);
    }
    .pp-save {
        font-size:11px; font-weight:700; letter-spacing:.04em;
        padding:2px 7px; border-radius:999px;
        background: rgba(34,197,94,.16); color:#4ade80;
        border:1px solid rgba(34,197,94,.3);
    }
    .pp-toggle a.is-active .pp-save { background: rgba(255,255,255,.18); color:#fff; border-color:transparent; }

    .pp-usd-note {
        text-align:center; font-size:12.5px; color: var(--text-dim2, #727e93);
        margin:0 auto 44px; max-width:600px; line-height:1.6;
    }
    .pp-usd-note strong { color: var(--text-dim, #8b96a8); font-weight:600; }

    /* ── Cards ── */
    .pp-grid {
        display:grid; gap:18px; align-items:stretch;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    }
    @media (min-width: 1180px) { .pp-grid { grid-template-columns: repeat(4, 1fr); } }

    .pp-card {
        position:relative; display:flex; flex-direction:column;
        background: var(--panel, rgba(15,21,35,.55));
        border:1px solid var(--line, rgba(120,180,220,.12));
        border-radius:18px; padding:28px 22px 24px;
        backdrop-filter: blur(8px);
        transition: transform .2s, border-color .2s, box-shadow .2s;
    }
    .pp-card:hover { transform: translateY(-4px); border-color: var(--line-hot, rgba(59,130,246,.35)); }
    .pp-card--featured {
        border-color: var(--line-hot, rgba(59,130,246,.35));
        background: linear-gradient(165deg, rgba(59,130,246,.10), var(--panel, rgba(15,21,35,.55)) 48%);
        box-shadow:0 0 44px rgba(59,130,246,.16);
    }
    .pp-badge {
        position:absolute; top:-11px; left:50%; transform:translateX(-50%);
        background: var(--neon-btn, #2563eb); color:#fff;
        font-size:10.5px; font-weight:800; letter-spacing:.09em; text-transform:uppercase;
        padding:5px 14px; border-radius:999px; white-space:nowrap;
        box-shadow:0 0 22px rgba(59,130,246,.5);
    }

    .pp-name { font-size:19px; font-weight:800; color: var(--text, #e6edf3); margin:2px 0 5px; }
    .pp-tagline {
        font-size:12.5px; color: var(--text-dim, #8b96a8);
        margin:0 0 20px; line-height:1.55; min-height:40px;
    }

    .pp-price { display:flex; align-items:baseline; gap:5px; flex-wrap:wrap; }
    .pp-amount { font-size:40px; font-weight:800; letter-spacing:-.03em; line-height:1; color: var(--text, #e6edf3); }
    .pp-suffix { font-size:14px; font-weight:600; color: var(--text-dim, #8b96a8); }
    .pp-local {
        font-size:12.5px; color: var(--neon-2, #60a5fa); margin:8px 0 0;
        font-variant-numeric: tabular-nums;
    }
    .pp-eff { font-size:11.5px; color: var(--text-dim2, #727e93); margin:4px 0 0; }
    .pp-savepill {
        display:inline-block; margin:10px 0 0;
        font-size:11.5px; font-weight:700;
        background: rgba(34,197,94,.14); color:#4ade80;
        border:1px solid rgba(34,197,94,.28);
        padding:3px 10px; border-radius:999px;
    }
    .pp-priceblock[hidden] { display:none !important; }

    .pp-cta { margin:22px 0 20px; }
    .pp-btn {
        width:100%; display:inline-flex; align-items:center; justify-content:center; gap:8px;
        cursor:pointer; text-decoration:none; font:inherit;
        background: var(--neon-btn, #2563eb); color:#fff;
        border:0; border-radius:12px; padding:13px 20px;
        font-size:14px; font-weight:700;
        box-shadow:0 0 30px rgba(59,130,246,.4);
        transition: transform .15s, box-shadow .15s;
    }
    .pp-btn:hover { transform: translateY(-2px); box-shadow:0 0 40px rgba(59,130,246,.6); }
    .pp-btn--ghost {
        background:transparent; color: var(--text, #e6edf3);
        border:1px solid var(--line-hot, rgba(59,130,246,.35)); box-shadow:none;
    }
    .pp-btn--ghost:hover { background: rgba(59,130,246,.08); box-shadow:none; }

    /* ── Feature list ── */
    .pp-feats { margin:0; padding:0; list-style:none; }
    .pp-feats__group {
        font-size:10px; font-weight:800; letter-spacing:.1em; text-transform:uppercase;
        color: var(--neon-2, #60a5fa); margin:16px 0 9px; padding-top:14px;
        border-top:1px solid var(--line, rgba(120,180,220,.12));
    }
    .pp-feats__group:first-child { margin-top:0; padding-top:0; border-top:0; }
    .pp-feats li {
        display:flex; gap:9px; align-items:flex-start;
        font-size:13px; color: var(--text-dim, #8b96a8);
        margin:0 0 9px; line-height:1.5;
    }
    .pp-feats .pp-tick { color:#4ade80; flex:none; margin-top:1px; font-size:12px; }
    .pp-feats b { color: var(--text, #e6edf3); font-weight:600; }

    /* ── Enterprise band ── */
    .pp-ent {
        margin:20px 0 0; display:flex; gap:22px; flex-wrap:wrap;
        align-items:center; justify-content:space-between;
        border:1px solid var(--line, rgba(120,180,220,.12));
        background: var(--panel, rgba(15,21,35,.55));
        border-radius:18px; padding:26px 28px;
    }
    .pp-ent h3 { margin:0 0 6px; font-size:18px; font-weight:800; color: var(--text, #e6edf3); }
    .pp-ent p { margin:0; font-size:13.5px; color: var(--text-dim, #8b96a8); max-width:640px; line-height:1.6; }
    .pp-ent .pp-btn { width:auto; }

    /* ── Comparison (collapsed by default) ── */
    .pp-compare { margin:30px 0 0; }
    .pp-compare > summary {
        cursor:pointer; list-style:none; text-align:center;
        font-size:13.5px; font-weight:700; color: var(--neon-2, #60a5fa);
        padding:14px; border:1px solid var(--line, rgba(120,180,220,.12));
        border-radius:12px; background: var(--panel, rgba(15,21,35,.55));
        transition: border-color .15s;
    }
    .pp-compare > summary::-webkit-details-marker { display:none; }
    .pp-compare > summary:hover { border-color: var(--line-hot, rgba(59,130,246,.35)); }
    .pp-compare > summary::after { content:' ↓'; }
    .pp-compare[open] > summary::after { content:' ↑'; }
    .pp-compare__scroll { overflow-x:auto; margin-top:16px; -webkit-overflow-scrolling:touch; }
    .pp-table { width:100%; border-collapse:collapse; font-size:13px; min-width:660px; }
    .pp-table th, .pp-table td {
        padding:11px 14px; text-align:left; vertical-align:top;
        border-bottom:1px solid var(--line, rgba(120,180,220,.12));
        color: var(--text-dim, #8b96a8);
    }
    .pp-table thead th {
        color: var(--text, #e6edf3); font-weight:700; font-size:12.5px;
        position:sticky; top:0; background: var(--bg-2, #0a0d14); z-index:1;
    }
    .pp-table td:not(:first-child), .pp-table thead th:not(:first-child) { text-align:center; }
    .pp-table tbody th { color: var(--text, #e6edf3); font-weight:600; }
    .pp-table .pp-grp td {
        color: var(--neon-2, #60a5fa); font-weight:800; font-size:11px;
        letter-spacing:.1em; text-transform:uppercase;
        background: rgba(59,130,246,.05);
        border-bottom-color: var(--line-hot, rgba(59,130,246,.35));
    }
    .pp-table .pp-yes { color:#4ade80; font-weight:700; }
    .pp-table .pp-no  { color: var(--text-dim2, #727e93); }

    @media (max-width: 540px) {
        .pp-card { padding:24px 18px 20px; }
        .pp-amount { font-size:34px; }
        .pp-ent { padding:22px 20px; }
    }
</style>

{{-- ── Interval toggle ─────────────────────────────────────────────── --}}
@if (count($intervals) > 1)
    <div class="pp-toggle-wrap reveal">
        <div class="pp-toggle" role="group" aria-label="Billing interval">
            @foreach ($intervals as $iv)
                {{-- A real link, not just a button: works with JS disabled, is
                     shareable, and the server renders whichever is chosen. --}}
                <a href="{{ request()->fullUrlWithQuery(['billing' => $iv['key']]) }}#pricing"
                   data-pp-interval="{{ $iv['key'] }}"
                   class="pp-interval {{ $iv['key'] === $selected ? 'is-active' : '' }}"
                   aria-pressed="{{ $iv['key'] === $selected ? 'true' : 'false' }}">
                    {{ $iv['label'] }}
                    @if ($iv['key'] !== 'monthly' && $bestSave > 0)
                        <span class="pp-save">Save {{ $bestSave }}%</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif

<p class="pp-usd-note reveal">
    <strong>All plans are charged in USD.</strong>
    @if ($hasLocal && $geo)
        Amounts shown in {{ $geo['currency'] }} are approximate and for reference only —
        your card is charged the US dollar amount.
    @endif
</p>

{{-- ── Cards ───────────────────────────────────────────────────────── --}}
<div class="pp-grid">
    @foreach ($paid as $plan)
        <div class="pp-card reveal {{ $plan['is_featured'] ? 'pp-card--featured' : '' }}">
            @if ($plan['badge'])
                <div class="pp-badge">{{ $plan['badge'] }}</div>
            @endif

            <div class="pp-name">{{ $plan['name'] }}</div>
            <p class="pp-tagline">{{ $plan['tagline'] }}</p>

            {{-- Price --}}
            @if ($plan['is_free'])
                <div class="pp-price">
                    <span class="pp-amount">$0</span>
                    <span class="pp-suffix">for {{ $plan['free_days'] ?? 7 }} days</span>
                </div>
                <p class="pp-eff">No credit card required</p>
            @elseif (! empty($plan['prices']))
                @foreach ($plan['prices'] as $key => $price)
                    <div class="pp-priceblock" data-pp-interval="{{ $key }}" @if($key !== $selected) hidden @endif>
                        <div class="pp-price">
                            <span class="pp-amount">{{ $price['usd'] }}</span>
                            <span class="pp-suffix">{{ $price['suffix'] }}</span>
                        </div>

                        @if ($price['local'])
                            <p class="pp-local">≈ {{ $price['local'] }} {{ $price['suffix'] }}</p>
                        @endif

                        @if ($price['months'] > 1)
                            {{-- The interval KEY is already the adverb we want
                                 ("annually", "quarterly"); the label is the
                                 noun ("Annual") and reads wrong after "billed". --}}
                            <p class="pp-eff">
                                {{ $price['effective_monthly'] }}/mo billed {{ $price['interval'] }}
                            </p>
                        @endif

                        @if ($price['savings_label'])
                            <span class="pp-savepill">{{ $price['savings_label'] }}</span>
                        @endif
                    </div>
                @endforeach
            @else
                <div class="pp-price"><span class="pp-suffix">Pricing coming soon</span></div>
            @endif

            {{-- CTA.
                 While checkout is switched off (config/billing.php →
                 checkout.enabled) a paid card gets NO call to action at all —
                 no button, no placeholder, not even the wrapper, so the card
                 closes up cleanly instead of leaving a gap. The plan is pure
                 information until billing goes live.

                 Free signup and the Enterprise contact link are unaffected:
                 neither takes money, and they're the marketing site's main
                 conversion paths. --}}
            @php
                $paidCta = $plan['purchasable'] && ! empty($plan['prices']) && $canBuy;
                $freeCta = $plan['is_free'];
                $linkCta = ! $plan['is_free'] && ! ($plan['purchasable'] && ! empty($plan['prices']));
            @endphp

            @if ($freeCta || $paidCta || $linkCta)
                <div class="pp-cta">
                    @if ($freeCta)
                        <a href="{{ url('/register') }}" class="pp-btn pp-btn--ghost">{{ $plan['cta_label'] }}</a>
                    @elseif ($paidCta)
                        <form method="POST" action="{{ route('pricing.checkout') }}">
                            @csrf
                            <input type="hidden" name="plan" value="{{ $plan['slug'] }}">
                            <input type="hidden" name="interval" value="{{ $selected }}" class="pp-interval-field">
                            <button type="submit" class="pp-btn">{{ $plan['cta_label'] }}</button>
                        </form>
                    @else
                        <a href="{{ $plan['cta_url'] ?: url('/contact') }}" class="pp-btn pp-btn--ghost">{{ $plan['cta_label'] }}</a>
                    @endif
                </div>
            @else
                {{-- Keeps the feature lists aligned across cards when one has a
                     button and another doesn't. --}}
                <div style="margin-top:22px"></div>
            @endif

            {{-- Everything the plan includes, grouped. --}}
            <ul class="pp-feats">
                @forelse ($plan['included'] as $group => $items)
                    <li class="pp-feats__group" style="display:block">{{ $group }}</li>
                    @foreach ($items as $item)
                        <li @if($item['note']) title="{{ $item['note'] }}" @endif>
                            <span class="pp-tick" aria-hidden="true">✓</span>
                            <span>{{ $item['label'] }}</span>
                        </li>
                    @endforeach
                @empty
                    @foreach ($plan['highlights'] as $item)
                        <li>
                            <span class="pp-tick" aria-hidden="true">✓</span>
                            <span>@if($item['highlighted'])<b>{{ $item['label'] }}</b>@else{{ $item['label'] }}@endif</span>
                        </li>
                    @endforeach
                @endforelse
            </ul>
        </div>
    @endforeach
</div>

{{-- ── Enterprise: a CTA band, not a price card ─────────────────────── --}}
@if ($ent)
    <div class="pp-ent reveal">
        <div>
            <h3>{{ $ent['name'] }}</h3>
            <p>{{ $ent['tagline'] ?: 'Unlimited projects, SSO, dedicated infrastructure, custom SLA and a named success manager.' }}</p>
        </div>
        <a href="{{ $ent['cta_url'] ?: url('/contact') }}" class="pp-btn">{{ $ent['cta_label'] }} →</a>
    </div>
@endif

{{-- ── Full comparison, collapsed ───────────────────────────────────── --}}
@if (! empty($pricing['comparison']))
    <details class="pp-compare reveal">
        <summary>Compare every feature across all plans</summary>

        <div class="pp-compare__scroll">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th scope="col">Feature</th>
                        @foreach ($paid as $plan)
                            <th scope="col">{{ $plan['name'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pricing['comparison'] as $group => $rows)
                        <tr class="pp-grp"><td colspan="{{ 1 + $paid->count() }}">{{ $group }}</td></tr>
                        @foreach ($rows as $row)
                            <tr>
                                <th scope="row">{{ $row['feature']->name }}</th>
                                @foreach ($paid as $plan)
                                    @php $val = $row['values'][$plan['id']] ?? '—'; @endphp
                                    <td class="{{ $val === '✓' ? 'pp-yes' : ($val === '—' ? 'pp-no' : '') }}">{{ $val }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
@endif

<script>
/**
 * Interval toggle — progressive enhancement only.
 *
 * The server has already rendered the interval from ?billing=, and each tab is
 * a real link, so this section works fully with JS disabled. With JS, switching
 * is instant and every checkout form's hidden `interval` field is kept in sync.
 *
 * Note what this does NOT do: it never computes or submits a price. The forms
 * carry a plan slug and an interval name; the server resolves the amount and
 * the Stripe price.
 */
(function () {
    var tabs = document.querySelectorAll('.pp-interval');
    if (!tabs.length) return;

    function select(interval) {
        document.querySelectorAll('.pp-priceblock').forEach(function (block) {
            block.hidden = block.dataset.ppInterval !== interval;
        });
        tabs.forEach(function (tab) {
            var on = tab.dataset.ppInterval === interval;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        document.querySelectorAll('.pp-interval-field').forEach(function (field) {
            field.value = interval;
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            select(tab.dataset.ppInterval);
        });
    });
})();
</script>
@endif
