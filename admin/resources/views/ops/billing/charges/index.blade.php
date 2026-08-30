@extends('layouts.ops')

@section('content')
@include('billing._styles')

<style>
    .ch-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin:18px 0 20px; }
    .ch-stat { background:#fff; border:1px solid #e2e8f0; border-radius:13px; padding:14px 16px; }
    .ch-stat__num { font-size:20px; font-weight:800; color:#0f172a; font-variant-numeric:tabular-nums; }
    .ch-stat__lbl { font-size:11.5px; color:#64748b; margin-top:3px; }
    .ch-stat--paid .ch-stat__num { color:#15803d; }
    .ch-stat--pending .ch-stat__num { color:#b45309; }
    .ch-stat--failed .ch-stat__num { color:#b91c1c; }

    .ch-filters {
        display:flex; gap:9px; flex-wrap:wrap; align-items:center; margin-bottom:16px;
        background:#fff; border:1px solid #e2e8f0; border-radius:13px; padding:12px 14px;
    }
    .ch-filters select, .ch-filters input {
        border:1px solid #e2e8f0; border-radius:9px; padding:7px 11px; font-size:13px;
        color:#0f172a; background:#f8fafc;
    }
    .ch-filters input { min-width:230px; }

    .ch-table { width:100%; border-collapse:collapse; background:#fff; border-radius:13px; overflow:hidden; }
    .ch-table th {
        text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
        color:#94a3b8; font-weight:700; padding:11px 14px; border-bottom:1px solid #e2e8f0; background:#f8fafc;
    }
    .ch-table td { padding:12px 14px; font-size:13px; color:#334155; border-bottom:1px solid #f1f5f9; }
    .ch-table tr:last-child td { border-bottom:0; }
    .ch-table tr:hover td { background:#f8fafc; }
    .ch-amt { font-variant-numeric:tabular-nums; font-weight:700; color:#0f172a; white-space:nowrap; }
    .ch-ref { font-family:ui-monospace,monospace; font-size:11.5px; color:#64748b; }
    .ch-when { font-size:11.5px; color:#94a3b8; white-space:nowrap; }
    .ch-who { font-weight:650; color:#0f172a; }

    .ch-note {
        display:flex; gap:10px; font-size:12.5px; line-height:1.6; color:#92400e;
        background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:12px 14px; margin-bottom:16px;
    }
    .ch-info {
        font-size:12px; color:#64748b; line-height:1.6; margin-top:14px;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:11px; padding:12px 14px;
    }

    html.dark .ch-stat, html.dark .ch-filters, html.dark .ch-table { background:#1e293b; border-color:#334155; }
    html.dark .ch-table th { background:#0f172a; border-bottom-color:#334155; color:#64748b; }
    html.dark .ch-table td { border-bottom-color:#0f172a; color:#cbd5e1; }
    html.dark .ch-stat__num, html.dark .ch-amt, html.dark .ch-who { color:#f1f5f9; }
    html.dark .ch-info { background:#0f172a; border-color:#334155; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-2">
    <div class="mr-auto">
        <h2 class="text-lg font-medium">Payments</h2>
        <p style="font-size:13px;color:#64748b;margin-top:3px">
            Every payment taken through a payment gateway — paid, pending and failed.
        </p>
    </div>
    <a href="{{ route('ops.payments.index') }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="settings" class="w-4 h-4"></i> Payment settings
    </a>
</div>

{{-- The state worth acting on, surfaced rather than buried behind a filter. --}}
@if ($stale > 0)
    <div class="ch-note intro-y">
        <i data-lucide="alert-triangle" class="w-5 h-5" style="flex:none"></i>
        <div>
            <strong>{{ $stale }} payment{{ $stale === 1 ? '' : 's' }} stuck pending for over two hours.</strong>
            A payment that starts and never resolves is either an abandoned checkout or — the case that
            matters — one the provider took whose webhook never reached us. They look identical from here,
            so each is worth opening and checking against the provider's dashboard.
            <div style="margin-top:8px">
                <a href="{{ route('ops.billing.charges.index', ['status' => 'pending']) }}"
                   class="bl-btn bl-btn--ghost bl-btn--sm">Show pending</a>
            </div>
        </div>
    </div>
@endif

{{-- ── Totals, per currency ───────────────────────────────────────── --}}
@foreach ($totals as $currency => $byStatus)
    <div style="font-size:12px;font-weight:700;color:#64748b;margin:{{ $loop->first ? '4px' : '18px' }} 0 -6px">
        {{ $currency }}
    </div>
    <div class="ch-stats">
        @foreach (['paid' => 'Collected', 'pending' => 'Awaiting payment', 'failed' => 'Failed'] as $status => $label)
            @php $row = $byStatus[$status] ?? ['count' => 0, 'amount' => 0]; @endphp
            <div class="ch-stat ch-stat--{{ $status }}">
                <div class="ch-stat__num">{{ tva_money((int) $row['amount'], $currency, false) }}</div>
                <div class="ch-stat__lbl">{{ $label }} · {{ number_format($row['count']) }} payment{{ $row['count'] === 1 ? '' : 's' }}</div>
            </div>
        @endforeach
    </div>
@endforeach

{{-- ── Filters ────────────────────────────────────────────────────── --}}
<form method="GET" class="ch-filters intro-y">
    <select name="status" onchange="this.form.submit()">
        <option value="">Any status</option>
        @foreach (['paid' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed'] as $v => $l)
            <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
        @endforeach
    </select>

    <select name="gateway" onchange="this.form.submit()">
        <option value="">Any provider</option>
        @foreach ($gateways as $g)
            <option value="{{ $g }}" @selected($filters['gateway'] === $g)>{{ ucfirst($g) }}</option>
        @endforeach
    </select>

    <select name="purpose" onchange="this.form.submit()">
        <option value="">Plans and add-ons</option>
        <option value="plan" @selected($filters['purpose'] === 'plan')>Plans only</option>
        <option value="addon" @selected($filters['purpose'] === 'addon')>Add-ons only</option>
    </select>

    <input type="text" name="q" value="{{ $filters['q'] }}"
           placeholder="Workspace, reference or provider id…">

    <button type="submit" class="bl-btn bl-btn--primary bl-btn--sm">
        <i data-lucide="search" class="w-4 h-4"></i> Search
    </button>

    @if (array_filter($filters))
        <a href="{{ route('ops.billing.charges.index') }}" class="bl-btn bl-btn--ghost bl-btn--sm">Clear</a>
    @endif
</form>

{{-- ── The list ───────────────────────────────────────────────────── --}}
<div class="intro-y" style="overflow-x:auto">
    <table class="ch-table">
        <thead>
            <tr>
                <th>Workspace</th>
                <th>For</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Provider</th>
                <th>Reference</th>
                <th>When</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($charges as $c)
                <tr>
                    <td>
                        @if ($c->client_slug)
                            <a href="{{ route('ops.billing.workspaces.show', \App\Support\Hashid::encode($c->client_id)) }}"
                               class="ch-who">{{ $c->client_name }}</a>
                        @else
                            <span class="ch-who">Workspace #{{ $c->client_id }}</span>
                        @endif
                        <div class="ch-when">{{ $c->billing_country ?: '—' }}</div>
                    </td>
                    <td>
                        {{ $c->plan_name ?: '—' }}
                        <div class="ch-when">
                            {{ $c->purpose === 'addon' ? 'Add-on' : 'Plan' }}{{ $c->interval ? ' · ' . $c->interval : '' }}
                        </div>
                    </td>
                    <td class="ch-amt">{{ tva_money((int) $c->amount_cents, $c->currency, false) }}</td>
                    <td>
                        <span class="bl-badge bl-badge--{{ $c->status === 'paid' ? 'green' : ($c->status === 'pending' ? 'amber' : 'red') }}">
                            {{ $c->status }}
                        </span>
                        @if ($c->status === 'failed' && $c->failure_reason)
                            <div class="ch-when" title="{{ $c->failure_reason }}">
                                {{ \Illuminate\Support\Str::limit($c->failure_reason, 40) }}
                            </div>
                        @endif
                    </td>
                    <td>{{ ucfirst($c->gateway) }}</td>
                    <td>
                        <a href="{{ route('ops.billing.charges.show', $c->reference) }}" class="ch-ref">
                            {{ $c->reference }}
                        </a>
                    </td>
                    <td class="ch-when">
                        {{ \Illuminate\Support\Carbon::parse($c->created_at)->format('j M Y H:i') }}
                        @if ($c->paid_at)
                            <div style="color:#15803d">paid {{ \Illuminate\Support\Carbon::parse($c->paid_at)->diffForHumans() }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding:34px;text-align:center;color:#94a3b8">
                        @if (array_filter($filters))
                            No payment matches those filters.
                        @else
                            No gateway payments yet.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($charges->hasPages())
    <div class="intro-y" style="margin-top:16px">{{ $charges->links() }}</div>
@endif

{{--
    Said plainly rather than left to be discovered: this is not every payment
    the business takes, and an operator who assumed it was would reconcile
    against a number that is missing a whole provider.
--}}
<div class="ch-info intro-y">
    <strong>Stripe payments are not listed here.</strong>
    Stripe bills its own subscriptions and never creates a row in this table — its payments live in
    the Stripe dashboard, and appear per workspace on that workspace's own billing page.
    This page covers the gateways we raise charges for ourselves.
</div>
@endsection
