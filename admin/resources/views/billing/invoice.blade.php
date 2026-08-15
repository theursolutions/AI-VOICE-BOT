@extends('layouts.master')

@section('content')
@php
    $brand   = tva_setting('content.brand_name', 'Serve AI');
    $addr    = tva_setting('content.contact_address', '');
    $email   = tva_setting('content.contact_email', '');
    // Our own NTN/VAT number, if the business has registered one. Editable in
    // site settings rather than hard-coded, because it differs per entity and
    // changes without a deploy.
    $sellerTaxId = tva_setting('content.tax_registration', '');
    $paid    = $invoice['status'] === 'paid';
    $money   = fn (int $cents) => '$' . number_format($cents / 100, 2);
@endphp

@include('billing._styles')

<style>
    .iv-sheet {
        max-width:860px; margin:0 auto; background:#fff; border:1px solid #e2e8f0;
        border-radius:16px; padding:44px 46px;
    }
    .iv-top { display:flex; gap:22px; flex-wrap:wrap; align-items:flex-start; margin-bottom:34px; }
    .iv-brand { display:flex; align-items:center; gap:11px; font-size:19px; font-weight:800; color:#0f172a; }
    .iv-brand img { width:34px; height:34px; object-fit:contain; }
    .iv-meta { margin-left:auto; text-align:right; }
    .iv-meta h1 { font-size:26px; font-weight:800; color:#0f172a; letter-spacing:-.02em; margin:0 0 5px; }
    .iv-meta .num { font-family:ui-monospace,monospace; font-size:12.5px; color:#64748b; }

    .iv-parties { display:grid; gap:26px; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); margin-bottom:32px; }
    .iv-parties h4 {
        font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.1em;
        color:#94a3b8; margin:0 0 7px;
    }
    .iv-parties p { font-size:13px; color:#475569; margin:0; line-height:1.65; }
    .iv-parties strong { color:#0f172a; }

    .iv-table { width:100%; border-collapse:collapse; margin-bottom:22px; }
    .iv-table th {
        font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:#94a3b8;
        font-weight:800; text-align:left; padding:0 0 10px; border-bottom:2px solid #e2e8f0;
    }
    .iv-table td { padding:15px 0; border-bottom:1px solid #f1f5f9; font-size:13.5px; color:#475569; vertical-align:top; }
    .iv-table th:last-child, .iv-table td:last-child { text-align:right; }
    .iv-desc { font-weight:650; color:#0f172a; }
    .iv-period { font-size:11.5px; color:#94a3b8; margin-top:3px; }

    .iv-totals { margin-left:auto; width:100%; max-width:300px; }
    .iv-totals div { display:flex; justify-content:space-between; font-size:13.5px; color:#475569; padding:7px 0; }
    .iv-totals .grand {
        border-top:2px solid #e2e8f0; margin-top:7px; padding-top:13px;
        font-size:18px; font-weight:800; color:#0f172a;
    }

    .iv-stamp {
        display:inline-flex; align-items:center; gap:7px; padding:7px 15px; border-radius:999px;
        font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.07em;
    }
    .iv-stamp--paid { background:#dcfce7; color:#15803d; }
    .iv-stamp--open { background:#fef3c7; color:#b45309; }
    .iv-stamp--void { background:#f1f5f9; color:#475569; }

    .iv-foot { margin-top:36px; padding-top:22px; border-top:1px solid #e2e8f0; font-size:11.5px; color:#94a3b8; line-height:1.7; }

    /* Print: just the invoice — no app chrome, no buttons. */
    @media print {
        body { background:#fff !important; }
        .side-nav, .top-bar, .mobile-menu, .intro-y > .bl-btn, .js-no-print,
        nav, .side-nav__devider { display:none !important; }
        .content, .wrapper, .wrapper-box { margin:0 !important; padding:0 !important; }
        .iv-sheet { border:0; padding:0; max-width:100%; }
    }

    html.dark .iv-sheet { background:#1e293b; border-color:#334155; }
    html.dark .iv-brand, html.dark .iv-meta h1, html.dark .iv-desc,
    html.dark .iv-parties strong, html.dark .iv-totals .grand { color:#f1f5f9; }
    html.dark .iv-table td { border-color:#334155; }
</style>

<div class="intro-y flex flex-wrap items-center gap-3 mt-8 mb-5 js-no-print" style="max-width:860px;margin-left:auto;margin-right:auto">
    <a href="{{ route('billing.index', ['client' => $client->slug]) }}" class="bl-btn bl-btn--ghost mr-auto">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to billing
    </a>
    <button type="button" onclick="window.print()" class="bl-btn bl-btn--ghost">
        <i data-lucide="printer" class="w-4 h-4"></i> Print / save PDF
    </button>
    @if ($invoice['pdf'])
        <a href="{{ $invoice['pdf'] }}" target="_blank" rel="noopener" class="bl-btn bl-btn--primary">
            <i data-lucide="download" class="w-4 h-4"></i> Stripe receipt
        </a>
    @endif
</div>

<div class="iv-sheet intro-y">
    <div class="iv-top">
        <div>
            <div class="iv-brand">
                <img src="{{ serveai_icon() }}" alt="">
                {{ $brand }}
            </div>
            @if ($addr)
                <p style="font-size:11.5px;color:#94a3b8;margin:9px 0 0;max-width:250px;line-height:1.6">{{ $addr }}</p>
            @endif
        </div>

        <div class="iv-meta">
            <h1>Invoice</h1>
            <div class="num">{{ $invoice['number'] ?: $invoice['id'] }}</div>
            <div style="margin-top:11px">
                <span class="iv-stamp iv-stamp--{{ $paid ? 'paid' : ($invoice['status'] === 'open' ? 'open' : 'void') }}">
                    @if ($paid)<i data-lucide="check" class="w-3.5 h-3.5"></i>@endif
                    {{ $paid ? 'Paid' : $invoice['status'] }}
                </span>
            </div>
        </div>
    </div>

    <div class="iv-parties">
        <div>
            <h4>Billed to</h4>
            <p>
                <strong>{{ $invoice['customer_name'] ?: $client->name }}</strong><br>
                {{ $invoice['customer_email'] ?: $client->billing_email }}
            </p>
        </div>
        <div>
            <h4>Invoice date</h4>
            <p>{{ $invoice['created']?->format('j F Y') ?? '—' }}</p>
        </div>
        @if ($paid && $invoice['paid_at'])
            <div>
                <h4>Paid on</h4>
                <p>{{ $invoice['paid_at']->format('j F Y') }}</p>
            </div>
        @endif
        @if ($invoice['period_start'] && $invoice['period_end'])
            <div>
                <h4>Billing period</h4>
                <p>{{ $invoice['period_start']->format('j M Y') }} – {{ $invoice['period_end']->format('j M Y') }}</p>
            </div>
        @endif
    </div>

    <table class="iv-table">
        <thead>
            <tr><th>Description</th><th style="width:70px;text-align:center">Qty</th><th style="width:120px">Amount</th></tr>
        </thead>
        <tbody>
            @forelse ($invoice['lines'] as $line)
                <tr>
                    <td>
                        <div class="iv-desc">{{ $line['description'] }}</div>
                        @if ($line['period_start'] && $line['period_end'])
                            <div class="iv-period">
                                {{ $line['period_start']->format('j M Y') }} – {{ $line['period_end']->format('j M Y') }}
                            </div>
                        @endif
                    </td>
                    <td style="text-align:center">{{ $line['quantity'] }}</td>
                    <td class="bl-amt">{{ $money($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="3" style="color:#94a3b8">No line items.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="iv-totals">
        <div><span>Subtotal</span><span>{{ $money($invoice['subtotal']) }}</span></div>

        {{-- Tax always appears, zero included: omitting the line leaves the
             reader unable to tell "no tax" from "tax hidden in the total".
             Mirrors the receipt email exactly — one source, no drift. --}}
        @forelse (($invoice['tax_lines'] ?? []) as $taxLine)
            <div>
                <span>
                    {{ $taxLine['label'] }}@if ($taxLine['percentage'] !== null) ({{ $taxLine['percentage'] }}%)@endif
                    @if ($taxLine['jurisdiction'])<span style="color:#94a3b8">· {{ $taxLine['jurisdiction'] }}</span>@endif
                    @if ($taxLine['inclusive'])<span style="color:#94a3b8">· included</span>@endif
                </span>
                <span>{{ $money($taxLine['amount']) }}</span>
            </div>
        @empty
            <div>
                <span>Tax @if (! empty($invoice['tax_note']))<span style="color:#94a3b8">· {{ $invoice['tax_note'] }}</span>@endif</span>
                <span>{{ $money($invoice['tax'] ?? 0) }}</span>
            </div>
        @endforelse

        <div class="grand"><span>Total</span><span>{{ $money($invoice['total']) }}</span></div>
        @if ($paid)
            <div style="color:#15803d;font-weight:650"><span>Amount paid</span><span>{{ $money($invoice['amount_paid']) }}</span></div>
        @endif
    </div>

    @if (! empty($invoice['tax_ids']) || $sellerTaxId)
        <div class="iv-foot" style="border:0;padding-bottom:0">
            @if ($sellerTaxId) Our tax registration: <strong>{{ $sellerTaxId }}</strong>. @endif
            @foreach (($invoice['tax_ids'] ?? []) as $taxId)
                Your tax ID: <strong>{{ $taxId }}</strong>.
            @endforeach
        </div>
    @endif

    <div class="iv-foot">
        Charged in {{ $invoice['currency'] }}. This invoice was generated by {{ $brand }} from your Stripe
        billing record.
        @if ($email) Questions? <strong>{{ $email }}</strong> @endif
    </div>
</div>
@endsection
