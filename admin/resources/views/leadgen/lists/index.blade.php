@extends('layouts.master')
@section('content')
@php
    $canEdit = auth()->user()->canAbility($client->id, 'leadgen', 'edit');
    $base    = ['client' => $client->slug, 'project_id' => $project->id];
    $tones   = ['indigo', 'blue', 'emerald', 'amber', 'rose', 'teal', 'violet'];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Lists'])

    @if ($canEdit)
    <form method="POST" action="{{ route('leadgen.lists.store', ['client' => $client->slug]) }}" class="tva-toolbar">
        @csrf<input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <span class="tva-tone tva-tone--indigo" style="width:34px;height:34px"><i data-lucide="list"></i></span>
        <input type="text" name="name" class="form-control form-control-sm" placeholder="New list name, e.g. Dubai agencies – October" required maxlength="160" style="width:320px">
        <button class="btn btn-sm btn-primary"><i data-lucide="plus"></i>Create list</button>
        <span class="tva-lg-sub ml-auto hidden md:inline">Or select prospects and choose “Add to list”.</span>
    </form>
    @endif

    @if ($lists->isEmpty())
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--amber mx-auto" style="width:52px;height:52px"><i data-lucide="list"></i></span>
            <div class="mt-3 font-semibold text-slate-600">No lists yet</div>
            <div class="mt-1">Lists group the prospects you want to contact. A campaign sends to one list.</div>
            <a class="btn btn-sm btn-outline-secondary mt-4" href="{{ route('leadgen.prospects.index', $base) }}"><i data-lucide="building"></i>Go to prospects</a>
        </div>
    @else
        <div class="tva-lg-steps" style="grid-template-columns:repeat(auto-fill, minmax(260px, 1fr))">
            @foreach ($lists as $l)
                <div class="tva-lg-step">
                    <div class="flex items-center gap-3">
                        <span class="tva-tone tva-tone--{{ $tones[crc32($l->name) % count($tones)] }}" style="width:40px;height:40px"><i data-lucide="list"></i></span>
                        <div style="min-width:0">
                            <a class="tva-lg-step__t block truncate" href="{{ route('leadgen.prospects.index', $base + ['list_id' => $l->id]) }}">{{ $l->name }}</a>
                            <div class="tva-lg-sub">{{ number_format($l->items_count) }} prospect{{ $l->items_count === 1 ? '' : 's' }}</div>
                        </div>
                    </div>
                    @if ($l->description)<div class="tva-lg-step__d">{{ $l->description }}</div>@endif
                    <div class="tva-lg-sub">Created {{ $l->created_at ? \Carbon\Carbon::createFromTimestamp($l->created_at)->toFormattedDateString() : '' }}</div>
                    <div class="flex gap-2 mt-1">
                        <a class="btn btn-sm btn-outline-secondary flex-1" href="{{ route('leadgen.prospects.index', $base + ['list_id' => $l->id]) }}"><i data-lucide="eye"></i>Open</a>
                        @if ($canEdit)
                        <form method="POST" action="{{ route('leadgen.lists.destroy', $base + ['id' => $l->id]) }}" data-confirm="Delete this list? The prospects in it are kept.">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="Delete list"><i data-lucide="trash-2"></i></button>
                        </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
        <div class="tva-lg-panel tva-lg-panel--flush" style="padding:0">@include('leadgen._pager', ['paginator' => $lists, 'noun' => 'lists'])</div>
    @endif
</div>
@endsection
