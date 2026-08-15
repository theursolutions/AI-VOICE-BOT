@extends('layouts.master')

@section('content')
@php
    $sub          = $subscription;
    $isFree       = (bool) $sub?->isFree();
    $freeDaysLeft = $sub?->freeDaysRemaining();
    $degraded     = $sub && ! $sub->grantsAccess();
    $canBuy       = (bool) config('billing.checkout.enabled', false);

    // "What you get" for the ACTIVE plan, reusing the same view model the
    // pricing page renders from — so this page and the marketing site can
    // never describe the same plan differently.
    $included = collect($pricing['plans'] ?? [])
        ->firstWhere('slug', $plan?->slug)['included'] ?? [];
@endphp

@include('billing._styles')

<div class="intro-y flex flex-wrap items-center gap-3 mt-8 mb-6">
    <h2 class="text-lg font-medium mr-auto">Billing &amp; plan</h2>

    @if ($isOwner && $canBuy)
        {{-- The upgrade path the customer is most likely to want, kept at the
             top of the page where it's found without scrolling. --}}
        <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--primary">
            <i data-lucide="arrow-up-circle" class="w-4 h-4"></i>
            {{ $sub?->stripe_subscription_ref ? 'Change plan' : 'Upgrade plan' }}
        </a>
    @endif
</div>

{{-- ── Status alerts ─────────────────────────────────────────────── --}}
@foreach ([['success','ok','check-circle'], ['error','err','alert-octagon'], ['info','info','info'], ['billing_warning','warn','alert-triangle']] as [$key, $kind, $icon])
    @if (session($key))
        <div class="bl-alert bl-alert--{{ $kind }}">
            <i data-lucide="{{ $icon }}" class="w-5 h-5" style="flex:none"></i>
            <div>{{ session($key) }}</div>
        </div>
    @endif
@endforeach

@if (! $stripeReady && auth()->user()?->isSuperAdmin())
    <div class="bl-alert bl-alert--err">
        <i data-lucide="alert-octagon" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>Stripe isn’t configured (only you can see this)</strong>
            Set STRIPE_KEY and STRIPE_SECRET in .env to enable checkout.
        </div>
    </div>
@endif

@if ($degraded)
    <div class="bl-alert bl-alert--warn">
        <i data-lucide="pause-circle" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>Your agent is paused</strong>
            @if ($sub->isExpired())
                Your free access ended{{ $sub->free_ends_at ? ' on ' . $sub->free_ends_at->format('j M Y') : '' }}.
                Everything is still here — leads, conversations and settings are untouched and exportable.
                @if ($sub->purge_after) Data is kept until {{ $sub->purge_after->format('j M Y') }}. @endif
            @elseif ($sub->isPastDue())
                We couldn’t take your last payment. Update your card to resume service.
            @endif
        </div>
    </div>
@elseif ($sub?->onGracePeriod())
    <div class="bl-alert bl-alert--info">
        <i data-lucide="info" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>Cancellation scheduled</strong>
            You keep full access until {{ $sub->ends_at?->format('j M Y') }}. Change your mind any time before then.
        </div>
    </div>
@endif

{{-- ── Current plan ──────────────────────────────────────────────── --}}
@php
    // One pill, chosen from real state rather than five stacked badges.
    [$pillClass, $pillText] = match (true) {
        $sub === null                       => ['muted',   'No plan'],
        $sub->isExpired()                   => ['stopped', 'Paused'],
        $sub->isPastDue()                   => ['warn',    'Payment failed'],
        $sub->cancel_at_period_end          => ['warn',    'Cancels ' . ($sub->ends_at?->format('j M') ?? 'soon')],
        $sub->isFree() && $freeDaysLeft !== null
            => ['trial', $freeDaysLeft . ' ' . Str::plural('day', $freeDaysLeft) . ' left'],
        $sub->isFree()                      => ['trial',   'Free'],
        default                             => ['live',    'Active'],
    };
@endphp

<div class="bl-plan intro-y">
    <div class="bl-plan__inner">
        <div class="bl-plan__top">
            <div style="min-width:0">
                <div class="bl-plan__eyebrow">Current plan</div>
                <div class="bl-plan__name">
                    {{ $plan?->name ?? 'No plan' }}
                    <span class="bl-pill bl-pill--{{ $pillClass }}">
                        <span class="bl-pill__dot"></span>{{ $pillText }}
                    </span>
                </div>
                @if ($plan?->tagline)
                    <p class="bl-plan__tagline">{{ $plan->tagline }}</p>
                @endif
            </div>

            @if ($price && $priceDisplay)
                <div class="bl-plan__price">
                    <div class="bl-plan__amount">{{ $priceDisplay['usd'] }}</div>
                    <div class="bl-plan__per">
                        per {{ $price->months() > 1 ? strtolower($price->intervalLabel()) : 'month' }} · USD
                    </div>
                    @if ($priceDisplay['local'])
                        {{-- Reference only; the card is charged the USD figure. --}}
                        <div class="bl-plan__local">≈ {{ $priceDisplay['local'] }}</div>
                    @endif
                </div>
            @elseif ($isFree)
                <div class="bl-plan__price">
                    <div class="bl-plan__amount">$0</div>
                    <div class="bl-plan__per">no card required</div>
                </div>
            @endif
        </div>

        {{-- Stat strip. Only facts that exist — an account screen full of
             "—" placeholders looks unfinished, not informative. --}}
        @php
            $stats = [];

            if ($isFree && $sub?->free_ends_at) {
                $stats[] = ['Free access ends', $sub->free_ends_at->format('j M Y'), null];
            }
            if ($sub?->nextBillingDate()) {
                $stats[] = ['Next payment', $sub->nextBillingDate()->format('j M Y'), null];
            }
            if ($sub?->cancel_at_period_end && $sub?->ends_at) {
                $stats[] = ['Access ends', $sub->ends_at->format('j M Y'), null];
            }
            if ($price) {
                $stats[] = ['Billing', $price->intervalLabel(), 'charged in USD'];
            }
            if ($paymentMethod) {
                $stats[] = [
                    'Payment method',
                    \App\Services\Billing\PaymentMethodService::brandLabel($paymentMethod['brand']),
                    '···· ' . $paymentMethod['last4'],
                ];
            }
            $seatLimit = $plan ? app(\App\Services\Billing\PlanFeatureService::class)->planLimit($plan, 'seats') : null;
            if ($seatLimit !== null && $seatLimit > 0) {
                $stats[] = ['Team seats', (string) $seatLimit, 'included'];
            }
        @endphp

        @if ($stats)
            <dl class="bl-plan__stats">
                @foreach ($stats as [$label, $value, $sub2])
                    <div class="bl-plan__stat">
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }} @if($sub2)<small>{{ $sub2 }}</small>@endif</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if ($isOwner)
            <div class="bl-plan__cta">
                @if ($canBuy)
                    <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--primary">
                        <i data-lucide="arrow-up-circle" class="w-4 h-4"></i>
                        {{ $sub?->stripe_subscription_ref ? 'Change plan' : 'Choose a plan' }}
                    </a>
                @endif

                @if ($sub?->onGracePeriod() || $sub?->cancel_at_period_end)
                    <form method="POST" action="{{ route('billing.resume', ['client' => $client->slug]) }}">
                        @csrf
                        <button type="submit" class="bl-btn bl-btn--ghost">
                            <i data-lucide="rotate-ccw" class="w-4 h-4"></i> Resume subscription
                        </button>
                    </form>
                @elseif ($sub?->stripe_subscription_ref && $sub->grantsAccess())
                    <form method="POST" action="{{ route('billing.cancel', ['client' => $client->slug]) }}"
                          onsubmit="return confirm('Cancel at the end of your current period? You keep full access until then.');">
                        @csrf
                        <button type="submit" class="bl-btn bl-btn--ghost">Cancel subscription</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</div>

<div class="bl-grid" style="margin-top:20px">
    {{-- ── Left column ───────────────────────────────────────────── --}}
    <div>
        {{-- Usage.
             Deliberately METERS, not doughnuts. Each figure is one value
             against one cap; a pie per metric is the classic way to make that
             harder to read. The % is stated as text and the state carries an
             icon + word, so nothing depends on colour alone. --}}
        <div class="bl-card intro-y">
            <div class="bl-card__head">
                <i data-lucide="activity" class="w-4 h-4" style="color:#6366f1"></i>
                <div class="bl-card__title">Usage this period</div>
                @php $resetsAt = collect($usage)->pluck('resets_at')->filter()->first(); @endphp
                @if ($resetsAt)
                    <div class="bl-card__action" style="font-size:11.5px;color:#94a3b8">
                        Resets {{ $resetsAt->format('j M') }}
                    </div>
                @endif
            </div>

            @if (empty($usage))
                <div class="bl-empty">
                    <i data-lucide="bar-chart-2" class="w-7 h-7"></i>
                    No usage recorded yet.
                </div>
            @else
                <div class="bl-meters">
                    @foreach ($usage as $metric => $row)
                        @php
                            $pct   = $row['unlimited'] ? 0 : (int) $row['percent'];
                            $over  = $row['overage'] > 0;
                            $warn  = ! $over && $pct >= 80;
                            $state = $over ? 'over' : ($warn ? 'warn' : 'ok');
                        @endphp
                        <div class="bl-meter {{ $row['unlimited'] ? 'bl-meter--unlimited' : '' }}">
                            <div class="bl-meter__top">
                                <span class="bl-meter__label">{{ $row['label'] }}</span>
                                @unless ($row['unlimited'])
                                    <span class="bl-meter__pct">{{ $pct }}%</span>
                                @endunless
                            </div>

                            <div class="bl-meter__bar">
                                <div class="bl-meter__fill {{ $over ? 'bl-meter__fill--over' : ($warn ? 'bl-meter__fill--warn' : '') }}"
                                     style="width:{{ $row['unlimited'] ? 100 : max(2, $pct) }}%"></div>
                            </div>

                            <div class="bl-meter__foot">
                                <span>
                                    {{ number_format($row['used']) }}
                                    @if ($row['unlimited']) used @else / {{ number_format($row['allowance']) }} @endif
                                    {{ $row['unit'] ? Str::plural($row['unit'], $row['used']) : '' }}
                                </span>

                                {{-- State in words + an icon, never colour alone. --}}
                                @if ($row['unlimited'])
                                    <span class="bl-meter__state bl-meter__state--ok">
                                        <i data-lucide="infinity" class="w-3 h-3"></i> Unlimited
                                    </span>
                                @elseif ($over)
                                    <span class="bl-meter__state bl-meter__state--over">
                                        <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                        {{ number_format($row['overage']) }} over
                                    </span>
                                @elseif ($warn)
                                    <span class="bl-meter__state bl-meter__state--warn">
                                        <i data-lucide="alert-circle" class="w-3 h-3"></i> Running low
                                    </span>
                                @else
                                    <span class="bl-meter__state bl-meter__state--ok">
                                        <i data-lucide="check" class="w-3 h-3"></i> Healthy
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (collect($usage)->contains(fn ($r) => $r['overage'] > 0))
                    <p class="bl-note">
                        Usage above your allowance is billed at your plan’s overage rate — your agent keeps
                        answering rather than stopping mid-month.
                    </p>
                @endif
            @endif
        </div>

        {{-- What the active plan includes --}}
        @if (! empty($included))
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="package-check" class="w-4 h-4" style="color:#6366f1"></i>
                    <div class="bl-card__title">What’s included in {{ $plan->name }}</div>
                </div>

                <div class="bl-incl">
                    @foreach ($included as $group => $items)
                        <div>
                            <div class="bl-incl__group">{{ $group }}</div>
                            <ul>
                                @foreach ($items as $item)
                                    <li>
                                        <i data-lucide="check" class="w-3.5 h-3.5 tick" style="margin-top:2px"></i>
                                        <span>{{ $item['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Transaction history --}}
        <div class="bl-card intro-y">
            <div class="bl-card__head">
                <i data-lucide="file-text" class="w-4 h-4" style="color:#6366f1"></i>
                <div class="bl-card__title">Payment history</div>
            </div>

            @if (empty($invoices))
                <div class="bl-empty">
                    <i data-lucide="file-text" class="w-7 h-7"></i>
                    No payments yet. Invoices appear here after your first charge.
                </div>
            @else
                <div style="overflow-x:auto">
                    <div class="tva-export-bar">@include('partials.table-export', ['table' => '#tva-t-billing', 'filename' => 'billing', 'paginator' => null])</div>
                    <table class="bl-table" id="tva-t-billing">
                        <thead>
                            <tr><th>Date</th><th>Invoice</th><th>Status</th><th>Amount</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($invoices as $inv)
                                <tr>
                                    <td>{{ $inv['created']?->format('j M Y') ?? '—' }}</td>
                                    <td>
                                        <a href="{{ route('billing.invoice', ['client' => $client->slug, 'invoice' => $inv['id']]) }}"
                                           style="color:#6366f1;font-weight:600">
                                            {{ $inv['number'] ?: 'View' }}
                                        </a>
                                    </td>
                                    <td>
                                        <span class="bl-badge bl-badge--{{ $inv['status'] === 'paid' ? 'green' : ($inv['status'] === 'open' ? 'amber' : 'slate') }}">
                                            {{ $inv['status'] }}
                                        </span>
                                    </td>
                                    <td class="bl-amt">${{ number_format($inv['total'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Right column ──────────────────────────────────────────── --}}
    <div>
        {{-- Saved cards --}}
        @if ($isOwner && ($cards || $canBuy))
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="credit-card" class="w-4 h-4" style="color:#6366f1"></i>
                    <div class="bl-card__title">Payment methods</div>
                    @if ($canBuy && $stripeReady)
                        <div class="bl-card__action">
                            <button type="button" class="bl-btn bl-btn--ghost bl-btn--sm" data-tva-modal-open="card-modal">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add card
                            </button>
                        </div>
                    @endif
                </div>

                @forelse ($cards as $card)
                    <div class="bl-pm {{ $card['is_default'] ? 'bl-pm--default' : '' }} {{ $card['expired'] ? 'bl-pm--expired' : '' }}">
                        <div class="bl-pm__brand">{{ \App\Services\Billing\PaymentMethodService::brandLabel($card['brand']) }}</div>
                        <div>
                            <div class="bl-pm__num">•••• •••• •••• {{ $card['last4'] }}</div>
                            <div class="bl-pm__exp">
                                Expires {{ str_pad((string) $card['exp_month'], 2, '0', STR_PAD_LEFT) }}/{{ $card['exp_year'] }}
                                @if ($card['expired'])
                                    <span class="bl-badge bl-badge--red" style="margin-left:6px">Expired</span>
                                @elseif ($card['is_default'])
                                    <span class="bl-badge bl-badge--blue" style="margin-left:6px">Default</span>
                                @endif
                            </div>
                        </div>

                        <div class="bl-pm__actions">
                            @unless ($card['is_default'])
                                <form method="POST" action="{{ route('billing.cards.default', ['client' => $client->slug]) }}">
                                    @csrf
                                    <input type="hidden" name="payment_method" value="{{ $card['id'] }}">
                                    <button type="submit" class="bl-btn bl-btn--ghost bl-btn--sm">Make default</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('billing.cards.destroy', ['client' => $client->slug]) }}"
                                  onsubmit="return confirm('Remove this card?');">
                                @csrf @method('DELETE')
                                <input type="hidden" name="payment_method" value="{{ $card['id'] }}">
                                <button type="submit" class="bl-btn bl-btn--danger bl-btn--sm" title="Remove">
                                    <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="bl-empty" style="padding:18px 10px">
                        <i data-lucide="credit-card" class="w-7 h-7"></i>
                        No cards saved. You’ll add one when you choose a plan.
                    </div>
                @endforelse

                @if ($cards)
                    <p class="bl-note">
                        Card details are held by Stripe and never touch our servers — we only store the brand
                        and last four digits to show here.
                    </p>
                @endif
            </div>
        @endif

        {{-- Stripe portal, for tax ids / billing address / raw invoices --}}
        @if ($isOwner && $client->hasStripeCustomer())
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="external-link" class="w-4 h-4" style="color:#6366f1"></i>
                    <div class="bl-card__title">Billing portal</div>
                </div>
                <p style="font-size:13px;color:#64748b;line-height:1.6;margin:0 0 14px">
                    Billing address, tax ID and original Stripe receipts.
                </p>
                <form method="POST" action="{{ route('billing.portal', ['client' => $client->slug]) }}">
                    @csrf
                    <button type="submit" class="bl-btn bl-btn--ghost" style="width:100%">
                        Open Stripe portal <i data-lucide="arrow-up-right" class="w-4 h-4"></i>
                    </button>
                </form>
            </div>
        @endif

        @unless ($isOwner)
            <div class="bl-card intro-y">
                <p style="font-size:13px;color:#64748b;margin:0;display:flex;gap:8px">
                    <i data-lucide="lock" class="w-4 h-4" style="flex:none;margin-top:2px"></i>
                    Only the workspace owner can change the plan or manage payment methods.
                </p>
            </div>
        @endunless
    </div>
</div>

{{-- ── Add-card modal (Stripe Elements) ──────────────────────────── --}}
@if ($isOwner && $canBuy && $stripeReady)
    @include('billing._card-modal', ['client' => $client, 'stripeKey' => config('billing.stripe.key')])
@endif
@endsection
