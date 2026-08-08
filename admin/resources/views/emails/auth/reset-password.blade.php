{{-- Password-reset link. Replaces Laravel's built-in unbranded notification so
     it matches the verification email (same shell, logo, footer, CTAs). --}}
@component('emails.layout', [
    'heading'   => 'Reset your password',
    'preheader' => 'Reset your ' . tva_setting('content.brand_name', 'Serve AI') . ' password — link expires in ' . $ttl . ' minutes.',
])
    <p style="margin:0 0 18px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        <strong style="font-weight:700; color:#0f172a;">Hi {{ $name ?: 'there' }},</strong>
    </p>

    <p style="margin:0 0 28px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        We received a request to reset the password for your account. Click the button below to choose a new one.
    </p>

    {{-- Primary action. Table-wrapped so Outlook renders the background. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center">
                <a href="{{ $url }}"
                   style="display:inline-block; padding:15px 38px; border-radius:10px; background-color:#3b82f6; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none;">
                    Reset Password
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:26px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:1.65; color:#475569;">
        This link expires in <strong style="color:#0f172a;">{{ $ttl }} minutes</strong> and can only be used once.
    </p>

    <p style="margin:18px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.65; color:#94a3b8;">
        Didn't request this? You can safely ignore this email — your password won't change unless you use the link above.
    </p>

    {{-- Fallback for clients that strip or mangle the button. A reset URL is
         long, so it's word-broken rather than allowed to overflow the card. --}}
    <p style="margin:26px 0 0 0; padding-top:20px; border-top:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#94a3b8;">
        If the button doesn't work, copy and paste this link into your browser:<br>
        <span style="color:#64748b; word-break:break-all;">{{ $url }}</span>
    </p>
@endcomponent
