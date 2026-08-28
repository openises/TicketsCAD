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
            // openises/TicketsCAD#29 (@rjonesbsink) — this used to blame a proxy
            // or firewall for every timeout. readyState 0 is CONNECTING: the
            // request was accepted and NO headers ever arrived, so there is no
            // HTTP status code at all. That is a different condition from a 502,
            // and the proxy/firewall story has no evidence behind it. The
            // reporter worked through proxy and firewall theories first because
            // that is what this text told him to check; the actual cause was the
            // server having no free worker to answer with.
            if (st === 0) {
                finish('warn', 'The live-update stream was accepted but never answered.',
                    'EventSource.readyState=0 (CONNECTING) after 9s — no headers arrived, so there is no HTTP status code to read. '
                    + 'That points at the server having no free slot to answer, rather than at a proxy or firewall (either of those would normally give you a status code or close the connection). '
                    + 'Check concurrent connection limits first: this page holds its own stream open on top of the one the navbar already runs, so a single tab needs two long-lived slots plus one for every ordinary request. '
                    + 'IIS on a Windows CLIENT edition is capped low regardless of configuration — 3 concurrent requests on Windows 11 Home — which two streams exhaust on their own; see docs/INSTALL-WINDOWS-IIS.md. '
                    + 'Also check PHP-FPM pm.max_children / IIS maxInstances. If the server has capacity to spare, then look at a proxy or firewall holding the long-lived connection.');
                return;
            }
            if (st === 1) {
                finish('warn', 'The live-update stream is open but has sent nothing yet.',
                    'EventSource.readyState=1 (OPEN) after 9s — headers arrived, so the server answered, but no event has come through. '
                    + 'Something between here and PHP is buffering the response: check output buffering / gzip on the stream, or a proxy that is not passing chunked output straight through.');
                return;
            }
            finish('warn', 'No response yet from the live-update stream.',
                'EventSource.readyState=' + st + ' after 9s. If it stays like this, a proxy or firewall may be blocking the long-lived connection.');
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
            f.enabled ? '' : 'An admin enables it under Settings → Web Push Notifications.');
        put('diagPush', f.vapid_configured ? 'ok' : 'bad',
            f.vapid_configured ? 'Server push keys (VAPID) are configured.' : 'Server push keys (VAPID) are missing.',
            f.vapid_configured ? '' : 'An admin generates them under Settings → Web Push Notifications.');
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
        // Phase 118: honest, always-visible connection-encryption indicator.
        // The browser authoritatively knows the scheme (no proxy ambiguity).
        var secure = window.location.protocol === 'https:';
        put('diagEnv', secure ? 'ok' : 'warn',
            'Connection encrypted (HTTPS): ' + (secure ? 'yes' : 'NO — served over plain HTTP'),
            secure ? '' : 'Traffic between this browser and the server is not encrypted in transit. See docs/HTTPS-SETUP.md to enable HTTPS.');
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
                : 'Start the Zello proxy — on Linux: systemd service newui-zello-proxy, or proxy/start-proxy.sh. On Windows: proxy/start-proxy.bat (or proxy/start-proxy-service.bat to run it as a service; see docs/INSTALL-WINDOWS-IIS.md). Until it runs, the widget can never stay connected.');
        put('diagZello', f.creds_present ? 'ok' : 'warn',
            f.creds_present ? 'Zello credentials are configured.'
                : 'Zello username + password/token not fully set (Settings → Communications & Integrations → Zello).', '');
        put('diagZello', f.channel_present ? 'ok' : 'warn',
            f.channel_present ? 'A Zello channel / network is configured.'
                : 'No Zello channel/network set (Settings → Communications & Integrations → Zello).', '');

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
            // GH#117 (2026-08-28) — this URL is only reverse-proxied at all
            // when it's wss:// (leg 1 already told us whether the daemon
            // itself is listening). A direct ws://host:port connection, the
            // common shape on a plain-HTTP or LAN-only install, has no
            // reverse proxy in the path — a closed-before-open here just
            // means nothing answered on that port, which leg 1 already
            // covers; suggesting a web-server misconfiguration in that case
            // sends the reader looking for something that cannot exist.
            var detail = isHttps
                ? ('The WebSocket to ' + url + ' closed (code ' + (ev && ev.code) + ') before it opened. On HTTPS this almost always means the web server is not reverse-proxying ' + wsPath + ' to the proxy. Apache needs mod_proxy_wstunnel enabled and a `<Location ' + wsPath + '>` that ProxyPasses to `ws://127.0.0.1:' + f.proxy_port + '/`. That mismatch is the usual cause of the widget connecting then dropping in a loop while the proxy log stays clean.')
                : ('The WebSocket to ' + url + ' closed (code ' + (ev && ev.code) + ') before it opened. This is a direct connection with no reverse proxy in the path, so this almost always means nothing is listening on port ' + f.proxy_port + ' yet, or a firewall is blocking it — see the check above.');
            finish('bad', 'The radio connection could NOT be established from this browser.', detail);
        };
        setTimeout(function () {
            if (done) { return; }
            // openises/TicketsCAD#29 — same correction as the SSE check above:
            // readyState 0 means nothing came back at all, which a saturated
            // web server produces just as readily as a proxy does.
            finish('warn', 'No response yet from the radio connection.',
                'readyState=' + (sock ? sock.readyState : -1) + ' after 8s with no reply from ' + url + '. '
                + 'Either a proxy/firewall is blocking the WebSocket upgrade, or the web server has no free slot to handle it — '
                + 'if the live-update check above also timed out at readyState=0, suspect concurrency before the network.');
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
