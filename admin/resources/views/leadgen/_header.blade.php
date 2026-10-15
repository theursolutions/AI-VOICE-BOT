{{--
    Lead Generation page header: title, project switcher, section tabs.
    Expects $client, $project (nullable), $projects; optional $title, $subtitle.
    Tabs only show for actions the member's role allows.
--}}
@php
    $u    = auth()->user();
    $canA = fn (string $a) => $u && $u->canAbility($client->id, 'leadgen', $a);
    $pq   = $project ? ['project_id' => $project->id] : [];
    $tabs = array_filter([
        ['leadgen.index',           'Overview',   'view'],
        ['leadgen.searches.index',  'Searches',   'view'],
        ['leadgen.searches.create', 'New search', 'search'],
        ['leadgen.imports.create',  'Import',     'import'],
    ], fn ($t) => $canA($t[2]));
@endphp
@include('leadgen._styles')
<div class="flex items-center gap-3 mt-4 flex-wrap">
    <h2 class="text-lg font-semibold">{{ $title ?? 'Lead Generation' }}</h2>
    @isset($subtitle)<div class="text-xs text-slate-500">{{ $subtitle }}</div>@endisset
    @if ($projects->count() > 1)
        <form method="GET" class="ml-auto">
            <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ hashid($p->id) }}" @selected($project && (int) $project->id === (int) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    @endif
</div>
@if ($project)
<nav class="tva-lg-tabs">
    @foreach ($tabs as [$route, $label])
        <a href="{{ route($route, ['client' => $client->slug] + $pq) }}"
           class="tva-lg-tab {{ request()->routeIs($route) || ($route === 'leadgen.searches.index' && request()->routeIs('leadgen.searches.show')) ? 'tva-lg-tab--on' : '' }}">{{ $label }}</a>
    @endforeach
</nav>
@endif
@if (session('success'))
    <div class="alert alert-success mt-4">{{ session('success') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-danger mt-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
@endif
