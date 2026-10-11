{{-- Team alert: a new user, client, project, website contact or payment.
     Sent to the ops inbox (services.ops_alerts.email) by App\Services\Ops\OpsAlerts. --}}
@component('emails.layout', [
    'heading'   => $title,
    'preheader' => $body ?: $title,
])
    {{-- The summary line, unless the table below already holds the whole message. --}}
    @if ($body && ! isset($details['Message']))
        <p style="margin:0 0 20px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
            {{ $body }}
        </p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="border:1px solid #e2e8f0; border-radius:10px; border-collapse:separate; overflow:hidden;">
        @foreach ($details as $label => $value)
            <tr>
                <td valign="top" style="width:150px; padding:10px 14px; background-color:#f8fafc; border-bottom:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; font-weight:600; color:#475569;">
                    {{ $label }}
                </td>
                <td valign="top" style="padding:10px 14px; border-bottom:1px solid #e2e8f0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:1.55; color:#0f172a; white-space:pre-wrap; word-break:break-word;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    @if ($url)
        <p style="margin:26px 0 0 0; text-align:center;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:12px 26px; border-radius:9px; background-color:#1d4ed8; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none;">
                Open in the admin panel
            </a>
        </p>
    @endif

    <p style="margin:22px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:1.6; color:#94a3b8;">
        {{ $when }} · sent to the {{ tva_setting('content.brand_name', 'serveAI') }} team.
    </p>
@endcomponent
