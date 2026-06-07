@extends('layouts.master')

@section('content')
<style>
    /* ── Page chrome ───────────────────────────────────────────────── */
    .tva-bs-page  { padding-bottom: 100px; }

    .tva-bs-hero {
        background: var(--tva-gradient);
        color: #fff;
        border-radius: 14px;
        padding: 22px 26px;
        margin-bottom: 22px;
        box-shadow: 0 10px 30px -10px rgba(0,0,0,.35);
        display: flex; align-items: center; gap: 18px;
    }
    .tva-bs-hero__icon {
        width: 56px; height: 56px; border-radius: 14px;
        background: rgba(255,255,255,.18); color: #fff;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0; font-size: 28px;
        border: 2px solid rgba(255,255,255,.3);
    }
    .tva-bs-hero__title { font-size: 20px; font-weight: 700; }
    .tva-bs-hero__sub   { font-size: 13px; opacity: .9; margin-top: 4px; }

    /* ── Card primitive ────────────────────────────────────────────── */
    .tva-bs-card {
        background:#fff; border:1px solid #e2e8f0; border-radius:14px;
        padding: 22px 24px;
        margin-bottom: 18px;
    }
    .tva-bs-card__head {
        display:flex; align-items:center; gap:10px;
        margin-bottom: 18px; padding-bottom: 14px;
        border-bottom: 1px solid #e2e8f0;
    }
    .tva-bs-card__icon {
        width: 36px; height: 36px; border-radius: 10px;
        background: #eef2ff; color: #6366f1;
        display:flex; align-items:center; justify-content:center;
        flex-shrink:0;
    }
    .tva-bs-card__title    { font-size:15px; font-weight:600; color:#0f172a; line-height: 1.2; }
    .tva-bs-card__subtitle { font-size:12px; color:#64748b; margin-top: 2px; }

    /* ── Provider grid ─────────────────────────────────────────────── */
    .tva-providers { display:grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
    @media (min-width: 768px) { .tva-providers { grid-template-columns: repeat(4, 1fr); } }

    .tva-prov-card {
        position: relative;
        background:#fff; border:2px solid #e2e8f0; border-radius:12px;
        padding: 16px 14px;
        text-align: center;
        cursor: pointer;
        transition: all .15s;
    }
    .tva-prov-card:hover { border-color:#a5b4fc; background:#fafbff; }
    .tva-prov-card.is-selected {
        border-color: var(--tva-primary, #6366f1);
        background: linear-gradient(135deg,#eef2ff,#faf5ff);
        box-shadow: 0 6px 18px -6px rgba(99,102,241,.35);
    }
    .tva-prov-card__logo {
        width: 44px; height: 44px; border-radius: 10px;
        margin: 0 auto 8px;
        display:flex; align-items:center; justify-content:center;
        font-size: 22px; font-weight: 700;
    }
    .tva-prov-card__name { font-size:13px; font-weight:600; color:#0f172a; }
    .tva-prov-card__tag  { font-size:10px; color:#64748b; margin-top:2px; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
    .tva-prov-card__check {
        position: absolute; top: 8px; right: 8px;
        width: 18px; height: 18px; border-radius: 50%;
        background:#10b981; color:#fff;
        display:none; align-items:center; justify-content:center;
        font-size: 10px;
    }
    .tva-prov-card.is-selected .tva-prov-card__check { display:flex; }

    .tva-prov-logo--groq      { background:#fff5d1; color:#92400e; border:1px solid #fde68a; }
    .tva-prov-logo--anthropic { background:#ffedd5; color:#9a3412; border:1px solid #fed7aa; }
    .tva-prov-logo--gemini    { background:#dbeafe; color:#1e40af; border:1px solid #93c5fd; }
    .tva-prov-logo--ollama    { background:#dcfce7; color:#166534; border:1px solid #86efac; }

    /* ── Field group ───────────────────────────────────────────────── */
    .tva-bs-field { margin-bottom: 14px; }
    .tva-bs-label {
        font-size:11px; color:#64748b; text-transform:uppercase;
        letter-spacing:.05em; font-weight:600; margin-bottom:6px; display:block;
    }
    .tva-bs-help { font-size:11px; color:#94a3b8; margin-top:5px; line-height: 1.45; }
    .tva-bs-help code { background:#f1f5f9; color:#475569; padding:1px 6px; border-radius:4px; font-size:11px; }

    /* ── Device pill row ───────────────────────────────────────────── */
    .tva-pill-row { display:grid; grid-template-columns: repeat(2, 1fr); gap:10px; }
    .tva-pill {
        background:#f8fafc; border:2px solid #e2e8f0; border-radius:10px;
        padding: 14px 12px; text-align:center; cursor:pointer;
        transition: all .15s; font-size:13px; font-weight:600; color:#334155;
    }
    .tva-pill:hover { background:#f1f5f9; }
    .tva-pill.is-selected {
        background:#eef2ff; border-color: var(--tva-primary, #6366f1);
        color:#3730a3; box-shadow: 0 4px 12px -4px rgba(99,102,241,.3);
    }
    .tva-pill__icon { font-size: 20px; margin-bottom: 4px; opacity: .7; }
    .tva-pill.is-selected .tva-pill__icon { opacity: 1; }

    /* ── Toggle row ────────────────────────────────────────────────── */
    .tva-toggle {
        display:flex; align-items:center; gap:14px;
        padding: 14px 16px; background:#f8fafc;
        border:1px solid #e2e8f0; border-radius:10px;
    }
    .tva-toggle__main  { flex:1; }
    .tva-toggle__label { font-size:13px; font-weight:600; color:#0f172a; }
    .tva-toggle__hint  { font-size:11px; color:#64748b; margin-top:2px; line-height: 1.45; }
    .tva-toggle__sw {
        width:46px; height:26px; appearance:none;
        background:#cbd5e1; border-radius:999px; cursor:pointer;
        position:relative; transition: background .15s; flex-shrink:0;
    }
    .tva-toggle__sw::before {
        content:''; position:absolute; top:3px; left:3px;
        width:20px; height:20px; background:#fff; border-radius:50%;
        transition: left .15s; box-shadow: 0 1px 3px rgba(0,0,0,.25);
    }
    .tva-toggle__sw:checked { background:#10b981; }
    .tva-toggle__sw:checked::before { left:23px; }

    /* ── Sticky save bar ───────────────────────────────────────────── */
    .tva-bs-savebar {
        position: sticky; bottom: 0; z-index: 10;
        margin: 0 -16px -16px;
        padding: 14px 24px;
        background: rgba(255,255,255,.96);
        backdrop-filter: blur(8px);
        border-top: 1px solid #e2e8f0;
        display:flex; align-items:center; gap:12px; flex-wrap:wrap;
    }
    .tva-bs-savebar__status { font-size:12px; color:#64748b; }

    /* ── Dark mode ─────────────────────────────────────────────────── */
    html.dark .tva-bs-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-bs-card__head { border-bottom-color:#334155; }
    html.dark .tva-bs-card__title { color:#f1f5f9; }
    html.dark .tva-bs-card__subtitle { color:#94a3b8; }
    html.dark .tva-bs-card__icon { background:#312e81; color:#a5b4fc; }

    html.dark .tva-prov-card { background:#1e293b; border-color:#334155; }
    html.dark .tva-prov-card:hover { background:#283449; }
    html.dark .tva-prov-card.is-selected { background: linear-gradient(135deg,#312e81,#3b0764); }
    html.dark .tva-prov-card__name { color:#f1f5f9; }
    html.dark .tva-prov-card__tag  { color:#94a3b8; }

    html.dark .tva-bs-label { color:#94a3b8; }
    html.dark .tva-bs-help  { color:#64748b; }
    html.dark .tva-bs-help code { background:#334155; color:#cbd5e1; }

    html.dark .tva-pill { background:#0f172a; border-color:#334155; color:#cbd5e1; }
    html.dark .tva-pill:hover { background:#1e293b; }
    html.dark .tva-pill.is-selected { background:#312e81; color:#c7d2fe; }

    html.dark .tva-toggle { background:#0f172a; border-color:#334155; }
    html.dark .tva-toggle__label { color:#f1f5f9; }
    html.dark .tva-toggle__hint { color:#94a3b8; }

    html.dark .tva-bs-savebar { background: rgba(15,23,42,.92); border-top-color:#334155; }
    html.dark .tva-bs-savebar__status { color:#94a3b8; }
</style>

@php
    // Tiny helper so the providers grid can render concisely.
    $provLogos = [
        'groq'      => 'G',
        'anthropic' => 'A',
        'gemini'    => 'G',
        'ollama'    => 'O',
    ];
    $provTags = [
        'groq'      => 'Free · Fast',
        'anthropic' => 'Paid · Best quality',
        'gemini'    => 'Free tier',
        'ollama'    => 'Local · Private',
    ];
@endphp

<div class="content tva-bs-page">

    {{-- ── Hero ─────────────────────────────────────────────────────── --}}
    <div class="tva-bs-hero mt-6">
        <div class="tva-bs-hero__icon">🧠</div>
        <div class="flex-1">
            <div class="tva-bs-hero__title">Brain & compute</div>
            <div class="tva-bs-hero__sub">Pick which AI powers your bot and where it runs. Switch any time without code changes.</div>
        </div>
    </div>

    {{-- ── Quick switches (one-click, no save needed) ─────────────── --}}
    @php
        $isLocal  = $current['provider'] === 'ollama';
        $isGpu    = $current['whisper_device'] === 'cuda';
    @endphp
    <style>
        .tva-quick-row {
            display:grid; gap:14px; grid-template-columns: 1fr;
            margin-bottom: 22px;
        }
        @media (min-width: 768px) { .tva-quick-row { grid-template-columns: 1fr 1fr; } }

        .tva-quick {
            background:#fff; border:1px solid #e2e8f0; border-radius:14px;
            padding: 18px 20px;
            display:flex; align-items:center; gap:14px;
            transition: all .15s;
            box-shadow: 0 4px 10px -6px rgba(0,0,0,.1);
        }
        .tva-quick__icon {
            width:48px; height:48px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            font-size: 24px; flex-shrink:0;
        }
        .tva-quick__main  { flex:1; min-width:0; }
        .tva-quick__label { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
        .tva-quick__value { font-size:18px; font-weight:700; color:#0f172a; margin-top:2px; }
        .tva-quick__hint  { font-size:11px; color:#94a3b8; margin-top:2px; }

        .tva-quick-btn {
            border:none; border-radius:999px;
            padding: 9px 16px; font-size:12px; font-weight:600;
            cursor:pointer; flex-shrink:0;
            background: var(--tva-gradient); color:#fff;
            transition: transform .15s, box-shadow .15s, opacity .2s;
        }
        .tva-quick-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 14px -4px rgba(0,0,0,.25); }
        .tva-quick-btn:disabled { opacity:.5; cursor:wait; }

        .tva-quick--local  .tva-quick__icon { background:#dcfce7; color:#166534; }
        .tva-quick--cloud  .tva-quick__icon { background:#fff5d1; color:#92400e; }
        .tva-quick--cpu    .tva-quick__icon { background:#e0e7ff; color:#4338ca; }
        .tva-quick--gpu    .tva-quick__icon { background:#fef3c7; color:#b45309; }

        html.dark .tva-quick { background:#1e293b; border-color:#334155; }
        html.dark .tva-quick__value { color:#f1f5f9; }
    </style>

    <div class="tva-quick-row">
        {{-- Brain quick toggle --}}
        <div class="tva-quick tva-quick--{{ $isLocal ? 'local' : 'cloud' }}" id="quick-brain">
            <div class="tva-quick__icon">{{ $isLocal ? '🖥️' : '☁️' }}</div>
            <div class="tva-quick__main">
                <div class="tva-quick__label">Brain</div>
                <div class="tva-quick__value" id="quick-brain-value">
                    {{ $isLocal ? 'Local · Ollama' : 'Cloud · ' . ucfirst($current['provider']) }}
                </div>
                <div class="tva-quick__hint" id="quick-brain-hint">
                    {{ $isLocal ? 'Switch to your last cloud provider' : 'Switch to local Ollama' }}
                </div>
            </div>
            <button type="button" class="tva-quick-btn" data-action="brain">
                <i data-lucide="arrow-left-right" class="w-3 h-3 inline -mt-0.5 mr-1"></i>
                Switch
            </button>
        </div>

        {{-- Device quick toggle --}}
        <div class="tva-quick tva-quick--{{ $isGpu ? 'gpu' : 'cpu' }}" id="quick-device">
            <div class="tva-quick__icon">{{ $isGpu ? '⚡' : '🖥️' }}</div>
            <div class="tva-quick__main">
                <div class="tva-quick__label">Compute</div>
                <div class="tva-quick__value" id="quick-device-value">
                    {{ $isGpu ? 'GPU (CUDA)' : 'CPU' }}
                </div>
                <div class="tva-quick__hint" id="quick-device-hint">
                    {{ $isGpu ? 'Switch back to CPU' : 'Switch to GPU (faster replies)' }}
                </div>
            </div>
            <button type="button" class="tva-quick-btn" data-action="device">
                <i data-lucide="arrow-left-right" class="w-3 h-3 inline -mt-0.5 mr-1"></i>
                Switch
            </button>
        </div>
    </div>

    <script>
        (function () {
            var routes = {
                brain:  '{{ route('brain-settings.toggle-brain',  ['client' => $client->slug]) }}',
                device: '{{ route('brain-settings.toggle-device', ['client' => $client->slug]) }}',
            };
            document.querySelectorAll('.tva-quick-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var action = btn.dataset.action;
                    var url = routes[action];
                    var card = btn.closest('.tva-quick');
                    var oldLabel = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i data-lucide="loader" class="w-3 h-3 inline -mt-0.5 mr-1"></i> Switching…';
                    if (window.lucide) try { window.lucide.createIcons(); } catch(_) {}

                    var fd = new FormData();
                    fd.append('_token', '{{ csrf_token() }}');

                    fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.ok) {
                                // Reload the page so all panels reflect new state.
                                window.location.reload();
                            } else {
                                btn.disabled = false;
                                btn.innerHTML = oldLabel;
                                if (window.lucide) try { window.lucide.createIcons(); } catch(_) {}
                                var msg = (data.reload && data.reload.error) || 'Reload failed';
                                alert('Switch failed: ' + msg);
                            }
                        })
                        .catch(function (err) {
                            btn.disabled = false;
                            btn.innerHTML = oldLabel;
                            if (window.lucide) try { window.lucide.createIcons(); } catch(_) {}
                            alert('Switch failed: ' + err.message);
                        });
                });
            });
        })();
    </script>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i>
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $err) <div>{{ $err }}</div> @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('brain-settings.update', ['client' => $client->slug]) }}" id="brain-form">
        @csrf
        @method('PATCH')

        {{-- ── LLM provider card ────────────────────────────────────── --}}
        <div class="tva-bs-card">
            <div class="tva-bs-card__head">
                <div class="tva-bs-card__icon" style="background:#fef3c7; color:#b45309;">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="tva-bs-card__title">Choose the AI brain</div>
                    <div class="tva-bs-card__subtitle">Which LLM should answer the bot's messages?</div>
                </div>
            </div>

            {{-- Provider cards --}}
            <div class="tva-providers" id="provider-grid">
                @foreach ($providers as $key => $meta)
                    <label class="tva-prov-card {{ $current['provider']===$key ? 'is-selected' : '' }}" data-provider="{{ $key }}">
                        <input type="radio" name="provider" value="{{ $key }}" hidden {{ $current['provider']===$key ? 'checked' : '' }}>
                        <div class="tva-prov-card__check"><i data-lucide="check" class="w-3 h-3"></i></div>
                        <div class="tva-prov-card__logo tva-prov-logo--{{ $key }}">{{ $provLogos[$key] ?? '?' }}</div>
                        <div class="tva-prov-card__name">{{ $meta['label'] }}</div>
                        <div class="tva-prov-card__tag">{{ $provTags[$key] ?? '' }}</div>
                    </label>
                @endforeach
            </div>

            {{-- Per-provider field blocks --}}
            <div class="provider-section" data-provider="groq" style="display:none;">
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Groq API key</label>
                    <input type="password" name="groq_api_key" value="{{ $current['groq_api_key'] }}" class="form-control" placeholder="gsk_...">
                    <div class="tva-bs-help">Get a free key at <code>console.groq.com</code>.</div>
                </div>
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Model</label>
                    <input type="text" name="groq_model" value="{{ $current['groq_model'] }}" class="form-control">
                    <div class="tva-bs-help">Try <code>llama-3.3-70b-versatile</code> for quality or <code>llama-3.1-8b-instant</code> for speed.</div>
                </div>
            </div>
            <div class="provider-section" data-provider="anthropic" style="display:none;">
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Anthropic API key</label>
                    <input type="password" name="anthropic_api_key" value="{{ $current['anthropic_api_key'] }}" class="form-control" placeholder="sk-ant-...">
                </div>
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Model</label>
                    <input type="text" name="anthropic_model" value="{{ $current['anthropic_model'] }}" class="form-control">
                    <div class="tva-bs-help">e.g. <code>claude-opus-4-7</code>, <code>claude-sonnet-4-6</code>, <code>claude-haiku-4-5-20251001</code>.</div>
                </div>
            </div>
            <div class="provider-section" data-provider="gemini" style="display:none;">
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Google API key</label>
                    <input type="password" name="gemini_api_key" value="{{ $current['gemini_api_key'] }}" class="form-control" placeholder="AIza...">
                </div>
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Model</label>
                    <input type="text" name="gemini_model" value="{{ $current['gemini_model'] }}" class="form-control">
                    <div class="tva-bs-help">e.g. <code>gemini-2.0-flash-lite</code> for cheap traffic, <code>gemini-2.5-flash</code> for quality.</div>
                </div>
            </div>
            <div class="provider-section" data-provider="ollama" style="display:none;">
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Ollama base URL</label>
                    <input type="text" name="ollama_base_url" value="{{ $current['ollama_base_url'] }}" class="form-control">
                    <div class="tva-bs-help">Default <code>http://localhost:11434/v1</code>. Make sure Ollama is running.</div>
                </div>
                <div class="tva-bs-field">
                    <label class="tva-bs-label">Model</label>
                    <input type="text" name="ollama_model" value="{{ $current['ollama_model'] }}" class="form-control">
                    <div class="tva-bs-help">Pull first with <code>ollama pull qwen2.5:7b</code>, then enter the model name here.</div>
                </div>
            </div>
        </div>

        {{-- ── Voice compute card ───────────────────────────────────── --}}
        <div class="tva-bs-card">
            <div class="tva-bs-card__head">
                <div class="tva-bs-card__icon" style="background:#ecfdf5; color:#10b981;">
                    <i data-lucide="cpu" class="w-4 h-4"></i>
                </div>
                <div>
                    <div class="tva-bs-card__title">Voice compute</div>
                    <div class="tva-bs-card__subtitle">Where speech recognition and text-to-speech run. GPU is ~10× faster.</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="tva-bs-label">Whisper STT device</label>
                    <div class="tva-pill-row" id="device-row">
                        <label class="tva-pill {{ $current['whisper_device']==='cpu' ? 'is-selected' : '' }}" data-device="cpu">
                            <input type="radio" name="whisper_device" value="cpu" hidden {{ $current['whisper_device']==='cpu' ? 'checked' : '' }}>
                            <div class="tva-pill__icon">🖥️</div>
                            <div>CPU</div>
                        </label>
                        <label class="tva-pill {{ $current['whisper_device']==='cuda' ? 'is-selected' : '' }}" data-device="cuda">
                            <input type="radio" name="whisper_device" value="cuda" hidden {{ $current['whisper_device']==='cuda' ? 'checked' : '' }}>
                            <div class="tva-pill__icon">⚡</div>
                            <div>GPU (CUDA)</div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="tva-bs-label">Whisper compute type</label>
                    <select name="whisper_compute_type" class="form-select">
                        @foreach ($computeTypes as $t)
                            <option value="{{ $t }}" @selected($current['whisper_compute_type']===$t)>{{ $t }}</option>
                        @endforeach
                    </select>
                    <div class="tva-bs-help">CPU → <code>int8</code> · GPU → <code>float16</code></div>
                </div>

                <div>
                    <label class="tva-bs-label">Whisper model size</label>
                    <select name="whisper_model" class="form-select">
                        @foreach ($whisperModels as $m)
                            <option value="{{ $m }}" @selected($current['whisper_model']===$m)>{{ $m }}</option>
                        @endforeach
                    </select>
                    <div class="tva-bs-help"><code>base</code> for speed, <code>large-v3</code> for best accuracy on GPU.</div>
                </div>

                <div>
                    <label class="tva-bs-label">Coqui TTS</label>
                    <div class="tva-toggle">
                        <div class="tva-toggle__main">
                            <div class="tva-toggle__label">Run on GPU</div>
                            <div class="tva-toggle__hint">Needs CUDA torch + ~2 GB VRAM.</div>
                        </div>
                        <input type="hidden" name="coqui_use_gpu" id="coqui_use_gpu_hidden" value="{{ $current['coqui_use_gpu'] }}">
                        <input type="checkbox" class="tva-toggle__sw" id="coqui_use_gpu_chk" {{ $current['coqui_use_gpu']==='true' ? 'checked' : '' }}>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Sticky save bar ──────────────────────────────────────── --}}
        <div class="tva-bs-savebar">
            <button type="submit" class="btn btn-primary shadow-md">
                <i data-lucide="save" class="w-4 h-4 mr-2 inline"></i> Save settings
            </button>
            <button type="button" id="reload-btn" class="btn btn-warning shadow-md">
                <i data-lucide="refresh-cw" class="w-4 h-4 mr-2 inline"></i> Reload Python
            </button>
            <span id="reload-status" class="tva-bs-savebar__status"></span>
            <span class="ml-auto tva-bs-savebar__status">
                <i data-lucide="info" class="w-3 h-3 inline -mt-0.5"></i>
                Save first, then reload to apply.
            </span>
        </div>
    </form>
</div>

<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        try { window.lucide.createIcons(); }
        catch (_) { if (window.lucide.icons) window.lucide.createIcons({ icons: window.lucide.icons }); }
    }

    // Provider selection
    (function () {
        function apply(provider) {
            document.querySelectorAll('.tva-prov-card').forEach(function (c) {
                c.classList.toggle('is-selected', c.dataset.provider === provider);
            });
            document.querySelectorAll('.provider-section').forEach(function (s) {
                s.style.display = (s.dataset.provider === provider) ? '' : 'none';
            });
        }
        apply(document.querySelector('input[name="provider"]:checked')?.value || 'groq');
        document.querySelectorAll('.tva-prov-card').forEach(function (c) {
            c.addEventListener('click', function () {
                c.querySelector('input').checked = true;
                apply(c.dataset.provider);
            });
        });
    })();

    // Device pills
    (function () {
        document.querySelectorAll('#device-row .tva-pill').forEach(function (t) {
            t.addEventListener('click', function () {
                document.querySelectorAll('#device-row .tva-pill').forEach(function (x) {
                    x.classList.remove('is-selected');
                });
                t.classList.add('is-selected');
                t.querySelector('input').checked = true;
            });
        });
    })();

    // GPU toggle ↔ hidden input
    (function () {
        var chk = document.getElementById('coqui_use_gpu_chk');
        var hidden = document.getElementById('coqui_use_gpu_hidden');
        chk.addEventListener('change', function () {
            hidden.value = chk.checked ? 'true' : 'false';
        });
    })();

    // Reload Python button
    (function () {
        var btn = document.getElementById('reload-btn');
        var status = document.getElementById('reload-status');
        btn.addEventListener('click', function () {
            btn.disabled = true;
            status.textContent = 'Reloading… up to 30 s if device changed';
            status.style.color = '#64748b';
            var fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fetch('{{ route('brain-settings.reload', ['client' => $client->slug]) }}', {
                method: 'POST', body: fd, credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.ok) {
                    status.textContent = '✓ Reloaded';
                    status.style.color = '#15803d';
                } else {
                    status.textContent = '✗ ' + (data.error || ('HTTP ' + data.code));
                    status.style.color = '#b91c1c';
                }
            })
            .catch(function (err) {
                btn.disabled = false;
                status.textContent = '✗ ' + err.message;
                status.style.color = '#b91c1c';
            });
        });
    })();
</script>
@endsection
