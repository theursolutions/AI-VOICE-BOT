<style>
/* ── Account dropdown ──────────────────────────────────────────────
   Sits on the deep navy `.bg-primary` menu, so every colour here is
   defined against that rather than the page background. */
.tva-acct { padding: 4px 0; }
.tva-acct__id {
    display: flex; align-items: center; gap: 11px;
    padding: 12px 14px 11px;
}
.tva-acct__av {
    width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff;
    box-shadow: 0 0 0 1px rgba(255,255,255,.16);
}
.tva-acct__name {
    display: block; font-size: 13.5px; font-weight: 600; color: #fff;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tva-acct__mail {
    display: block; font-size: 11.5px; color: rgba(255,255,255,.6); margin-top: 1px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* Plan row — the reason this menu exists beyond profile links. */
.tva-acct__plan {
    display: flex; align-items: center; gap: 11px;
    margin: 2px 10px 8px;
    padding: 10px 12px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.10);
    border-radius: 11px;
}
.tva-acct__badge {
    width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; color: #fff;
    box-shadow: 0 4px 12px -4px rgba(0,0,0,.5);
}
.tva-acct__plan-name {
    display: block; font-size: 13px; font-weight: 700; color: #fff;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tva-acct__plan-meta {
    display: flex; align-items: center; gap: 5px; flex-wrap: wrap;
    font-size: 11px; color: rgba(255,255,255,.62); margin-top: 3px;
}
.tva-acct__sep { opacity: .5; }
/* Status word next to the plan name — only rendered when the plan is
   NOT simply active, so its presence is itself the signal. */
.tva-acct__state {
    font-style: normal; font-size: 9.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    padding: 2px 6px; border-radius: 999px;
    white-space: nowrap; flex-shrink: 0;
}
.tva-acct__state.is-green { background: rgba(52,211,153,.22); color: #6ee7b7; }
.tva-acct__state.is-blue  { background: rgba(96,165,250,.22); color: #93c5fd; }
.tva-acct__state.is-amber { background: rgba(251,191,36,.22); color: #fcd34d; }
.tva-acct__state.is-red   { background: rgba(248,113,113,.22); color: #fca5a5; }
.tva-acct__state.is-slate { background: rgba(148,163,184,.22); color: #cbd5e1; }
.tva-acct__alert { color: #fca5a5; flex-shrink: 0; }
    .tva-tb-brand {
        display:flex; align-items:center; gap:10px;
        text-decoration:none; color:inherit;
        padding:4px 10px; border-radius:10px;
        transition: background .15s;
    }
    .tva-tb-brand:hover { background: rgba(15,23,42,.06); }
    html.dark .tva-tb-brand:hover { background: rgba(255,255,255,.06); }
    .tva-tb-logo {
        width:36px; height:36px; border-radius:9px;
        background: var(--tva-gradient); color:#fff;
        display:flex; align-items:center; justify-content:center;
        font-weight:700; font-size:13px;
        overflow:hidden; flex-shrink:0;
        box-shadow: 0 2px 6px rgba(99,102,241,.25);
    }
    .tva-tb-logo img { width:100%; height:100%; object-fit:cover; }
    /* Company/project name as a pill rather than loose text, so it reads as a
       distinct badge next to the logo instead of floating in the bar. */
    .tva-tb-brand__name {
        display:inline-flex; align-items:center;
        font-size:13px; font-weight:600; line-height:1;
        color:#0f172a;
        padding:5px 11px; border-radius:999px;
        background: rgba(15,23,42,.05);
        border:1px solid rgba(15,23,42,.08);
        max-width:220px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        transition: background .15s, border-color .15s;
    }
    .tva-tb-brand:hover .tva-tb-brand__name {
        background: rgba(15,23,42,.08);
        border-color: rgba(15,23,42,.14);
    }
    .tva-tb-brand__sub   { font-size:11px; color:#64748b; margin-top:3px; padding-left:11px; }
    html.dark .tva-tb-brand__name {
        color:#f1f5f9;
        background: rgba(255,255,255,.07);
        border-color: rgba(255,255,255,.12);
    }
    html.dark .tva-tb-brand:hover .tva-tb-brand__name {
        background: rgba(255,255,255,.11);
        border-color: rgba(255,255,255,.18);
    }
    @media (max-width: 640px) {
        .tva-tb-brand__text { display: none; }
    }

    /* Two controls on the bar are desktop-only and were crowding the phone
       header to the point where the workspace name had nowhere to go. */
    @media (max-width: 767px) {
        /* The rail collapse toggle has nothing to collapse here: the sidebar
           is already hidden below md and navigation comes from the mobile
           menu. It is injected by layouts/nav-collapse. */
        #navCollapseBtn { display: none !important; }
        /* The magnifier standing in for the search field, which is itself
           hidden below sm. It is the only <a class="notification"> on the bar
           — the bell is a <div> — so this does not touch notifications. */
        .top-bar a.notification { display: none !important; }
    }
</style>

<div class="top-bar">
    {{-- Active project brand (logo + name). Replaces the static breadcrumb. --}}
    @php
        $tvaLogoPath = $tvaProfile['logo_path'] ?? null;
        // asset() depends on APP_URL, which is often mis-set in dev.
        // Derive the URL from the running script dir so it always works.
        $tvaLogoUrl = null;
        if ($tvaLogoPath) {
            $tvaUrlBase = request()->getSchemeAndHttpHost() . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            $tvaLogoUrl = $tvaUrlBase . '/storage/' . ltrim($tvaLogoPath, '/');
        }
        $tvaProjectName = $tvaProject?->name ?? config('app.name', 'Serve AI');
        $tvaInitials = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $tvaProjectName) ?: 'P', 0, 2));
        $tvaProfileUrl = (request()->route('client') && $tvaProject)
            ? route('project-profile.index', ['client' => is_object(request()->route('client')) ? request()->route('client')->slug : request()->route('client'), 'project_id' => $tvaProject->id])
            : null;
    @endphp
    @if ($tvaProfileUrl)
        <a href="{{ $tvaProfileUrl }}" class="-intro-x mr-auto tva-tb-brand" title="Edit project profile">
            <div class="tva-tb-logo">
                @if ($tvaLogoUrl)
                    <img src="{{ $tvaLogoUrl }}" alt="{{ $tvaProjectName }}">
                @else
                    {{ $tvaInitials }}
                @endif
            </div>
            <div class="tva-tb-brand__text">
                <div class="tva-tb-brand__name">{{ $tvaProjectName }}</div>
            </div>
        </a>
    @else
        <div class="-intro-x mr-auto tva-tb-brand">
            <div class="tva-tb-logo">{{ $tvaInitials }}</div>
            <div class="tva-tb-brand__text">
                <div class="tva-tb-brand__name">{{ $tvaProjectName }}</div>
            </div>
        </div>
    @endif

    <div id="create-company-modal" class="modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content"> <a data-tw-dismiss="modal" href="javascript:;"> <i data-lucide="x" class="w-8 h-8 text-slate-400"></i> </a>
                <div class="modal-body p-0">
                    <div class="intro-y box">
                            <div class="flex flex-col sm:flex-row items-center p-5 border-b border-slate-200/60 dark:border-darkmode-400">
                                <h2 class="font-medium text-base mr-auto">
                                    Input
                                </h2>
                                <div class="form-check form-switch w-full sm:w-auto sm:ml-auto mt-3 sm:mt-0">
                                    <label class="form-check-label ml-0" for="show-example-1">Show example code</label>
                                    <input id="show-example-1" data-target="#input" class="show-code form-check-input mr-0 ml-3" type="checkbox">
                                </div>
                            </div>
                            <div id="input" class="p-5">
                                <div class="preview">
                                    <div>
                                        <label for="regular-form-1" class="form-label">Input Text</label>
                                        <input id="regular-form-1" type="text" class="form-control" placeholder="Input text">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-2" class="form-label">Rounded</label>
                                        <input id="regular-form-2" type="text" class="form-control form-control-rounded" placeholder="Rounded">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-3" class="form-label">With Help</label>
                                        <input id="regular-form-3" type="text" class="form-control" placeholder="With help">
                                        <div class="form-help">Lorem Ipsum is simply dummy text of the printing and typesetting industry.</div>
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-4" class="form-label">Password</label>
                                        <input id="regular-form-4" type="password" class="form-control" placeholder="Password">
                                    </div>
                                    <div class="mt-3">
                                        <label for="regular-form-5" class="form-label">Disabled</label>
                                        <input id="regular-form-5" type="text" class="form-control" placeholder="Disabled" disabled="">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <div class="px-5 pb-8 text-center"> <button type="button" data-tw-dismiss="modal" class="btn btn-primary w-24">Ok</button> </div>
                </div>
            </div>
        </div>
    </div>
    <!-- BEGIN: Workspace Switcher -->
    <div class="intro-x mr-3 sm:mr-6">
        @include('partials.workspace-switcher')
    </div>
    <!-- END: Workspace Switcher -->

    <!-- BEGIN: Search -->
    <div class="intro-x relative mr-3 sm:mr-6">
        <div class="search hidden sm:block">
            <input id="tva-search" type="text" autocomplete="off"
                   class="search__input form-control border-transparent" placeholder="Search pages…">
            <i data-lucide="search" class="search__icon dark:text-slate-500"></i>
        </div>
        <a class="notification sm:hidden" href="javascript:;"> <i data-lucide="search" class="notification__icon dark:text-slate-500"></i> </a>

        {{-- Live page search. Replaces the template's hard-coded demo results
             ("Mail Settings", stock-photo users, sample products) which linked
             nowhere and implied features that don't exist.

             The index is built at runtime from the RENDERED SIDEBAR, so it is
             correct by construction: it can only ever offer pages this user's
             role actually put in the menu. No endpoint, no query, and no way
             for it to leak a page the user can't reach. --}}
        <div class="search-result" id="tva-search-result">
            <div class="search-result__content">
                <div class="search-result__content__title">Pages</div>
                <div id="tva-search-hits" class="mb-2"></div>
                <div id="tva-search-empty" class="text-slate-500 text-xs py-2 hidden">No page matches that.</div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var input  = document.getElementById('tva-search');
        var panel  = document.getElementById('tva-search-result');
        var hits   = document.getElementById('tva-search-hits');
        var empty  = document.getElementById('tva-search-empty');
        if (!input || !panel || !hits) return;

        // Keyword aliases, mirroring AssistantController::navItems() so typing
        // "customers" or "contacts" finds Leads here exactly as it does in the
        // Ask AI voice navigation. Matching is on label OR any alias; the real
        // label is always what gets displayed.
        var ALIASES = {
            'dashboard':       ['home', 'overview', 'main', 'start', 'stats', 'kpi'],
            'ask ai':          ['assistant', 'ai chat', 'chatbot', 'team assistant', 'copilot'],
            'live compute':    ['compute', 'mesh', 'gpu', 'workers', 'nodes'],
            'messages':        ['inbox', 'chats', 'chat', 'agent inbox', 'conversations list', 'dm'],
            'channels':        ['whatsapp', 'instagram', 'facebook', 'meta', 'integrations', 'connect'],
            'bot strategy':    ['strategy', 'prompt', 'persona', 'behaviour', 'behavior', 'tone'],
            'brain & compute': ['brain', 'brain settings', 'model', 'llm', 'provider'],
            'data sources':    ['sources', 'datasource', 'upload', 'knowledge base', 'kb', 'crawler', 'documents', 'files'],
            'voices':          ['voice', 'voice library', 'tts', 'speech', 'accent', 'speaker'],
            'telephony':       ['phone', 'phone numbers', 'calls', 'sip', 'twilio', 'dialer'],
            'project profile': ['profile', 'project settings', 'company', 'business', 'industry', 'about'],
            'agents':          ['agent', 'bot agents', 'bots', 'personas'],
            'skills':          ['skill', 'actions', 'tools', 'functions', 'webhooks'],
            'flow builder':    ['flows', 'flow', 'builder', 'automation', 'workflow', 'ivr'],
            'widget':          ['webchat', 'web chat', 'chat widget', 'embed', 'snippet', 'install'],
            'conversations':   ['sessions', 'session', 'call log', 'transcripts', 'history', 'chat log'],
            'leads':           ['lead', 'customers', 'customer', 'contacts', 'contact', 'prospects', 'crm'],
            'members':         ['member', 'team', 'staff', 'users', 'user', 'people'],
            'roles':           ['role', 'permissions', 'permission', 'access', 'rbac'],
            'invitations':     ['invite', 'invites', 'invitation', 'onboard'],
            'verify email':    ['verify', 'verification', 'confirm email', 'otp', 'unlock']
        };

        // Build the index from the sidebar's own links. Skips collapsible
        // parents (href="javascript:;") and the veiled/blurred group, so an
        // unverified user isn't offered pages the middleware will refuse.
        var index = [];
        document.querySelectorAll('.side-nav a.side-menu').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (!href || href.indexOf('javascript:') === 0 || href === '#') return;
            if (a.closest('.tva-lock__items')) return;
            var t = a.querySelector('.side-menu__title');
            if (!t) return;
            // .side-menu__title also holds the chevron for parents — take the
            // leading text node only.
            var label = (t.childNodes[0] && t.childNodes[0].textContent || t.textContent || '').trim();
            if (!label) return;
            var keys = ALIASES[label.toLowerCase()] || [];
            index.push({
                label: label,
                href: href,
                // Pre-lowercased haystack: label plus its aliases.
                hay: (label + ' ' + keys.join(' ')).toLowerCase()
            });
        });

        function render(q) {
            q = q.trim().toLowerCase();
            hits.innerHTML = '';
            if (!q) { panel.classList.remove('show'); return; }

            // Rank label matches above alias-only matches, so typing "lead"
            // puts Leads first rather than whatever alias happened to match.
            var starts = [], contains = [], alias = [];
            index.forEach(function (i) {
                var l = i.label.toLowerCase();
                if (l.indexOf(q) === 0)            { starts.push(i); }
                else if (l.indexOf(q) !== -1)      { contains.push(i); }
                else if (i.hay.indexOf(q) !== -1)  { alias.push(i); }
            });
            var found = starts.concat(contains, alias).slice(0, 8);
            found.forEach(function (i) {
                var a = document.createElement('a');
                a.href = i.href;
                a.className = 'flex items-center mt-2';
                a.innerHTML =
                    '<div class="w-8 h-8 bg-primary/10 text-primary flex items-center justify-center rounded-full">' +
                    '<i class="w-4 h-4" data-lucide="corner-down-right"></i></div>' +
                    '<div class="ml-3"></div>';
                a.querySelector('.ml-3').textContent = i.label;
                hits.appendChild(a);
            });
            empty.classList.toggle('hidden', found.length > 0);
            panel.classList.add('show');
            if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
        }

        input.addEventListener('input',  function () { render(input.value); });
        input.addEventListener('focus',  function () { if (input.value) render(input.value); });
        // Enter opens the first hit.
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var first = hits.querySelector('a');
                if (first) { e.preventDefault(); window.location.href = first.href; }
            } else if (e.key === 'Escape') {
                input.value = ''; panel.classList.remove('show');
            }
        });
        document.addEventListener('click', function (e) {
            if (!panel.contains(e.target) && e.target !== input) panel.classList.remove('show');
        });
    })();
    </script>
    <!-- END: Search -->
    <!-- END: Search -->
    
    <!-- BEGIN: Theme -->
    {{-- The icon shows what you will GET, not what you are on — the
         commonest confusion with these controls. --}}
    <div class="intro-x mr-3 sm:mr-4">
        <button type="button" class="tva-theme" onclick="tvaToggleTheme()"
                aria-label="Switch between light and dark" title="Switch theme">
            <svg class="tva-theme__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
            <svg class="tva-theme__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
            </svg>
        </button>
    </div>
    <!-- END: Theme -->

    <!-- BEGIN: Notifications -->
    <div class="intro-x dropdown mr-auto sm:mr-6">
        {{-- Bell kept for layout continuity, but WITHOUT the template's demo
             notifications. There is no notification backend yet, so showing
             fabricated entries (stock avatars, lorem text, fake timestamps)
             would read as real activity on a live install. The dropdown opens
             and states plainly that there is nothing to show.
             `notification--bullet` is dropped too: the unread dot implied
             pending items that never existed. --}}
        <div class="dropdown-toggle notification cursor-pointer" role="button" aria-expanded="false" data-tw-toggle="dropdown" title="Notifications">
            <i data-lucide="bell" class="notification__icon dark:text-slate-500"></i>
        </div>
        <div class="notification-content pt-2 dropdown-menu">
            <div class="notification-content__box dropdown-content">
                <div class="notification-content__title">Notifications</div>
                <div class="flex flex-col items-center justify-center py-6 text-center">
                    <i data-lucide="bell-off" class="w-6 h-6 text-slate-400 mb-2"></i>
                    <div class="text-slate-500 text-xs">You're all caught up</div>
                </div>
            </div>
        </div>
    </div>
    <!-- END: Notifications -->
    <!-- BEGIN: Account Menu -->
    @php
        // Initials from the user's own name — first + last word, so "Umer Malik"
        // becomes "UM" and a single-word name falls back to its first letter.
        // Replaces the template's stock stock-photo avatar.
        $tvaUserName  = trim((string) (Auth::user()->name ?? ''));
        $tvaNameParts = preg_split('/\s+/', $tvaUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tvaUserInit  = count($tvaNameParts) > 1
            ? mb_strtoupper(mb_substr($tvaNameParts[0], 0, 1) . mb_substr(end($tvaNameParts), 0, 1))
            : mb_strtoupper(mb_substr($tvaUserName !== '' ? $tvaUserName : 'U', 0, 1));
    @endphp
    <div class="intro-x dropdown w-8 h-8">
        <div class="dropdown-toggle w-8 h-8 rounded-full overflow-hidden shadow-lg zoom-in cursor-pointer flex items-center justify-center font-medium text-xs text-white select-none"
             style="background-image: var(--tva-gradient, linear-gradient(135deg,#3b82f6,#2563eb));"
             role="button" aria-expanded="false" data-tw-toggle="dropdown"
             title="{{ $tvaUserName }}">
            {{ $tvaUserInit }}
        </div>
        <div class="dropdown-menu w-64">
            <ul class="dropdown-content bg-primary text-white tva-acct">
                <li class="tva-acct__id">
                    <span class="tva-acct__av" style="background-image: var(--tva-gradient, linear-gradient(135deg,#3b82f6,#2563eb));">{{ $tvaUserInit }}</span>
                    <span class="min-w-0">
                        <span class="tva-acct__name">{{ Auth::user()->name }}</span>
                        <span class="tva-acct__mail">{{ Auth::user()->email }}</span>
                    </span>
                </li>

                {{-- Current plan. "What am I on and when does it renew?" is an
                     account question, and this menu is where people look for
                     it — the answer was two clicks away on the billing page. --}}
                @php
                    $tbClientForPlan = request()->attributes->get('client') ?? Auth::user()->activeClient;
                    $tbSub = null;
                    if ($tbClientForPlan) {
                        try {
                            $tbSub = \App\Models\Billing\Subscription::with('plan')
                                ->where('client_id', $tbClientForPlan->id)
                                ->orderByDesc('id')
                                ->first();
                        } catch (\Throwable $e) {
                            // Billing tables not migrated — the menu still works.
                        }
                    }

                    // Each plan gets its own icon + colour so the tier is
                    // recognisable at a glance rather than read word by word.
                    $tbPlanSlug = $tbSub?->plan?->slug ?? 'free';
                    [$tbIcon, $tbC1, $tbC2] = match ($tbPlanSlug) {
                        'starter'    => ['rocket',   '#38bdf8', '#0284c7'],
                        'growth'     => ['trending-up', '#34d399', '#059669'],
                        'scale'      => ['zap',      '#a78bfa', '#7c3aed'],
                        'enterprise' => ['award',    '#fbbf24', '#d97706'],
                        default      => ['gift',     '#94a3b8', '#64748b'],
                    };

                    // What to show as the date depends on the state, and using
                    // the wrong one is worse than showing nothing: a cancelled
                    // plan "renewing" on a date it will actually end is exactly
                    // the sort of thing people budget around.
                    $tbWhen = null; $tbWhenLabel = null;
                    if ($tbSub) {
                        if ($tbSub->onTrial() && $tbSub->trial_ends_at) {
                            $tbWhen = $tbSub->trial_ends_at; $tbWhenLabel = 'Trial ends';
                        } elseif ($tbSub->isFree()) {
                            $tbFreeLeft = $tbSub->freeDaysRemaining();
                            $tbWhenLabel = $tbFreeLeft !== null
                                ? ($tbFreeLeft > 0 ? "{$tbFreeLeft} days left" : 'Free window ended')
                                : null;
                        } elseif ($tbSub->cancel_at_period_end && $tbSub->current_period_end) {
                            $tbWhen = $tbSub->current_period_end; $tbWhenLabel = 'Ends';
                        } elseif ($tbSub->current_period_end) {
                            $tbWhen = $tbSub->current_period_end; $tbWhenLabel = 'Renews';
                        }
                    }
                    $tbNeedsAction = $tbSub && ! $tbSub->grantsAccess();
                @endphp

                @if ($tbClientForPlan)
                    <li class="tva-acct__plan">
                        <span class="tva-acct__badge" style="background: linear-gradient(135deg, {{ $tbC1 }}, {{ $tbC2 }});">
                            <i data-lucide="{{ $tbIcon }}" class="w-4 h-4"></i>
                        </span>
                        <span class="flex-1 min-w-0">
                            {{-- Plan name gets the full width. The status used
                                 to sit beside it as a badge, and the two
                                 fought: either the status clipped to
                                 "AWAITING PA…" or the name clipped to "G…".
                                 The name is the thing being looked up, so the
                                 status moved down to the meta line where it
                                 reads with the date anyway. --}}
                            <span class="tva-acct__plan-name">{{ $tbSub?->plan?->name ?? 'Free' }}</span>
                            <span class="tva-acct__plan-meta">
                                @if ($tbSub && $tbSub->status !== 'active')
                                    <em class="tva-acct__state is-{{ $tbSub->statusColor() }}">{{ $tbSub->statusLabel() }}</em>
                                    @if ($tbWhen) <span class="tva-acct__sep">·</span> @endif
                                @endif
                                @if ($tbWhen)
                                    {{ $tbWhenLabel }} {{ $tbWhen->format('j M Y') }}
                                @elseif ($tbWhenLabel && (! $tbSub || $tbSub->status === 'active'))
                                    {{ $tbWhenLabel }}
                                @elseif (! $tbSub)
                                    No renewal date
                                @endif
                            </span>
                        </span>
                        @if ($tbNeedsAction)
                            <i data-lucide="alert-circle" class="w-4 h-4 tva-acct__alert" title="{{ $tbSub->statusLabel() }}"></i>
                        @endif
                    </li>
                @endif

                <li>
                    <hr class="dropdown-divider border-white/[0.08]">
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="dropdown-item hover:bg-white/5"> <i data-lucide="user" class="w-4 h-4 mr-2"></i> View Profile </a>
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}#update-password" class="dropdown-item hover:bg-white/5"> <i data-lucide="lock" class="w-4 h-4 mr-2"></i> Change Password </a>
                </li>
                {{-- Billing lives here as well as in the sidebar: "where do I
                     see my plan?" is an account question, and the account menu
                     is the first place people look for it. Owner-only, matching
                     the route gate. --}}
                @php
                    $tbClient = request()->attributes->get('client') ?? Auth::user()->activeClient;
                @endphp
                @if ($tbClient && Auth::user()->isOwnerOf($tbClient->id))
                    <li>
                        <a href="{{ route('billing.index', ['client' => $tbClient->slug]) }}" class="dropdown-item hover:bg-white/5">
                            <i data-lucide="credit-card" class="w-4 h-4 mr-2"></i> Plan &amp; billing
                        </a>
                    </li>
                @endif
                <li>
                    <hr class="dropdown-divider border-white/[0.08]">
                </li>
                <li>
                    <a href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();" 
                        class="dropdown-item hover:bg-white/5"> <i data-lucide="toggle-right" class="w-4 h-4 mr-2"></i> Logout 
                    </a>
                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                        @csrf
                    </form>
                </li>
            </ul>
        </div>
    </div>
    <!-- END: Account Menu -->
</div>