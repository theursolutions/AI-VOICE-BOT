@php
    $is = fn (...$names) => collect($names)->some(fn ($n) => request()->routeIs($n));
@endphp

<div class="mobile-menu md:hidden">
    <div class="mobile-menu-bar">
        <a href="{{ route('ops.overview') }}" class="flex mr-auto items-center gap-2">
            <img alt="Serve AI Ops" class="w-6" src="{{ serveai_icon() }}">
            <span class="text-white text-sm font-semibold">Serve AI</span>
            <span class="ops-internal-badge">Internal</span>
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
            <li><a href="{{ route('ops.overview') }}" class="menu {{ $is('ops.overview') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="home"></i></div>
                <div class="menu__title">Overview</div>
            </a></li>
            <li><a href="{{ route('ops.analytics.index') }}" class="menu {{ $is('ops.analytics.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="bar-chart-2"></i></div>
                <div class="menu__title">Analytics</div>
            </a></li>
            <li class="menu__devider my-4"></li>
            <li><a href="{{ route('ops.sessions.index') }}" class="menu {{ $is('ops.sessions.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="message-square"></i></div>
                <div class="menu__title">Conversations</div>
            </a></li>
            <li><a href="{{ route('ops.leads.index') }}" class="menu {{ $is('ops.leads.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="user-check"></i></div>
                <div class="menu__title">Leads</div>
            </a></li>
            <li class="menu__devider my-4"></li>
            <li><a href="{{ route('ops.voices.index') }}" class="menu {{ $is('ops.voices.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="mic"></i></div>
                <div class="menu__title">Voices</div>
            </a></li>
            <li><a href="{{ route('ops.telephony.index') }}" class="menu {{ $is('ops.telephony.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="phone"></i></div>
                <div class="menu__title">Telephony</div>
            </a></li>
            <li class="menu__devider my-4"></li>
            <li><a href="{{ route('ops.clients.index') }}" class="menu {{ $is('ops.clients.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="briefcase"></i></div>
                <div class="menu__title">Clients</div>
            </a></li>
            <li><a href="{{ route('ops.projects.index') }}" class="menu {{ $is('ops.projects.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="folder"></i></div>
                <div class="menu__title">Projects</div>
            </a></li>
            <li><a href="{{ route('ops.users.index') }}" class="menu {{ $is('ops.users.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="users"></i></div>
                <div class="menu__title">Users</div>
            </a></li>
            <li><a href="{{ route('ops.modules.index') }}" class="menu {{ $is('ops.modules.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="toggle-right"></i></div>
                <div class="menu__title">Modules</div>
            </a></li>
            <li class="menu__devider my-4"></li>
            <li><a href="{{ route('ops.contacts.index') }}" class="menu {{ $is('ops.contacts.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="phone-incoming"></i></div>
                <div class="menu__title">Website Contacts</div>
            </a></li>
            <li><a href="{{ route('ops.seo.index') }}" class="menu {{ $is('ops.seo.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="search"></i></div>
                <div class="menu__title">SEO</div>
            </a></li>
            <li><a href="{{ route('ops.content.index') }}" class="menu {{ $is('ops.content.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="layout-template"></i></div>
                <div class="menu__title">Page Content</div>
            </a></li>
            <li><a href="{{ route('ops.blog.index') }}" class="menu {{ $is('ops.blog.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="book-open"></i></div>
                <div class="menu__title">Articles</div>
            </a></li>
            <li class="menu__devider my-4"></li>
            <li><a href="{{ route('ops.audit.index') }}" class="menu {{ $is('ops.audit.*') ? 'menu--active' : '' }}">
                <div class="menu__icon"><i data-lucide="file-text"></i></div>
                <div class="menu__title">Audit log</div>
            </a></li>
        </ul>
    </div>
</div>
