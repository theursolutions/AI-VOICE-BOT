@extends('layouts.master')
@section('content')
<div class="content">
    @include('leadgen._header', ['title' => 'Searches & imports'])

    <div class="flex gap-4 mt-4 text-sm">
        <a href="{{ route('leadgen.searches.index', ['client' => $client->slug, 'project_id' => $project->id]) }}"
           class="{{ $tab === 'all' ? 'font-semibold text-primary' : 'text-slate-500' }}">All</a>
        <a href="{{ route('leadgen.searches.index', ['client' => $client->slug, 'project_id' => $project->id, 'tab' => 'saved']) }}"
           class="{{ $tab === 'saved' ? 'font-semibold text-primary' : 'text-slate-500' }}">Saved</a>
    </div>

    <div class="tva-lg-panel tva-lg-panel--flush">
        @if ($searches->isEmpty())
            <div class="tva-lg-empty">
                {{ $tab === 'saved' ? 'No saved searches yet. Save a search to run it again later.' : 'No searches yet.' }}
            </div>
        @else
            <table class="tva-lg-tbl">
                <thead><tr><th>Search</th><th>Source</th><th>Status</th><th>New</th><th>Already found</th><th>Started</th></tr></thead>
                <tbody>
                @foreach ($searches as $s)
                    @php $p = (array) $s->progress; @endphp
                    <tr>
                        <td>
                            <a class="tva-lg-name" href="{{ route('leadgen.searches.show', ['client' => $client->slug, 'id' => $s->id, 'project_id' => $project->id]) }}">{{ $s->name }}</a>
                            @if ($s->saved)<span class="tva-lg-sub"> · saved</span>@endif
                            <div class="tva-lg-sub">
                                {{ $s->provider === 'import' ? ($s->criteria['original_name'] ?? 'Uploaded file') : ($s->criteria['area_label'] ?? '') }}
                            </div>
                        </td>
                        <td>{{ $s->provider === 'import' ? 'Your import' : 'Overture Maps' }}</td>
                        <td>@include('leadgen.searches._status', ['status' => $s->status])</td>
                        <td class="tabular-nums">{{ number_format((int) ($p['new'] ?? 0)) }}</td>
                        <td class="tabular-nums">{{ number_format((int) ($p['duplicates'] ?? 0)) }}</td>
                        <td class="tva-lg-sub">{{ $s->created_at ? \Carbon\Carbon::createFromTimestamp($s->created_at)->diffForHumans() : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="px-5 pt-3">@include('partials.pagination', ['paginator' => $searches])</div>
        @endif
    </div>
</div>
@endsection
