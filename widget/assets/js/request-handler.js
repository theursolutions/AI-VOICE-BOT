/**
 * request-handler.js — bridges the widget UI to the Voice CRM Agent
 * contract. Two paths share a single state machine:
 *
 *   1. WebSocket streaming (preferred): on session start we open
 *      ws_url with the JWT, then text submits become `text` frames
 *      and voice submits stream `audio.chunk` frames. Replies stream
 *      back as `llm.delta` (text) and `audio.chunk` (TTS).
 *
 *   2. HTTP turn fallback: if the WS isn't open in time (or fails),
 *      we POST /api/v1/sessions/{id}/turn synchronously and render
 *      the full reply at once. Same look as before.
 *
 * The state cache lives on `window.tvaibwcSession` so the voice button
 * and other widget modules can read it.
 */

/* ─────────────── Send-button lifecycle manager + watchdog ─────────────── */
/**
 * Single source of truth for the "can the user send another message?"
 * state. Replaces the scattered $('#tvaibwc-sendButton').prop('disabled', ...)
 * calls that used to race each other.
 *
 * Why this exists:
 *   The original code relied on the WS server firing `llm.final` AND
 *   `turn.end` in lockstep to re-enable the button. In voice-reply mode
 *   (response_with=voice) the server sometimes ships only audio chunks
 *   plus turn.end (no llm.final). If turn.end then arrives slightly
 *   malformed or doesn't fire at all (TTS error, dropped frame), the
 *   send button stays disabled until the 60s failsafe — making it look
 *   completely broken on the very next message.
 *
 * Behaviour:
 *   markTurnStart()  — call once when a submit is in flight.
 *                      Disables the button + arms a 12 s watchdog that
 *                      auto-clears the state.
 *   markTurnEnd()    — call from EVERY successful completion path.
 *                      Idempotent; safe to call twice.
 *   forceEnable()    — emergency unlock (used by the mode-toggle and
 *                      voice-button handlers). Always re-enables.
 *
 * Also exposes a passive 2s tick that checks "button has been disabled
 * with no in-flight turn for > 5s" and unsticks it. Belt + braces.
 */
(function () {
    if (window.tvaibwcSendBtn) return;
    var $btn = function () { return $('#tvaibwc-sendButton'); };
    var turnInFlight  = false;
    var lastStartAt   = 0;
    var watchdogTimer = null;

    function setDisabled(disabled) {
        try { $btn().prop('disabled', !!disabled); } catch (_) {}
    }

    // The watchdog measures SILENCE, not elapsed time — it resets
    // every time the server sends ANY frame (delta, audio chunk,
    // stt.partial…). A long TTS pass on CPU is normal (~1s/char),
    // and would falsely trip a wall-clock timer; silence detection
    // only fires when the server has genuinely gone unresponsive.
    var WATCHDOG_SILENCE_MS = 20000;

    function armWatchdog() {
        if (watchdogTimer) clearTimeout(watchdogTimer);
        watchdogTimer = setTimeout(function () {
            console.warn('[tvaibwc] watchdog fired — no server frames for', WATCHDOG_SILENCE_MS, 'ms, re-enabling send');
            turnInFlight = false;
            setDisabled(false);
        }, WATCHDOG_SILENCE_MS);
    }
    function bumpWatchdog() {
        if (turnInFlight) armWatchdog();
    }
    function markTurnStart() {
        turnInFlight = true;
        lastStartAt  = Date.now();
        setDisabled(true);
        armWatchdog();
    }
    function markTurnEnd() {
        turnInFlight = false;
        if (watchdogTimer) { clearTimeout(watchdogTimer); watchdogTimer = null; }
        setDisabled(false);
    }
    function forceEnable() {
        markTurnEnd();
    }

    // Passive tick: if the button has stayed disabled with no in-flight
    // turn (state desync between handlers) for > 5s, unstick. Cheap.
    setInterval(function () {
        if (!turnInFlight && $btn().prop('disabled')) {
            console.warn('[tvaibwc] passive recovery — button disabled but no turn in flight');
            setDisabled(false);
        }
    }, 2000);

    window.tvaibwcSendBtn = {
        markTurnStart: markTurnStart,
        markTurnEnd:   markTurnEnd,
        forceEnable:   forceEnable,
        isInFlight:    function () { return turnInFlight; },
    };

    // Re-enable defensively when the user toggles voice-reply mode —
    // they're clearly intending to send another message, so unstick
    // any stale disabled state from the prior turn.
    $(document).on('change', '#tvaibwc-replyToggle', function () {
        forceEnable();
    });
})();

/* ───────────────────── voice-note bubble styles ───────────────────────── */
/* Injected once at module load so we don't need to touch the CSS file. */
(function () {
    if (document.getElementById('tvaibwc-vn-styles')) return;
    var css = ''
        + '.tvaibwc-vn{display:flex;align-items:center;gap:8px;padding:6px 4px}'
        + '.tvaibwc-vn-play,.tvaibwc-vn-stop{width:32px;height:32px;border-radius:50%;'
        +   'border:0;color:#fff;cursor:pointer;flex-shrink:0;'
        +   'display:flex;align-items:center;justify-content:center;font-size:12px}'
        + '.tvaibwc-vn-play{background:#4a90e2}'
        + '.tvaibwc-vn-play:hover{background:#3a7bc8}'
        + '.tvaibwc-vn-stop{background:#d94a4a;width:26px;height:26px;font-size:10px}'
        + '.tvaibwc-vn-stop:hover{background:#b73838}'
        + '.tvaibwc-vn-track{position:relative;flex:1;height:6px;min-width:80px;'
        +   'background:rgba(0,0,0,0.08);border-radius:3px;overflow:hidden}'
        + '.dark .tvaibwc-vn-track{background:rgba(255,255,255,0.12)}'
        + '.tvaibwc-vn-buffered{position:absolute;top:0;left:0;height:100%;width:0;'
        +   'background:rgba(74,144,226,0.25);transition:width 0.2s linear}'
        + '.tvaibwc-vn-progress{position:absolute;top:0;left:0;height:100%;width:0;'
        +   'background:#4a90e2;transition:width 0.1s linear}'
        + '.tvaibwc-vn-duration{font-size:11px;font-variant-numeric:tabular-nums;'
        +   'min-width:64px;text-align:right;color:#666}'
        + '.dark .tvaibwc-vn-duration{color:#aaa}';
    var style = document.createElement('style');
    style.id = 'tvaibwc-vn-styles';
    style.textContent = css;
    document.head.appendChild(style);
})();

window.tvaibwcSession = window.tvaibwcSession || {
    session_id: null,
    token: null,
    ws_url: null,
    expires_in: 0,
    starting: false,
    ws: null,          // TvaibwcWsClient instance once connected
    wsReady: false,
    playback: null,    // TvaibwcAudioPlayback
    mic: null,         // TvaibwcMicRecorder
    _pending: null
};

/* ───────────────────────── session bootstrap ──────────────────────────── */

function tvaibwcEnsureSession(profile) {
    var s = window.tvaibwcSession;
    if (s.session_id) return Promise.resolve(s);
    if (s._pending)   return s._pending;

    s.starting = true;
    s._pending = window.TvaibwcApi.startSession(profile || {})
        .then(function (envelope) {
            s.starting = false;
            s._pending = null;

            if (!envelope || !envelope.success) {
                throw new Error((envelope && envelope.message) || 'startSession failed');
            }
            var data = envelope.response || {};
            s.session_id = data.session_id || null;
            s.token      = data.token      || null;
            s.ws_url     = data.ws_url     || null;
            s.expires_in = data.expires_in || 0;

            // Once a session is live, the visitor's UI should be in
            // "actively chatting" mode regardless of which entry path
            // started the session: hide the Start-chat CTA, reveal
            // the input row. This used to only happen on the
            // start_chat_session form submit path, which left the
            // auto-bootstrap path (flows opening on first chat-toggle
            // click) stuck with no input and an overlaying Start-chat.
            $('#tvaibwc-startChatButton').hide().removeClass('is-visible');
            $('#tvaibwc-chatInputContainer').addClass('active').removeClass('is-ended');
            $('.tvaibwc-customer-form').hide();
            $('#tvaibwc-widgetTabs').hide();
            // Mirror tab visibility on the widget so CSS can re-position
            // bottom-anchored controls (Start Chat) without overlapping
            // the absent tab bar.
            $('#tvaibwc-chatWidget').addClass('no-tabs');
            $('#tvaibwc-backToTabs').show();

            // Phase 2 — webchat flows. If the widget is bound to a default
            // flow, the session-start response includes a `flow` block with
            // the first batch of messages already walked. Render them and
            // flip the session into flow_active mode so chat-submit routes
            // user input through /flow/step instead of /turn.
            if (data.flow && window.TvaibwcFlow) {
                try { window.TvaibwcFlow.apply(data.flow); }
                catch (e) { console.warn('[tvaibwc] flow render failed', e); }
            }

            // Open the streaming socket up-front (unless flow has not yet
            // handed off — flows defer WS open to the transfer_ai node so
            // we don't burn idle WS connections during deterministic IVR
            // steps). The WS opens lazily inside TvaibwcFlow.apply when
            // it sees a handoff block.
            if (!s.flow_active) {
                tvaibwcConnectWs();
            }

            return s;
        })
        .catch(function (err) {
            s.starting = false;
            s._pending = null;
            throw err;
        });

    return s._pending;
}

function tvaibwcConnectWs() {
    var s = window.tvaibwcSession;
    if (!s.token || !s.ws_url || !window.TvaibwcWsClient) return;

    s.playback = s.playback || new window.TvaibwcAudioPlayback();

    s.ws = new window.TvaibwcWsClient(s.ws_url, s.token, {
        onOpen: function () {
            s.wsReady = true;
            console.log('[tvaibwc] WS ready');
        },
        onClose: function () {
            s.wsReady = false;
            console.log('[tvaibwc] WS closed');
        },
        onError: function (code, msg) {
            // This fires for BOTH transport failures and per-turn server
            // `error` frames (e.g. empty_input, stt_failed, tts_failed). A
            // per-turn error must NOT disable the streaming path — otherwise
            // a single bad turn forces every later turn onto the slow HTTP
            // fallback (which surfaces as "Upstream API error"). Real
            // connection loss is handled by onClose, the only place that
            // clears wsReady.
            console.warn('[tvaibwc] WS error', code, msg);
            // Re-enable send button so the user can try again — without
            // this a single LLM error leaves the input frozen.
            window.tvaibwcSendBtn.forceEnable();
        },
        onSttPartial: function (text) {
            // Render incoming transcription in the user's own bubble as it
            // gets recognised. tvaibwcLastUserBubble holds the latest.
            if (window.tvaibwcLastUserBubble) {
                window.tvaibwcLastUserBubble.find('.tvaibwc-message-text').text(text);
            }
        },
        onSttFinal: function (text) {
            if (window.tvaibwcLastUserBubble) {
                window.tvaibwcLastUserBubble.find('.tvaibwc-message-text').text(text);
            }
        },
        onLlmDelta: function (chunk) {
            if (window.tvaibwcStreamingBubble) {
                window.tvaibwcStreamingBubble.append(chunk);
            }
        },
        onLlmFinal: function (text /* , frame */) {
            if (window.tvaibwcStreamingBubble) {
                window.tvaibwcStreamingBubble.replace(text);
            }
            // Belt-and-braces: the LLM text is the actual user-visible
            // reply. Once it's delivered, allow the next turn even if
            // turn.end somehow doesn't arrive (TTS error, dropped frame).
            window.tvaibwcSendBtn.markTurnEnd();
        },
        onAudioChunk: function (b64, format /*, seq */) {
            // Voice bubble accumulates PCM silently — playback only
            // happens when the user clicks ▶. We deliberately do NOT
            // push to s.playback so the server stays in charge.
            var b = window.tvaibwcStreamingBubble;
            if (b && b.isVoice && typeof b.onChunk === 'function') {
                b.onChunk(b64, format, 24000);
            }
        },
        onAudioEnd: function () {
            // No live playback to flush. Reset the queue so the legacy
            // HTTP-fallback path (which still uses s.playback) keeps
            // working in case it's used later.
            if (s.playback) s.playback.flushAndClose();
            s.playback = new window.TvaibwcAudioPlayback();
        },
        onTurnEnd: function (latencyMs, audioUrl) {
            if (window.tvaibwcStreamingBubble) {
                window.tvaibwcStreamingBubble.finalize(audioUrl);
                window.tvaibwcStreamingBubble = null;
            }
            // Re-enable the send button so the user can ask the next question.
            window.tvaibwcSendBtn.markTurnEnd();
            console.log('[tvaibwc] turn complete in', latencyMs, 'ms', audioUrl || '(no audio_url)');
        }
    });
    s.ws.connect();
}

/* ─────────────────── streaming text bubble helper ─────────────────────── */
/**
 * Wraps a DOM node so we can append `llm.delta` chunks as they arrive
 * and finalize when `turn.end` fires.
 */
function tvaibwcMakeStreamingBubble() {
    var dark = $('#tvaibwc-themeToggle').is(':checked');
    var $bubble = $(
        '<div class="tvaibwc-message tvaibwc-bot ' + (dark ? 'dark' : '') + '">' +
            '<div class="tvaibwc-message-text typing">' +
                '<span class="typing-dots"><span></span><span></span><span></span></span>' +
            '</div>' +
            '<div class="tvaibwc-message-time">' + tvaibwc_getCurrentTime() + '</div>' +
        '</div>'
    );
    $('#tvaibwc-chatMessages').append($bubble);
    tvaibwc_scrollToBottom();

    var $text = $bubble.find('.tvaibwc-message-text');
    var buffer = '';
    var streaming = false;

    return {
        $bubble: $bubble,
        append: function (chunk) {
            if (!streaming) {
                $text.removeClass('typing').empty();
                streaming = true;
            }
            buffer += chunk;
            $text.html(tvaibwc_textToHtml(buffer));
            tvaibwc_scrollToBottom();
        },
        replace: function (text) {
            $text.removeClass('typing').html(tvaibwc_textToHtml(text));
            buffer = text;
            streaming = true;
            tvaibwc_scrollToBottom();
        },
        finalize: function () {
            if (!streaming) {
                $text.removeClass('typing').text('(no reply)');
            }
        },
        renderHttpReply: function (text, audioUrl, voiceReply) {
            // Used when we fell back to HTTP and got the whole reply at once.
            if (voiceReply && audioUrl) {
                var audioId = 'tvaibwc-audio-bot-' + Date.now();
                $bubble.remove();
                tvaibwc_addAudioMessage(audioId, audioUrl, 5, 'tvaibwc-bot');
            } else if (text) {
                // Render with real line breaks. (We drop the per-char
                // typewriter here — it appended one character at a time,
                // which split "<br>" into visible characters; the WS
                // path already gives the live-typing feel.)
                $text.removeClass('typing voice').html(tvaibwc_textToHtml(text));
            } else {
                $text.removeClass('typing voice').text('(empty response)');
            }
        },
        remove: function () { $bubble.remove(); }
    };
}

/* ───────────────────── voice-note bubble factory ──────────────────────── */
/**
 * WhatsApp-style streaming voice note. Shows animated bars + duration timer
 * while audio chunks arrive. Stop button triggers barge-in (kills local
 * playback immediately + tells server to stop generating).
 *
 * Public surface matches tvaibwcMakeStreamingBubble so the WS handlers can
 * call into either one without checking the shape. Text methods (append/
 * replace) are no-ops because voice mode hides the transcript.
 */
function tvaibwcMakeVoiceBubble() {
    var dark = $('#tvaibwc-themeToggle').is(':checked');
    var startedAt = Date.now();

    // State machine:
    //   'paused'    — DEFAULT. Chunks accumulate silently in pcmChunks; no
    //                 audio plays. User clicks ▶ to hear what's buffered.
    //   'playing'   — Audio element is playing the snapshot of buffered PCM.
    //                 New chunks keep accumulating; user can pause + re-play
    //                 to hear newer content (snapshot rebuilds).
    //   'done'      — turn.end received. If server gave us a file URL we
    //                 switch the source to it (survives page reload).
    var state    = 'paused';
    var pcmChunks = [];     // accumulated Int16Array chunks
    var totalSamples = 0;
    var sampleRate = 24000;
    var audioEl  = null;    // built lazily on first ▶ click
    var serverAudioUrl = null;  // populated at turn.end if available

    var $bubble = $(
        '<div class="tvaibwc-message tvaibwc-bot ' + (dark ? 'dark' : '') + '">' +
            '<div class="tvaibwc-vn">' +
                '<button class="tvaibwc-vn-play" title="Play">' +
                    '<i class="fas fa-play"></i>' +
                '</button>' +
                '<div class="tvaibwc-vn-track">' +
                    '<div class="tvaibwc-vn-buffered"></div>' +
                    '<div class="tvaibwc-vn-progress"></div>' +
                '</div>' +
                '<div class="tvaibwc-vn-duration">0:00</div>' +
                '<button class="tvaibwc-vn-stop" title="Stop generating">' +
                    '<i class="fas fa-times"></i>' +
                '</button>' +
            '</div>' +
            '<div class="tvaibwc-message-time">' + tvaibwc_getCurrentTime() + '</div>' +
        '</div>'
    );
    $('#tvaibwc-chatMessages').append($bubble);
    tvaibwc_scrollToBottom();

    var $play     = $bubble.find('.tvaibwc-vn-play');
    var $stop     = $bubble.find('.tvaibwc-vn-stop');
    var $duration = $bubble.find('.tvaibwc-vn-duration');
    var $buffered = $bubble.find('.tvaibwc-vn-buffered');
    var $progress = $bubble.find('.tvaibwc-vn-progress');

    var fmt = function (secs) {
        return Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
    };

    // Counter ticks while chunks are still arriving. The buffered bar
    // reflects ACTUAL accumulated audio duration, not wall-clock time.
    var streamTimer = setInterval(function () {
        if (state === 'done') return;
        var bufSecs = totalSamples / sampleRate;
        $duration.text(fmt(Math.floor(bufSecs)));
        // Smooth fill, but never claim 100% until done.
        // Aim for ~30s typical reply but cap at 95% during streaming.
        var pct = Math.min(95, (bufSecs / 30) * 100);
        $buffered.css('width', pct + '%');
    }, 200);

    // ─── chunk arrival → accumulate PCM (no autoplay) ──────────────────
    function onChunk(b64, format, sr) {
        if (state === 'done') return;
        sampleRate = sr || sampleRate;
        if (!b64) return;
        try {
            var bin = atob(b64);
            var bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            var chunk = new Int16Array(bytes.buffer, bytes.byteOffset, bytes.length / 2);
            pcmChunks.push(chunk);
            totalSamples += chunk.length;
        } catch (_) {}
    }

    // ─── Build a WAV blob from everything we've buffered so far ─────────
    function buildWavBlob() {
        var total = 0;
        for (var i = 0; i < pcmChunks.length; i++) total += pcmChunks[i].length;
        var data = new Int16Array(total);
        var off = 0;
        for (var j = 0; j < pcmChunks.length; j++) {
            data.set(pcmChunks[j], off);
            off += pcmChunks[j].length;
        }
        var byteRate  = sampleRate * 2;
        var dataSize  = data.byteLength;
        var fileSize  = 36 + dataSize;
        var hdr = new ArrayBuffer(44);
        var v   = new DataView(hdr);
        var ws  = function (off, s) {
            for (var k = 0; k < s.length; k++) v.setUint8(off + k, s.charCodeAt(k));
        };
        ws(0, 'RIFF');
        v.setUint32(4, fileSize, true);
        ws(8, 'WAVE');
        ws(12, 'fmt ');
        v.setUint32(16, 16, true);
        v.setUint16(20, 1, true);
        v.setUint16(22, 1, true);
        v.setUint32(24, sampleRate, true);
        v.setUint32(28, byteRate, true);
        v.setUint16(32, 2, true);
        v.setUint16(34, 16, true);
        ws(36, 'data');
        v.setUint32(40, dataSize, true);
        return new Blob([hdr, data.buffer], { type: 'audio/wav' });
    }

    function rebuildAudio(rememberTime) {
        var t = rememberTime && audioEl ? audioEl.currentTime : 0;
        if (audioEl) {
            try { audioEl.pause(); } catch (_) {}
            // Only revoke if we built it from a blob (object URL).
            if (audioEl.src && audioEl.src.indexOf('blob:') === 0) {
                try { URL.revokeObjectURL(audioEl.src); } catch (_) {}
            }
        }

        // Preferred source: server file URL (when turn is done). Survives
        // page reload and is the file we persisted in messages.audio_url.
        // Fallback: blob assembled from accumulated PCM (works during
        // streaming or if server didn't return a URL).
        var src = null;
        if (state === 'done' && serverAudioUrl) {
            src = serverAudioUrl;
        } else if (pcmChunks.length) {
            src = URL.createObjectURL(buildWavBlob());
        }
        if (!src) return;

        audioEl = new Audio(src);
        audioEl.addEventListener('timeupdate', function () {
            if (!audioEl.duration) return;
            $progress.css('width', (audioEl.currentTime / audioEl.duration * 100) + '%');
            if (state === 'done') {
                $duration.text(fmt(Math.floor(audioEl.currentTime)) + ' / ' + fmt(Math.floor(audioEl.duration)));
            }
        });
        audioEl.addEventListener('ended', function () {
            if (state === 'playing') state = 'paused';
            $play.html('<i class="fas fa-play"></i>').attr('title', 'Play');
        });
        if (rememberTime && t) {
            audioEl.addEventListener('loadedmetadata', function () {
                audioEl.currentTime = Math.min(t, audioEl.duration || t);
            }, { once: true });
        }
    }

    // ─── Buttons ───────────────────────────────────────────────────────
    $play.on('click', function () {
        if (state === 'paused') {
            // Build a fresh snapshot from whatever has accumulated so far
            // and start playing it. If more chunks arrive while we're
            // playing, they keep accumulating; user can pause + replay
            // to hear the newer content.
            if (!pcmChunks.length && !serverAudioUrl) return;
            rebuildAudio(false);
            if (audioEl) {
                state = 'playing';
                audioEl.play().then(function () {
                    $play.html('<i class="fas fa-pause"></i>').attr('title', 'Pause');
                }).catch(function (err) {
                    console.warn('[tvaibwc] play failed', err);
                    state = 'paused';
                });
            }
            return;
        }

        if (state === 'playing') {
            if (audioEl) {
                try { audioEl.pause(); } catch (_) {}
            }
            state = 'paused';
            $play.html('<i class="fas fa-play"></i>').attr('title', 'Play');
            return;
        }

        if (state === 'done') {
            if (audioEl && audioEl.paused) {
                audioEl.play();
                $play.html('<i class="fas fa-pause"></i>').attr('title', 'Pause');
            } else if (audioEl) {
                audioEl.pause();
                $play.html('<i class="fas fa-play"></i>').attr('title', 'Play');
            }
        }
    });

    $stop.on('click', function () {
        if (state === 'done') return;
        // Tell the server to stop generating. Whatever has accumulated
        // so far remains playable.
        try { if (typeof window.tvaibwcBargeIn === 'function') window.tvaibwcBargeIn(); } catch (_) {}
        if (audioEl) { try { audioEl.pause(); } catch (_) {} }
        finishToDone(null);
    });

    function finishToDone(audioUrl) {
        if (state === 'done') return;
        state = 'done';
        clearInterval(streamTimer);
        $buffered.css('width', '100%');
        if (audioUrl) serverAudioUrl = audioUrl;
        // Prefer the server-side URL for replay (survives page reload).
        // Fall back to building from accumulated PCM if no URL was given.
        rebuildAudio(false);
        $stop.hide();
        if (audioEl) {
            audioEl.addEventListener('loadedmetadata', function () {
                $duration.text('0:00 / ' + fmt(Math.floor(audioEl.duration || 0)));
            }, { once: true });
        }
        $play.html('<i class="fas fa-play"></i>').attr('title', 'Play');
    }

    return {
        $bubble:  $bubble,
        isVoice:  true,
        getState: function () { return state; },
        append:   function () {},
        replace:  function () {},
        onChunk:  onChunk,
        finalize: function (audioUrl) {
            // Called when turn.end fires. Lock the bubble into 'done'
            // state and switch to the server file URL if provided.
            finishToDone(audioUrl || null);
        },
        renderHttpReply: function (text, audioUrl, voiceReply) {
            state = 'done';
            clearInterval(streamTimer);
            $bubble.remove();
            if (voiceReply && audioUrl) {
                var id = 'tvaibwc-audio-bot-' + Date.now();
                tvaibwc_addAudioMessage(id, audioUrl, 5, 'tvaibwc-bot');
            } else if (text) {
                $('#tvaibwc-chatMessages').append(
                    '<div class="tvaibwc-message tvaibwc-bot ' + (dark ? 'dark' : '') + '">' +
                        '<div class="tvaibwc-message-text">' + tvaibwc_textToHtml(text) + '</div>' +
                        '<div class="tvaibwc-message-time">' + tvaibwc_getCurrentTime() + '</div>' +
                    '</div>'
                );
            }
            tvaibwc_scrollToBottom();
        },
        remove: function () {
            clearInterval(streamTimer);
            if (audioEl) { try { audioEl.pause(); URL.revokeObjectURL(audioEl.src); } catch (_) {} }
            $bubble.remove();
        }
    };
}

/* ───────────────────────── widget bootstrap ───────────────────────────── */

$(document).ready(function () {
    $('#tvaibwc-chatToggle').on('click', function () {
        var s = window.tvaibwcSession;
        if (!s.session_id && !s.starting) {
            tvaibwcEnsureSession().catch(function (e) {
                console.warn('[tvaibwc] eager session start failed', e);
            });
        }
    });
});

$(document).on('submit', '#start_chat_session', function (e) {
    e.preventDefault();
    var fd = new FormData(this);
    var profile = {
        customer_name:  fd.get('customer_name')  || '',
        customer_phone: fd.get('customer_phone') || '',
        customer_email: fd.get('customer_email') || ''
    };

    var s = window.tvaibwcSession;
    s.session_id = null;
    s._pending   = null;
    if (s.ws) { try { s.ws.close(); } catch (_) {} s.ws = null; s.wsReady = false; }

    tvaibwcEnsureSession(profile)
        .then(function () {
            $('.tvaibwc-widget-tab[data-tab="tvaibwc-chat"]').click();
            $('.tvaibwc-customer-form').hide();
            $('#tvaibwc-startChatButton').hide();
            $('#tvaibwc-chatInputContainer').addClass('active');
            $('#tvaibwc-widgetTabs').hide();
            $('#tvaibwc-backToTabs').show();
            $('#tvaibwc-chatInput').focus();
        })
        .catch(function (err) {
            $('#responseBox').html('Error: ' + (err && err.message ? err.message : err));
        });
});

/* ───────────────────────── chat submit ────────────────────────────────── */

$(document).on('submit', '#create_chat_response', function (e) {
    e.preventDefault();
    tvaibwc_sendMessage();   // adds the user's bubble to the DOM

    // Capture a reference to the just-added user bubble so STT can replace it.
    window.tvaibwcLastUserBubble = $('#tvaibwc-chatMessages .tvaibwc-message.tvaibwc-user').last();

    // Phase 2 — webchat flow runtime. If the session is in flow mode
    // (a Flow is bound + we're still in the deterministic graph, not yet
    // handed off to free-form AI), route free-text through /flow/step
    // instead of the AI WS/HTTP path. This is what makes a capture_speech
    // node work; for menu_choice the user usually clicks a button (which
    // calls submitChoice directly), but they may also type — that text
    // routes to the "match" branch.
    if (window.tvaibwcSession && window.tvaibwcSession.flow_active && window.TvaibwcFlow) {
        var rawTextFlow = $('#tvaibwc-chatInput').val().trim();
        $('#tvaibwc-chatInput').val('').focus();
        if (rawTextFlow) {
            window.tvaibwcSendBtn.markTurnStart();
            window.TvaibwcFlow.submitFreeText(rawTextFlow);
        }
        return;
    }

    var voiceReply = $('#tvaibwc-replyToggle').is(':checked');
    var messageType = $('#message_type').val();
    var rawText = $('#tvaibwc-chatInput').val().trim();

    // Clear the input IMMEDIATELY — the user already saw their message
    // get added to the bubble timeline above (via tvaibwc_sendMessage),
    // so leaving the text in the box while we wait on the bot makes it
    // look stuck. Disable the send button until the reply arrives so
    // they can't accidentally double-send.
    var clearInput = function () {
        $('#tvaibwc-chatInput').val('').focus();
        $('#message_type').val('');
    };
    clearInput();
    // Manager handles the disable + 12s watchdog (auto-clear if the
    // server never sends turn.end for any reason — voice-only frames,
    // dropped WS, TTS hiccup, etc).
    window.tvaibwcSendBtn.markTurnStart();

    // Voice reply mode → WhatsApp-style audio bubble (no text shown).
    // Text reply mode → streaming text bubble (current behavior).
    var bubble = voiceReply ? tvaibwcMakeVoiceBubble() : tvaibwcMakeStreamingBubble();
    window.tvaibwcStreamingBubble = bubble;

    tvaibwcEnsureSession()
        .then(function (sess) {
            var s = window.tvaibwcSession;

            // ───── WS path (preferred) ─────
            if (s.wsReady && s.ws) {
                s.ws.sendText(rawText, window.tvaibwcGetLang ? window.tvaibwcGetLang() : 'en');
                clearInput();
                return;
            }

            // Wait briefly for the WS to come up before falling to the
            // HTTP path. The HTTP turn endpoint runs the FULL resolver
            // chain synchronously (tool picker + table picker + SQL
            // gen + repair + final LLM reply) — that's 3–5 sequential
            // LLM calls and frequently breaches the 120s cURL hard
            // timeout. WS streams the reply token-by-token instead,
            // so it doesn't have that ceiling.
            //
            // We wait up to 3s for WS regardless of voice/text mode.
            // If it doesn't come up we fall to HTTP and hope for the
            // best.
            var waitForWs = function () {
                if (s.wsReady && s.ws) return Promise.resolve(true);
                return new Promise(function (resolve) {
                    var t0 = Date.now();
                    (function tick() {
                        if (s.wsReady && s.ws)        return resolve(true);
                        if (Date.now() - t0 > 3000)   return resolve(false);
                        setTimeout(tick, 100);
                    })();
                });
            };

            return waitForWs().then(function (gotWs) {
                // Use WS regardless of reply mode — avoids the 120s
                // HTTP cURL timeout when the resolver chain is long.
                if (gotWs) {
                    s.ws.sendText(rawText, window.tvaibwcGetLang ? window.tvaibwcGetLang() : 'en');
                    return;
                }

                if (voiceReply) {
                    console.warn('[tvaibwc] WS not ready; downgrading voice→text');
                    voiceReply = false;
                    // Visual: if we built a voice bubble, swap to text.
                    if (bubble && bubble.isVoice) {
                        bubble.remove();
                        bubble = tvaibwcMakeStreamingBubble();
                        window.tvaibwcStreamingBubble = bubble;
                    }
                }

            // ───── HTTP fallback (text-only) ─────
            var turnPayload = {
                text:         rawText,
                respond_with: 'text',
                stream:       false
            };

            return window.TvaibwcApi.sendTurn(sess.session_id, turnPayload)
                .then(function (envelope) {
                    if (!envelope || !envelope.success || envelope.status_code !== 200) {
                        // Friendly fallback — never expose raw upstream
                        // error strings to the visitor. `envelope.message`
                        // is already sanitised on the PHP side.
                        var msg = (envelope && envelope.message)
                            || 'Sorry, I had trouble responding just now. Please try again.';
                        var dark = $('#tvaibwc-themeToggle').is(':checked') ? 'dark' : '';
                        bubble.remove();
                        $('#tvaibwc-chatMessages').append(
                            '<div class="tvaibwc-message tvaibwc-bot tvaibwc-flow-retry ' + dark + '">' +
                                '<div class="tvaibwc-message-text">' + $('<div>').text(msg).html() + '</div>' +
                                '<div class="tvaibwc-message-time">' + tvaibwc_getCurrentTime() + '</div>' +
                            '</div>'
                        );
                        tvaibwc_scrollToBottom();
                        return;
                    }
                    var resp = envelope.response || {};
                    var assistant = resp.assistant || resp.message || resp;
                    bubble.renderHttpReply(
                        assistant.content || assistant.text || '',
                        assistant.audio_url || assistant.file_url || '',
                        voiceReply
                    );
                    tvaibwc_scrollToBottom();
                })
                .finally(function () {
                    window.tvaibwcSendBtn.markTurnEnd();
                });
            });  // close waitForWs().then
        })
        .catch(function (err) {
            console.error('[tvaibwc] sendTurn error', err);
            bubble.remove();
            window.tvaibwcSendBtn.markTurnEnd();
        });
});

/* ────────────────────── voice button: streaming ───────────────────────── */
/**
 * Replaces the legacy "record then submit blob URL" flow with a real
 * WS streaming pipeline. The voice button in the widget should call
 * tvaibwcVoiceStart() on press and tvaibwcVoiceStop() on release (or
 * on a second click — whichever the existing UI uses).
 *
 * If the WS isn't ready we silently fall back to the legacy blob-URL
 * mechanism (see tvaibwc-core.js).
 */
window.tvaibwcVoiceStart = function () {
    var s = window.tvaibwcSession;
    if (!s.wsReady || !s.ws) {
        console.warn('[tvaibwc] WS not ready, voice will use legacy path');
        return false;
    }

    // Visual: empty user bubble that STT will fill in.
    var dark = $('#tvaibwc-themeToggle').is(':checked');
    window.tvaibwcLastUserBubble = $(
        '<div class="tvaibwc-message tvaibwc-user ' + (dark ? 'dark' : '') + '">' +
            '<div class="tvaibwc-message-text"><i>Listening…</i></div>' +
            '<div class="tvaibwc-message-time">' + tvaibwc_getCurrentTime() + '</div>' +
        '</div>'
    );
    $('#tvaibwc-chatMessages').append(window.tvaibwcLastUserBubble);

    // Voice in → voice out. Use the WhatsApp-style audio bubble.
    var bubble = tvaibwcMakeVoiceBubble();
    window.tvaibwcStreamingBubble = bubble;

    s.mic = new window.TvaibwcMicRecorder({
        onStart: function (sr) {
            console.log('[tvaibwc] mic on, sample_rate=' + sr +
                        ', ctx.state=' + (s.mic && s.mic.ctx && s.mic.ctx.state));
            s.ws.sendAudioStart({
                format: 'pcm16',
                sample_rate: sr,
                language: window.tvaibwcGetLang ? window.tvaibwcGetLang() : 'en',
            });
        },
        onChunk: function (seq, b64) {
            if (seq % 10 === 0) console.log('[tvaibwc] sent audio.chunk seq=' + seq + ' bytes=' + (b64.length * 3/4 | 0));
            s.ws.sendAudioChunk(seq, b64);
        },
        onStop: function (err) {
            if (err) console.warn('[tvaibwc] mic stop with error:', err);
            else    console.log('[tvaibwc] mic stop (clean)');
        }
    });
    s.mic.start();
    return true;
};

window.tvaibwcVoiceStop = function () {
    var s = window.tvaibwcSession;
    if (s.mic) {
        s.mic.stop();
        s.mic = null;
    }
    if (s.ws && s.wsReady) {
        s.ws.sendAudioEnd();
    }
};

/* Barge-in: user starts typing/talking while bot is still speaking. */
window.tvaibwcBargeIn = function () {
    var s = window.tvaibwcSession;
    if (s.playback) s.playback.stopAll();
    if (s.ws && s.wsReady) s.ws.sendBargeIn();
};
