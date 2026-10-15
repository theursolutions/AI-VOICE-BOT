{{-- One AI score with its grounding. Expects $q (Qualification). --}}
@php
    $band = $q->effectiveBand();
    $cls = ['high' => 'tva-lg-pill--ok', 'medium' => 'tva-lg-pill--run', 'low' => 'tva-lg-pill--off', 'needs_review' => 'tva-lg-pill--warn'][$band] ?? 'tva-lg-pill--off';
@endphp
<div class="flex items-center gap-2 flex-wrap">
    @include('leadgen._chips', ['chip' => 'score', 'q' => $q])
    @if ($q->override_band)<span class="tva-lg-sub">reviewed (AI said {{ str_replace('_', ' ', $q->band) }})</span>@endif
</div>
<div class="text-sm mt-2">{{ $q->explanation }}</div>
@if ($q->evidence)
    <div class="tva-lg-sub mt-2">Based on:
        @foreach ($q->evidence as $e)<span class="tva-lg-pill tva-lg-pill--off mr-1" title="{{ $e['why'] ?? '' }}">{{ str_replace('_', ' ', $e['fact']) }}: {{ $e['value'] }}</span>@endforeach
    </div>
@endif
@if ($q->hypotheses)
    <div class="text-xs mt-2"><b>Possible needs</b> <span class="tva-lg-sub">(guesses, not confirmed)</span>
        <ul class="list-disc pl-5">@foreach ($q->hypotheses as $h)<li>{{ $h }}</li>@endforeach</ul>
    </div>
@endif
@if ($q->value_prop)<div class="text-xs mt-2"><b>Angle:</b> {{ $q->value_prop }}</div>@endif
<div class="tva-lg-sub mt-2">
    Suggested channel: {{ $q->suggested_channel === 'none' ? 'none available' : $q->suggested_channel }}
    @if ($q->next_action) · Next: {{ $q->next_action }}@endif
    @if ($q->override_reason)<br>Reviewer: {{ $q->override_reason }}@endif
</div>
