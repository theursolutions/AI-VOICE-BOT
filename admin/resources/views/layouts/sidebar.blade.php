@php
    // Helper: check if any of the given route names is the current route.
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));

    // Section open-state — used to expand the parent menu that holds the
    // active route, and to rotate its chevron.
    $sec = [
        'ai'       => $is('bot-strategy.*', 'brain-settings.*'),
        'data'     => $is('data-sources.*', 'voices.*', 'telephony.*'),
        'project'  => $is('bot-agents.*', 'skills.*', 'widget-settings.*', 'project-profile.*'),
        'crm'      => $is('sessions.*', 'leads.*'),
        'members'  => $is('invitations.*'),
    ];

    $client = request()->route('client');
    $clientSlug = is_object($client) ? $client->slug : ($client ?? null);
@endphp

<nav class="side-nav">
    <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/') }}" class="intro-x flex items-center pl-5 pt-4">
        <img alt="NueraBot" class="w-6" src="{{ url('/assets/dist/images/logo.svg') }}">
        <span class="hidden xl:block text-white text-lg ml-3">NueraBot</span>
    </a>
    <div class="side-nav__devider my-6"></div>

    <ul>
        {{-- Dashboard (standalone) --}}
        <li>
            <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/dashboard') }}"
               class="side-menu {{ $is('dashboard', 'onboard') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="home"></i></div>
                <div class="side-menu__title">Dashboard</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">
                Workspace
            </div>
        </li>

        {{-- AI Automation --}}
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

        {{-- Data Connectivity --}}
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['data'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="link"></i></div>
                <div class="side-menu__title">
                    Data Connectivity
                    <div class="side-menu__sub-icon {{ $sec['data'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['data'] ? 'side-menu__sub-open' : '' }}">
                <li>
                    <a href="{{ $clientSlug ? route('data-sources.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('data-sources.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="database"></i></div>
                        <div class="side-menu__title">Data Sources</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('voices.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('voices.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="mic"></i></div>
                        <div class="side-menu__title">Voices</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('telephony.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('telephony.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="phone"></i></div>
                        <div class="side-menu__title">Telephony</div>
                    </a>
                </li>
            </ul>
        </li>

        {{-- Project Data --}}
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['project'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="folder"></i></div>
                <div class="side-menu__title">
                    Project Data
                    <div class="side-menu__sub-icon {{ $sec['project'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['project'] ? 'side-menu__sub-open' : '' }}">
                <li>
                    <a href="{{ $clientSlug ? route('project-profile.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('project-profile.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="image"></i></div>
                        <div class="side-menu__title">Project Profile</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('bot-agents.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('bot-agents.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="users"></i></div>
                        <div class="side-menu__title">Agents</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('skills.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('skills.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="tag"></i></div>
                        <div class="side-menu__title">Skills</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('widget-settings.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('widget-settings.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="layout-template"></i></div>
                        <div class="side-menu__title">Widget</div>
                    </a>
                </li>
            </ul>
        </li>

        {{-- CRM --}}
        <li>
            <a href="javascript:;" class="side-menu {{ $sec['crm'] ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="briefcase"></i></div>
                <div class="side-menu__title">
                    CRM
                    <div class="side-menu__sub-icon {{ $sec['crm'] ? 'transform rotate-180' : '' }}"><i data-lucide="chevron-down"></i></div>
                </div>
            </a>
            <ul class="{{ $sec['crm'] ? 'side-menu__sub-open' : '' }}">
                <li>
                    <a href="{{ $clientSlug ? route('sessions.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('sessions.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="message-square"></i></div>
                        <div class="side-menu__title">Conversations</div>
                    </a>
                </li>
                <li>
                    <a href="{{ $clientSlug ? route('leads.index', ['client' => $clientSlug]) : '#' }}"
                       class="side-menu {{ $is('leads.*') ? 'side-menu--active' : '' }}">
                        <div class="side-menu__icon"><i data-lucide="user-check"></i></div>
                        <div class="side-menu__title">Leads</div>
                    </a>
                </li>
            </ul>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">
                Team
            </div>
        </li>
        <li>
            <a href="{{ route('invitations.index') }}"
               class="side-menu {{ $is('invitations.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="user-plus"></i></div>
                <div class="side-menu__title">Invitations</div>
            </a>
        </li>
    </ul>
</nav>
