<x-mail::message>
# Verify your email

{{ $name ? 'Hi '.$name.',' : 'Hi there,' }}

Use this code to verify your email address on {{ config('app.name') }}:

<x-mail::panel>
# {{ $code }}
</x-mail::panel>

This code expires in {{ $ttl }} minutes. If you didn't request it, you can safely ignore this email.

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
