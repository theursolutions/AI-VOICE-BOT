@extends('layouts.ops')

@section('content')
@include('ops.billing._styles')

<style>
    /* Plan header row: identity on the left, actions pinned right, never
       colliding on a narrow screen. */
    .pi-head { display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap; }
    .pi-id { flex:1; min-width:230px; }
    .pi-name { font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .pi-slug { font-family:ui-monospace,monospace; font-size:11px; color:#94a3b8; margin-top:3px; }
    .pi-meta { font-size:12px; color:#64748b; margin-top:7px; line-height:1.6; }
    .pi-meta strong { color:#0f172a; }

    /* THE FIX for the crooked rows: the price editor is a grid with fixed
       track widths, so the amount box and its button are identical heights and
       line up across every row of the table instead of each cell sizing itself. */
    .pi-priceform { display:grid; grid-template-columns:118px auto; gap:8px; justify-content:end; align-items:center; }
    .pi-addform   { display:grid; grid-template-columns:minmax(170px,1fr) 132px auto; gap:10px; align-items:end; }
    @media (max-width:720px){ .pi-addform { grid-template-columns:1fr; } }

    .pi-addbar { margin-top:16px; padding-top:16px; border-top:1px dashed #e2e8f0; }
    html.dark .pi-addbar { border-color:#334155; }
    html.dark .pi-name { color:#f1f5f9; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">💳</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Plans &amp; Pricing</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Everything customers can buy. Prices, limits, features and trials are editable here —
                no developer, no deploy. Changing a price never affects existing subscribers.
            </div>
        </div>
        <div class="ob-card__actions">
            <a href="{{ route('ops.billing.features.index') }}" class="ob-btn">
                <i data-lucide="sliders" class="w-3.5 h-3.5"></i> Features &amp; limits
            </a>
            <a href="{{ route('ops.billing.subscriptions.index') }}" class="ob-btn">
                <i data-lucide="users" class="w-3.5 h-3.5"></i> Subscriptions
            </a>
            <a href="{{ route('ops.billing.plans.create') }}" class="ob-btn ob-btn--primary">
                <i data-lucide="plus" class="w-3.5 h-3.5"></i> New plan
            </a>
        </div>
    </div>

    <div class="mt-5">
        {{-- ── Stripe state ──────────────────────────────────────────── --}}
        @if (! $stripeReady)
            <div class="ob-note ob-note--err">
                <i data-lucide="alert-octagon" class="w-5 h-5"></i>
                <div>
                    <strong>Stripe isn’t configured.</strong>
                    Set <code>STRIPE_KEY</code>, <code>STRIPE_SECRET</code> and
                    <code>STRIPE_WEBHOOK_SECRET</code> in <code>.env</code>. You can still create plans and
                    prices — they just can’t be sold until they’re synced.
                </div>
            </div>
        @else
            <div class="ob-note ob-note--info">
                <i data-lucide="info" class="w-5 h-5"></i>
                <div style="flex:1">
                    Stripe is connected in <strong>{{ $stripeLiveMode ? 'LIVE' : 'TEST' }}</strong> mode.
                    @if ($unsyncedCount > 0)
                        <strong>{{ $unsyncedCount }} active price(s) have no Stripe price yet</strong>
                        and cannot be checked out.
                    @else
                        All active prices are synced.
                    @endif
                </div>
                <form method="POST" action="{{ route('ops.billing.plans.sync-stripe') }}" class="ob-inline">
                    @csrf
                    <button type="submit" class="ob-btn ob-btn--sm">
                        <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Sync all
                    </button>
                </form>
            </div>
        @endif

        @if (! empty($mismatches))
            <div class="ob-note ob-note--err">
                <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                <div>
                    <strong>{{ count($mismatches) }} price(s) were created in the other Stripe mode.</strong>
                    Checking out against a test price with a live key fails at Stripe — for a real customer,
                    at the moment they try to pay. Archive them and add fresh prices in
                    {{ $stripeLiveMode ? 'live' : 'test' }} mode:
                    @foreach ($mismatches as $m)
                        <code>{{ $m->plan?->slug }}/{{ $m->interval }}</code>@if(! $loop->last), @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Plans ─────────────────────────────────────────────────── --}}
        @forelse ($plans as $plan)
            <div class="ob-card intro-y">
                <div class="pi-head">
                    <div class="pi-id">
                        <div class="pi-name">
                            {{ $plan->name }}
                            <span class="ob-pill {{ $plan->is_active ? 'ob-pill--on' : 'ob-pill--off' }}">
                                {{ $plan->is_active ? 'On sale' : 'Hidden' }}
                            </span>
                            @if ($plan->is_featured)
                                <span class="ob-pill ob-pill--accent">{{ $plan->badge ?: 'Popular' }}</span>
                            @endif
                            @if ($plan->type === 'free')<span class="ob-pill ob-pill--info">Free</span>@endif
                            @if ($plan->type === 'enterprise')<span class="ob-pill ob-pill--purple">Enterprise</span>@endif
                            @if (! $plan->is_public)<span class="ob-pill ob-pill--muted">Private</span>@endif
                        </div>
                        <div class="pi-slug">{{ $plan->slug }}</div>
                        @if ($plan->tagline)
                            <div class="pi-meta">{{ $plan->tagline }}</div>
                        @endif
                        <div class="pi-meta">
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

                    <div class="ob-card__actions">
                        <a href="{{ route('ops.billing.plans.edit', ['id' => $plan->id]) }}" class="ob-btn ob-btn--primary ob-btn--sm">
                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i> Edit
                        </a>
                        @unless ($plan->is_featured)
                            <form method="POST" action="{{ route('ops.billing.plans.feature', ['id' => $plan->id]) }}" class="ob-inline">
                                @csrf
                                <button type="submit" class="ob-btn ob-btn--sm">
                                    <i data-lucide="star" class="w-3.5 h-3.5"></i> Make popular
                                </button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('ops.billing.plans.toggle', ['id' => $plan->id]) }}" class="ob-inline"
                              onsubmit="return confirm('{{ $plan->is_active
                                    ? 'Hide this plan from new signups? Existing subscribers keep their subscription.'
                                    : 'Put this plan back on sale?' }}');">
                            @csrf
                            <button type="submit" class="ob-btn ob-btn--sm {{ $plan->is_active ? 'ob-btn--warn' : '' }}">
                                <i data-lucide="{{ $plan->is_active ? 'eye-off' : 'eye' }}" class="w-3.5 h-3.5"></i>
                                {{ $plan->is_active ? 'Hide' : 'Activate' }}
                            </button>
                        </form>
                    </div>
                </div>

                {{-- ── Prices ────────────────────────────────────────── --}}
                @if ($plan->type === 'free')
                    <p class="ob-help" style="margin-top:14px">
                        A free plan has no price. Its limits live in
                        <a href="{{ route('ops.billing.features.index') }}" style="color:#c97a00;font-weight:600">Features &amp; limits</a>.
                    </p>
                @elseif ($plan->type === 'enterprise')
                    <p class="ob-help" style="margin-top:14px">
                        Enterprise renders as a “talk to us” CTA on the pricing page, not a price card.
                    </p>
                @else
                    <div class="ob-tablewrap" style="margin-top:16px">
                        <div class="tva-export-bar">@include('partials.table-export', ['table' => '#tva-t-ops-billing-plans', 'filename' => 'ops-billing-plans', 'paginator' => null])</div>
                        <table class="ob-table" id="tva-t-ops-billing-plans">
                            <thead>
                                <tr>
                                    <th style="width:110px">Interval</th>
                                    <th style="width:100px">Price</th>
                                    <th style="width:110px">Per month</th>
                                    <th>Stripe price</th>
                                    <th style="width:100px">State</th>
                                    <th style="width:260px"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($plan->prices as $price)
                                    <tr class="{{ $price->archived_at ? 'is-dim' : '' }}">
                                        <td>{{ $price->intervalLabel() }}</td>
                                        <td class="ob-amt">{{ $price->formatted() }}</td>
                                        <td>{{ $price->months() > 1 ? $price->formattedEffectiveMonthly() : '—' }}</td>
                                        <td>
                                            @if ($price->stripe_price_ref)
                                                <span class="ob-ref">{{ $price->stripe_price_ref }}</span>
                                                @if ($price->isStripeModeMismatched())
                                                    <span class="ob-pill ob-pill--off" style="margin-left:5px">wrong mode</span>
                                                @endif
                                            @else
                                                <span class="ob-pill ob-pill--off">not synced</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($price->is_active)
                                                <span class="ob-pill ob-pill--on">Active</span>
                                            @elseif ($price->archived_at)
                                                <span class="ob-pill ob-pill--muted">Archived</span>
                                            @else
                                                <span class="ob-pill ob-pill--off">Inactive</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="ob-rowactions">
                                                @if ($price->is_active)
                                                    {{-- Editing an amount never edits this row: it creates a
                                                         new price + Stripe price and archives this one, so
                                                         existing subscribers are grandfathered. --}}
                                                    <form method="POST"
                                                          action="{{ route('ops.billing.prices.update', ['id' => $plan->id, 'priceId' => $price->id]) }}"
                                                          class="pi-priceform"
                                                          onsubmit="return confirm('Create a NEW price at this amount?\n\nExisting subscribers stay on {{ $price->formatted() }} until you migrate them. New signups pay the new amount.');">
                                                        @csrf @method('PATCH')
                                                        <div class="ob-money">
                                                            <span>$</span>
                                                            <input class="ob-input ob-input--sm ob-input--num" type="number"
                                                                   name="amount" step="0.01" min="0" required
                                                                   value="{{ number_format($price->unit_amount / 100, 2, '.', '') }}">
                                                        </div>
                                                        <button type="submit" class="ob-btn ob-btn--sm">Update</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('ops.billing.prices.activate', ['id' => $plan->id, 'priceId' => $price->id]) }}" class="ob-inline">
                                                        @csrf
                                                        <button type="submit" class="ob-btn ob-btn--sm">Reactivate</button>
                                                    </form>
                                                @endif

                                                @if (! $price->stripe_price_ref && $stripeReady)
                                                    <form method="POST" action="{{ route('ops.billing.prices.sync', ['id' => $plan->id, 'priceId' => $price->id]) }}" class="ob-inline">
                                                        @csrf
                                                        <button type="submit" class="ob-btn ob-btn--primary ob-btn--sm">Sync</button>
                                                    </form>
                                                @endif

                                                @if ($price->is_active && ! $price->archived_at)
                                                    <form method="POST" action="{{ route('ops.billing.prices.archive', ['id' => $plan->id, 'priceId' => $price->id]) }}" class="ob-inline"
                                                          onsubmit="return confirm('Archive in Stripe? New checkouts are blocked. Existing subscribers keep renewing on it.');">
                                                        @csrf
                                                        <button type="submit" class="ob-btn ob-btn--warn ob-btn--icon" title="Archive">
                                                            <i data-lucide="archive" class="w-3.5 h-3.5"></i>
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" style="color:#94a3b8">No prices yet — add one below.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Add a price for an interval that has none --}}
                    @php $missing = array_diff($intervals, $plan->availableIntervals()); @endphp
                    @if (! empty($missing))
                        <div class="pi-addbar">
                            <form method="POST" action="{{ route('ops.billing.prices.store', ['id' => $plan->id]) }}" class="pi-addform">
                                @csrf
                                <div class="ob-field" style="margin:0">
                                    <label>Billing interval</label>
                                    <select name="interval" class="ob-select">
                                        @foreach ($missing as $iv)
                                            <option value="{{ $iv }}">
                                                {{ config("billing.intervals.labels.$iv", ucfirst($iv)) }}@if (! in_array($iv, $offered, true)) — not shown publicly @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="ob-field" style="margin:0">
                                    <label>Amount <span class="hint">USD</span></label>
                                    <div class="ob-money">
                                        <span>$</span>
                                        <input class="ob-input ob-input--num" type="number" name="amount"
                                               step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>
                                <div class="ob-field" style="margin:0">
                                    <button type="submit" class="ob-btn ob-btn--primary">
                                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add price
                                    </button>
                                </div>
                            </form>
                            <p class="ob-help">
                                USD is the only billing currency. Quarterly is supported by the schema but not
                                shown on the pricing page — add <code>quarterly</code> to
                                <code>billing.intervals.offered</code> to display it.
                            </p>
                        </div>
                    @endif
                @endif
            </div>
        @empty
            <div class="ob-card intro-y">
                <div class="ob-empty">
                    <i data-lucide="package" class="w-8 h-8"></i>
                    <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px">No plans yet</div>
                    <p style="margin-bottom:18px">
                        Run <code>php artisan db:seed --class=BillingSeeder</code> to install the approved
                        Free / Starter / Growth / Scale set, or create one by hand.
                    </p>
                    <a href="{{ route('ops.billing.plans.create') }}" class="ob-btn ob-btn--primary">
                        <i data-lucide="plus" class="w-3.5 h-3.5"></i> New plan
                    </a>
                </div>
            </div>
        @endforelse
    </div>
</div>
@endsection
