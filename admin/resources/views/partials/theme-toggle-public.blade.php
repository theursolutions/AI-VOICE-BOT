{{--
    Theme switch for the marketing site.

    A separate partial from the admin one because the public pages do not
    load the admin's token layer — they carry their own `--text-dim`/`--line`
    set, so the control has to speak that vocabulary to sit correctly in the
    nav on both themes.

    The icon shows what you will GET, not what you are on.
--}}
<button type="button" class="nav__theme" onclick="tvaTogglePublicTheme()"
        aria-label="Switch between light and dark" title="Switch theme">
    <svg class="nav__theme-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
    <svg class="nav__theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"/>
        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
    </svg>
</button>

<style>
    /* This partial carries its own <style> and <script>, so wherever it is
       dropped in they become CHILDREN of that container. A layout rule like
       `.nav__links > * { display: inline-flex }` then overrides their default
       display:none, and the browser lays out their SOURCE as visible text in
       the page. Cheap insurance, stated where the risk originates. */
    style, script { display: none !important; }

    .nav__theme {
        display: inline-flex; align-items: center; justify-content: center;
        width: 32px; height: 32px; padding: 0; flex-shrink: 0;
        border: 1px solid var(--line); border-radius: 9px;
        background: transparent; color: var(--text-dim);
        cursor: pointer; transition: color .14s, border-color .14s, background .14s;
    }
    .nav__theme:hover { color: var(--text); border-color: var(--line-hot); }
    .nav__theme:focus-visible { outline: 2px solid var(--neon); outline-offset: 2px; }
    .nav__theme svg { width: 15px; height: 15px; }
    .nav__theme-moon { display: block; }
    .nav__theme-sun  { display: none; }
    html.dark .nav__theme-moon { display: none; }
    html.dark .nav__theme-sun  { display: block; }
    /* Kept visible on phones, where the rest of the nav links collapse —
       it is a preference, not navigation. */
    @media (max-width: 720px) { .nav__links .nav__theme { display: inline-flex; } }
</style>

<script>
    window.tvaTogglePublicTheme = function () {
        var root = document.documentElement;
        var next = root.classList.contains('dark') ? 'light' : 'dark';

        root.classList.toggle('dark', next === 'dark');
        try { localStorage.setItem('tva_theme', next); } catch (e) {}
        // Shared with the admin, so a visitor who signs in keeps their choice.
        document.cookie = 'tva_theme=' + next + ';path=/;max-age=31536000;SameSite=Lax';
        // Canvas-drawn things cannot be restyled by CSS, so they listen for
        // this instead of being left on the previous theme until a reload.
        // Same event name the admin toggle emits.
        window.dispatchEvent(new CustomEvent('tva:theme', { detail: { theme: next } }));
    };

    // The server writes the class from the cookie, so this only corrects a
    // page served from a cache that ignored it.
    (function () {
        try {
            var stored = localStorage.getItem('tva_theme');
            if (stored === 'dark' || stored === 'light') {
                document.documentElement.classList.toggle('dark', stored === 'dark');
            }
        } catch (e) {}
    })();
</script>
