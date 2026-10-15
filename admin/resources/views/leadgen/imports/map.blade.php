@extends('layouts.master')
@section('content')
<div class="content">
    @include('leadgen._header', ['title' => 'Match your columns', 'subtitle' => $fileName . ' · ' . number_format($rowCount) . ' rows'])

    @if ($tooMany)
        <div class="alert alert-warning mt-4">Only the first {{ number_format(\App\Services\LeadGen\Providers\ImportSource::MAX_ROWS) }} rows will be imported.</div>
    @endif

    <form method="POST" action="{{ route('leadgen.imports.start', ['client' => $client->slug, 'token' => $token]) }}">
        @csrf
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <div class="tva-lg-grid" style="align-items:start">
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Which column holds…</div>
                @foreach ($fields as $field => $label)
                    <div class="flex items-center gap-3 mt-2">
                        <div class="text-sm" style="width:170px">{{ $label }}@if ($field === 'business_name') <span class="text-danger">*</span>@endif</div>
                        <select name="mapping[{{ $field }}]" class="form-select form-select-sm" @if ($field === 'business_name') required @endif>
                            <option value="">— not in this file —</option>
                            @foreach ($header as $i => $col)
                                <option value="{{ $i }}" @selected(($guess[$field] ?? null) === $i)>{{ $col !== '' ? $col : 'Column ' . ($i + 1) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            <div class="tva-lg-panel">
                <div class="tva-lg-h">Options</div>
                <div class="tva-lg-field" style="margin-top:0">
                    <label>Default country for phone numbers</label>
                    <select name="country_code" class="form-select">
                        <option value="">— numbers already include the country code —</option>
                        @foreach ($countries as $code => $name)
                            <option value="{{ $code }}" @selected($code === 'AE')>{{ $name }}</option>
                        @endforeach
                    </select>
                    <div class="tva-lg-hint">Used for numbers written without a + prefix, e.g. 050 123 4567.</div>
                </div>
                <div class="tva-lg-field">
                    <label>Name this import <span class="tva-lg-sub">(optional)</span></label>
                    <input type="text" name="name" class="form-control" maxlength="160" placeholder="Import: {{ $fileName }}">
                </div>
                <div class="tva-lg-hint mt-3">Importing the same rows again won't create duplicates. Imported contact details are marked unverified.</div>
                <button class="btn btn-primary w-full mt-4"><i data-lucide="rocket"></i>Import {{ number_format(min($rowCount, \App\Services\LeadGen\Providers\ImportSource::MAX_ROWS)) }} rows</button>
            </div>
        </div>
    </form>

    <div class="tva-lg-panel tva-lg-panel--flush">
        <div class="tva-lg-h px-5">Preview (first rows)</div>
        <table class="tva-lg-tbl">
            <thead><tr>@foreach ($header as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($preview as $row)
                <tr>@foreach ($header as $i => $_)<td>{{ \Illuminate\Support\Str::limit((string) ($row[$i] ?? ''), 40) }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
