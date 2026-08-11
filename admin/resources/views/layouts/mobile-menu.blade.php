@php
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));
    $sec = [
        'social'  => $is('chat.*', 'channels.*'),
        'ai'      => $is('bot-strategy.*', 'brain-settings.*'),
        'data'    => $is('data-sources.*', 'voices.*', 'telephony.*'),
        'project' => $is('bot-agents.*', 'skills.*', 'widget-settings.*', 'project-profile.*'),
        'crm'     => $is('sessions.*', 'leads.*'),
    ];
    $client = request()->route('client');
    $clientSlug = is_object($client) ? $client->slug : ($client ?? null);

    // Non-client pages (e.g. /profile) have no {client} in the route — fall
    // back to the user's active workspace so nav links still resolve.
    if (!$clientSlug && Auth::check()) {
        $clientSlug = optional(Auth::user()->activeClient)->slug;
    }

    // Unverified email → Ask AI only (mirrors sidebar + route gate).
    $emailOk = !Auth::check() || Auth::user()->hasVerifiedEmail();

    // Module visibility — use the SAME resolved list as the desktop sidebar
    // ($tvaModules, shared by the layouts.master composer), which is:
    //     the member's role grants
    //   ∩ modules a super-admin has switched on platform-wide
    //   ∩ modules the workspace's PLAN includes
    //
    // This previously called tva_module_enabled() alone, which only checks the
    // platform switchboard — so the mobile menu ignored both the member's role
    // and (once billing shipped) their plan. A member with a restricted role
    // could see every section here that the desktop sidebar correctly hid, and
    // clicking one just 403'd or 402'd.
    //
    // Falls back to the old behaviour when $tvaModules isn't shared (any
    // context outside layouts.master), so nothing can disappear unexpectedly.
    $mods  = $tvaModules ?? null;
    $modOn = $mods === null
        ? fn (string $k) => tva_module_enabled($k)
        : fn (string $k) => in_array($k, $mods, true);
@endphp

<div class="mobile-menu md:hidden">
    <div class="mobile-menu-bar">
        <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/') }}" class="flex mr-auto">
            <img alt="Serve AI" class="w-6" src="{{ serveai_icon() }}">
            <span class="text-white text-base ml-2 font-semibold">Serve AI</span>
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
            @unless($emailOk)
            {{-- Unverified email: Dashboard + Ask AI + the verify prompt --}}
            <li>
                <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/dashboard') }}"
                   class="menu {{ $is('dashboard', 'onboard') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="home"></i></div>
                    <div class="menu__title">Dashboard</div>
                </a>
            </li>
            <li>
                <a href="{{ route('verification.notice') }}" class="menu">
                    <div class="menu__icon"><i data-lucide="mail"></i></div>
                    <div class="menu__title">Verify email</div>
                </a>
            </li>
            <li>
                <a href="{{ $clientSlug ? route('assistant.index', ['client' => $clientSlug]) : '#' }}"
                   class="menu {{ $is('assistant.*') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="bot"></i></div>
                    <div class="menu__title">Ask AI</div>
                </a>
            </li>
            @else
            {{-- Dashboard --}}
            <li>
                <a href="{{ $clientSlug ? route('dashboard', ['client' => $clientSlug]) : url('/dashboard') }}"
                   class="menu {{ $is('dashboard', 'onboard') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="home"></i></div>
                    <div class="menu__title">Dashboard</div>
                </a>
            </li>
            {{-- Social — Messages + Channels --}}
            @if($modOn('messages') || $modOn('channels'))
            <li>
                <a href="javascript:;" class="menu {{ $sec['social'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="share-2"></i></div>
                    <div class="menu__title">
                        Social
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['social'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['social'] ? 'menu__sub-open' : '' }}">
                    @if($modOn('messages'))
                    <li>
                        <a href="{{ $clientSlug ? route('chat.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('chat.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="message-square"></i></div>
                            <div class="menu__title">Messages</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('channels'))
                    <li>
                        <a href="{{ $clientSlug ? route('channels.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('channels.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="radio"></i></div>
                            <div class="menu__title">Channels</div>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>

            <li class="menu__devider my-4"></li>
            @endif

            {{-- AI Automation --}}
            @if($modOn('bot_strategy'))
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
            @endif

            {{-- Data Connectivity --}}
            @if($modOn('data_sources') || $modOn('voices') || $modOn('telephony'))
            <li>
                <a href="javascript:;" class="menu {{ $sec['data'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="link"></i></div>
                    <div class="menu__title">
                        Data Connectivity
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['data'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['data'] ? 'menu__sub-open' : '' }}">
                    @if($modOn('data_sources'))
                    <li>
                        <a href="{{ $clientSlug ? route('data-sources.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('data-sources.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="database"></i></div>
                            <div class="menu__title">Data Sources</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('voices'))
                    <li>
                        <a href="{{ $clientSlug ? route('voices.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('voices.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="mic"></i></div>
                            <div class="menu__title">Voices</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('telephony'))
                    <li>
                        <a href="{{ $clientSlug ? route('telephony.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('telephony.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="phone"></i></div>
                            <div class="menu__title">Telephony</div>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @endif

            {{-- Project Data --}}
            @if($modOn('profile') || $modOn('agents') || $modOn('skills') || $modOn('widget'))
            <li>
                <a href="javascript:;" class="menu {{ $sec['project'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="folder"></i></div>
                    <div class="menu__title">
                        Project Data
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['project'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['project'] ? 'menu__sub-open' : '' }}">
                    @if($modOn('profile'))
                    <li>
                        <a href="{{ $clientSlug ? route('project-profile.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('project-profile.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="image"></i></div>
                            <div class="menu__title">Project Profile</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('agents'))
                    <li>
                        <a href="{{ $clientSlug ? route('bot-agents.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('bot-agents.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="users"></i></div>
                            <div class="menu__title">Agents</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('skills'))
                    <li>
                        <a href="{{ $clientSlug ? route('skills.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('skills.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="tag"></i></div>
                            <div class="menu__title">Skills</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('widget'))
                    <li>
                        <a href="{{ $clientSlug ? route('widget-settings.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('widget-settings.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="layout-template"></i></div>
                            <div class="menu__title">Widget</div>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @endif

            {{-- CRM --}}
            @if($modOn('conversations') || $modOn('leads'))
            <li>
                <a href="javascript:;" class="menu {{ $sec['crm'] ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="briefcase"></i></div>
                    <div class="menu__title">
                        CRM
                        <i data-lucide="chevron-down" class="menu__sub-icon {{ $sec['crm'] ? 'transform rotate-180' : '' }}"></i>
                    </div>
                </a>
                <ul class="{{ $sec['crm'] ? 'menu__sub-open' : '' }}">
                    @if($modOn('conversations'))
                    <li>
                        <a href="{{ $clientSlug ? route('sessions.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('sessions.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="message-square"></i></div>
                            <div class="menu__title">Conversations</div>
                        </a>
                    </li>
                    @endif
                    @if($modOn('leads'))
                    <li>
                        <a href="{{ $clientSlug ? route('leads.index', ['client' => $clientSlug]) : '#' }}"
                           class="menu {{ $is('leads.*') ? 'menu--active' : '' }}">
                            <div class="menu__icon"><i data-lucide="user-check"></i></div>
                            <div class="menu__title">Leads</div>
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @endif

            <li class="menu__devider my-4"></li>

            {{-- Team --}}
            @if($modOn('team'))
            <li>
                <a href="{{ $clientSlug ? route('invitations.index', ['client' => $clientSlug]) : '#' }}"
                   class="menu {{ $is('invitations.*') ? 'menu--active' : '' }}">
                    <div class="menu__icon"><i data-lucide="user-plus"></i></div>
                    <div class="menu__title">Invitations</div>
                </a>
            </li>
            @endif
            @endunless
        </ul>
    </div>
</div>
