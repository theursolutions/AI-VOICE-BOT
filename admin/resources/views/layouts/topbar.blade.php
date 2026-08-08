<style>
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
        <div class="dropdown-menu w-56">
            <ul class="dropdown-content bg-primary text-white">
                <li class="p-2">
                    <div class="font-medium">{{ Auth::user()->name }}</div>
                    <div class="text-xs text-white/70 mt-0.5 dark:text-slate-500">{{ Auth::user()->email }}</div>
                </li>
                <li>
                    <hr class="dropdown-divider border-white/[0.08]">
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}" class="dropdown-item hover:bg-white/5"> <i data-lucide="user" class="w-4 h-4 mr-2"></i> View Profile </a>
                </li>
                <li>
                    <a href="{{ route('profile.edit') }}#update-password" class="dropdown-item hover:bg-white/5"> <i data-lucide="lock" class="w-4 h-4 mr-2"></i> Change Password </a>
                </li>
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