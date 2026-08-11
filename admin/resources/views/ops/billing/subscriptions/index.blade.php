@extends('layouts.ops')

@section('content')
<style>
    .sb-stats { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); margin-bottom:16px; }
    .sb-stat { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px 18px; }
    .sb-stat__num { font-size:22px; font-weight:800; color:#0f172a; font-variant-numeric:tabular-nums; }
    .sb-stat__lbl { font-size:10.5px; text-transform:uppercase; letter-spacing:.07em; color:#94a3b8; font-weight:700; margin-top:3px; }

    .sb-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:20px; }
    .sb-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
    .sb-filters select, .sb-filters input {
        border:1px solid #e2e8f0; border-radius:9px; padding:8px 10px; font-size:13px;
        background:#fff; color:#0f172a;
    }
    .sb-label { font-size:10.5px; color:#64748b; text-transform:uppercase; letter-spacing:.06em; font-weight:700; margin-bottom:5px; display:block; }

    .sb-table { width:100%; border-collapse:collapse; font-size:13px; }
    .sb-table th, .sb-table td { text-align:left; padding:10px; border-bottom:1px solid #f1f5f9; color:#475569; vertical-align:top; }
    .sb-table th { font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; }
    .sb-ws { font-weight:600; color:#0f172a; }
    .sb-ref { font-family:ui-monospace,monospace; font-size:10.5px; color:#94a3b8; }
    .sb-amt { font-variant-numeric:tabular-nums; font-weight:700; color:#0f172a; }

    .sb-pill { font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:3px 8px; border-radius:999px; white-space:nowrap; }
    .sb-pill--green { background:#dcfce7; color:#15803d; }
    .sb-pill--blue  { background:#dbeafe; color:#1d4ed8; }
    .sb-pill--amber { background:#fef3c7; color:#b45309; }
    .sb-pill--red   { background:#fee2e2; color:#b91c1c; }
    .sb-pill--slate { background:#f1f5f9; color:#475569; }

    .sb-btn {
        display:inline-flex; align-items:center; gap:6px; cursor:pointer; text-decoration:none;
        font-size:11.5px; font-weight:600; padding:5px 10px; border-radius:7px;
        border:1px solid #e2e8f0; background:#fff; color:#334155;
    }
    .sb-btn--primary { background:var(--tva-gradient, linear-gradient(135deg,#6366f1,#8b5cf6)); color:#fff; border-color:transparent; }
    .sb-inline { display:inline-flex; gap:5px; align-items:center; }
    .sb-inline input[type=number] { width:56px; border:1px solid #e2e8f0; border-radius:7px; padding:4px 6px; font-size:11.5px; background:#fff; }
    .sb-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }

    .sb-note { font-size:11.5px; color:#94a3b8; margin-top:14px; line-height:1.55; }

    html.dark .sb-card, html.dark .sb-stat { background:#1e293b; border-color:#334155; }
    html.dark .sb-stat__num, html.dark .sb-ws, html.dark .sb-amt { color:#f1f5f9; }
    html.dark .sb-table th, html.dark .sb-table td { border-color:#334155; }
    html.dark .sb-btn, html.dark .sb-filters select, html.dark .sb-filters input,
    html.dark .sb-inline input { background:#0f172a; border-color:#334155; color:#cbd5e1; }
</style>

<div class="content">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📈</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Subscriptions</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Who is on what, and what's failing. Paid state comes from Stripe — there is deliberately no
                “mark as active” button here.
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="{{ route('ops.billing.subscriptions.events') }}" class="sb-btn">
                <i data-lucide="webhook" class="w-3.5 h-3.5"></i>
                Stripe events
                @if ($failedEvents > 0)
                    <span class="sb-pill sb-pill--red">{{ $failedEvents }} failed</span>
                @endif
            </a>
            <a href="{{ route('ops.billing.plans.index') }}" class="sb-btn">
                <i data-lucide="credit-card" class="w-3.5 h-3.5"></i> Plans
            </a>
        </div>
    </div>

    {{-- ── Headline numbers ───────────────────────────────────────── --}}
    <div class="sb-stats mt-5">
        <div class="sb-stat">
            <div class="sb-stat__num">${{ number_format($mrrCents / 100, 0) }}</div>
            <div class="sb-stat__lbl">Est. MRR (USD)</div>
        </div>
        @foreach (['active' => 'Active', 'trialing' => 'Trialing', 'free' => 'On free window', 'past_due' => 'Payment failed', 'expired' => 'Expired', 'canceled' => 'Canceled'] as $key => $label)
            <div class="sb-stat">
                <div class="sb-stat__num">{{ number_format($statuses[$key] ?? 0) }}</div>
                <div class="sb-stat__lbl">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    <div class="sb-card">
        {{-- ── Filters ────────────────────────────────────────────── --}}
        <form method="GET" class="sb-filters">
            <div>
                <label class="sb-label" for="f-status">Status</label>
                <select id="f-status" name="status">
                    <option value="">All</option>
                    @foreach (['free','trialing','active','past_due','expired','canceled','unpaid','incomplete','paused'] as $st)
                        <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="sb-label" for="f-plan">Plan</label>
                <select id="f-plan" name="plan">
                    <option value="">All</option>
                    @foreach ($plans as $p)
                        <option value="{{ $p->id }}" @selected((int) $filters['plan'] === $p->id)>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="sb-label" for="f-q">Search</label>
                <input id="f-q" name="q" value="{{ $filters['q'] }}" placeholder="workspace or Stripe ref">
            </div>
            <button type="submit" class="sb-btn sb-btn--primary" style="padding:8px 14px">
                <i data-lucide="search" class="w-3.5 h-3.5"></i> Filter
            </button>
        </form>

        {{-- ── Table ──────────────────────────────────────────────── --}}
        <div style="overflow-x:auto">
            <table class="sb-table">
                <thead>
                    <tr>
                        <th>Workspace</th><th>Plan</th><th>Status</th><th>Amount</th>
                        <th>Renews / ends</th><th>Stripe</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($subscriptions as $sub)
                        <tr>
                            <td>
                                <div class="sb-ws">{{ $sub->client?->name ?? '(deleted)' }}</div>
                                <div class="sb-ref">{{ $sub->client?->slug }}</div>
                            </td>
                            <td>
                                {{ $sub->plan?->name ?? '—' }}
                                @if ($sub->interval)
                                    <div class="sb-ref">{{ $sub->interval }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="sb-pill sb-pill--{{ $sub->statusColor() }}">{{ $sub->statusLabel() }}</span>
                                @if ($sub->read_only_since)
                                    <div class="sb-ref" style="margin-top:3px">read-only since {{ $sub->read_only_since->format('j M') }}</div>
                                @endif
                                @if ($sub->purge_after)
                                    <div class="sb-ref" style="color:#b91c1c">purge {{ $sub->purge_after->format('j M Y') }}</div>
                                @endif
                            </td>
                            <td class="sb-amt">
                                {{ $sub->unit_amount ? '$' . number_format($sub->unit_amount / 100, 2) : '—' }}
                            </td>
                            <td>
                                @if ($sub->isFree() && $sub->free_ends_at)
                                    free until {{ $sub->free_ends_at->format('j M Y') }}
                                @elseif ($sub->cancel_at_period_end && $sub->ends_at)
                                    ends {{ $sub->ends_at->format('j M Y') }}
                                @elseif ($sub->current_period_end)
                                    {{ $sub->current_period_end->format('j M Y') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($sub->stripe_subscription_ref)
                                    <div class="sb-ref">{{ $sub->stripe_subscription_ref }}</div>
                                    @if ($sub->stripe_status && $sub->stripe_status !== $sub->status)
                                        <span class="sb-pill sb-pill--amber">stripe: {{ $sub->stripe_status }}</span>
                                    @endif
                                @else
                                    <span class="sb-pill sb-pill--slate">no stripe object</span>
                                @endif
                            </td>
                            <td>
                                <div class="sb-actions">
                                    {{-- Extend free access: moves the clock and clears the degraded
                                         flags. Never fabricates paid state. --}}
                                    <form method="POST" action="{{ route('ops.billing.subscriptions.extend-free', ['id' => $sub->id]) }}" class="sb-inline">
                                        @csrf
                                        <input type="number" name="days" min="1" max="90" value="7" title="Days">
                                        <button type="submit" class="sb-btn" title="Extend free access">
                                            <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </form>

                                    @if ($sub->stripe_subscription_ref)
                                        <form method="POST" action="{{ route('ops.billing.subscriptions.reconcile', ['id' => $sub->id]) }}">
                                            @csrf
                                            <button type="submit" class="sb-btn" title="Re-pull from Stripe">
                                                <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </form>
                                    @endif

                                    @if ($sub->client)
                                        <form method="POST" action="{{ route('ops.billing.subscriptions.waive-trial', ['clientId' => $sub->client_id]) }}"
                                              onsubmit="return confirm('Waive the free-window blocks for this workspace so they can start a fresh free period?');">
                                            @csrf
                                            <button type="submit" class="sb-btn" title="Waive trial-abuse blocks">
                                                <i data-lucide="unlock" class="w-3.5 h-3.5"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" style="color:#94a3b8;padding:22px">No subscriptions match those filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px">{{ $subscriptions->links() }}</div>

        <p class="sb-note">
            <strong>Est. MRR</strong> normalises every paying subscription to a month (annual ÷ 12), so it's
            committed monthly revenue rather than cash received.
            <br>
            <strong>No “activate” action:</strong> paid state is written only by the Stripe webhook. Comping a
            customer is done with a 100%-off coupon in Stripe or a private plan — a manual override would
            leave a workspace with access and nothing to reconcile against.
            <br>
            If a status looks stale, check <a href="{{ route('ops.billing.subscriptions.events') }}" style="color:#6366f1">Stripe events</a>
            for a failed webhook first, then use the re-pull button.
        </p>
    </div>
</div>
@endsection
