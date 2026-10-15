@extends('layouts.master')
@section('content')
@php
    $canEdit = auth()->user()->canAbility($client->id, 'leadgen', 'edit');
    $base    = ['client' => $client->slug, 'project_id' => $project->id];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Lists'])

    @if ($canEdit)
    <form method="POST" action="{{ route('leadgen.lists.store', ['client' => $client->slug]) }}" class="flex gap-2 mt-4">
        @csrf<input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <input name="name" class="form-control form-control-sm" placeholder="New list name" required maxlength="160" style="width:260px">
        <button class="btn btn-sm btn-primary">Create list</button>
    </form>
    @endif

    <div class="tva-lg-panel tva-lg-panel--flush">
        @if ($lists->isEmpty())
            <div class="tva-lg-empty">No lists yet. Select prospects and use “Add to list”, or create one above.</div>
        @else
        <table class="tva-lg-tbl">
            <thead><tr><th>List</th><th>Prospects</th><th>Created</th><th></th></tr></thead>
            <tbody>
            @foreach ($lists as $l)
                <tr>
                    <td><a class="tva-lg-name" href="{{ route('leadgen.prospects.index', $base + ['list_id' => $l->id]) }}">{{ $l->name }}</a>
                        @if ($l->description)<div class="tva-lg-sub">{{ $l->description }}</div>@endif</td>
                    <td class="tabular-nums">{{ number_format($l->items_count) }}</td>
                    <td class="tva-lg-sub">{{ $l->created_at ? \Carbon\Carbon::createFromTimestamp($l->created_at)->toFormattedDateString() : '' }}</td>
                    <td class="text-right">
                        @if ($canEdit)
                        <form method="POST" action="{{ route('leadgen.lists.destroy', $base + ['id' => $l->id]) }}" data-confirm="Delete this list? The prospects in it are kept." class="inline">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="px-5 pt-3">@include('partials.pagination', ['paginator' => $lists])</div>
        @endif
    </div>
</div>
@endsection
