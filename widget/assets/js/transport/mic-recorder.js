/**
 * mic-recorder.js — captures microphone audio, downsamples to 16 kHz PCM16,
 * and emits base64-encoded chunks suitable for the contract's `audio.chunk`
 * frame.
 *
 * Uses ScriptProcessorNode (deprecated but universal). Each chunk is ~256 ms
 * of audio @ 16 kHz, which is small enough for low latency but not so small
 * that we drown the WS in tiny frames.
 *
 * Usage:
 *   var mic = new TvaibwcMicRecorder({
 *     onStart:  function (sampleRate) { ... },
 *     onChunk:  function (seq, base64) { ws.sendAudioChunk(seq, base64); },
 *     onStop:   function (err) { ... },   // err is undefined on clean stop
 *     onLevel:  function (rms) { ... }    // optional, for VU meter
 *   });
 *   mic.start();
 *   ...
 *   mic.stop();
 */
(function (root) {
    'use strict';

    var TARGET_SR = 16000;
    var BUFFER_SIZE = 4096;

    function int16ToBase64(int16) {
        var bytes = new Uint8Array(int16.buffer, int16.byteOffset, int16.byteLength);
        var bin = '';
        var chunk = 0x8000;
        for (var i = 0; i < bytes.length; i += chunk) {
            bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
        }
        return btoa(bin);
    }

    function TvaibwcMicRecorder(opts) {
        this.opts = opts || {};
        this.ctx = null;
        this.stream = null;
        this.source = null;
        this.processor = null;
        this.seq = 0;
        this.running = false;
    }

    TvaibwcMicRecorder.prototype.start = function () {
        var self = this;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            (self.opts.onStop || function () {})('getUserMedia unavailable');
            return;
        }

        // Create the AudioContext synchronously NOW, while we're still inside
        // the user-gesture callback. Chrome only allows AudioContext to start
        // running if it's constructed (or .resume() is called) from a user
        // gesture. If we defer this until after getUserMedia() resolves we
        // may be out of the gesture window → ctx stays suspended →
        // onaudioprocess never fires → server sees `empty_input`.
        var Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) {
            (self.opts.onStop || function () {})('AudioContext unavailable');
            return;
        }
        self.ctx = new Ctx();
        if (self.ctx.state === 'suspended' && self.ctx.resume) {
            self.ctx.resume().catch(function () {});
        }

        navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            }
        }).then(function (stream) {
            self.stream = stream;
            self.source = self.ctx.createMediaStreamSource(stream);
            self.processor = self.ctx.createScriptProcessor(BUFFER_SIZE, 1, 1);
            if (self.ctx.state !== 'running') {
                console.warn('[tvaibwc] AudioContext state at start:', self.ctx.state);
            }

            var nativeSr = self.ctx.sampleRate;
            var ratio = nativeSr / TARGET_SR;

            self.processor.onaudioprocess = function (ev) {
                if (!self.running) return;

                var input = ev.inputBuffer.getChannelData(0);
                var downsampled = self._downsample(input, ratio);

                // optional VU meter
                if (self.opts.onLevel) {
                    var sum = 0;
                    for (var k = 0; k < downsampled.length; k++) {
                        sum += downsampled[k] * downsampled[k];
                    }
                    self.opts.onLevel(Math.sqrt(sum / downsampled.length));
                }

                var pcm16 = new Int16Array(downsampled.length);
                for (var i = 0; i < downsampled.length; i++) {
                    var s = Math.max(-1, Math.min(1, downsampled[i]));
                    pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
                }

                (self.opts.onChunk || function () {})(self.seq++, int16ToBase64(pcm16));
            };

            self.source.connect(self.processor);
            self.processor.connect(self.ctx.destination);

            self.running = true;
            (self.opts.onStart || function () {})(TARGET_SR);
        }).catch(function (err) {
            (self.opts.onStop || function () {})(String(err && err.message ? err.message : err));
        });
    };

    /**
     * Naive linear downsampling. Average over each window of input samples
     * to produce one output sample. Good enough for speech recognition; we
     * don't need musical fidelity.
     */
    TvaibwcMicRecorder.prototype._downsample = function (buf, ratio) {
        if (ratio === 1) return buf;
        var outLen = Math.floor(buf.length / ratio);
        var out = new Float32Array(outLen);
        var pos = 0;
        for (var i = 0; i < outLen; i++) {
            var nextPos = Math.floor((i + 1) * ratio);
            var sum = 0, count = 0;
            for (var j = pos; j < nextPos && j < buf.length; j++) {
                sum += buf[j];
                count++;
            }
            out[i] = count > 0 ? sum / count : 0;
            pos = nextPos;
        }
        return out;
    };

    TvaibwcMicRecorder.prototype.stop = function () {
        this.running = false;
        if (this.processor) { try { this.processor.disconnect(); } catch (_) {} this.processor = null; }
        if (this.source)    { try { this.source.disconnect();    } catch (_) {} this.source = null; }
        if (this.stream) {
            this.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (_) {} });
            this.stream = null;
        }
        if (this.ctx) { try { this.ctx.close(); } catch (_) {} this.ctx = null; }
        this.seq = 0;
        (this.opts.onStop || function () {})();
    };

    root.TvaibwcMicRecorder = TvaibwcMicRecorder;
})(typeof window !== 'undefined' ? window : this);
