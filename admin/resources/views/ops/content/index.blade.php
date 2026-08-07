@extends('layouts.ops')

@section('content')
<style>
    .ct-wrap { max-width: 1080px; }
    .ct-layout { display:grid; grid-template-columns: 220px 1fr; gap:18px; align-items:start; }
    @media (max-width:880px){ .ct-layout{ grid-template-columns:1fr; } }

    .ct-tabs { display:flex; flex-direction:column; gap:4px; position:sticky; top:16px; }
    @media (max-width:880px){ .ct-tabs{ flex-direction:row; flex-wrap:wrap; position:static; } }
    .ct-tab {
        display:flex; align-items:center; gap:9px; padding:10px 12px; border-radius:10px;
        font-size:13px; font-weight:600; color:#475569; cursor:pointer; border:1px solid transparent;
        background:transparent; text-align:left; transition:all .12s;
    }
    .ct-tab:hover { background:#fff7ed; color:#c2410c; }
    .ct-tab.is-active { background:var(--tva-gradient); color:#fff; box-shadow:0 6px 16px -8px rgba(201,122,0,.6); }
    .ct-tab .em { font-size:15px; }

    .ct-panel { display:none; }
    .ct-panel.is-active { display:block; }

    .ct-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px 24px; margin-bottom:16px; }
    .ct-card__head { margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e2e8f0; }
    .ct-card__title { font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }

    .ct-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    @media (max-width:640px){ .ct-grid2{ grid-template-columns:1fr; } }
    .ct-field { margin-bottom:14px; }
    .ct-field.span2 { grid-column:1 / -1; }
    .ct-field > label { display:block; font-size:12px; font-weight:600; color:#334155; margin-bottom:6px; }
    .ct-input, .ct-textarea {
        width:100%; padding:9px 12px; border:1px solid #e2e8f0; border-radius:9px;
        background:#fff; font-size:13px; color:#0f172a;
    }
    .ct-textarea { resize:vertical; min-height:80px; font-family:inherit; }
    .ct-input:focus, .ct-textarea:focus { outline:none; border-color:var(--tva-accent); box-shadow:0 0 0 3px rgba(255,184,0,.15); }

    .ct-savebar {
        position:sticky; bottom:0; z-index:20; margin-top:6px;
        background:rgba(255,255,255,.92); backdrop-filter:blur(8px);
        border:1px solid #e2e8f0; border-radius:12px; padding:12px 18px;
        display:flex; align-items:center; justify-content:space-between; gap:12px;
        box-shadow:0 -6px 20px -12px rgba(0,0,0,.25);
    }
    .ct-savebar__note { font-size:12px; color:#64748b; }

    html.dark .ct-card { background:#1e293b; border-color:#334155; }
    html.dark .ct-card__title { color:#f1f5f9; }
    html.dark .ct-card__head { border-bottom-color:#334155; }
    html.dark .ct-field > label { color:#cbd5e1; }
    html.dark .ct-input, html.dark .ct-textarea { background:#0f172a; color:#f1f5f9; border-color:#334155; }
    html.dark .ct-savebar { background:rgba(15,23,42,.92); border-color:#334155; }
    html.dark .ct-tab:hover { background:#7c2d12; color:#fcd34d; }
</style>

<div class="content ct-wrap">
    <div class="tva-dt-hero mt-6">
        <div class="tva-dt-hero__icon">📝</div>
        <div class="flex-1">
            <div style="font-size:20px; font-weight:700;">Website Content</div>
            <div style="font-size:13px; opacity:.9; margin-top:4px;">
                Edit the copy on your public homepage — headlines, feature cards, stats, and calls-to-action.
                Changes go live the moment you save. Leave a field blank to use its built-in default.
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success-soft show mb-4 flex items-center">
            <i data-lucide="check-circle" class="w-4 h-4 mr-2"></i> {{ session('success') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger-soft show mb-4">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <div class="flex items-center gap-2 mb-4">
        <a href="{{ url('/') }}" target="_blank" class="btn btn-secondary btn-sm">
            <i data-lucide="external-link" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> View live homepage
        </a>
        <form method="POST" action="{{ route('ops.content.reset') }}" class="inline"
              data-confirm="Reset ALL homepage content back to defaults? This clears every override.">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm">
                <i data-lucide="rotate-ccw" class="w-3.5 h-3.5 inline -mt-0.5 mr-1"></i> Reset to defaults
            </button>
        </form>
    </div>

    <form method="POST" action="{{ route('ops.content.update') }}" id="ctForm">
        @csrf
        <div class="ct-layout">
            <div class="ct-tabs" id="ctTabs">
                @foreach ($sections as $name => $sec)
                    <button type="button" class="ct-tab {{ $loop->first ? 'is-active' : '' }}" data-tab="{{ $loop->index }}">
                        <span class="em">{{ $sec['icon'] }}</span> {{ $name }}
                    </button>
                @endforeach
            </div>

            <div>
                @foreach ($sections as $name => $sec)
                    <div class="ct-panel {{ $loop->first ? 'is-active' : '' }}" data-panel="{{ $loop->index }}">
                        <div class="ct-card">
                            <div class="ct-card__head"><div class="ct-card__title">{{ $sec['icon'] }} {{ $name }}</div></div>
                            <div class="ct-grid2">
                                @foreach ($sec['fields'] as $f)
                                    @php $isWide = $f['type'] === 'textarea'; @endphp
                                    <div class="ct-field {{ $isWide ? 'span2' : '' }}">
                                        <label>{{ $f['label'] }}</label>
                                        @if ($isWide)
                                            <textarea name="{{ $f['key'] }}" class="ct-textarea" rows="3">{{ $values[$f['key']] ?? '' }}</textarea>
                                        @else
                                            <input type="text" name="{{ $f['key'] }}" class="ct-input" value="{{ $values[$f['key']] ?? '' }}">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach

                <div class="ct-savebar">
                    <div class="ct-savebar__note"><i data-lucide="info" class="w-3.5 h-3.5 inline -mt-0.5"></i> Saving updates the live homepage instantly.</div>
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4 inline -mt-0.5 mr-1"></i> Save content</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    if (window.lucide?.createIcons) window.lucide.createIcons();
    var tabs = document.querySelectorAll('.ct-tab');
    var panels = document.querySelectorAll('.ct-panel');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(x => x.classList.remove('is-active'));
            panels.forEach(p => p.classList.remove('is-active'));
            t.classList.add('is-active');
            var p = document.querySelector('.ct-panel[data-panel="' + t.dataset.tab + '"]');
            if (p) p.classList.add('is-active');
        });
    });
})();
</script>
@endsection
