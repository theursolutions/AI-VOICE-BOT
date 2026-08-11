{{-- Billing lifecycle email. Copy comes from
     App\Notifications\BillingLifecycleNotification so the whole sequence
     (ending → ended → purge warning) reads as one voice and can't drift. --}}
@component('emails.layout', [
    'heading'   => $heading,
    'preheader' => $preheader,
])
    <p style="margin:0 0 18px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
        <strong style="font-weight:700; color:#0f172a;">Hi {{ $name ?: 'there' }},</strong>
    </p>

    @foreach ($lines as $line)
        <p style="margin:0 0 18px 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:#334155;">
            {!! $line !!}
        </p>
    @endforeach

    {{-- Primary action. Table-wrapped so Outlook renders the background colour. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;">
        <tr>
            <td align="center">
                <a href="{{ $ctaUrl }}"
                   style="display:inline-block; padding:15px 38px; border-radius:10px; background-color:#3b82f6; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none;">
                    {{ $ctaLabel }}
                </a>
            </td>
        </tr>
    </table>

    @isset($reassurance)
        <p style="margin:26px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.65; color:#64748b;">
            {{ $reassurance }}
        </p>
    @endisset

    <p style="margin:22px 0 0 0; font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13px; line-height:1.65; color:#94a3b8;">
        Workspace: <strong style="color:#475569;">{{ $workspace }}</strong>
    </p>
@endcomponent
