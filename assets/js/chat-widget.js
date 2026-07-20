/**
 * NewUI v4.0 - Chat Widget
 *
 * Floating widget for local chat messaging in the comms panel.
 * Loads messages from api/chat.php, sends via POST, listens for
 * real-time 'chat:message' events through the EventBus SSE layer.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────
    var widget = null;          // Root DOM element
    var feedEl = null;          // Message feed container
    var inputEl = null;         // Text input
    var sendBtn = null;         // Send button
    var channelSelect = null;   // Channel dropdown
    var signalBtn = null;       // Signal dropdown toggle
    var signalMenu = null;      // Signal dropdown menu
    var visible = false;
    var currentChannel = 'general';
    var lastMessageId = 0;
    var signals = [];           // Cached signal/code list
    var signalsLoaded = false;
    var unreadCount = 0;
    var autoScrollEnabled = true;
    var loading = false;

    // Drag state
    var dragState = { active: false, startX: 0, startY: 0, origLeft: 0, origTop: 0 };

    // Resize state
    var resizeState = { active: false, startX: 0, startY: 0, origW: 0, origH: 0 };

    // ── Helpers ──────────────────────────────────────────────────

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatTime(dateStr) {
        try {
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return '';
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return '';
        }
    }

    function savePosition() {
        try {
            var rect = widget.getBoundingClientRect();
            localStorage.setItem('chatWidgetPos', JSON.stringify({
                left: Math.round(rect.left),
                top: Math.round(rect.top),
                width: Math.round(rect.width),
                height: Math.round(rect.height)
            }));
        } catch (e) { /* ignore */ }
    }

    function loadPosition() {
        try {
            var raw = localStorage.getItem('chatWidgetPos');
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    // ── Init ─────────────────────────────────────────────────────

    function init() {
        var tpl = document.getElementById('tpl-chat-widget');
        if (!tpl) return;

        // Clone template
        var clone = tpl.content ? tpl.content.cloneNode(true) : tpl.cloneNode(true);
        widget = clone.querySelector('.chat-widget');
        if (!widget) return;

        document.body.appendChild(widget);

        // Cache elements
        feedEl        = widget.querySelector('.chat-feed');
        inputEl       = widget.querySelector('#chatTextInput');
        sendBtn       = widget.querySelector('#chatSendBtn');
        channelSelect = widget.querySelector('#chatChannelSelect');
        signalBtn     = widget.querySelector('#chatSignalBtn');
        signalMenu    = widget.querySelector('#chatSignalMenu');

        // Restore position or default to bottom-right
        var saved = loadPosition();
        if (saved) {
            widget.style.left = saved.left + 'px';
            widget.style.top  = saved.top + 'px';
            if (saved.width)  widget.style.width  = saved.width + 'px';
            if (saved.height) widget.style.height = saved.height + 'px';
        } else {
            widget.style.right  = '20px';
            widget.style.bottom = '80px';
        }

        // Start hidden
        widget.classList.add('chat-hidden');

        attachListeners();

        // Listen for toggle event from comms button
        if (typeof EventBus !== 'undefined') {
            EventBus.on('chat:toggle', function () {
                toggle();
            });

            // Real-time messages via SSE
            EventBus.on('chat:message', function (data) {
                handleIncomingMessage(data);
            });
        }

        // Auto-open when a chat notification linked here (issue #40).
        // The notification tray routes chat notifications to
        // index.php?openchat=1; if the user was already on a
        // non-index page the tray click issues a full navigation
        // and lands here.
        try {
            if (window.location.search.indexOf('openchat=1') !== -1) {
                setTimeout(function () { show(); }, 100);
                // Strip the marker so a subsequent refresh doesn't re-open.
                if (window.history && window.history.replaceState) {
                    var clean = window.location.pathname +
                        window.location.search
                          .replace(/[?&]openchat=1/, '')
                          .replace(/^\?$/, '') +
                        window.location.hash;
                    window.history.replaceState(null, '', clean);
                }
            }
        } catch (e) { /* non-fatal */ }
    }

    // ── Event Listeners ──────────────────────────────────────────

    function attachListeners() {
        // Send on button click
        sendBtn.addEventListener('click', function () {
            sendMessage();
        });

        // Send on Enter key (Shift+Enter for newline not needed — single line input)
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Channel change
        channelSelect.addEventListener('change', function () {
            currentChannel = channelSelect.value;
            lastMessageId = 0;
            // 2026-06-28 — clear unread badge for the channel we just opened.
            channelUnread[currentChannel] = 0;
            updateChannelSelectorBadges();
            clearFeed();
            loadMessages();
        });

        // Signal dropdown toggle
        signalBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!signalsLoaded) {
                loadSignals();
            }
            signalMenu.classList.toggle('show');
        });

        // Close signal dropdown on outside click
        document.addEventListener('click', function (e) {
            if (signalMenu && !signalMenu.contains(e.target) && e.target !== signalBtn) {
                signalMenu.classList.remove('show');
            }
        });

        // Signal item clicks (delegation)
        signalMenu.addEventListener('click', function (e) {
            var item = e.target.closest('.dropdown-item');
            if (!item) return;
            var signalId = item.getAttribute('data-signal-id');
            var signalText = item.getAttribute('data-signal-text');
            if (signalId) {
                sendSignal(signalId, signalText);
                signalMenu.classList.remove('show');
            }
        });

        // Minimize button
        var minBtn = widget.querySelector('#chatMinimize');
        if (minBtn) {
            minBtn.addEventListener('click', function () {
                toggle();
            });
        }

        // Close button
        var closeBtn = widget.querySelector('#chatClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                hide();
            });
        }

        // Auto-scroll detection: if user scrolls up, disable auto-scroll
        feedEl.addEventListener('scroll', function () {
            var atBottom = feedEl.scrollHeight - feedEl.scrollTop - feedEl.clientHeight < 30;
            autoScrollEnabled = atBottom;
        });

        // Drag support on header
        var header = widget.querySelector('.chat-header');
        if (header) {
            header.addEventListener('mousedown', function (e) {
                if (e.target.closest('button') || e.target.closest('select')) return;
                dragState.active = true;
                dragState.startX = e.clientX;
                dragState.startY = e.clientY;
                var rect = widget.getBoundingClientRect();
                dragState.origLeft = rect.left;
                dragState.origTop  = rect.top;
                // Remove right/bottom positioning so left/top takes over
                widget.style.right  = 'auto';
                widget.style.bottom = 'auto';
                e.preventDefault();
            });
        }

        document.addEventListener('mousemove', function (e) {
            if (dragState.active) {
                var dx = e.clientX - dragState.startX;
                var dy = e.clientY - dragState.startY;
                widget.style.left = (dragState.origLeft + dx) + 'px';
                widget.style.top  = (dragState.origTop + dy) + 'px';
            }
            if (resizeState.active) {
                var dw = e.clientX - resizeState.startX;
                var dh = e.clientY - resizeState.startY;
                widget.style.width  = Math.max(300, resizeState.origW + dw) + 'px';
                widget.style.height = Math.max(280, resizeState.origH + dh) + 'px';
            }
        });

        document.addEventListener('mouseup', function () {
            if (dragState.active || resizeState.active) {
                dragState.active = false;
                resizeState.active = false;
                savePosition();
            }
        });

        // Resize handle
        var resizeHandle = widget.querySelector('.chat-resize-handle');
        if (resizeHandle) {
            resizeHandle.addEventListener('mousedown', function (e) {
                resizeState.active = true;
                resizeState.startX = e.clientX;
                resizeState.startY = e.clientY;
                var rect = widget.getBoundingClientRect();
                resizeState.origW = rect.width;
                resizeState.origH = rect.height;
                // Ensure left/top are set
                widget.style.left   = rect.left + 'px';
                widget.style.top    = rect.top + 'px';
                widget.style.right  = 'auto';
                widget.style.bottom = 'auto';
                e.preventDefault();
                e.stopPropagation();
            });
        }
    }

    // ── Visibility ───────────────────────────────────────────────

    function toggle() {
        if (visible) {
            hide();
        } else {
            show();
        }
    }

    function show() {
        widget.classList.remove('chat-hidden');
        visible = true;
        unreadCount = 0;
        updateUnreadBadge();
        loadMessages();
        inputEl.focus();
    }

    function hide() {
        widget.classList.add('chat-hidden');
        visible = false;
    }

    // ── Message Loading ──────────────────────────────────────────

    function loadMessages() {
        if (loading) return;
        loading = true;

        var url = 'api/chat.php?channel=' + encodeURIComponent(currentChannel);
        if (lastMessageId > 0) {
            url += '&after_id=' + lastMessageId;
        } else {
            url += '&limit=50';
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = 'login.php';
                    throw new Error('Unauthorized');
                }
                return res.json();
            })
            .then(function (data) {
                loading = false;
                if (data.messages && data.messages.length > 0) {
                    renderMessages(data.messages);
                    var last = data.messages[data.messages.length - 1];
                    if (last && last.id) {
                        lastMessageId = parseInt(last.id, 10);
                    }
                }
                // Show empty state if no messages and feed is empty
                if (feedEl.children.length === 0 || (feedEl.children.length === 1 && feedEl.querySelector('.chat-feed-empty'))) {
                    showEmptyState();
                }
            })
            .catch(function (err) {
                loading = false;
                if (err.message !== 'Unauthorized') {
                    console.warn('Chat: failed to load messages', err);
                }
            });
    }

    function showEmptyState() {
        if (feedEl.querySelector('.chat-feed-empty')) return;
        if (feedEl.querySelector('.chat-msg')) return; // has real messages
        feedEl.innerHTML = '<div class="chat-feed-empty">' +
            '<span><i class="bi bi-chat-dots d-block mb-2" style="font-size:1.5rem"></i>' +
            'No messages yet.<br>Start the conversation.</span></div>';
    }

    function clearFeed() {
        feedEl.innerHTML = '';
    }

    // ── Message Rendering ────────────────────────────────────────

    function renderMessages(messages) {
        // Remove empty state if present
        var empty = feedEl.querySelector('.chat-feed-empty');
        if (empty) empty.remove();

        for (var i = 0; i < messages.length; i++) {
            appendMessage(messages[i]);
        }
        scrollToBottom();
    }

    function appendMessage(msg) {
        var row = document.createElement('div');
        row.className = 'chat-msg';
        row.setAttribute('data-msg-id', msg.id);

        // Priority class
        if (msg.priority === 'high' || msg.priority === 'urgent') {
            row.className += ' chat-msg-high';
        }

        // Signal class
        if (msg.msg_type === 'signal') {
            row.className += ' chat-msg-signal';
        }

        // System message class
        if (msg.msg_type === 'system' || msg.user_name === 'system') {
            row.className += ' chat-msg-system';
        }

        // Time
        var timeSpan = document.createElement('span');
        timeSpan.className = 'chat-msg-time';
        timeSpan.textContent = formatTime(msg.created_at);
        row.appendChild(timeSpan);

        // Username (skip for system)
        if (msg.msg_type !== 'system' && msg.user_name !== 'system') {
            var userSpan = document.createElement('span');
            userSpan.className = 'chat-msg-user';
            userSpan.textContent = msg.user_name || 'Unknown';
            userSpan.title = msg.user_name || '';
            row.appendChild(userSpan);
        }

        // Body
        var bodySpan = document.createElement('span');
        bodySpan.className = 'chat-msg-body';
        bodySpan.textContent = msg.body || '';
        row.appendChild(bodySpan);

        // Priority indicator icon
        if (msg.priority === 'high' || msg.priority === 'urgent') {
            var priIcon = document.createElement('i');
            priIcon.className = 'bi bi-exclamation-triangle-fill text-danger';
            priIcon.style.fontSize = '0.7rem';
            priIcon.style.flexShrink = '0';
            priIcon.title = 'High priority';
            row.appendChild(priIcon);
        }

        feedEl.appendChild(row);
    }

    function scrollToBottom() {
        if (!autoScrollEnabled) return;
        requestAnimationFrame(function () {
            feedEl.scrollTop = feedEl.scrollHeight;
        });
    }

    // ── Incoming Real-Time Messages ──────────────────────────────

    function handleIncomingMessage(data) {
        // 2026-06-28 (Eric beta follow-up) — track per-channel unread
        // counts so the channel selector can show traffic-on-other-channels
        // indicators. Previously messages on non-current channels were
        // silently dropped here, which meant a dispatcher on 'general'
        // had no way to know 'tac-1' was active.
        if (data.channel && data.channel !== currentChannel) {
            // Increment the per-channel unread bucket + repaint the selector.
            // (Own-user messages don't bump unread — the dispatcher knows
            // about their own send.)
            var selfId = (window.CURRENT_USER_ID || 0);
            if (!(selfId && data.user_id && parseInt(data.user_id, 10) === parseInt(selfId, 10))) {
                channelUnread[data.channel] = (channelUnread[data.channel] || 0) + 1;
                updateChannelSelectorBadges();
            }
            return;
        }

        // Avoid duplicates
        if (data.id && data.id <= lastMessageId) return;

        if (data.id) {
            lastMessageId = parseInt(data.id, 10);
        }

        // Remove empty state
        var empty = feedEl.querySelector('.chat-feed-empty');
        if (empty) empty.remove();

        appendMessage(data);
        scrollToBottom();

        // Unread tracking if widget is hidden
        if (!visible) {
            unreadCount++;
            updateUnreadBadge();
        }
    }

    // 2026-06-28 — per-channel unread tracking.
    var channelUnread = {};

    function updateChannelSelectorBadges() {
        if (!channelSelect) return;
        // Rewrite each option label to include "(N)" when unread > 0.
        // We store the original label in dataset.baseLabel the first time.
        var opts = channelSelect.querySelectorAll('option');
        for (var i = 0; i < opts.length; i++) {
            var val = opts[i].value;
            if (!opts[i].dataset.baseLabel) {
                opts[i].dataset.baseLabel = opts[i].textContent.replace(/\s*\(\d+\)$/, '');
            }
            var n = channelUnread[val] || 0;
            opts[i].textContent = n > 0
                ? opts[i].dataset.baseLabel + ' (' + n + ')'
                : opts[i].dataset.baseLabel;
        }
    }

    // ── Sending Messages ─────────────────────────────────────────

    function sendMessage() {
        var body = inputEl.value.trim();
        if (!body) return;

        inputEl.value = '';
        inputEl.focus();

        var payload = {
            action: 'send',
            body: body,
            channel: currentChannel,
            type: 'text',
            priority: 'normal'
        };

        fetch('api/chat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrf()
            },
            body: JSON.stringify(payload)
        })
        .then(function (res) {
            if (res.status === 401) {
                window.location.href = 'login.php';
                throw new Error('Unauthorized');
            }
            return res.json();
        })
        .then(function (data) {
            if (data.error || (data.success === false)) {
                // 2026-06-28 (a beta tester beta report): server returns error
                // but the widget previously only logged to console — user
                // saw "nothing happens." Surface it inline so they
                // know something went wrong + can act.
                var errText = data.error || (data.result && data.result.error) || 'Send failed';
                console.warn('Chat: send error', errText);
                appendSystemNotice('Send failed: ' + errText);
                return;
            }
            // 2026-06-28 (a beta tester beta report): "When posting a message
            // in the chat window, nothing happens." The send WAS
            // succeeding server-side but the widget waited for SSE to
            // render the message. If SSE round-trip is slow or
            // misconfigured (visibility scope mismatch, EventBus
            // disconnect), the user perceives "nothing happens."
            //
            // Optimistic render: show the user's own message
            // immediately. If SSE later delivers the same message,
            // handleIncomingMessage's duplicate-check by data-msg-id
            // prevents double-rendering.
            var chatId = (data.result && data.result.chat_id) || data.chat_id;
            if (chatId) {
                appendMessage({
                    id:         chatId,
                    user_id:    null,            // self
                    user_name:  (window.CURRENT_USER || 'me'),
                    channel:    payload.channel,
                    body:       payload.body,
                    msg_type:   payload.type,
                    priority:   payload.priority,
                    created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                });
                scrollToBottom();
            }
        })
        .catch(function (err) {
            if (err.message !== 'Unauthorized') {
                console.warn('Chat: send failed', err);
                appendSystemNotice('Send failed: network error');
            }
        });
    }

    // Inline system notice — used when the send fails so the user
    // gets visible feedback (the existing render path treats
    // user_name === 'system' as a system-style row, no chrome).
    function appendSystemNotice(text) {
        appendMessage({
            id: 'notice-' + Date.now(),
            user_id: null,
            user_name: 'system',
            channel: currentChannel,
            body: text,
            msg_type: 'system',
            priority: 'normal',
            created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        });
        scrollToBottom();
    }

    // ── Signal/Code Sending ──────────────────────────────────────

    function loadSignals() {
        fetch('api/chat.php?signals=1', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                signals = data.signals || [];
                signalsLoaded = true;
                renderSignalMenu();
            })
            .catch(function () {
                signalsLoaded = false;
            });
    }

    function renderSignalMenu() {
        signalMenu.innerHTML = '';

        if (signals.length === 0) {
            var empty = document.createElement('span');
            empty.className = 'dropdown-item-text text-body-secondary small';
            empty.textContent = 'No signals configured';
            signalMenu.appendChild(empty);
            return;
        }

        for (var i = 0; i < signals.length; i++) {
            var sig = signals[i];
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'dropdown-item';
            item.setAttribute('data-signal-id', sig.id || sig.code || i);
            item.setAttribute('data-signal-text', sig.code + ' - ' + (sig.description || sig.meaning || ''));

            var badge = document.createElement('span');
            badge.className = 'badge bg-secondary';
            badge.textContent = sig.code || '';
            item.appendChild(badge);

            var desc = document.createTextNode(' ' + (sig.description || sig.meaning || ''));
            item.appendChild(desc);

            signalMenu.appendChild(item);
        }
    }

    function sendSignal(signalId, signalText) {
        var payload = {
            action: 'send_signal',
            signal_id: signalId,
            body: signalText || '',
            channel: currentChannel,
            priority: 'normal'
        };

        fetch('api/chat.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrf()
            },
            body: JSON.stringify(payload)
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.error) {
                console.warn('Chat: signal send error', data.error);
            }
        })
        .catch(function (err) {
            console.warn('Chat: signal send failed', err);
        });
    }

    // ── Unread Badge ─────────────────────────────────────────────

    function updateUnreadBadge() {
        if (typeof EventBus !== 'undefined') {
            EventBus.emit('chat:unread', { count: unreadCount });
        }

        // Also update the comms button badge directly
        var btns = document.querySelectorAll('.ctrl-btn[data-action="chat"]');
        for (var i = 0; i < btns.length; i++) {
            var existing = btns[i].querySelector('.badge');
            if (unreadCount > 0) {
                if (!existing) {
                    existing = document.createElement('span');
                    existing.className = 'badge bg-danger rounded-pill ms-1';
                    existing.style.fontSize = '0.65rem';
                    btns[i].appendChild(existing);
                }
                existing.textContent = unreadCount > 99 ? '99+' : unreadCount;
            } else {
                if (existing) existing.remove();
            }
        }
    }

    // ── Bootstrap ────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
