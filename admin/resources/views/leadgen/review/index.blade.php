@extends('layouts.master')
@section('content')
@php
    $base    = ['client' => $client->slug, 'project_id' => $project->id];
    $canEdit = auth()->user()->canAbility($client->id, 'leadgen', 'edit');
    $bands   = ['high' => 'High fit', 'medium' => 'Medium fit', 'low' => 'Low fit', 'needs_review' => 'Needs review'];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Review', 'subtitle' => 'AI scores are suggestions. You decide who to contact.'])

    @if ($profiles->isEmpty())
        <div class="tva-lg-panel tva-lg-empty">Create an offer profile first — scores are always against what you sell.
            @if (auth()->user()->canAbility($client->id, 'leadgen', 'settings'))<br><a class="text-primary" href="{{ route('leadgen.profiles.create', $base) }}">New offer profile</a>@endif</div>
    @else
    <form method="GET" class="flex gap-2 mt-4 items-center flex-wrap">
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <select name="profile_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            @foreach ($profiles as $pf)<option value="{{ hashid($pf->id) }}" @selected($profile && $profile->id === $pf->id)>{{ $pf->name }}</option>@endforeach
        </select>
        <select name="band" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">All bands</option>
            @foreach ($bands as $k => $l)<option value="{{ $k }}" @selected($band === $k)>{{ $l }}</option>@endforeach
        </select>
        <span class="tva-lg-sub">To score more prospects, select them on the Prospects page and choose “Score against offer profile”.</span>
    </form>

    @if ($rows->isEmpty())
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--emerald mx-auto" style="width:52px;height:52px"><i data-lucide="clipboard-check"></i></span>
            <div class="mt-3 font-semibold text-slate-600">Nothing scored against this profile yet</div>
            <div class="mt-1">On the Prospects page, select businesses and choose “Score against offer profile”.</div>
            <a class="btn btn-sm btn-primary mt-4" href="{{ route('leadgen.prospects.index', $base) }}"><i data-lucide="building"></i>Go to prospects</a>
        </div>
    @else
        @foreach ($rows as $q)
            @php $p = $q->prospect; @endphp
            @continue(!$p)
            <div class="tva-lg-panel">
                <div class="tva-lg-grid" style="grid-template-columns: 2fr 1fr">
                    <div>
                        <div class="tva-who">
                            @include('leadgen._chips', ['chip' => 'avatar', 'name' => $p->business_name])
                            <div>
                                <a class="tva-who__n" href="{{ route('leadgen.prospects.show', $base + ['id' => $p->id]) }}">{{ $p->business_name }}</a>
                                <div class="tva-who__s">{{ $p->category ? str_replace('_', ' ', $p->category) : '' }} @if ($p->city)· {{ $p->city }}@endif @include('leadgen._chips', ['chip' => 'status', 'status' => $p->status])</div>
                            </div>
                        </div>
                        <div class="mt-2">@include('leadgen.review._qualification', ['q' => $q])</div>
                    </div>
                    @if ($canEdit)
                    <div>
                        <form method="POST" action="{{ route('leadgen.prospects.bulk', ['client' => $client->slug]) }}" class="flex gap-2">
                            @csrf<input type="hidden" name="project_id" value="{{ hashid($project->id) }}"><input type="hidden" name="ids[]" value="{{ hashid($p->id) }}">
                            <button name="action" value="approve" class="btn btn-sm {{ $p->status === 'approved' ? 'btn-success' : 'btn-outline-secondary' }} flex-1"><i data-lucide="check"></i>{{ $p->status === 'approved' ? 'Approved' : 'Approve' }}</button>
                            <button name="action" value="reject" class="btn btn-sm btn-outline-danger flex-1"><i data-lucide="x"></i>Reject</button>
                        </form>
                        <form method="POST" action="{{ route('leadgen.review.override', $base + ['id' => $q->id]) }}" class="mt-3">
                            @csrf
                            <select name="override_band" class="form-select form-select-sm">
                                <option value="">Agree with the AI</option>
                                @foreach ($bands as $k => $l)<option value="{{ $k }}" @selected($q->override_band === $k)>I’d say: {{ $l }}</option>@endforeach
                            </select>
                            <input name="override_reason" class="form-control form-control-sm mt-1" placeholder="Why (required if you change it)" value="{{ $q->override_reason }}" maxlength="500">
                            <button class="btn btn-sm btn-outline-secondary mt-2 w-full"><i data-lucide="save"></i>Save review</button>
                        </form>
                    </div>
                    @endif
                </div>
            </div>
        @endforeach
        <div class="tva-lg-panel" style="padding:0">@include('leadgen._pager', ['paginator' => $rows, 'noun' => 'scored prospects'])</div>
    @endif
    @endif
</div>
@endsection
