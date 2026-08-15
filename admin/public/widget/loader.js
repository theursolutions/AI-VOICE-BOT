/**
 * Voice CRM Agent — embeddable chat widget loader.
 *
 * Customer paste-on-site:
 *   <script src="https://your-domain/widget/loader.js"
 *           data-project-key="abc123"></script>
 *
 * Architecture: the loader handles only the launcher button + the
 * <iframe> that hosts the real widget (webchat-app.php). The iframe
 * gives us perfect style isolation from the host site (the host's CSS
 * can't reach inside, and our CSS / JS can't leak out).
 *
 *   loader.js (shadow DOM on host page)
 *     ├─ launcher button (positioned per config)
 *     └─ iframe → /widget/webchat-app.php?key=...&embed=1
 *
 *   webchat-app.php picks up the key, fetches the project's widget
 *   config from /api/v1/widget/config, and applies branding inline.
 */
(function () {
    'use strict';

    // --- locate our own <script> tag + derive API/widget base URLs ----
    var thisScript = document.currentScript || (function () {
        var all = document.getElementsByTagName('script');
        for (var i = all.length - 1; i >= 0; i--) {
            if (all[i].src && /widget\/loader\.js/.test(all[i].src)) return all[i];
        }
        return null;
    })();
    if (!thisScript) {
        console.warn('[tvaibwc] loader could not locate its own <script> tag');
        return;
    }
    var apiKey = thisScript.getAttribute('data-project-key');
    if (!apiKey) {
        console.warn('[tvaibwc] data-project-key attribute is missing');
        return;
    }

    // --- theme: follow the host page ---------------------------------
    // data-theme on the script tag pins it: "light" | "dark" | "auto".
    // "auto" (the default) reads the page itself and keeps following it,
    // so a visitor toggling the site's own light/dark switch takes the
    // widget with them.
    //
    // Detection order matters. An explicit class or attribute is a
    // deliberate choice by the site; prefers-color-scheme is only a
    // fallback for pages that never made one — using the OS setting on a
    // site that has decided to be light produces a dark widget on a white
    // page, which is exactly the mismatch this is meant to fix.
    var themeMode = (thisScript.getAttribute('data-theme') || 'auto').toLowerCase();

    function detectHostTheme() {
        if (themeMode === 'dark' || themeMode === 'light') return themeMode;

        var root = document.documentElement;
        var body = document.body;

        if (root.classList.contains('dark') || (body && body.classList.contains('dark'))) return 'dark';

        var attr = root.getAttribute('data-theme') || (body && body.getAttribute('data-theme'));
        if (attr === 'dark' || attr === 'light') return attr;

        if (root.classList.contains('light') || root.getAttribute('data-bs-theme') === 'light') return 'light';
        if (root.getAttribute('data-bs-theme') === 'dark') return 'dark';

        // No explicit marker. Read what the page ACTUALLY looks like.
        //
        // This matters more than it sounds: plenty of sites (ours included)
        // signal dark by adding a class and signal light by having no class
        // at all, so "no class" is indistinguishable from "no opinion". Asking
        // the OS at that point puts a dark widget on a white page for every
        // visitor whose laptop is set to dark. The rendered background cannot
        // be wrong in the same way — and it tracks the site's own toggle for
        // free, because toggling is what changes it.
        var bg = readBackgroundLuminance();
        if (bg !== null) return bg < 0.45 ? 'dark' : 'light';

        try {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) return 'dark';
        } catch (e) {}

        return 'light';
    }

    /**
     * Perceived lightness (0 = black, 1 = white) of the page background, or
     * null when everything is transparent and nothing can be concluded.
     */
    function readBackgroundLuminance() {
        var els = [document.body, document.documentElement];

        for (var i = 0; i < els.length; i++) {
            if (!els[i]) continue;

            var colour;
            try { colour = window.getComputedStyle(els[i]).backgroundColor; } catch (e) { continue; }
            if (!colour) continue;

            var m = colour.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+))?/i);
            if (!m) continue;

            // Fully transparent tells us nothing — keep looking up the tree.
            if (m[4] !== undefined && parseFloat(m[4]) === 0) continue;

            // Rec. 601 luma: cheap, and accurate enough to sort light from dark.
            return (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) / 255;
        }

        return null;
    }

    // The API always sits where loader.js does, minus the /widget/ segment —
    // true in both layouts described below. The webchat's location is not,
    // which is what the block after this works out.
    var srcUrl   = new URL(thisScript.src, window.location.href);
    var origin   = srcUrl.origin;
    var apiBase  = origin + srcUrl.pathname.replace(/\/widget\/loader\.js.*$/, '');

    // Two layouts, and the difference is where loader.js sits relative to the
    // webchat app:
    //
    //   development  admin/public/widget/loader.js  +  widget/webchat-app.php
    //                → siblings of admin/, one directory apart
    //   deployed     both served from /widget/ (nginx aliases it to
    //                /var/www/widget, and the image copies loader.js there)
    //                → same directory
    //
    // The original only handled the first and produced a URL pointing back at
    // loader.js itself on a real deploy, so the iframe loaded the loader as a
    // document. `data-webchat-url` overrides both for anyone hosting the two
    // halves somewhere else entirely.
    var webchatUrl = thisScript.getAttribute('data-webchat-url');
    if (!webchatUrl) {
        webchatUrl = /\/admin\/public\/widget\/loader\.js/.test(srcUrl.pathname)
            ? origin + srcUrl.pathname.replace(/\/admin\/public\/widget\/loader\.js.*$/, '/widget/webchat-app.php')
            : origin + srcUrl.pathname.replace(/loader\.js.*$/, 'webchat-app.php');
    }

    // --- avoid double-init -------------------------------------------
    if (window.__tvaibwcLoaded) return;
    window.__tvaibwcLoaded = true;

    function fetchJson(path) {
        return fetch(apiBase + path, {
            headers: { 'X-CLIENT-API-KEY': apiKey, 'Accept': 'application/json' }
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    // --- boot ---------------------------------------------------------
    fetchJson('/api/v1/widget/config')
        .then(function (resp) { mount(resp.config || {}); })
        .catch(function (err) {
            console.warn('[tvaibwc] failed to load widget config:', err);
            // Still mount with defaults so the widget is usable.
            mount({});
        });

    function mount(config) {
        var primary  = config.primary_color  || '#1a365d';
        var accent   = config.accent_color   || '#3b82f6';
        var position = config.position       || 'bottom-right';
        var logoUrl  = config.logo_url       || null;
        var emoji    = config.avatar_emoji   || '🤖';

        // ---- host element + shadow root -----------------------------
        // Shadow DOM keeps the host site's CSS from styling our
        // launcher (and vice versa). The iframe inside takes care of
        // the full chat panel isolation.
        var host = document.createElement('div');
        host.id = 'tvaibwc-host';
        host.style.cssText = 'all: initial; position: fixed; z-index: 2147483647; '
                          + (position === 'bottom-left' ? 'left:0; ' : 'right:0; ')
                          + 'bottom: 0; pointer-events: none;';
        document.body.appendChild(host);
        var shadow = host.attachShadow({ mode: 'open' });

        // ---- styles --------------------------------------------------
        var style = document.createElement('style');
        style.textContent = [
            ':host, * { box-sizing: border-box; }',
            ':host { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }',
            // re-enable pointer events only on the visible widget bits
            '.launcher, .frame, .frame-wrap { pointer-events: auto; }',
            '.launcher {',
            '  position: fixed; bottom: 20px;',
            '  ' + (position === 'bottom-left' ? 'left: 20px;' : 'right: 20px;'),
            '  width: 60px; height: 60px; border-radius: 50%;',
            '  background: linear-gradient(135deg, ' + primary + ', ' + accent + ');',
            '  display: flex; align-items: center; justify-content: center;',
            '  color: #fff; font-size: 26px;',
            '  cursor: pointer;',
            '  box-shadow: 0 12px 30px -8px rgba(0,0,0,.35);',
            '  transition: transform .15s, opacity .2s;',
            '}',
            '.launcher:hover { transform: scale(1.06); }',
            '.launcher img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }',
            '.launcher.is-hidden { opacity: 0; pointer-events: none; transform: scale(0); }',
            // The frame hosts the panel + 16px gap + 46px chevron-down
            // button + 6px padding. 380 × 695 default; the iframe can
            // request a wider/narrower size via postMessage when the
            // user clicks the "expand" button inside the widget.
            '.frame-wrap {',
            '  position: fixed; bottom: 14px;',
            '  ' + (position === 'bottom-left' ? 'left: 14px;' : 'right: 14px;'),
            '  width: 380px; height: 760px;',
            '  max-width: calc(100vw - 28px); max-height: calc(100vh - 28px);',
            '  overflow: visible;',
            '  background: transparent;',
            '  opacity: 0; transform: translateY(20px) scale(.95);',
            '  transition: opacity .2s, transform .2s, width .25s;',
            '  display: none;',
            '}',
            '.frame-wrap.is-expanded { width: 720px; }',
            '.frame-wrap.is-open { display: block; opacity: 1; transform: translateY(0) scale(1); }',
            '.frame { width: 100%; height: 100%; border: 0; background: transparent; }',
        ].join('');
        shadow.appendChild(style);

        // ---- launcher button ----------------------------------------
        var launcher = document.createElement('div');
        launcher.className = 'launcher';
        launcher.title = 'Open chat';
        if (logoUrl) {
            var img = document.createElement('img');
            img.src = logoUrl; img.alt = '';
            launcher.appendChild(img);
        } else {
            launcher.textContent = emoji;
        }

        // ---- iframe (created lazily on first open) ------------------
        var frameWrap = document.createElement('div');
        frameWrap.className = 'frame-wrap';
        var frame = null;

        function openWidget() {
            if (!frame) {
                frame = document.createElement('iframe');
                frame.className = 'frame';
                // Cache-bust so config changes pick up immediately during dev.
                // Theme travels in the URL so the widget paints correctly on
                // its FIRST frame. Sending it by postMessage after load would
                // show the wrong theme for a moment, which on a light page is
                // a black flash.
                var url = webchatUrl
                    + '?key='   + encodeURIComponent(apiKey)
                    + '&embed=1'
                    + '&theme=' + encodeURIComponent(detectHostTheme())
                    + '&_v='    + Date.now();
                frame.src = url;
                frame.setAttribute('title', 'Chat widget');
                frame.setAttribute('allow', 'microphone; clipboard-write');
                frame.setAttribute('referrerpolicy', 'no-referrer-when-downgrade');
                frameWrap.appendChild(frame);
            }
            frameWrap.classList.add('is-open');
            launcher.classList.add('is-hidden');
        }
        function closeWidget() {
            frameWrap.classList.remove('is-open');
            launcher.classList.remove('is-hidden');
        }

        launcher.addEventListener('click', openWidget);

        // ---- postMessage protocol with the iframe -------------------
        // webchat-app.php sends:
        //   { type: 'tvaibwc:close'  }              — close + show launcher
        //   { type: 'tvaibwc:expand', on: bool }    — toggle wide layout
        //   { type: 'tvaibwc:ready'  }              — boot complete
        window.addEventListener('message', function (ev) {
            if (!ev.data || typeof ev.data !== 'object') return;
            if (ev.data.type === 'tvaibwc:close')  closeWidget();
            if (ev.data.type === 'tvaibwc:expand') {
                frameWrap.classList.toggle('is-expanded', !!ev.data.on);
            }
        });

        // ---- keep the widget on the host page's theme ----------------
        // The site's own toggle flips a class on <html>; watching that
        // attribute is what makes the widget follow along instead of
        // sitting there in the opposite theme.
        var lastTheme = detectHostTheme();

        function pushTheme() {
            var next = detectHostTheme();
            if (next === lastTheme) return;          // observers fire a lot
            lastTheme = next;

            // Recolour the launcher too — it lives on the host page, not
            // inside the iframe, so the iframe's own theming never reaches it.
            applyLauncherTheme(next);

            if (frame && frame.contentWindow) {
                frame.contentWindow.postMessage({ type: 'tvaibwc:theme', theme: next }, '*');
            }
        }

        function applyLauncherTheme(theme) {
            // Dark pages need a lighter rim so a navy launcher doesn't
            // disappear into the background.
            launcher.style.boxShadow = theme === 'dark'
                ? '0 12px 30px -8px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.12)'
                : '0 12px 30px -8px rgba(16,24,40,.28)';
        }
        applyLauncherTheme(lastTheme);

        if (themeMode === 'auto') {
            try {
                new MutationObserver(pushTheme).observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class', 'data-theme', 'data-bs-theme'],
                });
                if (document.body) {
                    new MutationObserver(pushTheme).observe(document.body, {
                        attributes: true,
                        attributeFilter: ['class', 'data-theme'],
                    });
                }
            } catch (e) {}

            // Only relevant for pages with no explicit theme of their own.
            try {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(pushTheme);
            } catch (e) {}

            // Theme changed in another tab.
            window.addEventListener('storage', pushTheme);
        }

        // The iframe asks for the current theme once it has booted, which
        // covers the race where it loads before the host page has applied
        // its own stored preference.
        window.addEventListener('message', function (ev) {
            if (ev.data && ev.data.type === 'tvaibwc:ready' && frame && frame.contentWindow) {
                frame.contentWindow.postMessage({ type: 'tvaibwc:theme', theme: detectHostTheme() }, '*');
            }
        });

        shadow.appendChild(frameWrap);
        shadow.appendChild(launcher);
    }
})();
