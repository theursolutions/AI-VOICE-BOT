@extends('layouts.master')
@section('content')
@php
    $base = ['client' => $client->slug, 'project_id' => $project->id];
    $lines = fn ($v) => is_array($v) ? implode("\n", $v) : (string) $v;
    $editing = $profile->exists;
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $editing ? 'Edit offer profile' : 'New offer profile'])
    <form method="POST" action="{{ $editing ? route('leadgen.profiles.update', $base + ['id' => $profile->id]) : route('leadgen.profiles.store', ['client' => $client->slug]) }}" class="tva-lg-grid" style="align-items:start">
        @csrf @if ($editing) @method('PATCH') @endif
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <div class="tva-lg-panel">
            <div class="tva-lg-h">What you sell</div>
            <div class="tva-lg-field" style="margin-top:0"><label>Profile name</label><input name="name" class="form-control" value="{{ old('name', $profile->name) }}" required maxlength="120" placeholder="e.g. AI support for real-estate agencies"></div>
            <div class="tva-lg-field"><label>Product or service</label><textarea name="product" rows="3" class="form-control" required maxlength="2000">{{ old('product', $profile->product) }}</textarea></div>
            <div class="tva-lg-field"><label>Value proposition</label><textarea name="value_prop" rows="3" class="form-control" required maxlength="2000">{{ old('value_prop', $profile->value_prop) }}</textarea>
                <div class="tva-lg-hint">One or two sentences on the result a customer gets. Used in drafts as <code>&#123;&#123;value_proposition&#125;&#125;</code>.</div></div>
            <div class="tva-lg-field"><label>Ideal customer <span class="tva-lg-sub">(one per line)</span></label><textarea name="icp" rows="4" class="form-control" required maxlength="2000" placeholder="Real-estate agencies and brokers&#10;In Dubai and Abu Dhabi&#10;With a website and an active phone line">{{ old('icp', $lines($profile->icp)) }}</textarea></div>
            <div class="tva-lg-field"><label>Exclude <span class="tva-lg-sub">(one per line)</span></label><textarea name="exclusions" rows="3" class="form-control" maxlength="2000" placeholder="Government entities&#10;Developers selling only their own projects">{{ old('exclusions', $lines($profile->exclusions)) }}</textarea></div>
            <div class="tva-lg-field"><label>Questions a good prospect answers “yes” to <span class="tva-lg-sub">(one per line)</span></label><textarea name="questions" rows="3" class="form-control" maxlength="2000" placeholder="Do they handle many customer enquiries?">{{ old('questions', $lines($profile->questions)) }}</textarea></div>
        </div>
        <div>
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Scoring weights</div>
                <div class="tva-lg-hint" style="margin-top:0">How much each criterion counts toward the 0–100 score.</div>
                @foreach (config('leadgen.criteria') as $key => $c)
                    <div class="flex items-center gap-3 mt-2">
                        <div class="text-sm flex-1">{{ $c['label'] }}</div>
                        <input type="number" name="weights[{{ $key }}]" min="0" max="100" class="form-control form-control-sm" style="width:80px"
                               value="{{ old('weights.' . $key, $profile->scoring[$key]['weight'] ?? $c['weight']) }}">
                    </div>
                @endforeach
            </div>
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Messages</div>
                <div class="tva-lg-field" style="margin-top:0"><label>Sender name</label><input name="sender_name" class="form-control" value="{{ old('sender_name', $profile->sender_name) }}" maxlength="120"></div>
                <div class="tva-lg-field"><label>Booking link <span class="tva-lg-sub">(optional, https)</span></label><input name="booking_link" type="url" class="form-control" value="{{ old('booking_link', $profile->booking_link) }}" maxlength="500" placeholder="https://cal.com/you/15min"></div>
                <div class="grid grid-cols-2 gap-2 tva-lg-field">
                    <div><label class="text-xs font-semibold">Default language</label>
                        <select name="language" class="form-select">@foreach (config('leadgen.languages') as $code => $name)<option value="{{ $code }}" @selected(old('language', $profile->language) === $code)>{{ \Illuminate\Support\Str::before($name, ' (') }}</option>@endforeach</select></div>
                    <div><label class="text-xs font-semibold">Default tone</label>
                        <select name="tone" class="form-select">@foreach (\App\Services\LeadGen\Ai\Drafter::TONES as $k => $l)<option value="{{ $k }}" @selected(old('tone', $profile->tone) === $k)>{{ $l }}</option>@endforeach</select></div>
                </div>
            </div>
            <button class="btn btn-primary w-full mt-4"><i data-lucide="save"></i>Save profile</button>
        </div>
    </form>
</div>
@endsection
