/**
 * Self-service Diagnostics (GH #8 / #13 tester assist). Runs live client-side
 * tests IN the tester's browser: does the SSE stream connect, does Web Push
 * work on this device. ES5, no build step.
 */
(function () {
    'use strict';

    var CSRF = window.DIAG_CSRF || '';
    var facts = null;
    var lines = [];   // plain-text accumulation for "Copy report"

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return (m && m.content) || CSRF;
    }

    var ICON = { ok: 'check-circle-fill', warn: 'exclamation-triangle-fill',
                 bad: 'x-circle-fill', pend: 'hourglass-split' };
    var MARK = { ok: '[OK]  ', warn: '[WARN]', bad: '[FAIL]', pend: '[..]  ' };

    // Build a row safely (label/detail via textContent — no HTML injection).
    function mkRow(state, label, detail) {
        var d = document.createElement('div');
        d.className = 'diag-row';
        var ic = document.createElement('span');
        ic.className = 'diag-ico diag-' + state;
        ic.innerHTML = '<i class="bi bi-' + ICON[state] + '"></i>';
        d.appendChild(ic);
        var body = document.createElement('span');
        var lab = document.createElement('span');
        lab.className = 'diag-label';
        lab.textContent = label;
        body.appendChild(lab);
        if (detail) {
            var det = document.createElement('div');
            det.className = 'diag-detail';
            det.textContent = detail;
            body.appendChild(det);
        }
        d.appendChild(body);
        lines.push(MARK[state] + ' ' + label + (detail ? '  — ' + detail : ''));
        return d;
    }
    function put(id, state, label, detail) {
        document.getElementById(id).appendChild(mkRow(state, label, detail));
    }
    function clear(id) { document.getElementById(id).innerHTML = ''; }

    // ── Real-time (SSE) ──
    function testSse() {
        clear('diagSse');
        lines.push('--- Real-time updates ---');
        var box = document.getElementById('diagSse');

        // The app-wide EventBus state (what the dashboard actually rides on).
        if (window.EventBus && typeof window.EventBus.isSSEConnected === 'function') {
            var on = window.EventBus.isSSEConnected();
            box.appendChild(mkRow(on ? 'ok' : 'warn',
                on ? 'The app\'s live-update connection is active.'
                   : 'The app\'s live-update connection is not active yet.',
                on ? '' : 'It may still be connecting; the independent test below is the definitive check.'));
        }

        if (typeof EventSource === 'undefined') {
            box.appendChild(mkRow('bad', 'This browser has no live-update support (EventSource).',
                'Real-time refresh cannot work here — use a current Chrome, Firefox, Edge, or Safari.'));
            return;
        }
        var pending = mkRow('pend', 'Opening the live-update stream (api/stream.php)…', '');
        box.appendChild(pending);
        var es, done = false, t0 = Date.now();
        function finish(state, label, detail) {
            if (done) return; done = true;
            // Replace the pending row's contents in place.
            var repl = mkRow(state, label, detail);
            box.replaceChild(repl, pending);
            try { if (es) es.close(); } catch (e) {}
        }
        try { es = new EventSource('api/stream.php'); }
        catch (e) { finish('bad', 'The browser refused to open the live-update stream.', String(e)); return; }

        es.addEventListener('connected', function () {
            finish('ok', 'Live updates are connected.',
                'The stream opened in ' + (Date.now() - t0) + ' ms — real-time refresh should work here.');
        });
        es.addEventListener('ping', function () {
            finish('ok', 'Live updates are connected.', 'Keepalive received — the stream is open and flowing.');
        });
        es.onerror = function () {
            if (done) return;
            finish('bad', 'The live-update stream is NOT connecting on this device.',
                'The browser opened api/stream.php but it errored before streaming. Usually a network/proxy/VPN between you and the server is closing the long-lived connection, or your session expired. Open the browser Console (F12) and look for a red error mentioning stream.php or Content-Security-Policy.');
        };
        setTimeout(function () {
            if (done) return;
            var st = es ? es.readyState : -1;
            finish('warn', 'No response yet from the live-update stream.',
                'EventSource.readyState=' + st + ' after 9s. If it stays like this, a proxy or firewall is likely blocking the long-lived connection.');
        }, 9000);
    }

    // ── Push notifications ──
    function testPush() {
        clear('diagPush');
        lines.push('--- Push notifications ---');
        var box = document.getElementById('diagPush');
        var btn = document.getElementById('diagPushTest');
        var f = (facts && facts.push) || {};

        put('diagPush', f.enabled ? 'ok' : 'bad',
            f.enabled ? 'Push is enabled on the server.' : 'Push is turned OFF on the server.',
            f.enabled ? '' : 'An admin enables it under Settings → Notifications.');
        put('diagPush', f.vapid_configured ? 'ok' : 'bad',
            f.vapid_configured ? 'Server push keys (VAPID) are configured.' : 'Server push keys (VAPID) are missing.',
            f.vapid_configured ? '' : 'An admin generates them under Settings → Notifications.');
        if (f.routes && f.routes.length) {
            var routeTxt = f.routes.map(function (r) { return (r.enabled ? '✓ ' : '✗ ') + r.name; }).join('   ');
            put('diagPush', f.any_enabled_route ? 'ok' : 'warn',
                (f.any_enabled_route ? 'A push delivery route is enabled.' : 'No push delivery route is enabled.'),
                routeTxt);
        }

        var supported = window.TCADPush && window.TCADPush.isSupported();
        put('diagPush', supported ? 'ok' : 'bad',
            supported ? 'This browser supports Web Push.' : 'This browser/device can\'t receive Web Push here.',
            supported ? '' : 'On iPhone/iPad, Web Push only works when the app has been Added to the Home Screen (iOS 16.4+). In a normal Safari tab it will not work — that is an Apple limitation, not a bug.');

        var perm = window.TCADPush ? window.TCADPush.getPermission() : 'default';
        put('diagPush', perm === 'granted' ? 'ok' : (perm === 'denied' ? 'bad' : 'warn'),
            'Notification permission: ' + perm,
            perm === 'denied' ? 'Notifications are blocked for this site — re-enable them in the browser/site settings, then reload.'
                : (perm === 'default' ? 'Not requested yet — use the button above to enable and test.' : ''));

        var live = f.my_live_subscriptions || 0;
        put('diagPush', live > 0 ? 'ok' : 'warn',
            live > 0 ? ('Your account has ' + live + ' active push subscription(s).')
                     : 'This device is not subscribed to push yet.',
            live > 0 ? '' : 'Tap "Send a test to this device" above to subscribe and confirm delivery.');

        if (supported && f.enabled && f.vapid_configured) { btn.classList.remove('d-none'); }
        else { btn.classList.add('d-none'); }
    }

    function sendTestPush() {
        var btn = document.getElementById('diagPushTest');
        var box = document.getElementById('diagPush');
        btn.disabled = true;
        var status = mkRow('pend', 'Enabling push on this device…', 'Registering the service worker and asking permission.');
        box.appendChild(status);
        function replace(node, el) { box.replaceChild(el, node); return el; }

        if (!window.TCADPush) { replace(status, mkRow('bad', 'Push client not loaded.', '')); btn.disabled = false; return; }

        window.TCADPush.enable().then(function (res) {
            if (!res || !res.ok) {
                replace(status, mkRow('bad', 'Could not subscribe this device to push.', (res && res.error) || 'unknown'));
                btn.disabled = false; return;
            }
            var s2 = replace(status, mkRow('pend', 'Subscribed. Sending a test notification…', ''));
            fetch('api/diagnostics.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'push_test', csrf_token: csrf() })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) {
                    replace(s2, mkRow('ok', 'Test notification sent — watch for it now.',
                        'If it appears (even with the app in the background), Web Push works end to end on this device.'));
                } else {
                    replace(s2, mkRow('bad', 'The server could not deliver the test push.',
                        (d && (d.error || (d.errors && d.errors.join('; ')))) || 'unknown'));
                }
                btn.disabled = false;
            }).catch(function (e) {
                replace(s2, mkRow('bad', 'Network error sending the test push.', String(e))); btn.disabled = false;
            });
        }).catch(function (e) {
            replace(status, mkRow('bad', 'Enabling push failed.', String(e))); btn.disabled = false;
        });
    }

    // ── Environment ──
    function renderEnv() {
        clear('diagEnv');
        lines.push('--- This device & browser ---');
        var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
            || window.navigator.standalone === true;
        put('diagEnv', 'ok', 'Installed as an app (home-screen PWA): ' + (standalone ? 'yes' : 'no'),
            standalone ? '' : 'On iPhone, push requires the installed PWA — Share → Add to Home Screen, then open it from the icon.');
        put('diagEnv', navigator.onLine ? 'ok' : 'bad', 'Network: ' + (navigator.onLine ? 'online' : 'offline'), '');
        put('diagEnv', 'ok', 'Service Worker support: ' + ('serviceWorker' in navigator ? 'yes' : 'no'), '');
        put('diagEnv', 'ok', 'Browser', navigator.userAgent);
        put('diagEnv', 'ok', 'Page URL', window.location.href);
    }

    // ── Server facts ──
    function loadServer() {
        clear('diagServer');
        return fetch('api/diagnostics.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                facts = d;
                lines.push('--- Server settings ---');
                put('diagServer', 'ok', 'Server time (UTC)', (d && d.server_time) || '?');
                put('diagServer', 'ok', 'Your user id', String((d && d.user_id) || '?'));
                if (d && d.push) {
                    put('diagServer', d.push.library_loaded ? 'ok' : 'warn',
                        'Web Push library: ' + (d.push.library_loaded ? 'loaded' : 'not detected'), '');
                }
            })
            .catch(function (e) {
                put('diagServer', 'bad', 'Could not load server settings.', String(e));
            });
    }

    // ── Radio (Zello) connection (GH task #67 — widget "flapping") ──
    // Isolates WHICH leg drops: (1) is the proxy daemon listening on the server,
    // and (2) can THIS browser reach it through the web server's WebSocket
    // reverse-proxy. A flap is almost always leg 2 failing on HTTPS (Apache not
    // proxying /zello-ws) while leg 1 looks healthy — which is exactly why the
    // systemd proxy logs stay clean.
    function testZello() {
        var f = (facts && facts.zello) || {};
        var card = document.getElementById('diagZelloCard');
        if (!f.configured) { if (card) { card.style.display = 'none'; } return; }
        if (card) { card.style.display = ''; }
        clear('diagZello');
        lines.push('--- Radio (Zello) connection ---');

        // Leg 1 — is the proxy daemon accepting connections on the server?
        put('diagZello', f.daemon_listening ? 'ok' : 'bad',
            f.daemon_listening
                ? ('The radio proxy service is running (port ' + f.proxy_port + ')'
                    + (f.daemon_uptime_s ? ', up ' + Math.round(f.daemon_uptime_s / 60) + ' min.' : '.'))
                : ('The radio proxy service is NOT listening on port ' + f.proxy_port + '.'),
            f.daemon_listening ? ''
                : 'Start the Zello proxy (systemd service newui-zello-proxy, or proxy/start-proxy.sh). Until it runs, the widget can never stay connected.');
        put('diagZello', f.creds_present ? 'ok' : 'warn',
            f.creds_present ? 'Zello credentials are configured.'
                : 'Zello username + password/token not fully set (Settings → Communications → Zello).', '');
        put('diagZello', f.channel_present ? 'ok' : 'warn',
            f.channel_present ? 'A Zello channel / network is configured.'
                : 'No Zello channel/network set (Settings → Communications → Zello).', '');

        // Leg 2 — can this browser reach the proxy through the web server? THE flap culprit.
        var isHttps = window.location.protocol === 'https:';
        var wsPath = f.ws_path || '/zello-ws';
        var url = isHttps ? ('wss://' + window.location.host + wsPath)
                          : ('ws://' + window.location.hostname + ':' + f.proxy_port);
        var box = document.getElementById('diagZello');
        var pending = mkRow('pend', 'Opening the radio connection (' + url + ')…', '');
        box.appendChild(pending);
        var done = false, t0 = Date.now(), sock;
        function finish(state, label, detail) {
            if (done) { return; } done = true;
            box.replaceChild(mkRow(state, label, detail), pending);
            try { if (sock) sock.close(); } catch (e) {}
        }
        try { sock = new WebSocket(url); }
        catch (e) { finish('bad', 'The browser refused to open the radio connection.', String(e)); return; }
        sock.onopen = function () {
            finish('ok', 'This browser reached the radio proxy through the web server.',
                'The WebSocket upgrade succeeded in ' + (Date.now() - t0) + ' ms. If the widget still flaps, the drop is between the proxy and Zello (login/kick/rate-limit) — check the proxy log and your Zello credentials + channel name.');
        };
        sock.onclose = function (ev) {
            if (done) { return; }
            finish('bad', 'The radio connection could NOT be established from this browser.',
                'The WebSocket to ' + url + ' closed (code ' + (ev && ev.code) + ') before it opened. On HTTPS this almost always means the web server is not reverse-proxying ' + wsPath + ' to the proxy. Apache needs mod_proxy_wstunnel enabled and a `<Location ' + wsPath + '>` that ProxyPasses to `ws://127.0.0.1:' + f.proxy_port + '/`. That mismatch is the usual cause of the widget connecting then dropping in a loop while the proxy log stays clean.');
        };
        setTimeout(function () {
            if (done) { return; }
            finish('warn', 'No response yet from the radio connection.',
                'readyState=' + (sock ? sock.readyState : -1) + ' after 8s — a proxy/firewall is likely blocking the WebSocket upgrade to ' + url + '.');
        }, 8000);
    }

    function runAll() {
        lines = [];
        lines.push('TicketsCAD diagnostics — ' + new Date().toString());
        renderEnv();
        testSse();
        loadServer().then(function () { testPush(); testZello(); });
    }

    function init() {
        document.getElementById('diagRerun').addEventListener('click', runAll);
        document.getElementById('diagPushTest').addEventListener('click', sendTestPush);
        document.getElementById('diagCopy').addEventListener('click', function () {
            var txt = lines.join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(txt).then(function () {
                    this && (this.textContent = ' Copied');
                }.bind(this)).catch(function () { window.prompt('Copy the report:', txt); });
            } else { window.prompt('Copy the report:', txt); }
        });
        runAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else { init(); }
})();
