@php
    // Helper — mark active section/link by current route name.
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));

    $sec = [
        'analytics' => $is('ops.analytics.*', 'ops.overview'),
        'activity'  => $is('ops.sessions.*', 'ops.leads.*'),
        'resources' => $is('ops.voices.*', 'ops.telephony.*'),
        'platform'  => $is('ops.clients.*', 'ops.projects.*', 'ops.users.*'),
        'audit'     => $is('ops.audit.*'),
    ];
@endphp

<nav class="side-nav">
    <a href="{{ route('ops.overview') }}" class="intro-x flex items-center pl-5 pt-4">
        <img alt="Serve AI Ops" class="w-6" src="{{ serveai_icon() }}">
        <span class="hidden xl:block text-white text-lg ml-3">Serve AI</span>
        <span class="hidden xl:inline-block ops-internal-badge ml-2">Internal</span>
    </a>
    <div class="side-nav__devider my-6"></div>

    <ul>
        <li>
            <a href="{{ route('ops.overview') }}" class="side-menu {{ $is('ops.overview') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="home"></i></div>
                <div class="side-menu__title">Overview</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.analytics.index') }}" class="side-menu {{ $is('ops.analytics.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="bar-chart-3"></i></div>
                <div class="side-menu__title">Analytics</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">Activity</div>
        </li>
        <li>
            <a href="{{ route('ops.sessions.index') }}" class="side-menu {{ $is('ops.sessions.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="message-square"></i></div>
                <div class="side-menu__title">Conversations</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.leads.index') }}" class="side-menu {{ $is('ops.leads.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="user-check"></i></div>
                <div class="side-menu__title">Leads</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">Resources</div>
        </li>
        <li>
            <a href="{{ route('ops.voices.index') }}" class="side-menu {{ $is('ops.voices.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="mic"></i></div>
                <div class="side-menu__title">Voices</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.telephony.index') }}" class="side-menu {{ $is('ops.telephony.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="phone"></i></div>
                <div class="side-menu__title">Telephony</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">Platform</div>
        </li>
        <li>
            <a href="{{ route('ops.clients.index') }}" class="side-menu {{ $is('ops.clients.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="briefcase"></i></div>
                <div class="side-menu__title">Clients</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.projects.index') }}" class="side-menu {{ $is('ops.projects.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="folder"></i></div>
                <div class="side-menu__title">Projects</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.users.index') }}" class="side-menu {{ $is('ops.users.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="users"></i></div>
                <div class="side-menu__title">Users</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.modules.index') }}" class="side-menu {{ $is('ops.modules.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="toggle-right"></i></div>
                <div class="side-menu__title">Modules</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li class="side-menu__title-section">
            <div class="side-menu__title text-white/60 text-xs uppercase tracking-wider pl-5">Marketing Site</div>
        </li>
        <li>
            <a href="{{ route('ops.contacts.index') }}" class="side-menu {{ $is('ops.contacts.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="phone-incoming"></i></div>
                <div class="side-menu__title">Website Contacts</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.seo.index') }}" class="side-menu {{ $is('ops.seo.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="search"></i></div>
                <div class="side-menu__title">SEO</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.content.index') }}" class="side-menu {{ $is('ops.content.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="layout-template"></i></div>
                <div class="side-menu__title">Page Content</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.testimonials.index') }}" class="side-menu {{ $is('ops.testimonials.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="quote"></i></div>
                <div class="side-menu__title">Testimonials</div>
            </a>
        </li>

        <li class="side-nav__devider my-4"></li>
        <li>
            <a href="{{ route('ops.audit.index') }}" class="side-menu {{ $is('ops.audit.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="file-text"></i></div>
                <div class="side-menu__title">Audit log</div>
            </a>
        </li>
        <li>
            <a href="{{ url('/dashboard') }}" class="side-menu">
                <div class="side-menu__icon"><i data-lucide="log-out"></i></div>
                <div class="side-menu__title">Exit to user app</div>
            </a>
        </li>
    </ul>
</nav>
