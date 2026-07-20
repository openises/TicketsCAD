/**
 * NewUI v4.0 - Event Bus with SSE Real-Time Support
 *
 * Lightweight pub/sub for inter-widget communication.
 * Optionally connects to api/stream.php via Server-Sent Events
 * for real-time updates from the server.
 *
 * Local events:   EventBus.emit('widget:refresh', { id: 3 })
 * SSE events:     EventBus.on('incident:new', function (data) { ... })
 * Wildcard:       EventBus.on('*', function (type, data) { ... })
 */
var EventBus = (function () {
    'use strict';

    var target = new EventTarget();
    var wildcardListeners = [];
    var sseSource = null;
    var sseLastId = 0;
    var sseConnected = false;
    var sseReconnectTimer = null;
    var sseAttempts = 0;
    var sseBaseDelay = 3000;
    var sseMaxDelay = 30000;

    // SSE event types the server can send
    var SSE_TYPES = [
        'incident:new', 'incident:update', 'incident:close', 'incident:note',
        'responder:status', 'responder:assign',
        'facility:update',
        'chat:message',
        'message:new', 'message:broadcast',
        'system:refresh'
    ];

    function parseJSON(str) {
        try { return JSON.parse(str); } catch (e) { return {}; }
    }

    function dispatch(eventType, data) {
        target.dispatchEvent(new CustomEvent(eventType, { detail: data }));
        for (var i = 0; i < wildcardListeners.length; i++) {
            try { wildcardListeners[i](eventType, data); } catch (e) { /* ignore */ }
        }
    }

    // ── SSE Connection ──

    function sseConnect() {
        if (sseSource && sseSource.readyState !== EventSource.CLOSED) return;
        if (typeof EventSource === 'undefined') return; // Browser doesn't support SSE

        var url = 'api/stream.php';
        if (sseLastId > 0) url += '?last_id=' + sseLastId;

        try {
            sseSource = new EventSource(url);
        } catch (e) {
            sseScheduleReconnect();
            return;
        }

        sseSource.addEventListener('connected', function (e) {
            var data = parseJSON(e.data);
            sseConnected = true;
            sseAttempts = 0;
            if (data.last_id) sseLastId = data.last_id;
            dispatch('sse:connected', data);
        });

        sseSource.addEventListener('reconnect', function () {
            sseSource.close();
            sseScheduleReconnect();
        });

        sseSource.addEventListener('ping', function () {
            // Keepalive — no action needed
        });

        // Register all SSE event types
        for (var i = 0; i < SSE_TYPES.length; i++) {
            (function (type) {
                sseSource.addEventListener(type, function (e) {
                    var data = parseJSON(e.data);
                    if (data._event_id) sseLastId = data._event_id;
                    dispatch(type, data);
                });
            })(SSE_TYPES[i]);
        }

        sseSource.onerror = function () {
            sseConnected = false;
            sseSource.close();
            dispatch('sse:disconnected', { attempts: sseAttempts });
            sseScheduleReconnect();
        };
    }

    function sseScheduleReconnect() {
        if (sseReconnectTimer) return;
        sseAttempts++;
        // After many failures, switch to slow polling instead of giving up entirely
        if (sseAttempts > 20) {
            dispatch('sse:offline', { attempts: sseAttempts });
            // Still try again every 60 seconds — don't give up permanently
            sseReconnectTimer = setTimeout(function () {
                sseReconnectTimer = null;
                sseAttempts = 0; // Reset counter to try a fresh burst
                sseConnect();
            }, 60000);
            return;
        }
        var delay = Math.min(sseBaseDelay * Math.pow(1.5, sseAttempts - 1), sseMaxDelay);
        var jitter = Math.floor(Math.random() * 1000);
        sseReconnectTimer = setTimeout(function () {
            sseReconnectTimer = null;
            sseConnect();
        }, delay + jitter);
    }

    function sseDisconnect() {
        if (sseReconnectTimer) {
            clearTimeout(sseReconnectTimer);
            sseReconnectTimer = null;
        }
        if (sseSource) {
            sseSource.close();
            sseSource = null;
        }
        sseConnected = false;
    }

    // ── Public API ──

    var bus = {
        /**
         * Listen for an event. Use '*' for wildcard (callback gets type, data).
         */
        on: function (event, callback) {
            if (event === '*') {
                wildcardListeners.push(callback);
                return;
            }
            target.addEventListener(event, function (e) {
                callback(e.detail);
            });
        },

        /**
         * Emit a local event (does NOT send to server).
         */
        emit: function (event, data) {
            dispatch(event, data);
        },

        /**
         * Remove a listener.
         */
        off: function (event, callback) {
            if (event === '*') {
                for (var i = wildcardListeners.length - 1; i >= 0; i--) {
                    if (wildcardListeners[i] === callback) wildcardListeners.splice(i, 1);
                }
                return;
            }
            target.removeEventListener(event, callback);
        },

        /**
         * Start SSE connection for real-time server events.
         */
        connectSSE: sseConnect,

        /**
         * Stop SSE connection.
         */
        disconnectSSE: sseDisconnect,

        /**
         * Check SSE connection status.
         */
        isSSEConnected: function () { return sseConnected; }
    };

    // Auto-connect SSE after page loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(sseConnect, 2000);
        });
    } else {
        setTimeout(sseConnect, 2000);
    }

    // Clean disconnect on page unload
    window.addEventListener('beforeunload', sseDisconnect);

    return bus;
})();
