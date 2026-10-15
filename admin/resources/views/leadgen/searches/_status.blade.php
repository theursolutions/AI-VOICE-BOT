{{-- Status pill for a search. Expects $status. --}}
@php
    [$cls, $label] = match ($status) {
        'queued'     => ['tva-lg-pill--run',  'Queued'],
        'running'    => ['tva-lg-pill--run',  'Running'],
        'paused'     => ['tva-lg-pill--warn', 'Paused'],
        'cancelling' => ['tva-lg-pill--warn', 'Stopping'],
        'cancelled'  => ['tva-lg-pill--off',  'Cancelled'],
        'completed'  => ['tva-lg-pill--ok',   'Completed'],
        'failed'     => ['tva-lg-pill--bad',  'Failed'],
        default      => ['tva-lg-pill--off',  ucfirst((string) $status)],
    };
@endphp
<span class="tva-lg-pill {{ $cls }}" data-status-pill>{{ $label }}</span>
