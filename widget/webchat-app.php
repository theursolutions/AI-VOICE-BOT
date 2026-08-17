<?php
    require __DIR__ . '/app/config/bootstrap.php';

    // ---------------------------------------------------------------
    // Per-project branding. When the widget is opened via loader.js it
    // arrives with ?key=<project_api_key>&embed=1. We pull the saved
    // widget config from the admin and apply it inline.
    // ---------------------------------------------------------------
    $tvaProjectKey = isset($_GET['key']) ? trim($_GET['key']) : '';
    $tvaEmbed      = isset($_GET['embed']) && $_GET['embed'] === '1';

    // Theme the host page is currently in, handed over by loader.js. Applied
    // to the initial markup rather than after boot: the widget defaulted to
    // dark, so opening it on a light site flashed black before any JS could
    // correct it. Anything other than "light" keeps the historical dark
    // default, so a direct visit with no parameter is unchanged.
    $tvaTheme = (isset($_GET['theme']) && $_GET['theme'] === 'light') ? 'light' : 'dark';
    $tvaIsDark = $tvaTheme === 'dark';

    // Fetched config + project defaults — populated below if a key was
    // supplied. Otherwise we render with safe placeholders.
    $tvaConfig = [
        'primary_color'   => '#1a365d',
        'accent_color'    => '#3b82f6',
        'bot_name'        => 'Assistant',
        'welcome_title'   => 'Welcome to our Support',
        'welcome_message' => "Hi there! \u{1F44B} How can I help you today?",
        'avatar_emoji'    => "\u{1F916}",
        'logo_url'        => null,
        'opening_hours'   => '24/7',
        'placeholder'     => 'Type your message...',
        // Per-button visibility — every key defaults true so the widget
        // looks the same as before unless the project owner toggles
        // something off in /c/{slug}/widget-settings.
        'show_voice'         => true,
        'show_emoji'         => true,
        'show_attach'        => true,
        'show_theme_toggle'  => true,
        'show_reply_toggle'  => true,
        'show_language'      => true,
        'show_expand_button' => true,
        'show_visitor_modes' => true,
        'show_history_tab'   => true,
        'show_powered_by'    => true,
    ];

    if ($tvaProjectKey !== '' && function_exists('curl_init')) {
        $cfgUrl = (defined('LARAVEL_BASE_URL') ? LARAVEL_BASE_URL : 'http://127.0.0.1:8001')
                . '/api/v1/widget/config';
        $ch = curl_init($cfgUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_HTTPHEADER     => [
                'X-CLIENT-API-KEY: ' . $tvaProjectKey,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $decoded = json_decode($body, true);
            if (isset($decoded['config']) && is_array($decoded['config'])) {
                $tvaConfig = array_merge($tvaConfig, array_filter($decoded['config'], fn($v) => $v !== null));
            }
        }
        curl_close($ch);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVAIBWC Chat Widget</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/tvaibwc-style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <style>
        /* Per-project brand colors. We deliberately do NOT override
           --tvaibwc-primary-color globally — that variable feeds the
           widget's chrome (header, input bar, etc.) and the existing
           dark-theme design depends on it being a deep navy. Instead
           we expose the project's brand colors as new variables and
           apply them only to specific action elements (launcher,
           send button, primary CTAs). */
        :root {
            --tva-brand-primary: <?= htmlspecialchars($tvaConfig['primary_color']) ?>;
            --tva-brand-accent:  <?= htmlspecialchars($tvaConfig['accent_color']) ?>;
            --tva-brand-gradient: linear-gradient(135deg,
                <?= htmlspecialchars($tvaConfig['primary_color']) ?>,
                <?= htmlspecialchars($tvaConfig['accent_color']) ?>);
        }
        <?php if ($tvaEmbed): ?>
        /* Embed mode: transparent body, widget keeps its original
           layout (panel + floating chevron-down button below) so the
           UX matches the standalone webchat-app.php exactly. */
        html, body { background: transparent !important; margin: 0; padding: 0; height: 100%; }
        body { overflow: hidden; }
        /* Container anchors to the bottom-right of the iframe so the
           toggle button + panel stay on the right edge. */
        .tvaibwc-chat-widget-container {
            position: fixed !important;
            bottom: 6px !important;
            right: 6px !important;
            left: auto !important;
        }
        /* Force panel open. Anchored above the toggle button. */
        .tvaibwc-chat-widget {
            display: flex !important;
            width: 360px !important;
            /* The panel is anchored to right:0 of a container pinned 6px from
               the right edge, so a width wider than the iframe does not clip
               on the right — it runs off the LEFT. The loader caps the iframe
               at calc(100vw - 28px), which on a 360px phone is ~332px, so a
               hard 360px panel started around -34px and lost its left edge.
               Capping to the viewport keeps 360px wherever there is room and
               shrinks it where there is not. max-width beats width, so the
               .expanded rule below inherits the same ceiling. */
            max-width: calc(100vw - 12px) !important;
            right: 0 !important;
            bottom: 62px !important;
        }
        /* Expand button effect — parent loader widens the iframe to
           720, the panel inside grows with it. */
        .tvaibwc-chat-widget.expanded { width: 700px !important; }
        /* Adapt panel height to iframe height. */
        @media (max-height: 760px) {
            .tvaibwc-chat-widget { height: 560px !important; }
        }
        @media (max-height: 700px) {
            .tvaibwc-chat-widget { height: 510px !important; }
        }
        /* Shrink the floating chevron-down to ~46px so it's noticeable
           but doesn't dominate the corner. */
        #tvaibwc-chatToggle {
            display: flex !important;
            width: 46px !important; height: 46px !important;
        }
        #tvaibwc-chatToggle i { font-size: 18px !important; }
        <?php endif; ?>
        /* Action CTAs get the brand gradient. The send button is
           intentionally NOT in this list — we leave it on the stock
           dark theme so it blends into the input bar.  */
        .tvaibwc-chat-button,
        .tvaibwc-form-submit,
        .tvaibwc-start-chat-button,
        .tvaibwc-customer-btn,
        .tvaibwc-visitor-btn {
            background: var(--tva-brand-gradient) !important;
            color: #fff !important;
            border: none !important;
        }
        /* Active nav tab. The label/icon stay WHITE for contrast on the
           dark navy tab bar — colouring them with the brand primary made
           the active tab (e.g. "Chat" after starting a chat) vanish when
           the brand colour is itself dark/navy. The brand instead shows
           as a bright accent bar under the active tab. */
        .tvaibwc-widget-tab.active { color: #fff !important; }
        .tvaibwc-widget-tab.active i { color: #fff !important; }
        .tvaibwc-widget-tab.active::after {
            content: '';
            position: absolute;
            left: 24%;
            right: 24%;
            bottom: 6px;
            height: 3px;
            border-radius: 3px;
            background: var(--tva-brand-accent) !important;
        }
    </style>
</head>
<body data-embed="<?= $tvaEmbed ? '1' : '0' ?>" data-project-key="<?= htmlspecialchars($tvaProjectKey) ?>">
    <div class="tvaibwc-chat-widget-container">
        <button class="tvaibwc-chat-button" id="tvaibwc-chatToggle">
            <i class="fas fa-comment"></i>
        </button>

        <div class="tvaibwc-chat-widget<?= $tvaIsDark ? " dark" : "" ?>" id="tvaibwc-chatWidget">
            <div class="tvaibwc-widget-header">
                <h6><?= htmlspecialchars($tvaConfig['bot_name']) ?></h6>
                <div class="tvaibwc-header-actions">
                    <?php if (!empty($tvaConfig['show_language'])): ?>
                    <?php
                        // Languages offered in the header picker. `code` is what
                        // we send to the backend; `short` is the chip label.
                        // The model still mirrors the user's actual language —
                        // this just sets the fallback / default.
                        $tvaLangs = [
                            ['code' => 'en', 'short' => 'EN', 'label' => 'English'],
                            ['code' => 'ar', 'short' => 'AR', 'label' => 'العربية'],
                            ['code' => 'ur', 'short' => 'UR', 'label' => 'اردو'],
                            ['code' => 'hi', 'short' => 'HI', 'label' => 'हिन्दी'],
                            ['code' => 'es', 'short' => 'ES', 'label' => 'Español'],
                            ['code' => 'fr', 'short' => 'FR', 'label' => 'Français'],
                            ['code' => 'zh', 'short' => 'ZH', 'label' => '中文'],
                        ];
                    ?>
                    <div class="tvaibwc-lang-select" id="tvaibwc-langSelect">
                        <button type="button" class="tvaibwc-lang-toggle" id="tvaibwc-langToggle"
                                title="Response language" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-globe"></i>
                            <span class="tvaibwc-lang-code" id="tvaibwc-langCode">EN</span>
                            <i class="fas fa-chevron-down tvaibwc-lang-caret"></i>
                        </button>
                        <div class="tvaibwc-lang-menu" id="tvaibwc-langMenu" role="menu">
                            <?php foreach ($tvaLangs as $i => $l): ?>
                            <button type="button" class="tvaibwc-lang-option<?= $i === 0 ? ' is-selected' : '' ?>"
                                    data-lang="<?= htmlspecialchars($l['code']) ?>"
                                    data-code="<?= htmlspecialchars($l['short']) ?>" role="menuitem">
                                <span class="tvaibwc-lang-option-label"><?= htmlspecialchars($l['label']) ?></span>
                                <span class="tvaibwc-lang-option-code"><?= htmlspecialchars($l['short']) ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($tvaConfig['show_theme_toggle'])): ?>
                    <label class="tvaibwc-theme-switch" title="Toggle light / dark mode">
                        <input type="checkbox" <?= $tvaIsDark ? "checked" : "" ?> id="tvaibwc-themeToggle">
                        <span class="tvaibwc-slider">
                            <i class="fas fa-sun"></i>
                            <i class="fas fa-moon"></i>
                        </span>
                    </label>
                    <?php endif; ?>
                    <?php if (!empty($tvaConfig['show_reply_toggle'])): ?>
                    <label class="tvaibwc-reply-switch" title="Voice replies on / off">
                        <input type="checkbox" id="tvaibwc-replyToggle">
                        <span class="tvaibwc-slider">
                            <i class="fas fa-comment"></i>
                            <i class="fas fa-volume-high"></i>
                        </span>
                    </label>
                    <?php endif; ?>
                    <?php if (!empty($tvaConfig['show_expand_button'])): ?>
                    <button id="tvaibwc-expandWidget" title="Expand / maximise">
                        <i class="fas fa-expand"></i>
                    </button>
                    <?php endif; ?>
                    <button id="tvaibwc-closeWidget" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div class="tvaibwc-widget-content active" id="tvaibwc-homeContent">
                <!-- Update your homeContent section -->
                <div class="tvaibwc-home-content">
                    <?php if (!empty($tvaConfig['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($tvaConfig['logo_url']) ?>" alt="<?= htmlspecialchars($tvaConfig['bot_name']) ?>" style="border-radius:50%; width:80px; height:80px; object-fit:cover;">
                    <?php else: ?>
                        <div style="font-size:64px; line-height:1; margin-bottom:8px;"><?= $tvaConfig['avatar_emoji'] ?></div>
                    <?php endif; ?>
                    <h4><?= htmlspecialchars($tvaConfig['welcome_title']) ?></h4>
                    <p class="tvaibwc-content-text"><?= htmlspecialchars($tvaConfig['welcome_message']) ?></p>
                    
                    <?php if (!empty($tvaConfig['show_visitor_modes'])): ?>
                    <div class="tvaibwc-user-type-buttons">
                        <button class="tvaibwc-user-type-btn tvaibwc-visitor-btn">
                            <i class="fas fa-user-clock"></i>
                            <span>New Visitor</span>
                        </button>
                        <button class="tvaibwc-user-type-btn tvaibwc-customer-btn">
                            <i class="fas fa-user-check"></i>
                            <span>Returning Customer</span>
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Existing Customer Form (hidden by default) -->
                    <div class="tvaibwc-customer-form" style="display:none;">
                        <form id="start_chat_session" method="POST" action="<?php echo BASE_HANDLERS_URL."/SessionHandler.php";?>">
                            <input type="hidden" name="action" value="getOrCreate">
                            <div class="tvaibwc-form-group">
                                <input type="text" class="tvaibwc-form-input" name="customer_name" id="tvaibwc-full-name" required>
                                <label for="tvaibwc-full-name">Full Name</label>
                            </div>
                            <div class="tvaibwc-form-group">
                                <input type="tel" class="tvaibwc-form-input" name="customer_phone" id="tvaibwc-phone" required>
                                <label for="tvaibwc-phone">Phone Number</label>
                            </div>
                            <div class="tvaibwc-form-group">
                                <input type="email" class="tvaibwc-form-input" name="customer_email" id="tvaibwc-email">
                                <label for="tvaibwc-email">Email Address</label>
                            </div>
                            <button type="submit" class="tvaibwc-form-submit">Continue to Chat</button>
                            <button type="reset" class="tvaibwc-form-back-button">Back</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tvaibwc-widget-content<?= $tvaIsDark ? " dark" : "" ?>" id="tvaibwc-chatContent">
                <div class="tvaibwc-chat-messages <?= $tvaIsDark ? "dark" : "light" ?>" id="tvaibwc-chatMessages">
                    <div class="tvaibwc-message tvaibwc-bot">
                        <div class="tvaibwc-message-text"><?= htmlspecialchars($tvaConfig['welcome_message']) ?></div>
                        <div class="tvaibwc-message-time"><?= date('g:i A') ?></div>
                    </div>
                </div>

                <?php // Flow actions bar — sits between messages and the
                      // start-chat / input area. Hosts the "Back to menu"
                      // pill so it's ALWAYS visible at the end of the
                      // message timeline regardless of how many messages
                      // came in after a handoff. ?>
                <div class="tvaibwc-flow-actions-bar" id="tvaibwc-flowActionsBar"></div>

                <button class="tvaibwc-start-chat-button" id="tvaibwc-startChatButton">
                    <i class="fas fa-comments"></i>
                    <span>Start chat</span>
                </button>

                <div class="tvaibwc-chat-input-container" id="tvaibwc-chatInputContainer">
                    <form id="create_chat_response" method="POST" enctype="multipart/form-data" action="<?php echo BASE_HANDLERS_URL."/ChatHandler.php";?>">
                        <input type="hidden" name="action" value="chatResponse">
                        <div class="tvaibwc-chat-input-row">
                            <input type="text" class="tvaibwc-chat-input" name="message_text" id="tvaibwc-chatInput" placeholder="<?= htmlspecialchars($tvaConfig['placeholder']) ?>">
                            <input type="hidden" name="message_type" id="message_type" class="tvaibwc-file-input d-none" data-type="">
                            <input type="hidden" name="response_in_voice" id="response_in_voice" value="0" class="tvaibwc-file-input d-none" data-type="">
                            <input type="file" name="message_file" id="message_file" class="tvaibwc-file-input d-none" data-type="">
                            <button class="tvaibwc-send-button" id="tvaibwc-sendButton">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        <div class="tvaibwc-chat-actions">
                            <?php if (!empty($tvaConfig['show_voice'])): ?>
                            <button class="tvaibwc-action-button" id="tvaibwc-voiceButton">
                                <i class="fas fa-microphone"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($tvaConfig['show_emoji'])): ?>
                            <button class="tvaibwc-action-button" id="tvaibwc-emojiButton">
                                <i class="far fa-smile"></i>
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($tvaConfig['show_attach'])): ?>
                            <button class="tvaibwc-action-button" id="tvaibwc-attachButton">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <?php endif; ?>

                            <?php // End conversation — saves transcript + marks session ended.
                                  // Different from the X-close in the header which just hides
                                  // the widget (visitor can resume). ?>
                            <button type="button" class="tvaibwc-end-session-btn" id="tvaibwc-endSessionBtn" title="End this conversation">
                                <i class="fas fa-circle-stop"></i> End chat
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Attachments inside chat widget -->
                <div class="tvaibwc-attachment-modal" style="display:none;">
                    <div class="tvaibwc-attachment-modal-content">
                        <span class="tvaibwc-close-modal">&times;</span>
                        <h5>Send File</h5>
                        <div class="tvaibwc-attachment-options">
                        <label class="tvaibwc-attachment-option">
                            <input type="file" accept="image/*" class="tvaibwc-file-input" data-type="image">
                            <i class="fas fa-image"></i>
                            <span>Photo</span>
                        </label>
                        <label class="tvaibwc-attachment-option">
                            <input type="file" accept="video/*" class="tvaibwc-file-input" data-type="video">
                            <i class="fas fa-video"></i>
                            <span>Video</span>
                        </label>
                        <label class="tvaibwc-attachment-option">
                            <input type="file" accept="audio/*" class="tvaibwc-file-input" data-type="audio">
                            <i class="fas fa-microphone"></i>
                            <span>Audio</span>
                        </label>
                        <label class="tvaibwc-attachment-option">
                            <input type="file" class="tvaibwc-file-input" data-type="file">
                            <i class="fas fa-paperclip"></i>
                            <span>File</span>
                        </label>
                        </div>
                    </div>
                </div>

                <button class="tvaibwc-back-to-tabs" id="tvaibwc-backToTabs">
                    <i class="fas fa-chevron-down"></i> Back to Menu
                </button>
            </div>

            <div class="tvaibwc-widget-content" id="tvaibwc-historyContent">
                <h6>Recent Conversations</h6>
                <div class="tvaibwc-history-list" id="tvaibwc-historyList">
                    <div class="tvaibwc-history-item">
                        <div class="tvaibwc-history-date">Today, 10:30 AM</div>
                        <div class="tvaibwc-history-preview">Hello! How can I help you today?</div>
                    </div>
                    <div class="tvaibwc-history-item">
                        <div class="tvaibwc-history-date">Yesterday, 2:45 PM</div>
                        <div class="tvaibwc-history-preview">Thanks for your question about our pricing plans...</div>
                    </div>
                    <div class="tvaibwc-history-item">
                        <div class="tvaibwc-history-date">Monday, 9:15 AM</div>
                        <div class="tvaibwc-history-preview">Your account has been successfully upgraded...</div>
                    </div>
                </div>
            </div>

            <div class="tvaibwc-widget-content" id="tvaibwc-faqContent">
                <h6>Frequently Asked Questions</h6>
                <div class="tvaibwc-faq-list" id="tvaibwc-faqList">
                    <div class="tvaibwc-faq-item">
                        <div class="tvaibwc-faq-question">
                            <span>How do I reset my password?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="tvaibwc-faq-answer" style="display: none;">
                            You can reset your password by clicking on the "Forgot Password" link on the login page. We'll send you an email with instructions to reset it.
                        </div>
                    </div>
                    <div class="tvaibwc-faq-item">
                        <div class="tvaibwc-faq-question">
                            <span>What payment methods do you accept?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="tvaibwc-faq-answer" style="display: none;">
                            We accept all major credit cards including Visa, MasterCard, and American Express. We also support PayPal for certain regions.
                        </div>
                    </div>
                    <div class="tvaibwc-faq-item">
                        <div class="tvaibwc-faq-question">
                            <span>How can I cancel my subscription?</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="tvaibwc-faq-answer" style="display: none;">
                            You can cancel your subscription at any time from the Billing section in your account settings. Your subscription will remain active until the end of the current billing period.
                        </div>
                    </div>
                </div>
            </div>

            <div class="tvaibwc-widget-tabs" id="tvaibwc-widgetTabs">
                <div class="tvaibwc-widget-tab active" data-tab="tvaibwc-home">
                    <i class="fas fa-home"></i>
                    <span>Home</span>
                </div>
                <div class="tvaibwc-widget-tab" data-tab="tvaibwc-chat">
                    <i class="fas fa-comment-dots"></i>
                    <span>Chat</span>
                </div>
                <?php if (!empty($tvaConfig['show_history_tab'])): ?>
                <div class="tvaibwc-widget-tab" data-tab="tvaibwc-history">
                    <i class="fas fa-history"></i>
                    <span>History</span>
                </div>
                <?php endif; ?>
                <div class="tvaibwc-widget-tab" data-tab="tvaibwc-faq">
                    <i class="fas fa-question-circle"></i>
                    <span>FAQ</span>
                </div>
            </div>

            <?php if (!empty($tvaConfig['show_powered_by'])): ?>
            <div style="text-align:center; font-size:10.5px; color:#94a3b8; padding:6px 10px; border-top:1px solid rgba(148,163,184,.15);">
                Powered by <a href="https://nuerabot.io" target="_blank" rel="noopener" style="color:inherit; text-decoration:none; font-weight:600;">NueraBot</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($tvaEmbed): ?>
    <script>
        // Embed-mode: tell the parent window to close us when the user
        // clicks the X in the widget header, and signal that we're ready.
        window.TVAIBWC_PROJECT_API_KEY = <?= json_encode($tvaProjectKey) ?>;
        window.TVAIBWC_EMBED = true;
        window.TVAIBWC_WIDGET_CONFIG = <?= json_encode($tvaConfig) ?>;

        document.addEventListener('DOMContentLoaded', function () {
            // Auto-open the panel — the parent loader handles the
            // outer launcher, but this iframe still owns the inner
            // floating ▼ button and the X in the header.
            var w = document.getElementById('tvaibwc-chatWidget');
            if (w) { w.classList.add('active'); w.classList.add('show'); }

            // Swap the chat-toggle icon to a chevron-down so users
            // know it minimises the widget.
            var toggle = document.getElementById('tvaibwc-chatToggle');
            if (toggle) {
                toggle.innerHTML = '<i class="fas fa-chevron-down"></i>';
                toggle.title = 'Minimise';
            }

            function closeToParent(e) {
                if (e) e.preventDefault();
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: 'tvaibwc:close' }, '*');
                }
            }

            var closeBtn = document.getElementById('tvaibwc-closeWidget');
            if (closeBtn) closeBtn.addEventListener('click', closeToParent);
            if (toggle)   toggle.addEventListener('click', closeToParent);

            // Expand button: stock JS toggles .expanded which widens
            // the panel from 360 → 720. The iframe is only 380 wide,
            // so we forward the toggle to the parent loader and it
            // resizes the iframe to match.
            var expandBtn = document.getElementById('tvaibwc-expandWidget');
            if (expandBtn) {
                expandBtn.addEventListener('click', function () {
                    // The stock handler runs first (jQuery .click was
                    // attached at DOM ready); we just need to read the
                    // resulting state and broadcast it.
                    setTimeout(function () {
                        var on = document.getElementById('tvaibwc-chatWidget')
                                 .classList.contains('expanded');
                        if (window.parent && window.parent !== window) {
                            window.parent.postMessage({
                                type: 'tvaibwc:expand', on: on
                            }, '*');
                        }
                    }, 0);
                });
            }

            if (window.parent && window.parent !== window) {
                window.parent.postMessage({ type: 'tvaibwc:ready' }, '*');
            }
        });

        /* Follow the host page's light/dark switch.
           loader.js sends this whenever the site's theme changes. Rather
           than re-implementing the class toggling, we flip the existing
           theme checkbox and fire its change handler — one source of truth
           for what "dark" means, so the two can never drift apart. */
        window.addEventListener('message', function (ev) {
            if (!ev.data || ev.data.type !== 'tvaibwc:theme') return;

            var wantDark = ev.data.theme === 'dark';
            var toggle   = document.getElementById('tvaibwc-themeToggle');

            if (!toggle) {
                // The project hid the theme switch — apply the classes directly
                // so the widget still follows the page.
                document.getElementById('tvaibwc-chatWidget')?.classList.toggle('dark', wantDark);
                document.querySelectorAll('.tvaibwc-widget-content').forEach(function (el) {
                    el.classList.toggle('dark', wantDark);
                });
                var msgs = document.getElementById('tvaibwc-chatMessages');
                if (msgs) { msgs.classList.toggle('dark', wantDark); msgs.classList.toggle('light', !wantDark); }
                return;
            }

            if (toggle.checked === wantDark) return;   // already there
            toggle.checked = wantDark;

            // jQuery's .change() handler is what does the real work; a native
            // event alone would not reach a jQuery-bound listener.
            if (window.jQuery) window.jQuery(toggle).trigger('change');
            else toggle.dispatchEvent(new Event('change', { bubbles: true }));
        });
    </script>
    <?php endif; ?>
    <script src="<?php echo BASE_URL;?>/assets/js/jquery-3.6.0.min.js"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/tvaibwc-core.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1/index.js" type="module"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/common-vars.js"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/common.js"></script>
    <?php $v = time(); /* TODO: switch to filemtime() per file for prod */ ?>
    <script src="<?php echo BASE_URL;?>/assets/js/transport/api-client.js?v=<?php echo $v;?>"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/transport/ws-client.js?v=<?php echo $v;?>"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/transport/audio-playback.js?v=<?php echo $v;?>"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/transport/mic-recorder.js?v=<?php echo $v;?>"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/flow-runtime.js?v=<?php echo $v;?>"></script>
    <script src="<?php echo BASE_URL;?>/assets/js/request-handler.js?v=<?php echo $v;?>"></script>
</body>
</html>