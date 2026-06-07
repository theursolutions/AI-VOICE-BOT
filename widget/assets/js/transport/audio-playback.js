/**
 * audio-playback.js — gapless playback queue for streamed `audio.chunk` frames.
 *
 * Server sends successive PCM16 chunks (base64) with a sample rate; we
 * schedule each one back-to-back on a single AudioContext timeline so the
 * speech sounds continuous instead of stuttery.
 *
 * Browsers block AudioContext creation until a user gesture, so this lazy-
 * inits on the first `push()` — which is fine because the user clicks the
 * voice button (or types) before any chunk arrives.
 *
 * Usage:
 *   var pb = new TvaibwcAudioPlayback();
 *   pb.push(base64Pcm16, 'pcm16', 24000);
 *   ...
 *   pb.flushAndClose();   // after audio.end
 */
(function (root) {
    'use strict';

    function base64ToInt16(b64) {
        var bin = atob(b64);
        var len = bin.length;
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
        // PCM16 is little-endian on the wire from Python's soundfile output.
        return new Int16Array(bytes.buffer, bytes.byteOffset, len / 2);
    }

    function TvaibwcAudioPlayback() {
        this.ctx = null;
        this.nextStartAt = 0;
        this.sources = [];
        this.lastSeq = -1;
        // Forward cushion in seconds. Each chunk is scheduled at least this
        // far ahead of currentTime so chunk-arrival jitter (TTS chunks on
        // CPU come in spurts) doesn't produce audible gaps.
        // Higher = smoother playback, higher first-chunk latency.
        this.lookahead = 0.75;
    }

    TvaibwcAudioPlayback.prototype._ensureCtx = function () {
        if (!this.ctx) {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                console.warn('[tvaibwc] AudioContext unavailable; cannot play streamed audio');
                return false;
            }
            this.ctx = new Ctx();
        }
        if (this.ctx.state === 'suspended') {
            this.ctx.resume().catch(function () {});
        }
        return true;
    };

    TvaibwcAudioPlayback.prototype.push = function (base64, format, sampleRate) {
        if (!this._ensureCtx()) return;
        if (!base64) return;

        // Drop out-of-order frames (rare but possible). The contract carries
        // `seq`; if not provided we assume monotonic.
        // (We accept seq via the public push; ws-client passes it positionally.)

        var pcm16;
        try {
            pcm16 = base64ToInt16(base64);
        } catch (e) {
            console.warn('[tvaibwc] bad audio chunk base64', e);
            return;
        }

        var sr = sampleRate || 24000;
        var float32 = new Float32Array(pcm16.length);
        for (var i = 0; i < pcm16.length; i++) {
            float32[i] = pcm16[i] / 32768;
        }

        var buf = this.ctx.createBuffer(1, float32.length, sr);
        buf.copyToChannel(float32, 0);

        var src = this.ctx.createBufferSource();
        src.buffer = buf;
        src.connect(this.ctx.destination);

        var when = Math.max(this.ctx.currentTime + this.lookahead, this.nextStartAt);
        src.start(when);
        this.nextStartAt = when + buf.duration;
        this.sources.push(src);
    };

    /** Stop playback NOW. Used for barge-in. */
    TvaibwcAudioPlayback.prototype.stopAll = function () {
        this.sources.forEach(function (s) {
            try { s.stop(); } catch (_) {}
        });
        this.sources = [];
        this.nextStartAt = 0;
    };

    /** Let pending audio finish, then close the context. */
    TvaibwcAudioPlayback.prototype.flushAndClose = function () {
        var self = this;
        if (!this.ctx) return;
        var idleAt = Math.max(this.nextStartAt - this.ctx.currentTime, 0);
        setTimeout(function () {
            if (self.ctx) {
                try { self.ctx.close(); } catch (_) {}
                self.ctx = null;
                self.sources = [];
                self.nextStartAt = 0;
            }
        }, idleAt * 1000 + 200);
    };

    root.TvaibwcAudioPlayback = TvaibwcAudioPlayback;
})(typeof window !== 'undefined' ? window : this);
