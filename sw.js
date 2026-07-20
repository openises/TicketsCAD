/**
 * Service worker — Phase 96 Web Push.
 *
 * Receives encrypted push messages from FCM/APNs, decrypts them via
 * the browser's built-in Web Push crypto (the SW doesn't see the
 * private key — only the browser knows it), and displays the
 * notification in the OS tray.
 *
 * On click, opens the URL the notification carried (incident detail,
 * unit detail, etc.).
 *
 * Lives at the web root (NOT under /assets/) so its scope can be
 * '/' — service workers can only control pages within their own
 * directory scope.
 */

'use strict';

// On install, take over immediately (no waiting for old SW to expire).
self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

// Push event — fired when FCM/APNs delivers a push to this browser.
self.addEventListener('push', function (event) {
    var payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch (e) {
        // Fallback if server sent plain text
        payload = { title: 'TicketsCAD', body: event.data ? event.data.text() : '' };
    }

    var title = payload.title || 'TicketsCAD';
    var options = {
        body:  payload.body || '',
        icon:  '/assets/icons/icon-192.png',
        badge: '/assets/icons/badge-72.png',
        tag:   payload.tag || 'tcad-notification',
        renotify: true,         // re-alert even if tag matches
        requireInteraction: false,
        data: payload.data || { url: payload.url || '/' },
    };

    // Pull URL out into data so the click handler can find it.
    if (payload.url) options.data.url = payload.url;

    event.waitUntil(self.registration.showNotification(title, options));
});

// Click on notification — focus / open the relevant page.
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var targetUrl = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            // If a TicketsCAD tab is already open, focus it + navigate
            for (var i = 0; i < clientList.length; i++) {
                var c = clientList[i];
                if ('focus' in c) {
                    c.focus();
                    if ('navigate' in c && targetUrl !== '/') {
                        c.navigate(targetUrl);
                    }
                    return;
                }
            }
            // Otherwise open a new window
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

// Subscription change — re-register if the push service rotates the
// subscription (rare but possible per the Web Push spec).
self.addEventListener('pushsubscriptionchange', function (event) {
    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: event.oldSubscription
                ? event.oldSubscription.options.applicationServerKey
                : undefined,
        }).then(function (newSub) {
            // Best-effort re-POST to the server. May fail if user
            // session expired; that's fine, they'll re-subscribe next
            // time they visit.
            return fetch('/api/push-subscribe.php', {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    endpoint: newSub.endpoint,
                    keys: {
                        p256dh: arrayBufferToBase64Url(newSub.getKey('p256dh')),
                        auth:   arrayBufferToBase64Url(newSub.getKey('auth')),
                    },
                    csrf_token: '', // best-effort; server may reject
                }),
            }).catch(function () {});
        })
    );
});

function arrayBufferToBase64Url(buffer) {
    var bytes = new Uint8Array(buffer);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
