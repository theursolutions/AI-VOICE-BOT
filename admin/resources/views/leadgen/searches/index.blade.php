@extends('layouts.master')
@section('content')
@php
    $base = ['client' => $client->slug, 'project_id' => $project->id];
    $canSearch = auth()->user()->canAbility($client->id, 'leadgen', 'search');
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Search history'])

    <div class="flex gap-2 mt-4 flex-wrap items-center">
        <a href="{{ route('leadgen.searches.index', $base) }}" class="tva-lg-tab2 {{ $tab === 'all' ? 'tva-lg-tab2--on' : '' }}" style="padding:6px 12px"><i data-lucide="clock"></i>All</a>
        <a href="{{ route('leadgen.searches.index', $base + ['tab' => 'saved']) }}" class="tva-lg-tab2 {{ $tab === 'saved' ? 'tva-lg-tab2--on' : '' }}" style="padding:6px 12px"><i data-lucide="bookmark"></i>Saved</a>
        @if ($canSearch)
            <a class="btn btn-sm btn-primary ml-auto" href="{{ route('leadgen.searches.create', $base) }}"><i data-lucide="search"></i>New search</a>
        @endif
    </div>

    <div class="tva-lg-panel tva-lg-panel--flush">
        @if ($searches->isEmpty())
            <div class="tva-lg-empty">
                <span class="tva-tone tva-tone--blue mx-auto" style="width:52px;height:52px"><i data-lucide="{{ $tab === 'saved' ? 'bookmark' : 'search' }}"></i></span>
                <div class="mt-3 font-semibold text-slate-600">{{ $tab === 'saved' ? 'No saved searches yet' : 'No searches yet' }}</div>
                <div class="mt-1">{{ $tab === 'saved' ? 'Tick “Save this search” on the last step to keep one here.' : 'Start your first search — for example real-estate agencies in Dubai.' }}</div>
                @if ($canSearch && $tab !== 'saved')
                    <a class="btn btn-sm btn-primary mt-4" href="{{ route('leadgen.searches.create', $base) }}"><i data-lucide="search"></i>Find businesses</a>
                @endif
            </div>
        @else
            <div style="overflow-x:auto">
            <table class="tva-lg-tbl">
                <thead><tr><th>Search</th><th>Status</th><th>New</th><th>Already found</th><th>Started</th><th style="width:1%"></th></tr></thead>
                <tbody>
                @foreach ($searches as $s)
                    @php $p = (array) $s->progress; $isImport = $s->provider === 'import'; $href = route('leadgen.searches.show', $base + ['id' => $s->id]); @endphp
                    <tr>
                        <td style="min-width:260px">
                            <div class="tva-who">
                                <span class="tva-tone {{ $isImport ? 'tva-tone--violet' : 'tva-tone--blue' }}" style="width:36px;height:36px"><i data-lucide="{{ $isImport ? 'upload' : 'map-pin' }}"></i></span>
                                <div style="min-width:0">
                                    <a class="tva-who__n" href="{{ $href }}">{{ $s->name }}</a>
                                    <div class="tva-who__s">
                                        <span>{{ $isImport ? 'Import · ' . ($s->criteria['original_name'] ?? 'file') : 'Overture Maps · ' . ($s->criteria['area_label'] ?? '') }}</span>
                                        @if ($s->saved)<span class="tva-tag tva-tag--1">saved</span>@endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>@include('leadgen.searches._status', ['status' => $s->status])</td>
                        <td><span class="font-bold tabular-nums">{{ number_format((int) ($p['new'] ?? 0)) }}</span></td>
                        <td class="tabular-nums text-slate-500">{{ number_format((int) ($p['duplicates'] ?? 0)) }}</td>
                        <td class="tva-lg-sub">{{ $s->created_at ? \Carbon\Carbon::createFromTimestamp($s->created_at)->diffForHumans() : '' }}</td>
                        <td><a href="{{ $href }}" class="btn btn-sm btn-outline-secondary" title="Open"><i data-lucide="chevron-right"></i></a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @include('leadgen._pager', ['paginator' => $searches, 'noun' => 'searches'])
        @endif
    </div>
</div>
@endsection
