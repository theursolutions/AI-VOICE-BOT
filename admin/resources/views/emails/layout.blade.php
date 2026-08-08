{{--
    Shared branded shell for transactional email.

    Deliberately plain HTML with INLINE styles and a table layout, not Blade
    mail components or a CSS class sheet: Outlook, Gmail and Yahoo all strip
    <style> blocks to different degrees, so inline attributes are the only
    thing that renders consistently. Max width 600px is the safe standard.

    Contact details come from tva_setting() so they stay in step with the
    website footer and the contact page — one source, no drift.

    Slots: $heading, $slot (body), and optionally $preheader.
--}}
@php
    $brand   = tva_setting('content.brand_name', 'Serve AI');
    $base    = rtrim(config('app.url'), '/');
    $logo    = $base . '/assets/dist/images/servai-icon-full.png';
    $cEmail  = tva_setting('content.contact_email',   'info@serveai.com.pk');
    $cPhone  = tva_setting('content.contact_phone',   '+92 349 149 4383');
    $cAddr   = tva_setting('content.contact_address', 'Daftarkhwan | Vogue, Vogue Towers, MM Alam Rd, Block C2, Gulberg III, Lahore 54000, Pakistan');
    $year    = date('Y');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $heading ?? $brand }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; -webkit-font-smoothing:antialiased;">

    {{-- Preheader: the grey preview line inbox clients show next to the
         subject. Hidden in the body itself. --}}
    @isset($preheader)
        <div style="display:none; font-size:1px; color:#f1f5f9; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
            {{ $preheader }}
        </div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;">
        <tr>
            <td align="center" style="padding:32px 16px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;">

                    {{-- Branded header band. Gradient is a literal hex pair, not
                         var(--tva-gradient): email clients strip CSS custom
                         properties, so theme colours must be inlined. --}}
                    <tr>
                        <td align="center"
                            style="background-color:#2563eb; background-image:linear-gradient(135deg,#3b82f6 0%,#2563eb 55%,#1d4ed8 100%); border-radius:14px 14px 0 0; padding:34px 24px 30px 24px;">
                            <a href="{{ $base }}" style="text-decoration:none;">
                                {{-- White rounded tile so a dark/transparent logo
                                     stays legible on the coloured band. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;">
                                    <tr>
                                        <td align="center" valign="middle"
                                            style="width:72px; height:72px; background-color:#ffffff; border-radius:18px; text-align:center;">
                                            <img src="{{ $logo }}" alt="{{ $brand }}" width="48" height="48"
                                                 style="display:block; margin:0 auto; border:0; width:48px; height:48px;">
                                        </td>
                                    </tr>
                                </table>
                                <div style="margin-top:16px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:30px; line-height:1.2; font-weight:700; color:#ffffff; letter-spacing:-0.5px;">
                                    {{ $brand }}
                                </div>
                                <div style="margin-top:6px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; color:#dbeafe;">
                                    AI voice &amp; chat for every customer conversation
                                </div>
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background-color:#ffffff; border-radius:0 0 14px 14px; border:1px solid #e2e8f0; border-top:0; padding:40px 36px;">
                            <h1 style="margin:0 0 20px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; line-height:1.3; font-weight:700; color:#0f172a;">
                                {{ $heading ?? '' }}
                            </h1>
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- CTA buttons --}}
                    <tr>
                        <td align="center" style="padding:28px 0 8px 0;">
                            {{-- Filled primary + tinted secondary, both in the theme
                                 blue. White buttons read as disabled. --}}
                            <a href="{{ route('contact') }}"
                               style="display:inline-block; margin:0 5px 10px 5px; padding:12px 26px; border-radius:9px; background-color:#2563eb; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; font-weight:700; color:#ffffff; text-decoration:none;">
                                Contact Support
                            </a>
                            <a href="{{ route('terms') }}"
                               style="display:inline-block; margin:0 5px 10px 5px; padding:12px 26px; border-radius:9px; background-color:#eff6ff; border:1px solid #bfdbfe; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; font-weight:700; color:#1d4ed8; text-decoration:none;">
                                Terms &amp; Conditions
                            </a>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 12px 0 12px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.7; color:#64748b; text-align:center;">
                            <div style="font-weight:600; color:#475569; margin-bottom:6px;">{{ $brand }}</div>
                            <div>{{ $cAddr }}</div>
                            <div>
                                <a href="mailto:{{ $cEmail }}" style="color:#64748b; text-decoration:none;">{{ $cEmail }}</a>
                                &nbsp;·&nbsp;
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $cPhone) }}" style="color:#64748b; text-decoration:none;">{{ $cPhone }}</a>
                            </div>
                            <div style="margin-top:14px; padding-top:14px; border-top:1px solid #e2e8f0;">
                                &copy; {{ $year }} {{ $brand }}. All rights reserved.
                                &nbsp;·&nbsp;
                                <a href="{{ route('privacy') }}" style="color:#64748b;">Privacy Policy</a>
                            </div>
                            <div style="margin-top:10px; color:#94a3b8;">
                                This is an automated message — please don't reply to it directly.
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
