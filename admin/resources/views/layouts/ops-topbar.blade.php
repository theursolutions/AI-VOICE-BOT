<style>
    .ops-tb-pill {
        display: inline-flex; align-items: center; gap: 6px;
        background: var(--tva-accent); color: #1c1300;
        font-family: ui-monospace, monospace; font-size: 10px; font-weight: 800;
        padding: 5px 11px; border-radius: 6px;
        text-transform: uppercase; letter-spacing: .12em;
        box-shadow: 0 0 14px rgba(255, 184, 0, .35);
    }
</style>

<div class="top-bar">
    {{-- Internal-mode brand pill (replaces the customer project chip) --}}
    <div class="-intro-x mr-auto" style="display:flex; align-items:center; gap:12px;">
        <span class="ops-tb-pill">
            <i data-lucide="shield" class="w-3 h-3"></i> Ops Console
        </span>
        <span style="font-size:13px; color:#94a3b8;" class="hidden md:inline">
            Platform-wide view · all workspaces
        </span>
    </div>

    {{-- Account dropdown — mirrors the customer topbar shape --}}
    <div class="intro-x dropdown w-8 h-8">
        <div class="dropdown-toggle w-8 h-8 rounded-full overflow-hidden shadow-lg image-fit zoom-in flex items-center justify-center"
             role="button" aria-expanded="false" data-tw-toggle="dropdown"
             style="background: linear-gradient(135deg, #ffb800, #c97a00); color:#1c1300; font-weight:800; font-size:13px;">
            {{ strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', auth()->user()?->name ?? 'S') ?: 'S', 0, 2)) }}
        </div>
        <div class="dropdown-menu w-56">
            <ul class="dropdown-content bg-primary text-white">
                <li class="p-2">
                    <div class="font-medium">{{ auth()->user()?->name }}</div>
                    <div class="text-xs text-white/70 mt-0.5">{{ auth()->user()?->email }}</div>
                    <div class="text-xs text-white/50 mt-1 uppercase tracking-wider">super-admin</div>
                </li>
                <li><hr class="dropdown-divider border-white/[0.08]"></li>
                <li>
                    <a href="{{ url('/dashboard') }}" class="dropdown-item hover:bg-white/5">
                        <i data-lucide="log-out" class="w-4 h-4 mr-2"></i> Exit to user app
                    </a>
                </li>
                <li>
                    <a href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();"
                       class="dropdown-item hover:bg-white/5">
                        <i data-lucide="toggle-right" class="w-4 h-4 mr-2"></i> Sign out
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">@csrf</form>
                </li>
            </ul>
        </div>
    </div>
</div>
