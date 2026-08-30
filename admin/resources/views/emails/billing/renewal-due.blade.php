@component('mail::message')
# {{ $days <= 1 ? 'Your plan expires tomorrow' : 'Time to renew ' . $planName }}

Hi{{ $client?->name ? ' ' . $client->name : '' }},

@if ($days <= 1)
Your **{{ $planName }}** plan ends {{ $endsAt?->format('j F') }} — that's tomorrow. Once it
lapses your AI stops answering customers, so this is the one to act on.
@elseif ($days <= 3)
Your **{{ $planName }}** plan ends on **{{ $endsAt?->format('j F') }}**, in {{ $days }} days.
A quick renewal now keeps everything running without a gap.
@else
Just so it isn't a surprise: your **{{ $planName }}** plan runs until
**{{ $endsAt?->format('j F') }}**, {{ $days }} days from now.
@endif

@if ($amount)
Renewing costs **{{ $currency }} {{ $amount }}** for another {{ $interval }}.
@endif

@component('mail::button', ['url' => $renewUrl])
Renew now
@endcomponent

You can pay by card, bank account, JazzCash or Easypaisa — whichever is easiest.

@if ($days > 1)
Nothing changes until {{ $endsAt?->format('j F') }}, and your leads, conversations and
settings stay exactly as they are either way.
@endif

Thanks,<br>
{{ config('app.name') }}
@endcomponent
