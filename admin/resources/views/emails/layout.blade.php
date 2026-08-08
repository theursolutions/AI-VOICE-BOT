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
    $cAddr   = tva_setting('content.contact_address', 'Arfa Software Technology Park, Lahore, Pakistan');
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

                    {{-- Logo + app name --}}
                    <tr>
                        <td align="center" style="padding-bottom:24px;">
                            <a href="{{ $base }}" style="text-decoration:none;">
                                <img src="{{ $logo }}" alt="{{ $brand }}" width="44" height="44"
                                     style="display:inline-block; vertical-align:middle; border:0; width:44px; height:44px;">
                                <span style="display:inline-block; vertical-align:middle; margin-left:12px; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; font-weight:700; color:#0f172a; letter-spacing:-0.3px;">
                                    {{ $brand }}
                                </span>
                            </a>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background-color:#ffffff; border-radius:14px; border:1px solid #e2e8f0; padding:40px 36px;">
                            <h1 style="margin:0 0 20px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:22px; line-height:1.3; font-weight:700; color:#0f172a;">
                                {{ $heading ?? '' }}
                            </h1>
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- CTA buttons --}}
                    <tr>
                        <td align="center" style="padding:28px 0 8px 0;">
                            <a href="{{ route('contact') }}"
                               style="display:inline-block; margin:0 6px 8px 6px; padding:10px 20px; border-radius:8px; border:1px solid #cbd5e1; background-color:#ffffff; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; font-weight:600; color:#334155; text-decoration:none;">
                                Contact Support
                            </a>
                            <a href="{{ route('terms') }}"
                               style="display:inline-block; margin:0 6px 8px 6px; padding:10px 20px; border-radius:8px; border:1px solid #cbd5e1; background-color:#ffffff; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; font-weight:600; color:#334155; text-decoration:none;">
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
