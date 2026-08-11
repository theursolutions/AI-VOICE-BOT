@extends('layouts.master')

@section('content')
@php
    $sub   = $subscription;
    $isFree = $sub?->isFree();
    $freeDaysLeft = $sub?->freeDaysRemaining();
    $degraded = $sub && ! $sub->grantsAccess();
@endphp

<style>
    .bl-grid { display:grid; gap:20px; grid-template-columns:1fr; }
    @media (min-width:1100px){ .bl-grid { grid-template-columns: 1.35fr 1fr; align-items:start; } }

    .bl-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; }
    .bl-card__title {
        font-size:13px; font-weight:700; color:#0f172a; text-transform:uppercase;
        letter-spacing:.06em; display:flex; align-items:center; gap:8px;
        margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0;
    }

    .bl-plan-row { display:flex; align-items:flex-start; gap:16px; flex-wrap:wrap; justify-content:space-between; }
    .bl-plan-name { font-size:26px; font-weight:800; color:#0f172a; line-height:1.15; }
    .bl-plan-meta { font-size:13px; color:#64748b; margin-top:4px; }
    .bl-amount { font-size:26px; font-weight:800; color:#0f172a; text-align:right; line-height:1.15; }
    .bl-amount small { font-size:13px; font-weight:600; color:#64748b; }
    .bl-local { font-size:12px; color:#6366f1; text-align:right; margin-top:2px; }

    .bl-badge {
        display:inline-flex; align-items:center; gap:6px; font-size:11px; font-weight:700;
        letter-spacing:.06em; text-transform:uppercase; padding:4px 10px; border-radius:999px;
    }
    .bl-badge--green { background:#dcfce7; color:#15803d; }
    .bl-badge--blue  { background:#dbeafe; color:#1d4ed8; }
    .bl-badge--amber { background:#fef3c7; color:#b45309; }
    .bl-badge--red   { background:#fee2e2; color:#b91c1c; }
    .bl-badge--slate { background:#f1f5f9; color:#475569; }

    .bl-alert { border-radius:12px; padding:14px 16px; font-size:14px; display:flex; gap:11px; margin-bottom:20px; }
    .bl-alert--warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .bl-alert--info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
    .bl-alert--err  { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .bl-alert__body { flex:1; }
    .bl-alert strong { display:block; margin-bottom:2px; }

    .bl-meter { margin-bottom:16px; }
    .bl-meter:last-child { margin-bottom:0; }
    .bl-meter__head { display:flex; justify-content:space-between; align-items:baseline; font-size:13px; margin-bottom:6px; }
    .bl-meter__label { color:#334155; font-weight:600; }
    .bl-meter__value { color:#64748b; font-variant-numeric:tabular-nums; }
    .bl-meter__bar { height:7px; border-radius:999px; background:#f1f5f9; overflow:hidden; }
    .bl-meter__fill { height:100%; border-radius:999px; background:linear-gradient(90deg,#6366f1,#8b5cf6); }
    .bl-meter__fill--warn { background:linear-gradient(90deg,#f59e0b,#ef4444); }
    .bl-meter__over { font-size:11.5px; color:#b45309; margin-top:4px; }

    .bl-btn {
        display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:600;
        padding:10px 16px; border-radius:10px; text-decoration:none; cursor:pointer;
        border:1px solid transparent; transition:filter .15s;
    }
    .bl-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; }
    .bl-btn--ghost   { background:#fff; border-color:#e2e8f0; color:#334155; }
    .bl-btn--danger  { background:#fff; border-color:#fecaca; color:#b91c1c; }
    .bl-btn:hover { filter:brightness(1.05); }
    .bl-actions { display:flex; gap:9px; flex-wrap:wrap; margin-top:18px; }

    .bl-table { width:100%; border-collapse:collapse; font-size:13px; }
    .bl-table th, .bl-table td { text-align:left; padding:9px 10px; border-bottom:1px solid #f1f5f9; color:#475569; }
    .bl-table th { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .bl-table td:last-child, .bl-table th:last-child { text-align:right; }

    .bl-empty { font-size:13px; color:#94a3b8; padding:14px 0; }

    /* Plan chooser */
    .bl-plans { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); margin-top:6px; }
    .bl-plan-opt { border:1px solid #e2e8f0; border-radius:12px; padding:16px; background:#f8fafc; }
    .bl-plan-opt--current { border-color:#6366f1; background:#eef2ff; }
    .bl-plan-opt h4 { font-size:14.5px; font-weight:700; color:#0f172a; margin:0 0 3px; }
    .bl-plan-opt .amt { font-size:20px; font-weight:800; color:#0f172a; }
    .bl-plan-opt .amt small { font-size:12px; font-weight:600; color:#64748b; }
    .bl-plan-opt .loc { font-size:11.5px; color:#6366f1; margin-top:2px; }
    .bl-plan-opt form { margin-top:11px; }
    .bl-plan-opt button { width:100%; justify-content:center; }
    .bl-plan-opt__current { font-size:11px; font-weight:700; color:#4f46e5; text-transform:uppercase; letter-spacing:.06em; }

    .bl-note { font-size:11.5px; color:#94a3b8; margin-top:12px; line-height:1.55; }

    html.dark .bl-card { background:#1e293b; border-color:#334155; }
    html.dark .bl-card__title, html.dark .bl-plan-name, html.dark .bl-amount, html.dark .bl-plan-opt h4, html.dark .bl-plan-opt .amt { color:#f1f5f9; }
    html.dark .bl-plan-opt { background:#0f172a; border-color:#334155; }
    html.dark .bl-plan-opt--current { background:#312e81; border-color:#6366f1; }
    html.dark .bl-btn--ghost { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .bl-meter__bar { background:#334155; }
    html.dark .bl-table th, html.dark .bl-table td { border-color:#334155; }
</style>

<div class="intro-y flex items-center mt-8 mb-6">
    <h2 class="text-lg font-medium mr-auto">Billing</h2>
</div>

{{-- ── Status alerts ──────────────────────────────────────────────── --}}
@if (session('billing_warning'))
    <div class="bl-alert bl-alert--warn">
        <i data-lucide="alert-triangle" class="w-5 h-5" style="flex:none"></i>
        <div class="bl-alert__body">{{ session('billing_warning') }}</div>
    </div>
@endif

{{-- "Stripe keys are missing" is an OPERATOR problem, not the customer's.
     Showing it to them advertises that our billing isn't set up and gives them
     nothing they can act on, so it's super-admin only. --}}
@if (! $stripeReady && auth()->user()?->isSuperAdmin())
    <div class="bl-alert bl-alert--err">
        <i data-lucide="alert-octagon" class="w-5 h-5" style="flex:none"></i>
        <div class="bl-alert__body">
            <strong>Stripe isn't configured (only you can see this)</strong>
            Checkout is unavailable until STRIPE_KEY and STRIPE_SECRET are set in .env.
        </div>
    </div>
@endif

@if ($isFree && $freeDaysLeft !== null && ! $degraded)
    <div class="bl-alert bl-alert--info">
        <i data-lucide="clock" class="w-5 h-5" style="flex:none"></i>
        <div class="bl-alert__body">
            <strong>{{ $freeDaysLeft }} {{ Str::plural('day', $freeDaysLeft) }} left of free access</strong>
            Your free {{ $sub->plan?->free_window_days ?? 7 }} days end on
            {{ $sub->free_ends_at?->format('j M Y') }}. Choose a plan before then and your agent keeps
            answering without interruption — no card needed until you do.
        </div>
    </div>
@endif

@if ($degraded)
    <div class="bl-alert bl-alert--warn">
        <i data-lucide="pause-circle" class="w-5 h-5" style="flex:none"></i>
        <div class="bl-alert__body">
            <strong>Your agent is paused</strong>
            @if ($sub->isExpired())
                Your free access ended{{ $sub->free_ends_at ? ' on ' . $sub->free_ends_at->format('j M Y') : '' }}.
                Everything is still here — your leads, conversations and settings are untouched, and you can
                export them any time. Pick a plan below to switch the agent back on.
                @if ($sub->purge_after)
                    <br><small>Data is kept until {{ $sub->purge_after->format('j M Y') }}.</small>
                @endif
            @elseif ($sub->isPastDue())
                We couldn't take your last payment. Update your card to resume service.
            @else
                Choose a plan below to continue.
            @endif
        </div>
    </div>
@endif

@if ($sub?->onGracePeriod())
    <div class="bl-alert bl-alert--info">
        <i data-lucide="info" class="w-5 h-5" style="flex:none"></i>
        <div class="bl-alert__body">
            <strong>Cancellation scheduled</strong>
            You keep full access until {{ $sub->ends_at?->format('j M Y') }}. Change your mind any time before then.
        </div>
    </div>
@endif

<div class="bl-grid">
    {{-- ── Left column ────────────────────────────────────────────── --}}
    <div>
        {{-- Current plan --}}
        <div class="bl-card intro-y" style="margin-bottom:20px">
            <div class="bl-card__title"><i data-lucide="credit-card" class="w-4 h-4"></i> Current plan</div>

            <div class="bl-plan-row">
                <div>
                    <div class="bl-plan-name">{{ $plan?->name ?? 'No plan' }}</div>
                    <div class="bl-plan-meta">
                        <span class="bl-badge bl-badge--{{ $sub?->statusColor() ?? 'slate' }}">
                            {{ $sub?->statusLabel() ?? 'None' }}
                        </span>
                        @if ($price)
                            <span style="margin-left:8px">Billed {{ strtolower($price->intervalLabel()) }}</span>
                        @endif
                    </div>
                </div>

                @if ($price && $priceDisplay)
                    <div>
                        <div class="bl-amount">
                            {{ $priceDisplay['usd'] }}<small>{{ $priceDisplay['suffix'] }}</small>
                        </div>
                        @if ($priceDisplay['local'])
                            {{-- Reference only. Stripe charges the USD figure above. --}}
                            <div class="bl-local">≈ {{ $priceDisplay['local'] }} {{ $priceDisplay['suffix'] }}</div>
                        @endif
                    </div>
                @endif
            </div>

            <table class="bl-table" style="margin-top:20px">
                <tbody>
                    @if ($isFree && $sub?->free_ends_at)
                        <tr><td>Free access ends</td><td>{{ $sub->free_ends_at->format('j M Y') }}</td></tr>
                    @endif
                    @if ($sub?->onTrial())
                        <tr><td>Trial ends</td><td>{{ $sub->trial_ends_at->format('j M Y') }}</td></tr>
                    @endif
                    @if ($sub?->nextBillingDate())
                        <tr><td>Next payment</td><td>{{ $sub->nextBillingDate()->format('j M Y') }}</td></tr>
                    @endif
                    @if ($sub?->ends_at && $sub->cancel_at_period_end)
                        <tr><td>Access ends</td><td>{{ $sub->ends_at->format('j M Y') }}</td></tr>
                    @endif
                    {{-- Only once there IS a card. Telling someone who has never
                         paid that they have "no payment method on file" reads as
                         a setup task they've failed to do, when in fact nothing
                         is required of them at all. --}}
                    @if ($paymentMethod)
                        <tr>
                            <td>Payment method</td>
                            <td>
                                {{ ucfirst((string) $paymentMethod['brand']) }} ···· {{ $paymentMethod['last4'] }}
                                @if ($paymentMethod['exp_month'])
                                    <span style="color:#94a3b8">
                                        ({{ str_pad((string) $paymentMethod['exp_month'], 2, '0', STR_PAD_LEFT) }}/{{ $paymentMethod['exp_year'] }})
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endif

                    {{-- Likewise: "charged in USD" only matters once something
                         is actually being charged. --}}
                    @if ($price)
                        <tr><td>Billing currency</td><td>USD</td></tr>
                    @endif
                </tbody>
            </table>

            @if ($isOwner)
                <div class="bl-actions">
                    @if ($client->hasStripeCustomer())
                        <form method="POST" action="{{ route('billing.portal', ['client' => $client->slug]) }}">
                            @csrf
                            <button type="submit" class="bl-btn bl-btn--ghost">
                                <i data-lucide="external-link" class="w-4 h-4"></i> Manage payment &amp; invoices
                            </button>
                        </form>
                    @endif

                    @if ($sub?->onGracePeriod() || $sub?->cancel_at_period_end)
                        <form method="POST" action="{{ route('billing.resume', ['client' => $client->slug]) }}">
                            @csrf
                            <button type="submit" class="bl-btn bl-btn--primary">
                                <i data-lucide="rotate-ccw" class="w-4 h-4"></i> Resume subscription
                            </button>
                        </form>
                    @elseif ($sub?->stripe_subscription_ref && $sub->grantsAccess())
                        <form method="POST" action="{{ route('billing.cancel', ['client' => $client->slug]) }}"
                              onsubmit="return confirm('Cancel at the end of your current period? You keep full access until then.');">
                            @csrf
                            <button type="submit" class="bl-btn bl-btn--danger">
                                <i data-lucide="x-circle" class="w-4 h-4"></i> Cancel subscription
                            </button>
                        </form>
                    @endif
                </div>
                <p class="bl-note">
                    Cards, billing addresses and invoice PDFs are handled by Stripe's secure portal —
                    your card details never touch our servers.
                </p>
            @else
                <p class="bl-note" style="margin-top:18px">
                    <i data-lucide="lock" class="w-3.5 h-3.5" style="display:inline;vertical-align:-2px"></i>
                    Only the workspace owner can change billing.
                </p>
            @endif
        </div>

        {{-- Plan chooser. Hidden entirely while checkout is switched off
             (config/billing.php → checkout.enabled) — no placeholder, no
             explanation, the section simply isn't there. --}}
        @if ($isOwner && $stripeReady && config('billing.checkout.enabled', false))
            <div class="bl-card intro-y" style="margin-bottom:20px">
                <div class="bl-card__title">
                    <i data-lucide="layers" class="w-4 h-4"></i>
                    {{ $sub?->stripe_subscription_ref ? 'Change plan' : 'Choose a plan' }}
                </div>

                @foreach ($pricing['intervals'] as $iv)
                    @php
                        $ivKey = $iv['key'];
                        $any = collect($pricing['plans'])->contains(fn ($p) => isset($p['prices'][$ivKey]));
                    @endphp
                    @continue(! $any)

                    <div style="margin-bottom:22px">
                        <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
                            {{ $iv['label'] }}
                            @php
                                $best = collect($pricing['plans'])
                                    ->map(fn ($p) => (int) ($p['prices'][$ivKey]['savings_percent'] ?? 0))
                                    ->max();
                            @endphp
                            @if ($best > 0)
                                <span style="color:#15803d">· save {{ $best }}%</span>
                            @endif
                        </div>

                        <div class="bl-plans">
                            @foreach ($pricing['plans'] as $option)
                                @continue($option['is_free'] || $option['is_enterprise'])
                                @continue(! isset($option['prices'][$ivKey]))

                                @php
                                    $optPrice = $option['prices'][$ivKey];
                                    $isCurrent = $plan && $plan->slug === $option['slug']
                                                 && $price && $price->interval === $ivKey;
                                @endphp

                                <div class="bl-plan-opt {{ $isCurrent ? 'bl-plan-opt--current' : '' }}">
                                    <h4>{{ $option['name'] }}</h4>
                                    <div class="amt">{{ $optPrice['usd'] }}<small>{{ $optPrice['suffix'] }}</small></div>
                                    @if ($optPrice['local'])
                                        <div class="loc">≈ {{ $optPrice['local'] }}</div>
                                    @endif

                                    @if ($isCurrent)
                                        <div class="bl-plan-opt__current" style="margin-top:11px">Current plan</div>
                                    @else
                                        {{-- Only `plan` + `interval` are submitted. The amount and the
                                             Stripe price are resolved server-side; nothing money-shaped
                                             leaves the browser. Field names avoid `*_id` because
                                             DecodeHashids rewrites those keys. --}}
                                        <form method="POST" action="{{ $sub?->stripe_subscription_ref && $sub->grantsAccess()
                                                    ? route('billing.change', ['client' => $client->slug])
                                                    : route('billing.checkout.store', ['client' => $client->slug]) }}">
                                            @csrf
                                            <input type="hidden" name="plan" value="{{ $option['slug'] }}">
                                            <input type="hidden" name="interval" value="{{ $ivKey }}">
                                            <button type="submit" class="bl-btn bl-btn--primary">
                                                {{ $sub?->stripe_subscription_ref && $sub->grantsAccess() ? 'Switch' : 'Choose' }}
                                                {{ $option['name'] }}
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <p class="bl-note">
                    Prices are charged in USD.
                    @if ($pricing['has_local'])
                        Local amounts are approximate and for reference only.
                    @endif
                    Upgrades apply immediately; the difference is prorated on your next invoice.
                    <a href="{{ url('/pricing') }}" style="color:#6366f1">Compare all plans →</a>
                </p>
            </div>
        @endif
    </div>

    {{-- ── Right column ───────────────────────────────────────────── --}}
    <div>
        {{-- Usage --}}
        <div class="bl-card intro-y" style="margin-bottom:20px">
            <div class="bl-card__title"><i data-lucide="activity" class="w-4 h-4"></i> Usage this period</div>

            @forelse ($usage as $metric => $row)
                <div class="bl-meter">
                    <div class="bl-meter__head">
                        <span class="bl-meter__label">{{ $row['label'] }}</span>
                        <span class="bl-meter__value">
                            {{ number_format($row['used']) }}
                            @if ($row['unlimited'])
                                / unlimited
                            @else
                                / {{ number_format($row['allowance']) }}
                            @endif
                        </span>
                    </div>
                    @unless ($row['unlimited'])
                        <div class="bl-meter__bar">
                            <div class="bl-meter__fill {{ $row['percent'] >= 85 ? 'bl-meter__fill--warn' : '' }}"
                                 style="width:{{ $row['percent'] }}%"></div>
                        </div>
                    @endunless
                    @if ($row['overage'] > 0)
                        <div class="bl-meter__over">
                            {{ number_format($row['overage']) }} {{ Str::plural($row['unit'], $row['overage']) }}
                            over your allowance — billed at your plan's overage rate.
                        </div>
                    @endif
                </div>
            @empty
                <p class="bl-empty">No usage recorded yet.</p>
            @endforelse

            @php $resets = collect($usage)->pluck('resets_at')->filter()->first(); @endphp
            @if ($resets)
                <p class="bl-note">Resets {{ $resets->format('j M Y') }}.</p>
            @endif
        </div>

        {{-- Invoices — only once there are some. An empty "No invoices yet"
             card on a brand-new free workspace is pure noise. --}}
        @if (! empty($invoices))
        <div class="bl-card intro-y">
            <div class="bl-card__title"><i data-lucide="receipt" class="w-4 h-4"></i> Invoices</div>

            {{-- Plain @foreach: the surrounding @if already guarantees there is
                 at least one invoice, so the empty branch would be dead code. --}}
            <table class="bl-table">
                <thead><tr><th>Date</th><th>Status</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td>
                                @if ($invoice['hosted_url'])
                                    <a href="{{ $invoice['hosted_url'] }}" target="_blank" rel="noopener" style="color:#6366f1">
                                        {{ $invoice['created']?->format('j M Y') ?? '—' }}
                                    </a>
                                @else
                                    {{ $invoice['created']?->format('j M Y') ?? '—' }}
                                @endif
                            </td>
                            <td>
                                <span class="bl-badge bl-badge--{{ $invoice['status'] === 'paid' ? 'green' : ($invoice['status'] === 'open' ? 'amber' : 'slate') }}">
                                    {{ $invoice['status'] }}
                                </span>
                            </td>
                            <td>${{ number_format($invoice['total'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
