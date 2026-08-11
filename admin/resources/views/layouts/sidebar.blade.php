@php
    // Helper: check if any of the given route names is the current route.
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));

    // Section open-state — used to expand the parent menu that holds the
    // active route, and to rotate its chevron.
    $sec = [
        'social'   => $is('chat.*', 'channels.*'),
        'ai'       => $is('bot-strategy.*', 'brain-settings.*'),
        'data'     => $is('data-sources.*', 'voices.*', 'telephony.*'),
        'project'  => $is('bot-agents.*', 'skills.*', 'widget-settings.*', 'project-profile.*', 'flows.*'),
        'crm'      => $is('sessions.*', 'leads.*'),
        'team'     => $is('members.*', 'roles.*', 'invitations.*'),
    ];

    $client = request()->route('client');
    $clientSlug = is_object($client) ? $client->slug : ($client ?? null);

    // On non-client-scoped pages (e.g. /profile) there's no {client} in the
    // route, so fall back to the user's active workspace. This keeps the nav
    // links working and gives client-scoped route() calls a value to bind.
    if (!$clientSlug && Auth::check()) {
        $clientSlug = optional(Auth::user()->activeClient)->slug;
    }

    // Until the email is verified, the workspace is locked to Ask AI only
    // (mirrors the EnsureEmailVerified route gate).
    $emailOk = !Auth::check() || Auth::user()->hasVerifiedEmail();

    // Two things can lock the menu. Provisioning comes FIRST: before a project
    // exists every link below is refused by `workspace.provisioned` and
    // redirected to /setup, so pointing an unverified user at "verify your
    // email" would send them to the second step of a two-step wall.
    $workspaceReady = $tvaWorkspaceReady ?? true;
    $lock = null;
    if (!$workspaceReady && $clientSlug) {
        $lock = [
            'href' => route('setup', ['client' => $clientSlug]),
            'text' => 'Initialize your workspace to unlock these features',
            'title'=> 'Finish setting up your workspace first',
        ];
    } elseif (!$emailOk) {
        $lock = [
            'href' => route('verification.notice'),
            'text' => 'Verify your email to unlock the amazing features',
            'title'=> 'Verify your email to unlock these features',
        ];
    }

    // Role-based module visibility. $tvaModules is shared by the
    // layouts.master view composer (owner = all modules). Default to all
    // so non-client / edge contexts never hide everything.
    $mods = $tvaModules ?? array_keys((array) config('modules', []));

    // Visibility depends ONLY on the role's module grants. Email verification no
    // longer hides items: unverified users see the full menu behind a blurred,
    // non-interactive "verify to unlock" veil (below), so they can tell what
    // they're unlocking instead of facing a three-item stub that looks broken.
    //
    // Safe because the veil is presentation only and the real gate is
    // server-side (EnsureEmailVerified middleware) — a visible link an
    // unverified user clicks still gets refused by the route.
    $can  = function (string $k) use ($mods) {
        return in_array($k, $mods, true);
    };

    // Section visibility — a collapsible group shows only if the member can
    // reach at least one of its children.
    $showSocial    = $can('messages') || $can('channels');
    $showAi        = $can('bot_strategy');
    $showData      = $can('data_sources') || $can('voices') || $can('telephony');
    $showProject   = $can('profile') || $can('agents') || $can('skills') || $can('flows') || $can('widget');
    $showCrm       = $can('conversations') || $can('leads');
    $showWorkspace = $showAi || $showData || $showProject || $showCrm;
@endphp

<nav class="side-nav">
    <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/') }}" class="intro-x flex items-center pl-5 pt-4">
        <img alt="Serve AI" class="w-6" src="{{ serveai_icon() }}">
        <span class="hidden xl:block text-white text-lg ml-3">Serve AI</span>
    </a>
    <div class="side-nav__devider my-6"></div>

    <ul>
        {{-- Dashboard (standalone) — always available, incl. unverified users --}}
        <li>
            <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/dashboard') }}"
               class="side-menu {{ $is('dashboard', 'onboard') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="home"></i></div>
                <div class="side-menu__title">Dashboard</div>
            </a>
        </li>

        {{-- Verify email prompt (only while unverified) --}}
        @unless($emailOk)
        <li>
            <a href="{{ route('verification.notice') }}"
               class="side-menu {{ $is('verification.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="mail"></i></div>
                <div class="side-menu__title">Verify email</div>
            </a>
        </li>
        @endunless

        {{-- Team Assistant (internal AI, RBAC project-scoped) --}}
        @if($can('assistant'))
        <li>
            <a href="{{ $clientSlug ? route('assistant.index', ['client' => $clientSlug]) : '#' }}"
               class="side-menu {{ $is('assistant.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="bot"></i></div>
                <div class="side-menu__title">Ask AI</div>
            </a>
        </li>
        @endif

        {{-- ── Email-verification gate ─────────────────────────────────────────
             Everything below stays VISIBLE but veiled until the user verifies
             their email, so they can see what they're unlocking. Dashboard and
             Ask AI above remain usable so the account isn't a dead end.

             This is presentation only — the real enforcement is server-side
             (EnsureEmailVerified middleware). A blurred link is still a link,
             so pointer-events:none here is convenience, not security. --}}
        @if ($lock)
        <style>
            /* `display:block` + `position:relative` so the veil's inset:0 has a
               real box to resolve against. Without an explicit display the div
               sits inside a <ul> and can collapse, which leaves the veil sized
               to nothing and its centred content floating at the top. */
            .tva-lock { position:relative; display:block; }
            .tva-lock__items { filter:blur(3px); opacity:.40; pointer-events:none; user-select:none; }
            .tva-lock__veil {
                position:absolute;
                top:0; left:0; right:0; bottom:0;   /* explicit, not just inset:0 */
                width:100%; height:100%;
                z-index:5;
                display:flex; flex-direction:column;
                align-items:center;                  /* horizontal centre */
                justify-content:center;              /* vertical centre   */
                gap:10px; text-align:center; padding:18px; text-decoration:none;
                box-sizing:border-box;
            }
            .tva-lock__veil:hover .tva-lock__cta { text-decoration:underline; }
            .tva-lock__icon {
                width:44px; height:44px; border-radius:50%;
                display:flex; align-items:center; justify-content:center;
                background:rgba(239,68,68,.14); border:1px solid rgba(239,68,68,.45);
                color:#ef4444; flex-shrink:0;
            }
            .tva-lock__cta { color:#fecaca; font-size:11.5px; line-height:1.45; font-weight:500; max-width:170px; }
        </style>
        <div class="tva-lock">
            <a class="tva-lock__veil" href="{{ $lock['href'] }}" title="{{ $lock['title'] }}">
                <span class="tva-lock__icon"><i data-lucide="lock" class="w-5 h-5"></i></span>
                <span class="tva-lock__cta">{{ $lock['text'] }}</span>
            </a>
            <div class="tva-lock__items">
        @endif

        {{-- Live Compute (was "Compute Mesh") — directly after Ask AI --}}
        @if($can('compute'))
        <li>
            <a href="{{ $clientSlug ? route('compute.index', ['client' => $clientSlug]) : '#' }}"
               class="side-menu {{ $is('compute.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="cpu"></i></div>
                <div class="side-menu__title">Live Compute</div>
            </a>
        </li>
        @endif

        {{-- Social — agent inbox (Messages) + channel onboarding (Channels) --}}
        @if($showSocial)
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['social'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="share-2"></i></div>
                <div class="side-menu__title">
                    Social
                    <div class="side-menu__sub-icon {{ $sec['social'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['social'] ? 'side-menu__sub-open' : '' }}">
                @if($can('messages'))
                <li>
                    <a href="{{ $clientSlug ? route('chat.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('chat.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="message-square"></i></div>
                        <div class="side-menu__title">Messages</div>
                    </a>
                </li>
                @endif
                @if($can('channels'))
                <li>
                    <a href="{{ $clientSlug ? route('channels.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('channels.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="radio"></i></div>
                        <div class="side-menu__title">Channels</div>
                    </a>
                </li>
                @endif
            </ul>
        </li>
        @endif

        @if($showWorkspace)
        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">
                Workspace
            </div>
        </li>
        @endif

        {{-- AI Automation --}}
        @if($showAi)
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['ai'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="cpu"></i></div>
                <div class="side-menu__title">
                    AI Automation
                    <div class="side-menu__sub-icon {{ $sec['ai'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['ai'] ? 'side-menu__sub-open' : '' }}">
                <li>
                    <a href="{{ $clientSlug ? route('bot-strategy.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('bot-strategy.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="layers"></i></div>
                        <div class="side-menu__title">Bot Strategy</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('brain-settings.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('brain-settings.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="bot"></i></div>
                        <div class="side-menu__title">Brain &amp; Compute</div>
                    </a>
                </li>
            </ul>
        </li>
        @endif

        {{-- Data Connectivity --}}
        @if($showData)
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['data'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="link"></i></div>
                <div class="side-menu__title">
                    Data Connectivity
                    <div class="side-menu__sub-icon {{ $sec['data'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['data'] ? 'side-menu__sub-open' : '' }}">
                @if($can('data_sources'))
                <li>
                    <a href="{{ $clientSlug ? route('data-sources.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('data-sources.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="database"></i></div>
                        <div class="side-menu__title">Data Sources</div>
                    </a>
                </li>
                @endif
                @if($can('voices'))
                <li>
                    <a href="{{ $clientSlug ? route('voices.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('voices.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="mic"></i></div>
                        <div class="side-menu__title">Voices</div>
                    </a>
                </li>
                @endif
                @if($can('telephony'))
                <li>
                    <a href="{{ $clientSlug ? route('telephony.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('telephony.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="phone"></i></div>
                        <div class="side-menu__title">Telephony</div>
                    </a>
                </li>
                @endif
            </ul>
        </li>
        @endif

        {{-- Project Data --}}
        @if($showProject)
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['project'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="folder"></i></div>
                <div class="side-menu__title">
                    Project Data
                    <div class="side-menu__sub-icon {{ $sec['project'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['project'] ? 'side-menu__sub-open' : '' }}">
                @if($can('profile'))
                <li>
                    <a href="{{ $clientSlug ? route('project-profile.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('project-profile.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="image"></i></div>
                        <div class="side-menu__title">Project Profile</div>
                    </a>
                </li>
                @endif
                @if($can('agents'))
                <li>
                    <a href="{{ $clientSlug ? route('bot-agents.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('bot-agents.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="users"></i></div>
                        <div class="side-menu__title">Agents</div>
                    </a>
                </li>
                @endif
                @if($can('skills'))
                <li>
                    <a href="{{ $clientSlug ? route('skills.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('skills.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="tag"></i></div>
                        <div class="side-menu__title">Skills</div>
                    </a>
                </li>
                @endif
                @if($can('flows'))
                <li>
                    <a href="{{ $clientSlug ? route('flows.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('flows.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="git-branch"></i></div>
                        <div class="side-menu__title">Flow builder</div>
                    </a>
                </li>
                @endif
                @if($can('widget'))
                <li>
                    <a href="{{ $clientSlug ? route('widget-settings.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('widget-settings.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="layout-template"></i></div>
                        <div class="side-menu__title">Widget</div>
                    </a>
                </li>
                @endif
            </ul>
        </li>
        @endif

        {{-- CRM --}}
        @if($showCrm)
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['crm'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="briefcase"></i></div>
                <div class="side-menu__title">
                    CRM
                    <div class="side-menu__sub-icon {{ $sec['crm'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['crm'] ? 'side-menu__sub-open' : '' }}">
                @if($can('conversations'))
                <li>
                    <a href="{{ $clientSlug ? route('sessions.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('sessions.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="message-square"></i></div>
                        <div class="side-menu__title">Conversations</div>
                    </a>
                </li>
                @endif
                @if($can('leads'))
                <li>
                    <a href="{{ $clientSlug ? route('leads.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('leads.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="user-check"></i></div>
                        <div class="side-menu__title">Leads</div>
                    </a>
                </li>
                @endif
            </ul>
        </li>
        @endif

        {{-- Team & Roles — Members + Roles + Invitations in one group --}}
        @if($can('team'))
        <li class="side-nav__devider my-4"></li>
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['team'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="users"></i></div>
                <div class="side-menu__title">
                    Team &amp; Roles
                    <div class="side-menu__sub-icon {{ $sec['team'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['team'] ? 'side-menu__sub-open' : '' }}">
                <li>
                    <a href="{{ $clientSlug ? route('members.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('members.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="users"></i></div>
                        <div class="side-menu__title">Members</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('roles.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('roles.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="shield"></i></div>
                        <div class="side-menu__title">Roles</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('invitations.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('invitations.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="user-plus"></i></div>
                        <div class="side-menu__title">Invitations</div>
                    </a>
                </li>
            </ul>
        </li>
        @endif

        {{-- ── Billing ──────────────────────────────────────────────────
             Deliberately NOT behind a module key: /billing has to stay
             reachable when the workspace is read-only, otherwise the
             paywall has no way through it. Owner-only, since only the
             owner can change what the workspace pays. --}}
        @if ($clientSlug && auth()->user()?->isOwnerOf(auth()->user()->active_client_id ?? 0))
        @php
            $billingSub = auth()->user()?->activeClient?->currentSubscription();
            $billingDue = $billingSub && ! $billingSub->grantsAccess();
            $freeLeft   = $billingSub?->isFree() ? $billingSub->freeDaysRemaining() : null;
        @endphp
        <li>
            <a href="{{ route('billing.index', ['client' => $clientSlug]) }}"
               class="side-menu {{ $is('billing.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="credit-card"></i></div>
                <div class="side-menu__title">
                    Billing
                    @if ($billingDue)
                        <span class="ml-2 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-red-500 text-white">Action needed</span>
                    @elseif ($freeLeft !== null && $freeLeft <= 3)
                        <span class="ml-2 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wide bg-amber-400 text-amber-950">{{ $freeLeft }}d left</span>
                    @endif
                </div>
            </a>
        </li>
        @endif

        @if ($lock)
            </div>{{-- .tva-lock__items --}}
        </div>{{-- .tva-lock --}}
        @endif
    </ul>
</nav>
