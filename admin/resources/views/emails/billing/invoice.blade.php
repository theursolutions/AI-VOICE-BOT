{{--
    Payment receipt / invoice email.

    Sent once a Stripe invoice is actually PAID, so the tone is a receipt, not
    a demand: the amount and the fact that it's settled come first, the line
    detail second, and the "what you now have" plan summary third — that last
    part is the bit customers actually keep the email for.

    Same constraints as the rest of emails/: inline styles, table layout, no
    <style> block and no CSS custom properties. Gmail strips them, Outlook
    ignores half of them, and a receipt that renders as unstyled text looks
    like a phishing attempt.

    Money is formatted by the caller (App\Mail\InvoicePaidMail) so cents never
    become floats in a template.
--}}
@component('emails.layout', [
    'heading'   => $heading,
    'preheader' => $preheader,
])
    <p style="margin:0 0 22px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        <strong style="font-weight:700; color:#0f172a;">Hi {{ $name ?: 'there' }},</strong><br>
        {{ $intro }}
    </p>

    {{-- ── Amount paid: the one thing that must survive a 2-second skim ── --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="margin:0 0 26px 0; background-color:#f0f9ff; border:1px solid #bae6fd; border-radius:12px;">
        <tr>
            <td align="center" style="padding:26px 20px;">
                <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#0369a1;">
                    Amount paid
                </div>
                <div style="margin-top:8px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:38px; line-height:1.1; font-weight:800; color:#0b1220; letter-spacing:-1px;">
                    {{ $total }}
                </div>
                <div style="margin-top:8px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#0369a1;">
                    Charged in {{ $currency }} on {{ $paidOn }}
                </div>

                {{-- Paid pill --}}
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:14px auto 0 auto;">
                    <tr>
                        <td style="padding:6px 16px; background-color:#dcfce7; border-radius:999px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; color:#15803d;">
                            ✓&nbsp; Paid — no action needed
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- ── Invoice meta ─────────────────────────────────────────────── --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px 0;">
        <tr>
            <td width="50%" valign="top" style="padding:0 8px 14px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12.5px; line-height:1.6; color:#64748b;">
                <div style="font-weight:700; color:#94a3b8; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">Invoice</div>
                <div style="color:#0f172a; font-weight:600;">{{ $number }}</div>
            </td>
            <td width="50%" valign="top" style="padding:0 0 14px 8px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12.5px; line-height:1.6; color:#64748b;">
                <div style="font-weight:700; color:#94a3b8; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">Billed to</div>
                <div style="color:#0f172a; font-weight:600;">{{ $workspace }}</div>
                @if ($billedToEmail)
                    <div>{{ $billedToEmail }}</div>
                @endif
            </td>
        </tr>
        @if ($periodLabel)
            <tr>
                <td colspan="2" style="padding:0 0 14px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12.5px; line-height:1.6; color:#64748b;">
                    <div style="font-weight:700; color:#94a3b8; font-size:11px; letter-spacing:.06em; text-transform:uppercase;">Billing period</div>
                    <div style="color:#0f172a; font-weight:600;">{{ $periodLabel }}</div>
                </td>
            </tr>
        @endif
    </table>

    {{-- ── Line items ───────────────────────────────────────────────── --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="margin:10px 0 0 0; border:1px solid #e2e8f0; border-radius:12px; border-collapse:separate; overflow:hidden;">
        <tr>
            <td style="padding:11px 16px; background-color:#f8fafc; border-bottom:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#64748b;">
                Description
            </td>
            <td align="right" style="padding:11px 16px; background-color:#f8fafc; border-bottom:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#64748b;">
                Amount
            </td>
        </tr>

        @foreach ($lines as $line)
            <tr>
                <td style="padding:13px 16px; border-bottom:1px solid #f1f5f9; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.5; color:#0f172a;">
                    {{ $line['description'] }}
                    @if ($line['quantity'] > 1)
                        <span style="color:#64748b;">&nbsp;× {{ $line['quantity'] }}</span>
                    @endif
                    @if (! empty($line['period']))
                        <div style="margin-top:3px; font-size:11.5px; color:#94a3b8;">{{ $line['period'] }}</div>
                    @endif
                </td>
                <td align="right" valign="top" style="padding:13px 16px; border-bottom:1px solid #f1f5f9; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; font-weight:600; color:#0f172a; white-space:nowrap;">
                    {{ $line['amount'] }}
                </td>
            </tr>
        @endforeach

        @if ($subtotal !== null)
            <tr>
                <td align="right" style="padding:11px 16px 3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#64748b;">Subtotal</td>
                <td align="right" style="padding:11px 16px 3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#334155; white-space:nowrap;">{{ $subtotal }}</td>
            </tr>
        @endif

        {{-- Tax is ALWAYS shown, including when it is zero. A receipt that
             simply omits the line leaves the reader unable to tell whether tax
             was charged and hidden, or genuinely not charged — and a business
             customer can't file it either way. Each rate is named with its
             percentage and jurisdiction; a zero total carries the reason. --}}
        @foreach ($taxLines as $taxLine)
            <tr>
                <td align="right" style="padding:3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#64748b;">
                    {{ $taxLine['label'] }}
                    @if ($taxLine['percentage'] !== null)
                        <span style="color:#94a3b8;">({{ $taxLine['percentage'] }}%)</span>
                    @endif
                    @if ($taxLine['jurisdiction'])
                        <span style="color:#94a3b8;">· {{ $taxLine['jurisdiction'] }}</span>
                    @endif
                    @if ($taxLine['inclusive'])
                        <span style="color:#94a3b8;">· included</span>
                    @endif
                </td>
                <td align="right" style="padding:3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#334155; white-space:nowrap;">
                    {{ $taxLine['amount'] }}
                </td>
            </tr>
        @endforeach

        @if (empty($taxLines))
            <tr>
                <td align="right" style="padding:3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#64748b;">
                    Tax
                    @if ($taxNote)
                        <span style="color:#94a3b8;">· {{ $taxNote }}</span>
                    @endif
                </td>
                <td align="right" style="padding:3px 16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#334155; white-space:nowrap;">{{ $tax }}</td>
            </tr>
        @endif

        <tr>
            <td align="right" style="padding:12px 16px 16px 16px; border-top:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; font-weight:700; color:#0f172a;">
                Total paid
            </td>
            <td align="right" style="padding:12px 16px 16px 16px; border-top:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:800; color:#0f172a; white-space:nowrap;">
                {{ $total }}
            </td>
        </tr>
    </table>

    {{-- Payment method + tax registration details --}}
    @if ($cardLabel)
        <p style="margin:14px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12.5px; color:#64748b;">
            Paid with {{ $cardLabel }}
        </p>
    @endif

    @if ($taxIds || $sellerTaxId)
        <p style="margin:8px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#94a3b8;">
            @if ($sellerTaxId)
                Our tax registration: <span style="color:#64748b;">{{ $sellerTaxId }}</span><br>
            @endif
            @foreach ($taxIds as $taxId)
                Your tax ID: <span style="color:#64748b;">{{ $taxId }}</span><br>
            @endforeach
        </p>
    @endif

    {{-- ── What the plan includes ───────────────────────────────────── --}}
    @if ($planName)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
               style="margin:28px 0 0 0; background-color:#fbfbfe; border:1px solid #e7e9f0; border-radius:12px;">
            <tr>
                <td style="padding:20px 20px 6px 20px;">
                    <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#94a3b8;">
                        Your plan
                    </div>
                    <div style="margin-top:5px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:18px; font-weight:800; color:#0b1220; letter-spacing:-.2px;">
                        {{ $planName }}
                        <span style="font-size:13px; font-weight:600; color:#64748b;">· {{ $intervalLabel }}</span>
                    </div>
                    @if ($renewsOn)
                        <div style="margin-top:5px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12.5px; color:#64748b;">
                            Renews on {{ $renewsOn }}
                        </div>
                    @endif
                </td>
            </tr>

            @if (! empty($highlights))
                <tr>
                    <td style="padding:10px 20px 20px 20px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            @foreach ($highlights as $item)
                                <tr>
                                    <td width="18" valign="top" style="padding:4px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#12b76a; font-weight:700;">✓</td>
                                    <td style="padding:4px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.5; color:#475467;">{{ $item }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>
            @endif
        </table>
    @endif

    {{-- ── Actions ──────────────────────────────────────────────────── --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:26px;">
        <tr>
            <td align="center">
                <a href="{{ $invoiceUrl }}"
                   style="display:inline-block; margin:0 4px 10px 4px; padding:14px 30px; border-radius:10px; background-color:#3b82f6; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14.5px; font-weight:700; color:#ffffff; text-decoration:none;">
                    View invoice
                </a>
                @if ($pdfUrl)
                    <a href="{{ $pdfUrl }}"
                       style="display:inline-block; margin:0 4px 10px 4px; padding:14px 30px; border-radius:10px; background-color:#eff6ff; border:1px solid #bfdbfe; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14.5px; font-weight:700; color:#1d4ed8; text-decoration:none;">
                        Download PDF
                    </a>
                @endif
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.65; color:#64748b;">
        You can see every invoice and your current usage any time in
        <a href="{{ $billingUrl }}" style="color:#2563eb; text-decoration:none; font-weight:600;">Billing</a>.
        Questions about this charge? Just reply to this email.
    </p>

    <p style="margin:18px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.65; color:#94a3b8;">
        Workspace: <strong style="color:#475569;">{{ $workspace }}</strong>
    </p>
@endcomponent
