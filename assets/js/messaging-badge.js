/**
 * NewUI v4.0 - Messaging Badge (Global)
 *
 * Lightweight script to update the navbar unread message badge on ALL pages.
 * Include this on every page that has the navbar. It polls the unread count
 * and subscribes to SSE events for real-time updates.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var pollInterval = 60000; // 60 seconds
    var timer = null;

    function updateBadge(count) {
        var badge = document.getElementById('navMsgBadge');
        if (!badge) return;

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.remove('d-none');
        } else {
            badge.classList.add('d-none');
        }
    }

    function fetchCount() {
        fetch('api/messaging.php?unread_count=1', { credentials: 'same-origin' })
            .then(function (res) {
                if (res.status === 401) return null;
                return res.json();
            })
            .then(function (data) {
                if (data && typeof data.unread_count !== 'undefined') {
                    updateBadge(data.unread_count);
                }
            })
            .catch(function () { /* silent */ });
    }

    function init() {
        // Initial fetch
        fetchCount();

        // Poll as fallback
        timer = setInterval(fetchCount, pollInterval);

        // SSE real-time updates
        if (typeof EventBus !== 'undefined') {
            EventBus.on('message:new', function (data) {
                // Skip own messages
                if (data.from_user_id && typeof USER_ID !== 'undefined' &&
                    parseInt(data.from_user_id, 10) === parseInt(USER_ID, 10)) return;
                fetchCount();
            });

            EventBus.on('message:broadcast', function (data) {
                if (data.from_user_id && typeof USER_ID !== 'undefined' &&
                    parseInt(data.from_user_id, 10) === parseInt(USER_ID, 10)) return;
                fetchCount();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
