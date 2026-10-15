@extends('layouts.master')
@section('content')
@php
    $pf = $prefill ?? [];
    $overture = $sources['overture'] ?? null;
    $ready = $overture && $overture->isConfigured();
    $radiusMode = !empty($pf['center']);
    $maxRadius = \App\Services\LeadGen\Providers\SearchCriteria::MAX_RADIUS_KM;
    $steps = [
        ['Where', 'Choose an area', 'map-pin'],
        ['What', 'Types of business', 'building'],
        ['Must have', 'Contact details', 'filter'],
        ['Review', 'Check and start', 'rocket'],
    ];
@endphp
<div class="content">
    @include('leadgen._header', ['title' => 'Find businesses'])

    @if (!$ready)
        <div class="tva-wz-card text-center">
            <span class="tva-tone tva-tone--amber mx-auto" style="width:48px;height:48px"><i data-lucide="clock"></i></span>
            <div class="tva-wz-title mt-3">Business search isn’t available yet</div>
            <div class="tva-wz-sub">The listings service hasn’t been set up on this server. You can still
                <a class="text-primary font-semibold" href="{{ route('leadgen.imports.create', ['client' => $client->slug, 'project_id' => $project->id]) }}">import your own list</a>.</div>
        </div>
    @else
    <form method="POST" action="{{ route('leadgen.searches.store', ['client' => $client->slug]) }}" id="lg-search" novalidate>
        @csrf
        <input type="hidden" name="project_id" value="{{ hashid($project->id) }}">
        <input type="hidden" name="source" value="overture">
        @if (request('from_id'))<input type="hidden" name="rerun_id" value="{{ hashid((int) request('from_id')) }}">@endif

        {{-- Stepper --}}
        <div class="tva-wz-steps" role="tablist">
            @foreach ($steps as $i => [$label, $sub, $icon])
                @if ($i > 0)<div class="tva-wz-line" data-line="{{ $i }}"></div>@endif
                <button type="button" class="tva-wz-step" data-goto="{{ $i }}" role="tab">
                    <span class="tva-wz-dot"><i data-lucide="{{ $icon }}"></i></span>
                    <span class="tva-wz-label">{{ $i + 1 }}. {{ $label }}<small>{{ $sub }}</small></span>
                </button>
            @endforeach
        </div>

        {{-- 1. Where --}}
        <section class="tva-wz-pane tva-wz-card" data-pane="0">
            <div class="tva-wz-title">Where should we look?</div>
            <div class="tva-wz-sub">Search a city or district by name, or draw a circle around a point. Smaller areas give more focused results.</div>

            <div class="tva-wz-seg mt-4" role="radiogroup">
                <label><input type="radio" name="area_mode" value="place" @checked(!$radiusMode)><i data-lucide="map"></i>City or district</label>
                <label><input type="radio" name="area_mode" value="radius" @checked($radiusMode)><i data-lucide="compass"></i>Around a point</label>
            </div>

            <div data-mode="place" class="{{ $radiusMode ? 'hidden' : '' }}">
                <div class="grid gap-3 mt-4" style="grid-template-columns: minmax(0,220px) minmax(0,1fr)">
                    <div>
                        <label class="text-xs font-semibold">Country</label>
                        <select name="country_code" class="form-select mt-1" id="lg-country">
                            @foreach ($countries as $code => $name)
                                <option value="{{ $code }}" @selected(($pf['country_code'] ?? 'AE') === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold">City or district</label>
                        <div class="flex gap-2 mt-1">
                            <input type="text" id="lg-place-q" class="form-control" placeholder="e.g. Dubai, Jumeirah, Karachi, Lahore" maxlength="80" autocomplete="off">
                            <button type="button" class="tva-btn-prime" id="lg-place-find"><i data-lucide="search"></i><span>Find</span></button>
                        </div>
                    </div>
                </div>
                <div id="lg-place-status" class="tva-lg-hint mt-2"></div>
                <div id="lg-place-load" class="tva-load hidden" aria-live="polite"></div>
                <div id="lg-place-matches" class="tva-wz-matches"></div>
                @foreach ([0, 1, 2, 3] as $i)
                    <input type="hidden" name="bbox[{{ $i }}]" value="{{ $pf['bbox'][$i] ?? '' }}" data-bbox>
                @endforeach
            </div>

            <div data-mode="radius" class="{{ $radiusMode ? '' : 'hidden' }}">
                <div class="grid grid-cols-3 gap-3 mt-4">
                    <div><label class="text-xs font-semibold">Latitude</label><input type="number" step="any" name="lat" class="form-control mt-1" value="{{ $pf['center'][1] ?? '' }}" placeholder="25.0800" data-radius></div>
                    <div><label class="text-xs font-semibold">Longitude</label><input type="number" step="any" name="lng" class="form-control mt-1" value="{{ $pf['center'][0] ?? '' }}" placeholder="55.1400" data-radius></div>
                    <div><label class="text-xs font-semibold">Radius (km)</label><input type="number" step="0.5" min="0.5" max="{{ $maxRadius }}" name="radius_km" class="form-control mt-1" value="{{ $pf['radius_km'] ?? 5 }}" data-radius></div>
                </div>
                <div class="tva-lg-hint mt-2">Tip: in Google Maps, right-click a spot and click the coordinates to copy them.</div>
            </div>

            <input type="hidden" name="area_label" id="lg-area-label" value="{{ $pf['area_label'] ?? '' }}">
            <div id="lg-area-chosen" class="tva-wz-chosen {{ empty($pf['area_label']) ? 'hidden' : '' }}">
                <i data-lucide="check-circle"></i><span>{{ !empty($pf['area_label']) ? 'Area: ' . $pf['area_label'] : '' }}</span>
            </div>
            <div id="lg-step0-error" class="alert alert-warning mt-4 hidden">Choose an area first — search for a city and pick one of the results, or switch to “Around a point”.</div>

            <div class="tva-wz-nav">
                <span class="tva-lg-sub">Step 1 of 4</span>
                <button type="button" class="btn btn-primary ml-auto" data-next>Next: business types <i data-lucide="arrow-right"></i></button>
            </div>
        </section>

        {{-- 2. What --}}
        <section class="tva-wz-pane tva-wz-card" data-pane="1">
            <div class="tva-wz-title">What kind of business?</div>
            <div class="tva-wz-sub">These are the categories that actually exist in your area, with how many businesses each has. Pick one or more — or leave them all empty to include every type.</div>

            <div id="lg-cats-status" class="tva-lg-hint mt-4"></div>
            <div id="lg-cats-load" class="tva-load hidden" aria-live="polite"></div>
            <input type="text" id="lg-cats-filter" class="form-control mt-3 hidden" placeholder="Filter categories, e.g. real estate, salon, clinic…">
            <div id="lg-cats" class="tva-lg-chips mt-3" style="max-height:300px">
                @foreach ((array) ($pf['categories'] ?? []) as $c)
                    <label class="tva-lg-chip tva-lg-chip--on" data-name="{{ str_replace('_', ' ', $c) }}"><input type="checkbox" name="categories[]" value="{{ $c }}" checked> {{ str_replace('_', ' ', $c) }}</label>
                @endforeach
            </div>
            <div class="tva-lg-hint mt-2" id="lg-cats-count"></div>

            <div class="mt-5" style="max-width:520px">
                <label class="text-xs font-semibold">Keywords <span class="tva-lg-sub">(optional)</span></label>
                <input type="text" name="keywords" class="form-control mt-1" maxlength="120" value="{{ $pf['keywords'] ?? '' }}" placeholder="e.g. properties, realty, dental">
                <div class="tva-lg-hint">Matched against business names and categories.</div>
            </div>

            <div class="tva-wz-nav">
                <button type="button" class="btn btn-outline-secondary" data-back><i data-lucide="arrow-left"></i> Back</button>
                <span class="tva-lg-sub">Step 2 of 4</span>
                <button type="button" class="btn btn-primary ml-auto" data-next>Next: contact details <i data-lucide="arrow-right"></i></button>
            </div>
        </section>

        {{-- 3. Must have --}}
        <section class="tva-wz-pane tva-wz-card" data-pane="2">
            <div class="tva-wz-title">Which businesses can you actually reach?</div>
            <div class="tva-wz-sub">Only include businesses that list these. Fewer requirements means more results; you can check websites for missing emails later.</div>

            <div class="tva-wz-toggles mt-4">
                @foreach ([
                    'require_website' => ['A website', 'Needed to check details and personalise', 'globe', 'blue'],
                    'require_phone'   => ['A phone number', 'For calls or opted-in WhatsApp', 'phone', 'emerald'],
                    'require_email'   => ['A listed email', 'For email outreach', 'mail', 'rose'],
                    'require_social'  => ['A social profile', 'Instagram, Facebook, LinkedIn…', 'instagram', 'violet'],
                ] as $k => [$label, $hint, $icon, $tone])
                    <label class="tva-wz-toggle">
                        <input type="checkbox" name="{{ $k }}" value="1" @checked($pf[$k] ?? ($k === 'require_website'))>
                        <span class="tva-tone tva-tone--{{ $tone }}" style="width:38px;height:38px"><i data-lucide="{{ $icon }}"></i></span>
                        <span><b>{{ $label }}</b><small>{{ $hint }}</small></span>
                        <span class="tva-wz-check"></span>
                    </label>
                @endforeach
            </div>

            <div class="grid gap-4 mt-5" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
                <div>
                    <label class="text-xs font-semibold">Listing quality</label>
                    <select name="min_confidence" class="form-select mt-1">
                        @foreach (['0' => 'Any listing', '0.5' => 'Fair or better (recommended)', '0.7' => 'Good or better', '0.9' => 'Only the most reliable'] as $v => $l)
                            <option value="{{ $v }}" @selected((string) ($pf['min_confidence'] ?? '0.5') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                    <div class="tva-lg-hint">How sure the source is that the listing is a real, current business.</div>
                </div>
                <div class="flex flex-col gap-2 pt-5">
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="exclude_closed" value="0"><input type="checkbox" name="exclude_closed" value="1" class="form-check-input" @checked($pf['exclude_closed'] ?? true)> Skip businesses listed as closed</label>
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="exclude_existing_crm" value="0"><input type="checkbox" name="exclude_existing_crm" value="1" class="form-check-input" @checked($pf['exclude_existing_crm'] ?? true)> Mark businesses already in my CRM</label>
                </div>
            </div>

            <div class="tva-wz-nav">
                <button type="button" class="btn btn-outline-secondary" data-back><i data-lucide="arrow-left"></i> Back</button>
                <span class="tva-lg-sub">Step 3 of 4</span>
                <button type="button" class="btn btn-primary ml-auto" data-next>Next: review <i data-lucide="arrow-right"></i></button>
            </div>
        </section>

        {{-- 4. Review --}}
        <section class="tva-wz-pane tva-wz-card" data-pane="3">
            <div class="tva-wz-title">Ready to search</div>
            <div class="tva-wz-sub">Check your choices. The search runs in the background — you can leave this page and come back.</div>

            <dl class="tva-wz-sum mt-4">
                <div><dt>Area</dt><dd id="sum-area">—</dd></div>
                <div><dt>Business types</dt><dd id="sum-cats">—</dd></div>
                <div><dt>Must have</dt><dd id="sum-req">—</dd></div>
                <div><dt>Quality</dt><dd id="sum-quality">—</dd></div>
            </dl>

            <div class="tva-wz-estimate">
                <span class="tva-tone tva-tone--blue" style="width:40px;height:40px"><i data-lucide="bar-chart-2"></i></span>
                <div><b id="lg-estimate-n">…</b><div class="tva-lg-sub" id="lg-estimate-out">Counting matching businesses…</div></div>
            </div>

            <div class="grid gap-4 mt-5" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr))">
                <div>
                    <label class="text-xs font-semibold">Name <span class="tva-lg-sub">(optional)</span></label>
                    <input type="text" name="name" class="form-control mt-1" maxlength="160" value="{{ $prefillName }}" placeholder="e.g. Dubai agencies – October">
                </div>
                <div>
                    <label class="text-xs font-semibold">Stop after</label>
                    <select name="max_results" class="form-select mt-1">
                        @foreach ([100, 250, 500, 1000, 2500, 5000] as $n)
                            <option value="{{ $n }}" @selected((int) ($pf['max_results'] ?? 250) === $n)>{{ number_format($n) }} businesses</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm mt-4"><input type="checkbox" name="saved" value="1" class="form-check-input"> Save this search so I can run it again later</label>

            <div class="tva-wz-nav">
                <button type="button" class="btn btn-outline-secondary" data-back><i data-lucide="arrow-left"></i> Back</button>
                <span class="tva-lg-sub hidden sm:inline">{{ \App\Services\LeadGen\Providers\OvertureProvider::ATTRIBUTION }}</span>
                <button class="btn btn-primary ml-auto" id="lg-submit"><i data-lucide="rocket"></i> Start search</button>
            </div>
        </section>
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
    var MAX_RADIUS = @json($maxRadius);
    var $ = function (id) { return document.getElementById(id); };
    var step = 0, catsForBox = null;

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    // This bundle's createIcons() throws without an icon set; pass it explicitly (as the Assistant page does).
    function icons() { try { var L = window.lucide; if (L && L.createIcons) L.createIcons({icons: (L.icons || {}), nameAttr: 'data-lucide'}); } catch (_) {} }
    function json(url, body, method) {
        var opts = {method: method || 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}};
        if (body && opts.method !== 'GET') opts.body = JSON.stringify(body);
        return fetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (d) { d._ok = r.ok; d._status = r.status; return d; });
        }).catch(function () { return {_ok: false, message: 'Couldn’t reach the server. Check your connection and try again.'}; });
    }
    function errorText(d, fallback) {
        if (d.message) return d.message;
        if (d.errors) { var f = Object.values(d.errors)[0]; return Array.isArray(f) ? f[0] : f; }
        return fallback;
    }

    // ── loader: orb + moving bar + timer + rotating tips ──────────────
    var ICON = {
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
        store: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9h18l-2-5H5L3 9Z"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/></svg>',
        chart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>'
    };
    var TIPS = [
        'Tip: tick “A website” in step 3 — it lets us check contact details for you later.',
        'Smaller areas give more focused results — try a district like Jumeirah or DIFC.',
        'Every contact detail stays marked “unverified” until we confirm it.',
        'After the search, select prospects and choose “Check their websites” to find emails.',
        'AI scores show the facts they used, so you can see why a business fits.',
        'Save a search in step 4 and re-run it next month in one click.',
        'This step happens once per country or area — after that it is instant.'
    ];
    var loaders = {};
    function startLoader(id, opts) {
        stopLoader(id);
        var el = $(id), started = Date.now(), tip = Math.floor(Math.random() * TIPS.length);
        el.className = 'tva-load' + (opts.small ? ' tva-load--sm' : '');
        el.innerHTML = '<div class="tva-load__orb"><div class="tva-load__core">' + (ICON[opts.icon] || ICON.pin) + '</div></div>'
            + '<div class="tva-load__t">' + esc(opts.title) + '</div>'
            + (opts.sub ? '<div class="tva-load__s">' + esc(opts.sub) + '</div>' : '')
            + '<div class="tva-load__bar"></div>'
            + '<div class="tva-load__meta"><span class="tva-load__time">0:00</span><span>elapsed</span></div>'
            + (opts.tips === false ? '' : '<div class="tva-load__tip">' + esc(TIPS[tip]) + '</div>');
        var timeEl = el.querySelector('.tva-load__time'), tipEl = el.querySelector('.tva-load__tip');
        loaders[id] = {
            clock: setInterval(function () {
                var s = Math.floor((Date.now() - (loaders[id] ? loaders[id].started : started)) / 1000);
                timeEl.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
            }, 1000),
            tips: tipEl ? setInterval(function () {
                tipEl.style.opacity = 0;
                setTimeout(function () { tip = (tip + 1) % TIPS.length; tipEl.textContent = TIPS[tip]; tipEl.style.opacity = 1; }, 350);
            }, 5000) : null,
            started: started
        };
    }
    function stopLoader(id) {
        var l = loaders[id];
        if (l) { clearInterval(l.clock); if (l.tips) clearInterval(l.tips); delete loaders[id]; }
        var el = $(id);
        if (el) { el.className = 'tva-load hidden'; el.innerHTML = ''; }
    }
    function isLoading(id) { return !!loaders[id]; }
    function startLoaderOnce(id, opts) {
        var l = loaders[id];
        if (l && l.full) return;
        var started = l ? l.started : Date.now();
        startLoader(id, opts);
        loaders[id].full = true;
        loaders[id].started = started;
    }
    function setBusy(busy) {
        var btn = $('lg-place-find'), input = $('lg-place-q');
        btn.disabled = busy;
        input.readOnly = busy;
        input.classList.toggle('tva-input-busy', busy);
        btn.innerHTML = busy ? '<span class="tva-spin"></span><span>Finding…</span>' : '<i data-lucide="search"></i><span>Find</span>';
        if (!busy) icons();
    }

    // ── area mode ──────────────────────────────────────────────────────
    function mode() { return form.querySelector('input[name=area_mode]:checked').value; }
    function applyMode() {
        form.querySelectorAll('[data-mode]').forEach(function (el) { el.classList.toggle('hidden', el.dataset.mode !== mode()); });
        form.querySelectorAll('[data-bbox]').forEach(function (i) { i.disabled = mode() !== 'place'; });
        form.querySelectorAll('[data-radius]').forEach(function (i) { i.disabled = mode() !== 'radius'; });
        updateChosen();
    }
    form.querySelectorAll('input[name=area_mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
    form.querySelectorAll('[data-radius]').forEach(function (i) { i.addEventListener('input', updateChosen); });

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
    function areaLabel() {
        if (mode() === 'radius') {
            var b = bbox();
            return b ? (form.radius_km.value + ' km around ' + (+form.lat.value).toFixed(4) + ', ' + (+form.lng.value).toFixed(4)) : '';
        }
        return $('lg-area-label').value;
    }
    function updateChosen() {
        var label = bbox() ? areaLabel() : '';
        $('lg-area-chosen').classList.toggle('hidden', !label);
        $('lg-area-chosen').querySelector('span').textContent = label ? 'Area: ' + label : '';
        if (label) $('lg-step0-error').classList.add('hidden');
    }

    // ── place lookup ───────────────────────────────────────────────────
    function findPlace() {
        var q = $('lg-place-q').value.trim(), country = $('lg-country').value;
        if (q.length < 2) { $('lg-place-status').textContent = 'Type at least 2 letters of a city or district.'; return; }
        $('lg-place-status').textContent = '';
        $('lg-place-matches').innerHTML = '';
        setBusy(true);
        json(urls.geocode + '?country=' + encodeURIComponent(country) + '&q=' + encodeURIComponent(q) + '&project_id=' + projectId, null, 'GET')
            .then(function (d) {
                if (d.status === 'loading') {
                    if (!isLoading('lg-place-load')) {
                        var cname = $('lg-country').options[$('lg-country').selectedIndex].text;
                        startLoader('lg-place-load', {icon: 'pin', title: 'Preparing places in ' + cname,
                            sub: 'We are loading this country’s cities and districts. First time only — about a minute. After this, lookups are instant.'});
                    }
                    setTimeout(findPlace, 5000);
                    return;
                }
                stopLoader('lg-place-load');
                setBusy(false);
                if (!d._ok || d.status === 'error' || d.status === 'failed') {
                    $('lg-place-status').textContent = errorText(d, d.error || 'Lookup failed. Try again.');
                    return;
                }
                $('lg-place-status').textContent = d.matches.length ? 'Pick the area you mean:' : 'No match. Try another spelling or a nearby city.';
                $('lg-place-matches').innerHTML = d.matches.map(function (m, i) {
                    var sub = [m.subtype, m.local_name].filter(Boolean).join(' · ');
                    return '<button type="button" class="tva-wz-match" data-i="' + i + '"' + (m.too_large ? ' disabled title="Too large to search — pick a city or district inside it"' : '') + '>'
                        + '<span class="tva-tone tva-tone--blue" style="width:32px;height:32px"><i data-lucide="map-pin"></i></span>'
                        + '<span><b>' + esc(m.name) + '</b><span>' + esc(sub) + (m.too_large ? ' · too large' : '') + '</span></span></button>';
                }).join('');
                icons();
                $('lg-place-matches').querySelectorAll('button:not([disabled])').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var m = d.matches[+btn.dataset.i];
                        form.querySelectorAll('[data-bbox]').forEach(function (inp, k) { inp.value = m.bbox[k]; });
                        $('lg-area-label').value = m.name;
                        $('lg-place-matches').innerHTML = '';
                        $('lg-place-status').textContent = '';
                        updateChosen();
                    });
                });
            });
    }
    $('lg-place-find').addEventListener('click', findPlace);
    $('lg-place-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); findPlace(); } });

    // ── categories (loaded when step 2 opens) ─────────────────────────
    function selectedCats() {
        return Array.prototype.map.call(form.querySelectorAll('input[name="categories[]"]:checked'), function (c) { return c.value; });
    }
    function catCount() {
        var n = selectedCats().length;
        $('lg-cats-count').textContent = n ? n + ' selected' : 'None selected — every type of business will be included.';
    }
    function loadCategories() {
        var b = bbox();
        if (!b) return;
        var key = b.join(',');
        if (catsForBox === key) return;
        $('lg-cats-status').textContent = '';
        if (!isLoading('lg-cats-load')) {
            startLoader('lg-cats-load', {icon: 'store', small: true, tips: false, title: 'Finding the business types in ' + (areaLabel() || 'this area')});
        }
        json(urls.categories, {bbox: b, project_id: projectId}).then(function (d) {
            if (d.status === 'loading') {
                startLoaderOnce('lg-cats-load', {icon: 'store', title: 'Loading every business in ' + (areaLabel() || 'this area'),
                    sub: 'First time for this area — usually 1–2 minutes. Feel free to wait here; we’ll show the categories as soon as they’re ready.'});
                setTimeout(loadCategories, 6000);
                return;
            }
            stopLoader('lg-cats-load');
            if (!d._ok || !d.categories) { stopLoader('lg-cats-load'); $('lg-cats-status').textContent = errorText(d, 'Could not load categories.'); return; }
            catsForBox = key;
            var chosen = {};
            selectedCats().forEach(function (c) { chosen[c] = true; });
            $('lg-cats-status').textContent = (d.total || 0).toLocaleString() + ' businesses in this area.';
            $('lg-cats').innerHTML = d.categories.map(function (c) {
                var on = chosen[c.key], name = c.key.replace(/_/g, ' ');
                return '<label class="tva-lg-chip' + (on ? ' tva-lg-chip--on' : '') + '" data-name="' + esc(name) + '">'
                    + '<input type="checkbox" name="categories[]" value="' + esc(c.key) + '"' + (on ? ' checked' : '') + '> '
                    + esc(name) + ' <span class="tva-lg-sub">' + c.count + '</span></label>';
            }).join('');
            $('lg-cats-filter').classList.remove('hidden');
            catCount();
        });
    }
    $('lg-cats').addEventListener('change', function (e) {
        if (e.target.matches('input[type=checkbox]')) { e.target.closest('.tva-lg-chip').classList.toggle('tva-lg-chip--on', e.target.checked); catCount(); }
    });
    $('lg-cats-filter').addEventListener('input', function () {
        var q = this.value.toLowerCase();
        $('lg-cats').querySelectorAll('.tva-lg-chip').forEach(function (c) {
            c.style.display = !q || (c.dataset.name || '').indexOf(q) !== -1 || c.querySelector('input').checked ? '' : 'none';
        });
    });

    // ── review + estimate ──────────────────────────────────────────────
    function payload() {
        var data = {}, fd = new FormData(form);
        fd.forEach(function (v, k) {
            if (k.slice(-2) === '[]') { (data[k.slice(0, -2)] = data[k.slice(0, -2)] || []).push(v); }
            else if (/^bbox\[\d\]$/.test(k)) { (data.bbox = data.bbox || [])[+k[5]] = v; }
            else { data[k] = v; }
        });
        if (mode() !== 'place') delete data.bbox;
        if (mode() === 'radius') data.area_label = areaLabel();
        return data;
    }
    function fillSummary() {
        $('sum-area').textContent = areaLabel() || '—';
        var cats = selectedCats().map(function (c) { return c.replace(/_/g, ' '); });
        $('sum-cats').textContent = cats.length ? cats.join(', ') : 'All types';
        var req = [];
        [['require_website', 'website'], ['require_phone', 'phone'], ['require_email', 'email'], ['require_social', 'social profile']].forEach(function (r) {
            if (form[r[0]].checked) req.push(r[1]);
        });
        var kw = form.keywords.value.trim();
        $('sum-req').textContent = (req.length ? req.join(', ') : 'Nothing required') + (kw ? ' · keywords: ' + kw : '');
        $('sum-quality').textContent = form.min_confidence.options[form.min_confidence.selectedIndex].text;
    }
    function estimate() {
        var box = document.querySelector('.tva-wz-estimate');
        box.classList.add('is-busy');
        $('lg-estimate-n').textContent = 'Counting…';
        $('lg-estimate-out').textContent = 'Checking how many businesses match your choices…';
        json(urls.estimate, payload()).then(function (d) {
            if (d.status === 'loading') { $('lg-estimate-out').textContent = 'Still loading this area — usually 1–2 minutes the first time…'; setTimeout(estimate, 6000); return; }
            box.classList.remove('is-busy');
            if (!d._ok) { $('lg-estimate-n').textContent = '—'; $('lg-estimate-out').textContent = errorText(d, 'Could not estimate.'); return; }
            $('lg-estimate-n').textContent = Number(d.estimate).toLocaleString();
            $('lg-estimate-out').textContent = d.estimate ? 'businesses match. The search adds them to your Prospects.' : 'No businesses match — go back and loosen the filters.';
        });
    }

    // ── steps ──────────────────────────────────────────────────────────
    function show(n) {
        step = n;
        form.querySelectorAll('[data-pane]').forEach(function (p) { p.classList.toggle('tva-wz-pane--on', +p.dataset.pane === n); });
        form.querySelectorAll('[data-goto]').forEach(function (b) {
            var i = +b.dataset.goto;
            b.classList.toggle('tva-wz-step--on', i === n);
            b.classList.toggle('tva-wz-step--done', i < n);
            b.querySelector('.tva-wz-dot').innerHTML = i < n ? '<i data-lucide="check"></i>' : b.querySelector('.tva-wz-dot').dataset.icon;
        });
        form.querySelectorAll('[data-line]').forEach(function (l) { l.classList.toggle('tva-wz-line--done', +l.dataset.line <= n); });
        icons();
        if (n === 1) { loadCategories(); catCount(); }
        if (n === 3) { fillSummary(); estimate(); }
        window.scrollTo({top: form.getBoundingClientRect().top + window.scrollY - 90, behavior: 'smooth'});
    }
    function canLeave(from) {
        if (from === 0 && !bbox()) { $('lg-step0-error').classList.remove('hidden'); return false; }
        return true;
    }
    form.querySelectorAll('[data-goto]').forEach(function (b) {
        b.querySelector('.tva-wz-dot').dataset.icon = b.querySelector('.tva-wz-dot').innerHTML;
        b.addEventListener('click', function () {
            var to = +b.dataset.goto;
            if (to > step && !canLeave(step)) return;
            if (to > 0 && !bbox()) { show(0); $('lg-step0-error').classList.remove('hidden'); return; }
            show(to);
        });
    });
    form.querySelectorAll('[data-next]').forEach(function (b) { b.addEventListener('click', function () { if (canLeave(step)) show(step + 1); }); });
    form.querySelectorAll('[data-back]').forEach(function (b) { b.addEventListener('click', function () { show(step - 1); }); });
    form.addEventListener('submit', function (e) {
        if (!bbox()) { e.preventDefault(); show(0); $('lg-step0-error').classList.remove('hidden'); return; }
        if (mode() === 'radius') $('lg-area-label').value = areaLabel();
        $('lg-submit').disabled = true;
        $('lg-submit').innerHTML = '<span class="tva-spin"></span> Starting…';
    });

    applyMode();
    show({{ $errors->any() ? 3 : 0 }});
})();
</script>
@endif
@endsection
