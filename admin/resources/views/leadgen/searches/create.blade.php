@extends('layouts.master')
@section('content')
@php
    $pf = $prefill ?? [];
    $overture = $sources['overture'] ?? null;
    $ready = $overture && $overture->isConfigured();
    $radiusMode = !empty($pf['center']);
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Find businesses'])

    @if (!$ready)
        <div class="tva-lg-panel tva-lg-empty">
            <div class="font-semibold text-slate-600">Business search isn't available yet</div>
            <div class="mt-1">The listings service hasn't been set up on this server. You can still
                <a class="text-primary" href="{{ route('leadgen.imports.create', ['client' => $client->slug, 'project_id' => $project->id]) }}">import your own list</a>.</div>
        </div>
    @else
    <form method="POST" action="{{ route('leadgen.searches.store', ['client' => $client->slug]) }}" id="lg-search" class="tva-lg-grid" style="align-items:start">
        @csrf
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <input type="hidden" name="source" value="overture">
        @if (request('from_id'))<input type="hidden" name="rerun_id" value="{{ hashid((int) request('from_id')) }}">@endif

        <div>
            <div class="tva-lg-panel" style="margin-top:18px">
                <div class="tva-lg-h">1. Where</div>
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-2"><input type="radio" name="area_mode" value="place" class="form-check-input" @checked(!$radiusMode)> A city or district</label>
                    <label class="flex items-center gap-2"><input type="radio" name="area_mode" value="radius" class="form-check-input" @checked($radiusMode)> Around a point</label>
                </div>

                <div data-mode="place" class="{{ $radiusMode ? 'hidden' : '' }}">
                    <div class="tva-lg-field">
                        <label>Country</label>
                        <select name="country_code" class="form-select" id="lg-country">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected(($pf['country_code'] ?? 'AE') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="tva-lg-field">
                        <label>City or district</label>
                        <div class="flex gap-2">
                            <input type="text" id="lg-place-q" class="form-control" placeholder="e.g. Dubai, Jumeirah, Karachi" maxlength="80">
                            <button type="button" class="btn btn-outline-secondary" id="lg-place-find">Find</button>
                        </div>
                        <div id="lg-place-status" class="tva-lg-hint"></div>
                        <div id="lg-place-matches"></div>
                    </div>
                    @foreach ([0, 1, 2, 3] as $i)
                        <input type="hidden" name="bbox[{{ $i }}]" value="{{ $pf['bbox'][$i] ?? '' }}" data-bbox>
                    @endforeach
                </div>

                <div data-mode="radius" class="{{ $radiusMode ? '' : 'hidden' }}">
                    <div class="grid grid-cols-3 gap-2 tva-lg-field">
                        <div><label class="text-xs font-semibold">Latitude</label><input type="number" step="any" name="lat" class="form-control" value="{{ $pf['center'][1] ?? '' }}" data-radius></div>
                        <div><label class="text-xs font-semibold">Longitude</label><input type="number" step="any" name="lng" class="form-control" value="{{ $pf['center'][0] ?? '' }}" data-radius></div>
                        <div><label class="text-xs font-semibold">Radius (km)</label><input type="number" step="0.5" min="0.5" max="{{ \App\Services\LeadGen\Providers\SearchCriteria::MAX_RADIUS_KM }}" name="radius_km" class="form-control" value="{{ $pf['radius_km'] ?? 5 }}" data-radius></div>
                    </div>
                    <div class="tva-lg-hint">Tip: right-click a spot in Google Maps to copy its coordinates.</div>
                </div>

                <input type="hidden" name="area_label" id="lg-area-label" value="{{ $pf['area_label'] ?? '' }}">
                <div id="lg-area-chosen" class="mt-3 text-sm font-semibold">{{ !empty($pf['area_label']) ? 'Area: ' . $pf['area_label'] : '' }}</div>
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">2. What kind of business</div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="lg-cats-load">Show categories in this area</button>
                <div id="lg-cats-status" class="tva-lg-hint"></div>
                <input type="text" id="lg-cats-filter" class="form-control form-control-sm mt-2 hidden" placeholder="Filter categories…">
                <div id="lg-cats" class="tva-lg-chips mt-2">
                    @foreach ((array) ($pf['categories'] ?? []) as $c)
                        <label class="tva-lg-chip tva-lg-chip--on"><input type="checkbox" name="categories[]" value="{{ $c }}" checked> {{ str_replace('_', ' ', $c) }}</label>
                    @endforeach
                </div>
                <div class="tva-lg-field">
                    <label>Keywords <span class="tva-lg-sub">(optional)</span></label>
                    <input type="text" name="keywords" class="form-control" maxlength="120" value="{{ $pf['keywords'] ?? '' }}" placeholder="e.g. properties, realty">
                    <div class="tva-lg-hint">Matched against business names and categories.</div>
                </div>
            </div>
        </div>

        <div>
            <div class="tva-lg-panel" style="margin-top:18px">
                <div class="tva-lg-h">3. Must have</div>
                @foreach ([
                    'require_website' => 'A website',
                    'require_phone'   => 'A phone number',
                    'require_email'   => 'A listed email address',
                    'require_social'  => 'A social media profile',
                ] as $k => $label)
                    <label class="flex items-center gap-2 text-sm mt-2"><input type="checkbox" name="{{ $k }}" value="1" class="form-check-input" @checked($pf[$k] ?? ($k === 'require_website'))> {{ $label }}</label>
                @endforeach
                <label class="flex items-center gap-2 text-sm mt-2"><input type="hidden" name="exclude_closed" value="0"><input type="checkbox" name="exclude_closed" value="1" class="form-check-input" @checked($pf['exclude_closed'] ?? true)> Skip businesses listed as closed</label>
                <label class="flex items-center gap-2 text-sm mt-2"><input type="hidden" name="exclude_existing_crm" value="0"><input type="checkbox" name="exclude_existing_crm" value="1" class="form-check-input" @checked($pf['exclude_existing_crm'] ?? true)> Skip businesses already in my CRM</label>
                <div class="tva-lg-field">
                    <label>Listing quality</label>
                    <select name="min_confidence" class="form-select">
                        @foreach (['0' => 'Any', '0.5' => 'Fair or better', '0.7' => 'Good or better', '0.9' => 'Only the most reliable'] as $v => $l)
                            <option value="{{ $v }}" @selected((string) ($pf['min_confidence'] ?? '0.5') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                    <div class="tva-lg-hint">How sure the source is that the listing is a real, current business.</div>
                </div>
            </div>

            <div class="tva-lg-panel">
                <div class="tva-lg-h">4. Run it</div>
                <div class="tva-lg-field" style="margin-top:0">
                    <label>Name <span class="tva-lg-sub">(optional)</span></label>
                    <input type="text" name="name" class="form-control" maxlength="160" value="{{ $prefillName }}">
                </div>
                <div class="tva-lg-field">
                    <label>Stop after</label>
                    <select name="max_results" class="form-select">
                        @foreach ([100, 250, 500, 1000, 2500, 5000] as $n)
                            <option value="{{ $n }}" @selected((int) ($pf['max_results'] ?? 500) === $n)>{{ number_format($n) }} prospects</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm mt-3"><input type="checkbox" name="saved" value="1" class="form-check-input"> Save this search to run again later</label>
                <div class="flex items-center gap-3 mt-4">
                    <button type="button" class="btn btn-outline-secondary" id="lg-estimate">Estimate</button>
                    <span id="lg-estimate-out" class="text-sm text-slate-500"></span>
                </div>
                <button class="btn btn-primary w-full mt-4" id="lg-submit">Start search</button>
                <div class="tva-lg-hint mt-2">Runs in the background — you can leave this page. {{ \App\Services\LeadGen\Providers\OvertureProvider::ATTRIBUTION }}</div>
            </div>
        </div>
    </form>
    @endif
</div>
@endsection

@section('script')
@if ($ready)
<script>
(function () {
    var form = document.getElementById('lg-search');
    var csrf = form.querySelector('input[name=_token]').value;
    var urls = {
        geocode: @json(route('leadgen.places.geocode', ['client' => $client->slug])),
        categories: @json(route('leadgen.places.categories', ['client' => $client->slug])),
        estimate: @json(route('leadgen.places.estimate', ['client' => $client->slug]))
    };
    var projectId = @json(hashid($project->id));
    var $ = function (id) { return document.getElementById(id); };
    var MAX_RADIUS = @json(\App\Services\LeadGen\Providers\SearchCriteria::MAX_RADIUS_KM);

    function mode() { return form.querySelector('input[name=area_mode]:checked').value; }
    // Only the active mode's inputs are submitted (empty hidden bbox fields
    // would otherwise fail validation on a radius search).
    function applyMode() {
        form.querySelectorAll('[data-mode]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.mode !== mode()); });
        form.querySelectorAll('[data-bbox]').forEach(function (i) { i.disabled = mode() !== 'place'; });
        form.querySelectorAll('[data-radius]').forEach(function (i) { i.disabled = mode() !== 'radius'; });
    }
    form.querySelectorAll('input[name=area_mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
    applyMode();

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    function json(url, body, method) {
        var opts = {method: method || 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}};
        if (body && opts.method !== 'GET') opts.body = JSON.stringify(body);
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { d._ok = r.ok; return d; });
        });
    }

    // Current area as a bbox; radius mode builds the box around the point.
    function bbox() {
        if (mode() === 'radius') {
            var lat = parseFloat(form.lat.value), lng = parseFloat(form.lng.value), km = Math.min(parseFloat(form.radius_km.value) || 0, MAX_RADIUS);
            if (isNaN(lat) || isNaN(lng) || !km) return null;
            var dLat = km / 111.32, dLng = km / (111.32 * Math.max(Math.cos(lat * Math.PI / 180), 0.01));
            return [lng - dLng, lat - dLat, lng + dLng, lat + dLat];
        }
        var b = Array.prototype.map.call(form.querySelectorAll('[data-bbox]'), function (i) { return parseFloat(i.value); });
        return b.some(isNaN) ? null : b;
    }

    // ── place lookup ───────────────────────────────────────────────────
    function findPlace() {
        var q = $('lg-place-q').value.trim(), country = $('lg-country').value;
        if (q.length < 2) return;
        $('lg-place-status').textContent = 'Searching…';
        json(urls.geocode + '?country=' + encodeURIComponent(country) + '&q=' + encodeURIComponent(q) + '&project_id=' + projectId, null, 'GET')
            .then(function (d) {
                if (d.status === 'loading') {
                    $('lg-place-status').textContent = 'Preparing places for this country (first time only, about a minute)…';
                    setTimeout(findPlace, 5000);
                    return;
                }
                if (!d._ok || d.status === 'error' || d.status === 'failed') {
                    $('lg-place-status').textContent = d.message || d.error || 'Lookup failed. Try again.';
                    return;
                }
                $('lg-place-status').textContent = d.matches.length ? 'Pick one:' : 'No match. Try a different spelling or a nearby city.';
                $('lg-place-matches').innerHTML = d.matches.map(function (m, i) {
                    var sub = [m.subtype, m.local_name].filter(Boolean).join(' · ');
                    return '<button type="button" class="tva-lg-match" data-i="' + i + '"' + (m.too_large ? ' disabled title="Too large — pick a city or district"' : '') + '>'
                        + '<b>' + esc(m.name) + '</b> <span class="tva-lg-sub">' + esc(sub) + (m.too_large ? ' · too large to search' : '') + '</span></button>';
                }).join('');
                $('lg-place-matches').querySelectorAll('button:not([disabled])').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var m = d.matches[+btn.dataset.i];
                        form.querySelectorAll('[data-bbox]').forEach(function (inp, k) { inp.value = m.bbox[k]; });
                        $('lg-area-label').value = m.name;
                        $('lg-area-chosen').textContent = 'Area: ' + m.name;
                        $('lg-place-matches').innerHTML = '';
                        $('lg-place-status').textContent = '';
                    });
                });
            });
    }
    $('lg-place-find').addEventListener('click', findPlace);
    $('lg-place-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); findPlace(); } });

    // ── categories ─────────────────────────────────────────────────────
    function loadCategories() {
        var b = bbox();
        if (!b) { $('lg-cats-status').textContent = 'Choose an area first.'; return; }
        $('lg-cats-status').textContent = 'Loading…';
        json(urls.categories, {bbox: b, project_id: projectId}).then(function (d) {
            if (d.status === 'loading') {
                $('lg-cats-status').textContent = 'Loading this area for the first time — usually 1–2 minutes…';
                setTimeout(loadCategories, 6000);
                return;
            }
            if (!d._ok || !d.categories) {
                $('lg-cats-status').textContent = d.message || (d.errors && Object.values(d.errors)[0]) || 'Could not load categories.';
                return;
            }
            var chosen = {};
            form.querySelectorAll('input[name="categories[]"]:checked').forEach(function (c) { chosen[c.value] = true; });
            $('lg-cats-status').textContent = (d.total || 0).toLocaleString() + ' businesses in this area. Pick one or more categories:';
            $('lg-cats').innerHTML = d.categories.map(function (c) {
                var on = chosen[c.key];
                return '<label class="tva-lg-chip' + (on ? ' tva-lg-chip--on' : '') + '" data-name="' + esc(c.key.replace(/_/g, ' ')) + '">'
                    + '<input type="checkbox" name="categories[]" value="' + esc(c.key) + '"' + (on ? ' checked' : '') + '> '
                    + esc(c.key.replace(/_/g, ' ')) + ' <span class="tva-lg-sub">' + c.count + '</span></label>';
            }).join('');
            $('lg-cats-filter').classList.remove('hidden');
        });
    }
    $('lg-cats-load').addEventListener('click', loadCategories);
    $('lg-cats').addEventListener('change', function (e) {
        if (e.target.matches('input[type=checkbox]')) e.target.closest('.tva-lg-chip').classList.toggle('tva-lg-chip--on', e.target.checked);
    });
    $('lg-cats-filter').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        $('lg-cats').querySelectorAll('.tva-lg-chip').forEach(function (c) {
            c.style.display = !q || (c.dataset.name || '').indexOf(q) !== -1 || c.querySelector('input').checked ? '' : 'none';
        });
    });

    // ── estimate ───────────────────────────────────────────────────────
    function payload() {
        var data = {}, fd = new FormData(form);
        fd.forEach(function (v, k) {
            if (k.slice(-2) === '[]') { (data[k.slice(0, -2)] = data[k.slice(0, -2)] || []).push(v); }
            else if (/^bbox\[\d\]$/.test(k)) { (data.bbox = data.bbox || [])[+k[5]] = v; }
            else { data[k] = v; }
        });
        if (mode() !== 'place') delete data.bbox;
        return data;
    }
    $('lg-estimate').addEventListener('click', function estimate() {
        if (!bbox()) { $('lg-estimate-out').textContent = 'Choose an area first.'; return; }
        $('lg-estimate-out').textContent = 'Counting…';
        json(urls.estimate, payload()).then(function (d) {
            if (d.status === 'loading') { $('lg-estimate-out').textContent = 'Loading the area…'; setTimeout(estimate, 6000); return; }
            if (!d._ok) { $('lg-estimate-out').textContent = d.message || (d.errors && Object.values(d.errors)[0][0]) || 'Could not estimate.'; return; }
            $('lg-estimate-out').textContent = '≈ ' + Number(d.estimate).toLocaleString() + ' businesses match';
        });
    });

    form.addEventListener('submit', function (e) {
        if (!bbox()) { e.preventDefault(); $('lg-area-chosen').textContent = 'Choose an area first.'; }
    });
})();
</script>
@endif
@endsection
