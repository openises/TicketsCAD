/**
 * NewUI v4.0 - Internal Messaging & HAS Broadcast
 *
 * Handles the three-tab messaging interface (Inbox, Sent, Compose),
 * message detail modal, HAS broadcast, and real-time SSE notifications.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ────────────────────────────────────────────────────
    var inboxPage  = 1;
    var sentPage   = 1;
    var pageLimit  = 25;
    var usersCache = null;
    var currentMsgId = null;
    var currentMsgFromUserId = null;   // GH #87 — sender of the open message, for Reply pre-select
    var selectedIds  = {};

    // ── Helpers ──────────────────────────────────────────────────

    function getCsrf() {
        return (typeof CSRF_TOKEN !== 'undefined') ? CSRF_TOKEN : '';
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var now = new Date();
            var isToday = d.toDateString() === now.toDateString();
            if (isToday) {
                return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
            return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) +
                   ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateStr;
        }
    }

    function formatDateFull(dateStr) {
        if (!dateStr) return '';
        try {
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            return d.toLocaleString();
        } catch (e) {
            return dateStr;
        }
    }

    function priorityBadge(priority) {
        if (priority === 'urgent') {
            return '<span class="badge bg-danger">Urgent</span>';
        }
        if (priority === 'high') {
            return '<span class="badge bg-warning text-dark">High</span>';
        }
        return '<span class="badge bg-secondary">Normal</span>';
    }

    function apiGet(url, callback) {
        fetch(url, { credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = 'login.php';
                    throw new Error('Unauthorized');
                }
                return res.json();
            })
            .then(function (data) {
                callback(null, data);
            })
            .catch(function (err) {
                if (err.message !== 'Unauthorized') {
                    callback(err, null);
                }
            });
    }

    function apiPost(action, data, callback) {
        var payload = {};
        for (var k in data) {
            if (data.hasOwnProperty(k)) payload[k] = data[k];
        }
        payload.action = action;
        payload.csrf_token = getCsrf();

        fetch('api/messaging.php', {
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
            callback(null, data);
        })
        .catch(function (err) {
            if (err.message !== 'Unauthorized') {
                callback(err, null);
            }
        });
    }

    // ── Inbox ────────────────────────────────────────────────────

    function loadInbox(page) {
        if (page) inboxPage = page;
        var url = 'api/messaging.php?folder=inbox&page=' + inboxPage + '&limit=' + pageLimit;
        apiGet(url, function (err, data) {
            if (err || !data) return;
            renderInbox(data);
        });
    }

    function renderInbox(data) {
        var tbody = document.getElementById('inboxBody');
        var info  = document.getElementById('inboxInfo');
        var msgs  = data.messages || [];

        selectedIds = {};
        // 2026-06-28 (Eric beta) — the select-all master checkbox
        // wasn't being reset on re-render, so after a page reload it
        // still showed as checked (state from previous interaction)
        // even though all per-row checkboxes were freshly unchecked.
        // To re-select all the user had to toggle it off then on.
        // Reset it explicitly here.
        var selectAllEl = document.getElementById('inboxSelectAll');
        if (selectAllEl) selectAllEl.checked = false;
        updateBulkDeleteBtn();

        if (msgs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-inbox d-block mb-2" style="font-size:1.5rem"></i>No messages</td></tr>';
            info.textContent = '';
            renderPagination('inboxPagination', data.page, data.pages, function (p) { loadInbox(p); });
            return;
        }

        var html = '';
        for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            var isUnread = !m.read_at;
            var rowClass = isUnread ? 'msg-row-unread' : '';
            var broadcastIcon = m.is_broadcast == 1 ? ' <i class="bi bi-megaphone-fill text-danger" title="Broadcast"></i>' : '';

            html += '<tr class="msg-row ' + rowClass + '" data-msg-id="' + m.id + '">';
            html += '<td><input type="checkbox" class="form-check-input inbox-check" data-id="' + m.id + '"></td>';
            html += '<td>' + (isUnread ? '<i class="bi bi-circle-fill text-primary" style="font-size:0.5rem" title="Unread"></i>' : '') + '</td>';
            html += '<td class="text-truncate" style="max-width:140px">' + escapeHtml(m.from_name || 'Unknown') + broadcastIcon + '</td>';
            html += '<td class="msg-subject-cell text-truncate" style="max-width:400px;cursor:pointer">' + escapeHtml(m.subject || '(no subject)') + '</td>';
            html += '<td class="text-center">' + priorityBadge(m.priority) + '</td>';
            html += '<td class="small text-body-secondary">' + formatDate(m.created_at) + '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;

        info.textContent = 'Showing ' + msgs.length + ' of ' + data.total;
        renderPagination('inboxPagination', data.page, data.pages, function (p) { loadInbox(p); });

        // Attach click handlers
        var rows = tbody.querySelectorAll('.msg-row');
        for (var j = 0; j < rows.length; j++) {
            (function (row) {
                var subjectCell = row.querySelector('.msg-subject-cell');
                if (subjectCell) {
                    subjectCell.addEventListener('click', function () {
                        openMessage(parseInt(row.getAttribute('data-msg-id'), 10));
                    });
                }
                var checkbox = row.querySelector('.inbox-check');
                if (checkbox) {
                    checkbox.addEventListener('change', function (e) {
                        e.stopPropagation();
                        var mid = checkbox.getAttribute('data-id');
                        if (checkbox.checked) {
                            selectedIds[mid] = true;
                        } else {
                            delete selectedIds[mid];
                        }
                        updateBulkDeleteBtn();
                    });
                }
            })(rows[j]);
        }
    }

    function updateBulkDeleteBtn() {
        var btn = document.getElementById('inboxBulkDelete');
        var readBtn = document.getElementById('inboxBulkMarkRead');
        var count = Object.keys(selectedIds).length;
        if (count > 0) {
            btn.classList.remove('d-none');
            btn.innerHTML = '<i class="bi bi-trash me-1"></i>Delete Selected (' + count + ')';
            if (readBtn) {
                readBtn.classList.remove('d-none');
                readBtn.innerHTML = '<i class="bi bi-envelope-open me-1"></i>Mark Read (' + count + ')';
            }
        } else {
            btn.classList.add('d-none');
            if (readBtn) readBtn.classList.add('d-none');
        }
    }

    // Phase 2026-06-28 — bulk mark-read companion to bulk-delete.
    function bulkMarkRead() {
        var ids = Object.keys(selectedIds);
        if (ids.length === 0) return;
        var intIds = [];
        for (var i = 0; i < ids.length; i++) intIds.push(parseInt(ids[i], 10));
        apiPost('bulk_mark_read', { message_ids: intIds }, function (err, data) {
            if (err) { alert('Failed to mark read: ' + err); return; }
            selectedIds = {};
            loadInbox();
            // 2026-06-28 (Eric beta) — refresh the unread badge count.
            // Previously bulkDelete called this but bulkMarkRead did not,
            // so the navbar/tab badge stayed stale until the 60-second
            // poll fired.
            loadUnreadCount();
        });
    }

    // ── Sent ─────────────────────────────────────────────────────

    function loadSent(page) {
        if (page) sentPage = page;
        var url = 'api/messaging.php?folder=sent&page=' + sentPage + '&limit=' + pageLimit;
        apiGet(url, function (err, data) {
            if (err || !data) return;
            renderSent(data);
        });
    }

    function renderSent(data) {
        var tbody = document.getElementById('sentBody');
        var msgs  = data.messages || [];

        if (msgs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-send d-block mb-2" style="font-size:1.5rem"></i>No sent messages</td></tr>';
            renderPagination('sentPagination', data.page, data.pages, function (p) { loadSent(p); });
            return;
        }

        // Phase 99b (2026-06-28) — unified Sent rows. Internal
        // messages carry source='inbox' + click-opens the message
        // detail modal. External sends carry source='external' +
        // channel ('smtp'/'sms'/...) — click is a no-op for now
        // (they're send-and-forget; no thread to open). A channel
        // icon prefixes the To column so dispatchers see at a
        // glance what protocol the message went through.
        var html = '';
        for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            var isExternal = (m.source === 'external');
            var broadcastIcon = m.is_broadcast == 1 ? ' <i class="bi bi-megaphone-fill text-danger" title="Broadcast"></i>' : '';
            var toLabel = m.is_broadcast == 1 ? 'All Users' : escapeHtml(m.to_names || 'Unknown');
            var channelIcon = _sentChannelIcon(m.channel);
            var statusBadge = '';
            if (isExternal && m.status) {
                var statusCls = (m.status === 'delivered') ? 'bg-success'
                              : (m.status === 'failed')    ? 'bg-danger'
                              : (m.status === 'pending')   ? 'bg-warning text-dark'
                              : 'bg-secondary';
                statusBadge = ' <span class="badge ' + statusCls + ' ms-1" style="font-size:0.55rem;">' + escapeHtml(m.status) + '</span>';
            }
            // External rows aren't clickable (no detail modal for fire-and-forget sends).
            var rowAttrs = isExternal
                ? 'class="msg-row-external" data-msg-id="' + m.id + '"'
                : 'class="msg-row" data-msg-id="' + m.id + '" style="cursor:pointer"';

            html += '<tr ' + rowAttrs + '>';
            html += '<td class="text-truncate" style="max-width:220px">' + channelIcon + toLabel + broadcastIcon + '</td>';
            html += '<td class="text-truncate" style="max-width:380px">' + escapeHtml(m.subject || '(no subject)') + statusBadge + '</td>';
            html += '<td class="text-center">' + priorityBadge(m.priority) + '</td>';
            html += '<td class="small text-body-secondary">' + formatDate(m.created_at) + '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;

        renderPagination('sentPagination', data.page, data.pages, function (p) { loadSent(p); });

        // Click to view (internal-message rows only; external are inert).
        var rows = tbody.querySelectorAll('.msg-row');
        for (var j = 0; j < rows.length; j++) {
            (function (row) {
                row.addEventListener('click', function () {
                    openMessage(parseInt(row.getAttribute('data-msg-id'), 10));
                });
            })(rows[j]);
        }
    }

    // Phase 99b — small icon prefix for the Sent tab's To column,
    // so dispatchers can see at a glance what channel each message
    // went out on. Returns empty string for inbox (current default).
    function _sentChannelIcon(channel) {
        if (!channel || channel === 'inbox') return '';
        var icons = {
            'smtp':       'bi-envelope-fill text-primary',
            'email':      'bi-envelope-fill text-primary',
            'sms':        'bi-phone-fill text-success',
            'meshtastic': 'bi-broadcast text-info',
            'meshcore':   'bi-broadcast text-info',
            'zello':      'bi-megaphone-fill text-warning',
            'aprs':       'bi-router text-secondary',
            'dmr':        'bi-broadcast-pin text-info',
            'slack':      'bi-slack text-purple'
        };
        var cls = icons[channel] || 'bi-send text-body-secondary';
        return '<i class="bi ' + cls + ' me-1" title="' + escapeHtml(channel) + '"></i>';
    }

    // ── Pagination ───────────────────────────────────────────────

    function renderPagination(containerId, currentPage, totalPages, onPageClick) {
        var container = document.getElementById(containerId);
        if (!container) return;

        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        var html = '<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0">';

        // Previous
        html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a></li>';

        // Page numbers (show max 7)
        var startPage = Math.max(1, currentPage - 3);
        var endPage   = Math.min(totalPages, startPage + 6);
        if (endPage - startPage < 6) startPage = Math.max(1, endPage - 6);

        for (var p = startPage; p <= endPage; p++) {
            html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '">';
            html += '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
        }

        // Next
        html += '<li class="page-item' + (currentPage >= totalPages ? ' disabled' : '') + '">';
        html += '<a class="page-link" href="#" data-page="' + (currentPage + 1) + '">&raquo;</a></li>';

        html += '</ul></nav>';
        container.innerHTML = html;

        // Attach click handlers
        var links = container.querySelectorAll('.page-link');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function (e) {
                e.preventDefault();
                var pg = parseInt(this.getAttribute('data-page'), 10);
                if (!isNaN(pg) && pg >= 1 && pg <= totalPages) {
                    onPageClick(pg);
                }
            });
        }
    }

    // ── Message Detail ───────────────────────────────────────────

    function openMessage(msgId) {
        currentMsgId = msgId;
        apiGet('api/messaging.php?id=' + msgId, function (err, data) {
            if (err || !data || !data.message) return;
            var m = data.message;
            currentMsgFromUserId = m.from_user_id ? parseInt(m.from_user_id, 10) : null;

            document.getElementById('msgDetailTitle').textContent = m.subject || '(no subject)';
            document.getElementById('msgDetailFrom').textContent = m.from_name || 'Unknown';
            document.getElementById('msgDetailDate').textContent = formatDateFull(m.created_at);

            // Recipients
            var toNames = [];
            if (m.recipients && m.recipients.length > 0) {
                for (var i = 0; i < m.recipients.length; i++) {
                    toNames.push(m.recipients[i].to_name || 'User #' + m.recipients[i].to_user_id);
                }
            }
            document.getElementById('msgDetailTo').textContent = m.is_broadcast == 1 ? 'All Users (Broadcast)' : toNames.join(', ');
            document.getElementById('msgDetailPriority').innerHTML = priorityBadge(m.priority);
            document.getElementById('msgDetailIncident').textContent = m.incident_id ? '#' + m.incident_id : 'None';

            // Body — preserve whitespace
            var bodyEl = document.getElementById('msgDetailBody');
            bodyEl.textContent = m.body || '';

            // Show modal
            var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('msgDetailModal'));
            modal.show();

            // Mark row as read in inbox
            var row = document.querySelector('#inboxBody tr[data-msg-id="' + msgId + '"]');
            if (row) {
                row.classList.remove('msg-row-unread');
                var dot = row.querySelector('.bi-circle-fill');
                if (dot) dot.remove();
            }

            // Update unread count
            loadUnreadCount();
        });
    }

    // ── Compose ──────────────────────────────────────────────────

    function loadUsers(onDone) {
        if (usersCache) {
            populateUserSelect(usersCache);
            if (onDone) onDone();
            return;
        }
        apiGet('api/messaging.php?users=1', function (err, data) {
            if (err || !data) { if (onDone) onDone(); return; }
            usersCache = data.users || [];
            populateUserSelect(usersCache);
            if (onDone) onDone();
        });
    }

    function populateUserSelect(users) {
        var select = document.getElementById('composeTo');
        // Keep the "All Users" option, remove others
        while (select.options.length > 1) {
            select.remove(1);
        }
        for (var i = 0; i < users.length; i++) {
            var opt = document.createElement('option');
            opt.value = users[i].id;
            opt.textContent = users[i].name;
            select.appendChild(opt);
        }
    }

    // Phase 99a (2026-06-28) — channel-aware sendMessage. Branches
    // between the legacy internal-messages send path (api/messaging.php
    // ?action=send) and the new broker send path (api/messaging-send.php)
    // based on the channel picker.
    function sendMessage() {
        var channel  = (document.getElementById('composeChannel') || {}).value || 'inbox';
        var subject  = document.getElementById('composeSubject').value.trim();
        var body     = document.getElementById('composeBody').value.trim();
        var priority = document.getElementById('composePriority').value;
        var incident = document.getElementById('composeIncident').value;
        var sendAs   = (document.getElementById('composeSendAs') || {}).value || 'system';

        if (!body) {
            alert('Please enter a message body.');
            document.getElementById('composeBody').focus();
            return;
        }

        var sendBtn = document.getElementById('composeSend');

        if (channel === 'inbox') {
            // ── Inbox (internal messages) — legacy path unchanged ──
            var toSelect = document.getElementById('composeTo');
            var toAll  = false;
            var toUsers = [];
            for (var i = 0; i < toSelect.selectedOptions.length; i++) {
                var val = toSelect.selectedOptions[i].value;
                if (val === 'all') { toAll = true; break; }
                toUsers.push(parseInt(val, 10));
            }
            if (!toAll && toUsers.length === 0) {
                alert('Please select at least one recipient.');
                return;
            }
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
            apiPost('send', {
                subject:     subject,
                body:        body,
                priority:    priority,
                incident_id: incident ? parseInt(incident, 10) : null,
                to_users:    toUsers,
                to_all:      toAll
            }, function (err, data) {
                _composeAfterSend(err, data, sendBtn);
            });
            return;
        }

        // ── External channel — resolve the recipient per the current Send-To mode ──
        var sendTo = (document.getElementById('composeSendTo') || {}).value || 'text';
        var to = '';
        if (sendTo === 'channel') {
            to = (document.getElementById('composeChannelSlot') || {}).value || 'channel:0';
        } else if (sendTo === 'text') {
            to = (document.getElementById('composeToText') || {}).value.trim();
            if (!to) {
                alert('Please enter at least one recipient.');
                document.getElementById('composeToText').focus();
                return;
            }
        } else if (sendTo === 'unit') {
            alert('Direct-to-unit resolver is not yet wired for ' + channel + '. Pick a different "Send to" option above.');
            return;
        } else if (sendTo === 'talkgroup') {
            // Phase 99e (2026-06-28) — DMR talkgroup.
            to = (document.getElementById('composeTalkgroup') || {}).value || '';
            if (!to || to.indexOf('tg:') !== 0) {
                alert('Please select a talkgroup.');
                return;
            }
        }

        var bridgeId = parseInt(((document.getElementById('composeBridge') || {}).value) || '0', 10);
        var speak    = !!(document.getElementById('composeSpeak') || {}).checked;

        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
        fetch('api/messaging-send.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': (typeof getCsrf === 'function' ? getCsrf() :
                    (document.querySelector('meta[name="csrf-token"]') || {}).content || '')
            },
            body: JSON.stringify({
                channel:           channel,
                to:                to,
                subject:           subject,
                body:              body,
                priority:          priority,
                send_as:           sendAs,
                incident_id:       incident ? parseInt(incident, 10) : null,
                target_bridge_id:  bridgeId || null,
                speak_on_channel:  speak
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="bi bi-send me-1"></i>Send Message';
            if (data.error) {
                alert('Send failed: ' + data.error);
                return;
            }
            // Per-recipient outcome summary.
            var ok = data.success_count || 0;
            var fail = data.failed_count || 0;
            if (fail > 0) {
                var failList = (data.results || []).filter(function (r) { return !r.ok; })
                    .map(function (r) { return r.recipient + ' (' + (r.error || 'unknown') + ')'; })
                    .join('\n  ');
                alert('Sent: ' + ok + ', failed: ' + fail + '\n\nFailures:\n  ' + failList);
            } else if (ok > 0) {
                // Phase 99a-v2 followup — surface routing detail.
                // For mesh channels the per-result row carries
                // target_bridge_label + note from the channel handler.
                // Reroute warnings get a yellow toast (so the user
                // sees the server fell back to a different bridge);
                // clean sends get a success toast with the bridge label.
                var first = (data.results || [])[0] || {};
                var note = first.note || '';
                var label = first.target_bridge_label || '';
                if (first.reroute_warning) {
                    alert('Sent (re-routed):\n\n' + note);
                } else if (note) {
                    alert(note);
                }
            }
            // Clear form on full or partial success.
            if (ok > 0) {
                _composeClearForm();
            }
        })
        .catch(function (err) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="bi bi-send me-1"></i>Send Message';
            alert('Network error: ' + err.message);
        });
    }

    function _composeAfterSend(err, data, sendBtn) {
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<i class="bi bi-send me-1"></i>Send Message';
        if (err || (data && data.error)) {
            alert('Failed to send message: ' + (data ? data.error : 'Network error'));
            return;
        }
        _composeClearForm();
        // Switch to Sent tab so the user sees confirmation.
        var sentTab = document.getElementById('tab-sent');
        if (sentTab) {
            var bsTab = bootstrap.Tab.getOrCreateInstance(sentTab);
            bsTab.show();
        }
        loadSent(1);
    }

    function _composeClearForm() {
        document.getElementById('composeSubject').value = '';
        document.getElementById('composeBody').value = '';
        document.getElementById('composePriority').value = 'normal';
        document.getElementById('composeIncident').value = '';
        var toSel = document.getElementById('composeTo');
        if (toSel) toSel.selectedIndex = -1;
        var toText = document.getElementById('composeToText');
        if (toText) toText.value = '';
        // Repaint char counter to clean state.
        _composeUpdateCharCounter();
    }

    // Phase 99a-v2 (2026-06-28) — Eric beta redesign. Channel meta now
    // declares (a) what Send-To options apply, (b) which extra controls
    // appear (bridge picker, speak-on-channel, subject, incident,
    // priority, send-as). Form reshapes dynamically as the user picks
    // Channel + Send To.
    //
    // sendToOpts: array of {value, label} — order matters; first is default
    // bridgePicker: 'mesh' shows the mesh bridge selector
    // speak: true shows the 'Speak on channel (TTS)' checkbox
    // priority: true shows the priority dropdown (false hides it)
    var CHANNEL_META = {
        inbox: {
            help: 'Internal — message lands in recipient\'s TicketsCAD inbox.',
            sendToOpts: [{value: 'users', label: 'TicketsCAD users'}],
            bridgePicker: null,
            speak: false,
            priority: true,
            sendAs: false,
            showSubject: true,
            showIncident: true,
            charLimit: 0, unitLabel: ''
        },
        smtp: {
            help: 'Email via SMTP. Comma-separate multiple addresses.',
            sendToOpts: [{value: 'text', label: 'Direct — email address'},
                         {value: 'unit', label: 'Direct — unit / person'}],
            bridgePicker: null,
            speak: false,
            priority: true,
            sendAs: true,
            showSubject: true,
            showIncident: false,
            charLimit: 0, unitLabel: ''
        },
        sms: {
            help: 'Text message via SMS. Limit 160 chars per segment.',
            sendToOpts: [{value: 'text', label: 'Direct — phone number'},
                         {value: 'unit', label: 'Direct — unit / person'}],
            bridgePicker: null,
            speak: false,
            priority: true,
            sendAs: false,
            showSubject: false,
            showIncident: false,
            charLimit: 160, unitLabel: '/160'
        },
        meshtastic: {
            help: 'Mesh radio (Meshtastic). Pick a channel slot for broadcast, or search a node by name for direct message.',
            sendToOpts: [{value: 'channel', label: 'Channel (broadcast)'},
                         {value: 'text',    label: 'Direct — node search'},
                         {value: 'unit',    label: 'Direct — unit / person'}],
            bridgePicker: 'mesh',
            speak: false,     // mesh voice not supported
            priority: false,  // no semantic effect
            sendAs: false,
            showSubject: false,
            showIncident: false,
            charLimit: 200, unitLabel: '/200'
        },
        meshcore: {
            help: 'Mesh radio (MeshCore). Pick a channel slot for broadcast, or search a node by name for direct message.',
            sendToOpts: [{value: 'channel', label: 'Channel (broadcast)'},
                         {value: 'text',    label: 'Direct — node search'},
                         {value: 'unit',    label: 'Direct — unit / person'}],
            bridgePicker: 'mesh',
            speak: false,
            priority: false,
            sendAs: false,
            showSubject: false,
            showIncident: false,
            charLimit: 200, unitLabel: '/200'
        },
        aprs: {
            help: 'Amateur radio APRS message via APRS-IS. Destination is a callsign (with optional -SSID). Limit 67 chars.',
            sendToOpts: [{value: 'text', label: 'Direct — callsign'},
                         {value: 'unit', label: 'Direct — unit / person'}],
            bridgePicker: null,
            speak: false,
            priority: false,
            sendAs: false,
            showSubject: false,
            showIncident: false,
            charLimit: 67, unitLabel: '/67'
        },
        // Phase 99e (2026-06-28) — DMR text channel. Talkgroups come
        // from the registry (Settings → DMR Talkgroups). Direct radio-id
        // option is for private call to a specific DMR ID. Speak-on-channel
        // is supported (DMR voice path already shipped in Phase 84).
        dmr: {
            help: 'DMR text message via BrandMeister. Choose a talkgroup (broadcast) or a direct DMR radio ID (private call). Speak-on-channel converts text to voice and TXes via the radio bridge.',
            sendToOpts: [{value: 'talkgroup', label: 'Talkgroup (broadcast)'},
                         {value: 'text',      label: 'Direct — DMR radio ID'},
                         {value: 'unit',      label: 'Direct — unit / person'}],
            bridgePicker: null,
            speak: true,
            priority: false,
            sendAs: false,
            showSubject: false,
            showIncident: false,
            charLimit: 138, unitLabel: '/138'   // DMR data short msg cap
        }
    };

    // Recipient sub-mode meta — placeholder + help text per (channel, sendTo).
    function _recipDetail(channel, sendTo) {
        if (sendTo === 'text') {
            if (channel === 'smtp')       return {label: 'To (email)',       ph: 'recipient@example.com, another@example.com', help: 'Separate multiple email addresses with commas. Start typing to search personnel.'};
            if (channel === 'sms')        return {label: 'To (phone)',       ph: '+13205550123, 612-555-0167',                  help: 'Separate multiple numbers with commas. Include + and country code for international.'};
            if (channel === 'aprs')       return {label: 'To (callsign)',    ph: 'W0AM-7 or KE0XYZ',                            help: 'APRS callsign with optional -SSID.'};
            if (channel === 'meshtastic') return {label: 'To node (search by name)', ph: 'Type a node long/short name…',        help: 'Search mesh_nodes by long_name, short_name, or !hex node id.'};
            if (channel === 'meshcore')   return {label: 'To node (search by name)', ph: 'Type a node long/short name…',        help: 'Search mesh_nodes by long_name, short_name, or pubkey hex.'};
            if (channel === 'dmr')        return {label: 'To (DMR radio ID)', ph: '3104410',                                    help: 'Numeric DMR radio ID (radioid.net lookup). Sends as private call.'};
        }
        return {label: 'To', ph: '', help: ''};
    }

    function _composeApplyChannel() {
        var sel = document.getElementById('composeChannel');
        if (!sel) return;
        var channel = sel.value || 'inbox';

        // Channel availability gating (unchanged).
        if (window.MESSAGING_CHANNELS) {
            var opts = sel.querySelectorAll('option');
            for (var i = 0; i < opts.length; i++) {
                var v = opts[i].value;
                if (v === 'inbox') continue;
                var configured = !!window.MESSAGING_CHANNELS[v];
                opts[i].disabled = !configured;
                opts[i].textContent = opts[i].textContent.replace(/\s*—\s*not configured.*$/, '');
                if (!configured) opts[i].textContent += ' — not configured';
            }
            if (sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].disabled) {
                sel.value = 'inbox';
                channel = 'inbox';
            }
        }

        var meta = CHANNEL_META[channel] || CHANNEL_META.inbox;

        // Channel-help line.
        var helpEl = document.getElementById('composeChannelHelp');
        if (helpEl) helpEl.textContent = meta.help;

        // Bridge picker (mesh only).
        var bridgeCol = document.getElementById('composeBridgeCol');
        if (bridgeCol) bridgeCol.style.display = (meta.bridgePicker === 'mesh') ? '' : 'none';
        if (meta.bridgePicker === 'mesh') _composeLoadBridges(channel);

        // Send-To selector.
        var sendToCol = document.getElementById('composeSendToCol');
        var sendToSel = document.getElementById('composeSendTo');
        if (meta.sendToOpts.length > 1) {
            if (sendToCol) sendToCol.style.display = '';
        } else {
            if (sendToCol) sendToCol.style.display = 'none';
        }
        if (sendToSel) {
            sendToSel.innerHTML = '';
            meta.sendToOpts.forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o.value;
                opt.textContent = o.label;
                sendToSel.appendChild(opt);
            });
        }

        // Initial Send-To = first option.
        _composeApplySendTo();

        // Speak-on-channel toggle.
        var speakCol = document.getElementById('composeSpeakCol');
        if (speakCol) speakCol.style.display = meta.speak ? '' : 'none';

        // Priority column.
        var prioCol = document.getElementById('composePriorityCol');
        if (prioCol) prioCol.style.display = meta.priority ? '' : 'none';

        // Subject + Incident-attach.
        var subjCol = document.getElementById('composeSubjectCol');
        if (subjCol) subjCol.style.display = meta.showSubject ? '' : 'none';
        var incCol = document.getElementById('composeIncidentCol');
        if (incCol) incCol.style.display = meta.showIncident ? '' : 'none';

        // Send-As column.
        var sendAsCol = document.getElementById('composeSendAsCol');
        var sendAsSel = document.getElementById('composeSendAs');
        var personal  = (window.SEND_AS_PERSONAL && window.SEND_AS_PERSONAL[channel]) || null;
        if (meta.sendAs && personal) {
            if (sendAsCol) sendAsCol.style.display = '';
            if (sendAsSel) {
                sendAsSel.innerHTML =
                    '<option value="system">Dispatch (system)</option>' +
                    '<option value="me">' + _escHtml(personal.label) + '</option>';
            }
        } else {
            if (sendAsCol) sendAsCol.style.display = 'none';
        }

        _composeUpdateCharCounter();
    }

    // Recipient row reshape — driven by Send To selection (within a channel).
    function _composeApplySendTo() {
        var channelSel = document.getElementById('composeChannel');
        var sendToSel  = document.getElementById('composeSendTo');
        var channel = (channelSel && channelSel.value) || 'inbox';
        var meta = CHANNEL_META[channel] || CHANNEL_META.inbox;
        var sendTo = (sendToSel && sendToSel.value) || meta.sendToOpts[0].value;

        // Hide all recipient subforms first.
        ['composeRecipInbox', 'composeRecipChannel', 'composeRecipText',
         'composeRecipUnit', 'composeRecipTalkgroup']
            .forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });

        // Show the chosen subform + populate as needed.
        if (sendTo === 'users') {
            document.getElementById('composeRecipInbox').style.display = '';
        } else if (sendTo === 'channel') {
            document.getElementById('composeRecipChannel').style.display = '';
            var lbl = document.getElementById('composeRecipChannelLabel');
            if (lbl) lbl.textContent = (channel === 'aprs') ? 'Channel' : 'Channel slot';
        } else if (sendTo === 'text') {
            var rowText = document.getElementById('composeRecipText');
            rowText.style.display = '';
            var d = _recipDetail(channel, 'text');
            var lblT = document.getElementById('composeRecipTextLabel');
            var helpT = document.getElementById('composeRecipTextHelp');
            var inputT = document.getElementById('composeToText');
            if (lblT) lblT.textContent = d.label;
            if (helpT) helpT.textContent = d.help;
            if (inputT) inputT.placeholder = d.ph;
        } else if (sendTo === 'unit') {
            document.getElementById('composeRecipUnit').style.display = '';
            _composeLoadUnitPick(channel);
        } else if (sendTo === 'talkgroup') {
            // Phase 99e (2026-06-28) — DMR talkgroup picker.
            document.getElementById('composeRecipTalkgroup').style.display = '';
            _composeLoadTalkgroups();
        }
    }

    // Phase 99e (2026-06-28) — populate talkgroup dropdown from registry.
    // Only enabled rows. Sorted server-side by sort_order ASC, name ASC.
    // Cached for the session — talkgroups don't change mid-compose.
    var _talkgroupsCache = null;

    function _composeLoadTalkgroups() {
        var sel = document.getElementById('composeTalkgroup');
        if (!sel) return;

        function render(list) {
            sel.innerHTML = '';
            if (!list || list.length === 0) {
                sel.innerHTML = '<option value="">— no enabled talkgroups —</option>';
                return;
            }
            list.forEach(function (tg) {
                var opt = document.createElement('option');
                opt.value = 'tg:' + tg.dmr_id;
                // "TG 31673 — FEMA Region V AUXCOMM (group)"
                var ctTag = tg.call_type === 'private' ? ' (private)' : '';
                opt.textContent = 'TG ' + tg.dmr_id + ' — ' + tg.name + ctTag;
                if (tg.description) opt.title = tg.description;
                sel.appendChild(opt);
            });
        }

        if (_talkgroupsCache) {
            render(_talkgroupsCache);
            return;
        }
        fetch('api/talkgroups.php?enabled=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _talkgroupsCache = (data && data.talkgroups) || [];
                render(_talkgroupsCache);
            })
            .catch(function () {
                sel.innerHTML = '<option value="">— failed to load talkgroups —</option>';
            });
    }

    // Bridge meta cache so the offline-warning + change handler can
    // read status without re-fetching. Keyed by bridge id (string).
    var _bridgeMeta = {};

    function _composeLoadBridges(channel) {
        var sel = document.getElementById('composeBridge');
        if (!sel) return;
        fetch('api/messaging-bridges.php?protocol=' + encodeURIComponent(channel), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.bridges) return;
                sel.innerHTML = '<option value="0">Any (heard-by default)</option>';
                _bridgeMeta = {};
                data.bridges.forEach(function (b) {
                    var statusBit = (b.status === 'online')
                        ? ' • online'
                        : (b.status === 'offline' ? ' • ' + (b.last_seen_age || 'offline') : ' • never seen');
                    var heardBit = b.recently_heard ? ' ✓' : '';
                    var opt = document.createElement('option');
                    opt.value = String(b.id);
                    opt.textContent = b.label + statusBit + heardBit;
                    if (b.summary) opt.title = b.summary;
                    sel.appendChild(opt);
                    _bridgeMeta[String(b.id)] = b;
                });
                _composeUpdateBridgeWarning();
            })
            .catch(function () { /* non-fatal — picker stays at 'Any' */ });

        // Bind once: bridge change shows/hides the offline warning.
        if (sel && sel.dataset.warnBound !== '1') {
            sel.dataset.warnBound = '1';
            sel.addEventListener('change', _composeUpdateBridgeWarning);
        }
    }

    // Phase 99a-v2 followup (2026-06-28) — soft warning when the user
    // picks a specific bridge that's offline. The send will still go
    // through (server falls back to another bridge or queues unpinned)
    // but the user should know.
    function _composeUpdateBridgeWarning() {
        var sel = document.getElementById('composeBridge');
        if (!sel) return;
        // Reuse a single warning row in the bridge column. Create on first use.
        var warn = document.getElementById('composeBridgeWarn');
        if (!warn) {
            warn = document.createElement('div');
            warn.id = 'composeBridgeWarn';
            warn.className = 'form-text text-warning';
            warn.style.display = 'none';
            sel.parentNode.appendChild(warn);
        }
        var pick = sel.value;
        var meta = _bridgeMeta[pick];
        if (!pick || pick === '0' || !meta || meta.status === 'online') {
            warn.style.display = 'none';
            return;
        }
        warn.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
            'This bridge is ' + (meta.last_seen_age ? meta.last_seen_age : 'offline') +
            '. If you send now, the system will re-route to an online bridge automatically.';
        warn.style.display = '';
    }

    // Unit / person picker — currently a placeholder. Future: query
    // members with a comm_identifier for the target channel.
    function _composeLoadUnitPick(channel) {
        var sel = document.getElementById('composeUnitPick');
        var help = document.getElementById('composeRecipUnitHelp');
        if (!sel) return;
        sel.innerHTML = '<option value="">— select —</option>';
        if (help) {
            help.innerHTML = '<i class="bi bi-info-circle me-1"></i>Direct-by-person resolver not wired yet for ' +
                             _escHtml(channel) + '. For now use the Direct — ' +
                             (channel === 'aprs' ? 'callsign' :
                              (channel === 'smtp' ? 'email' :
                              (channel === 'sms' ? 'phone' : 'node search'))) +
                             ' option above.';
        }
    }

    function _composeUpdateCharCounter() {
        var sel = document.getElementById('composeChannel');
        var channel = (sel && sel.value) || 'inbox';
        var meta = CHANNEL_META[channel] || CHANNEL_META.inbox;
        var counter = document.getElementById('composeCharCounter');
        var bodyEl  = document.getElementById('composeBody');
        if (!counter || !bodyEl) return;
        if (!meta.charLimit) {
            counter.style.display = 'none';
            return;
        }
        var len = bodyEl.value.length;
        counter.textContent = len + meta.unitLabel;
        counter.style.display = '';
        // Color-cue when approaching / over the limit.
        counter.classList.remove('text-warning', 'text-danger');
        if (len > meta.charLimit) counter.classList.add('text-danger');
        else if (len > meta.charLimit * 0.9) counter.classList.add('text-warning');
    }

    // Lightweight HTML-escape for the Send-As label.
    function _escHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }

    // ─── Personnel picker autocomplete ─────────────────────────
    // Phase 99a-followup (2026-06-28). When the user is on an
    // external channel and types in the recipient field, hit
    // /api/messaging-personnel.php for matching members + show
    // a clickable dropdown. Picking a member appends their
    // address (comma-separated for multi-select).

    var _pickerDebounce = null;
    var _pickerHighlightIdx = -1;
    var _pickerItems = [];

    function _composeBindPersonnelPicker() {
        var input = document.getElementById('composeToText');
        var dd    = document.getElementById('composeToDropdown');
        if (!input || !dd) return;

        // Look up on every keystroke (debounced 200ms). Skip while
        // the user is typing inside the current incomplete
        // recipient — only the last segment after the rightmost
        // comma is the search query, since earlier ones are
        // already-picked addresses.
        input.addEventListener('input', function () {
            if (_pickerDebounce) clearTimeout(_pickerDebounce);
            _pickerDebounce = setTimeout(_pickerLookup, 200);
        });
        // Focus with empty query → show top-25 as a starter list.
        input.addEventListener('focus', function () {
            _pickerLookup();
        });
        // Keyboard nav: up/down to highlight, Enter to pick,
        // Esc to close.
        input.addEventListener('keydown', function (e) {
            if (dd.style.display === 'none') return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                _pickerHighlight(_pickerHighlightIdx + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                _pickerHighlight(_pickerHighlightIdx - 1);
            } else if (e.key === 'Enter') {
                if (_pickerHighlightIdx >= 0 && _pickerHighlightIdx < _pickerItems.length) {
                    e.preventDefault();
                    _pickerPick(_pickerItems[_pickerHighlightIdx]);
                }
            } else if (e.key === 'Escape') {
                _pickerHide();
            }
        });
        // Click-outside dismiss. (Listening on document with a
        // contains() check is the standard pattern for popovers
        // anchored to a specific input.)
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dd.contains(e.target)) {
                _pickerHide();
            }
        });
    }

    function _pickerCurrentQuery() {
        var input = document.getElementById('composeToText');
        if (!input) return '';
        // The query is whatever the user is typing AFTER the last comma.
        var raw = input.value;
        var idx = raw.lastIndexOf(',');
        return (idx < 0 ? raw : raw.substr(idx + 1)).trim();
    }

    function _pickerLookup() {
        var sel = document.getElementById('composeChannel');
        var channel = (sel && sel.value) || 'inbox';
        // Picker channels: smtp+sms search the member table; mesh
        // channels (#11 follow-on 2026-06-28) search mesh_nodes by
        // long_name/short_name so dispatchers don't have to type
        // raw '!hex' node IDs.
        if (channel !== 'smtp' && channel !== 'sms' &&
            channel !== 'meshtastic' && channel !== 'meshcore') {
            _pickerHide();
            return;
        }
        var q = _pickerCurrentQuery();
        var url = 'api/messaging-personnel.php?channel=' + encodeURIComponent(channel) +
                  '&q=' + encodeURIComponent(q);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    _pickerHide();
                    return;
                }
                _pickerRender(data.members || []);
            })
            .catch(function () { _pickerHide(); });
    }

    function _pickerRender(items) {
        var dd = document.getElementById('composeToDropdown');
        if (!dd) return;
        _pickerItems = items.slice(0, 10);  // cap visible to 10
        _pickerHighlightIdx = -1;
        if (_pickerItems.length === 0) {
            dd.innerHTML = '<div class="list-group-item small text-body-secondary py-1">No matching personnel.</div>';
            dd.style.display = '';
            return;
        }
        var html = '';
        _pickerItems.forEach(function (m, i) {
            html += '<a href="#" class="list-group-item list-group-item-action py-1 px-2 picker-item" data-idx="' + i + '">' +
                    '<div class="d-flex justify-content-between align-items-center">' +
                    '<span class="fw-semibold small">' + _escHtml(m.name) + '</span>' +
                    '<small class="text-body-secondary font-monospace">' + _escHtml(m.secondary) + '</small>' +
                    '</div>' +
                    '</a>';
        });
        if (items.length > _pickerItems.length) {
            html += '<div class="list-group-item small text-body-secondary py-1 text-center">' +
                    '+ ' + (items.length - _pickerItems.length) + ' more — refine search' +
                    '</div>';
        }
        dd.innerHTML = html;
        dd.style.display = '';
        // Bind clicks on each item.
        var els = dd.querySelectorAll('.picker-item');
        for (var i = 0; i < els.length; i++) {
            els[i].addEventListener('click', function (e) {
                e.preventDefault();
                var idx = parseInt(this.getAttribute('data-idx'), 10);
                if (_pickerItems[idx]) _pickerPick(_pickerItems[idx]);
            });
            els[i].addEventListener('mouseenter', function () {
                _pickerHighlight(parseInt(this.getAttribute('data-idx'), 10));
            });
        }
    }

    function _pickerHighlight(newIdx) {
        if (_pickerItems.length === 0) return;
        // Wrap-around.
        if (newIdx < 0) newIdx = _pickerItems.length - 1;
        if (newIdx >= _pickerItems.length) newIdx = 0;
        _pickerHighlightIdx = newIdx;
        var dd = document.getElementById('composeToDropdown');
        if (!dd) return;
        var items = dd.querySelectorAll('.picker-item');
        for (var i = 0; i < items.length; i++) {
            if (i === newIdx) items[i].classList.add('active');
            else items[i].classList.remove('active');
        }
    }

    function _pickerPick(member) {
        var input = document.getElementById('composeToText');
        if (!input || !member) return;
        // Replace the LAST segment (current query) with the
        // picked address, append ', ' so the user can keep typing
        // more recipients.
        var raw = input.value;
        var idx = raw.lastIndexOf(',');
        var prefix = (idx < 0) ? '' : raw.substr(0, idx + 1).replace(/\s+$/, '') + ' ';
        input.value = prefix + member.address + ', ';
        input.focus();
        _pickerHide();
    }

    function _pickerHide() {
        var dd = document.getElementById('composeToDropdown');
        if (dd) dd.style.display = 'none';
        _pickerHighlightIdx = -1;
        _pickerItems = [];
    }

    // ── Reply ────────────────────────────────────────────────────

    function replyToMessage() {
        // Get current message info from modal
        var subject    = document.getElementById('msgDetailTitle').textContent || '';
        var fromUserId = currentMsgFromUserId;

        // Switch to compose tab
        var composeTab = document.getElementById('tab-compose');
        if (composeTab) {
            var bsTab = bootstrap.Tab.getOrCreateInstance(composeTab);
            bsTab.show();
        }

        // A reply is an internal (inbox) message — make sure that channel is
        // active so the recipient multi-select (composeTo) is the shown control.
        var channelSel = document.getElementById('composeChannel');
        if (channelSel && channelSel.value !== 'inbox') {
            channelSel.value = 'inbox';
            try { channelSel.dispatchEvent(new Event('change')); } catch (e) {}
        }

        // Pre-fill subject
        if (subject && subject.indexOf('Re: ') !== 0) {
            subject = 'Re: ' + subject;
        }
        document.getElementById('composeSubject').value = subject;

        // GH #87 — pre-select the original sender as the recipient. Populate the
        // user list first (async on first use), then select the matching option.
        // The dispatcher can still add/remove recipients before sending.
        loadUsers(function () {
            var sel = document.getElementById('composeTo');
            if (sel && fromUserId) {
                for (var i = 0; i < sel.options.length; i++) {
                    sel.options[i].selected =
                        (parseInt(sel.options[i].value, 10) === fromUserId);
                }
            }
            document.getElementById('composeBody').focus();
        });

        // Close modal
        var modal = bootstrap.Modal.getInstance(document.getElementById('msgDetailModal'));
        if (modal) modal.hide();
    }

    // ── Delete ───────────────────────────────────────────────────

    function deleteMessage(msgId) {
        if (!confirm('Move this message to trash?')) return;

        apiPost('delete', { message_id: msgId }, function (err, data) {
            if (err || (data && data.error)) {
                alert('Failed to delete: ' + (data ? data.error : 'Network error'));
                return;
            }

            // Close modal
            var modal = bootstrap.Modal.getInstance(document.getElementById('msgDetailModal'));
            if (modal) modal.hide();

            // Refresh inbox
            loadInbox();
            loadUnreadCount();
        });
    }

    function bulkDelete() {
        var ids = Object.keys(selectedIds);
        if (ids.length === 0) return;
        if (!confirm('Delete ' + ids.length + ' selected message(s)?')) return;

        var intIds = [];
        for (var i = 0; i < ids.length; i++) {
            intIds.push(parseInt(ids[i], 10));
        }

        apiPost('bulk_delete', { message_ids: intIds }, function (err, data) {
            if (err || (data && data.error)) {
                alert('Failed to delete: ' + (data ? data.error : 'Network error'));
                return;
            }
            selectedIds = {};
            loadInbox();
            loadUnreadCount();
        });
    }

    // ── Unread Count ─────────────────────────────────────────────

    function loadUnreadCount() {
        apiGet('api/messaging.php?unread_count=1', function (err, data) {
            if (err || !data) return;
            updateUnreadBadges(data.unread_count || 0);
        });
    }

    function updateUnreadBadges(count) {
        // Tab badge
        var tabBadge = document.getElementById('inboxBadge');
        if (tabBadge) {
            if (count > 0) {
                tabBadge.textContent = count > 99 ? '99+' : count;
                tabBadge.classList.remove('d-none');
            } else {
                tabBadge.classList.add('d-none');
            }
        }

        // Navbar badge (global)
        var navBadge = document.getElementById('navMsgBadge');
        if (navBadge) {
            if (count > 0) {
                navBadge.textContent = count > 99 ? '99+' : count;
                navBadge.classList.remove('d-none');
            } else {
                navBadge.classList.add('d-none');
            }
        }
    }

    // ── HAS Broadcast ────────────────────────────────────────────

    function sendBroadcast() {
        var subject = document.getElementById('hasSubject').value.trim();
        var body    = document.getElementById('hasBody').value.trim();

        if (!body) {
            alert('Please enter a broadcast message.');
            document.getElementById('hasBody').focus();
            return;
        }

        if (!confirm('This will send an URGENT broadcast to ALL users. Continue?')) {
            return;
        }

        var sendBtn = document.getElementById('hasSendBtn');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Broadcasting...';

        apiPost('broadcast', {
            subject: subject,
            body:    body
        }, function (err, data) {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="bi bi-megaphone-fill me-1"></i>Broadcast to All Stations';

            if (err || (data && data.error)) {
                alert('Broadcast failed: ' + (data ? data.error : 'Network error'));
                return;
            }

            // Close modal and clear
            var modal = bootstrap.Modal.getInstance(document.getElementById('hasBroadcastModal'));
            if (modal) modal.hide();
            document.getElementById('hasBody').value = '';
            document.getElementById('hasSubject').value = 'HAS Broadcast';

            alert('Broadcast sent to ' + (data.recipients || 0) + ' user(s).');
        });
    }

    // ── Incoming HAS Alert (via SSE) ─────────────────────────────

    function showHasAlert(data) {
        var bodyEl = document.getElementById('hasAlertBody');
        var timeEl = document.getElementById('hasAlertTime');
        var toastEl = document.getElementById('hasAlertToast');

        if (!bodyEl || !toastEl) return;

        var fromName = data.from_name || 'Dispatch';
        bodyEl.innerHTML = '<strong>' + escapeHtml(fromName) + ':</strong> ' +
            escapeHtml(data.subject || 'HAS Broadcast') +
            '<hr class="my-1">' +
            '<div class="msg-detail-body">' + escapeHtml(data.body || '') + '</div>';
        timeEl.textContent = formatDate(new Date().toISOString());

        var toast = bootstrap.Toast.getOrCreateInstance(toastEl);
        toast.show();

        // Flash the page border for attention
        document.body.classList.add('has-alert-flash');
        setTimeout(function () {
            document.body.classList.remove('has-alert-flash');
        }, 6000);
    }

    // ── SSE Event Subscriptions ──────────────────────────────────

    function subscribeSSE() {
        if (typeof EventBus === 'undefined') return;

        // New message notification
        EventBus.on('message:new', function (data) {
            // Skip own messages
            if (data.from_user_id && typeof USER_ID !== 'undefined' &&
                parseInt(data.from_user_id, 10) === parseInt(USER_ID, 10)) return;

            // Check if this message is for current user
            var forMe = false;
            if (data.to_all) {
                forMe = true;
            } else if (data.to_users) {
                for (var i = 0; i < data.to_users.length; i++) {
                    if (parseInt(data.to_users[i], 10) === parseInt(USER_ID, 10)) {
                        forMe = true;
                        break;
                    }
                }
            }

            if (forMe) {
                loadUnreadCount();
                // Refresh inbox if currently viewing it
                var inboxPane = document.getElementById('pane-inbox');
                if (inboxPane && inboxPane.classList.contains('show')) {
                    loadInbox();
                }
            }
        });

        // HAS broadcast alert
        EventBus.on('message:broadcast', function (data) {
            // Skip own broadcasts
            if (data.from_user_id && typeof USER_ID !== 'undefined' &&
                parseInt(data.from_user_id, 10) === parseInt(USER_ID, 10)) return;

            showHasAlert(data);
            loadUnreadCount();

            // Play urgent tone
            if (typeof AudioAlerts !== 'undefined') {
                AudioAlerts.playTone('highSeverity');
            }

            // Refresh inbox if visible
            var inboxPane = document.getElementById('pane-inbox');
            if (inboxPane && inboxPane.classList.contains('show')) {
                loadInbox();
            }
        });
    }

    // ── Initialization ───────────────────────────────────────────

    function init() {
        // Load initial data
        loadInbox();
        loadUsers();
        loadUnreadCount();

        // Tab change listeners — load data when switching
        var sentTab = document.getElementById('tab-sent');
        if (sentTab) {
            sentTab.addEventListener('shown.bs.tab', function () {
                loadSent(1);
            });
        }

        var composeTab = document.getElementById('tab-compose');
        if (composeTab) {
            composeTab.addEventListener('shown.bs.tab', function () {
                loadUsers();
            });
        }

        var inboxTab = document.getElementById('tab-inbox');
        if (inboxTab) {
            inboxTab.addEventListener('shown.bs.tab', function () {
                loadInbox();
            });
        }

        // Compose form submit
        var composeForm = document.getElementById('composeForm');
        if (composeForm) {
            composeForm.addEventListener('submit', function (e) {
                e.preventDefault();
                sendMessage();
            });
        }

        // Compose clear button
        var clearBtn = document.getElementById('composeClear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                _composeClearForm();
            });
        }

        // Phase 99a-v2 (2026-06-28) — channel + send-to wiring.
        var channelSel = document.getElementById('composeChannel');
        if (channelSel) {
            _composeApplyChannel();  // initial paint
            channelSel.addEventListener('change', _composeApplyChannel);
        }
        var sendToSel = document.getElementById('composeSendTo');
        if (sendToSel) sendToSel.addEventListener('change', _composeApplySendTo);
        var bodyEl = document.getElementById('composeBody');
        if (bodyEl) bodyEl.addEventListener('input', _composeUpdateCharCounter);

        // Personnel/node autocomplete on the text recipient input.
        _composeBindPersonnelPicker();

        // Select all checkbox
        var selectAll = document.getElementById('inboxSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checks = document.querySelectorAll('.inbox-check');
                selectedIds = {};
                for (var i = 0; i < checks.length; i++) {
                    checks[i].checked = selectAll.checked;
                    if (selectAll.checked) {
                        selectedIds[checks[i].getAttribute('data-id')] = true;
                    }
                }
                updateBulkDeleteBtn();
            });
        }

        // Bulk delete button
        var bulkBtn = document.getElementById('inboxBulkDelete');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', bulkDelete);
        }
        var bulkReadBtn = document.getElementById('inboxBulkMarkRead');
        if (bulkReadBtn) {
            bulkReadBtn.addEventListener('click', bulkMarkRead);
        }

        // Message detail modal buttons
        var replyBtn = document.getElementById('msgReplyBtn');
        if (replyBtn) {
            replyBtn.addEventListener('click', replyToMessage);
        }

        var deleteBtn = document.getElementById('msgDeleteBtn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                if (currentMsgId) deleteMessage(currentMsgId);
            });
        }

        // HAS broadcast send button
        var hasSendBtn = document.getElementById('hasSendBtn');
        if (hasSendBtn) {
            hasSendBtn.addEventListener('click', sendBroadcast);
        }

        // Subscribe to SSE events
        subscribeSSE();

        // Poll unread count every 60 seconds as fallback
        setInterval(loadUnreadCount, 60000);
    }

    // ── Bootstrap ────────────────────────────────────────────────

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
