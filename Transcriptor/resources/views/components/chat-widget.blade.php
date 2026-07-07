@php
$chatId = 'chat-widget-' . md5(uniqid());
@endphp

<div class="fixed bottom-6 right-6 z-50">
    <!-- Float Button -->
    <button id="{{ $chatId }}-button" onclick="toggleChat('{{ $chatId }}')" class="w-[60px] h-[60px] rounded-full bg-gradient-to-br from-blue-500 to-blue-700 shadow-lg shadow-blue-500/40 border-0 cursor-pointer flex items-center justify-center transition-all duration-300 hover:scale-105 hover:shadow-blue-500/50 active:scale-95">
        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8V4H8"/>
            <rect width="16" height="12" x="4" y="8" rx="2"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 14h2"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 14h2"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13v2"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13v2"/>
        </svg>
    </button>

    <!-- Chat Box -->
    <div id="{{ $chatId }}" class="absolute bottom-20 right-0 w-[350px] h-[500px] max-h-[calc(100vh-120px)] bg-white rounded-2xl shadow-2xl hidden flex-col overflow-hidden chat-box">
        <!-- Chat Header -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-700 text-white p-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8V4H8"/>
                        <rect width="16" height="12" x="4" y="8" rx="2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 14h2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 14h2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13v2"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13v2"/>
                    </svg>
                </div>
                <div>
                    <div class="text-base font-semibold">AgSOAR AI</div>
                    <div class="text-xs opacity-90">Online</div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="resetChat('{{ $chatId }}')" class="bg-white/20 border-0 w-8 h-8 rounded-full cursor-pointer flex items-center justify-center transition-colors hover:bg-white/30">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </button>
                <button onclick="toggleChat('{{ $chatId }}')" class="bg-white/20 border-0 w-8 h-8 rounded-full cursor-pointer flex items-center justify-center transition-colors hover:bg-white/30">
                    <svg class="w-[18px] h-[18px] text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div id="{{ $chatId }}-messages" class="flex-1 p-4 overflow-y-auto bg-gray-100 flex flex-col gap-3">
            <!-- Welcome message -->
            <div class="bg-white max-w-[80%] p-[10px_14px] rounded-2xl rounded-bl-sm shadow-sm self-start">
                I am the AgSOAR AI Assistant. How can I assist you with the system today?
                <span class="text-[11px] opacity-70 mt-1 block">Now</span>
            </div>
        </div>

        <!-- Chat Input -->
        <div class="bg-white p-3 border-t border-gray-200 flex gap-2 items-center">
            <input type="text" id="{{ $chatId }}-input" class="flex-1 px-[14px] py-2.5 border border-gray-200 rounded-2xl text-sm outline-none transition-colors focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed" placeholder="Type your message..." onkeypress="handleChatKeypress(event, '{{ $chatId }}')" />
            <button id="{{ $chatId }}-send" onclick="sendMessage('{{ $chatId }}')" class="w-10 h-10 bg-blue-500 border-0 rounded-full cursor-pointer flex items-center justify-center transition-colors hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed disabled:hover:bg-gray-400">
                <svg class="w-[18px] h-[18px] text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
            </button>
        </div>
    </div>
</div>

<script>
    function toggleChat(chatId) {
        const chatBox = document.getElementById(chatId);
        const floatButton = document.getElementById(chatId + '-button');
        const messagesContainer = document.getElementById(chatId + '-messages');

        if (!chatBox) return;

        if (chatBox.classList.contains('hidden')) {
            // Open chat
            chatBox.classList.remove('hidden');
            chatBox.classList.add('flex');
            if (floatButton) {
                floatButton.style.transform = 'scale(0)';
                floatButton.style.opacity = '0';
            }

            setTimeout(() => {
                const input = document.getElementById(chatId + '-input');
                if (input) input.focus();
            }, 100);
        } else {
            // Close chat
            chatBox.classList.add('hidden');
            chatBox.classList.remove('flex');
            if (floatButton) {
                floatButton.style.transform = 'scale(1)';
                floatButton.style.opacity = '1';
            }
        }
    }

    function handleChatKeypress(event, chatId) {
        if (event.key === 'Enter') {
            sendMessage(chatId);
        }
    }

    function setChatLoading(chatId, isLoading) {
        const input = $('#' + chatId + '-input');
        const sendButton = $('#' + chatId + '-send');

        if (isLoading) {
            input.prop('disabled', true);
            input.prop('placeholder', 'AI is typing...');
            sendButton.prop('disabled', true);
        } else {
            input.prop('disabled', false);
            input.prop('placeholder', 'Type your message...');
            sendButton.prop('disabled', false);
            input.focus();
        }
    }

    async function sendMessage(chatId) {
        const input = $('#' + chatId + '-input');
        const messagesContainer = $('#' + chatId + '-messages');
        const message = input.val().trim();

        if (!message) return;

        // Disable input while loading
        setChatLoading(chatId, true);

        // Add user message
        addMessage(messagesContainer, message, 'user');
        input.val('');

        // Scroll to bottom
        messagesContainer.scrollTop(messagesContainer[0].scrollHeight);

        // Show typing indicator
        showTypingIndicator(messagesContainer, chatId);

        $.ajax({
            url: '/api/chatbot/send',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            data: {
                message: message
            },
            success: function(data) {
                // Remove typing indicator
                $('#typing-' + chatId).remove();

                if (data.success) {
                    if (data.messages) {
                        // Handle array of messages (tool calls) with delays
                        data.messages.forEach(function(msg, index) {
                            setTimeout(function() {
                                addMessage(messagesContainer, msg.message, msg.type, msg.timestamp);
                                // Re-enable input after last message
                                if (index === data.messages.length - 1) {
                                    setChatLoading(chatId, false);
                                }
                            }, index * 1000); // 1 second delay between each message
                        });
                    } else if (data.message) {
                        // Handle single message (normal response)
                        addMessage(messagesContainer, data.message, 'bot', data.timestamp);
                        // Re-enable input after response
                        setChatLoading(chatId, false);
                    }
                } else {
                    addMessage(messagesContainer, 'Sorry, I encountered an error: ' + (data.error || 'Unknown error'), 'bot');
                    // Re-enable input after error
                    setChatLoading(chatId, false);
                }
            },
            error: function(xhr, status, error) {
                console.error('Chat error:', error);

                // Remove typing indicator
                $('#typing-' + chatId).remove();

                addMessage(messagesContainer, 'Sorry, I\'m having trouble connecting right now. Please try again later.', 'bot');

                // Re-enable input after error
                setChatLoading(chatId, false);
            }
        });
    }

    function addMessage(container, text, type, timestamp = null) {
        const time = timestamp || new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        let messageClass = '';
        let extraClass = '';

        if (type === 'user') {
            messageClass = 'bg-blue-500 text-white self-end rounded-br-sm';
        } else if (type === 'system') {
            messageClass = 'bg-gray-100 text-gray-600 self-start rounded-lg text-xs border border-dashed border-gray-300 max-w-[60%]';
        } else {
            messageClass = 'bg-white self-start rounded-bl-sm shadow-sm';
        }

        const messageHtml = `
            <div class="max-w-[80%] p-[10px_14px] rounded-2xl text-sm leading-[1.4] break-words ${messageClass}">
                ${text}
                <span class="text-[11px] opacity-70 mt-1 block">${time}</span>
            </div>
        `;

        container.append(messageHtml);
        container.scrollTop(container[0].scrollHeight);
    }

    function showTypingIndicator(container, chatId) {
        const typingHtml = `
            <div id="typing-${chatId}" class="flex gap-1 p-3">
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></span>
                <span class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></span>
            </div>
        `;
        container.append(typingHtml);
        container.scrollTop(container[0].scrollHeight);
    }

    async function resetChat(chatId) {
        const messagesContainer = $('#' + chatId + '-messages');

        if (!confirm('Are you sure you want to reset the chat? This will clear all messages.')) {
            return;
        }

        $.ajax({
            url: '/api/chatbot/clear',
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(data) {
                if (data.success) {
                    // Clear messages from UI
                    messagesContainer.empty();

                    // Add welcome message
                    addMessage(messagesContainer, 'Chat has been reset. How can I help you today?', 'bot');
                } else {
                    addMessage(messagesContainer, 'Failed to reset chat: ' + (data.error || 'Unknown error'), 'bot');
                }
            },
            error: function(xhr, status, error) {
                console.error('Reset chat error:', error);
                addMessage(messagesContainer, 'Failed to reset chat. Please try again.', 'bot');
            }
        });
    }

    // Load chat history when widget initializes
    $(document).ready(function() {
        loadChatHistoryFromServer('{{ $chatId }}');
    });

    function loadChatHistoryFromServer(chatId) {
        // Add a test element to verify function is called
        const messagesContainer = $('#' + chatId + '-messages');

        if (messagesContainer.length === 0) {
            return;
        }

        $.ajax({
            url: '/api/chatbot/history',
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(data) {
                // Add test message to show function was called
                const testMsg = $('<div class="text-xs text-gray-400 text-center py-2">History loaded from server</div>');
                messagesContainer.append(testMsg);

                if (data.success && data.history && Array.isArray(data.history) && data.history.length > 0) {
                    // Remove test message if we have real data
                    testMsg.remove();

                    // Clear existing messages and welcome message
                    messagesContainer.empty();

                    // Load messages from server history
                    data.history.forEach(function(msg) {
                        // Skip hidden messages and messages without required fields
                        if (msg.hidden !== true && msg.content && msg.role) {
                            const type = msg.role === 'user' ? 'user' : 'bot';
                            let timestamp = null;

                            if (msg.timestamp) {
                                try {
                                    timestamp = new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                                } catch (e) {
                                    timestamp = null;
                                }
                            }

                            addMessage(messagesContainer, msg.content, type, timestamp);
                        }
                    });
                }
            },
            error: function(xhr, status, error) {
                // Add test message to show error occurred
                const errorMsg = $('<div class="text-xs text-red-400 text-center py-2">Failed to load history</div>');
                messagesContainer.append(errorMsg);
            }
        });
    }

    function saveChatToSession(messagesContainer) {
        // Chat history is now saved server-side, no need for client-side storage
        // This function is kept for compatibility but does nothing
    }

    function loadChatHistory(messagesContainer) {
        // Chat history is now loaded from server on page load
        // This function is kept for compatibility but does nothing
    }

    // Close chat when clicking outside
    document.addEventListener('click', function(event) {
        const widget = document.getElementById('{{ $chatId }}');
        const floatButton = document.getElementById('{{ $chatId }}-button');

        if (widget && !widget.classList.contains('hidden') && !widget.contains(event.target) && !floatButton.contains(event.target)) {
            toggleChat('{{ $chatId }}');
        }
    });
</script>
