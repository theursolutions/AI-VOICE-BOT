@php
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));
    $sec = [
        'ai'      => $is('bot-strategy.*', 'brain-settings.*'),
        'data'    => $is('data-sources.*', 'voices.*', 'telephony.*'),
        'project' => $is('bot-agents.*', 'skills.*', 'widget-settings.*', 'project-profile.*'),
        'crm'     => $is('sessions.*', 'leads.*'),
    ];
    $client = request()->route('client');
    $clientSlug = is_object($client) ? $client->slug : ($client ?? null);
@endphp

<div class="mobile-menu md:hidden">
    <div class="mobile-menu-bar">
        <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/') }}" class="flex mr-auto">
            <img alt="NueraBot" class="w-6" src="{{ url('/assets/dist/images/logo.svg') }}">
            <span class="text-white text-base ml-2 font-semibold">NueraBot</span>
        </a>
        <a href="javascript:;" class="mobile-menu-toggler">
            <i data-lucide="bar-chart-2" class="w-8 h-8 text-white transform -rotate-90"></i>
        </a>
    </div>

    <div class="scrollable">
        <a href="javascript:;" class="mobile-menu-toggler">
            <i data-lucide="x-circle" class="w-8 h-8 text-white transform -rotate-90"></i>
        </a>
        <ul class="scrollable__content py-2">
            {{-- Dashboard --}}
            <li>
                <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/dashboard') }}"
                   class="menu {{ $is('dashboard', 'onboard') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="home"></i></div>
                    <div class="menu__title">Dashboard</div>
                </a>
            </li>

            <li class="menu__devider my-4"></li>

            {{-- AI Automation --}}
            <li>
                <a href="javascript:;" class="menu {{ $sec['ai'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="cpu"></i></div>
                    <div class="menu__title">
                        AI Automation
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['ai'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['ai'] ? 'menu__sub-open' : '' }}">
                    <li>
                        <a href="{{ $clientSlug ? route('bot-strategy.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('bot-strategy.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="layers"></i></div>
                            <div class="menu__title">Bot Strategy</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('brain-settings.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('brain-settings.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="bot"></i></div>
                            <div class="menu__title">Brain &amp; Compute</div>
                        </a>
                    </li>
                </ul>
            </li>

            {{-- Data Connectivity --}}
            <li>
                <a href="javascript:;" class="menu {{ $sec['data'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="link"></i></div>
                    <div class="menu__title">
                        Data Connectivity
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['data'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['data'] ? 'menu__sub-open' : '' }}">
                    <li>
                        <a href="{{ $clientSlug ? route('data-sources.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('data-sources.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="database"></i></div>
                            <div class="menu__title">Data Sources</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('voices.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('voices.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="mic"></i></div>
                            <div class="menu__title">Voices</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('telephony.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('telephony.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="phone"></i></div>
                            <div class="menu__title">Telephony</div>
                        </a>
                    </li>
                </ul>
            </li>

            {{-- Project Data --}}
            <li>
                <a href="javascript:;" class="menu {{ $sec['project'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="folder"></i></div>
                    <div class="menu__title">
                        Project Data
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['project'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['project'] ? 'menu__sub-open' : '' }}">
                    <li>
                        <a href="{{ $clientSlug ? route('project-profile.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('project-profile.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="image"></i></div>
                            <div class="menu__title">Project Profile</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('bot-agents.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('bot-agents.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="users"></i></div>
                            <div class="menu__title">Agents</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('skills.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('skills.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="tag"></i></div>
                            <div class="menu__title">Skills</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('widget-settings.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('widget-settings.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="layout-template"></i></div>
                            <div class="menu__title">Widget</div>
                        </a>
                    </li>
                </ul>
            </li>

            {{-- CRM --}}
            <li>
                <a href="javascript:;" class="menu {{ $sec['crm'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="briefcase"></i></div>
                    <div class="menu__title">
                        CRM
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['crm'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['crm'] ? 'menu__sub-open' : '' }}">
                    <li>
                        <a href="{{ $clientSlug ? route('sessions.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('sessions.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="message-square"></i></div>
                            <div class="menu__title">Conversations</div>
                        </a>
                    </li>
                    <li>
                        <a href="{{ $clientSlug ? route('leads.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('leads.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="user-check"></i></div>
                            <div class="menu__title">Leads</div>
                        </a>
                    </li>
                </ul>
            </li>

            <li class="menu__devider my-4"></li>

            {{-- Team --}}
            <li>
                <a href="{{ route('invitations.index') }}"
                   class="menu {{ $is('invitations.*') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="user-plus"></i></div>
                    <div class="menu__title">Invitations</div>
                </a>
            </li>
        </ul>
    </div>
</div>
