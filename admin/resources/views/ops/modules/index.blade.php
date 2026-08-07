@extends('layouts.ops')

@section('content')
<style>
    .mod-grid { display:grid; grid-template-columns: repeat(2, 1fr); gap:14px; }
    @media (max-width: 820px) { .mod-grid { grid-template-columns: 1fr; } }

    .mod-row {
        display:flex; align-items:center; gap:14px;
        background:#fff; border:1px solid #e2e8f0; border-radius:12px;
        padding:14px 16px; transition: border-color .15s, box-shadow .15s;
    }
    .mod-row.is-off { background:#fafafa; border-style:dashed; }
    .mod-row__icon {
        width:40px; height:40px; border-radius:10px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
        background:#fef3c7; color:#92400e;
    }
    .mod-row.is-off .mod-row__icon { background:#f1f5f9; color:#94a3b8; }
    .mod-row__body { flex:1; min-width:0; }
    .mod-row__name { font-size:14px; font-weight:600; color:#0f172a; display:flex; align-items:center; gap:8px; }
    .mod-row__key  { font-size:11px; color:#94a3b8; font-family:ui-monospace,monospace; margin-top:2px; }
    .mod-row__state { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
    .mod-row__state.on  { color:#15803d; }
    .mod-row__state.off { color:#b91c1c; }

    .mod-lock {
        font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
        background:#e2e8f0; color:#475569; padding:2px 7px; border-radius:999px;
    }

    /* Toggle switch */
    .mod-switch { position:relative; display:inline-block; width:46px; height:26px; flex-shrink:0; }
    .mod-switch input { opacity:0; width:0; height:0; }
    .mod-switch .track {
        position:absolute; inset:0; cursor:pointer; background:#cbd5e1;
        border-radius:999px; transition: background .2s;
    }
    .mod-switch .track::before {
        content:''; position:absolute; height:20px; width:20px; left:3px; top:3px;
        background:#fff; border-radius:50%; transition: transform .2s; box-shadow:0 1px 3px rgba(0,0,0,.3);
    }
    .mod-switch input:checked + .track { background: var(--tva-gradient); }
    .mod-switch input:checked + .track::before { transform: translateX(20px); }
    .mod-switch input:disabled + .track { opacity:.55; cursor:not-allowed; }

    html.dark .mod-row { background:#1e293b; border-color:#334155; }
    html.dark .mod-row.is-off { background:#172033; }
    html.dark .mod-row__name { color:#f1f5f9; }
    html.dark .mod-lock { background:#334155; color:#cbd5e1; }
</style>

<div class="content">
    {{-- Hero --}}
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">🧩</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Module switchboard</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Switch admin modules on or off for the whole platform. A module that's off
                disappears from every customer's sidebar and Roles &amp; Permissions, and shows a
                <b>“under development”</b> page on a direct hit.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success mb-4">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger mb-4">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @php
        // Lucide icon per module key (falls back to "box").
        $icons = [
            'dashboard'=>'home', 'assistant'=>'bot', 'conversations'=>'message-square',
            'messages'=>'message-circle', 'leads'=>'user-check', 'agents'=>'users',
            'channels'=>'radio', 'data_sources'=>'database', 'flows'=>'git-branch',
            'bot_strategy'=>'layers', 'skills'=>'tag', 'telephony'=>'phone',
            'voices'=>'mic', 'widget'=>'layout-template', 'compute'=>'cpu',
            'profile'=>'image', 'team'=>'shield',
        ];
    @endphp

    <form method="POST" action="{{ route('ops.modules.update') }}">
        @csrf

        <div class="tva-dt-card" style="padding:18px;">
            <div class="flex items-center mb-4">
                <div>
                    <div style="font-size:14px; font-weight:700; color:var(--tva-accent);">
                        {{ count(\App\Support\Modules::enabledKeys()) }} on
                        · {{ count($disabled) }} off
                    </div>
                    <div class="text-xs text-slate-400 mt-0.5">
                        Toggle below, then save. Dashboard stays on so no one is locked out.
                    </div>
                </div>
                <div class="ml-auto flex gap-2">
                    <button type="button" id="modAllOn"  class="btn btn-outline-secondary btn-sm">Turn all on</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i data-lucide="save" class="w-4 h-4 mr-1"></i> Save changes
                    </button>
                </div>
            </div>

            <div class="mod-grid">
                @foreach ($modules as $key => $cfg)
                    @php
                        $locked = in_array($key, $alwaysOn, true);
                        $isOn   = $locked || ! in_array($key, $disabled, true);
                        $icon   = $icons[$key] ?? 'box';
                    @endphp
                    <label class="mod-row {{ $isOn ? '' : 'is-off' }}" data-mod-row>
                        <span class="mod-row__icon"><i data-lucide="{{ $icon }}" class="w-5 h-5"></i></span>
                        <span class="mod-row__body">
                            <span class="mod-row__name">
                                {{ $cfg['label'] ?? $key }}
                                @if ($locked)<span class="mod-lock">Always on</span>@endif
                            </span>
                            <span class="mod-row__key">{{ $key }}</span>
                        </span>
                        <span class="mod-row__state {{ $isOn ? 'on' : 'off' }}" data-mod-state>
                            {{ $isOn ? 'On' : 'Off' }}
                        </span>
                        <span class="mod-switch">
                            <input type="checkbox" name="enabled[]" value="{{ $key }}"
                                   @checked($isOn) @disabled($locked) data-mod-toggle>
                            <span class="track"></span>
                        </span>
                        @if ($locked)
                            {{-- Keep always-on modules submitted even though the
                                 visible checkbox is disabled (disabled inputs
                                 aren't posted). --}}
                            <input type="hidden" name="enabled[]" value="{{ $key }}">
                        @endif
                    </label>
                @endforeach
            </div>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Live row state + "Turn all on".
        document.querySelectorAll('[data-mod-toggle]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var row   = cb.closest('[data-mod-row]');
                var state = row.querySelector('[data-mod-state]');
                row.classList.toggle('is-off', !cb.checked);
                state.textContent = cb.checked ? 'On' : 'Off';
                state.classList.toggle('on',  cb.checked);
                state.classList.toggle('off', !cb.checked);
            });
        });
        var allOn = document.getElementById('modAllOn');
        if (allOn) {
            allOn.addEventListener('click', function () {
                document.querySelectorAll('[data-mod-toggle]:not(:disabled)').forEach(function (cb) {
                    if (!cb.checked) { cb.checked = true; cb.dispatchEvent(new Event('change')); }
                });
            });
        }
    });
</script>
@endsection
