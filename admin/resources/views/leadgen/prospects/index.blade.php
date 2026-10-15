@extends('layouts.master')
@section('content')
@php
    $u        = auth()->user();
    $canEdit  = $u->canAbility($client->id, 'leadgen', 'edit');
    $canSrch  = $u->canAbility($client->id, 'leadgen', 'search');
    $canExp   = $u->canAbility($client->id, 'leadgen', 'export');
    $base     = ['client' => $client->slug, 'project_id' => $project->id];
    $f        = $filters;
    $listName = !empty($f['list_id']) ? optional($lists->firstWhere('id', (int) $f['list_id']))->name : null;
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $listName ? 'List: ' . $listName : 'Prospects'])

    <form method="GET" class="flex gap-2 mt-4 flex-wrap items-center">
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        @if (!empty($f['list_id']))<input type="hidden" name="list_id" value="{{ hashid((int) $f['list_id']) }}">@endif
        @if (!empty($f['search_id']))<input type="hidden" name="search_id" value="{{ hashid((int) $f['search_id']) }}">@endif
        <input name="q" value="{{ $f['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Name, website, email or phone" style="width:220px">
        <select name="status" class="form-select form-select-sm" style="width:auto">
            <option value="">Any status</option>
            @foreach ($statuses as $s)<option value="{{ $s }}" @selected(($f['status'] ?? '') === $s)>{{ str_replace('_', ' ', ucfirst($s)) }}</option>@endforeach
        </select>
        <select name="has" class="form-select form-select-sm" style="width:auto">
            <option value="">Any contact info</option>
            @foreach (['email' => 'Has email', 'phone' => 'Has phone', 'website' => 'Has website', 'nothing' => 'No email or phone'] as $k => $l)
                <option value="{{ $k }}" @selected(($f['has'] ?? '') === $k)>{{ $l }}</option>
            @endforeach
        </select>
        <input name="city" value="{{ $f['city'] ?? '' }}" class="form-control form-control-sm" placeholder="City" style="width:120px">
        <input name="tag" value="{{ $f['tag'] ?? '' }}" class="form-control form-control-sm" placeholder="Tag" style="width:110px">
        <select name="show" class="form-select form-select-sm" style="width:auto">
            <option value="">Hide do-not-contact</option>
            <option value="all" @selected(($f['show'] ?? '') === 'all')>Show everything</option>
            <option value="suppressed" @selected(($f['show'] ?? '') === 'suppressed')>Only do-not-contact</option>
            <option value="possible_duplicates" @selected(($f['show'] ?? '') === 'possible_duplicates')>Possible duplicates</option>
        </select>
        <button class="btn btn-sm btn-secondary">Filter</button>
        @if ($canExp)
            <a class="btn btn-sm btn-outline-secondary ml-auto" href="{{ route('leadgen.prospects.export', request()->query() + $base) }}">Export Excel</a>
        @endif
    </form>

    <form method="POST" action="{{ route('leadgen.prospects.bulk', ['client' => $client->slug]) }}" id="lg-bulk">
        @csrf
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <div class="tva-lg-panel tva-lg-panel--flush">
            @if (($canEdit || $canSrch) && $prospects->isNotEmpty())
            <div class="flex gap-2 px-5 pb-3 flex-wrap items-center" id="lg-bulk-bar">
                <span class="text-xs text-slate-500" id="lg-sel-count">0 selected</span>
                <select name="action" class="form-select form-select-sm" style="width:auto" id="lg-action">
                    <option value="">Choose an action…</option>
                    @if ($canEdit)
                        <option value="add_to_list">Add to list</option>
                        @if (!empty($f['list_id']))<option value="remove_from_list">Remove from this list</option>@endif
                        <option value="tag">Add a tag</option>
                        <option value="approve">Mark approved</option>
                        <option value="reject">Mark rejected</option>
                        <option value="add_to_crm">Add to CRM</option>
                        <option value="suppress">Do not contact</option>
                        <option value="delete">Delete</option>
                    @endif
                    @if ($canSrch)<option value="enrich">Check their websites</option>@endif
                </select>
                <span data-for="add_to_list" class="hidden flex gap-2">
                    <select name="list_id" class="form-select form-select-sm" style="width:auto">
                        <option value="">New list…</option>
                        @foreach ($lists as $l)<option value="{{ hashid($l->id) }}">{{ $l->name }}</option>@endforeach
                    </select>
                    <input name="new_list" class="form-control form-control-sm" placeholder="New list name" style="width:160px">
                </span>
                @if (!empty($f['list_id']))<input type="hidden" name="list_id" value="{{ hashid((int) $f['list_id']) }}" data-for-list disabled>@endif
                <span data-for="tag" class="hidden"><input name="tag" class="form-control form-control-sm" placeholder="Tag" style="width:140px"></span>
                <label data-for="delete" class="hidden text-xs flex items-center gap-1"><input type="checkbox" name="never_readd" value="1" class="form-check-input" checked> Don’t add these again in future searches</label>
                <button class="btn btn-sm btn-primary" id="lg-apply" disabled>Apply</button>
            </div>
            @endif

            @if ($prospects->isEmpty())
                <div class="tva-lg-empty">
                    No prospects match.
                    @if ($canSrch)<br><a class="text-primary" href="{{ route('leadgen.searches.create', $base) }}">Find businesses</a>@endif
                </div>
            @else
            <table class="tva-lg-tbl">
                <thead><tr>
                    <th style="width:1%"><input type="checkbox" class="form-check-input" id="lg-all" title="Select all on this page"></th>
                    <th>Business</th><th>Email</th><th>Phone</th><th>Location</th><th>Fit</th><th>Status</th>
                </tr></thead>
                <tbody>
                @foreach ($prospects as $pr)
                    @php $q = $pr->currentQualification; @endphp
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ hashid($pr->id) }}" class="form-check-input" data-row></td>
                        <td>
                            <a class="tva-lg-name" href="{{ route('leadgen.prospects.show', $base + ['id' => $pr->id]) }}">{{ $pr->business_name }}</a>
                            <div class="tva-lg-sub">
                                {{ $pr->category ? str_replace('_', ' ', $pr->category) : '' }}
                                @if ($pr->domain_norm) · {{ $pr->domain_norm }} @endif
                                @foreach ((array) $pr->tags as $t) · <span class="tva-lg-pill tva-lg-pill--off">{{ $t }}</span>@endforeach
                            </div>
                        </td>
                        <td>@if ($pr->email){{ $pr->email }}<div>@include('leadgen.prospects._verified', ['level' => $pr->verificationOf('email'), 'mx' => $pr->field_meta['email']['mx'] ?? null])</div>@else <span class="tva-lg-sub">—</span> @endif</td>
                        <td>@if ($pr->phone_e164){{ $pr->phone_e164 }}<div>@include('leadgen.prospects._verified', ['level' => $pr->verificationOf('phone')])</div>@else <span class="tva-lg-sub">—</span> @endif</td>
                        <td>{{ trim(($pr->city ?? '') . ($pr->country_code ? ', ' . $pr->country_code : ''), ', ') ?: '—' }}</td>
                        <td>@if ($q)<b>{{ $q->score }}</b> <span class="tva-lg-sub">{{ str_replace('_', ' ', $q->effectiveBand()) }}</span>@else <span class="tva-lg-sub">—</span>@endif</td>
                        <td>
                            <span class="tva-lg-pill {{ $pr->status === 'in_crm' ? 'tva-lg-pill--ok' : ($pr->status === 'rejected' ? 'tva-lg-pill--bad' : 'tva-lg-pill--off') }}">{{ str_replace('_', ' ', $pr->status === 'in_crm' ? 'in CRM' : $pr->status) }}</span>
                            @if ($pr->suppressed_at)<div><span class="tva-lg-pill tva-lg-pill--bad">do not contact</span></div>@endif
                            @if ($pr->duplicate_of)<div><span class="tva-lg-pill tva-lg-pill--warn">possible duplicate</span></div>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="px-5 pt-3">@include('partials.pagination', ['paginator' => $prospects])</div>
            @endif
        </div>
    </form>

    @if ($canEdit && empty($f['list_id']))
    <details class="tva-lg-panel">
        <summary class="cursor-pointer text-sm font-semibold">New empty list</summary>
        <form method="POST" action="{{ route('leadgen.lists.store', ['client' => $client->slug]) }}" class="flex gap-2 mt-3">
            @csrf<input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
            <input name="name" class="form-control form-control-sm" placeholder="List name" required maxlength="160" style="width:240px">
            <button class="btn btn-sm btn-outline-secondary">Create</button>
        </form>
    </details>
    @endif
</div>
@endsection

@section('script')
<script>
(function () {
    var form = document.getElementById('lg-bulk');
    if (!form) return;
    var rows = form.querySelectorAll('[data-row]'), all = document.getElementById('lg-all');
    var action = document.getElementById('lg-action'), apply = document.getElementById('lg-apply'), count = document.getElementById('lg-sel-count');
    function refresh() {
        var n = form.querySelectorAll('[data-row]:checked').length;
        if (count) count.textContent = n + ' selected';
        if (apply) apply.disabled = !n || !action.value;
        form.querySelectorAll('[data-for]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.for !== (action && action.value)); });
        // The list picker and the current list both post `list_id`; only one may be enabled.
        var removing = action && action.value === 'remove_from_list';
        form.querySelectorAll('[data-for="add_to_list"] select').forEach(function (s) { s.disabled = removing; });
        form.querySelectorAll('[data-for-list]').forEach(function (i) { i.disabled = !removing; });
    }
    if (all) all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); refresh(); });
    rows.forEach(function (r) { r.addEventListener('change', refresh); });
    if (action) action.addEventListener('change', refresh);
    form.addEventListener('submit', function (e) {
        if (action.value === 'delete' && !confirm('Delete the selected prospects?')) e.preventDefault();
    });
    refresh();
})();
</script>
@endsection
