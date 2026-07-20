/**
 * Notification Tray — Visual History of Audio Alert Events
 *
 * Captures all SSE events that trigger audio alerts and displays them
 * in a dropdown tray so dispatchers can see what caused a sound.
 * Persists notifications in sessionStorage (survives page navigation
 * within the session but clears on tab close).
 *
 * ES5 only — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var MAX_NOTIFICATIONS = 50;
    var STORAGE_KEY = 'ticketsNotifyTray';
    var notifications = [];
    var unreadCount = 0;
    var listEl = null;
    var badgeEl = null;

    // Event type metadata: icon, color, label, link builder
    var EVENT_META = {
        'incident:new':      { icon: 'bi-exclamation-triangle-fill', color: 'text-warning',   label: 'New Incident' },
        'incident:update':   { icon: 'bi-pencil-square',             color: 'text-info',      label: 'Incident Updated' },
        'incident:close':    { icon: 'bi-check-circle',              color: 'text-success',   label: 'Incident Closed' },
        'incident:note':     { icon: 'bi-chat-left-text',            color: 'text-secondary', label: 'Incident Note' },
        'responder:status':  { icon: 'bi-arrow-repeat',              color: 'text-primary',   label: 'Status Change' },
        'responder:assign':  { icon: 'bi-person-check',              color: 'text-info',      label: 'Unit Assigned' },
        'facility:update':   { icon: 'bi-building',                  color: 'text-secondary', label: 'Facility Updated' },
        'chat:message':      { icon: 'bi-chat-dots',                 color: 'text-success',   label: 'Chat Message' },
        'message:new':       { icon: 'bi-envelope',                  color: 'text-primary',   label: 'New Message' },
        'message:broadcast': { icon: 'bi-megaphone-fill',            color: 'text-danger',    label: 'HAS Broadcast' },
        'geofence:enter':    { icon: 'bi-geo-alt-fill',              color: 'text-warning',   label: 'Geofence Enter' },
        'geofence:exit':     { icon: 'bi-geo-alt',                   color: 'text-secondary', label: 'Geofence Exit' },
        'unit:assignment':   { icon: 'bi-people',                    color: 'text-info',      label: 'Unit Assignment' },
        'weather:alert':     { icon: 'bi-cloud-lightning-rain-fill', color: 'text-danger',    label: 'Weather Alert' },
        'system:refresh':    { icon: 'bi-arrow-clockwise',           color: 'text-secondary', label: 'System Refresh' }
    };

    function init() {
        listEl = document.getElementById('notifyList');
        badgeEl = document.getElementById('navNotifyBadge');

        if (!listEl || !badgeEl) return;

        // Load persisted notifications
        loadFromStorage();
        render();

        // Subscribe to ALL SSE events that might trigger sounds
        if (typeof EventBus !== 'undefined') {
            EventBus.on('*', function (eventType, data) {
                // Only capture events we care about
                if (!EVENT_META[eventType]) return;

                // Skip events from current user (except geofence, weather, and
                // system events — always show those; weather has no origin user
                // and matters to everyone on the board).
                var alwaysShow = eventType.indexOf('geofence:') === 0 ||
                                 eventType.indexOf('weather:') === 0 ||
                                 eventType.indexOf('system:') === 0;
                if (!alwaysShow && data && data._origin_user && typeof USER_ID !== 'undefined' && data._origin_user === USER_ID) return;

                addNotification(eventType, data);
            });
        }

        // Clear button
        var clearBtn = document.getElementById('btnClearNotifications');
        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                clearAll();
            });
        }

        // Mark as read when dropdown opens
        var dropdownEl = document.getElementById('navNotifyBtn');
        if (dropdownEl) {
            dropdownEl.addEventListener('shown.bs.dropdown', function () {
                markAllRead();
            });
        }
    }

    function addNotification(eventType, data) {
        var meta = EVENT_META[eventType] || { icon: 'bi-bell', color: 'text-secondary', label: eventType };

        var entry = {
            id: Date.now() + '-' + Math.random().toString(36).substr(2, 5),
            type: eventType,
            icon: meta.icon,
            color: meta.color,
            label: meta.label,
            summary: buildSummary(eventType, data),
            link: buildLink(eventType, data),
            time: new Date().toISOString(),
            read: false,
            data: sanitizeData(data)
        };

        notifications.unshift(entry);

        // Trim to max
        if (notifications.length > MAX_NOTIFICATIONS) {
            notifications = notifications.slice(0, MAX_NOTIFICATIONS);
        }

        unreadCount++;
        saveToStorage();
        render();
    }

    function buildSummary(eventType, data) {
        if (!data) return '';

        switch (eventType) {
            case 'incident:new':
                return (data.scope || 'New incident') +
                       (data.address ? ' at ' + data.address : '') +
                       (data.severity !== undefined ? ' (sev ' + data.severity + ')' : '');
            case 'incident:update':
                // Phase 99p — show admin-configured case number; SSE
                // payload now carries `incident_number` alongside
                // the internal ticket_id.
                return 'Incident ' + (data.incident_number || ('#' + (data.ticket_id || '?'))) +
                       (data.fields_changed ? ' — ' + data.fields_changed.join(', ') : '');
            case 'incident:close':
                return 'Incident ' + (data.incident_number || ('#' + (data.ticket_id || '?'))) + ' — ' + (data.status_label || 'closed');
            case 'incident:note':
                return 'Note on incident ' + (data.incident_number || ('#' + (data.ticket_id || '?')));
            case 'responder:status':
                return (data.responder || 'Unit') + ' → ' + (data.new_status || '?');
            case 'responder:assign':
                return (data.responder || 'Unit') + ' assigned to incident ' + (data.incident_number || ('#' + (data.ticket_id || '?')));
            case 'chat:message':
                return (data.from_name || data.from || 'Someone') + ': ' + truncate(data.body || data.message || '', 60);
            case 'message:new':
                return (data.from_name || 'Message') + ': ' + truncate(data.subject || '', 60);
            case 'message:broadcast':
                return (data.from_name || 'BROADCAST') + ': ' + (data.subject || '');
            case 'geofence:enter':
            case 'geofence:exit':
                return (data.unit_identifier || 'Unit') + ' — ' + (data.fence_name || 'geofence');
            case 'weather:alert':
                return (data.event || 'Weather alert') + (data.area_desc ? ' — ' + truncate(data.area_desc, 50) : '');
            case 'unit:assignment':
                return (data.action || 'update') + ' — unit #' + (data.responder_id || '?');
            default:
                return eventType;
        }
    }

    function buildLink(eventType, data) {
        if (!data) return null;
        if (eventType.indexOf('incident') === 0 && data.ticket_id) {
            return 'incident-detail.php?id=' + data.ticket_id;
        }
        if (eventType.indexOf('responder') === 0 && data.responder_id) {
            return 'unit-detail.php?id=' + data.responder_id;
        }
        if (eventType === 'message:new' || eventType === 'message:broadcast') {
            return 'messaging.php';
        }
        if (eventType === 'chat:message') {
            // Chat notifications previously routed to messaging.php#chat, but
            // messaging.php is for internal messages and doesn't host the
            // chat widget. Chat lives on index.php via the .ctrl-btn
            // data-action="chat" button (issue #40 — a beta tester 2026-07-03).
            //
            // Route to index.php?openchat=1 so the dashboard opens with
            // the chat widget already visible. If we're already on
            // index.php, the click handler further down skips the
            // navigation and just clicks the local chat button.
            return 'index.php?openchat=1';
        }
        return null;
    }

    function sanitizeData(data) {
        // Keep only safe/small fields for storage
        if (!data) return null;
        var safe = {};
        var keep = ['ticket_id', 'responder_id', 'severity', 'scope', 'address',
                     'new_status', 'from_name', 'subject', 'action'];
        for (var i = 0; i < keep.length; i++) {
            if (data[keep[i]] !== undefined) safe[keep[i]] = data[keep[i]];
        }
        return safe;
    }

    function render() {
        if (!listEl) return;

        if (notifications.length === 0) {
            listEl.innerHTML =
                '<div class="text-center text-body-secondary py-4 small">' +
                '<i class="bi bi-bell-slash d-block mb-1" style="font-size:1.5rem;opacity:0.3"></i>' +
                'No recent notifications</div>';
        } else {
            var html = '';
            for (var i = 0; i < notifications.length; i++) {
                var n = notifications[i];
                var timeStr = formatTimeAgo(n.time);
                var readClass = n.read ? '' : ' bg-body-tertiary';
                var unreadDot = n.read ? '' : '<span class="position-absolute top-50 start-0 translate-middle-y ms-1" style="width:6px;height:6px;border-radius:50%;background:var(--bs-primary)"></span>';

                html += '<div class="notify-item position-relative px-3 py-2 border-bottom' + readClass + '"' +
                        (n.link ? ' style="cursor:pointer" data-link="' + esc(n.link) + '"' : '') + '>';
                html += unreadDot;
                html += '<div class="d-flex align-items-start gap-2" style="padding-left:10px">';
                html += '<i class="bi ' + esc(n.icon) + ' ' + esc(n.color) + ' mt-1 flex-shrink-0" style="font-size:0.9rem"></i>';
                html += '<div class="flex-grow-1 small">';
                html += '<div class="d-flex justify-content-between">';
                html += '<span class="fw-semibold">' + esc(n.label) + '</span>';
                html += '<span class="text-body-tertiary" style="font-size:0.7rem;white-space:nowrap;margin-left:8px">' + esc(timeStr) + '</span>';
                html += '</div>';
                html += '<div class="text-body-secondary" style="font-size:0.78rem;line-height:1.3">' + esc(n.summary) + '</div>';
                html += '</div></div></div>';
            }
            listEl.innerHTML = html;

            // Click to navigate. Special case for chat: if we're
            // already on the dashboard (index.php or root), don't do a
            // full navigation — just click the local chat button so
            // the widget opens inline (issue #40).
            var items = listEl.querySelectorAll('.notify-item[data-link]');
            for (var j = 0; j < items.length; j++) {
                items[j].addEventListener('click', function () {
                    var link = this.getAttribute('data-link');
                    if (!link) return;
                    if (link.indexOf('index.php?openchat=1') === 0) {
                        var onDash = /(?:^|\/)(?:index\.php)?(?:$|\?)/.test(window.location.pathname);
                        if (onDash) {
                            var chatBtn = document.querySelector('.ctrl-btn[data-action="chat"]');
                            if (chatBtn) { chatBtn.click(); return; }
                        }
                    }
                    window.location.href = link;
                });
            }
        }

        // Update badge
        updateBadge();
    }

    function updateBadge() {
        if (!badgeEl) return;
        // Count unread
        unreadCount = 0;
        for (var i = 0; i < notifications.length; i++) {
            if (!notifications[i].read) unreadCount++;
        }

        if (unreadCount > 0) {
            badgeEl.textContent = unreadCount > 99 ? '99+' : unreadCount;
            badgeEl.classList.remove('d-none');
        } else {
            badgeEl.classList.add('d-none');
        }
    }

    function markAllRead() {
        for (var i = 0; i < notifications.length; i++) {
            notifications[i].read = true;
        }
        unreadCount = 0;
        saveToStorage();
        render();
    }

    function clearAll() {
        notifications = [];
        unreadCount = 0;
        saveToStorage();
        render();
    }

    function formatTimeAgo(isoStr) {
        var d = new Date(isoStr);
        var now = new Date();
        var diffMs = now - d;
        var diffSec = Math.floor(diffMs / 1000);

        if (diffSec < 10) return 'just now';
        if (diffSec < 60) return diffSec + 's ago';
        var diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) return diffMin + 'm ago';
        var diffHr = Math.floor(diffMin / 60);
        if (diffHr < 24) return diffHr + 'h ago';
        // Show time for older
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return hours + ':' + mins;
    }

    function truncate(str, maxLen) {
        if (!str) return '';
        return str.length > maxLen ? str.substr(0, maxLen) + '...' : str;
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function saveToStorage() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                notifications: notifications,
                unreadCount: unreadCount
            }));
        } catch (e) {}
    }

    function loadFromStorage() {
        try {
            var stored = sessionStorage.getItem(STORAGE_KEY);
            if (stored) {
                var parsed = JSON.parse(stored);
                notifications = parsed.notifications || [];
                unreadCount = parsed.unreadCount || 0;
            }
        } catch (e) {}
    }

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
