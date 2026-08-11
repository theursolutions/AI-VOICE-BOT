@extends('layouts.ops')

@section('content')
<style>
    .pl-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; margin-bottom:16px; }
    .pl-head { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; }
    .pl-name { font-size:17px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:9px; }
    .pl-slug { font-size:11px; font-family:ui-monospace,monospace; color:#94a3b8; margin-top:2px; }
    .pl-tag  { font-size:12.5px; color:#64748b; margin-top:5px; max-width:520px; }

    .pl-pill {
        font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.07em;
        padding:3px 8px; border-radius:999px;
    }
    .pl-pill--on    { background:#dcfce7; color:#15803d; }
    .pl-pill--off   { background:#fee2e2; color:#b91c1c; }
    .pl-pill--pop   { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; }
    .pl-pill--free  { background:#dbeafe; color:#1d4ed8; }
    .pl-pill--ent   { background:#f3e8ff; color:#7e22ce; }
    .pl-pill--priv  { background:#f1f5f9; color:#475569; }

    .pl-prices { width:100%; border-collapse:collapse; font-size:13px; margin-top:16px; }
    .pl-prices th, .pl-prices td { text-align:left; padding:9px 10px; border-bottom:1px solid #f1f5f9; color:#475569; }
    .pl-prices th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .pl-prices tr.is-archived td { opacity:.55; }
    .pl-amt { font-weight:800; color:#0f172a; font-variant-numeric:tabular-nums; }
    .pl-ref { font-family:ui-monospace,monospace; font-size:10.5px; color:#64748b; }
    .pl-actions-cell { text-align:right; white-space:nowrap; }

    .pl-btn {
        display:inline-flex; align-items:center; gap:6px; cursor:pointer; text-decoration:none;
        font-size:12px; font-weight:600; padding:6px 11px; border-radius:8px;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
    }
    .pl-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; border-color:transparent; }
    .pl-btn--warn { border-color:#fde68a; color:#b45309; background:#fffbeb; }
    .pl-btn:hover { filter:brightness(1.04); }
    .pl-btn-row { display:flex; gap:8px; flex-wrap:wrap; margin-left:auto; }

    .pl-inline { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .pl-inline input[type=number], .pl-inline select {
        border:1px solid #e2e8f0; border-radius:8px; padding:6px 9px; font-size:12.5px;
        width:110px; background:#fff; color:#0f172a;
    }
    .pl-warn {
        background:#fffbeb; border:1px solid #fde68a; color:#92400e;
        border-radius:12px; padding:14px 16px; font-size:13.5px; margin-bottom:16px;
        display:flex; gap:11px;
    }
    .pl-warn--err { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .pl-warn code { font-family:ui-monospace,monospace; font-size:12px; }
    .pl-note { font-size:11.5px; color:#94a3b8; margin-top:10px; line-height:1.55; }

    html.dark .pl-card { background:#1e293b; border-color:#334155; }
    html.dark .pl-name, html.dark .pl-amt { color:#f1f5f9; }
    html.dark .pl-prices th, html.dark .pl-prices td { border-color:#334155; }
    html.dark .pl-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .pl-inline input, html.dark .pl-inline select { background:#0f172a; border-color:#334155; color:#e2e8f0; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">💳</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Plans &amp; Pricing</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Everything customers can buy. Prices, limits, features and trial lengths are all editable
                here — no developer, no deploy. Changing a price never affects existing subscribers.
            </div>
        </div>
        <div class="pl-btn-row">
            <a href="{{ route('ops.billing.features.index') }}" class="pl-btn">
                <i data-lucide="sliders" class="w-3.5 h-3.5"></i> Features &amp; limits
            </a>
            <a href="{{ route('ops.billing.subscriptions.index') }}" class="pl-btn">
                <i data-lucide="users" class="w-3.5 h-3.5"></i> Subscriptions
            </a>
            <a href="{{ route('ops.billing.plans.create') }}" class="pl-btn pl-btn--primary">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> New plan
            </a>
        </div>
    </div>

    {{-- ── Stripe configuration state ─────────────────────────────── --}}
    @if (! $stripeReady)
        <div class="pl-warn pl-warn--err mt-5">
            <i data-lucide="alert-octagon" class="w-5 h-5" style="flex:none"></i>
            <div>
                <strong>Stripe isn't configured.</strong>
                Set <code>STRIPE_KEY</code>, <code>STRIPE_SECRET</code> and <code>STRIPE_WEBHOOK_SECRET</code>
                in <code>.env</code>. You can still create plans and prices here — they just can't be sold
                until they're synced to Stripe.
            </div>
        </div>
    @else
        <div class="pl-warn mt-5" style="background:#f0f9ff;border-color:#bae6fd;color:#075985">
            <i data-lucide="info" class="w-5 h-5" style="flex:none"></i>
            <div>
                Stripe is connected in <strong>{{ $stripeLiveMode ? 'LIVE' : 'TEST' }}</strong> mode.
                @if ($unsyncedCount > 0)
                    <strong>{{ $unsyncedCount }} active price(s) have no Stripe price yet</strong> and cannot be
                    checked out.
                @endif
                <form method="POST" action="{{ route('ops.billing.plans.sync-stripe') }}" style="display:inline;margin-left:8px">
                    @csrf
                    <button type="submit" class="pl-btn">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Sync all to Stripe
                    </button>
                </form>
            </div>
        </div>
    @endif

    @if (! empty($mismatches))
        <div class="pl-warn pl-warn--err">
            <i data-lucide="alert-triangle" class="w-5 h-5" style="flex:none"></i>
            <div>
                <strong>{{ count($mismatches) }} price(s) were created in the other Stripe mode.</strong>
                Checking out against a test price with a live key fails at Stripe. Archive them and create
                fresh prices in {{ $stripeLiveMode ? 'live' : 'test' }} mode:
                @foreach ($mismatches as $m)
                    <code>{{ $m->plan?->slug }}/{{ $m->interval }}</code>@if(! $loop->last), @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── Plans ──────────────────────────────────────────────────── --}}
    @forelse ($plans as $plan)
        <div class="pl-card intro-y">
            <div class="pl-head">
                <div style="flex:1;min-width:220px">
                    <div class="pl-name">
                        {{ $plan->name }}
                        <span class="pl-pill {{ $plan->is_active ? 'pl-pill--on' : 'pl-pill--off' }}">
                            {{ $plan->is_active ? 'On sale' : 'Hidden' }}
                        </span>
                        @if ($plan->is_featured)<span class="pl-pill pl-pill--pop">{{ $plan->badge ?: 'Popular' }}</span>@endif
                        @if ($plan->type === 'free')<span class="pl-pill pl-pill--free">Free</span>@endif
                        @if ($plan->type === 'enterprise')<span class="pl-pill pl-pill--ent">Enterprise</span>@endif
                        @if (! $plan->is_public)<span class="pl-pill pl-pill--priv">Private</span>@endif
                    </div>
                    <div class="pl-slug">{{ $plan->slug }}</div>
                    @if ($plan->tagline)<div class="pl-tag">{{ $plan->tagline }}</div>@endif
                    <div class="pl-tag">
                        <strong>{{ $plan->subscriptions_count }}</strong> subscription(s)
                        @if ($plan->type === 'free')
                            · free window
                            {{ $plan->free_window_days === null ? 'permanent' : $plan->free_window_days . ' days' }}
                        @elseif ($plan->trial_days > 0)
                            · {{ $plan->trial_days }}-day trial
                            ({{ $plan->trial_requires_payment_method ? 'card required' : 'no card' }})
                        @else
                            · no trial
                        @endif
                    </div>
                </div>

                <div class="pl-btn-row">
                    <a href="{{ route('ops.billing.plans.edit', ['id' => $plan->id]) }}" class="pl-btn pl-btn--primary">
                        <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit
                    </a>
                    @unless ($plan->is_featured)
                        <form method="POST" action="{{ route('ops.billing.plans.feature', ['id' => $plan->id]) }}">
                            @csrf
                            <button type="submit" class="pl-btn"><i data-lucide="star" class="w-3.5 h-3.5"></i> Make popular</button>
                        </form>
                    @endunless
                    <form method="POST" action="{{ route('ops.billing.plans.toggle', ['id' => $plan->id]) }}"
                          onsubmit="return confirm('{{ $plan->is_active
                                ? 'Hide this plan from new signups? Existing subscribers keep their subscription.'
                                : 'Put this plan back on sale?' }}');">
                        @csrf
                        <button type="submit" class="pl-btn {{ $plan->is_active ? 'pl-btn--warn' : '' }}">
                            <i data-lucide="{{ $plan->is_active ? 'eye-off' : 'eye' }}" class="w-3.5 h-3.5"></i>
                            {{ $plan->is_active ? 'Hide' : 'Activate' }}
                        </button>
                    </form>
                </div>
            </div>

            {{-- ── Prices ─────────────────────────────────────────── --}}
            @if ($plan->type === 'free')
                <p class="pl-note">A free plan has no price. Its limits live in
                    <a href="{{ route('ops.billing.features.index') }}" style="color:#6366f1">Features &amp; limits</a>.
                </p>
            @elseif ($plan->type === 'enterprise')
                <p class="pl-note">Enterprise is rendered as a “talk to us” CTA on the pricing page, not a price card.</p>
            @else
                <table class="pl-prices">
                    <thead>
                        <tr>
                            <th>Interval</th><th>Price</th><th>Per month</th>
                            <th>Stripe price</th><th>State</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plan->prices as $price)
                            <tr class="{{ $price->archived_at ? 'is-archived' : '' }}">
                                <td>{{ $price->intervalLabel() }}</td>
                                <td class="pl-amt">{{ $price->formatted() }}</td>
                                <td>{{ $price->months() > 1 ? $price->formattedEffectiveMonthly() : '—' }}</td>
                                <td>
                                    @if ($price->stripe_price_ref)
                                        <span class="pl-ref">{{ $price->stripe_price_ref }}</span>
                                        @if ($price->isStripeModeMismatched())
                                            <span class="pl-pill pl-pill--off" style="margin-left:5px">wrong mode</span>
                                        @endif
                                    @else
                                        <span class="pl-pill pl-pill--off">not synced</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($price->is_active)
                                        <span class="pl-pill pl-pill--on">Active</span>
                                    @elseif ($price->archived_at)
                                        <span class="pl-pill pl-pill--priv">Archived</span>
                                    @else
                                        <span class="pl-pill pl-pill--off">Inactive</span>
                                    @endif
                                </td>
                                <td class="pl-actions-cell">
                                    @if ($price->is_active)
                                        {{-- Editing an amount NEVER edits this row: it creates a new
                                             price + Stripe price and archives this one, so existing
                                             subscribers are grandfathered automatically. --}}
                                        <form method="POST" action="{{ route('ops.billing.prices.update', ['id' => $plan->id, 'priceId' => $price->id]) }}"
                                              class="pl-inline" style="justify-content:flex-end"
                                              onsubmit="return confirm('Create a NEW price at this amount?\n\nExisting subscribers stay on {{ $price->formatted() }} until you migrate them. New signups pay the new amount.');">
                                            @csrf @method('PATCH')
                                            <input type="number" name="amount" step="0.01" min="0"
                                                   value="{{ number_format($price->unit_amount / 100, 2, '.', '') }}">
                                            <button type="submit" class="pl-btn">Change price</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('ops.billing.prices.activate', ['id' => $plan->id, 'priceId' => $price->id]) }}" style="display:inline">
                                            @csrf
                                            <button type="submit" class="pl-btn">Reactivate</button>
                                        </form>
                                    @endif

                                    @if (! $price->stripe_price_ref && $stripeReady)
                                        <form method="POST" action="{{ route('ops.billing.prices.sync', ['id' => $plan->id, 'priceId' => $price->id]) }}" style="display:inline">
                                            @csrf
                                            <button type="submit" class="pl-btn pl-btn--primary">Sync</button>
                                        </form>
                                    @endif

                                    @if ($price->is_active && ! $price->archived_at)
                                        <form method="POST" action="{{ route('ops.billing.prices.archive', ['id' => $plan->id, 'priceId' => $price->id]) }}" style="display:inline"
                                              onsubmit="return confirm('Archive in Stripe? New checkouts will be blocked. Existing subscribers keep renewing on it.');">
                                            @csrf
                                            <button type="submit" class="pl-btn pl-btn--warn">Archive</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" style="color:#94a3b8">No prices yet — add one below.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                {{-- Add a price for an interval that has none --}}
                @php $missing = array_diff($intervals, $plan->availableIntervals()); @endphp
                @if (! empty($missing))
                    <form method="POST" action="{{ route('ops.billing.prices.store', ['id' => $plan->id]) }}"
                          class="pl-inline" style="margin-top:14px">
                        @csrf
                        <select name="interval">
                            @foreach ($missing as $iv)
                                <option value="{{ $iv }}">
                                    {{ config("billing.intervals.labels.$iv", ucfirst($iv)) }}
                                    @if (! in_array($iv, $offered, true)) (not shown on pricing page) @endif
                                </option>
                            @endforeach
                        </select>
                        <input type="number" name="amount" step="0.01" min="0" placeholder="USD" required>
                        <button type="submit" class="pl-btn pl-btn--primary">
                            <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add price
                        </button>
                    </form>
                    <p class="pl-note">
                        Amount in US dollars — USD is the only billing currency. Quarterly is supported by the
                        schema but not currently shown on the pricing page; add
                        <code>quarterly</code> to <code>billing.intervals.offered</code> to display it.
                    </p>
                @endif
            @endif
        </div>
    @empty
        <div class="pl-card intro-y" style="text-align:center;padding:40px">
            <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px">No plans yet</div>
            <p style="font-size:13px;color:#64748b;margin-bottom:18px">
                Run <code>php artisan db:seed --class=BillingSeeder</code> to install the approved
                Free / Starter / Growth / Scale set, or create one by hand.
            </p>
            <a href="{{ route('ops.billing.plans.create') }}" class="pl-btn pl-btn--primary">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> New plan
            </a>
        </div>
    @endforelse
</div>
@endsection
