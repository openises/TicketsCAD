/**
 * Push client — Phase 96.
 *
 * Loaded on any page that wants the "Enable notifications" affordance.
 * Exposes window.TCADPush with these methods:
 *
 *   TCADPush.isSupported()    → boolean (browser supports Web Push)
 *   TCADPush.getPermission()  → 'granted' | 'denied' | 'default'
 *   TCADPush.enable()         → Promise<{ok, error?}> — registers SW + subscribes
 *   TCADPush.disable()        → Promise<{ok}> — unsubscribes + tells server
 *
 * On the page side, attach to a button:
 *   document.getElementById('btnEnablePush').addEventListener('click', function () {
 *     TCADPush.enable().then(function (r) { ... });
 *   });
 *
 * Permission requests MUST be triggered from a user gesture (click) —
 * browsers reject programmatic Notification.requestPermission() calls
 * outside that context.
 */

(function () {
    'use strict';

    function getCsrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function isSupported() {
        return 'serviceWorker' in navigator
            && 'PushManager' in window
            && 'Notification' in window;
    }

    function getPermission() {
        return ('Notification' in window) ? Notification.permission : 'default';
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = atob(base64);
        var output = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) output[i] = rawData.charCodeAt(i);
        return output;
    }

    function arrayBufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function fetchVapidPublicKey() {
        return fetch('/api/push-vapid-public-key.php')
            .then(function (r) {
                if (!r.ok) throw new Error('push_disabled_or_unconfigured');
                return r.json();
            })
            .then(function (data) {
                if (!data.ok || !data.public_key) {
                    throw new Error(data.error || 'no_public_key');
                }
                return data.public_key;
            });
    }

    /**
     * Enable push for this device.
     *
     * @param {Object} [opts]
     * @param {string} [opts.source]       'mobile' | 'desktop' — see
     *                                     api/push-subscribe.php for the
     *                                     default-filter rules. Mobile
     *                                     defaults to scope=assigned so
     *                                     field responders don't get the
     *                                     full dispatcher firehose.
     * @param {Object} [opts.filters_json] explicit override for the
     *                                     per-subscription filter
     *                                     (skips the source default).
     */
    function enable(opts) {
        opts = opts || {};
        if (!isSupported()) {
            return Promise.resolve({ ok: false, error: 'not_supported' });
        }

        // Step 1: register the service worker
        return navigator.serviceWorker.register('/sw.js')
            .then(function (registration) {
                // Step 2: request notification permission (must be from user gesture)
                return Notification.requestPermission().then(function (permission) {
                    if (permission !== 'granted') {
                        throw new Error('permission_' + permission);
                    }
                    return registration;
                });
            })
            .then(function (registration) {
                // Step 3: fetch the server's VAPID public key
                return fetchVapidPublicKey().then(function (publicKey) {
                    return { registration: registration, publicKey: publicKey };
                });
            })
            .then(function (ctx) {
                // Step 4: subscribe via PushManager
                return ctx.registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(ctx.publicKey),
                });
            })
            .then(function (subscription) {
                // Step 5: POST the subscription to the server
                return fetch('/api/push-subscribe.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        endpoint: subscription.endpoint,
                        keys: {
                            p256dh: arrayBufferToBase64Url(subscription.getKey('p256dh')),
                            auth:   arrayBufferToBase64Url(subscription.getKey('auth')),
                        },
                        device_label: navigator.platform || '',
                        // Phase 99t — source hint drives the default
                        // server-side filter. mobile.php passes 'mobile'.
                        source: opts.source || null,
                        filters_json: opts.filters_json || null,
                        csrf_token: getCsrf(),
                    }),
                }).then(function (r) { return r.json(); });
            })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'server_error');
                return { ok: true, subscription_id: data.subscription_id };
            })
            .catch(function (err) {
                return { ok: false, error: String(err.message || err) };
            });
    }

    function disable() {
        if (!isSupported()) return Promise.resolve({ ok: true });
        return navigator.serviceWorker.getRegistration('/sw.js')
            .then(function (registration) {
                if (!registration) return null;
                return registration.pushManager.getSubscription();
            })
            .then(function (subscription) {
                if (!subscription) return { ok: true };
                var endpoint = subscription.endpoint;
                return subscription.unsubscribe().then(function () {
                    return fetch('/api/push-subscribe.php', {
                        method: 'DELETE',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            endpoint: endpoint,
                            csrf_token: getCsrf(),
                        }),
                    });
                });
            })
            .then(function () { return { ok: true }; })
            .catch(function () { return { ok: true }; });  // best-effort
    }

    window.TCADPush = {
        isSupported:   isSupported,
        getPermission: getPermission,
        enable:        enable,
        disable:       disable,
    };
})();
