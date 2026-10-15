{{--
    Small display helpers, all in one partial: @include('leadgen._chips', ['chip' => 'status'|'tag'|'avatar'|'score', ...])
      status: $status (prospect status)            → coloured pill with a dot
      tag:    $tag                                  → colour chosen from the tag text, so a tag is always the same colour
      avatar: $name                                 → initials on a gradient chosen from the name
      score:  $q (Qualification)                    → score bubble coloured by band, with the band label
--}}
@switch($chip)
    @case('status')
        @php
            [$cls, $label] = match ($status) {
                'new'       => ['tva-st--new', 'New'],
                'reviewing' => ['tva-st--reviewing', 'In review'],
                'approved'  => ['tva-st--approved', 'Approved'],
                'rejected'  => ['tva-st--rejected', 'Rejected'],
                'in_crm'    => ['tva-st--in_crm', 'In CRM'],
                default     => ['tva-st--muted', ucfirst(str_replace('_', ' ', (string) $status))],
            };
        @endphp
        <span class="tva-st {{ $cls }}">{{ $label }}</span>
        @break

    @case('tag')
        <span class="tva-tag tva-tag--{{ crc32(mb_strtolower($tag)) % 6 }}">{{ $tag }}</span>
        @break

    @case('avatar')
        @php
            $words = preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $name))) ?: [];
            $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1) . mb_substr($words[1] ?? '', 0, 1));
        @endphp
        <span class="tva-av tva-av--{{ crc32((string) $name) % 6 }}" aria-hidden="true">{{ $initials ?: '?' }}</span>
        @break

    @case('score')
        @php $band = $q->effectiveBand(); @endphp
        <span class="tva-score tva-score--{{ $band }}">
            <span class="tva-score__n">{{ $q->score }}</span>
            <span class="tva-score__l">{{ ['high' => 'High fit', 'medium' => 'Medium fit', 'low' => 'Low fit', 'needs_review' => 'Needs review'][$band] ?? $band }}</span>
        </span>
        @break
@endswitch
