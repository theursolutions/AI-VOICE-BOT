@extends('layouts.master')

@section('content')
<style>
    .tva-voice-card {
        background:#fff; border:1px solid #e2e8f0;
        border-radius: 12px; padding: 16px 18px;
        display:flex; align-items:center; gap:14px;
        transition: all .15s;
    }
    .tva-voice-card.is-default {
        border-color:#34d399; background: linear-gradient(135deg,#f0fdf4 0%,#ecfdf5 100%);
        box-shadow: 0 4px 14px -4px rgba(16,185,129,.25);
    }
    .tva-voice-icon {
        width:48px; height:48px; border-radius: 12px;
        background: var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-voice-name { font-size:15px; font-weight:600; color:#0f172a; }
    .tva-voice-meta { font-size:12px; color:#64748b; margin-top:2px; display:flex; flex-wrap:wrap; gap:10px; }
    .tva-chip { font-size:10px; padding:3px 9px; border-radius:999px; background:#e2e8f0; color:#475569; font-weight:600; }
    .tva-chip--ok { background:#dcfce7; color:#15803d; }
    .tva-chip--default { background:#d1fae5; color:#047857; }

    .tva-voice-play {
        background: linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff;
        border: none; cursor: pointer;
        transition: transform .15s, box-shadow .15s;
        box-shadow: 0 4px 10px -3px rgba(99,102,241,.5);
    }
    .tva-voice-play:hover { transform: scale(1.06); }
    .tva-voice-play.is-playing { background: linear-gradient(135deg,#ef4444,#f59e0b); }
    .tva-voice-play.is-loading { opacity: .6; cursor: wait; }

    .tva-form-label { font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; font-weight:600; margin-bottom:6px; display:block; }
    .tva-upload-zone {
        display:flex; flex-direction:column; align-items:center; justify-content:center;
        border: 2px dashed #cbd5e1; border-radius: 12px;
        padding: 32px 16px; text-align: center;
        background: #f8fafc;
        cursor: pointer; transition: all .15s;
        min-height: 180px;
    }
    .tva-upload-zone:hover { border-color:#6366f1; background:#eef2ff; }
    .tva-upload-zone.is-dragging { border-color:#6366f1; background:#e0e7ff; transform: scale(1.01); }
    .tva-upload-zone__icon { width:40px; height:40px; color:#6366f1; margin-bottom:10px; }
    .tva-upload-zone__title { font-size:13px; font-weight:600; color:#0f172a; }
    .tva-upload-zone__hint  { font-size:11px; color:#94a3b8; margin-top:4px; }
    .tva-upload-zone__file  { font-size:12px; color:#0f172a; font-weight:600; margin-top:8px; padding:4px 12px; background:#dbeafe; color:#1d4ed8; border-radius:999px; display:none; }
    .tva-upload-zone__file.is-set { display:inline-block; }

    /* ── 6/6 column split for upload + list ──────────────────────── */
    .tva-cols { display:grid; gap:24px; grid-template-columns: 1fr; }
    @media (min-width: 900px) { .tva-cols { grid-template-columns: 1fr 1fr; align-items: start; } }

    html.dark .tva-voice-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-voice-card.is-default { background: linear-gradient(135deg,#052e16 0%,#064e3b 100%); border-color:#10b981; }
    html.dark .tva-voice-name { color:#f1f5f9; }
    html.dark .tva-voice-meta { color:#94a3b8; }
    html.dark .tva-chip { background:#334155; color:#cbd5e1; }
    html.dark .tva-chip--default { background:#064e3b; color:#6ee7b7; }
    html.dark .tva-upload-zone { background:#0f172a; border-color:#334155; }
    html.dark .tva-upload-zone:hover { background:#1e293b; border-color:#6366f1; }
    html.dark .tva-form-label { color:#94a3b8; }
</style>

<div class="content">
    <div class="intro-y flex items-center mt-6 mb-4">
        <h2 class="text-lg font-medium mr-auto">
            Voices — {{ $client?->name }}
        </h2>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    <div class="intro-y box p-3 mb-5">
        <form method="GET">
            <label class="tva-form-label">Project</label>
            <select name="project_id" class="form-select w-full md:w-1/3" onchange="this.form.submit()">
                @foreach ($projects as $p)
                    <option value="{{ $p->id }}" @selected((int)$projectId === (int)$p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="tva-cols">
        {{-- ── Upload form ────────────────────────────────────────────── --}}
        <div>
            <div class="intro-y box p-5">
                <h3 class="font-medium text-base mb-4 flex items-center">
                    <i data-lucide="upload-cloud" class="w-4 h-4 mr-2"></i>
                    Add a voice
                </h3>

                <form method="POST" action="{{ route('voices.store', ['client' => $client->slug]) }}" enctype="multipart/form-data" id="tvaVoiceForm">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $projectId }}">

                    <div class="mb-3">
                        <label class="tva-form-label">Voice name</label>
                        <input type="text" name="name" required maxlength="120"
                               class="form-control w-full"
                               placeholder="e.g. Sarah (female, friendly)">
                    </div>

                    <div class="mb-3">
                        <label class="tva-form-label">Language</label>
                        <select name="language" class="form-select w-full" required>
                            @foreach ($languages as $code => $label)
                                <option value="{{ $code }}" @selected($code === 'en')>{{ $label }} ({{ $code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-4">
                        <span class="tva-form-label">Reference audio (6–15 s, mono, 24 kHz preferred)</span>
                        <label for="tvaFileInput" class="tva-upload-zone" id="tvaUploadZone">
                            <i data-lucide="mic" class="tva-upload-zone__icon"></i>
                            <div class="tva-upload-zone__title">Drop a WAV here or click to browse</div>
                            <div class="tva-upload-zone__hint">.wav, .mp3 or .m4a · up to 10 MB</div>
                            <div class="tva-upload-zone__file" id="tvaFileLabel"></div>
                        </label>
                        <input type="file" name="speaker" id="tvaFileInput" accept=".wav,.mp3,.m4a" class="hidden" style="display:none;" required>
                    </div>

                    <button class="btn btn-primary w-full shadow-md">
                        <i data-lucide="save" class="w-4 h-4 mr-2 inline"></i> Upload voice
                    </button>

                    <p class="text-xs text-slate-500 mt-3">
                        <i data-lucide="info" class="w-3 h-3 inline -mt-0.5"></i>
                        For best results record yourself saying 8–10 sentences in a quiet room. Avoid background music.
                    </p>
                </form>
            </div>
        </div>

        {{-- ── Voice list ─────────────────────────────────────────────── --}}
        <div>
            <div class="intro-y box p-5">
                <h3 class="font-medium text-base mb-4 flex items-center">
                    <i data-lucide="library" class="w-4 h-4 mr-2"></i>
                    Available voices
                    <span class="ml-auto text-xs text-slate-500">{{ $voices->count() }} total</span>
                </h3>

                @forelse ($voices as $voice)
                    @php
                        $meta = $voice->metadata ?? [];
                        $isDefault = !empty($meta['is_default']);
                        $langLabel = $languages[$voice->language] ?? strtoupper($voice->language);
                    @endphp
                    <div class="tva-voice-card {{ $isDefault ? 'is-default' : '' }} mb-3">
                        <div class="tva-voice-icon">
                            <i data-lucide="mic" class="w-5 h-5"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="tva-voice-name">{{ $voice->name }}</div>
                            <div class="tva-voice-meta">
                                <span class="tva-chip">{{ $langLabel }}</span>
                                <span class="tva-chip {{ $voice->status === 'ready' ? 'tva-chip--ok' : '' }}">{{ $voice->status }}</span>
                                @if ($isDefault) <span class="tva-chip tva-chip--default">DEFAULT</span> @endif
                                <span style="opacity:.7;">#{{ $voice->id }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($voice->status === 'ready')
                                <button type="button"
                                        class="tva-voice-play inline-flex items-center justify-center w-9 h-9 rounded-full"
                                        data-audio-url="{{ route('voices.audio', ['client' => $client->slug, 'id' => $voice->id]) }}?project_id={{ $projectId }}"
                                        title="Preview voice">
                                    <i data-lucide="play" class="w-4 h-4"></i>
                                </button>
                            @endif
                            @if (!$isDefault)
                                <form method="POST" action="{{ route('voices.default', ['client' => $client->slug, 'id' => $voice->id]) }}">
                                    @csrf
                                    <input type="hidden" name="project_id" value="{{ $projectId }}">
                                    <button class="text-primary inline-flex items-center justify-center w-8 h-8 rounded hover:bg-primary/10" title="Make default">
                                        <i data-lucide="star" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('voices.destroy', ['client' => $client->slug, 'id' => $voice->id]) }}"
                                  onsubmit="return confirm('Remove this voice?');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="project_id" value="{{ $projectId }}">
                                <button class="text-danger inline-flex items-center justify-center w-8 h-8 rounded hover:bg-danger/10" title="Remove">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-12 text-slate-400">
                        <i data-lucide="mic-off" class="w-10 h-10 inline mb-2"></i>
                        <div>No voices uploaded yet.</div>
                        <div class="text-xs mt-1">Upload a 10-second reference WAV on the left to clone a voice.</div>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }

    // Voice preview — one shared <audio> element so starting a new
    // voice automatically stops any previous one. We drive the button
    // state off audio EVENTS (playing/pause/ended/error), not the
    // play() promise, because that promise can stall silently if the
    // server hangs or the codec fails.
    (function () {
        var audio = new Audio();
        audio.preload = 'auto';
        var activeBtn = null;

        function setIcon(btn, name) {
            btn.innerHTML = '<i data-lucide="' + name + '" class="w-4 h-4"></i>';
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        }
        function reset(btn, iconName) {
            if (!btn) return;
            btn.classList.remove('is-playing', 'is-loading');
            setIcon(btn, iconName || 'play');
        }

        audio.addEventListener('playing', function () {
            if (!activeBtn) return;
            activeBtn.classList.remove('is-loading');
            activeBtn.classList.add('is-playing');
            setIcon(activeBtn, 'pause');
        });
        audio.addEventListener('pause', function () {
            if (!activeBtn) return;
            // ignore the implicit pause that fires when ended sets currentTime
            if (audio.ended) return;
            activeBtn.classList.remove('is-loading', 'is-playing');
            setIcon(activeBtn, 'play');
        });
        audio.addEventListener('ended', function () {
            reset(activeBtn, 'play');
            activeBtn = null;
        });
        audio.addEventListener('error', function () {
            console.warn('[tva] voice preview failed:',
                audio.error ? audio.error.code : 'unknown',
                'src=', audio.src);
            if (activeBtn) {
                reset(activeBtn, 'alert-triangle');
                activeBtn.title = 'Could not load audio (check server console).';
            }
            activeBtn = null;
        });

        document.querySelectorAll('.tva-voice-play').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-audio-url');
                if (!url) return;

                // Same button → toggle play/pause.
                if (activeBtn === btn) {
                    if (audio.paused) audio.play().catch(function () {});
                    else              audio.pause();
                    return;
                }

                // New button → stop previous, swap source, start.
                reset(activeBtn, 'play');
                activeBtn = btn;
                btn.classList.add('is-loading');
                setIcon(btn, 'loader-2');
                // Cache-buster avoids stale 304 quirks on some hosts.
                audio.src = url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
                // play() may reject (user gesture, codec, etc) — surface as error.
                var p = audio.play();
                if (p && typeof p.then === 'function') {
                    p.catch(function (err) {
                        console.warn('[tva] audio.play() rejected:', err);
                        reset(btn, 'alert-triangle');
                        activeBtn = null;
                    });
                }
            });
        });
    })();

    // Upload-zone affordances
    (function () {
        var zone = document.getElementById('tvaUploadZone');
        var input = document.getElementById('tvaFileInput');
        var label = document.getElementById('tvaFileLabel');
        if (!zone || !input) return;

        function showFile(name) {
            label.textContent = name || '';
            label.classList.toggle('is-set', !!name);
        }
        input.addEventListener('change', function () {
            showFile(input.files[0] ? input.files[0].name : '');
        });
        ['dragenter','dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-dragging'); });
        });
        ['dragleave','drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-dragging'); });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                input.files = e.dataTransfer.files;
                showFile(e.dataTransfer.files[0].name);
            }
        });
    })();
</script>
@endsection
