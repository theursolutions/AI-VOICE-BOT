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
                    <form method="POST" action="{{ route('leadgen.searches.pause', $qp) }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i data-lucide="pause"></i>Pause</button></form>
                @endif
                @if ($search->status === 'paused')
                    <form method="POST" action="{{ route('leadgen.searches.resume', $qp) }}">@csrf<button class="btn btn-sm btn-primary"><i data-lucide="play"></i>Resume</button></form>
                @endif
                @if (in_array($search->status, ['queued', 'running', 'paused'], true))
                    <form method="POST" action="{{ route('leadgen.searches.cancel', $qp) }}" data-confirm="Stop this search? Prospects already found are kept.">@csrf<button class="btn btn-sm btn-outline-danger"><i data-lucide="x"></i>Cancel</button></form>
                @endif
                @if (!$isImport)
                    <form method="POST" action="{{ route('leadgen.searches.save', $qp) }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i data-lucide="bookmark"></i>{{ $search->saved ? 'Saved' : 'Save' }}</button></form>
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('leadgen.searches.create', ['client' => $client->slug, 'project_id' => $project->id, 'from_id' => $search->id]) }}"><i data-lucide="refresh-cw"></i>Run again</a>
                    <a class="btn btn-sm btn-primary" href="{{ route('leadgen.prospects.index', ['client' => $client->slug, 'project_id' => $project->id, 'search_id' => $search->id]) }}"><i data-lucide="building"></i>Work with these prospects</a>
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
        <div class="tva-bulkbar">
            @foreach (['' => ['All', 'layers'], 'new' => ['New', 'star'], 'duplicate' => ['Already found', 'copy'], 'existing_crm' => ['In your CRM', 'user-check']] as $oc => [$label, $icon])
                <a href="{{ route('leadgen.searches.show', $qp + ($oc ? ['outcome' => $oc] : [])) }}" class="tva-lg-tab2 {{ ($outcome ?? '') === $oc ? 'tva-lg-tab2--on' : '' }}" style="padding:6px 12px"><i data-lucide="{{ $icon }}"></i>{{ $label }}</a>
            @endforeach
        </div>
        @if ($results->isEmpty())
            <div class="tva-lg-empty">
                <span class="tva-tone tva-tone--blue mx-auto" style="width:48px;height:48px"><i data-lucide="{{ $active ? 'clock' : 'inbox' }}"></i></span>
                <div class="mt-3">{{ $active ? 'Results will appear here as they arrive.' : 'No results for this filter.' }}</div>
            </div>
        @else
            <div style="overflow-x:auto">
            <table class="tva-lg-tbl">
                <thead><tr><th>Business</th><th>Website</th><th>Contact</th><th>Location</th><th>Result</th></tr></thead>
                <tbody>
                @foreach ($results as $r)
                    @php $pr = $r->prospect; @endphp
                    @continue(!$pr)
                    <tr>
                        <td style="min-width:230px">
                            <div class="tva-who">
                                @include('leadgen._chips', ['chip' => 'avatar', 'name' => $pr->business_name])
                                <div style="min-width:0">
                                    <a class="tva-who__n" href="{{ route('leadgen.prospects.show', ['client' => $client->slug, 'project_id' => $project->id, 'id' => $pr->id]) }}">{{ $pr->business_name }}</a>
                                    <div class="tva-who__s">{{ $pr->category ? str_replace('_', ' ', $pr->category) : '—' }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            @if ($pr->website)
                                <a href="{{ $pr->website }}" target="_blank" rel="noopener nofollow" class="tva-cell-ico text-primary"><i data-lucide="globe"></i>{{ $pr->domain_norm ?: parse_url($pr->website, PHP_URL_HOST) }}</a>
                            @else <span class="tva-empty-dash">—</span> @endif
                        </td>
                        <td style="min-width:200px">
                            @if ($pr->email)
                                <div class="tva-cell-ico"><i data-lucide="mail"></i>{{ $pr->email }}</div>
                                <div class="mt-1">@include('leadgen.prospects._verified', ['level' => $pr->verificationOf('email')])</div>
                            @endif
                            @if ($pr->phone_e164 || isset($pr->field_meta['phone']['raw']))
                                <div class="tva-cell-ico {{ $pr->email ? 'mt-2' : '' }}"><i data-lucide="phone"></i>{{ $pr->phone_e164 ?: $pr->field_meta['phone']['raw'] }}</div>
                            @endif
                            @if (!$pr->email && !$pr->phone_e164 && !isset($pr->field_meta['phone']['raw']))<span class="tva-empty-dash">—</span>@endif
                        </td>
                        <td>@if ($pr->city || $pr->country_code)<span class="tva-cell-ico"><i data-lucide="map-pin"></i>{{ trim(($pr->city ?? '') . ($pr->country_code ? ', ' . $pr->country_code : ''), ', ') }}</span>@else<span class="tva-empty-dash">—</span>@endif</td>
                        <td>
                            @if ($r->outcome === 'new')<span class="tva-st tva-st--new">New</span>@endif
                            @if ($r->outcome === 'duplicate')<span class="tva-st tva-st--muted">Already found</span>@endif
                            @if ($r->outcome === 'existing_crm')<span class="tva-st tva-st--in_crm">In your CRM</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @include('leadgen._pager', ['paginator' => $results, 'noun' => 'results'])
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
