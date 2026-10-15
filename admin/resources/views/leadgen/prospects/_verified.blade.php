{{-- How far a contact field is verified. Expects $level (Prospect::VERIFICATION), optional $mx (bool|null). --}}
@php
    [$cls, $label, $tip] = match ($level) {
        'user_confirmed' => ['tva-lg-pill--ok',  'confirmed',       'Confirmed by a person on your team.'],
        'found_on_site'  => ['tva-lg-pill--ok',  'on their site',   'Found on the business’s own website.'],
        'mx_ok'          => ['tva-lg-pill--run', 'domain ok',       'The email domain accepts mail. The mailbox itself isn’t checked.'],
        'syntax_ok'      => ['tva-lg-pill--off', 'format ok',       'Looks like a valid address. Not checked further.'],
        default          => ['tva-lg-pill--off', 'unverified',      'From a public listing. Not checked yet.'],
    };
@endphp
<span class="tva-lg-pill {{ $cls }}" title="{{ $tip }}">{{ $label }}</span>
@if (isset($mx) && $mx === false)<span class="tva-lg-pill tva-lg-pill--bad" title="This domain has no mail servers — email to it will bounce.">no mail server</span>@endif
