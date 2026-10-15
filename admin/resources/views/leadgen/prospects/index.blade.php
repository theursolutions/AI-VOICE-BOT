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
    $filtered = collect($f)->except(['list_id', 'search_id'])->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    $keep     = array_filter(['list_id' => $f['list_id'] ?? null, 'search_id' => $f['search_id'] ?? null]);
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $listName ? 'List: ' . $listName : 'Prospects'])

    {{-- Quick status tabs --}}
    <div class="flex gap-2 mt-4 flex-wrap">
        @foreach (['' => ['All', 'layers'], 'new' => ['New', 'sparkle'], 'reviewing' => ['In review', 'clipboard-check'], 'approved' => ['Approved', 'check-circle'], 'in_crm' => ['In CRM', 'user-check'], 'rejected' => ['Rejected', 'x-circle']] as $st => [$label, $icon])
            @php $on = ($f['status'] ?? '') === $st; @endphp
            <a href="{{ route('leadgen.prospects.index', $base + $keep + ($st ? ['status' => $st] : [])) }}"
               class="tva-lg-tab2 {{ $on ? 'tva-lg-tab2--on' : '' }}" style="padding:6px 12px">
                <i data-lucide="{{ $icon === 'sparkle' ? 'star' : $icon }}"></i>{{ $label }}
            </a>
        @endforeach
    </div>

    {{-- Filters --}}
    <form method="GET" class="tva-toolbar">
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        @if (!empty($f['list_id']))<input type="hidden" name="list_id" value="{{ hashid((int) $f['list_id']) }}">@endif
        @if (!empty($f['search_id']))<input type="hidden" name="search_id" value="{{ hashid((int) $f['search_id']) }}">@endif
        @if (!empty($f['status']))<input type="hidden" name="status" value="{{ $f['status'] }}">@endif
        <div class="tva-search">
            <i data-lucide="search"></i>
            <input type="text" name="q" value="{{ $f['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Search name, website, email or phone">
        </div>
        <select name="has" class="form-select form-select-sm">
            <option value="">Any contact info</option>
            @foreach (['email' => 'Has email', 'phone' => 'Has phone', 'website' => 'Has website', 'nothing' => 'No email or phone'] as $k => $l)
                <option value="{{ $k }}" @selected(($f['has'] ?? '') === $k)>{{ $l }}</option>
            @endforeach
        </select>
        <input type="text" name="city" value="{{ $f['city'] ?? '' }}" class="form-control form-control-sm" placeholder="City" style="width:120px">
        <input type="text" name="tag" value="{{ $f['tag'] ?? '' }}" class="form-control form-control-sm" placeholder="Tag" style="width:110px">
        <select name="show" class="form-select form-select-sm">
            <option value="">Hide do-not-contact</option>
            <option value="all" @selected(($f['show'] ?? '') === 'all')>Show everything</option>
            <option value="suppressed" @selected(($f['show'] ?? '') === 'suppressed')>Only do-not-contact</option>
            <option value="possible_duplicates" @selected(($f['show'] ?? '') === 'possible_duplicates')>Possible duplicates</option>
        </select>
        <button class="btn btn-sm btn-primary"><i data-lucide="filter"></i>Filter</button>
        @if ($filtered)
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('leadgen.prospects.index', $base + $keep) }}"><i data-lucide="x"></i>Clear</a>
        @endif
        @if ($canExp)
            <a class="btn btn-sm btn-outline-secondary ml-auto" href="{{ route('leadgen.prospects.export', request()->query() + $base) }}"><i data-lucide="download"></i>Export Excel</a>
        @endif
    </form>

    <form method="POST" action="{{ route('leadgen.prospects.bulk', ['client' => $client->slug]) }}" id="lg-bulk">
        @csrf
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <div class="tva-lg-panel tva-lg-panel--flush">
            @if (($canEdit || $canSrch) && $prospects->isNotEmpty())
            <div class="tva-bulkbar" id="lg-bulk-bar">
                <span class="tva-bulkbar__count" id="lg-sel-count">0 selected</span>
                <select name="action" class="form-select form-select-sm" style="width:auto" id="lg-action">
                    <option value="">Choose an action…</option>
                    @if ($canSrch)
                        <optgroup label="Check & score">
                            <option value="enrich">Check their websites</option>
                            @if ($profiles->isNotEmpty())<option value="qualify">Score against offer profile</option>@endif
                        </optgroup>
                    @endif
                    @if ($canEdit)
                        <optgroup label="Organise">
                            <option value="add_to_list">Add to list</option>
                            @if (!empty($f['list_id']))<option value="remove_from_list">Remove from this list</option>@endif
                            <option value="tag">Add a tag</option>
                            <option value="approve">Mark approved</option>
                            <option value="reject">Mark rejected</option>
                            <option value="add_to_crm">Add to CRM</option>
                        </optgroup>
                        <optgroup label="Remove">
                            <option value="suppress">Do not contact</option>
                            <option value="delete">Delete</option>
                        </optgroup>
                    @endif
                </select>
                <span data-for="add_to_list" class="hidden flex gap-2">
                    <select name="list_id" class="form-select form-select-sm" style="width:auto">
                        <option value="">New list…</option>
                        @foreach ($lists as $l)<option value="{{ hashid($l->id) }}">{{ $l->name }}</option>@endforeach
                    </select>
                    <input type="text" name="new_list" class="form-control form-control-sm" placeholder="New list name" style="width:170px">
                </span>
                @if (!empty($f['list_id']))<input type="hidden" name="list_id" value="{{ hashid((int) $f['list_id']) }}" data-for-list disabled>@endif
                <span data-for="qualify" class="hidden"><select name="profile_id" class="form-select form-select-sm" style="width:auto">@foreach ($profiles as $pf)<option value="{{ hashid($pf->id) }}">{{ $pf->name }}</option>@endforeach</select></span>
                <span data-for="tag" class="hidden"><input type="text" name="tag" class="form-control form-control-sm" placeholder="Tag name" style="width:150px"></span>
                <label data-for="delete" class="hidden text-xs flex items-center gap-2"><input type="checkbox" name="never_readd" value="1" class="form-check-input" checked> Don’t add these again in future searches</label>
                <button class="btn btn-sm btn-primary" id="lg-apply" disabled><i data-lucide="check"></i>Apply</button>
            </div>
            @endif

            @if ($prospects->isEmpty())
                <div class="tva-lg-empty">
                    <span class="tva-tone tva-tone--indigo mx-auto" style="width:52px;height:52px"><i data-lucide="building"></i></span>
                    <div class="mt-3 font-semibold text-slate-600">{{ $filtered ? 'No prospects match these filters' : 'No prospects yet' }}</div>
                    <div class="mt-1">{{ $filtered ? 'Try clearing a filter.' : 'Find businesses by area and type, or import a list you already have.' }}</div>
                    <div class="flex gap-2 justify-center mt-4">
                        @if ($filtered)
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('leadgen.prospects.index', $base + $keep) }}"><i data-lucide="x"></i>Clear filters</a>
                        @elseif ($canSrch)
                            <a class="btn btn-sm btn-primary" href="{{ route('leadgen.searches.create', $base) }}"><i data-lucide="search"></i>Find businesses</a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('leadgen.imports.create', $base) }}"><i data-lucide="upload"></i>Import a list</a>
                        @endif
                    </div>
                </div>
            @else
            <div style="overflow-x:auto">
            <table class="tva-lg-tbl">
                <thead><tr>
                    <th style="width:1%"><input type="checkbox" class="form-check-input" id="lg-all" title="Select all on this page"></th>
                    <th>Business</th><th>Contact</th><th>Location</th><th>Fit</th><th>Status</th><th style="width:1%"></th>
                </tr></thead>
                <tbody>
                @foreach ($prospects as $pr)
                    @php $q = $pr->currentQualification; $href = route('leadgen.prospects.show', $base + ['id' => $pr->id]); @endphp
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ hashid($pr->id) }}" class="form-check-input" data-row></td>
                        <td style="min-width:240px">
                            <div class="tva-who">
                                @include('leadgen._chips', ['chip' => 'avatar', 'name' => $pr->business_name])
                                <div style="min-width:0">
                                    <a class="tva-who__n" href="{{ $href }}">{{ $pr->business_name }}</a>
                                    <div class="tva-who__s">
                                        @if ($pr->category)<span>{{ str_replace('_', ' ', $pr->category) }}</span>@endif
                                        @if ($pr->domain_norm)<span>· {{ $pr->domain_norm }}</span>@endif
                                        @foreach ((array) $pr->tags as $t)@include('leadgen._chips', ['chip' => 'tag', 'tag' => $t])@endforeach
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="min-width:210px">
                            @if ($pr->email)
                                <div class="tva-cell-ico"><i data-lucide="mail"></i>{{ $pr->email }}</div>
                                <div class="mt-1">@include('leadgen.prospects._verified', ['level' => $pr->verificationOf('email'), 'mx' => $pr->field_meta['email']['mx'] ?? null])</div>
                            @endif
                            @if ($pr->phone_e164)
                                <div class="tva-cell-ico {{ $pr->email ? 'mt-2' : '' }}"><i data-lucide="phone"></i>{{ $pr->phone_e164 }}</div>
                            @endif
                            @if (!$pr->email && !$pr->phone_e164)<span class="tva-empty-dash">No contact details yet</span>@endif
                        </td>
                        <td>
                            @if ($pr->city || $pr->country_code)
                                <span class="tva-cell-ico"><i data-lucide="map-pin"></i>{{ trim(($pr->city ?? '') . ($pr->country_code ? ', ' . $pr->country_code : ''), ', ') }}</span>
                            @else <span class="tva-empty-dash">—</span> @endif
                        </td>
                        <td>@if ($q)@include('leadgen._chips', ['chip' => 'score', 'q' => $q])@else<span class="tva-empty-dash">Not scored</span>@endif</td>
                        <td>
                            <div class="flex flex-col gap-1 items-start">
                                @include('leadgen._chips', ['chip' => 'status', 'status' => $pr->status])
                                @if ($pr->suppressed_at)<span class="tva-st tva-st--blocked">Do not contact</span>@endif
                                @if ($pr->duplicate_of)<span class="tva-st tva-st--dup">Possible duplicate</span>@endif
                            </div>
                        </td>
                        <td><a href="{{ $href }}" class="btn btn-sm btn-outline-secondary" title="Open"><i data-lucide="chevron-right"></i></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @include('leadgen._pager', ['paginator' => $prospects, 'noun' => 'prospects'])
            @endif
        </div>
    </form>

    @if ($canEdit && empty($f['list_id']))
    <details class="tva-lg-panel">
        <summary class="cursor-pointer text-sm font-semibold flex items-center gap-2"><i data-lucide="list" class="w-4 h-4"></i>Create an empty list</summary>
        <form method="POST" action="{{ route('leadgen.lists.store', ['client' => $client->slug]) }}" class="flex gap-2 mt-3">
            @csrf<input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
            <input type="text" name="name" class="form-control form-control-sm" placeholder="List name" required maxlength="160" style="width:260px">
            <button class="btn btn-sm btn-primary"><i data-lucide="plus"></i>Create</button>
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
    var bar = document.getElementById('lg-bulk-bar');
    function refresh() {
        var n = form.querySelectorAll('[data-row]:checked').length;
        if (count) count.textContent = n ? n + ' selected' : '0 selected';
        if (bar) bar.classList.toggle('has-selection', n > 0);
        rows.forEach(function (r) { r.closest('tr').classList.toggle('is-selected', r.checked); });
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
    var bulkUrl = form.action, qualifyUrl = @json(route('leadgen.qualify.queue', ['client' => $client->slug]));
    form.addEventListener('submit', function (e) {
        if (action.value === 'delete' && !confirm('Delete the selected prospects?')) { e.preventDefault(); return; }
        form.action = action && action.value === 'qualify' ? qualifyUrl : bulkUrl;
        if (apply) { apply.disabled = true; apply.innerHTML = '<span class="tva-spin"></span> Working…'; }
    });
    refresh();
})();
</script>
@endsection
