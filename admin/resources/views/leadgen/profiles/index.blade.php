@extends('layouts.master')
@section('content')
@php $base = ['client' => $client->slug, 'project_id' => $project->id]; @endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Offer profiles'])
    <div class="mt-4 flex"><a class="btn btn-primary" href="{{ route('leadgen.profiles.create', $base) }}"><i data-lucide="plus"></i>New offer profile</a></div>

    @if ($profiles->isEmpty())
        <div class="tva-lg-panel tva-lg-empty">
            <span class="tva-tone tva-tone--indigo mx-auto" style="width:52px;height:52px"><i data-lucide="target"></i></span>
            <div class="mt-3 font-semibold text-slate-600">No offer profiles yet</div>
            <div class="mt-1">Create one to score prospects and write outreach. Agencies: make one per client you generate leads for.</div>
            <a class="btn btn-sm btn-primary mt-4" href="{{ route('leadgen.profiles.create', $base) }}"><i data-lucide="plus"></i>Create your first profile</a>
        </div>
    @else
        <div class="tva-lg-steps" style="grid-template-columns:repeat(auto-fill, minmax(300px, 1fr))">
            @foreach ($profiles as $pf)
                <div class="tva-lg-step">
                    <div class="flex items-center gap-3">
                        <span class="tva-tone tva-tone--indigo" style="width:40px;height:40px"><i data-lucide="target"></i></span>
                        <a class="tva-lg-step__t" href="{{ route('leadgen.profiles.edit', $base + ['id' => $pf->id]) }}">{{ $pf->name }}</a>
                    </div>
                    <div class="tva-lg-step__d">{{ \Illuminate\Support\Str::limit($pf->product, 140) }}</div>
                    <div class="flex flex-wrap gap-1">
                        <span class="tva-tag tva-tag--1">{{ \Illuminate\Support\Str::before(config('leadgen.languages.' . $pf->language, $pf->language), ' (') }}</span>
                        <span class="tva-tag tva-tag--2">{{ \App\Services\LeadGen\Ai\Drafter::TONES[$pf->tone] ?? $pf->tone }}</span>
                        @if ($pf->booking_link)<span class="tva-tag tva-tag--5">booking link</span>@endif
                    </div>
                    <div class="flex gap-2 mt-1">
                        <a class="btn btn-sm btn-outline-secondary flex-1" href="{{ route('leadgen.profiles.edit', $base + ['id' => $pf->id]) }}"><i data-lucide="edit-3"></i>Edit</a>
                        <form method="POST" action="{{ route('leadgen.profiles.destroy', $base + ['id' => $pf->id]) }}" data-confirm="Delete this offer profile? Past scores are kept.">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" title="Delete"><i data-lucide="trash-2"></i></button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
