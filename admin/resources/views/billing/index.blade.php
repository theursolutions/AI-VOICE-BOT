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
        {{-- Two separate intentions, so two buttons.
             "I need one more seat" and "I need a bigger plan" are different
             problems, and routing the first through the plan ladder made the
             customer do the matching themselves — then land on a page that told
             them to choose a plan they already had. --}}
        @if ($sub && ! $sub->isFree())
            <a href="{{ route('billing.addons', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost">
                <i data-lucide="plus-circle" class="w-4 h-4"></i> Add-ons
            </a>
        @endif

        <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="bl-btn bl-btn--primary">
            <i data-lucide="arrow-up-circle" class="w-4 h-4"></i> Upgrade plan
        </a>
    @endif
</div>

{{-- ── Status alerts ─────────────────────────────────────────────── --}}
@include('billing._flash')

@if (! $stripeReady && auth()->user()?->isSuperAdmin())
    <div class="bl-alert bl-alert--err">
        <i data-lucide="alert-octagon" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>Stripe isn’t configured (only you can see this)</strong>
            Set STRIPE_KEY and STRIPE_SECRET in .env to enable checkout.
        </div>
    </div>
@endif

{{-- The "your agent is paused" banner was removed at the owner's request: it
     read as an alarm on a page people open to check a figure, and the state it
     announced is already legible from the status pill on the plan card.

     The past-due case is the one that still needs a nudge, because it is the
     only one the customer can fix and the fix is one click away — so it stays,
     as an ordinary prompt rather than a warning about a paused product. --}}
@if ($sub?->isPastDue())
    <div class="bl-alert bl-alert--info">
        <i data-lucide="credit-card" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>Your last payment didn’t go through</strong>
            Update your card below and everything carries on as normal.
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
                {{-- No plan button here. It duplicated the one in the page
                     header two screens above, so the same action appeared twice
                     with different wording. Cancel and resume stay, because this
                     is the only place they belong. --}}
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
                    <i data-lucide="bar-chart" class="w-7 h-7"></i>
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

                                    {{-- Purchased capacity is named, not folded
                                         into the plan's number: a customer
                                         should see what they'd lose by
                                         removing an add-on they pay for. --}}
                                    @if (! empty($row['addon']) && ! $row['unlimited'])
                                        <span class="bl-badge bl-badge--blue" style="margin-left:6px">
                                            {{ number_format($row['included']) }} included
                                            + {{ number_format($row['addon']) }} added
                                        </span>
                                    @endif
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

        {{-- Conversation length.
             The bridge between the two units. The plan is sold in
             conversations and metered in messages, so this control is what
             makes "1,000 conversations" a real number rather than a hope: it
             is the divisor. Shown next to the meters because that is where
             someone asks "why is my allowance going so fast". --}}
        @php
            $msgAllowance = data_get($usage, 'messages.allowance');
            $msgUnlimited = (bool) data_get($usage, 'messages.unlimited', false);
            $convCount    = data_get($usage, 'conversations.used', 0);
            // Null perConversation is a plan with no automatic handoff, so
            // there is no divisor and no conversation estimate to show.
            $estConvs     = ($msgAllowance && $perConversation)
                ? intdiv((int) $msgAllowance, (int) $perConversation)
                : null;
        @endphp
        <div class="bl-card intro-y">
            <div class="bl-card__head">
                <i data-lucide="message-square" class="w-4 h-4" style="color:#6366f1"></i>
                <div class="bl-card__title">Conversation length</div>
            </div>

            <div style="padding:4px 0 2px;">
                <p style="font-size:13px;color:#475569;line-height:1.65;margin:0 0 14px;">
                    @if ($perConversation === null)
                        Your plan places <strong>no limit</strong> on how long the assistant keeps
                        answering, so conversations are only handed over when the AI decides to or
                        someone on your team steps in.
                    @else
                        Each conversation gets
                        <strong>{{ $perConversation }} AI replies</strong>.
                        After that the assistant stops and the conversation moves to your inbox
                        for a person to answer — it is never left unanswered.
                    @endif
                    @if ($estConvs)
                        At this setting your plan covers about
                        <strong>{{ number_format($estConvs) }} conversations</strong>
                        ({{ number_format((int) $msgAllowance) }} messages) a month.
                    @elseif ($msgUnlimited)
                        Your plan has no message limit, so this only controls when a person steps in.
                    @endif
                </p>

                @if (! empty($isOwner))
                    <form method="POST" action="{{ route('billing.conversation-budget', ['client' => $client->slug]) }}"
                          style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;">
                        @csrf
                        <div style="display:flex;flex-direction:column;gap:5px;">
                            <label for="mpc" style="font:600 11.5px system-ui,sans-serif;color:#334155;">
                                AI replies per conversation
                            </label>
                            <input type="number" name="messages_per_conversation" id="mpc"
                                   value="{{ old('messages_per_conversation', $perConversation ?? \App\Services\Conversation\ConversationBudget::DEFAULT_LIMIT) }}"
                                   min="{{ $budgetBounds['min'] }}" max="{{ $budgetBounds['max'] }}" required
                                   style="width:120px;padding:8px 10px;border:1px solid #e2e8f0;border-radius:8px;
                                          font:13px ui-monospace,Menlo,monospace;color:#0f172a;">
                        </div>
                        <button type="submit"
                                style="background:#0b6e5b;color:#fff;border:none;border-radius:8px;padding:9px 16px;
                                       font:650 12.5px system-ui,sans-serif;cursor:pointer;">
                            Save
                        </button>
                        <span style="font-size:11.5px;color:#94a3b8;">
                            {{ $budgetBounds['min'] }}&ndash;{{ $budgetBounds['max'] }};
                            default {{ $budgetBounds['default'] }}
                        </span>
                    </form>

                    @error('messages_per_conversation')
                        <p style="margin:10px 0 0;font-size:12px;color:#b91c1c;line-height:1.5;">{{ $message }}</p>
                    @enderror
                @else
                    <p style="font-size:11.5px;color:#94a3b8;margin:0;">
                        Only the workspace owner can change this.
                    </p>
                @endif

                @if ($convCount)
                    <p style="font-size:11.5px;color:#94a3b8;margin:12px 0 0;">
                        {{ number_format($convCount) }} conversations so far this period.
                    </p>
                @endif
            </div>
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
        {{-- Add-ons: extra seats / AI agents on top of the plan.
             Buying requires a live Stripe subscription — you cannot add an
             item to a subscription that doesn't exist yet. But hiding the
             whole section when that isn't true made add-ons look like a
             feature we don't have, so the unavailable case now says WHY and
             what to do about it. --}}
        @php
            $blCanBuyAddons = $subscription?->stripe_subscription_ref && $subscription->grantsAccess();
            $blAddonBlocked = null;
            if ($isOwner && ! empty($addons) && ! $blCanBuyAddons) {
                $blAddonBlocked = match (true) {
                    ! $subscription                          => 'Choose a plan first — add-ons sit on top of a paid subscription.',
                    $subscription->isFree()                  => 'Add-ons are available on paid plans. Upgrade to add extra seats or agents.',
                    ! $subscription->stripe_subscription_ref => 'Your subscription is still being set up with our payment provider — add-ons unlock shortly.',
                    $subscription->isPastDue()               => 'Your last payment failed. Update your card and add-ons will be available again.',
                    default                                  => 'Available once your subscription is active — ' . lcfirst($subscription->statusLabel()) . '.',
                };
            }
        @endphp

        @if ($isOwner && ! empty($addons) && ! $blCanBuyAddons)
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="plus-circle" class="w-4 h-4" style="color:#94a3b8"></i>
                    <div class="bl-card__title">Add-ons</div>
                </div>
                <div style="padding:4px 2px 2px;">
                    <p style="font-size:12.5px;color:#64748b;line-height:1.6;margin:0 0 12px;">
                        {{ $blAddonBlocked }}
                    </p>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px;">
                        @foreach ($addons as $item)
                            @php $ap = $item['plan']; @endphp
                            <div style="display:flex;align-items:center;gap:10px;opacity:.6;">
                                <i data-lucide="{{ $ap->slug === 'addon-seat' ? 'user-plus' : 'bot' }}" class="w-4 h-4" style="color:#94a3b8;"></i>
                                <span style="font-size:12.5px;color:#334155;flex:1;">{{ $ap->name }}</span>
                                @if (! empty($item['price']))
                                    <span style="font-size:12px;color:#64748b;">
                                        ${{ number_format($item['price']->unit_amount / 100, 2) }}/{{ $item['price']->interval === 'annually' ? 'yr' : 'mo' }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($subscription?->isPastDue())
                        <a href="#payment-methods" class="btn btn-primary btn-sm w-full">Update payment method</a>
                    @elseif ($subscription && ! $subscription->isFree())
                        {{-- Already on a paid plan: the add-ons page itself, not
                             the plan ladder. It renders for them and explains
                             what is still pending — sending someone to "choose a
                             plan" when they have one is the bug this replaces. --}}
                        <a href="{{ route('billing.addons', ['client' => $client->slug]) }}" class="btn btn-primary btn-sm w-full">
                            See add-ons
                        </a>
                    @else
                        <a href="{{ route('billing.plans', ['client' => $client->slug]) }}" class="btn btn-primary btn-sm w-full">
                            Choose a plan
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @if ($isOwner && ! empty($addons) && $blCanBuyAddons)
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="plus-circle" class="w-4 h-4" style="color:#6366f1"></i>
                    <div class="bl-card__title">Add-ons</div>
                    @if ($addonTotal > 0)
                        <div class="bl-card__action" style="font-size:12px;color:#64748b">
                            +${{ number_format($addonTotal / 100, 2) }}/{{ $subscription->interval === 'annually' ? 'yr' : 'mo' }}
                        </div>
                    @endif
                </div>

                <p style="font-size:12.5px;color:#64748b;line-height:1.6;margin:0 0 16px">
                    Need more than your plan includes? Buy extra capacity without upgrading.
                    Charged on your existing invoice and prorated from today.
                </p>

                {{-- Overview only. Choosing quantities happens on the add-ons
                     page, which can show the prorated cost before committing —
                     a bare number box here charged the card with no idea of
                     what it would cost. --}}
                @foreach ($addons as $item)
                    @php $addonPlan = $item['plan']; @endphp
                    <div class="bl-pm" style="align-items:flex-start;flex-wrap:wrap;gap:12px">
                        <div style="flex:1;min-width:170px">
                            <div class="bl-pm__num">{{ $addonPlan->name }}</div>
                            <div class="bl-pm__exp">
                                {{ $item['price']->formatted() }}
                                per {{ $item['price']->months() > 1 ? 'year' : 'month' }} each
                                @if ($item['owned'] > 0)
                                    <span class="bl-badge bl-badge--blue" style="margin-left:6px">
                                        {{ $item['owned'] }} active · {{ $item['line_total'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach

                <a href="{{ route('billing.addons', ['client' => $client->slug]) }}"
                   class="bl-btn bl-btn--primary bl-btn--sm" style="width:100%;margin-top:14px;justify-content:center">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    {{ $addonTotal > 0 ? 'Manage add-ons' : 'Add seats or agents' }}
                </a>

                <p class="bl-note">
                    Extra capacity is billed on your existing invoice and prorated from the day you
                    add it. Remove any add-on at any time.
                </p>
            </div>
        @endif

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
                                    <i data-lucide="trash" class="w-3.5 h-3.5"></i>
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

        {{-- Billing details.
             What replaced the hosted Stripe portal. Everything the portal
             offered now lives on this page: cards above, invoices below, and
             the name, country and tax number that appear on an invoice here.
             Saved locally first and pushed to Stripe second, so the paperwork is
             right the moment it is saved — including for a workspace that has
             never paid and so has no Stripe customer yet. --}}
        @if ($isOwner)
            <div class="bl-card intro-y">
                <div class="bl-card__head">
                    <i data-lucide="receipt" class="w-4 h-4" style="color:#6366f1"></i>
                    <div class="bl-card__title">Billing details</div>
                </div>
                <p style="font-size:13px;color:#64748b;line-height:1.6;margin:0 0 14px">
                    What appears on your invoices.
                </p>

                <form method="POST" action="{{ route('billing.details', ['client' => $client->slug]) }}">
                    @csrf @method('PATCH')

                    @php
                        $detailFields = [
                            ['billing_name',    'Billed to',      'text',  $client->name, 'Company or person'],
                            ['billing_email',   'Invoice email',  'email', '',            'accounts@example.com'],
                            ['billing_country', 'Country code',   'text',  '',            'PK'],
                            ['billing_tax_id',  'Tax number',     'text',  '',            'NTN / GST / VAT'],
                        ];
                    @endphp

                    @foreach ($detailFields as [$name, $label, $type, $fallback, $placeholder])
                        <div style="display:flex;flex-direction:column;gap:5px;margin-bottom:11px;">
                            <label for="{{ $name }}" style="font:650 11.5px system-ui,sans-serif;color:#334155;">
                                {{ $label }}
                            </label>
                            <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}"
                                   value="{{ old($name, $client->{$name}) }}"
                                   placeholder="{{ $placeholder ?: $fallback }}"
                                   @if ($name === 'billing_country') maxlength="2" style="text-transform:uppercase" @endif
                                   style="padding:9px 11px;border:1px solid #e2e8f0;border-radius:8px;
                                          font:13px system-ui,sans-serif;color:#0f172a;background:#fff;">
                            @error($name)
                                <span style="font-size:11.5px;color:#b91c1c;line-height:1.45">{{ $message }}</span>
                            @enderror
                        </div>
                    @endforeach

                    <button type="submit" class="bl-btn bl-btn--ghost" style="width:100%;margin-top:4px">
                        Save details
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
