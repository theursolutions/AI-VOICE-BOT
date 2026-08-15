{{--
    Colour tokens + the theme switch.

    Two rules govern everything below:

    1. LIGHT IS THE BASE. Every token has its light value on bare `:root`,
       and `html.dark` redefines only what changes. A colour whose only
       definition lives inside a `.dark` block is a bug — it disappears in
       light mode.

    2. THE SERVER DECIDES. `tva_theme()` reads a cookie and the class is
       written into the initial HTML, so a dark-mode user never sees a white
       flash while JavaScript catches up.

    The palette is deliberately warm-neutral rather than pure grey. Pure
    #ffffff on #f8fafc is the default every framework ships with, and it is
    a large part of why generated interfaces look generated — real products
    put a little warmth in the paper and reserve pure white for cards that
    need to lift off it.
--}}
<style>
    :root {
        /* Surfaces, back to front */
        --tva-bg:          #f7f8fa;   /* page — a touch warm, never pure grey */
        --tva-surface:     #ffffff;   /* cards, panels, menus */
        --tva-surface-2:   #f9fafb;   /* nested / inset areas */
        --tva-surface-3:   #f1f3f6;   /* rails, tracks, hovered rows */

        /* Ink */
        --tva-text:        #16202e;   /* headings and primary copy */
        --tva-text-2:      #475467;   /* body */
        --tva-text-3:      #7a869a;   /* meta, captions, placeholders */

        /* Lines */
        --tva-border:      #e4e7ec;
        --tva-border-2:    #eef0f3;   /* hairlines inside a card */

        /* Interaction. Named for the STATE, not the colour, so a hover has
           one definition instead of thirty. */
        --tva-hover:       #f2f4f7;
        --tva-active:      #e8ebf0;
        --tva-ring:        rgba(79,70,229,.28);   /* focus ring */

        /* Status. Each has a tint for backgrounds and a solid for text. */
        --tva-ok:          #067647;  --tva-ok-bg:    #ecfdf3;  --tva-ok-line:   #abefc6;
        --tva-warn:        #b54708;  --tva-warn-bg:  #fffaeb;  --tva-warn-line: #fedf89;
        --tva-danger:      #b42318;  --tva-danger-bg:#fef3f2;  --tva-danger-line:#fecdca;
        --tva-info:        #175cd3;  --tva-info-bg:  #eff8ff;  --tva-info-line: #b2ddff;

        --tva-shadow:      0 1px 2px rgba(16,24,40,.05);
        --tva-shadow-lg:   0 12px 32px -8px rgba(16,24,40,.14);
    }

    html.dark {
        /* The blue-black the product already had — kept deliberately, since
           it is the identity people recognise. Only the ROLES move. */
        --tva-bg:          #0b1220;
        --tva-surface:     #131c2e;
        --tva-surface-2:   #18223a;
        --tva-surface-3:   #1e293b;

        --tva-text:        #eef2f8;
        --tva-text-2:      #b7c1d1;
        --tva-text-3:      #7d8aa0;

        --tva-border:      #26324a;
        --tva-border-2:    #1d2740;

        --tva-hover:       #1c2740;
        --tva-active:      #24314d;
        --tva-ring:        rgba(129,140,248,.35);

        --tva-ok:          #75e0a7;  --tva-ok-bg:    #0d2b1e;  --tva-ok-line:   #1f5138;
        --tva-warn:        #fec84b;  --tva-warn-bg:  #2e2005;  --tva-warn-line: #5a3d0a;
        --tva-danger:      #fda29b;  --tva-danger-bg:#2d1211;  --tva-danger-line:#5b2320;
        --tva-info:        #84caff;  --tva-info-bg:  #0a1f38;  --tva-info-line: #17406b;

        --tva-shadow:      0 1px 2px rgba(0,0,0,.35);
        --tva-shadow-lg:   0 14px 36px -10px rgba(0,0,0,.55);
    }

    /* ── Chrome ────────────────────────────────────────────────────────
       Deep navy sidebar and top bar with a light working area. The Midone
       shell already works this way — the chrome is simply the <html>
       background showing through, with `.content` covering the rest — so
       setting two colours does the whole job.

       This is also the pattern that stops a light admin looking like a blank
       document: the navy gives the eye an anchor and a clear hierarchy
       between navigation and work, which a flat all-white app never has. */
    html:not(.dark) {
        background: #1b3962;
    }
    /* The top bar is NOT a sibling of `.content` — it lives inside it. So the
       navy above cannot simply show through: the content's own background
       paints straight over the bar, which left a light header carrying
       controls styled white-on-navy, i.e. invisible ones.

       The band is painted onto `.content` rather than onto `.top-bar` so it
       runs full-bleed. The bar sits inside the content's 22px side padding,
       so giving the BAR a background leaves a pale gutter down both edges,
       and cancelling that with negative margins risks horizontal overflow on
       small screens. A background-image on the parent has neither problem.

       68px = the bar's fixed 67px plus the 1px `.content::before` spacer,
       which would otherwise read as a hairline of paper above the navy.

       Matched through the shell (body > .flex > .content) rather than on
       `.content` alone, because 45 page templates open a SECOND .content
       inside the layout's one. A bare `.content` selector paints the band
       again at the top of the page body — a phantom navy strip that reads as
       an over-tall header — and repaints the vendor's rounded panel, which
       surfaces as a stray white card behind the page's own hero.

       The work area's top corners are rounded by the two radial layers, not by
       a border-radius: the paper starts 68px down, in the MIDDLE of the panel,
       and a radius only ever rounds a box's own edge. Each layer is a 30px
       square holding a quarter-circle of navy — page colour inside the curve,
       navy outside it — parked at the corner where the band ends.

       They are corner squares rather than a deeper band on purpose. Running
       the band 30px further down is the obvious way to get the same shape, and
       it puts navy underneath the sidebar's active item, whose corner wedges
       are painted a hardcoded light colour to match the content: on navy they
       turn into a light slab with a notch. The squares stop at 30px wide, so
       they touch only the corner the sidebar's own wedge already occupies —
       same radius, same place, so where both are present they coincide.

       This is also why the Dashboard looked right while every other page
       looked square: Dashboard is the FIRST menu item, so its active wedge
       lands on that corner by accident of position. */
    html:not(.dark) body > .flex > .content {
        background-color: var(--tva-bg);
        background-image:
            /* top-left: curve centred on the square's bottom-right */
            radial-gradient(circle 30px at 100% 100%, rgba(27,57,98,0) 0 29px, #1b3962 30px),
            /* top-right: centred on its bottom-left */
            radial-gradient(circle 30px at 0 100%,    rgba(27,57,98,0) 0 29px, #1b3962 30px),
            linear-gradient(#1b3962 0, #1b3962 68px, transparent 68px);
        background-size: 30px 30px, 30px 30px, auto;
        /* 67px, not 68: the corner layers are pulled up a pixel so they OVERLAP
           the band rather than butting against it. Meeting exactly at 68 leaves
           the two edges to antialias against each other, and the hairline of
           page colour that survives between them reads — right where the arc
           is — as a thin curved splinter sitting in the corner. */
        background-position: 0 67px, 100% 67px, 0 0;
        background-repeat: no-repeat;
    }
    /* Those nested wrappers inherit the page instead of painting their own
       slightly-different slate, which otherwise shows as a faint rounded
       panel edge around the content. */
    html:not(.dark) .content .content { background: transparent; }

    html:not(.dark) .top-bar { border-bottom-color: rgba(255,255,255,.10); }

    /* The chrome is dark in BOTH themes, so its contents must not follow the
       light text tokens — white-on-navy is correct in light mode too. */
    html:not(.dark) .top-bar .search__input {
        background: rgba(255,255,255,.08);
        border-color: rgba(255,255,255,.14);
        color: #eaf0f8;
    }
    html:not(.dark) .top-bar .search__input::placeholder { color: rgba(234,240,248,.55); }
    html:not(.dark) .top-bar .search__input:focus {
        background: rgba(255,255,255,.14);
        box-shadow: 0 0 0 3px rgba(255,255,255,.10);
    }
    /* The theme switch sits on the navy, not on paper. */
    html:not(.dark) .tva-theme {
        background: rgba(255,255,255,.08);
        border-color: rgba(255,255,255,.16);
        color: #eaf0f8;
    }
    html:not(.dark) .tva-theme:hover { background: rgba(255,255,255,.16); color:#fff; }

    /* The magnifier and the bell are vendor icons coloured for a pale bar. */
    html:not(.dark) .top-bar .search__icon { color: rgba(234,240,248,.60); }
    html:not(.dark) .top-bar .notification__icon { color: rgba(234,240,248,.78); }
    html:not(.dark) .top-bar .notification:hover .notification__icon { color: #fff; }

    /* The two name pills — the project brand and the workspace switcher.
       Both were a wash of near-black on 5% black: correct on paper, a grey
       smudge on navy. Outlined in the brand's own blue instead of another
       neutral, so the workspace you are in is legible at a glance and the
       bar keeps some colour in it. */
    html:not(.dark) .tva-tb-brand__name,
    html:not(.dark) .tva-ws-pill {
        color: #eaf2ff;
        background: rgba(96,165,250,.14);
        border-color: rgba(147,197,253,.45);
    }
    html:not(.dark) .tva-tb-brand:hover .tva-tb-brand__name,
    html:not(.dark) button.tva-ws-pill:hover {
        background: rgba(96,165,250,.24);
        border-color: rgba(147,197,253,.70);
        color: #ffffff;
    }
    html:not(.dark) .tva-ws-pill svg { opacity: .8; }
    html:not(.dark) .tva-tb-brand:hover { background: rgba(255,255,255,.07); }
    html:not(.dark) .tva-tb-brand__sub { color: rgba(234,240,248,.60); }

    /* The account avatar is a blue gradient disc — nearly camouflaged against
       navy. A hairline ring separates it from the bar without recolouring it. */
    html:not(.dark) .top-bar .dropdown-toggle.rounded-full {
        box-shadow: 0 0 0 2px rgba(255,255,255,.32), 0 2px 8px rgba(0,0,0,.28);
    }
    html:not(.dark) .tva-tb-logo {
        box-shadow: 0 0 0 1px rgba(255,255,255,.22), 0 2px 8px rgba(0,0,0,.25);
    }

    /* ── Light-mode safety net ─────────────────────────────────────────
       Every rule below fixes a real gap found by auditing all 596 `.dark`
       selectors in the app against their light counterparts: places where a
       colour was ONLY ever defined for dark, so in light mode the element
       inherited something wrong — dark text on a white card, or an unreadable
       input.

       Kept here rather than patched into twenty separate files because these
       are the SHARED primitives (modals, popovers, toasts, table search,
       composer) and one definition is easier to keep honest than twenty.
       Page-specific colours stay with their page. */

    /* Popovers, menus, toasts and dialogs — all had a dark `color` and no
       light one, which left dark text rules inherited onto light panels. */
    .tva-pop, #msgMenu, .tva-toast, .tva-dlg { color: var(--tva-text); }

    /* Inputs inside our own overlays. The vendor theme styles `.form-control`
       but not a bare `input` inside a custom dialog. */
    .tva-dlg input, .tva-dlg textarea,
    .tva-dt-search input, .tva-acl-search input,
    .ft-matrix input, .pe-feat input, .pl-inline input, .sb-inline input {
        background: var(--tva-surface);
        color: var(--tva-text);
        border-color: var(--tva-border);
    }
    .tva-dlg input::placeholder, .tva-dlg textarea::placeholder,
    .tva-dt-search input::placeholder, .tva-acl-search input::placeholder {
        color: var(--tva-text-3);
    }
    .tva-acl-search i { color: var(--tva-text-3); }

    /* The chat composer — the single most-used input in the product. */
    .tva-composer-row textarea {
        background: var(--tva-surface);
        color: var(--tva-text);
    }

    /* Rows that only defined a dark hover, so light mode had none at all. */
    .tva-acl-row:hover { background: var(--tva-hover); }

    /* Cards and banners whose text colour was dark-only. */
    .tva-status-card { color: var(--tva-text); }
    .tva-source-banner .tva-meta-value { color: var(--tva-text); }
    .tva-source-banner .tva-meta-label { color: var(--tva-text-3); }
    .log-acc { color: var(--tva-text-2); }

    /* Modal form controls. Several pages force these with !important in dark
       and nothing in light, which left the vendor's light styles in place —
       correct, but inconsistent with our own modals. Matched here so a modal
       looks the same wherever it opens. */
    .tva-modal__body .form-control,
    .tva-modal__body .form-select {
        background: var(--tva-surface);
        color: var(--tva-text);
        border-color: var(--tva-border);
    }
    .tva-modal__body .form-control::placeholder { color: var(--tva-text-3); }
    .tva-modal__body .form-select option { background: var(--tva-surface); color: var(--tva-text); }
    .tva-modal__body .form-label,
    .tva-modal__body label { color: var(--tva-text-2); }
    .tva-modal__body small { color: var(--tva-text-3); }

    /* Rich-text editors ship their own light defaults, but the dark override
       here has no light twin — so state it. */
    .ql-editor { color: var(--tva-text); }

    /* Tom Select, used in the skills and agent pickers. */
    .tva-modal__body .ts-wrapper .ts-control,
    .tva-modal__body .ts-wrapper .ts-control input,
    .tva-modal__body .ts-dropdown .option { color: var(--tva-text); }

    /* Theme switch. Sits in the top bar; the icon shows what you will GET,
       not what you are on — the commonest confusion with these controls. */
    .tva-theme {
        display:inline-flex; align-items:center; justify-content:center;
        width:34px; height:34px; border-radius:10px; cursor:pointer;
        border:1px solid var(--tva-border); background:var(--tva-surface);
        color:var(--tva-text-2); transition:background .14s, color .14s, border-color .14s;
    }
    .tva-theme:hover { background:var(--tva-hover); color:var(--tva-text); }
    .tva-theme:focus-visible { outline:none; box-shadow:0 0 0 3px var(--tva-ring); }
    .tva-theme svg { width:16px; height:16px; }
    .tva-theme__moon { display:block; }
    .tva-theme__sun  { display:none; }
    html.dark .tva-theme__moon { display:none; }
    html.dark .tva-theme__sun  { display:block; }

    /* Switching repaints the whole page. A short crossfade on the big
       surfaces stops it feeling like a glitch — and is suppressed entirely
       for anyone who has asked for less motion. */
    html.tva-theming, html.tva-theming body,
    html.tva-theming .content, html.tva-theming .box {
        transition: background-color .18s ease, color .18s ease, border-color .18s ease;
    }
    @media (prefers-reduced-motion: reduce) {
        html.tva-theming, html.tva-theming * { transition:none !important; }
    }
</style>

<script>
(function () {
    // Belt and braces. The server already wrote the class from the cookie,
    // so this only matters when the page was served from a cache that
    // ignored it — in which case fixing it here is still better than a
    // wrong theme.
    try {
        var stored = localStorage.getItem('tva_theme');
        if (stored === 'dark' || stored === 'light') {
            document.documentElement.classList.toggle('dark', stored === 'dark');
        }
    } catch (e) { /* private mode: the cookie already did the job */ }

    window.tvaToggleTheme = function () {
        var root = document.documentElement;
        var next = root.classList.contains('dark') ? 'light' : 'dark';

        root.classList.add('tva-theming');
        root.classList.toggle('dark', next === 'dark');

        try { localStorage.setItem('tva_theme', next); } catch (e) {}
        // A year, and SameSite=Lax so it survives an external OAuth return —
        // coming back from Meta into the wrong theme reads as a broken login.
        document.cookie = 'tva_theme=' + next + ';path=/;max-age=31536000;SameSite=Lax';

        // Charts read the class once, at build time, so they have to be told.
        window.dispatchEvent(new CustomEvent('tva:theme', { detail: { theme: next } }));

        setTimeout(function () { root.classList.remove('tva-theming'); }, 260);
    };
})();
</script>
