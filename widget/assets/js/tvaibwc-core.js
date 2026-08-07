
    // Initialize with dark theme
    $('#tvaibwc-chatWidget').addClass('dark');
    $('.tvaibwc-widget-content').addClass('dark');
    $('.tvaibwc-message.tvaibwc-bot').addClass('dark');
    $('.tvaibwc-action-button').addClass('dark');
    $('.tvaibwc-history-item').addClass('dark');
    $('.tvaibwc-faq-item').addClass('dark');
    $('.tvaibwc-faq-answer').addClass('dark');
    $('.tvaibwc-home-content p').addClass('dark');
    $('.tvaibwc-history-date').addClass('dark');
    $('#tvaibwc-chatMessages').addClass('dark');
    
    // Toggle chat widget
    $('#tvaibwc-chatToggle').click(function() {
        $('#tvaibwc-chatWidget').toggleClass('show');
        $(this).toggleClass('open');
        
        if ($(this).hasClass('open')) {
            $(this).html('<i class="fas fa-chevron-down"></i>');
        } else {
            $(this).html('<i class="fas fa-comment"></i>');
        }
    });

    // Close widget from top button
    $('#tvaibwc-closeWidget').click(function() {
        $('#tvaibwc-chatWidget').removeClass('show');
        $('#tvaibwc-chatToggle').html('<i class="fas fa-comment"></i>');
        $('#tvaibwc-chatToggle').removeClass('open');
    });

    // Tab switching
    $('.tvaibwc-widget-tab').click(function() {
        const tab = $(this).data('tab');
        
        // Update active tab
        $('.tvaibwc-widget-tab').removeClass('active');
        $(this).addClass('active');
        
        // Show corresponding content
        $('.tvaibwc-widget-content').removeClass('active');
        $(`#${tab}Content`).addClass('active');
        
        // Scroll to bottom if chat tab
        if (tab === 'tvaibwc-chat') {
            tvaibwc_scrollToBottom();
        }
    });

    // Start chat button — two purposes:
    //   (a) Initial chat-tab entry: just reveal the input.
    //   (b) After an "End chat" the session was cleared (session_id =
    //       null) — clicking this needs to mint a brand-new session
    //       so flow re-bootstrap fires on the server. tvaibwcEnsureSession
    //       handles both cases idempotently: returns the existing one
    //       if still live, creates one otherwise.
    $('#tvaibwc-startChatButton').click(function() {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $btn.hide().removeClass('is-visible');
        $('#tvaibwc-chatInputContainer').addClass('active').removeClass('is-ended');
        $('#tvaibwc-widgetTabs').hide();
        $('#tvaibwc-backToTabs').show();
        $('#tvaibwc-chatInput').focus();

        // Fire ensureSession so the flow (if bound) restarts cleanly.
        // The empty profile is fine — first chat had whatever was
        // typed, the restart can adopt the same defaults.
        if (typeof window.tvaibwcEnsureSession === 'function') {
            window.tvaibwcEnsureSession()
                .catch(function (e) { console.warn('[tvaibwc] restart session failed', e); })
                .then(function () { $btn.prop('disabled', false); });
        } else {
            $btn.prop('disabled', false);
        }
    });

    // Back to tabs button — restores the navigation tabs.
    $('#tvaibwc-backToTabs').click(function() {
        $('#tvaibwc-chatInputContainer').removeClass('active');
        $('#tvaibwc-widgetTabs').show();
        $('#tvaibwc-chatWidget').removeClass('no-tabs');  // mirror tab state
        $('#tvaibwc-startChatButton').show();
        $(this).hide();
    });

    // Theme toggle
    $('#tvaibwc-themeToggle').change(function() {
        if ($(this).is(':checked')) {
            // Dark theme
            $('#tvaibwc-chatWidget').addClass('dark');
            $('.tvaibwc-widget-content').addClass('dark');
            $('.tvaibwc-message.tvaibwc-bot').addClass('dark');
            $('.tvaibwc-action-button').addClass('dark');
            $('.tvaibwc-history-item').addClass('dark');
            $('.tvaibwc-faq-item').addClass('dark');
            $('.tvaibwc-faq-answer').addClass('dark');
            $('.tvaibwc-home-content p').addClass('dark');
            $('.tvaibwc-history-date').addClass('dark');
            $('#tvaibwc-chatMessages').removeClass('light').addClass('dark');
        } else {
            // Light theme
            $('#tvaibwc-chatWidget').removeClass('dark');
            $('.tvaibwc-widget-content').removeClass('dark');
            $('.tvaibwc-message.tvaibwc-bot').removeClass('dark');
            $('.tvaibwc-action-button').removeClass('dark');
            $('.tvaibwc-history-item').removeClass('dark');
            $('.tvaibwc-faq-item').removeClass('dark');
            $('.tvaibwc-faq-answer').removeClass('dark');
            $('.tvaibwc-home-content p').removeClass('dark');
            $('.tvaibwc-history-date').removeClass('dark');
            $('#tvaibwc-chatMessages').removeClass('dark').addClass('light');
        }
    });

    // ─── Language picker (header dropdown) ───────────────────────────
    // Persisted in localStorage so a returning visitor keeps their pick.
    // window.tvaibwcGetLang() is the single source of truth read by the
    // session-start + per-turn senders. The pick is only the fallback /
    // default language — the bot still mirrors the language the visitor
    // actually writes (so "picked English, typed Urdu" → Urdu reply).
    (function () {
        var KEY = 'tvaibwc_lang';
        var stored = null;
        try { stored = localStorage.getItem(KEY); } catch (_) {}
        window.tvaibwcSelectedLang = stored || 'en';

        function applySelection(code) {
            window.tvaibwcSelectedLang = code || 'en';
            try { localStorage.setItem(KEY, window.tvaibwcSelectedLang); } catch (_) {}
            var $opt = $('.tvaibwc-lang-option[data-lang="' + window.tvaibwcSelectedLang + '"]');
            $('#tvaibwc-langCode').text(
                ($opt.data('code') || window.tvaibwcSelectedLang).toString().toUpperCase()
            );
            $('.tvaibwc-lang-option').removeClass('is-selected');
            $opt.addClass('is-selected');
        }

        // Reflect a persisted pick on load (HTML already marks EN by default).
        if (stored) applySelection(stored);

        $('#tvaibwc-langToggle').on('click', function (e) {
            e.stopPropagation();
            var open = $('#tvaibwc-langSelect').toggleClass('open').hasClass('open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
        });

        $('.tvaibwc-lang-option').on('click', function (e) {
            e.stopPropagation();
            applySelection($(this).data('lang'));
            $('#tvaibwc-langSelect').removeClass('open');
            $('#tvaibwc-langToggle').attr('aria-expanded', 'false');
        });

        // Close the menu when clicking anywhere outside it.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('#tvaibwc-langSelect').length) {
                $('#tvaibwc-langSelect').removeClass('open');
                $('#tvaibwc-langToggle').attr('aria-expanded', 'false');
            }
        });
    })();

    window.tvaibwcGetLang = function () {
        return window.tvaibwcSelectedLang || 'en';
    };

    //Toggle reply mode
    $('#tvaibwc-replyToggle').change(function() {
        if ($(this).is(':checked')) {
            $("#response_in_voice").val(1);
        }
        else{
            $("#response_in_voice").val(0);
        }

    });

    // Expand widget
    $('#tvaibwc-expandWidget').click(function() {
        $('#tvaibwc-chatWidget').toggleClass('expanded');
        
        if ($('#tvaibwc-chatWidget').hasClass('expanded')) {
            $(this).html('<i class="fas fa-compress"></i>');
        } else {
            $(this).html('<i class="fas fa-expand"></i>');
        }
    });

    // Send message
    $('#tvaibwc-chatInput').keypress(function(e) {
        if (e.which === 13) {
            //$('#create_chat_response').trigger('submit');
        }
    });

    function tvaibwc_sendMessage() {
        const messageText = $('#tvaibwc-chatInput').val().trim();
        if (messageText) {
            // Add user message
            $('#tvaibwc-chatMessages').append(`
                <div class="tvaibwc-message tvaibwc-user">
                    <div class="tvaibwc-message-text">${tvaibwc_escapeHtml(messageText)}</div>
                    <div class="tvaibwc-message-time">${tvaibwc_getCurrentTime()}</div>
                </div>
            `);
            // Scroll to bottom
            tvaibwc_scrollToBottom();
            $(".tvaibwc-emoji-picker-container").css("display", "none");
        }
    }

    // Auto-scroll to bottom
    function tvaibwc_scrollToBottom() {
        const messages = $('#tvaibwc-chatMessages');
        messages.scrollTop(messages[0].scrollHeight);
    }

    // FAQ accordion
    $('.tvaibwc-faq-question').click(function() {
        $(this).next('.tvaibwc-faq-answer').slideToggle();
        $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
    });

    // Audio elements storage
    const tvaibwc_audioElements = {};
    
    // Voice recording variables
    let tvaibwc_mediaRecorder;
    let tvaibwc_audioChunks = [];
    let tvaibwc_recordingStartTime;
    let tvaibwc_timerInterval;
    
    // Check for recording support
    const tvaibwc_isRecordingSupported = () => {
        return navigator.mediaDevices && window.MediaRecorder;
    };
    
    // Initialize voice recording button
    $('#tvaibwc-voiceButton').click(function(e) {
        e.preventDefault();
        
        if (!tvaibwc_isRecordingSupported()) {
            alert('Voice recording is not supported in your browser');
            return;
        }
        
        if ($(this).hasClass('tvaibwc-recording')) {
            tvaibwc_stopRecording();
        } else {
            tvaibwc_startRecording();
        }
    });
    
    // Start recording — streams PCM16 chunks over WS to /ws/turn.
    // Falls back to legacy MediaRecorder if WS isn't ready.
    function tvaibwc_startRecording() {
        var s = window.tvaibwcSession;
        var wsReady = s && s.wsReady && s.ws && typeof window.tvaibwcVoiceStart === 'function';

        // Common UI feedback (used by both paths)
        var setRecordingUI = function () {
            $('#tvaibwc-chatInput').prop('disabled', true);
            $('#tvaibwc-voiceButton').addClass('tvaibwc-recording');
            $('#tvaibwc-voiceButton i').removeClass('fa-microphone').addClass('fa-stop');
            tvaibwc_recordingStartTime = Date.now();
            tvaibwc_updateRecordingTimer();
            tvaibwc_timerInterval = setInterval(tvaibwc_updateRecordingTimer, 1000);
        };
        var resetRecordingUI = function () {
            clearInterval(tvaibwc_timerInterval);
            $('#tvaibwc-voiceButton').removeClass('tvaibwc-recording');
            $('#tvaibwc-voiceButton i').removeClass('fa-stop').addClass('fa-microphone');
            $('#tvaibwc-recording-timer').remove();
            $('#tvaibwc-chatInput').prop('disabled', false);
        };

        // ─── WS streaming path (preferred) ───
        if (wsReady) {
            try {
                if (window.tvaibwcVoiceStart() === false) {
                    // tvaibwcVoiceStart signals "not ready" — fall through to legacy
                    console.warn('[tvaibwc] WS voice start refused, falling back to legacy');
                } else {
                    setRecordingUI();
                    // Stash for stop() — so we know which path to unwind.
                    $('#tvaibwc-voiceButton').data('mode', 'ws');
                    return;
                }
            } catch (err) {
                console.warn('[tvaibwc] WS voice start threw, falling back to legacy', err);
            }
        }

        // ─── Legacy MediaRecorder fallback (HTTP form submit) ───
        $('#tvaibwc-voiceButton').data('mode', 'legacy');
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (tvaibwc_stream) {
                tvaibwc_mediaRecorder = new MediaRecorder(tvaibwc_stream);
                setRecordingUI();

                tvaibwc_mediaRecorder.ondataavailable = function (e) {
                    tvaibwc_audioChunks.push(e.data);
                };
                tvaibwc_mediaRecorder.onstop = function () {
                    resetRecordingUI();
                    var tvaibwc_audioBlob = new Blob(tvaibwc_audioChunks, { type: 'audio/wav' });
                    tvaibwc_audioChunks = [];
                    var tvaibwc_audioUrl = URL.createObjectURL(tvaibwc_audioBlob);
                    var recorded_audio = new File([tvaibwc_audioBlob], "recorded.wav", { type: "audio/wav" });
                    var dataTransfer = new DataTransfer();
                    dataTransfer.items.add(recorded_audio);
                    $("#message_file")[0].files = dataTransfer.files;
                    $("#message_type").val('voice');
                    tvaibwc_sendAudioMessage(tvaibwc_audioUrl, Math.floor((Date.now() - tvaibwc_recordingStartTime) / 1000));
                    tvaibwc_stream.getTracks().forEach(function (t) { t.stop(); });
                    $("#create_chat_response").trigger('submit');
                    $("#message_file").val("");
                };
                tvaibwc_mediaRecorder.start(100);
            })
            .catch(function (error) {
                console.error('Error accessing microphone:', error);
                alert('Could not access microphone. Please check permissions.');
                resetRecordingUI();
            });
    }

    // Stop recording — dispatches to the path that was started.
    function tvaibwc_stopRecording() {
        var mode = $('#tvaibwc-voiceButton').data('mode') || 'legacy';

        if (mode === 'ws' && typeof window.tvaibwcVoiceStop === 'function') {
            // UI reset happens here; the WS reply will populate the bubble.
            clearInterval(tvaibwc_timerInterval);
            $('#tvaibwc-voiceButton').removeClass('tvaibwc-recording');
            $('#tvaibwc-voiceButton i').removeClass('fa-stop').addClass('fa-microphone');
            $('#tvaibwc-recording-timer').remove();
            $('#tvaibwc-chatInput').prop('disabled', false);
            window.tvaibwcVoiceStop();
            return;
        }

        // Legacy path: MediaRecorder.onstop() handles UI reset + form submit.
        if (tvaibwc_mediaRecorder && tvaibwc_mediaRecorder.state !== 'inactive') {
            tvaibwc_mediaRecorder.stop();
        }
    }
    
    // Update recording timer
    function tvaibwc_updateRecordingTimer() {
        const tvaibwc_seconds = Math.floor((Date.now() - tvaibwc_recordingStartTime) / 1000);
        let $tvaibwc_timer = $('#tvaibwc-recording-timer');
        
        if (!$tvaibwc_timer.length) {
            $tvaibwc_timer = $(`<div id="tvaibwc-recording-timer" class="tvaibwc-recording-timer">00:00</div>`);
            $('#tvaibwc-chatInputContainer').append($tvaibwc_timer);
        }
        
        const tvaibwc_mins = Math.floor(tvaibwc_seconds / 60).toString().padStart(2, '0');
        const tvaibwc_secs = (tvaibwc_seconds % 60).toString().padStart(2, '0');
        $tvaibwc_timer.text(`${tvaibwc_mins}:${tvaibwc_secs}`);
    }
    
    // Send audio message (simulated)
    function tvaibwc_sendAudioMessage(tvaibwc_audioUrl, tvaibwc_duration) {
        const tvaibwc_messageId = 'tvaibwc-audio-' + Date.now();
        tvaibwc_addAudioMessage(tvaibwc_messageId, tvaibwc_audioUrl, tvaibwc_duration, 'tvaibwc-user');
    }
    
    // Add audio message to chat
    function tvaibwc_addAudioMessage(tvaibwc_id, tvaibwc_audioUrl, tvaibwc_duration, tvaibwc_sender) {
        const tvaibwc_isDark = $('#tvaibwc-themeToggle').is(':checked');
        const tvaibwc_time = tvaibwc_getCurrentTime();
        
        const tvaibwc_audioPlayer = `
            <div class="tvaibwc-audio-player ${tvaibwc_sender} ${tvaibwc_isDark ? 'dark' : ''}">
                <button class="tvaibwc-play-btn">
                    <i class="fas fa-play"></i>
                </button>
                <div class="tvaibwc-progress-container">
                    <div class="tvaibwc-progress-bar"></div>
                    <div class="tvaibwc-progress-time">00:00</div>
                </div>
                <div class="tvaibwc-duration">${tvaibwc_formatDuration(tvaibwc_duration)}</div>
                <audio src="${tvaibwc_audioUrl}" id="${tvaibwc_id}"></audio>
            </div>
        `;
        
        const tvaibwc_messageHTML = `
            <div class="tvaibwc-audio-message ${tvaibwc_sender}">
                <div class="tvaibwc-message-text">${tvaibwc_audioPlayer}</div>
                <div class="tvaibwc-message-time">${tvaibwc_time}</div>
            </div>
        `;
        
        $('#tvaibwc-chatMessages').append(tvaibwc_messageHTML);
        tvaibwc_scrollToBottom();
        
        // Initialize audio player
        tvaibwc_initAudioPlayer(tvaibwc_id);
    }
    
    // Initialize audio player controls
    function tvaibwc_initAudioPlayer(tvaibwc_id) {
        const $tvaibwc_player = $(`#${tvaibwc_id}`).parent();
        const $tvaibwc_audio = $(`#${tvaibwc_id}`)[0];
        const $tvaibwc_playBtn = $tvaibwc_player.find('.tvaibwc-play-btn');
        const $tvaibwc_progressBar = $tvaibwc_player.find('.tvaibwc-progress-bar');
        const $tvaibwc_progressTime = $tvaibwc_player.find('.tvaibwc-progress-time');
        
        $tvaibwc_playBtn.click(function() {
            if ($tvaibwc_audio.paused) {
                $tvaibwc_audio.play();
                $(this).html('<i class="fas fa-pause"></i>');
            } else {
                $tvaibwc_audio.pause();
                $(this).html('<i class="fas fa-play"></i>');
            }
        });
        
        $tvaibwc_audio.addEventListener('timeupdate', function() {
            const tvaibwc_currentTime = $tvaibwc_audio.currentTime;
            const tvaibwc_duration = $tvaibwc_audio.duration;
            const tvaibwc_progressPercent = (tvaibwc_currentTime / tvaibwc_duration) * 100;
            console.log("timeupdate " + tvaibwc_progressPercent);
            $tvaibwc_progressBar.css('width', `${tvaibwc_progressPercent}%`);
            $tvaibwc_progressTime.text(tvaibwc_formatDuration(tvaibwc_currentTime));
        });
        
        $tvaibwc_audio.addEventListener('ended', function() {
            $tvaibwc_playBtn.html('<i class="fas fa-play"></i>');
        });
        
        $tvaibwc_audio.addEventListener('loadedmetadata', function() {
            console.log("loadedmetadata" + $tvaibwc_audio.duration);
            $tvaibwc_player.find('.tvaibwc-duration').text(tvaibwc_formatDuration($tvaibwc_audio.duration));
        });
    }
    
    // Format duration (seconds to MM:SS)
    function tvaibwc_formatDuration(tvaibwc_seconds) {
        if(tvaibwc_seconds && tvaibwc_seconds!="Infinity"){
            const tvaibwc_mins = Math.floor(tvaibwc_seconds / 60).toString().padStart(2, '0');
            const tvaibwc_secs = Math.floor(tvaibwc_seconds % 60).toString().padStart(2, '0');
            if(tvaibwc_mins!="NaN" && tvaibwc_secs!="Nan"){
                return `${tvaibwc_mins}:${tvaibwc_secs}`;
            }
            else{
                return "few secs";
            }
        }
        else{
            return "few secs";
        }
    }

    // Escape HTML so arbitrary model/flow text can't inject markup.
    function tvaibwc_escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Turn plain bot/flow text into HTML-safe markup with real line
    // breaks. The LLM (and some flow authors) emit literal "<br>" or
    // blank lines to separate paragraphs; rendered with .text() those
    // show up as the visible characters "<br>" instead of a break. So:
    // normalise any literal <br> to a newline, escape everything, then
    // convert newlines back into real <br> tags. Use with .html().
    function tvaibwc_textToHtml(text) {
        var s = String(text == null ? '' : text).replace(/<br\s*\/?>/gi, '\n');
        s = tvaibwc_escapeHtml(s);
        return s.replace(/\n{3,}/g, '\n\n').replace(/\n/g, '<br>');
    }
    window.tvaibwc_escapeHtml = tvaibwc_escapeHtml;
    window.tvaibwc_textToHtml = tvaibwc_textToHtml;

    // Helper function to get current time
    function tvaibwc_getCurrentTime() {
        const tvaibwc_now = new Date();
        let tvaibwc_hours = tvaibwc_now.getHours();
        const tvaibwc_minutes = tvaibwc_now.getMinutes().toString().padStart(2, '0');
        const tvaibwc_ampm = tvaibwc_hours >= 12 ? 'PM' : 'AM';
        tvaibwc_hours = tvaibwc_hours % 12;
        tvaibwc_hours = tvaibwc_hours ? tvaibwc_hours : 12;
        return `${tvaibwc_hours}:${tvaibwc_minutes} ${tvaibwc_ampm}`;
    }

    // Emoji Section

    const tvaibwc_initEmojiPicker = () => {
        // Create emoji picker container
        const pickerContainer = document.createElement('div');
        pickerContainer.className = 'tvaibwc-emoji-picker-container';
        pickerContainer.style.display = 'none';
        pickerContainer.style.position = 'absolute';
        pickerContainer.style.bottom = '60px';
        pickerContainer.style.right = '10px';
        pickerContainer.style.zIndex = '1001';
        
        // Create emoji picker
        const picker = document.createElement('emoji-picker');
        picker.classList.add('tvaibwc-emoji-picker');
        pickerContainer.appendChild(picker);
        
        // Add to chat container
        document.getElementById('tvaibwc-chatWidget').appendChild(pickerContainer);
        
        // Handle emoji selection
        picker.addEventListener('emoji-click', event => {
            const emoji = event.detail.unicode;
            const input = document.getElementById('tvaibwc-chatInput');
            input.value += emoji;
            input.focus();
        });
        
        // Toggle picker visibility
        $('#tvaibwc-emojiButton').click(function(e) {
            e.preventDefault();
            const isVisible = pickerContainer.style.display === 'block';
            pickerContainer.style.display = isVisible ? 'none' : 'block';
            
            // Close if clicking outside
            if (!isVisible) {
                setTimeout(() => {
                    $(document).one('click', function closePicker(e) {
                        if (!$(e.target).closest('.tvaibwc-emoji-picker-container, #tvaibwc-emojiButton').length) {
                            pickerContainer.style.display = 'none';
                            $(document).off('click', closePicker);
                        }
                    });
                }, 0);
            }
        });
    };

    // Call the initialization
    tvaibwc_initEmojiPicker();


    //For Attachment

    // Initialize attachment modal
    function tvaibwc_initAttachmentModal() {
        // Position modal above input area
        function tvaibwc_positionModal() {
            const $inputContainer = $('#tvaibwc-chatInputContainer');
            const $modal = $('.tvaibwc-attachment-modal');
            
            $modal.css({
                'bottom': $inputContainer.outerHeight() + 35 + 'px',
                'left': '10px',
                'right': '10px',
                'width': 'calc(100% - 20px)'
            });
        }
        
        // Handle attachment button click
        $('#tvaibwc-attachButton').click(function(e) {
            e.preventDefault();
            e.stopPropagation();
            tvaibwc_positionModal();
            $('.tvaibwc-attachment-modal').fadeIn(200);
        });
        
        // Close modal
        $('.tvaibwc-close-modal').click(function() {
            $('.tvaibwc-attachment-modal').fadeOut(200);
        });
        
        // Close when clicking outside
        $(document).click(function(e) {
            if (!$(e.target).closest('.tvaibwc-attachment-modal-content, #tvaibwc-attachButton').length) {
                $('.tvaibwc-attachment-modal').fadeOut(200);
            }
        });
        
        // Prevent modal close when clicking content
        $('.tvaibwc-attachment-modal-content').click(function(e) {
            e.stopPropagation();
        });
        
        // Handle file selection
        $('.tvaibwc-file-input').change(function() {
            const file = this.files[0];
            const type = $(this).data('type');
            
            if (file) {
                tvaibwc_handleFileUpload(file, type);
                $('.tvaibwc-attachment-modal').fadeOut(200);
            }
        });
        
        // Reposition on resize
        $(window).resize(tvaibwc_positionModal);
    }
    
    // Handle file upload and display
    function tvaibwc_handleFileUpload(file, type) {
        const reader = new FileReader();
        const isDark = $('#tvaibwc-themeToggle').is(':checked');
        const messageId = 'tvaibwc-media-' + Date.now();
        
        reader.onload = function(e) {
            let contentHtml = '';
            const fileSize = (file.size / 1024).toFixed(2) + ' KB';
            const fileName = file.name.length > 20 ? 
                file.name.substring(0, 15) + '...' + file.name.split('.').pop() : 
                file.name;
            
            switch(type) {
                case 'image':
                    contentHtml = `
                        <div class="tvaibwc-media-container">
                            <img src="${e.target.result}" alt="Sent image" class="tvaibwc-media-image">
                            <div class="tvaibwc-media-info">
                                <span class="tvaibwc-media-name">${fileName}</span>
                                <span class="tvaibwc-media-size">${fileSize}</span>
                            </div>
                        </div>
                    `;
                    break;
                case 'audio':
                    contentHtml = `
                        <div class="tvaibwc-media-container" data-audio-id="tvaibwc-audio-${Date.now()}">
                            <div class="tvaibwc-audio-player">
                                <div class="tvaibwc-audio-controls">
                                    <button class="tvaibwc-play-audio">
                                        <i class="fas fa-play"></i>
                                    </button>
                                    <div class="tvaibwc-audio-progress">
                                        <div class="tvaibwc-progress-bar"></div>
                                    </div>
                                    <span class="tvaibwc-audio-duration">00:00</span>
                                    <a href="${e.target.result}" download="${file.name}" class="tvaibwc-download-audio">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                                <div class="tvaibwc-media-info">
                                    <span class="tvaibwc-media-name">${fileName}</span>
                                    <span class="tvaibwc-media-size">${fileSize}</span>
                                </div>
                                <audio src="${e.target.result}" class="tvaibwc-audio-element"></audio>
                            </div>
                        </div>
                    `;
                    break;
                case 'video':
                    contentHtml = `
                        <div class="tvaibwc-media-container">
                            <video controls class="tvaibwc-media-video">
                                <source src="${e.target.result}" type="${file.type}">
                            </video>
                            <div class="tvaibwc-media-info">
                                <span class="tvaibwc-media-name">${fileName}</span>
                                <span class="tvaibwc-media-size">${fileSize}</span>
                            </div>
                        </div>
                    `;
                    break;
                    
                default:
                    contentHtml = `
                        <div class="tvaibwc-file-container">
                            <div class="tvaibwc-file-icon">
                                <i class="fas fa-file-alt"></i>
                                <span class="tvaibwc-file-extension">${file.name.split('.').pop()}</span>
                            </div>
                            <div class="tvaibwc-file-info">
                                <span class="tvaibwc-media-name">${fileName}</span>
                                <span class="tvaibwc-media-size">${fileSize}</span>
                                <a href="${e.target.result}" download="${file.name}" class="tvaibwc-download-btn">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                        </div>
                    `;
            }
            
            const messageHTML = `
                <div class="tvaibwc-message tvaibwc-user">
                    <div class="tvaibwc-message-text">
                        ${contentHtml}
                    </div>
                    <div class="tvaibwc-message-time">${tvaibwc_getCurrentTime()}</div>
                </div>
            `;
            
            $('#tvaibwc-chatMessages').append(messageHTML);
            tvaibwc_scrollToBottom();
            // Call this after appending new messages
            setTimeout(tvaibwc_initAudioPlayers, 100);
            // Simulate bot response
            setTimeout(() => {
                tvaibwc_addBotResponse(type, isDark);
            }, 1000);
        };
        
        reader.readAsDataURL(file);
    }
    
    function tvaibwc_initAudioPlayers() {
        // Stop all other audio players when one starts
        function stopAllAudioPlayers() {
            $('.tvaibwc-audio-element').each(function() {
                this.pause();
                $(this).siblings('.tvaibwc-audio-controls')
                    .find('.tvaibwc-play-audio')
                    .html('<i class="fas fa-play"></i>');
            });
        }

        $('.tvaibwc-play-audio').off('click').on('click', function() {
            const $audioContainer = $(this).closest('.tvaibwc-media-container');
            const audioElement = $audioContainer.find('.tvaibwc-audio-element')[0];
            const $progressBar = $audioContainer.find('.tvaibwc-progress-bar');
            const $durationDisplay = $audioContainer.find('.tvaibwc-audio-duration');
            const $playButton = $(this);
            
            // Load metadata if not loaded
            if (isNaN(audioElement.duration)) {
                audioElement.load();
                audioElement.addEventListener('loadedmetadata', function() {
                    $durationDisplay.text(tvaibwc_formatTime(audioElement.duration));
                });
            }

            if (audioElement.paused) {
                stopAllAudioPlayers();
                audioElement.play()
                    .then(() => {
                        $playButton.html('<i class="fas fa-pause"></i>');
                        
                        // Update progress as audio plays
                        audioElement.addEventListener('timeupdate', function updateProgress() {
                            const progress = (audioElement.currentTime / audioElement.duration) * 100;
                            $progressBar.css('width', progress + '%');
                            $durationDisplay.text(tvaibwc_formatTime(audioElement.currentTime));
                        });
                    })
                    .catch(error => {
                        console.error("Audio playback failed:", error);
                        alert("Audio playback failed. Please try again.");
                    });
            } else {
                audioElement.pause();
                $playButton.html('<i class="fas fa-play"></i>');
            }
        });

        // Initialize duration displays
        $('.tvaibwc-audio-element').each(function() {
            const audioElement = this;
            const $durationDisplay = $(this).siblings('.tvaibwc-audio-controls')
                                        .find('.tvaibwc-audio-duration');
            
            // Load metadata to get duration
            audioElement.addEventListener('loadedmetadata', function() {
                $durationDisplay.text(tvaibwc_formatTime(audioElement.duration));
            });
            
            // Handle when audio ends
            audioElement.addEventListener('ended', function() {
                $(this).siblings('.tvaibwc-audio-controls')
                    .find('.tvaibwc-play-audio')
                    .html('<i class="fas fa-play"></i>');
            });
        });
    }

    // Add bot response
    function tvaibwc_addBotResponse(type, isDark) {
        let responseContent = '';
        const responses = {
            image: 'Thanks for the image! I can see it clearly.',
            video: 'Nice video! I\'ve received it successfully.',
            file: 'File received. I\'ll review it shortly.',
            audio: 'Audio message received. I\'ll listen to it shortly.',
        };
        
        responseContent = `
            <div class="tvaibwc-message tvaibwc-bot ${isDark ? 'dark' : ''}">
                <div class="tvaibwc-message-text">
                    ${responses[type] || 'Thanks for the file!'}
                </div>
                <div class="tvaibwc-message-time">${tvaibwc_getCurrentTime()}</div>
            </div>
        `;
        
        $('#tvaibwc-chatMessages').append(responseContent);
        tvaibwc_scrollToBottom();
    }
    
    // Initialize the attachment modal
    tvaibwc_initAttachmentModal();


    // For start chat Visitoe + Existing customer

    // Store original home content
    const originalHomeContent = $('.tvaibwc-home-content').html();
    
    // Handle visitor button click — quick path: jump into chat with
    // an anonymous profile. Eagerly mint a session so the flow (if
    // bound) auto-bootstraps and the visitor sees the menu immediately.
    $('.tvaibwc-visitor-btn').click(function() {
        // Switch to chat tab
        $('.tvaibwc-widget-tab[data-tab="tvaibwc-chat"]').click();

        // UI: hide Start Chat + tabs, reveal input, keep state in sync
        $('#tvaibwc-startChatButton').hide().removeClass('is-visible');
        $('#tvaibwc-chatInputContainer').addClass('active').removeClass('is-ended');
        $('#tvaibwc-widgetTabs').hide();
        $('#tvaibwc-chatWidget').addClass('no-tabs');
        $('#tvaibwc-backToTabs').show();
        $('#tvaibwc-chatInput').focus();

        // Eagerly mint a session so flow auto-bootstrap fires (visitor
        // sees the menu without having to type first). Idempotent.
        if (typeof window.tvaibwcEnsureSession === 'function') {
            window.tvaibwcEnsureSession()
                .catch(function (e) { console.warn('[tvaibwc] visitor-mode session start failed', e); });
        }
    });
    
    // Handle customer button click
    $('.tvaibwc-customer-btn').click(function() {
        // Hide home content and show form
        $('.tvaibwc-user-type-buttons').hide();
        $('.tvaibwc-home-content h4').text('Welcome Back!');
        $('.tvaibwc-content-text').text('Please provide your details');
        $('.tvaibwc-customer-form').fadeIn(300);
        $(".tvaibwc-home-content img").css("width", "50px");
        $(".tvaibwc-home-content img").css("height", "50px");
    });
    
    // Handle back button
    $(document).on('click', '.tvaibwc-form-back-button', function() {
        $('.tvaibwc-home-content').html(originalHomeContent);
        $(".tvaibwc-home-content img").css("width", "100px");
        $(".tvaibwc-home-content img").css("height", "100px");
    });
    
    // Handle form submission
 /*    $(document).on('click', '.tvaibwc-form-submit', function() {
        const phone = $('#tvaibwc-phone').val();
        const email = $('#tvaibwc-email').val();
        
        if (!phone || !email) {
            alert('Please fill in both fields');
            return;
        }
        
        // Switch to chat tab
        $('.tvaibwc-widget-tab[data-tab="tvaibwc-chat"]').click();
        
        // Hide form and show chat
        $('.tvaibwc-customer-form').hide();
        $('#tvaibwc-startChatButton').hide();
        $('#tvaibwc-chatInputContainer').addClass('active');
        $('#tvaibwc-widgetTabs').hide();
        $('#tvaibwc-backToTabs').show();
        $('#tvaibwc-chatInput').focus();
        
        // Add verification message
        const verifiedHTML = `
            <div class="tvaibwc-message tvaibwc-bot dark">
                <div class="tvaibwc-message-text">
                    <i class="fas fa-check-circle" style="color:#4CAF50;margin-right:8px;"></i>
                    Thank you! You're now connected as a verified customer.
                </div>
                <div class="tvaibwc-message-time">${tvaibwc_getCurrentTime()}</div>
            </div>
        `;
        $('#tvaibwc-chatMessages').append(verifiedHTML);
        tvaibwc_scrollToBottom();
    }); */
    
    // Floating label effect
    $(document).on('focus', '.tvaibwc-form-input', function() {
        $(this).siblings('label').addClass('tvaibwc-label-active');
    });
    
    $(document).on('blur', '.tvaibwc-form-input', function() {
        if (!$(this).val()) {
            $(this).siblings('label').removeClass('tvaibwc-label-active');
        }
    });
    
    // Initialize labels based on existing values
    $('.tvaibwc-form-input').each(function() {
        if ($(this).val()) {
            $(this).siblings('label').addClass('tvaibwc-label-active');
        }
    });

    function typeWriter(target, text, speed = 50, callback) {
        let i = 0;
        target.html('');
        if(text!="" && text!=null){
            let interval = setInterval(() => {
                target.append(text.charAt(i));
                i++;
                if (i >= text.length) {
                    clearInterval(interval);
                    if (callback) callback();
                }
            }, speed);
        }
    }

    function formatResponseData(data) {
        if (!Array.isArray(data) || data.length === 0) {
            return '<p class="text-muted fst-italic">No data available</p>';
        }

        let html = `
            <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
                <thead class="table-light">
                <tr>
        `;

        // Dynamically generate headers
        Object.keys(data[0]).forEach(key => {
            html += `<th>${key.replace(/_/g, ' ').toUpperCase()}</th>`;
        });

        html += `
                </tr>
                </thead>
                <tbody>
        `;

        // Table rows
        data.forEach(row => {
            html += '<tr>';
            Object.values(row).forEach(value => {
            html += `<td>${value}</td>`;
            });
            html += '</tr>';
        });

        html += `
                </tbody>
            </table>
            </div>
        `;

        return html;
    }

    function cleanResponseText(rawText) {
        if (typeof rawText !== "string") {
            return "";
        }
        // Remove surrounding quotes if they exist
        let cleaned = rawText.replace(/^["']|["']$/g, "");

        // Trim leading and trailing whitespace
        cleaned = $.trim(cleaned);
        // Normalize line breaks to <br>
        cleaned = cleaned.replace(/\r\n|\r|\n/g, "<br>");

        cleaned = cleaned.replace(/\s\s+/g, " ");

        return cleaned;
    }