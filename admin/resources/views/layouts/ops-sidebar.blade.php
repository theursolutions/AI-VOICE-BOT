<style>
    /* Section headings in the ops nav.
       They were text-white/60 at text-xs with a shared my-4 divider, which put
       the label and the rule at almost the same visual weight — so the eye read
       one undifferentiated column instead of grouped sections. Now the rule is
       faint, the label is brighter and tracked out, and the space above a group
       is larger than the space below it, which is what makes the label belong to
       the items under it rather than float between two lists. */
    .ops-nav-sep {
        height: 1px;
        margin: 22px 20px 0;
        background: linear-gradient(to right, rgba(255,255,255,.14), rgba(255,255,255,.02));
    }
    .ops-nav-group { padding: 14px 0 4px; }
    .ops-nav-group__label {
        padding-left: 20px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .13em;
        text-transform: uppercase;
        color: rgba(255,255,255,.55);
    }
    /* Collapsed rail (xl:down in this theme): the label would wrap to a stripe
       of unreadable letters, so it is hidden and only the rule survives. */
    @media (max-width: 1279px) {
        .ops-nav-group { padding: 8px 0 2px; }
        .ops-nav-group__label { display: none; }
    }
</style>

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
                <div class="side-menu__icon"><i data-lucide="bar-chart-2"></i></div>
                <div class="side-menu__title">Analytics</div>
            </a>
        </li>

        <li class="ops-nav-sep"></li>
        <li class="side-menu__title-section ops-nav-group">
            <div class="ops-nav-group__label">Activity</div>
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

        <li class="ops-nav-sep"></li>
        <li class="side-menu__title-section ops-nav-group">
            <div class="ops-nav-group__label">Resources</div>
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

        <li class="ops-nav-sep"></li>
        <li class="side-menu__title-section ops-nav-group">
            <div class="ops-nav-group__label">Platform</div>
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
        <li>
            <a href="{{ route('ops.ai-brains.index') }}" class="side-menu {{ $is('ops.ai-brains.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="cpu"></i></div>
                <div class="side-menu__title">AI Brains</div>
            </a>
        </li>

        <li class="ops-nav-sep"></li>
        <li class="side-menu__title-section ops-nav-group">
            <div class="ops-nav-group__label">Billing</div>
        </li>
        <li>
            <a href="{{ route('ops.billing.plans.index') }}" class="side-menu {{ $is('ops.billing.plans.*', 'ops.billing.prices.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="credit-card"></i></div>
                <div class="side-menu__title">Plans &amp; Pricing</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.billing.features.index') }}" class="side-menu {{ $is('ops.billing.features.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="sliders"></i></div>
                <div class="side-menu__title">Features &amp; Limits</div>
            </a>
        </li>
        <li>
            <a href="{{ route('ops.billing.subscriptions.index') }}" class="side-menu {{ $is('ops.billing.subscriptions.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="repeat"></i></div>
                <div class="side-menu__title">Subscriptions</div>
            </a>
        </li>

        <li class="ops-nav-sep"></li>
        <li class="side-menu__title-section ops-nav-group">
            <div class="ops-nav-group__label">Marketing Site</div>
        </li>
        <li>
            <a href="{{ route('ops.visitors.index') }}" class="side-menu {{ $is('ops.visitors.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="globe"></i></div>
                <div class="side-menu__title">Visitors</div>
            </a>
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
        <li>
            <a href="{{ route('ops.blog.index') }}" class="side-menu {{ $is('ops.blog.*') ? 'side-menu--active' : '' }}">
                <div class="side-menu__icon"><i data-lucide="book-open"></i></div>
                <div class="side-menu__title">Articles</div>
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
