{{-- Email-verification code. Rendered inside emails/layout (branded shell:
     logo, app name, CTA buttons, contact footer). Plain HTML with inline
     styles — see the layout's header comment for why. --}}
@component('emails.layout', [
    'heading'   => 'Verify your email address',
    'preheader' => 'Your ' . tva_setting('content.brand_name', 'Serve AI') . ' verification code is ' . $code,
])
    <p style="margin:0 0 18px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        <strong style="font-weight:700; color:#0f172a;">Hi {{ $name ?: 'there' }},</strong>
    </p>

    <p style="margin:0 0 26px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        Welcome aboard. Enter the code below to confirm your email address and unlock your workspace.
    </p>

    {{-- The code: the one thing the reader is here for. Large, bold, wide
         letter-spacing so digits can't be misread, and selectable as text
         (never an image — images are blocked by default in many clients). --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td align="center" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:26px 20px;">
                <div style="font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:11px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:#94a3b8; margin-bottom:12px;">
                    Your verification code
                </div>
                <div style="font-family:'SF Mono',SFMono-Regular,Menlo,Consolas,monospace; font-size:38px; font-weight:700; letter-spacing:8px; line-height:1.1; color:#0f172a;">
                    {{ $code }}
                </div>
            </td>
        </tr>
    </table>

    <p style="margin:24px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:1.65; color:#475569;">
        This code expires in <strong style="color:#0f172a;">{{ $ttl }} minutes</strong>. For your security, don't share it with anyone.
    </p>

    <p style="margin:18px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.65; color:#94a3b8;">
        Didn't create an account? You can safely ignore this email — nothing will happen without the code.
    </p>
@endcomponent
