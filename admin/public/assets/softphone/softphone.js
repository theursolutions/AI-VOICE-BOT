/* =============================================================================
   serveAI softphone — WebRTC (JsSIP ↔ Asterisk).

   Two modes, one file:
   - engine  (the phone window, /c/{client}/phone): signs in to Asterisk and
             holds the live call.
   - remote  (every admin page): mirrors the engine and sends it commands
             over a BroadcastChannel. Refreshing or navigating an admin page
             only reloads a remote — the call lives on in the phone window.

   A Web Lock ("tva-phone-engine") allows a single engine per browser and
   tells remotes reliably whether it is running. A heartbeat alone would
   not: browsers throttle timers in background windows, so a busy phone
   window could look dead — and "re-opening" it would reload it mid-call.

   Page code can start a call: window.tvaSoftphone.dial('1001'), or mark any
   element with data-softphone-dial="1001".
   ============================================================================= */
(function () {
    'use strict';

    var root = document.getElementById('tva-softphone');
    if (!root || root.getAttribute('data-ready')) return;
    root.setAttribute('data-ready', '1');
    var D = root.dataset;
    var ENGINE = D.mode === 'engine';
    var LOCK = 'tva-phone-engine';
    var bus = 'BroadcastChannel' in window ? new BroadcastChannel('tva-softphone') : null;

    if (ENGINE && !window.JsSIP) {
        root.innerHTML = '<p style="color:#fff;padding:24px;font-family:sans-serif">The phone library could not load. Check your connection and reopen this window.</p>';
        return;
    }

    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
    };
    // Per tab, survives a refresh: was the card open right now? (localStorage
    // holds the user's general preference; a call auto-opens the card without
    // changing it, and a refresh mid-call should come back the same way.)
    var tab = {
        get: function (k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* private mode */ } }
    };
    var openNow = tab.get('tsp_open_now');

    function blankPeer() { return { number: '', name: '', note: '', url: '' }; }

    // Call state — owned by the engine, copied verbatim to remotes.
    var C = {
        view: 'boot',        // boot | setup | idle | incoming | outgoing | connected | ended | locked
        reg: 'offline',      // offline | connecting | online | failed
        regError: '',
        cfg: { configured: false },  // never carries the password
        peer: blankPeer(),
        startedAt: 0, endedText: '', ringback: false,
        muted: false, held: false, recording: false,
        quality: null,                    // { level, codec, loss, conceal, jitter } during a call
        consult: '', consultTarget: '',   // consult: '' | 'calling' | 'connected'
        formError: ''
    };

    // Window-local UI state.
    var U = {
        open: ENGINE || (openNow !== null ? openNow === '1' : store.get('tsp_open') === '1'),
        alive: ENGINE,       // remote: is a phone window running?
        setup: false, panel: null, dial: '', dtmf: '',
        toast: '', toastTimer: null, wasOpen: null,
        lastShape: '', lastView: '', lastPanel: '', lastJson: ''
    };

    // Engine-only: the SIP side.
    var E = {
        ua: null, domain: '', password: '',
        session: null, consult: null, makingConsult: false,
        transferredTo: '', endTimer: null, recorder: null
    };

    var mainAudio = ENGINE ? audioEl('tsp-audio-main') : null;
    var consultAudio = ENGINE ? audioEl('tsp-audio-consult') : null;

    // ── Helpers ────────────────────────────────────────────────────────────
    function audioEl(id) {
        var el = document.getElementById(id);
        if (!el) { el = document.createElement('audio'); el.id = id; el.autoplay = true; document.body.appendChild(el); }
        return el;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function cleanNumber(s) { return String(s || '').replace(/[^0-9+*#]/g, ''); }
    function fmt(ms) {
        var t = Math.max(0, Math.floor(ms / 1000)), h = Math.floor(t / 3600), m = Math.floor(t % 3600 / 60), s = t % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : String(m).padStart(2, '0')) + ':' + String(s).padStart(2, '0');
    }
    function initials(name, number) {
        var src = (name || '').trim();
        if (!src) return number ? '#' : '?';
        var parts = src.split(/\s+/);
        return ((parts[0][0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
    }
    function inCall() { return C.view === 'incoming' || C.view === 'outgoing' || C.view === 'connected'; }
    function peerTitle() { return C.peer.name || C.peer.number || 'Unknown caller'; }
    function peerSub() { return C.peer.name && C.peer.number ? C.peer.number : ''; }
    function toast(text) {
        U.toast = text; render();
        clearTimeout(U.toastTimer);
        U.toastTimer = setTimeout(function () { U.toast = ''; render(); }, 2600);
    }
    // Icons the admin bundle's older lucide lacks (grid-3x3, circle-dot) are
    // drawn here, so they show the same on admin pages and in the phone window.
    var SVG = {
        keypad: '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true">' +
            [5, 12, 19].map(function (y) {
                return [5, 12, 19].map(function (x) { return '<circle cx="' + x + '" cy="' + y + '" r="1.9"/>'; }).join('');
            }).join('') + '</svg>',
        rec: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
            '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5" fill="currentColor" stroke="none"/></svg>'
    };
    function ic(name) { return SVG[name] || '<i data-lucide="' + name + '"></i>'; }

    function icons() {
        // The admin bundle exposes { createIcons, icons } and its createIcons()
        // THROWS without the icon set; the UMD build in the phone window takes
        // the same arguments. Icons are decoration: never let them stop the phone.
        var L = window.lucide;
        if (!L || typeof L.createIcons !== 'function') return;
        try { L.createIcons({ icons: L.icons || {}, nameAttr: 'data-lucide' }); } catch (e) { /* no icons */ }
    }

    // ── Tones (Web Audio — no files) ───────────────────────────────────────
    var Tone = (function () {
        var ctx = null;
        function ac() {
            if (!ctx) {
                var Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return null;
                ctx = new Ctx();
            }
            if (ctx.state === 'suspended') ctx.resume();
            return ctx;
        }
        function note(freqs, at, dur, vol) {
            var c = ac(); if (!c) return;
            var g = c.createGain();
            g.gain.setValueAtTime(0.0001, at);
            g.gain.exponentialRampToValueAtTime(vol, at + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, at + dur);
            g.connect(c.destination);
            freqs.forEach(function (f) {
                var o = c.createOscillator();
                o.type = 'sine'; o.frequency.value = f;
                o.connect(g); o.start(at); o.stop(at + dur + 0.02);
            });
        }
        function loop(play, every) {
            var t = null;
            return {
                start: function () { if (t) return; play(); t = setInterval(play, every); },
                stop: function () { clearInterval(t); t = null; }
            };
        }
        document.addEventListener('pointerdown', function () { ac(); }, { once: true, capture: true });
        return {
            ring: loop(function () {
                var c = ac(); if (!c) return; var n = c.currentTime;
                [0, 0.32, 1.0, 1.32].forEach(function (d, i) { note([i % 2 ? 660 : 880], n + d, 0.26, 0.09); });
            }, 3000),
            back: loop(function () {
                var c = ac(); if (!c) return; var n = c.currentTime;
                note([400, 450], n, 0.4, 0.05); note([400, 450], n + 0.6, 0.4, 0.05);
            }, 3000),
            key: function (digit) {
                var c = ac(); if (!c) return;
                var rows = { 1: 697, 2: 697, 3: 697, 4: 770, 5: 770, 6: 770, 7: 852, 8: 852, 9: 852, '*': 941, 0: 941, '#': 941 };
                var cols = { 1: 1209, 4: 1209, 7: 1209, '*': 1209, 2: 1336, 5: 1336, 8: 1336, 0: 1336, 3: 1477, 6: 1477, 9: 1477, '#': 1477 };
                if (!rows[digit]) return;
                note([rows[digit], cols[digit]], c.currentTime, 0.09, 0.05);
            }
        };
    })();

    // Ring in whichever window the user is looking at.
    function syncTones() {
        var visible = document.visibilityState === 'visible';
        if (C.view === 'incoming' && visible) Tone.ring.start(); else Tone.ring.stop();
        if (C.view === 'outgoing' && C.ringback && visible) Tone.back.start(); else Tone.back.stop();
    }
    document.addEventListener('visibilitychange', syncTones);

    // ── Rendering ──────────────────────────────────────────────────────────
    function view() {
        if (!ENGINE && !U.alive) return 'offline';
        if (C.view === 'locked') return 'locked';
        if ((U.setup || C.view === 'setup') && !inCall()) return 'setup';
        return C.view;
    }
    function enter(key, current) { return U[key] !== current ? ' is-enter' : ''; }

    function render() {
        var v = view();
        var shape = ENGINE || U.open ? 'card' : (U.alive && inCall() ? 'banner' : 'fab');
        root.innerHTML = shape === 'card' ? cardHtml(shape, v) : shape === 'banner' ? bannerHtml(shape) : fabHtml(shape);
        U.lastShape = shape; U.lastView = v; U.lastPanel = U.panel;
        if (!ENGINE) tab.set('tsp_open_now', U.open ? '1' : '0');
        icons();
    }

    function dotClass() {
        if (!ENGINE && !U.alive) return '';
        return C.reg === 'online' ? 'is-online' : C.reg === 'connecting' ? 'is-connecting' : C.reg === 'failed' ? 'is-failed' : '';
    }

    function btn(cls, act, icon, title, extra) {
        return '<button class="' + cls + '" data-act="' + act + '" title="' + esc(title) + '"' + (extra || '') + '>' +
            ic(icon) + '</button>';
    }

    function avatar(extra) {
        return '<div class="tsp-avatar ' + (extra || '') + '">' + esc(initials(C.peer.name, C.peer.number)) + '</div>';
    }

    function fabHtml(shape) {
        return '<button class="tsp-fab' + enter('lastShape', shape) + '" data-act="expand" title="Phone">' +
            '<i data-lucide="phone"></i><span class="tsp-dot ' + dotClass() + '"></span></button>';
    }

    function bannerHtml(shape) {
        var ringing = C.view === 'incoming', calling = C.view === 'outgoing', live = C.view === 'connected';
        var status = ringing ? 'Incoming call…' : calling ? 'Calling…'
            : '<span class="tsp-live-timer">' + fmt(Date.now() - C.startedAt) + '</span>' + (C.held ? ' · on hold' : '') + (C.muted ? ' · muted' : '');
        var actions = ringing
            ? btn('tsp-mini is-red', 'decline', 'phone', 'Decline') + btn('tsp-mini is-green', 'answer', 'phone', 'Answer')
            : (live ? btn('tsp-mini' + (C.muted ? ' is-on' : ''), 'mute', C.muted ? 'mic-off' : 'mic', C.muted ? 'Unmute' : 'Mute') : '') +
              btn('tsp-mini is-red', 'hangup', 'phone', 'Hang up');
        return '<div class="tsp-banner' + (ringing ? ' is-ringing' : '') + enter('lastShape', shape) + '">' +
            avatar(ringing || calling ? 'is-pulsing' : '') +
            '<div class="tsp-banner-text" data-act="expand" style="cursor:pointer" title="Expand">' +
            '<b>' + esc(peerTitle()) + '</b><span>' + status + '</span></div>' +
            actions + btn('tsp-mini', 'expand', 'maximize-2', 'Expand') +
            '</div>';
    }

    function cardHtml(shape, v) {
        var left = !inCall() && v !== 'offline' && v !== 'locked' && v !== 'boot'
            ? btn('tsp-iconbtn', 'settings', 'settings', 'Phone settings') : '<span style="width:30px"></span>';
        var right = ENGINE ? '<span style="width:30px"></span>' : btn('tsp-iconbtn', 'minimize', 'minus', 'Minimise');
        return '<div class="tsp-card' + enter('lastShape', shape) + '">' +
            '<div class="tsp-head">' + left + '<span class="tsp-brand">serveAI</span>' + right + '</div>' +
            (U.toast ? '<div class="tsp-toast">' + esc(U.toast) + '</div>' : '') +
            '<div class="tsp-body' + enter('lastView', v) + '">' + bodyHtml(v) + '</div>' +
            footHtml() +
            '</div>';
    }

    function footHtml() {
        if (!ENGINE && !U.alive) return '<div class="tsp-foot"><span class="tsp-dot"></span>Phone window closed</div>';
        var label = C.reg === 'online' ? 'ext ' + esc(C.cfg.extension) + ' — my CID'
            : C.reg === 'connecting' ? 'Connecting…'
            : C.reg === 'failed' ? 'Not signed in' : 'Offline';
        var q = C.view === 'connected' && C.quality
            ? ' · <span class="tsp-q is-' + C.quality.level + '" title="Loss ' + C.quality.loss + '% · gaps ' + C.quality.conceal + '% · jitter ' + C.quality.jitter + ' ms">' +
              { good: 'Good', fair: 'Fair', poor: 'Poor' }[C.quality.level] + ' audio' + (C.quality.codec ? ' · ' + esc(C.quality.codec) : '') + '</span>'
            : '';
        return '<div class="tsp-foot"><span class="tsp-dot ' + dotClass() + '"></span>' + label + q + '</div>';
    }

    function bodyHtml(v) {
        switch (v) {
            case 'offline': return offlineHtml();
            case 'locked': return lockedHtml();
            case 'boot': return '<div class="tsp-who" style="padding:40px 0"><div class="tsp-state">Starting phone<span class="tsp-ellipsis"></span></div></div>';
            case 'setup': return setupHtml();
            case 'incoming': return incomingHtml();
            case 'outgoing': return outgoingHtml();
            case 'connected': return connectedHtml();
            case 'ended': return endedHtml();
            default: return idleHtml();
        }
    }

    function offlineHtml() {
        return '<div class="tsp-who" style="padding:10px 0 2px">' +
            '<div class="tsp-avatar is-small"><i data-lucide="phone-off"></i></div>' +
            '<div class="tsp-name" style="font-size:18px">Your phone is closed</div>' +
            '<div class="tsp-state" style="line-height:1.5">Open the phone window to make and take calls. Keep it open — refreshing or moving between pages won\'t drop your calls.</div>' +
            '</div>' +
            '<button class="tsp-call" data-act="open-window"><i data-lucide="external-link"></i> Open phone</button>';
    }

    function lockedHtml() {
        return '<div class="tsp-who" style="padding:24px 0 6px">' +
            '<div class="tsp-avatar is-small"><i data-lucide="copy"></i></div>' +
            '<div class="tsp-name" style="font-size:18px">Phone already open</div>' +
            '<div class="tsp-state" style="line-height:1.5">Your phone is running in another window. Use that one — this window can be closed.</div>' +
            '</div>' +
            '<button class="tsp-call" style="background:rgba(255,255,255,.14)" data-act="close-window"><i data-lucide="x"></i> Close this window</button>';
    }

    function whoHtml(stateLine, top) {
        return '<div class="tsp-who">' + (top || '') +
            '<div class="tsp-name">' + esc(peerTitle()) + '</div>' +
            (peerSub() ? '<div class="tsp-number">' + esc(peerSub()) + '</div>' : '') +
            (stateLine || '') + noteHtml() + '</div>';
    }

    function noteHtml() {
        if (!C.peer.note && !C.peer.url) return '';
        return '<div class="tsp-note">' + esc(C.peer.note || '') +
            (C.peer.url ? (C.peer.note ? ' · ' : '') + '<a href="' + esc(C.peer.url) + '" target="_blank" rel="noopener">Open conversation</a>' : '') +
            '</div>';
    }

    function idleHtml() {
        var problem = C.reg === 'failed'
            ? '<div class="tsp-error">Can\'t sign in to the phone server' + (C.regError ? ' (' + esc(C.regError) + ')' : '') +
              '. <a href="#" data-act="settings" style="color:#fff;text-decoration:underline">Check settings</a></div>'
            : '';
        return '<div class="tsp-display">' +
            '<input class="tsp-dial" inputmode="tel" autocomplete="off" placeholder="Enter a number" value="' + esc(U.dial) + '">' +
            btn('tsp-iconbtn', 'backspace', 'delete', 'Delete') +
            '</div>' + keypadHtml('') +
            '<button class="tsp-call" data-act="call"' + (C.reg !== 'online' ? ' disabled' : '') + '><i data-lucide="phone"></i> Call</button>' +
            problem;
    }

    function keypadHtml(cls) {
        var keys = [['1', ''], ['2', 'ABC'], ['3', 'DEF'], ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'],
                    ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'], ['*', ''], ['0', '+'], ['#', '']];
        return '<div class="tsp-keys ' + (cls || '') + '">' + keys.map(function (k) {
            return '<button class="tsp-key" data-act="key" data-key="' + k[0] + '"><b>' + k[0] + '</b><small>' + k[1] + '</small></button>';
        }).join('') + '</div>';
    }

    function incomingHtml() {
        return whoHtml('<div class="tsp-state">Incoming call<span class="tsp-ellipsis"></span></div>', avatar('is-pulsing')) +
            '<div class="tsp-pair">' +
            '<button class="tsp-hangup tsp-decline" data-act="decline" style="margin:0;width:auto"><i data-lucide="phone"></i> Decline</button>' +
            '<button class="tsp-answer" data-act="answer"><i data-lucide="phone"></i> Answer</button>' +
            '</div>';
    }

    function outgoingHtml() {
        return whoHtml('<div class="tsp-state">Calling<span class="tsp-ellipsis"></span></div>', avatar('is-pulsing')) +
            '<button class="tsp-hangup" data-act="hangup"><i data-lucide="phone"></i></button>';
    }

    function connectedHtml() {
        var badges = (C.held ? '<span class="tsp-badge is-hold">On hold</span>' : '') +
            (C.muted ? '<span class="tsp-badge is-mute">Muted</span>' : '') +
            (C.recording ? '<span class="tsp-badge is-rec">Recording</span>' : '');
        var consulting = !!C.consult;
        var panel = consulting ? 'consult' : U.panel;
        // The in-call keypad takes over the card, like a real phone.
        if (panel === 'keypad') return keypadScreenHtml();
        var ctl = function (act, icon, label, on, disabled, extraCls) {
            return '<button class="tsp-ctl' + (on ? ' is-on' : '') + (extraCls || '') + '" data-act="' + act + '"' + (disabled ? ' disabled' : '') + '>' +
                '<span class="tsp-round">' + ic(icon) + '</span>' + label + '</button>';
        };
        return whoHtml('<div class="tsp-timer tsp-live-timer">' + fmt(Date.now() - C.startedAt) + '</div>' +
                (badges ? '<div class="tsp-badges">' + badges + '</div>' : '')) +
            '<div class="tsp-grid">' +
            ctl('hold', C.held ? 'play' : 'pause', C.held ? 'Resume' : 'Hold', C.held, consulting) +
            ctl('panel-transfer', 'forward', 'Blind transfer', panel === 'transfer', consulting) +
            ctl('panel-keypad', 'keypad', 'Keypad', false) +
            ctl('mute', C.muted ? 'mic-off' : 'mic', C.muted ? 'Unmute' : 'Mute', C.muted) +
            ctl('panel-consult', 'users', 'Consultation', panel === 'consult') +
            ctl('rec', 'rec', C.recording ? 'Stop rec' : 'Rec', C.recording, false, C.recording ? ' is-rec' : '') +
            '<span></span>' +
            '<button class="tsp-ctl" disabled title="Three-way calling — coming soon"><span class="tsp-round"><i data-lucide="user-plus"></i></span>Conference</button>' +
            '<span></span>' +
            '</div>' +
            panelHtml(panel) +
            '<button class="tsp-hangup" data-act="hangup"><i data-lucide="phone"></i></button>';
    }

    function keypadScreenHtml() {
        return '<div class="tsp-kp' + enter('lastPanel', 'keypad') + '">' +
            '<div class="tsp-kp-head">' +
            btn('tsp-iconbtn', 'panel-close', 'arrow-left', 'Back to the call') +
            '<div class="tsp-kp-who"><b>' + esc(peerTitle()) + '</b>' +
            '<span class="tsp-live-timer">' + fmt(Date.now() - C.startedAt) + '</span></div>' +
            '<span style="width:30px"></span>' +
            '</div>' +
            '<div class="tsp-kp-digits">' + (esc(U.dtmf) || '&nbsp;') + '</div>' +
            keypadHtml('') +
            '<button class="tsp-hangup" data-act="hangup">' + ic('phone') + '</button>' +
            '</div>';
    }

    function panelHtml(panel) {
        var enterCls = enter('lastPanel', panel);
        if (panel === 'transfer') {
            return '<div class="tsp-panel' + enterCls + '"><h4>Blind transfer to</h4><form class="tsp-row" data-form="transfer">' +
                '<input class="tsp-input" name="dest" inputmode="tel" placeholder="Extension or number" autocomplete="off" required>' +
                '<button class="tsp-btn" type="submit">Transfer</button></form></div>';
        }
        if (panel === 'consult') {
            if (C.consult) {
                var connected = C.consult === 'connected';
                return '<div class="tsp-panel' + enterCls + '"><h4>Consulting ' + esc(C.consultTarget) + '</h4>' +
                    '<div class="tsp-state" style="margin:0 0 10px">' + (connected ? 'Connected — the caller is on hold' : 'Ringing<span class="tsp-ellipsis"></span>') + '</div>' +
                    '<div class="tsp-row">' +
                    '<button class="tsp-btn" data-act="consult-complete"' + (connected ? '' : ' disabled') + '>Complete transfer</button>' +
                    '<button class="tsp-btn is-ghost" data-act="consult-cancel">Cancel</button></div></div>';
            }
            return '<div class="tsp-panel' + enterCls + '"><h4>Consult first, then transfer</h4><form class="tsp-row" data-form="consult">' +
                '<input class="tsp-input" name="dest" inputmode="tel" placeholder="Colleague\'s extension" autocomplete="off" required>' +
                '<button class="tsp-btn" type="submit">Call</button></form></div>';
        }
        return '';
    }

    function endedHtml() {
        return whoHtml('<div class="tsp-state">' + esc(C.endedText) + '</div>', avatar('is-small'));
    }

    function setupHtml() {
        var c = C.cfg || {};
        var defaultWs = (location.protocol === 'https:' ? 'wss://' : 'ws://') + location.hostname + ':8088/ws';
        return '<form class="tsp-form" data-form="settings">' +
            '<div class="tsp-name" style="font-size:17px;text-align:center">Phone settings</div>' +
            '<label>Extension</label><input class="tsp-input" name="extension" required value="' + esc(c.extension || '') + '" placeholder="e.g. 1002">' +
            '<label>Password</label><input class="tsp-input" name="password" type="password" autocomplete="new-password" placeholder="' + (c.configured ? 'Unchanged' : 'Extension password') + '">' +
            '<label>Phone server (WebSocket)</label><input class="tsp-input" name="ws_url" required value="' + esc(c.ws_url || defaultWs) + '">' +
            '<label>Name shown on calls</label><input class="tsp-input" name="display_name" value="' + esc(c.display_name || D.user || '') + '">' +
            (C.formError ? '<div class="tsp-error">' + esc(C.formError) + '</div>' : '') +
            '<div class="tsp-row" style="margin-top:14px">' +
            '<button class="tsp-btn" type="submit" style="flex:1">Save &amp; connect</button>' +
            (c.configured ? '<button class="tsp-btn is-ghost" type="button" data-act="setup-cancel">Cancel</button>' : '') +
            '</div>' +
            '<div class="tsp-hint">Your extension on the company phone system (Asterisk). In the lab: 1002 with PASS_1002 from the install output.</div>' +
            '</form>';
    }

    // Live timer without re-rendering (keeps inputs and focus intact).
    setInterval(function () {
        if (C.view !== 'connected' || !C.startedAt) return;
        var text = fmt(Date.now() - C.startedAt);
        root.querySelectorAll('.tsp-live-timer').forEach(function (el) { el.textContent = text; });
    }, 1000);

    // ── UI actions (any window) ────────────────────────────────────────────
    var openWindow = function () { /* remote mode sets this; the engine IS the window */ };

    function handle(act, el) {
        switch (act) {
            case 'expand':
                U.open = true; store.set('tsp_open', '1');
                // Granted per site, so the phone window can alert on calls too.
                if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
                render(); return;
            case 'minimize': U.open = false; store.set('tsp_open', '0'); render(); return;
            case 'open-window': openWindow(); return;
            case 'close-window': window.close(); return;
            case 'settings': if (!inCall()) { U.setup = true; U.open = true; render(); } return;
            case 'setup-cancel': U.setup = false; C.formError = ''; render(); return;
            case 'key': key(el.getAttribute('data-key')); return;
            case 'backspace': U.dial = U.dial.slice(0, -1); syncDialInput(); return;
            case 'call': if (U.dial) { command('dial', U.dial); U.dial = ''; syncDialInput(); } return;
            case 'panel-close': U.panel = null; U.dtmf = ''; render(); return;
            case 'panel-keypad': case 'panel-transfer': case 'panel-consult':
                var p = act.slice(6);
                if (C.consult && p !== 'consult') return;
                U.panel = U.panel === p && !C.consult ? null : p; render();
                var first = root.querySelector('.tsp-panel input'); if (first) first.focus();
                return;
            default: command(act); // answer, decline, hangup, hold, mute, rec, consult-*
        }
    }

    function key(d) {
        Tone.key(d);
        if (C.view === 'connected') {
            command('dtmf', d);
            U.dtmf = (U.dtmf + d).slice(-14);  // what was pressed, for the keypad screen
            var shown = root.querySelector('.tsp-kp-digits');
            if (shown) shown.textContent = U.dtmf;
            return;
        }
        U.dial = cleanNumber(U.dial + d); syncDialInput();
    }

    function syncDialInput() {
        var input = root.querySelector('.tsp-dial');
        if (input) input.value = U.dial;
    }

    function command(name, arg) {
        if (ENGINE) { engineDo(name, arg); return; }
        if (!U.alive) { U.open = true; render(); toast('Open the phone first'); return; }
        if (bus) bus.postMessage({ type: 'cmd', name: name, arg: arg });
    }

    root.addEventListener('click', function (e) {
        var el = e.target.closest('[data-act]');
        if (!el || el.disabled) return;
        if (el.tagName === 'A') e.preventDefault();
        handle(el.getAttribute('data-act'), el);
    });

    root.addEventListener('submit', function (e) {
        var form = e.target, kind = form.getAttribute('data-form');
        if (!kind) return;
        e.preventDefault();
        if (kind === 'settings') {
            var data = {};
            new FormData(form).forEach(function (v, k) { data[k] = String(v).trim(); });
            U.setup = false; C.formError = '';
            command('save-settings', data);
            render();
            return;
        }
        // form.elements, not form.target: "target" is a built-in form property.
        var dest = form.elements.dest.value;
        U.panel = kind === 'consult' ? 'consult' : null;
        command(kind, dest);
        render();
    });

    root.addEventListener('input', function (e) {
        if (!e.target.classList.contains('tsp-dial')) return;
        var v = cleanNumber(e.target.value);
        if (v !== e.target.value) e.target.value = v;
        U.dial = v;
    });
    root.addEventListener('keydown', function (e) {
        if (e.target.classList.contains('tsp-dial') && e.key === 'Enter' && U.dial) {
            e.preventDefault(); command('dial', U.dial); U.dial = ''; syncDialInput();
        }
    });

    // Click-to-call anywhere: <a data-softphone-dial="1001">.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-softphone-dial]');
        if (!el || root.contains(el)) return;
        e.preventDefault();
        command('dial', el.getAttribute('data-softphone-dial'));
    });

    window.tvaSoftphone = {
        dial: function (n) { command('dial', n); },
        open: function () { U.open = true; render(); },
        // Quietly: a closed phone window reads the new login when it opens.
        reloadConfig: function () {
            if (ENGINE) { engineDo('reload-config'); return; }
            if (bus) bus.postMessage({ type: 'cmd', name: 'reload-config' });
        }
    };

    // ══ Remote mode: mirror the phone window ═══════════════════════════════
    if (!ENGINE) {
        var offlineState = JSON.parse(JSON.stringify(C));

        var setOffline = function () {
            if (!U.alive) return;
            U.alive = false; U.lastJson = ''; U.panel = null;
            C = JSON.parse(JSON.stringify(offlineState));
            render(); syncTones();
        };

        var applyState = function (state) {
            var json = JSON.stringify(state);
            var wasAlive = U.alive;
            U.alive = true;
            if (json === U.lastJson && wasAlive) return;
            U.lastJson = json;
            var prev = C.view;
            C = state;
            if (C.view === 'incoming' && prev !== 'incoming') { U.wasOpen = U.open; U.open = true; }
            if (!inCall() && C.view !== 'ended' && U.wasOpen !== null) { U.open = U.wasOpen; U.wasOpen = null; }
            if (!inCall()) { U.panel = null; U.dtmf = ''; }
            render(); syncTones();
        };

        if (bus) {
            bus.onmessage = function (ev) {
                var m = ev.data || {};
                if (m.type === 'state') applyState(m.state);
                else if (m.type === 'bye') setOffline();
                else if (m.type === 'toast') toast(m.text);
            };
        }

        // The lock is the truth about whether a phone window is running —
        // even one whose timers the browser has throttled.
        var checkEngine = function () {
            if (!navigator.locks || !navigator.locks.query) return;
            navigator.locks.query().then(function (snapshot) {
                var held = snapshot.held.some(function (l) { return l.name === LOCK; });
                if (held && !U.alive && bus) bus.postMessage({ type: 'hello' });
                if (!held) setOffline();
            }).catch(function () { /* keep the last known state */ });
        };

        openWindow = function () {
            // Never re-open a running phone: window.open on its name would
            // reload it — mid-call. U.alive comes from the lock check above.
            if (U.alive) { toast('The phone window is already open'); return; }
            var w = window.open(D.windowUrl, 'tva-phone', 'popup=yes,width=360,height=720');
            if (!w) toast('Allow pop-ups for this site to open the phone');
        };

        C.view = 'idle';
        render();
        if (bus) bus.postMessage({ type: 'hello' });
        checkEngine();
        setInterval(checkEngine, 3000);
        return;
    }

    // ══ Engine mode: the phone window ══════════════════════════════════════
    function publish() {
        if (bus) bus.postMessage({ type: 'state', state: C });
    }
    function changed() { render(); publish(); syncTones(); }
    function notify(text) {
        toast(text);
        if (bus) bus.postMessage({ type: 'toast', text: text });
    }

    if (bus) {
        bus.onmessage = function (ev) {
            var m = ev.data || {};
            if (m.type === 'hello') publish();
            else if (m.type === 'cmd') engineDo(m.name, m.arg);
        };
    }

    function engineDo(name, arg) {
        if (C.view === 'locked') return;
        switch (name) {
            case 'dial': dial(arg); break;
            case 'answer': answer(); break;
            case 'decline': case 'hangup': hangup(); break;
            case 'hold': toggleHold(); break;
            case 'mute': toggleMute(); break;
            case 'rec': if (C.recording) stopRecording(); else startRecording(); break;
            case 'dtmf': sendDtmf(arg); break;
            case 'transfer': blindTransfer(arg); break;
            case 'consult': startConsult(arg); break;
            case 'consult-complete': completeConsult(); break;
            case 'consult-cancel': if (E.consult) { try { E.consult.terminate(); } catch (e) { /* gone */ } } break;
            case 'save-settings': saveSettings(arg); break;
            case 'reload-config': reloadConfig(); break;
        }
    }

    // The login changed elsewhere (Telephony → Extensions → "Use in my
    // browser"): sign in with it now. Without this the window kept retrying
    // the old server — "Connecting…" forever — until it was reopened.
    function reloadConfig() {
        if (E.session) { toast('New phone login saved — it applies after this call'); return; }
        fetch(D.configUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(applyConfig)
            .catch(function () { C.reg = 'failed'; C.regError = 'settings unavailable'; changed(); });
    }

    function sipUri(target) {
        target = String(target).trim();
        if (/^sips?:/i.test(target)) return target;
        return 'sip:' + target + (target.indexOf('@') === -1 ? '@' + E.domain : '');
    }
    function callOptions() {
        return {
            mediaConstraints: { audio: true, video: false },
            pcConfig: { iceServers: C.cfg.ice_servers || [] },
            rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false }
        };
    }

    function boot() {
        window.name = 'tva-phone';
        C.view = 'boot'; changed();
        // Ask for the microphone now, while this window is in front: later
        // calls are answered from admin pages, where the prompt can't show.
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(function (s) { s.getTracks().forEach(function (t) { t.stop(); }); })
                .catch(function () { /* asked again on the first call */ });
        }
        fetch(D.configUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(applyConfig)
            .catch(function () { C.view = 'idle'; C.reg = 'failed'; C.regError = 'settings unavailable'; changed(); });
    }

    function applyConfig(cfg) {
        E.password = cfg.password || '';
        C.cfg = { configured: !!cfg.configured, extension: cfg.extension || '', ws_url: cfg.ws_url || '',
                  display_name: cfg.display_name || '', ice_servers: cfg.ice_servers || [] };
        if (!cfg.configured) { C.view = 'setup'; changed(); return; }
        C.view = 'idle'; C.formError = '';
        connect();
    }

    function connect() {
        if (E.ua) { try { E.ua.stop(); } catch (e) { /* already stopped */ } E.ua = null; }
        try { E.domain = new URL(C.cfg.ws_url.replace(/^ws/i, 'http')).hostname; } catch (e) { E.domain = location.hostname; }
        C.reg = 'connecting'; C.regError = ''; changed();

        if (window.JsSIP.debug) window.JsSIP.debug.disable('JsSIP:*');
        var ua;
        try {
            ua = new window.JsSIP.UA({
                sockets: [new window.JsSIP.WebSocketInterface(C.cfg.ws_url)],
                uri: 'sip:' + C.cfg.extension + '@' + E.domain,
                password: E.password,
                display_name: C.cfg.display_name || D.user || '',
                register: true,
                register_expires: 300,
                session_timers: false
            });
        } catch (e) {
            C.reg = 'failed'; C.regError = e.message; changed(); return;
        }
        ua.on('registered', function () { C.reg = 'online'; C.regError = ''; changed(); });
        ua.on('unregistered', function () { if (C.reg === 'online') { C.reg = 'offline'; changed(); } });
        ua.on('registrationFailed', function (e) { C.reg = 'failed'; C.regError = e.cause || ''; changed(); });
        ua.on('disconnected', function () { if (C.reg !== 'failed') { C.reg = 'connecting'; changed(); } });
        ua.on('newRTCSession', onNewSession);
        ua.start();
        E.ua = ua;
    }

    function wireMedia(session, el) {
        function attach(pc) {
            if (!pc || pc.__tsp) return;
            pc.__tsp = true;
            pc.addEventListener('track', function (e) {
                el.srcObject = e.streams[0];
                var p = el.play(); if (p && p.catch) p.catch(function () { /* resumes on interaction */ });
            });
        }
        attach(session.connection);
        session.on('peerconnection', function (e) { attach(e.peerconnection); });
    }

    function onNewSession(e) {
        var session = e.session;
        if (E.makingConsult && e.originator === 'local') { E.consult = session; wireConsult(session); return; }
        if (e.originator === 'remote' && E.session) { session.terminate({ status_code: 486, reason_phrase: 'Busy Here' }); return; }

        clearTimeout(E.endTimer);
        E.session = session; E.transferredTo = '';
        C.muted = false; C.held = false; C.startedAt = 0; C.ringback = false; U.panel = null;
        wireMedia(session, mainAudio);

        if (e.originator === 'remote') {
            C.peer = { number: session.remote_identity.uri.user || '', name: session.remote_identity.display_name || '', note: '', url: '' };
            C.view = 'incoming';
            notifyIncoming();
        } else {
            C.view = 'outgoing';
        }
        lookup(C.peer.number, session);

        session.on('progress', function () { if (C.view === 'outgoing') { C.ringback = true; changed(); } });
        session.on('accepted', connected);
        session.on('confirmed', connected);
        session.on('hold', function (ev) { if (ev.originator === 'local') { C.held = true; changed(); } });
        session.on('unhold', function (ev) { if (ev.originator === 'local') { C.held = false; changed(); } });
        session.on('muted', function () { C.muted = true; changed(); });
        session.on('unmuted', function () { C.muted = false; changed(); });
        session.on('ended', function (ev) { finish(session, ev.cause); });
        session.on('failed', function (ev) { finish(session, ev.cause); });
        changed();
    }

    function connected() {
        if (C.view === 'connected') return;
        C.view = 'connected'; C.startedAt = Date.now(); C.ringback = false;
        startQuality(E.session);
        changed();
    }

    // Call quality from the call's own statistics: packet loss, jitter and
    // how much audio the browser had to invent to hide gaps ("concealment" —
    // what choppy audio is). Shown in the footer, logged to the console.
    function startQuality(session) {
        stopQuality();
        var last = null;
        E.qualityTimer = setInterval(function () {
            var pc = session && session.connection;
            if (!pc || E.session !== session) return;
            pc.getStats().then(function (report) {
                var inb = null;
                report.forEach(function (s) { if (s.type === 'inbound-rtp' && s.kind === 'audio') inb = s; });
                if (!inb) return;
                var codecStat = inb.codecId && report.get ? report.get(inb.codecId) : null;
                var codec = codecStat && codecStat.mimeType ? codecStat.mimeType.replace(/^audio\//i, '') : '';
                var cur = { lost: inb.packetsLost || 0, recv: inb.packetsReceived || 0,
                            conc: inb.concealedSamples || 0, total: inb.totalSamplesReceived || 0 };
                if (last) {
                    var sent = (cur.recv - last.recv) + (cur.lost - last.lost);
                    var loss = sent > 0 ? (cur.lost - last.lost) / sent : 0;
                    var conceal = cur.total > last.total ? (cur.conc - last.conc) / (cur.total - last.total) : 0;
                    var jitter = (inb.jitter || 0) * 1000;
                    var level = loss > 0.05 || conceal > 0.08 || jitter > 60 ? 'poor'
                        : loss > 0.01 || conceal > 0.02 || jitter > 30 ? 'fair' : 'good';
                    var q = { level: level, codec: codec, loss: +(loss * 100).toFixed(1),
                              conceal: +(conceal * 100).toFixed(1), jitter: Math.round(jitter) };
                    if (window.console) console.info('[softphone] audio', q);
                    // Re-render only when what's shown changes (keeps typing intact).
                    var shown = C.quality ? C.quality.level + C.quality.codec : '';
                    C.quality = q;
                    if (shown !== level + codec) changed(); else publish();
                }
                last = cur;
            }).catch(function () { /* stats are best-effort */ });
        }, 3000);
    }
    function stopQuality() {
        clearInterval(E.qualityTimer); E.qualityTimer = null;
        C.quality = null;
    }

    function finish(session, cause) {
        if (session !== E.session) return;
        var wasRinging = C.view === 'incoming';
        stopRecording();
        // After an attended transfer Asterisk owns the consult leg — leave it be.
        if (E.consult && !E.transferredTo) { try { E.consult.terminate(); } catch (e) { /* gone */ } }
        var talked = C.startedAt ? fmt(Date.now() - C.startedAt) : '';
        C.endedText = E.transferredTo ? 'Transferred to ' + E.transferredTo
            : talked ? 'Call ended · ' + talked
            : (cause === 'Canceled' && !wasRinging) ? 'Call cancelled'
            : reason(cause);
        E.session = null; E.consult = null;
        stopQuality();
        C.consult = ''; C.view = 'ended'; C.muted = false; C.held = false; C.startedAt = 0; C.ringback = false;
        U.panel = null; U.dtmf = '';
        mainAudio.srcObject = null;
        changed();
        clearTimeout(E.endTimer);
        E.endTimer = setTimeout(function () {
            if (C.view !== 'ended') return;
            C.view = 'idle'; C.peer = blankPeer(); changed();
        }, 2600);
    }

    function reason(cause) {
        var map = {
            Canceled: 'Missed call', Rejected: 'Declined', Busy: 'Busy', 'No Answer': 'No answer',
            Unavailable: 'Unavailable', 'Not Found': 'Number not found', 'User Denied Media Access': 'Microphone blocked',
            'Connection Error': 'Connection lost', 'Request Timeout': 'No answer', 'Address Incomplete': 'Number incomplete'
        };
        return map[cause] || (cause ? String(cause) : 'Call ended');
    }

    function lookup(number, session) {
        if (!number || !D.lookupUrl) return;
        fetch(D.lookupUrl + '?number=' + encodeURIComponent(number), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (info) {
                if (!info || !info.found || E.session !== session) return;
                C.peer.name = C.peer.name && C.peer.name !== number ? C.peer.name : (info.name || C.peer.name);
                C.peer.note = info.note || (info.project ? 'Earlier ' + info.channel + ' conversation · ' + info.project : '');
                C.peer.url = info.url || '';
                changed();
            })
            .catch(function () { /* lookup is a nicety */ });
    }

    function notifyIncoming() {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            var n = new Notification('Incoming call', { body: peerTitle(), tag: 'tsp-incoming' });
            n.onclick = function () { window.focus(); n.close(); };
        } catch (e) { /* notifications blocked */ }
    }

    // ── Call actions ───────────────────────────────────────────────────────
    function dial(number) {
        number = cleanNumber(number);
        if (!number) return;
        if (!E.ua || C.reg !== 'online') { notify('Phone is not signed in'); return; }
        if (E.session) { notify('Finish the current call first'); return; }
        C.peer = { number: number, name: '', note: '', url: '' };
        E.ua.call(sipUri(number), callOptions());
    }

    function answer() {
        if (!E.session || C.view !== 'incoming') return;
        E.session.answer(callOptions());
    }

    function hangup() {
        if (!E.session) return;
        try {
            E.session.terminate(C.view === 'incoming' ? { status_code: 603, reason_phrase: 'Decline' } : undefined);
        } catch (e) { finish(E.session, 'Terminated'); }
    }

    function toggleHold() {
        if (!E.session || C.view !== 'connected') return;
        if (E.session.isOnHold().local) E.session.unhold(); else E.session.hold();
    }

    function toggleMute() {
        if (!E.session) return;
        if (E.session.isMuted().audio) E.session.unmute({ audio: true }); else E.session.mute({ audio: true });
    }

    function sendDtmf(d) {
        if (!E.session || C.view !== 'connected') return;
        try { E.session.sendDTMF(d, { duration: 120, interToneGap: 70 }); } catch (e) { /* not yet */ }
    }

    function blindTransfer(target) {
        target = cleanNumber(target) || String(target || '').trim();
        if (!E.session || !target) return;
        E.session.refer(sipUri(target), {
            eventHandlers: {
                requestSucceeded: function () { E.transferredTo = target; notify('Transferring to ' + target + '…'); },
                requestFailed: function () { notify('Transfer failed'); },
                failed: function () { E.transferredTo = ''; notify('Transfer failed'); }
            }
        });
    }

    function startConsult(target) {
        target = cleanNumber(target) || String(target || '').trim();
        if (!E.session || E.consult || !target) return;
        if (!E.session.isOnHold().local) E.session.hold();
        C.consultTarget = target; C.consult = 'calling';
        E.makingConsult = true;
        try { E.ua.call(sipUri(target), callOptions()); } finally { E.makingConsult = false; }
        changed();
    }

    function wireConsult(c) {
        wireMedia(c, consultAudio);
        c.on('confirmed', function () { C.consult = 'connected'; changed(); });
        var done = function () {
            if (E.consult !== c) return;
            E.consult = null; C.consult = ''; consultAudio.srcObject = null;
            if (E.session && E.session.isOnHold().local && !E.transferredTo) E.session.unhold();
            changed();
        };
        c.on('ended', done);
        c.on('failed', function () { notify('No answer from ' + C.consultTarget); done(); });
    }

    function completeConsult() {
        if (!E.session || !E.consult || C.consult !== 'connected') return;
        var target = C.consultTarget;
        E.session.refer(E.consult.remote_identity.uri.toString(), {
            replaces: E.consult,
            eventHandlers: {
                requestSucceeded: function () { E.transferredTo = target; notify('Transferred to ' + target); },
                failed: function () { E.transferredTo = ''; notify('Transfer failed'); }
            }
        });
    }

    // Local recording: both sides mixed, saved to this computer when it stops.
    function startRecording() {
        var pc = E.session && E.session.connection;
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!pc || !window.MediaRecorder || !Ctx) { notify('Recording isn\'t supported in this browser'); return; }
        var ctx = new Ctx(), dest = ctx.createMediaStreamDestination(), any = false;
        pc.getSenders().concat(pc.getReceivers()).forEach(function (x) {
            if (x.track && x.track.kind === 'audio') { ctx.createMediaStreamSource(new MediaStream([x.track])).connect(dest); any = true; }
        });
        if (!any) { ctx.close(); notify('No audio to record yet'); return; }
        var chunks = [], mr = new MediaRecorder(dest.stream), who = C.peer.number || 'call';
        mr.ondataavailable = function (ev) { if (ev.data && ev.data.size) chunks.push(ev.data); };
        mr.onstop = function () {
            ctx.close();
            var blob = new Blob(chunks, { type: mr.mimeType || 'audio/webm' });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'call-' + who + '-' + new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '') + '.webm';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
        };
        mr.start(1000);
        E.recorder = mr; C.recording = true; changed();
    }
    function stopRecording() {
        if (E.recorder && E.recorder.state !== 'inactive') E.recorder.stop();
        E.recorder = null;
        if (C.recording) { C.recording = false; changed(); }
    }

    function saveSettings(data) {
        fetch(D.saveUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': D.csrf },
            body: JSON.stringify(data || {})
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                if (!res.ok) {
                    var errs = res.body.errors ? Object.values(res.body.errors).flat() : [];
                    C.formError = errs[0] || res.body.message || 'Could not save';
                    C.view = 'setup'; changed(); return;
                }
                applyConfig(res.body);
            })
            .catch(function () { C.formError = 'Could not save — check your connection'; C.view = 'setup'; changed(); });
    }

    // The phone window owns the call: warn before it is closed or reloaded.
    window.addEventListener('beforeunload', function (e) {
        if (!E.session) return;
        e.preventDefault(); e.returnValue = '';
    });
    window.addEventListener('pagehide', function () {
        if (bus) bus.postMessage({ type: 'bye' });
        if (E.ua) { try { E.ua.stop(); } catch (e) { /* closing */ } }
    });

    // One phone per browser: hold the lock for this window's whole life.
    render();
    if (navigator.locks && navigator.locks.request) {
        navigator.locks.request(LOCK, { ifAvailable: true }, function (lock) {
            if (!lock) { C.view = 'locked'; render(); return; }
            boot();
            return new Promise(function () { /* released when the window closes */ });
        });
    } else {
        boot();
    }
})();
