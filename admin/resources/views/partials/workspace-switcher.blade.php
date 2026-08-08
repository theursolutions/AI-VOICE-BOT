@php
    /** @var \App\Models\User|null $user */
    $user      = auth()->user();
    $clients   = $user ? $user->clients()->orderBy('clients.name')->get() : collect();
    $current   = $user?->activeClient;
@endphp

{{-- Pill styling matches .tva-tb-brand__name in layouts/topbar.blade.php so the
     workspace name and the project name read as the same class of element.
     Both branches below use it: the switcher button (2+ workspaces) and the
     static label (exactly 1), which previously rendered as loose grey text. --}}
<style>
    .tva-ws-pill {
        display:inline-flex; align-items:center; gap:6px;
        font-size:13px; font-weight:600; line-height:1;
        color:#0f172a;
        padding:6px 12px; border-radius:999px;
        background: rgba(15,23,42,.05);
        border:1px solid rgba(15,23,42,.08);
        max-width:200px;
        transition: background .15s, border-color .15s;
    }
    .tva-ws-pill > span:first-child {
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    }
    button.tva-ws-pill { cursor:pointer; }
    button.tva-ws-pill:hover {
        background: rgba(15,23,42,.08);
        border-color: rgba(15,23,42,.14);
    }
    html.dark .tva-ws-pill {
        color:#f1f5f9;
        background: rgba(255,255,255,.07);
        border-color: rgba(255,255,255,.12);
    }
    html.dark button.tva-ws-pill:hover {
        background: rgba(255,255,255,.11);
        border-color: rgba(255,255,255,.18);
    }
    .tva-ws-pill svg { flex-shrink:0; opacity:.55; }
</style>

@if ($clients->count() > 1 && $current)
    <div class="workspace-switcher relative inline-block">
        <button type="button"
                onclick="document.getElementById('workspace-switcher-menu').classList.toggle('hidden')"
                class="tva-ws-pill"
                title="Switch workspace — {{ $current->name }}">
            <span>{{ $current->name }}</span>
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
                      clip-rule="evenodd"/>
            </svg>
        </button>

        <div id="workspace-switcher-menu"
             class="hidden absolute right-0 mt-2 w-56 rounded-lg shadow-lg z-50
                    bg-white ring-1 ring-black/5
                    dark:bg-darkmode-600 dark:ring-white/10">
            <div class="py-1">
                @foreach ($clients as $c)
                    <form method="POST" action="{{ route('workspace.select') }}">
                        @csrf
                        <input type="hidden" name="client_id" value="{{ $c->id }}">
                        <button type="submit"
                                class="w-full text-left px-4 py-2 text-sm flex items-center justify-between
                                       hover:bg-slate-100 dark:hover:bg-white/5
                                       {{ $c->id === $current->id
                                            ? 'font-semibold text-slate-900 dark:text-white'
                                            : 'text-slate-700 dark:text-slate-300' }}">
                            <span class="truncate">{{ $c->name }}</span>
                            @if ($c->id === $current->id)
                                <span class="text-xs text-green-600 ml-2">●</span>
                            @endif
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
@elseif ($current)
    <span class="tva-ws-pill" title="{{ $current->name }}">
        <span>{{ $current->name }}</span>
    </span>
@endif
