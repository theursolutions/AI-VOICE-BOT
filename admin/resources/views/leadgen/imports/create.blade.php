@extends('layouts.master')
@section('content')
<div class="content">
    @include('leadgen._header', ['title' => 'Import a prospect list'])

    <div class="tva-wz-card" style="max-width:680px">
        <div class="flex items-center gap-3 mb-4">
            <span class="tva-tone tva-tone--violet" style="width:44px;height:44px"><i data-lucide="upload"></i></span>
            <div><div class="tva-wz-title">Upload your list</div><div class="tva-wz-sub" style="margin-top:2px">Step 1 of 2 — next you’ll match your columns.</div></div>
        </div>
        <form method="POST" action="{{ route('leadgen.imports.upload', ['client' => $client->slug]) }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
            <div class="tva-lg-field" style="margin-top:0">
                <label>File</label>
                <input type="file" name="file" accept=".csv,.xlsx,.txt,.tsv" class="form-control" required>
                <div class="tva-lg-hint">CSV or Excel (.xlsx), up to 5 MB and {{ number_format(\App\Services\LeadGen\Providers\ImportSource::MAX_ROWS) }} rows. The first row must be column headings. You'll match columns on the next step.</div>
            </div>
            <label class="flex items-start gap-2 text-sm mt-4">
                <input type="checkbox" name="attested" value="1" class="form-check-input mt-1" required>
                <span>I confirm this list was collected lawfully and I'm allowed to use it to contact these businesses.</span>
            </label>
            <button class="btn btn-primary mt-4"><i data-lucide="upload"></i>Upload and continue</button>
        </form>
    </div>
</div>
@endsection
