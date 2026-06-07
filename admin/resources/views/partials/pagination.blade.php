{{-- Generic pagination renderer for the tva-dt-card footer.
     Usage: @include('partials.pagination', ['paginator' => $somePaginator]) --}}
@php
    $window = method_exists($paginator, 'onEachSide') ? 1 : 1;
    $current = $paginator->currentPage();
    $last    = $paginator->lastPage();
    $start   = max(1, $current - $window);
    $end     = min($last, $current + $window);
@endphp

<div class="tva-pag">
    {{-- Prev --}}
    @if ($paginator->onFirstPage())
        <span class="is-disabled"><i data-lucide="chevron-left" class="w-4 h-4"></i></span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" rel="prev"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
    @endif

    {{-- First page + ellipsis --}}
    @if ($start > 1)
        <a href="{{ $paginator->url(1) }}">1</a>
        @if ($start > 2) <span class="is-disabled">…</span> @endif
    @endif

    {{-- Numbered window --}}
    @for ($i = $start; $i <= $end; $i++)
        @if ($i === $current)
            <span class="is-current">{{ $i }}</span>
        @else
            <a href="{{ $paginator->url($i) }}">{{ $i }}</a>
        @endif
    @endfor

    {{-- Last page + ellipsis --}}
    @if ($end < $last)
        @if ($end < $last - 1) <span class="is-disabled">…</span> @endif
        <a href="{{ $paginator->url($last) }}">{{ $last }}</a>
    @endif

    {{-- Next --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" rel="next"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
    @else
        <span class="is-disabled"><i data-lucide="chevron-right" class="w-4 h-4"></i></span>
    @endif
</div>
