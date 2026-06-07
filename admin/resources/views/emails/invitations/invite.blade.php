<x-mail::message>
# You've been invited to join {{ $client->name }}

{{ $recipientName ? 'Hi '.$recipientName.',' : 'Hi there,' }}

**{{ $inviter->name }}** has invited you to collaborate in the **{{ $client->name }}** workspace on {{ config('app.name') }}.

Click the button below to accept. If you already have an account, you'll be signed in and added to the workspace. If not, you can set a password in one step.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This invitation expires on {{ \Illuminate\Support\Carbon::createFromTimestamp($invitation->expires_at)->toDayDateTimeString() }}.

If you weren't expecting this, you can safely ignore this email.

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
