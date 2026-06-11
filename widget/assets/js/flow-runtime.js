/* tvaibwc-flow-runtime — Phase 2: client-side flow renderer.
 *
 * Renders the WebFlowRunner envelope into the chat timeline:
 *   - kind=text  → normal bot bubble (optional audio autoplay)
 *   - kind=menu  → bubble + a horizontal row of quick-reply buttons
 *
 * Also handles handoff: when a flow hits a transfer_ai node, the
 * runner emits a `handoff` block with ws_url + token and we open the
 * existing WS like the non-flow path does.
 *
 * Session-level flag `tvaibwcSession.flow_active` controls whether the
 * regular chat-submit form should intercept and route through flowStep
 * instead of sendTurn.
 */
(function (root) {
    'use strict';
    var $ = root.jQuery || root.$;

    function isDark() {
        return $('#tvaibwc-themeToggle').is(':checked');
    }

    function nowTime() {
        if (typeof root.tvaibwc_getCurrentTime === 'function') return root.tvaibwc_getCurrentTime();
        try { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
        catch (_) { return ''; }
    }

    function scrollToBottom() {
        if (typeof root.tvaibwc_scrollToBottom === 'function') return root.tvaibwc_scrollToBottom();
        var $box = $('#tvaibwc-chatMessages');
        if ($box.length) $box.scrollTop($box[0].scrollHeight);
    }

    /* ─────────────────────────── bubble renderers ───────────────────────── */

    function renderTextBubble(text, audioUrl) {
        var dark = isDark();
        var safe = $('<div>').text(text || '').html();
        var $bubble = $(
            '<div class="tvaibwc-message tvaibwc-bot' + (dark ? ' dark' : '') + '">' +
                '<div class="tvaibwc-message-text">' + safe + '</div>' +
                '<div class="tvaibwc-message-time">' + nowTime() + '</div>' +
            '</div>'
        );
        $('#tvaibwc-chatMessages').append($bubble);

        // Optional audio autoplay — only fires if the customer uploaded a
        // pre-recorded audio for this Say node. No audio = no TTS spend.
        if (audioUrl) {
            try {
                var audio = new Audio(audioUrl);
                audio.play().catch(function () { /* autoplay can be blocked — fine */ });
            } catch (_) {}
        }
        scrollToBottom();
        return $bubble;
    }

    function renderMenuBubble(prompt, audioUrl, options) {
        var dark = isDark();
        var $bubble = $(
            '<div class="tvaibwc-message tvaibwc-bot tvaibwc-flow-menu' + (dark ? ' dark' : '') + '">' +
                '<div class="tvaibwc-message-text">' + $('<div>').text(prompt || '').html() + '</div>' +
                '<div class="tvaibwc-flow-options"></div>' +
                '<div class="tvaibwc-message-time">' + nowTime() + '</div>' +
            '</div>'
        );

        var $opts = $bubble.find('.tvaibwc-flow-options');
        (options || []).forEach(function (opt) {
            var $btn = $('<button type="button" class="tvaibwc-flow-option-btn"></button>');
            $btn.text(opt.label || opt.id);
            $btn.attr('data-choice-id', opt.id);
            $btn.on('click', function () {
                // Disable every button in this bubble — once a choice is
                // made the menu is no longer interactive.
                $opts.find('button').prop('disabled', true);
                $btn.addClass('is-selected');

                // Show the chosen label as the user's own message so the
                // conversation reads naturally.
                renderUserBubble(opt.label || opt.id);
                submitChoice(opt.id);
            });
            $opts.append($btn);
        });

        $('#tvaibwc-chatMessages').append($bubble);
        if (audioUrl) {
            try { new Audio(audioUrl).play().catch(function () {}); } catch (_) {}
        }
        scrollToBottom();
        return $bubble;
    }

    function renderUserBubble(text) {
        var dark = isDark();
        var $bubble = $(
            '<div class="tvaibwc-message tvaibwc-user' + (dark ? ' dark' : '') + '">' +
                '<div class="tvaibwc-message-text">' + $('<div>').text(text).html() + '</div>' +
                '<div class="tvaibwc-message-time">' + nowTime() + '</div>' +
            '</div>'
        );
        $('#tvaibwc-chatMessages').append($bubble);
        scrollToBottom();
    }

    /* ─────────────────────────── envelope handling ──────────────────────── */

    function applyFlowResult(result) {
        if (!result) return;
        var s = root.tvaibwcSession;

        // Clear any pending pill from the previous step — a fresh walk
        // means there isn't a stale "back to menu" hanging around while
        // new messages stream in.
        clearFlowActionsBar();

        (result.messages || []).forEach(function (m) {
            if (!m) return;
            if (m.kind === 'menu') {
                renderMenuBubble(m.prompt, m.audio_url, m.options);
            } else {
                renderTextBubble(m.text, m.audio_url);
            }
        });

        // Track expected input mode + current node so chat-submit can
        // route correctly. The form-input stays enabled for free-text
        // capture_speech; for menu_choice the user can also type
        // (we'll send it as free text, runner may route to "match" or
        // fall through to timeout).
        s.flow_active     = !result.ended && !result.handoff;
        s.flow_expecting  = result.expecting || 'none';
        s.flow_current_id = result.current_node_id || null;

        // Once any flow step has run, the session is flow-bound — even
        // after handoff/end we want the "Back to menu" pill available
        // so the visitor can re-enter the flow without refreshing.
        s.flow_bound = true;

        // Handoff: flow finished pre-AI setup, hand the call over to
        // the existing WS path. tvaibwcConnectWs reads from session, so
        // patch the session fields and call it.
        if (result.handoff) {
            s.token  = result.handoff.token  || s.token;
            s.ws_url = result.handoff.ws_url || s.ws_url;
            if (typeof root.tvaibwcConnectWs === 'function') {
                try { root.tvaibwcConnectWs(); } catch (e) { console.warn('[tvaibwc] handoff WS open failed', e); }
            }
        }

        if (result.cost_avoided) {
            console.log('[tvaibwc] flow avoided ' + result.cost_avoided + ' LLM turn(s) this step');
        }

        // After handoff or end, surface the "Back to menu" pill so the
        // visitor can re-enter the flow if they want to ask about a
        // different topic. We do NOT show it while the flow is still
        // expecting menu/text input — those have their own UI.
        if (result.handoff || result.ended) {
            renderBackToMenuPill();
        }
    }

    function renderBackToMenuPill() {
        // Pill lives in #tvaibwc-flowActionsBar — a dedicated row that
        // sits BELOW the messages list (outside of it). That way the
        // pill is always physically at the end of the conversation,
        // even if the AI streams 20 more bubbles after the handoff.
        var $bar = $('#tvaibwc-flowActionsBar');
        if (!$bar.length) return;
        if ($bar.find('.tvaibwc-flow-back-btn').length) return;  // de-dupe

        var dark = isDark();
        var $row = $(
            '<div class="tvaibwc-flow-back-row' + (dark ? ' dark' : '') + '">' +
                '<button type="button" class="tvaibwc-flow-back-btn">' +
                    '<span class="tvaibwc-flow-back-arrow">‹</span> Back to main menu' +
                '</button>' +
            '</div>'
        );
        $row.find('button').on('click', function () {
            $row.find('button').prop('disabled', true);
            restartFlow(function () { $bar.empty(); });
        });
        $bar.empty().append($row);
        scrollToBottom();
    }

    // Whenever the flow walks back to its Start (e.g. via restart) or
    // ends, we want the pill area cleared so it doesn't stack.
    function clearFlowActionsBar() {
        $('#tvaibwc-flowActionsBar').empty();
    }

    function restartFlow(onDone) {
        var s = root.tvaibwcSession;
        if (!s || !s.session_id) { if (onDone) onDone(); return; }
        root.tvaibwcSendBtn && root.tvaibwcSendBtn.markTurnStart && root.tvaibwcSendBtn.markTurnStart();
        root.TvaibwcApi.flowRestart(s.session_id)
            .then(function (envelope) {
                root.tvaibwcSendBtn && root.tvaibwcSendBtn.forceEnable && root.tvaibwcSendBtn.forceEnable();
                if (envelope && envelope.success) applyFlowResult(envelope.response);
                else renderRetryBubble(envelope && envelope.message);
            })
            .catch(function (err) {
                root.tvaibwcSendBtn && root.tvaibwcSendBtn.forceEnable && root.tvaibwcSendBtn.forceEnable();
                console.error('[tvaibwc] flowRestart error', err);
                renderRetryBubble('Couldn\'t reopen the menu just now. Please try again.');
            })
            .then(function () { if (onDone) onDone(); });
    }

    function renderRetryBubble(message) {
        var dark = isDark();
        var safe = $('<div>').text(
            message || 'Sorry, that didn\'t go through. Please try again.'
        ).html();
        var $bubble = $(
            '<div class="tvaibwc-message tvaibwc-bot tvaibwc-flow-retry' + (dark ? ' dark' : '') + '">' +
                '<div class="tvaibwc-message-text">' + safe + '</div>' +
                '<div class="tvaibwc-message-time">' + nowTime() + '</div>' +
            '</div>'
        );
        $('#tvaibwc-chatMessages').append($bubble);
        scrollToBottom();
    }

    function dispatchFlowCall(promise) {
        return promise
            .then(function (envelope) {
                root.tvaibwcSendBtn && root.tvaibwcSendBtn.forceEnable && root.tvaibwcSendBtn.forceEnable();
                if (envelope && envelope.success) {
                    applyFlowResult(envelope.response);
                } else {
                    // Friendly fallback — envelope.message has already
                    // been sanitised by the PHP envelope helper. Console
                    // gets the full payload for debugging.
                    console.warn('[tvaibwc] flowStep failed', envelope);
                    renderRetryBubble(envelope && envelope.message);
                }
            })
            .catch(function (err) {
                root.tvaibwcSendBtn && root.tvaibwcSendBtn.forceEnable && root.tvaibwcSendBtn.forceEnable();
                console.error('[tvaibwc] flowStep error', err);
                renderRetryBubble('I had trouble connecting. Please try again.');
            });
    }

    function submitChoice(choiceId) {
        var s = root.tvaibwcSession;
        if (!s.session_id) return;
        root.tvaibwcSendBtn && root.tvaibwcSendBtn.markTurnStart && root.tvaibwcSendBtn.markTurnStart();
        dispatchFlowCall(root.TvaibwcApi.flowStep(s.session_id, { choice_id: choiceId }));
    }

    function submitFreeText(text) {
        var s = root.tvaibwcSession;
        if (!s.session_id) return;
        root.tvaibwcSendBtn && root.tvaibwcSendBtn.markTurnStart && root.tvaibwcSendBtn.markTurnStart();
        dispatchFlowCall(root.TvaibwcApi.flowStep(s.session_id, { text: text }));
    }

    /* ─────────────────────────── public surface ─────────────────────────── */

    /* ─────────────────────────── end conversation ───────────────────────
       Triggered by the "End chat" soft button in the input footer.
       Confirms, calls /sessions/{id}/end (idempotent), shows a closing
       bubble, locks the input. Works for both flow-bound and free-form
       sessions — the bound-vs-free distinction doesn't matter here. */

    function endConversation() {
        var s = root.tvaibwcSession;
        if (!s || !s.session_id) return;

        // Best-effort confirm — most chat widgets ask before ending so
        // a misclick on a long thread doesn't lose context.
        if (!root.confirm('End this conversation? The chat history will be saved but you won\'t be able to continue this session.')) {
            return;
        }

        var $container = $('#tvaibwc-chatInputContainer');
        var $btn = $('#tvaibwc-endSessionBtn');
        $btn.prop('disabled', true);

        var finalize = function (envelope) {
            renderTextBubble('Conversation ended. Thank you — come back any time!', null);

            // Tear down WS + clear session-level state so the next
            // "Start chat" creates a genuinely fresh session.
            if (s.ws) { try { s.ws.close(); } catch (_) {} }
            s.ws = null;
            s.wsReady = false;
            s.session_id = null;
            s.token = null;
            s.ws_url = null;
            s.flow_active = false;
            s.flow_bound = false;
            s._pending = null;

            // Hide the input row + the End-chat-flavoured chrome, surface
            // the Start-chat CTA in its place. They share the same
            // bottom slot, so the visitor never sees both at once.
            $container.removeClass('active is-ended');
            clearFlowActionsBar();
            $('#tvaibwc-startChatButton').addClass('is-visible').show();

            if (!envelope || !envelope.success) {
                console.warn('[tvaibwc] endSession server response was not OK', envelope);
            }
        };

        root.TvaibwcApi.endSession(s.session_id)
            .then(finalize)
            .catch(function (err) {
                console.error('[tvaibwc] endSession error', err);
                finalize(null);
            });
    }

    // Wire the soft button. The form-row HTML lives in webchat-app.php
    // and only renders when the chat panel is mounted, so we delegate.
    $(document).on('click', '#tvaibwc-endSessionBtn', endConversation);

    root.TvaibwcFlow = {
        apply:           applyFlowResult,
        submitChoice:    submitChoice,
        submitFreeText:  submitFreeText,
        restart:         restartFlow,
        endConversation: endConversation
    };
})(typeof window !== 'undefined' ? window : this);
