@extends('layouts.master')
@section('content')
@php
    $p        = (array) $search->progress;
    $isImport = $search->provider === 'import';
    $active   = in_array($search->status, ['queued', 'running', 'cancelling'], true);
    $total    = (int) ($p['total'] ?? 0);
    $fetched  = (int) ($p['fetched'] ?? 0);
    $cap      = $isImport ? $total : min($total ?: PHP_INT_MAX, (int) ($search->criteria['max_results'] ?? 0));
    $pct      = $cap > 0 ? min(100, (int) round($fetched / $cap * 100)) : ($search->status === 'completed' ? 100 : 0);
    $u        = auth()->user();
    $canSearch = $u->canAbility($client->id, 'leadgen', 'search');
    $qp       = ['client' => $client->slug, 'id' => $search->id, 'project_id' => $project->id];
    $verLabel = ['unverified' => 'unverified', 'syntax_ok' => 'format ok', 'mx_ok' => 'domain accepts mail',
                 'found_on_site' => 'on their website', 'user_confirmed' => 'confirmed'];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $search->name])

    <div class="tva-lg-panel">
        <div class="flex items-center gap-3 flex-wrap">
            @include('leadgen.searches._status', ['status' => $search->status])
            <div class="tva-lg-sub">
                {{ $isImport ? 'Import of ' . ($search->criteria['original_name'] ?? 'a file') : ($search->criteria['area_label'] ?? '') }}
                @if (!$isImport && !empty($search->criteria['categories']))
                    · {{ str_replace('_', ' ', implode(', ', array_slice($search->criteria['categories'], 0, 4))) }}
                @endif
            </div>
            @if ($canSearch)
            <div class="ml-auto flex gap-2">
                @if (in_array($search->status, ['queued', 'running'], true))
                    <form method="POST" action="{{ route('leadgen.searches.pause', $qp) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Pause</button></form>
                @endif
                @if ($search->status === 'paused')
                    <form method="POST" action="{{ route('leadgen.searches.resume', $qp) }}">@csrf<button class="btn btn-sm btn-primary">Resume</button></form>
                @endif
                @if (in_array($search->status, ['queued', 'running', 'paused'], true))
                    <form method="POST" action="{{ route('leadgen.searches.cancel', $qp) }}" data-confirm="Stop this search? Prospects already found are kept.">@csrf<button class="btn btn-sm btn-outline-danger">Cancel</button></form>
                @endif
                @if (!$isImport)
                    <form method="POST" action="{{ route('leadgen.searches.save', $qp) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $search->saved ? 'Unsave' : 'Save' }}</button></form>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('leadgen.searches.create', ['client' => $client->slug, 'project_id' => $project->id, 'from_id' => $search->id]) }}">Run again</a>
                @endif
            </div>
            @endif
        </div>

        <div class="mt-4">
            <div class="tva-lg-bar"><div data-bar style="width: {{ $pct }}%"></div></div>
            <div class="flex gap-5 mt-2 text-xs text-slate-500 flex-wrap" data-counts>
                <span><b data-k="new">{{ number_format((int) ($p['new'] ?? 0)) }}</b> new prospects</span>
                <span><b data-k="duplicates">{{ number_format((int) ($p['duplicates'] ?? 0)) }}</b> already found earlier</span>
                @if (!empty($p['in_crm']))<span><b data-k="in_crm">{{ number_format((int) $p['in_crm']) }}</b> already in your CRM</span>@endif
                @if (!empty($p['excluded']))<span><b data-k="excluded">{{ number_format((int) $p['excluded']) }}</b> on your do-not-re-add list</span>@endif
                @if (!empty($p['skipped']))<span><b data-k="skipped">{{ number_format((int) $p['skipped']) }}</b> skipped (no name)</span>@endif
                @if ($total)<span>{{ number_format($total) }} match{{ $total === 1 ? '' : 'es' }} in total</span>@endif
                <span data-phase>
                    @if (($p['phase'] ?? '') === 'loading_area')
                        Loading this area for the first time — usually 1–2 minutes…
                    @endif
                </span>
            </div>
        </div>
        @if ($search->status === 'failed' && $search->error)
            <div class="alert alert-danger mt-3">{{ $search->error }}</div>
        @endif
    </div>

    <div class="tva-lg-panel tva-lg-panel--flush">
        <div class="flex gap-4 px-5 pb-3 text-sm">
            <a href="{{ route('leadgen.searches.show', $qp) }}" class="{{ !$outcome ? 'font-semibold text-primary' : 'text-slate-500' }}">All</a>
            <a href="{{ route('leadgen.searches.show', $qp + ['outcome' => 'new']) }}" class="{{ $outcome === 'new' ? 'font-semibold text-primary' : 'text-slate-500' }}">New</a>
            <a href="{{ route('leadgen.searches.show', $qp + ['outcome' => 'duplicate']) }}" class="{{ $outcome === 'duplicate' ? 'font-semibold text-primary' : 'text-slate-500' }}">Already found</a>
            <a href="{{ route('leadgen.searches.show', $qp + ['outcome' => 'existing_crm']) }}" class="{{ $outcome === 'existing_crm' ? 'font-semibold text-primary' : 'text-slate-500' }}">In your CRM</a>
        </div>
        @if ($results->isEmpty())
            <div class="tva-lg-empty">{{ $active ? 'Results will appear here as they arrive.' : 'No results.' }}</div>
        @else
            <table class="tva-lg-tbl">
                <thead><tr><th>Business</th><th>Website</th><th>Phone</th><th>Email</th><th>Location</th><th></th></tr></thead>
                <tbody>
                @foreach ($results as $r)
                    @php $pr = $r->prospect; @endphp
                    @continue(!$pr)
                    <tr>
                        <td>
                            <div class="tva-lg-name">{{ $pr->business_name }}</div>
                            <div class="tva-lg-sub">{{ $pr->category ? str_replace('_', ' ', $pr->category) : '—' }}</div>
                        </td>
                        <td>
                            @if ($pr->website)
                                <a href="{{ $pr->website }}" target="_blank" rel="noopener nofollow" class="text-primary">{{ $pr->domain_norm ?: parse_url($pr->website, PHP_URL_HOST) }}</a>
                            @else <span class="tva-lg-sub">—</span> @endif
                        </td>
                        <td>
                            {{ $pr->phone_e164 ?: ($pr->field_meta['phone']['raw'] ?? '—') }}
                            @if ($pr->phone_e164 || isset($pr->field_meta['phone']))<div class="tva-lg-sub">{{ $verLabel[$pr->verificationOf('phone')] ?? 'unverified' }}</div>@endif
                        </td>
                        <td>
                            {{ $pr->email ?: '—' }}
                            @if ($pr->email)<div class="tva-lg-sub">{{ $verLabel[$pr->verificationOf('email')] ?? 'unverified' }}</div>@endif
                        </td>
                        <td>{{ trim(($pr->city ?? '') . ($pr->country_code ? ', ' . $pr->country_code : ''), ', ') ?: '—' }}</td>
                        <td>
                            @if ($r->outcome === 'duplicate')<span class="tva-lg-pill tva-lg-pill--off">already found</span>@endif
                            @if ($r->outcome === 'existing_crm')<span class="tva-lg-pill tva-lg-pill--ok">in your CRM</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="px-5 pt-3">@include('partials.pagination', ['paginator' => $results])</div>
        @endif
        @if ($attribution)
            <div class="tva-lg-sub px-5 pt-3">Data: {{ $attribution }} Contact details come from public listings and are unverified until confirmed.</div>
        @endif
    </div>

    @if ($events->isNotEmpty())
    <div class="tva-lg-panel">
        <div class="tva-lg-h">Activity</div>
        @foreach ($events as $e)
            <div class="tva-lg-sub" style="padding:3px 0">
                {{ \Carbon\Carbon::createFromTimestamp($e->created_at)->format('M j, H:i:s') }} —
                {{ str_replace(['search.', 'import.', '_'], ['', '', ' '], $e->event) }}
                @if (!empty($e->payload['message'])) · {{ $e->payload['message'] }} @endif
            </div>
        @endforeach
    </div>
    @endif
</div>
@endsection

@section('script')
@if ($active || ($p['phase'] ?? '') === 'loading_area')
<script>
(function () {
    var url = @json(route('leadgen.searches.progress', $qp));
    var last = @json($search->status);
    function tick() {
        fetch(url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                var p = d.progress || {};
                ['new', 'duplicates', 'in_crm', 'excluded', 'skipped'].forEach(function (k) {
                    var el = document.querySelector('[data-k="' + k + '"]');
                    if (el && p[k] != null) el.textContent = Number(p[k]).toLocaleString();
                });
                var ph = document.querySelector('[data-phase]');
                if (ph) ph.textContent = p.phase === 'loading_area' ? 'Loading this area for the first time — usually 1–2 minutes…' : '';
                var cap = @json($cap ?: 0);
                if (cap && p.fetched != null) document.querySelector('[data-bar]').style.width = Math.min(100, Math.round(p.fetched / cap * 100)) + '%';
                // New rows or a finished search: reload so the table catches up.
                if (d.status !== last || (p.new || 0) !== @json((int) ($p['new'] ?? 0))) { location.reload(); return; }
                setTimeout(tick, 4000);
            })
            .catch(function () { setTimeout(tick, 8000); });
    }
    setTimeout(tick, 4000);
})();
</script>
@endif
@endsection
