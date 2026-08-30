<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $brandName }} — Receipt {{ $charge->reference }}</title>
    <style>
        * { box-sizing:border-box; }
        body {
            margin:0; padding:32px 20px; background:#f1f5f9; color:#0f172a;
            font:14px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;
        }
        .rc { max-width:660px; margin:0 auto; background:#fff; border-radius:16px; padding:40px 44px; }

        .rc__head { display:flex; justify-content:space-between; gap:20px; align-items:flex-start; margin-bottom:34px; }
        .rc__brand { font-size:19px; font-weight:800; letter-spacing:-.01em; }
        .rc__brand small { display:block; font-size:12px; font-weight:500; color:#64748b; margin-top:3px; }
        .rc__badge {
            font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
            background:#dcfce7; color:#166534; padding:5px 11px; border-radius:999px; white-space:nowrap;
        }

        .rc__title { font-size:23px; font-weight:800; margin:0 0 4px; }
        .rc__ref { font-family:ui-monospace,monospace; font-size:12.5px; color:#64748b; margin-bottom:30px; }

        .rc__grid { display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-bottom:30px; }
        .rc__label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:700; margin-bottom:5px; }
        .rc__value { font-size:13.5px; }

        table { width:100%; border-collapse:collapse; margin-bottom:22px; }
        th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em;
             color:#94a3b8; font-weight:700; padding-bottom:9px; border-bottom:1px solid #e2e8f0; }
        th:last-child, td:last-child { text-align:right; }
        td { padding:13px 0; border-bottom:1px solid #f1f5f9; font-size:13.5px; }
        .rc__total td { border-bottom:0; padding-top:16px; font-size:17px; font-weight:800; }
        .rc__muted { color:#64748b; font-size:12.5px; }

        .rc__foot { margin-top:30px; padding-top:20px; border-top:1px solid #e2e8f0;
                    font-size:11.5px; color:#94a3b8; line-height:1.7; }

        .rc__print {
            display:inline-flex; align-items:center; gap:8px; margin-bottom:18px;
            background:#4f46e5; color:#fff; border:0; border-radius:10px;
            padding:10px 18px; font:700 13.5px system-ui,sans-serif; cursor:pointer;
        }

        /* The printed page is the deliverable — the button and the grey
           surround are screen furniture and must not appear on it. */
        @media print {
            body { background:#fff; padding:0; }
            .rc { border-radius:0; padding:0; max-width:none; }
            .rc__print { display:none; }
        }
    </style>
</head>
<body>
    {{-- "Download" is print-to-PDF, which every browser and phone can do
         without us shipping a PDF library or a headless renderer. --}}
    <button type="button" class="rc__print" onclick="window.print()">Download / print</button>

    <div class="rc">
        <div class="rc__head">
            <div class="rc__brand">
                {{ $brandName }}
                @if ($brandAddress)
                    <small>{!! nl2br(e($brandAddress)) !!}</small>
                @endif
            </div>
            <span class="rc__badge">Paid</span>
        </div>

        <h1 class="rc__title">Receipt</h1>
        <div class="rc__ref">{{ $charge->reference }}</div>

        <div class="rc__grid">
            <div>
                <div class="rc__label">Billed to</div>
                <div class="rc__value">
                    <strong>{{ $client->name }}</strong><br>
                    @if ($client->billing_email){{ $client->billing_email }}<br>@endif
                    @if ($taxId){{ $taxId }}<br>@endif
                    {{ $countryName }}
                </div>
            </div>
            <div>
                <div class="rc__label">Payment</div>
                <div class="rc__value">
                    Paid {{ \Illuminate\Support\Carbon::parse($charge->paid_at)->format('j F Y') }}<br>
                    <span class="rc__muted">
                        @if ($charge->period_start && $charge->period_end)
                            Covers {{ \Illuminate\Support\Carbon::parse($charge->period_start)->format('j M Y') }}
                            – {{ \Illuminate\Support\Carbon::parse($charge->period_end)->format('j M Y') }}
                        @endif
                    </span>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr><th>Description</th><th>Amount</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        {{ $description }}
                        @if ($charge->interval)
                            <div class="rc__muted">Billed {{ $charge->interval }}</div>
                        @endif
                    </td>
                    <td>{{ tva_money($subtotal, $charge->currency, false) }}</td>
                </tr>

                @if ($tax > 0)
                    <tr>
                        <td>
                            {{ $taxInclusive ? 'Tax (included above)' : 'Tax' }}
                            @if ($taxRate > 0)
                                <div class="rc__muted">{{ rtrim(rtrim(number_format($taxRate, 2), '0'), '.') }}%</div>
                            @endif
                        </td>
                        <td>{{ tva_money($tax, $charge->currency, false) }}</td>
                    </tr>
                @endif

                <tr class="rc__total">
                    <td>Total paid</td>
                    <td>{{ tva_money((int) $charge->amount_cents, $charge->currency, false) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="rc__foot">
            @if ($resellerNote)
                {{-- A Merchant of Record is the seller on its own invoice. Ours
                     is a record of the same payment, not a second demand for
                     it — said here so nobody files two. --}}
                {{ $resellerNote }}<br><br>
            @endif
            Paid in {{ strtoupper($charge->currency) }}. This receipt confirms a completed payment;
            no further action is needed.<br>
            Questions about this payment? Quote reference <strong>{{ $charge->reference }}</strong>.
        </div>
    </div>
</body>
</html>
