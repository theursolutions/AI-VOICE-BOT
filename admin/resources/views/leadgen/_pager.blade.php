{{-- "Showing 1–50 of 230 prospects" + page links. Expects $paginator; optional $noun. --}}
@if ($paginator->total() > 0)
<div class="tva-pager">
    <div class="tva-pager__info">
        Showing <b>{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</b>
        of <b>{{ number_format($paginator->total()) }}</b> {{ $noun ?? 'results' }}
    </div>
    @if ($paginator->hasPages())
        @include('partials.pagination', ['paginator' => $paginator])
    @endif
</div>
@endif
