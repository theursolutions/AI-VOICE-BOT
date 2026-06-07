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

    // loader.js is served from {host}/AI-CRM-AGENT/admin/public/widget/loader.js
    // webchat lives at        {host}/AI-CRM-AGENT/widget/webchat-app.php
    // and the API is at        {host}/AI-CRM-AGENT/admin/public/api/v1/widget/config
    var srcUrl   = new URL(thisScript.src, window.location.href);
    var origin   = srcUrl.origin;
    var apiBase  = origin + srcUrl.pathname.replace(/\/widget\/loader\.js.*$/, '');
    var webchatUrl = origin + srcUrl.pathname.replace(/\/admin\/public\/widget\/loader\.js.*$/, '/widget/webchat-app.php');

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
                var url = webchatUrl
                    + '?key='  + encodeURIComponent(apiKey)
                    + '&embed=1'
                    + '&_v='   + Date.now();
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

        shadow.appendChild(frameWrap);
        shadow.appendChild(launcher);
    }
})();
