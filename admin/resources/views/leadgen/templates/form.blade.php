@extends('layouts.master')
@section('content')
@php
    $base = ['client' => $client->slug, 'project_id' => $project->id];
    $editing = $template->exists;
@endphp
<div class="content">
    @include('leadgen._header', ['title' => $editing ? 'Edit template' : 'New template'])
    <form method="POST" id="lg-tpl" action="{{ $editing ? route('leadgen.templates.update', $base + ['id' => $template->id]) : route('leadgen.templates.store', ['client' => $client->slug]) }}" class="tva-lg-grid" style="align-items:start">
        @csrf @if ($editing) @method('PATCH') @endif
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <div class="tva-lg-panel">
            <div class="grid grid-cols-2 gap-2">
                <div class="col-span-2"><label class="text-xs font-semibold">Name</label><input name="name" class="form-control" value="{{ old('name', $template->name) }}" required maxlength="160"></div>
                <div><label class="text-xs font-semibold">Channel</label>
                    <select name="channel" class="form-select" id="lg-channel">@foreach (\App\Models\LeadGen\Template::CHANNELS as $c)<option value="{{ $c }}" @selected(old('channel', $template->channel) === $c)>{{ ucfirst($c) }}</option>@endforeach</select></div>
                <div><label class="text-xs font-semibold">Kind</label>
                    <select name="kind" class="form-select">@foreach (['intro' => 'Introduction', 'follow_up' => 'Follow-up', 'meeting' => 'Meeting invitation', 'inquiry' => 'Business inquiry'] as $k => $l)<option value="{{ $k }}" @selected(old('kind', $template->kind) === $k)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="text-xs font-semibold">Language</label>
                    <select name="language" class="form-select">@foreach (config('leadgen.languages') as $code => $name)<option value="{{ $code }}" @selected(old('language', $template->language) === $code)>{{ \Illuminate\Support\Str::before($name, ' (') }}</option>@endforeach</select></div>
            </div>
            <div class="tva-lg-field" data-only="email"><label>Subject</label><input name="subject" id="lg-subject" class="form-control" value="{{ old('subject', $template->subject) }}" maxlength="255"></div>
            <div class="tva-lg-field" data-only="whatsapp">
                <label>Approved WhatsApp template name</label>
                <input name="wa_template_name" class="form-control" value="{{ old('wa_template_name', $template->wa_template_name) }}" maxlength="191" placeholder="e.g. intro_offer_v1">
                <div class="tva-lg-hint">WhatsApp only delivers templates Meta has approved, and only to people who opted in. The text below is your reference copy.</div>
            </div>
            <div class="tva-lg-field"><label>Message</label><textarea name="body" id="lg-body" rows="12" class="form-control" required maxlength="5000">{{ old('body', $template->body) }}</textarea>
                <div class="tva-lg-hint">Variables: @foreach (\App\Models\LeadGen\Template::VARIABLES as $v)<code class="mr-1">&#123;&#123;{{ $v }}&#125;&#125;</code>@endforeach</div></div>
            <button class="btn btn-primary mt-4"><i data-lucide="save"></i>Save template</button>
        </div>
        <div class="tva-lg-panel">
            <div class="flex items-center gap-2">
                <div class="tva-lg-h" style="margin:0">Preview</div>
                @if ($profiles->isNotEmpty())
                <select id="lg-prev-profile" class="form-select form-select-sm ml-auto" style="width:auto">
                    @foreach ($profiles as $pf)<option value="{{ hashid($pf->id) }}">{{ $pf->name }}</option>@endforeach
                </select>
                @endif
            </div>
            <div class="tva-lg-sub mt-1">With a sample business (Acme Realty, Dubai).</div>
            <div class="mt-3 text-sm font-semibold" id="lg-prev-subject"></div>
            <div class="mt-2 text-sm" id="lg-prev-body" style="white-space:pre-wrap"></div>
            <div class="mt-3" id="lg-prev-warn"></div>
        </div>
    </form>
</div>
@endsection

@section('script')
<script>
(function () {
    var form = document.getElementById('lg-tpl'), csrf = form.querySelector('[name=_token]').value;
    var url = @json(route('leadgen.templates.preview', ['client' => $client->slug]));
    var channel = document.getElementById('lg-channel');
    function show() {
        form.querySelectorAll('[data-only]').forEach(function (el) { el.style.display = el.dataset.only === channel.value ? '' : 'none'; });
    }
    var timer;
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var prof = document.getElementById('lg-prev-profile');
            fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({project_id: @json(hashid($project->id)), subject: channel.value === 'email' ? document.getElementById('lg-subject').value : '',
                                      body: document.getElementById('lg-body').value || ' ', profile_id: prof ? prof.value : null})})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    document.getElementById('lg-prev-subject').textContent = d.subject || '';
                    document.getElementById('lg-prev-body').textContent = d.body || '';
                    var w = [];
                    if (d.missing && d.missing.length) w.push('No value yet for: ' + d.missing.join(', ') + ' (set it on the offer profile).');
                    if (d.unknown && d.unknown.length) w.push('Unknown variable: ' + d.unknown.join(', '));
                    document.getElementById('lg-prev-warn').innerHTML = w.map(function (t) { var e = document.createElement('div'); e.className = 'alert alert-warning text-xs'; e.textContent = t; return e.outerHTML; }).join('');
                });
        }, 350);
    }
    channel.addEventListener('change', function () { show(); preview(); });
    ['lg-subject', 'lg-body', 'lg-prev-profile'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', preview); if (el) el.addEventListener('change', preview); });
    show(); preview();
})();
</script>
@endsection
