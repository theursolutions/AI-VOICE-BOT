/**
 * api-client.js — thin fetch wrapper around the WebChatBot PHP handlers.
 *
 * The browser does NOT talk to Laravel directly. It posts to our PHP
 * handlers (ChatHandler.php), which forward to the Laravel control plane
 * with the X-CLIENT-API-KEY header. See:
 *   ai-voice-bot-admin/docs/API_CONTRACT.md
 *
 * Exposes a single global `TvaibwcApi` with two methods:
 *   - startSession(profile)        → POST action=startSession
 *   - sendTurn(sessionId, payload) → POST action=sendTurn
 *
 * Both resolve to the PHP envelope:
 *   { success, status_code, message, response }
 * where `response` is the upstream Laravel JSON body.
 */
(function (root) {
  'use strict';

  // Resolve the chat handler URL. webchat-app.php exposes BASE_HANDLERS_URL
  // through the rendered form action; we read it off the form so this file
  // works whether served from PHP or from a static bundle.
  function resolveHandlerUrl() {
    var form = document.getElementById('create_chat_response');
    // Use getAttribute, NOT form.action — a child <input name="action">
    // shadows the property and would return the element itself.
    var fromForm = form && form.getAttribute('action');
    if (fromForm) return fromForm;
    return (root.APP_BASE_URL || '') + '/app/Handlers/ChatHandler.php';
  }

  function postForm(action, fields) {
    var url = resolveHandlerUrl();
    var fd = new FormData();
    fd.append('action', action);
    // Embed mode: webchat-app.php injects window.TVAIBWC_PROJECT_API_KEY
    // from the ?key= URL param. Forward it to the PHP handler so it
    // can route the request to the right project — otherwise the
    // handler falls back to the .env's TVAIBWC_PROJECT_API_KEY which
    // means every embed always targets the same project.
    if (root.TVAIBWC_PROJECT_API_KEY) {
      fd.append('project_api_key', root.TVAIBWC_PROJECT_API_KEY);
    }
    Object.keys(fields || {}).forEach(function (key) {
      var value = fields[key];
      if (value === undefined || value === null) return;
      if (typeof value === 'object' && !(value instanceof Blob)) {
        fd.append(key, JSON.stringify(value));
      } else {
        fd.append(key, value);
      }
    });

    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) { return res.text(); })
      .then(function (text) {
        try { return JSON.parse(text); }
        catch (_) { return { success: false, status_code: 500, message: 'Invalid JSON', response: text }; }
      });
  }

  var TvaibwcApi = {
    /**
     * POST /api/v1/sessions (via PHP).
     * profile: { customer_name?, customer_phone?, customer_email?, voice_id?, metadata? }
     * Resolves with envelope.response = { session_id, token, ws_url, expires_in }.
     */
    startSession: function (profile) {
      var p = profile || {};
      return postForm('startSession', {
        channel:        'web',
        customer_name:  p.customer_name  || '',
        customer_phone: p.customer_phone || '',
        customer_email: p.customer_email || '',
        voice_id:       p.voice_id || '',
        metadata:       p.metadata || {}
      });
    },

    /**
     * POST /api/v1/sessions/{id}/turn (via PHP).
     * payload: { text?, audio_url?, respond_with?, stream? }
     */
    sendTurn: function (sessionId, payload) {
      var p = payload || {};
      return postForm('sendTurn', {
        session_id:   sessionId,
        text:         p.text || '',
        audio_url:    p.audio_url || '',
        respond_with: p.respond_with || 'text',
        stream:       p.stream ? 1 : 0
      });
    }
  };

  root.TvaibwcApi = TvaibwcApi;
})(typeof window !== 'undefined' ? window : this);
