/**
 * ws-client.js — WebSocket wrapper for the Python data plane.
 *
 * Endpoint: ws_url + "?token=<JWT>"   (token is minted by Laravel)
 *
 * Frame protocol (see ai-voice-bot-admin/docs/API_CONTRACT.md):
 *   Client → server: audio.start | audio.chunk | audio.end | text | barge_in
 *   Server → client: stt.partial | stt.final | llm.delta | llm.final
 *                    | audio.chunk | audio.end | turn.end | error
 *
 * Usage:
 *   var ws = new TvaibwcWsClient(wsUrl, token, {
 *     onLlmDelta:    function(text) { ... },     // streaming text bubble
 *     onLlmFinal:    function(text) { ... },
 *     onSttPartial:  function(text) { ... },
 *     onSttFinal:    function(text) { ... },
 *     onAudioChunk:  function(base64, format) { ... },  // queue + play
 *     onAudioEnd:    function() { ... },
 *     onTurnEnd:     function(latencyMs) { ... },
 *     onError:       function(code, message) { ... },
 *     onOpen:        function() { ... },
 *     onClose:       function(ev) { ... }
 *   });
 *   ws.connect();
 *   ws.sendText("hello");
 *   ws.sendAudioStart({ format: 'pcm16', sample_rate: 16000 });
 *   ws.sendAudioChunk(seq, base64Data);
 *   ws.sendAudioEnd();
 *
 * NOTE: This is a structural stub. The connect() method opens the socket
 * and dispatches inbound frames to handler callbacks by frame.type, but
 * the audio-chunk playback queue and STT/LLM rendering integration into
 * the widget UI is left to the request-handler layer.
 *
 * TODO: wire audio.chunk frames into a Web Audio API playback queue.
 * TODO: stream microphone frames via MediaRecorder + audio.chunk frames
 *       (currently the widget POSTs the recorded blob through the HTTP
 *        text-fallback path; see request-handler.js).
 */
(function (root) {
  'use strict';

  function noop() {}

  function TvaibwcWsClient(wsUrl, token, handlers) {
    if (!(this instanceof TvaibwcWsClient)) {
      return new TvaibwcWsClient(wsUrl, token, handlers);
    }
    this.wsUrl  = wsUrl;
    this.token  = token;
    this.socket = null;
    this.h = handlers || {};
    // Default every handler to noop so call-sites don't need to null-check.
    [
      'onOpen', 'onClose', 'onError',
      'onSttPartial', 'onSttFinal',
      'onLlmDelta', 'onLlmFinal',
      'onAudioChunk', 'onAudioEnd',
      'onTurnEnd'
    ].forEach(function (key) {
      if (typeof this.h[key] !== 'function') this.h[key] = noop;
    }, this);
  }

  TvaibwcWsClient.prototype.connect = function () {
    if (!this.wsUrl) {
      this.h.onError('no_ws_url', 'ws_url not provided by /sessions');
      return;
    }
    var url = this.wsUrl + (this.wsUrl.indexOf('?') === -1 ? '?' : '&')
            + 'token=' + encodeURIComponent(this.token || '');

    var self = this;
    try {
      this.socket = new WebSocket(url);
    } catch (err) {
      this.h.onError('ws_construct_failed', String(err));
      return;
    }

    this.socket.addEventListener('open',  function ()  { self.h.onOpen(); });
    this.socket.addEventListener('close', function (e) { self.h.onClose(e); });
    this.socket.addEventListener('error', function (e) {
      self.h.onError('ws_error', e && e.message ? e.message : 'WebSocket error');
    });
    this.socket.addEventListener('message', function (ev) { self._dispatch(ev.data); });
  };

  // Route inbound frame to the right handler by `type`.
  TvaibwcWsClient.prototype._dispatch = function (raw) {
    var frame;
    try { frame = JSON.parse(raw); }
    catch (_) {
      this.h.onError('bad_frame', 'Non-JSON frame: ' + String(raw).slice(0, 80));
      return;
    }
    switch (frame.type) {
      case 'stt.partial': return this.h.onSttPartial(frame.text || '');
      case 'stt.final':   return this.h.onSttFinal(frame.text || '');
      case 'llm.delta':   return this.h.onLlmDelta(frame.text || '');
      case 'llm.final':   return this.h.onLlmFinal(frame.text || '', frame);
      case 'audio.chunk': return this.h.onAudioChunk(frame.data || '', frame.format || 'pcm16', frame.seq || 0);
      case 'audio.end':   return this.h.onAudioEnd();
      case 'turn.end':    return this.h.onTurnEnd(frame.latency_ms || 0, frame.audio_url || null);
      case 'error':       return this.h.onError(frame.code || 'unknown', frame.message || '');
      default:            return this.h.onError('unknown_type', 'Unhandled frame type: ' + frame.type);
    }
  };

  TvaibwcWsClient.prototype._send = function (obj) {
    if (!this.socket || this.socket.readyState !== 1) {
      // 1 === WebSocket.OPEN
      this.h.onError('ws_not_open', 'Cannot send, socket not open');
      return false;
    }
    this.socket.send(JSON.stringify(obj));
    return true;
  };

  TvaibwcWsClient.prototype.sendText = function (text) {
    return this._send({ type: 'text', text: text });
  };
  TvaibwcWsClient.prototype.sendAudioStart = function (opts) {
    var o = opts || {};
    return this._send({ type: 'audio.start', format: o.format || 'pcm16', sample_rate: o.sample_rate || 16000 });
  };
  TvaibwcWsClient.prototype.sendAudioChunk = function (seq, base64Data) {
    return this._send({ type: 'audio.chunk', seq: seq, data: base64Data });
  };
  TvaibwcWsClient.prototype.sendAudioEnd = function () {
    return this._send({ type: 'audio.end' });
  };
  TvaibwcWsClient.prototype.sendBargeIn = function () {
    return this._send({ type: 'barge_in' });
  };
  TvaibwcWsClient.prototype.close = function () {
    if (this.socket) { try { this.socket.close(); } catch (_) {} }
    this.socket = null;
  };

  /**
   * Convenience factory. Mirrors the signature requested by the spec:
   *   connectStream(token, wsUrl) → TvaibwcWsClient
   */
  function connectStream(token, wsUrl, handlers) {
    var client = new TvaibwcWsClient(wsUrl, token, handlers);
    client.connect();
    return client;
  }

  root.TvaibwcWsClient = TvaibwcWsClient;
  root.connectStream  = connectStream;
})(typeof window !== 'undefined' ? window : this);
