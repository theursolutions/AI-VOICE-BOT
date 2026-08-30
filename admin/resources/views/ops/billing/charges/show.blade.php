@extends('layouts.ops')

@section('content')
@include('billing._styles')

<style>
    .cs-wrap { display:grid; gap:18px; grid-template-columns:1fr; max-width:1000px; }
    @media (min-width:940px) { .cs-wrap { grid-template-columns:1fr 1fr; align-items:start; } }

    .cs-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:18px 20px; }
    .cs-card__title { font-size:14px; font-weight:750; color:#0f172a; margin-bottom:14px; }

    .cs-row { display:flex; gap:14px; font-size:13px; padding:8px 0; border-bottom:1px solid #f1f5f9; }
    .cs-row:last-child { border-bottom:0; }
    .cs-row dt { color:#64748b; min-width:132px; flex:none; }
    .cs-row dd { margin:0; color:#0f172a; font-weight:600; word-break:break-all; }

    .cs-amount { font-size:26px; font-weight:800; color:#0f172a; font-variant-numeric:tabular-nums; }

    .cs-raw {
        font-family:ui-monospace,monospace; font-size:11.5px; line-height:1.6; color:#334155;
        background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:13px;
        max-height:420px; overflow:auto; white-space:pre; margin:0;
    }

    html.dark .cs-card { background:#1e293b; border-color:#334155; }
    html.dark .cs-card__title, html.dark .cs-row dd, html.dark .cs-amount { color:#f1f5f9; }
    html.dark .cs-raw { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .cs-row { border-bottom-color:#0f172a; }
</style>

<div class="intro-y flex items-center gap-3 mt-8 mb-5">
    <a href="{{ route('ops.billing.charges.index') }}" class="bl-btn bl-btn--ghost">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> All payments
    </a>
    <div class="mr-auto"></div>
    <span class="bl-badge bl-badge--{{ $charge->status === 'paid' ? 'green' : ($charge->status === 'pending' ? 'amber' : 'red') }}">
        {{ $charge->status }}
    </span>
</div>

<div class="cs-wrap">
    <div class="cs-card intro-y">
        <div class="cs-card__title">Payment</div>

        <div class="cs-amount" style="margin-bottom:16px">
            {{ tva_money((int) $charge->amount_cents, $charge->currency, false) }}
        </div>

        <dl style="margin:0">
            <div class="cs-row"><dt>Reference</dt><dd>{{ $charge->reference }}</dd></div>
            <div class="cs-row"><dt>Provider</dt><dd>{{ ucfirst($charge->gateway) }}</dd></div>
            <div class="cs-row">
                <dt>Provider's id</dt>
                <dd>{{ $charge->gateway_ref ?: '— not yet returned' }}</dd>
            </div>
            <div class="cs-row"><dt>For</dt><dd>{{ $charge->purpose === 'addon' ? 'Add-on' : 'Plan' }}</dd></div>
            <div class="cs-row"><dt>Interval</dt><dd>{{ $charge->interval ?: '—' }}</dd></div>
            <div class="cs-row">
                <dt>Started</dt>
                <dd>{{ \Illuminate\Support\Carbon::parse($charge->created_at)->format('j M Y, H:i') }}</dd>
            </div>
            <div class="cs-row">
                <dt>Paid</dt>
                <dd>{{ $charge->paid_at ? \Illuminate\Support\Carbon::parse($charge->paid_at)->format('j M Y, H:i') : '—' }}</dd>
            </div>
            @if ($charge->failure_reason)
                <div class="cs-row"><dt>Failure</dt><dd style="color:#b91c1c">{{ $charge->failure_reason }}</dd></div>
            @endif
        </dl>

        @if ($charge->status === 'pending')
            <div class="bl-alert bl-alert--warn" style="margin:16px 0 0">
                <i data-lucide="alert-triangle" class="w-4 h-4" style="flex:none"></i>
                <div>
                    <strong>Still pending.</strong>
                    Check this reference in the provider's dashboard. If it shows as paid there, the
                    webhook never arrived — re-send it from the provider and this row will settle
                    itself, granting the plan as it goes.
                </div>
            </div>
        @endif
    </div>

    <div class="cs-card intro-y">
        <div class="cs-card__title">Workspace &amp; period</div>

        <dl style="margin:0">
            <div class="cs-row">
                <dt>Workspace</dt>
                <dd>
                    @if ($client)
                        <a href="{{ route('ops.billing.workspaces.show', \App\Support\Hashid::encode($client->id)) }}">
                            {{ $client->name }}
                        </a>
                    @else
                        #{{ $charge->client_id }} (deleted)
                    @endif
                </dd>
            </div>
            <div class="cs-row"><dt>Country</dt><dd>{{ $client?->billing_country ?: '—' }}</dd></div>
            <div class="cs-row"><dt>Billing email</dt><dd>{{ $client?->billing_email ?: '—' }}</dd></div>
            <div class="cs-row">
                <dt>Period</dt>
                <dd>
                    {{ $charge->period_start ? \Illuminate\Support\Carbon::parse($charge->period_start)->format('j M Y') : '—' }}
                    →
                    {{ $charge->period_end ? \Illuminate\Support\Carbon::parse($charge->period_end)->format('j M Y') : '—' }}
                </dd>
            </div>
            <div class="cs-row"><dt>Subscription</dt><dd>{{ $charge->subscription_id ?: '— none yet' }}</dd></div>
        </dl>

        @if ($items)
            <div class="cs-card__title" style="margin:20px 0 10px">What was bought</div>
            <pre class="cs-raw">{{ $items }}</pre>
        @endif
    </div>
</div>

@if ($raw)
    <div class="cs-card intro-y" style="max-width:1000px;margin-top:18px">
        <div class="cs-card__title">What the provider sent</div>
        <p style="font-size:12.5px;color:#64748b;line-height:1.6;margin:0 0 12px">
            The payload from the webhook, verbatim. This is what settles an argument when a customer
            says they paid and the record says otherwise.
        </p>
        <pre class="cs-raw">{{ $raw }}</pre>
    </div>
@endif
@endsection
