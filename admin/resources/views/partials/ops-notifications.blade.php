{{-- Super-admin notification bell: new users, clients, projects, website
     contacts and payments, one tab each (App\Services\Ops\OpsAlerts writes them).
     Used in the ops top bar and, for super admins, the customer top bar. --}}
@php
    $tvaNotiCounts = \App\Models\OpsNotification::unreadCounts((int) auth()->id());
    $tvaNotiTabs   = ['' => 'All', 'user' => 'Users', 'client' => 'Clients', 'project' => 'Projects',
                      'contact' => 'Contacts', 'payment' => 'Payments'];
@endphp
<style>
    .tva-noti .notification { position: relative; }
    .tva-noti__badge { position:absolute; top:-7px; right:-9px; min-width:18px; height:18px; padding:0 5px; border-radius:999px;
        background:#ef4444; color:#fff; font-size:10.5px; font-weight:700; line-height:18px; text-align:center; box-shadow:0 0 0 2px var(--tva-surface, #fff); }
    .tva-noti .notification-content { width: 400px; max-width: calc(100vw - 24px); }
    .tva-noti__box { padding: 14px 0 6px !important; }
    .tva-noti__head { display:flex; align-items:center; gap:8px; padding:0 16px 10px; }
    .tva-noti__head .notification-content__title { margin:0 !important; flex:1; }
    .tva-noti__link { background:none; border:0; padding:0; font-size:12px; font-weight:600; color:#2563eb; cursor:pointer; text-decoration:none; }
    .tva-noti__tabs { display:flex; flex-wrap:wrap; gap:6px; padding:0 16px 10px; border-bottom:1px solid var(--tva-border, #e4e7ec); }
    .tva-noti__tab { flex:0 0 auto; display:inline-flex; align-items:center; gap:5px; border:1px solid var(--tva-border, #e4e7ec); background:transparent;
        color:var(--tva-text-2, #475467); border-radius:999px; padding:4px 9px; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; }
    .tva-noti__tab.is-on { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
    .tva-noti__tab b { font-size:10.5px; background:#ef4444; color:#fff; border-radius:999px; padding:0 6px; line-height:16px; }
    .tva-noti__list { max-height: 380px; overflow-y:auto; }
    .tva-noti__item { display:flex; gap:11px; padding:11px 16px; text-decoration:none; color:inherit; border-bottom:1px solid var(--tva-border, #eef0f3); }
    .tva-noti__item:hover { background: var(--tva-hover, #f2f4f7); }
    .tva-noti__item.is-unread { background: rgba(37, 99, 235, .06); }
    .tva-noti__ico { flex:0 0 34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
    .tva-noti__ico svg { width:17px; height:17px; }
    .tva-noti__t { font-size:13px; font-weight:600; color:var(--tva-text, #16202e); line-height:1.35; }
    .tva-noti__item.is-unread .tva-noti__t::after { content:""; display:inline-block; width:7px; height:7px; border-radius:50%; background:#2563eb; margin-left:6px; vertical-align:middle; }
    .tva-noti__b { font-size:12px; color:var(--tva-text-2, #475467); margin-top:2px; line-height:1.4; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
    .tva-noti__time { font-size:11px; color:#94a3b8; margin-top:3px; }
    .tva-noti__empty { padding:28px 16px; text-align:center; font-size:12px; color:#94a3b8; }
    .tva-noti__foot { display:block; text-align:center; padding:10px 16px 6px; font-size:12.5px; font-weight:600; color:#2563eb; }
</style>

<div class="intro-x dropdown mr-4 sm:mr-6 tva-noti" id="tva-noti"
     data-feed="{{ route('ops.notifications.feed') }}" data-read="{{ route('ops.notifications.read') }}">
    <div class="dropdown-toggle notification cursor-pointer" role="button" aria-expanded="false" data-tw-toggle="dropdown" title="Notifications">
        <i data-lucide="bell" class="notification__icon dark:text-slate-500"></i>
        <span class="tva-noti__badge" data-noti-badge @if (! $tvaNotiCounts['all']) hidden @endif>{{ $tvaNotiCounts['all'] > 99 ? '99+' : $tvaNotiCounts['all'] }}</span>
    </div>
    <div class="notification-content pt-2 dropdown-menu">
        <div class="notification-content__box dropdown-content tva-noti__box">
            <div class="tva-noti__head">
                <div class="notification-content__title">Notifications</div>
                <button type="button" class="tva-noti__link" data-noti-readall>Mark all as read</button>
            </div>
            <div class="tva-noti__tabs">
                @foreach ($tvaNotiTabs as $key => $label)
                    @php $n = $tvaNotiCounts[$key === '' ? 'all' : $key]; @endphp
                    <button type="button" class="tva-noti__tab {{ $key === '' ? 'is-on' : '' }}" data-noti-tab="{{ $key }}">
                        {{ $label }}<b data-noti-count="{{ $key === '' ? 'all' : $key }}" @if (! $n) hidden @endif>{{ $n }}</b>
                    </button>
                @endforeach
            </div>
            <div class="tva-noti__list" data-noti-list><div class="tva-noti__empty">Loading…</div></div>
            <a class="tva-noti__foot" href="{{ route('ops.notifications.index') }}">View all notifications</a>
        </div>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('tva-noti');
    if (!root || root.dataset.ready) return;
    root.dataset.ready = '1';

    var ICONS = {
        user:    ['#eff6ff', '#2563eb', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>'],
        client:  ['#f5f3ff', '#7c3aed', '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>'],
        project: ['#ecfeff', '#0891b2', '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M12 10v6M9 13h6"/>'],
        contact: ['#fff7ed', '#ea580c', '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>'],
        payment: ['#f0fdf4', '#16a34a', '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>']
    };
    var kind = '';
    var list = root.querySelector('[data-noti-list]');
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function paintCounts(counts) {
        var all = counts.all || 0, badge = root.querySelector('[data-noti-badge]');
        badge.hidden = !all; badge.textContent = all > 99 ? '99+' : all;
        root.querySelectorAll('[data-noti-count]').forEach(function (b) {
            var n = counts[b.getAttribute('data-noti-count')] || 0; b.hidden = !n; b.textContent = n;
        });
    }

    function paintList(items) {
        if (!items.length) { list.innerHTML = '<div class="tva-noti__empty">Nothing here yet.</div>'; return; }
        list.innerHTML = items.map(function (n) {
            var ic = ICONS[n.kind] || ICONS.user;
            return '<a class="tva-noti__item' + (n.unread ? ' is-unread' : '') + '" href="' + esc(n.url) + '">' +
                '<span class="tva-noti__ico" style="background:' + ic[0] + ';color:' + ic[1] + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + ic[2] + '</svg></span>' +
                '<span style="min-width:0"><div class="tva-noti__t">' + esc(n.title) + '</div>' +
                (n.body ? '<div class="tva-noti__b">' + esc(n.body) + '</div>' : '') +
                '<div class="tva-noti__time">' + esc(n.time) + '</div></span></a>';
        }).join('');
    }

    function load(withList) {
        return fetch(root.dataset.feed + (kind ? '?kind=' + encodeURIComponent(kind) : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (d) { paintCounts(d.counts); if (withList) paintList(d.items); })
            .catch(function () { if (withList) list.innerHTML = '<div class="tva-noti__empty">Could not load notifications.</div>'; });
    }

    root.querySelector('.dropdown-toggle').addEventListener('click', function () { load(true); });
    root.querySelectorAll('[data-noti-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.stopPropagation();
            kind = tab.getAttribute('data-noti-tab');
            root.querySelectorAll('[data-noti-tab]').forEach(function (t) { t.classList.toggle('is-on', t === tab); });
            list.innerHTML = '<div class="tva-noti__empty">Loading…</div>';
            load(true);
        });
    });
    root.querySelector('[data-noti-readall]').addEventListener('click', function (e) {
        e.stopPropagation();
        fetch(root.dataset.read, { method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(kind ? { kind: kind } : {}) }).then(function () { load(true); });
    });
    // New events show up without a reload.
    setInterval(function () { if (!document.hidden) load(false); }, 60000);
})();
</script>
