/*!
 * Website "Call us" button — visitors talk to the project's AI agent in the
 * browser (WebRTC → Asterisk → the voice engine). No phone, no app.
 *
 *   <script src="https://serveai.com.pk/webcall/call.js"
 *           data-project-key="PROJECT_PUBLIC_KEY" async></script>
 *
 * Optional: data-label="Talk to our AI", data-position="left|right".
 * Any element with [data-tva-call] opens it; window.TvaCall.open() too.
 *
 * Each call asks the server for a one-call SIP account (POST /api/webcall/session);
 * the server applies the project's limits (call length, calls a day per
 * visitor, hours between calls) and explains them when it says no.
 */
(function () {
    'use strict';
    if (window.TvaCall) return;

    var script = document.currentScript || (function () {
        var s = document.querySelectorAll('script[data-project-key][src*="webcall"]');
        return s[s.length - 1];
    })();
    if (!script) return;

    var KEY = script.getAttribute('data-project-key');
    var API = (script.getAttribute('data-api') || new URL(script.src).origin).replace(/\/$/, '');
    var LABEL = script.getAttribute('data-label') || 'Talk to our AI agent';
    var SIDE = script.getAttribute('data-position') === 'right' ? 'right' : 'left';
    var JSSIP_SRC = 'https://cdn.jsdelivr.net/npm/jssip@3.12.0/dist/jssip.min.js';
    if (!KEY) { console.warn('[tva-call] data-project-key is missing'); return; }

    var ICON_PHONE = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
    var ICON_MIC = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4"/></svg>';
    var ICON_X = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

    var CSS = [
        ':host{all:initial}',
        '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}',
        '.fab{position:fixed;bottom:22px;' + SIDE + ':22px;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;color:#fff;',
        ' background:linear-gradient(135deg,#4f46e5,#7c3aed);box-shadow:0 10px 25px -6px rgba(79,70,229,.6);z-index:2147483000;',
        ' display:flex;align-items:center;justify-content:center;transition:transform .2s}',
        '.fab:hover{transform:scale(1.07)}',
        '.fab::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid #7c3aed;animation:ring 2.2s infinite}',
        '@keyframes ring{0%{transform:scale(1);opacity:.8}100%{transform:scale(1.6);opacity:0}}',
        '.tip{position:fixed;bottom:34px;' + SIDE + ':92px;background:#0f172a;color:#fff;font-size:13px;padding:7px 12px;border-radius:999px;',
        ' z-index:2147483000;white-space:nowrap;box-shadow:0 6px 18px rgba(0,0,0,.2)}',
        '.card{position:fixed;bottom:96px;' + SIDE + ':22px;width:300px;max-width:calc(100vw - 32px);background:#fff;color:#0f172a;border-radius:18px;',
        ' box-shadow:0 24px 60px -12px rgba(15,23,42,.45);z-index:2147483001;overflow:hidden;animation:pop .18s ease-out}',
        '@keyframes pop{from{transform:translateY(10px);opacity:0}to{transform:none;opacity:1}}',
        '.head{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:18px 18px 16px;position:relative}',
        '.head h3{margin:0;font-size:16px;font-weight:700}',
        '.head p{margin:4px 0 0;font-size:12px;opacity:.9}',
        '.close{position:absolute;top:12px;right:12px;background:rgba(255,255,255,.18);border:0;color:#fff;width:28px;height:28px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}',
        '.body{padding:18px;text-align:center}',
        '.avatar{width:72px;height:72px;margin:0 auto 10px;border-radius:50%;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center}',
        '.avatar.live{animation:talk 1.4s ease-in-out infinite}',
        '@keyframes talk{0%,100%{box-shadow:0 0 0 0 rgba(79,70,229,.35)}50%{box-shadow:0 0 0 12px rgba(79,70,229,0)}}',
        '.status{font-size:14px;font-weight:600;min-height:20px}',
        '.sub{font-size:12px;color:#64748b;margin-top:4px;min-height:16px}',
        '.timer{font-variant-numeric:tabular-nums;font-size:26px;font-weight:700;margin:6px 0 2px;color:#4f46e5}',
        '.row{display:flex;gap:10px;justify-content:center;margin-top:16px}',
        '.btn{border:0;border-radius:999px;padding:11px 18px;font-size:14px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px}',
        '.go{background:#16a34a;color:#fff}.go:hover{background:#15803d}',
        '.end{background:#dc2626;color:#fff}.end:hover{background:#b91c1c}',
        '.mute{background:#f1f5f9;color:#0f172a}.mute.on{background:#fee2e2;color:#b91c1c}',
        '.note{font-size:11px;color:#94a3b8;margin-top:12px}',
        '.hidden{display:none!important}'
    ].join('');

    var host = document.createElement('div');
    host.id = 'tva-call';
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    root.innerHTML = '<style>' + CSS + '</style>' +
        '<button class="fab" aria-label="' + esc(LABEL) + '">' + ICON_PHONE + '</button>' +
        '<div class="tip hidden">' + esc(LABEL) + '</div>' +
        '<div class="card hidden" role="dialog" aria-label="' + esc(LABEL) + '">' +
        '  <div class="head"><h3>' + esc(LABEL) + '</h3><p>Free call from your browser — just allow the microphone.</p>' +
        '    <button class="close" aria-label="Close">' + ICON_X + '</button></div>' +
        '  <div class="body">' +
        '    <div class="avatar">' + ICON_PHONE + '</div>' +
        '    <div class="status">Ready when you are</div>' +
        '    <div class="timer hidden">1:00</div>' +
        '    <div class="sub">Our AI agent answers in English or Urdu.</div>' +
        '    <div class="row">' +
        '      <button class="btn go">' + ICON_PHONE.replace(/24/g, '18') + 'Call now</button>' +
        '      <button class="btn mute hidden">' + ICON_MIC + 'Mute</button>' +
        '      <button class="btn end hidden">End</button>' +
        '    </div>' +
        '    <div class="note">Calls are limited in length and number per visitor.</div>' +
        '  </div>' +
        '</div>';
    var audio = document.createElement('audio');
    audio.autoplay = true;
    root.appendChild(audio);

    function $(sel) { return root.querySelector(sel); }
    var ui = { fab: $('.fab'), tip: $('.tip'), card: $('.card'), status: $('.status'), sub: $('.sub'), timer: $('.timer'),
               go: $('.go'), mute: $('.mute'), end: $('.end'), avatar: $('.avatar'), close: $('.close') };

    var state = { busy: false, ua: null, session: null, stream: null, tick: null, muted: false };

    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
    function show(el, on) { el.classList.toggle('hidden', !on); }
    function say(status, sub) { ui.status.textContent = status; if (sub !== undefined) ui.sub.textContent = sub; }

    function open() { show(ui.card, true); show(ui.tip, false); }
    function close() { if (state.busy) return; show(ui.card, false); }

    ui.fab.addEventListener('click', function () { ui.card.classList.contains('hidden') ? open() : close(); });
    ui.fab.addEventListener('mouseenter', function () { if (ui.card.classList.contains('hidden')) show(ui.tip, true); });
    ui.fab.addEventListener('mouseleave', function () { show(ui.tip, false); });
    ui.close.addEventListener('click', function () { if (state.busy) hangup(); show(ui.card, false); });
    ui.go.addEventListener('click', call);
    ui.end.addEventListener('click', hangup);
    ui.mute.addEventListener('click', toggleMute);
    document.addEventListener('click', function (e) {
        var t = e.target && e.target.closest && e.target.closest('[data-tva-call]');
        if (t) { e.preventDefault(); open(); }
    });

    function loadJsSIP() {
        return new Promise(function (resolve, reject) {
            if (window.JsSIP) return resolve(window.JsSIP);
            var s = document.createElement('script');
            s.src = JSSIP_SRC; s.async = true;
            s.onload = function () { window.JsSIP ? resolve(window.JsSIP) : reject(new Error('jssip')); };
            s.onerror = function () { reject(new Error('jssip')); };
            document.head.appendChild(s);
        });
    }

    function setCalling(on) {
        state.busy = on;
        show(ui.go, !on); show(ui.end, on); show(ui.mute, on);
        ui.avatar.classList.toggle('live', on);
        if (!on) { show(ui.timer, false); clearInterval(state.tick); }
    }

    function call() {
        if (state.busy) return;
        if (!window.RTCPeerConnection || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            say('Your browser can’t make calls', 'Please try Chrome, Edge, Safari or Firefox.');
            return;
        }
        setCalling(true);
        say('Allow the microphone…', 'Your browser will ask for permission.');

        navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } })
            .then(function (stream) {
                state.stream = stream;
                say('Connecting…', 'Setting up a secure line.');
                return fetch(API + '/api/webcall/session', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ key: KEY, page: location.href })
                }).then(function (r) { return r.json().catch(function () { return {}; }); });
            })
            .then(function (s) {
                if (!s || !s.ok) {
                    fail(s && s.message ? s.message : 'Calling isn’t available right now.', s && s.reason === 'daily_limit' ? 'Thank you for your interest!' : '');
                    return;
                }
                return loadJsSIP().then(function (JsSIP) { dial(JsSIP, s); });
            })
            .catch(function (err) {
                if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) {
                    fail('Microphone blocked', 'Allow the microphone in your browser’s address bar, then try again.');
                } else if (err && err.name === 'NotFoundError') {
                    fail('No microphone found', 'Connect a microphone or headset and try again.');
                } else {
                    fail('Couldn’t connect', 'Please check your internet and try again.');
                }
            });
    }

    function dial(JsSIP, s) {
        var ua = new JsSIP.UA({
            sockets: [new JsSIP.WebSocketInterface(s.ws_url)],
            uri: 'sip:' + s.username + '@' + s.domain,
            password: s.password,
            register: false,
            session_timers: false,
            user_agent: 'serveAI WebCall'
        });
        state.ua = ua;
        var started = false;

        ua.on('connected', function () {
            if (started) return;
            started = true;
            var hear = function (pc) {
                pc.addEventListener('track', function (ev) {
                    // Asterisk's answer carries no stream id, so ev.streams
                    // is empty: wrap the bare track, or the AI is silent.
                    audio.srcObject = (ev.streams && ev.streams[0]) || new MediaStream([ev.track]);
                    audio.play().catch(function () {});
                });
            };
            var session = ua.call('sip:' + s.dial + '@' + s.domain, {
                mediaStream: state.stream,
                pcConfig: { iceServers: s.ice_servers || [], rtcpMuxPolicy: 'require' },
                rtcOfferConstraints: { offerToReceiveAudio: true, offerToReceiveVideo: false },
                // Passed in, not attached afterwards: ua.call() creates the
                // peer connection before it returns.
                eventHandlers: { peerconnection: function (e) { hear(e.peerconnection); } }
            });
            state.session = session;
            // Don't wait for every ICE candidate: one reachable address is enough.
            var iceTimer = null;
            session.on('icecandidate', function (e) {
                if (/typ (srflx|relay)/.test(e.candidate.candidate)) { e.ready(); return; }
                if (!iceTimer) iceTimer = setTimeout(function () { e.ready(); }, 1500);
            });
            session.on('progress', function () { say('Ringing…', ''); });
            session.on('confirmed', function () {
                say('Connected', 'Say hello — the AI agent is listening.');
                countdown(s.max_seconds || 60);
            });
            session.on('ended', function () { finish('Call ended', 'Thanks for talking to us!'); });
            session.on('failed', function (e) {
                finish('Call didn’t connect', e && e.cause === 'Rejected' ? 'Please try again later.' : 'Please try again in a moment.');
            });
        });
        ua.on('disconnected', function () { if (!started) fail('Couldn’t reach our phone line', 'Please try again in a few minutes.'); });
        ua.start();
    }

    function countdown(seconds) {
        var end = Date.now() + seconds * 1000;
        show(ui.timer, true);
        var paint = function () {
            var left = Math.max(0, Math.round((end - Date.now()) / 1000));
            ui.timer.textContent = Math.floor(left / 60) + ':' + ('0' + (left % 60)).slice(-2);
        };
        paint();
        clearInterval(state.tick);
        state.tick = setInterval(paint, 500);
    }

    function toggleMute() {
        if (!state.session) return;
        state.muted = !state.muted;
        state.muted ? state.session.mute({ audio: true }) : state.session.unmute({ audio: true });
        ui.mute.classList.toggle('on', state.muted);
        ui.mute.lastChild.textContent = state.muted ? 'Unmute' : 'Mute';
    }

    function hangup() {
        if (state.session && !state.session.isEnded()) {
            try { state.session.terminate(); } catch (e) { /* already gone */ }
        } else {
            finish('Call ended', '');
        }
    }

    function cleanup() {
        if (state.stream) state.stream.getTracks().forEach(function (t) { t.stop(); });
        if (state.ua) { try { state.ua.stop(); } catch (e) { /* ignore */ } }
        audio.srcObject = null;
        state.stream = state.ua = state.session = null;
        state.muted = false;
        ui.mute.classList.remove('on');
        ui.mute.lastChild.textContent = 'Mute';
    }

    function finish(status, sub) { cleanup(); setCalling(false); say(status, sub); }
    function fail(status, sub) { finish(status, sub); }

    window.TvaCall = { open: open, close: close };
    (document.body || document.documentElement).appendChild(host);
})();
