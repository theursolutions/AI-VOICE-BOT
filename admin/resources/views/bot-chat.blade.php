<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Ai ChatBot </title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6e48aa;
            --sidebar-width: 260px;
            --sidebar-bg: #28334e;
            --chat-bg: #1b253b;
            --user-bubble: #e3f2fd;
            --ai-bubble: #ffffff;
            --border-color: #e5e5e6;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            height: 100vh;
            background-color: var(--chat-bg);
        }
        
        .app-container {
            display: flex;
            height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--sidebar-bg);
            color: white;
            height: 100vh;
            display: flex;
            flex-direction: column;
            border-right: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .new-chat-btn {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border-radius: 6px;
            background-color: rgba(255,255,255,0.1);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .new-chat-btn:hover {
            background-color: rgba(255,255,255,0.2);
        }
        
        .chat-history {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }
        
        .chat-item {
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .chat-item:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .chat-item.active {
            background-color: rgba(255,255,255,0.2);
        }
        
        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .user-profile:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #555;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Main Chat Area */
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
        }
        
        .chat-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--sidebar-bg);
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .chat-title {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background-color: var(--chat-bg);
        }
        
        .message {
            margin-bottom: 24px;
            display: flex;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .user-message {
            justify-content: flex-end;
        }
        
        .ai-message {
            justify-content: flex-start;
        }
        
        .message-content {
            max-width: calc(100% - 80px);
        }
        
        .message-bubble {
            padding: 16px 20px;
            border-radius: 8px;
            line-height: 1.6;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            position: relative;
        }
        
        .user-bubble {
            background-color: var(--user-bubble);
            border-bottom-right-radius: 2px;
        }
        
        .ai-bubble {
            background-color: var(--ai-bubble);
            border-bottom-left-radius: 2px;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 16px;
            background-color: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .ai-avatar {
            background-color: var(--primary-color);
            color: white;
        }
        
        .user-avatar-small {
            background-color: #6c757d;
            color: white;
        }
        
        .message-actions {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            justify-content: flex-end;
        }
        
        .action-btn {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .action-btn:hover {
            background-color: rgba(0,0,0,0.05);
        }
        
        /* Input Area */
        .input-container {
            padding: 16px 24px;
            background-color: var(--sidebar-bg);;
            border-top: 1px solid var(--border-color);
        }
        
        .input-box {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
        }
        
        .message-input {
            width: 100%;
            border-radius: 8px;
            padding: 12px 50px 12px 16px;
            border: 1px solid var(--border-color);
            resize: none;
            overflow-y: hidden;
            min-height: 56px;
            max-height: 200px;
            background-color: var(--chat-bg);
        }
        
        .send-button {
            position: absolute;
            right: 12px;
            bottom: 12px;
            background: var(--primary-color);
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .send-button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            padding: 12px 16px;
            background-color: var(--ai-bubble);
            border-radius: 8px;
            margin-bottom: 24px;
            width: fit-content;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            gap: 6px;
        }
        
        .typing-dot {
            width: 8px;
            height: 8px;
            background-color: #666;
            border-radius: 50%;
            animation: typingAnimation 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) {
            animation-delay: 0s;
        }
        
        .typing-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typingAnimation {
            0%, 60%, 100% {
                transform: translateY(0);
            }
            30% {
                transform: translateY(-5px);
            }
        }
        
        /* Model Selector */
        .model-selector {
            background-color: var(--sidebar-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 8px 24px;
            display: flex;
            color: white;
            align-items: center;
            justify-content: center;
        }
        
        .model-badge {
            background-color: #f0f0f0;
            border-radius: 12px;
            padding: 4px 10px;
            font-size: 0.8rem;
            margin-left: 8px;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                z-index: 1000;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .sidebar-toggle {
                display: block !important;
            }
        }
        
        .sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: #333;
            font-size: 1.2rem;
            cursor: pointer;
        }
        
        .markdown-content pre {
            background-color: #f6f8fa;
            padding: 16px;
            border-radius: 6px;
            overflow-x: auto;
        }
        
        .markdown-content code {
            font-family: 'Courier New', Courier, monospace;
            background-color: rgba(175,184,193,0.2);
            padding: 0.2em 0.4em;
            border-radius: 3px;
            font-size: 85%;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <button class="new-chat-btn">
                    <i class="fas fa-plus"></i>
                    New Chat
                </button>
            </div>
            
            <div class="chat-history">
                <div class="chat-item active">
                    <i class="far fa-comment"></i>
                    <span>Ai ChatBot</span>
                </div>
                <div class="chat-item">
                    <i class="far fa-comment"></i>
                    <span>React Component Help</span>
                </div>
                <div class="chat-item">
                    <i class="far fa-comment"></i>
                    <span>Python Script Review</span>
                </div>
                <div class="chat-item">
                    <i class="far fa-comment"></i>
                    <span>System Design Tips</span>
                </div>
                <div class="chat-item">
                    <i class="far fa-comment"></i>
                    <span>Job Interview Prep</span>
                </div>
            </div>
            
            <div class="sidebar-footer">
                <div class="user-profile">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <span>User Name</span>
                </div>
            </div>
        </div>
        
        <!-- Main Chat Area -->
        <div class="chat-container">
            <div class="chat-header">
                <button class="sidebar-toggle" id="sidebarToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="chat-title">Ai ChatBot Response</div>
                <div></div> <!-- Spacer -->
            </div>
            
            <div class="model-selector">
                <span>Project:</span>
                <span class="model-badge">Project-23</span>
            </div>
            
            <div class="chat-messages" id="chatMessages">
                <!-- AI Welcome Message -->
                <div class="message ai-message">
                    <div class="message-avatar ai-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-bubble ai-bubble markdown-content">
                            Hello! I'm Ai Bot , your AI assistant. How can I help you today?
                        </div>
                        <div class="message-actions">
                            <button class="action-btn"><i class="far fa-copy"></i> Copy</button>
                            <button class="action-btn"><i class="far fa-thumbs-up"></i></button>
                            <button class="action-btn"><i class="far fa-thumbs-down"></i></button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Input Area -->
            <div class="input-container">
                <div class="input-box">
                    <form id="chatForm">
                        <textarea class="form-control message-input" id="messageInput" rows="1" placeholder="Write your query..." autofocus></textarea>
                        <button type="submit" class="send-button" id="sendButton" disabled>
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        var project_id = "{{$id}}";
        function sendAjaxRequest(url, data, onSuccess, onError) {
            $.ajax({
                url: url,
                type: 'POST',
                data: data,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') // Add CSRF token header
                },
                success: function(response) {
                    if (onSuccess && typeof onSuccess === 'function') {
                        onSuccess(response);
                    } else {
                        console.log('Success:', response);
                    }
                },
                error: function(xhr, status, error) {
                    if (onError && typeof onError === 'function') {
                        onError(xhr, status, error);
                    } else {
                        console.error('Error:', error);
                    }
                }
            });
        }

        // Auto-resize textarea as user types
        const textarea = document.getElementById('messageInput');
        const sendButton = document.getElementById('sendButton');
        
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            
            // Enable/disable send button based on input
            sendButton.disabled = this.value.trim() === '';
        });
        
        // Handle form submission
        document.getElementById('chatForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const message = textarea.value.trim();
            if (message) {
                addUserMessage(message);
                textarea.value = '';
                textarea.style.height = 'auto';
                sendButton.disabled = true;
                simulateAIResponse(message);
            }
        });

        $('#messageInput').on('keypress', function(event) {
            if (event.which === 13) { 
                event.preventDefault(); 
                const message = textarea.value.trim();
                if (message) {
                    addUserMessage(message);
                    textarea.value = '';
                    textarea.style.height = 'auto';
                    sendButton.disabled = true;
                    simulateAIResponse(message);
                }
            }
        });
        
        // Toggle sidebar on mobile
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            
            if (window.innerWidth <= 768 && 
                !sidebar.contains(e.target) && 
                e.target !== toggleBtn && 
                !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
        
        function addUserMessage(text) {
            const messagesContainer = document.getElementById('chatMessages');
            const messageHtml = `
                <div class="message user-message">
                    <div class="message-content">
                        <div class="message-bubble user-bubble">${text}</div>
                        <div class="message-actions">
                            <button class="action-btn"><i class="far fa-copy"></i> Copy</button>
                            <button class="action-btn"><i class="fas fa-ellipsis-h"></i></button>
                        </div>
                    </div>
                    <div class="message-avatar user-avatar-small ms-3 mt-2">
                        <i class="fas fa-user"></i>
                    </div>
                </div>
            `;
            messagesContainer.insertAdjacentHTML('beforeend', messageHtml);
            scrollToBottom();
        }
        
        function addAIMessage(text) {
            const messagesContainer = document.getElementById('chatMessages');
            const messageHtml = `
                <div class="message ai-message">
                    <div class="message-avatar ai-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content">
                        <div class="message-bubble ai-bubble markdown-content">${text}</div>
                        <div class="message-actions">
                            <button class="action-btn"><i class="far fa-copy"></i> Copy</button>
                            <button class="action-btn"><i class="far fa-thumbs-up"></i></button>
                            <button class="action-btn"><i class="far fa-thumbs-down"></i></button>
                        </div>
                    </div>
                </div>
            `;
            messagesContainer.insertAdjacentHTML('beforeend', messageHtml);
            scrollToBottom();
        }
        
        function showTypingIndicator() {
            const messagesContainer = document.getElementById('chatMessages');
            const typingHtml = `
                <div class="message ai-message">
                    <div class="message-avatar ai-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="typing-indicator">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                </div>
            `;
            messagesContainer.insertAdjacentHTML('beforeend', typingHtml);
            scrollToBottom();
            return messagesContainer.lastElementChild;
        }
        
        function removeTypingIndicator(typingElement) {
            if (typingElement) {
                typingElement.remove();
            }
        }
        
        function scrollToBottom() {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        function simulateAIResponse(query) {
            var typingElement = showTypingIndicator();
            // Simulate AI thinking time
            let requestData = {
                query: query,
                project_id:project_id
            };
            let ajax_url = "{{url('/chat-query-ajax')}}";
            sendAjaxRequest(ajax_url, requestData,
                function(response) {
                    removeTypingIndicator(typingElement);
                    console.log(response);
                    if(response.code == 200){
                        //var resp_data = JSON.stringify(response.data, null, 2);
                        if(response.intent_type == "data"){
                            var res_html = formatResponseData(response.data);
                            addAIMessage(res_html);
                        }
                        else if(response.intent_type == "conversation"){
                            var conv_resp = cleanResponseText(response.data);
                            addAIMessage(conv_resp);
                        }
                        else{
                            var message = `Something went wrong. All servers are busy right now.`;
                            addAIMessage(message);
                        }
                    }
                    else{
                        addAIMessage(response.message);
                    }
                },
                function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    removeTypingIndicator(typingElement);
                    var message = `Something went wrong. All servers are busy right now.`;
                    addAIMessage(message);
                }
            );
        }
        
        // Add click handlers for action buttons
        document.addEventListener('click', function(e) {
            // Copy button functionality
            if (e.target.classList.contains('fa-copy') || 
                (e.target.parentElement && e.target.parentElement.classList.contains('fa-copy'))) {
                const messageBubble = e.target.closest('.message-actions').previousElementSibling;
                const textToCopy = messageBubble.textContent;
                navigator.clipboard.writeText(textToCopy.trim());
                
                // Show feedback
                const originalIcon = e.target.classList.contains('fa-copy') ? 
                    e.target : e.target.firstElementChild;
                const originalClass = originalIcon.className;
                originalIcon.className = 'fas fa-check';
                setTimeout(() => {
                    originalIcon.className = originalClass;
                }, 2000);
            }
            
            // Chat item click handler
            if (e.target.closest('.chat-item')) {
                const chatItems = document.querySelectorAll('.chat-item');
                chatItems.forEach(item => item.classList.remove('active'));
                e.target.closest('.chat-item').classList.add('active');
                
                // In a real app, you would load the chat history here
            }
            
            // New chat button handler
            if (e.target.closest('.new-chat-btn')) {
                document.querySelectorAll('.chat-item').forEach(item => item.classList.remove('active'));
                
                // In a real app, you would create a new chat session here
                alert('New chat created!');
            }
        });

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


    </script>
</body>
</html>