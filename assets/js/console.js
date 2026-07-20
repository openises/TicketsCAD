/**
 * NewUI v4.0 — Communications Console (Phase 114b, slices b1+b2)
 *
 * b1: one strip per enabled registry channel (api/channels.php) with
 *     status LED, last-caller line, voice strips bound to today's
 *     Zello/Radio widget backends, text strips with feed drawer + send.
 * b2: named views as tabs (api/console-views.php). A designer-authored
 *     view picks WHICH channels appear, in what order, with per-strip
 *     overrides (label, colours, width) and an explicit control list.
 *     The built-in "All Channels" tab remains as the auto-generated
 *     fallback and is always available.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var API = 'api/channels.php';
    var VIEWS_API = 'api/console-views.php';
    var REFRESH_MS = 15000;      // strip status refresh
    var PROBE_EVERY = 4;         // probe (heavier) every Nth refresh
    var FEED_MS = 10000;         // open-drawer feed refresh
    var TAB_KEY = 'newui_console_active_view';

    var bank = document.getElementById('consoleBank');
    if (!bank) { return; }
    var tabBar = document.getElementById('consoleTabs');

    var canTx   = document.body.getAttribute('data-can-tx') === '1';
    var canSend = document.body.getAttribute('data-can-send') === '1';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var channels = [];           // last fetched channel list (enabled only)
    var channelsById = {};
    var views = [];              // shared views from the designer
    var activeView = 'auto';     // 'auto' or a view id (string)
    var openFeeds = {};          // channelId -> feed element while drawer open
    var refreshCount = 0;

    try { activeView = localStorage.getItem(TAB_KEY) || 'auto'; } catch (e) {}

    // ── Helpers ──────────────────────────────────────────────────
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function relTime(mysqlDt) {
        if (!mysqlDt) { return ''; }
        var t = new Date(String(mysqlDt).replace(' ', 'T'));
        if (isNaN(t.getTime())) { return mysqlDt; }
        var s = Math.floor((Date.now() - t.getTime()) / 1000);
        if (s < 0) { s = 0; }
        if (s < 60) { return s + 's ago'; }
        if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
        if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
        return Math.floor(s / 86400) + 'd ago';
    }

    function adapterIcon(adapter) {
        var map = {
            zello: 'bi-mic-fill', dmr_bm: 'bi-broadcast', dmr_local: 'bi-broadcast',
            mesh: 'bi-diagram-3', meshcore: 'bi-diagram-3', aprs: 'bi-geo-alt',
            local_chat: 'bi-chat-dots', smtp: 'bi-envelope', sms: 'bi-phone',
            slack: 'bi-slack', push: 'bi-bell', nws: 'bi-cloud-lightning-rain',
            eventbus: 'bi-lightning-charge', allstar: 'bi-broadcast-pin',
            sip: 'bi-telephone', intercom: 'bi-door-open', ptt1: 'bi-mic'
        };
        return map[adapter] || 'bi-broadcast-pin';
    }

    // Default control list for a channel with no designer config —
    // everything the channel is capable of (b1 behavior).
    function defaultControls(caps) {
        var out = ['activity'];
        if (caps.voice_rx || caps.voice_tx) { out.push('voice'); }
        if (caps.text_rx || caps.text_tx || caps.source) { out.push('text'); }
        return out;
    }

    // ── Strip rendering ──────────────────────────────────────────
    // cfg (optional, from a designer view): {overrides:{label,short_label,
    // color,ptt_color,ptt_mode}, controls:[...], width:1|2}
    function renderStrip(ch, cfg) {
        var ov = (cfg && cfg.overrides) || {};
        var controls = (cfg && cfg.controls && cfg.controls.length)
            ? cfg.controls : defaultControls(ch.capabilities || {});
        var accent = ov.color || ch.color;
        var pttColor = ov.ptt_color || accent;

        var strip = el('div', 'console-strip' + ((cfg && cfg.width === 2) ? ' console-strip-wide' : ''));
        strip.setAttribute('data-channel-id', ch.id);
        if (accent) { strip.style.borderTopColor = accent; }

        var head = el('div', 'console-strip-head');
        head.appendChild(el('i', 'bi ' + adapterIcon(ch.adapter) + ' me-1'));
        var lbl = el('span', 'console-strip-label',
            ov.short_label || ov.label || ch.short_label || ch.label);
        lbl.title = (ov.label || ch.label) + ' (' + ch.adapter + ')';
        head.appendChild(lbl);
        var led = el('span', 'console-led console-led-' + (ch.state || 'unknown'));
        led.title = 'Status: ' + (ch.state || 'unknown');
        head.appendChild(led);
        strip.appendChild(head);

        if (ch.regulatory_class === 'amateur') {
            strip.appendChild(el('div', 'console-strip-reg', 'AMATEUR — ID required'));
        }

        if ((int0(ch.enabled)) !== 1) {
            strip.classList.add('console-strip-disabled');
            strip.appendChild(el('div', 'console-strip-note', 'Channel disabled'));
            return strip;
        }

        // Activity line (also the in-place refresh target)
        if (controls.indexOf('activity') !== -1) {
            var act = el('div', 'console-strip-activity');
            if (ch.last_rx_at) {
                act.appendChild(el('span', 'console-activity-text',
                    (ch.last_caller ? ch.last_caller + ' · ' : '') + relTime(ch.last_rx_at)));
            } else {
                act.appendChild(el('span', 'console-activity-text text-body-secondary', 'no recent activity'));
            }
            strip.appendChild(act);
        }

        var caps = ch.capabilities || {};
        var controlsBox = el('div', 'console-strip-controls');

        // Voice: bind to today's backends (bus PTT lands in 114c+)
        if (controls.indexOf('voice') !== -1 && (caps.voice_tx || caps.voice_rx)) {
            if (ch.adapter === 'zello') {
                var zb = el('button', 'btn btn-sm console-ptt', null);
                zb.type = 'button';
                zb.appendChild(el('i', 'bi bi-mic-fill me-1'));
                zb.appendChild(document.createTextNode('Open Zello'));
                if (pttColor) { zb.style.background = pttColor; }
                zb.addEventListener('click', function () {
                    if (window.EventBus) { window.EventBus.emit('zello:toggle'); }
                });
                controlsBox.appendChild(zb);
            } else if (ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local') {
                var rb = el('button', 'btn btn-sm console-ptt', null);
                rb.type = 'button';
                rb.setAttribute('data-action', 'radio'); // radio-widget global delegator
                rb.appendChild(el('i', 'bi bi-broadcast me-1'));
                rb.appendChild(document.createTextNode('Open Radio'));
                if (pttColor) { rb.style.background = pttColor; }
                controlsBox.appendChild(rb);
            } else {
                controlsBox.appendChild(el('div', 'console-strip-note',
                    'Voice controls arrive with the audio bus (Phase 114c+)'));
            }
            if (!canTx) {
                controlsBox.appendChild(el('div', 'console-strip-note', 'Listen-only (no TX permission)'));
            }
        }

        // Text drawer
        if (controls.indexOf('text') !== -1 && (caps.text_rx || caps.text_tx || caps.source)) {
            var tBtn = el('button', 'btn btn-sm btn-outline-secondary console-text-toggle', null);
            tBtn.type = 'button';
            tBtn.appendChild(el('i', 'bi bi-chat-left-text me-1'));
            tBtn.appendChild(document.createTextNode(caps.source ? 'Feed' : 'Messages'));
            controlsBox.appendChild(tBtn);

            var drawer = el('div', 'console-strip-drawer d-none');
            var feed = el('div', 'console-strip-feed');
            drawer.appendChild(feed);

            if (caps.text_tx && canSend) {
                var form = el('div', 'input-group input-group-sm console-send-row');
                var inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'form-control form-control-sm';
                inp.placeholder = 'Send on ' + (ov.short_label || ch.short_label || ch.label);
                inp.maxLength = 500;
                var sb = el('button', 'btn btn-sm btn-primary', null);
                sb.type = 'button';
                sb.appendChild(el('i', 'bi bi-send'));
                form.appendChild(inp);
                form.appendChild(sb);
                drawer.appendChild(form);
                var doSend = function () {
                    var body = inp.value.replace(/^\s+|\s+$/g, '');
                    if (!body) { return; }
                    sb.disabled = true;
                    fetch(API, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', id: ch.id, body: body, csrf_token: csrf })
                    }).then(function (r) { return r.json(); }).then(function (j) {
                        sb.disabled = false;
                        if (j && j.ok) {
                            inp.value = '';
                            loadFeed(ch.id, feed);
                        } else {
                            var msg = (j && (j.error || (j.result && j.result.error))) || 'send failed';
                            showFeedNotice(feed, 'Send failed: ' + msg);
                        }
                    }).catch(function () {
                        sb.disabled = false;
                        showFeedNotice(feed, 'Send failed: network error');
                    });
                };
                sb.addEventListener('click', doSend);
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); doSend(); }
                });
            }

            tBtn.addEventListener('click', function () {
                var opening = drawer.classList.contains('d-none');
                drawer.classList.toggle('d-none');
                if (opening) {
                    openFeeds[ch.id] = feed;
                    loadFeed(ch.id, feed);
                } else {
                    delete openFeeds[ch.id];
                }
            });
            strip.appendChild(controlsBox);
            strip.appendChild(drawer);
        } else {
            strip.appendChild(controlsBox);
        }

        return strip;
    }

    function int0(v) { return parseInt(v, 10) || 0; }

    function showFeedNotice(feedEl2, text) {
        var n = el('div', 'console-feed-item console-feed-notice', text);
        feedEl2.appendChild(n);
        feedEl2.scrollTop = feedEl2.scrollHeight;
    }

    function loadFeed(channelId, feedEl2) {
        fetch(API + '?feed=' + encodeURIComponent(channelId) + '&limit=30')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.feed) { return; }
                feedEl2.innerHTML = '';
                if (!j.feed.length) {
                    feedEl2.appendChild(el('div', 'console-feed-item text-body-secondary', 'No messages yet'));
                    return;
                }
                for (var i = 0; i < j.feed.length; i++) {
                    var m = j.feed[i];
                    var item = el('div', 'console-feed-item' + (m.dir === 'tx' || m.dir === 'outgoing' ? ' console-feed-tx' : ''));
                    item.appendChild(el('div', 'console-feed-meta',
                        (m.who ? m.who + ' · ' : '') + relTime(m.when)));
                    item.appendChild(el('div', 'console-feed-body', m.body || ''));
                    feedEl2.appendChild(item);
                }
                feedEl2.scrollTop = feedEl2.scrollHeight;
            })
            .catch(function () { /* transient — next tick retries */ });
    }

    // ── Tabs ─────────────────────────────────────────────────────
    function renderTabs() {
        if (!tabBar) { return; }
        tabBar.innerHTML = '';
        // Hide the whole bar when no designer views exist — the auto view
        // needs no chrome (b1 look).
        if (!views.length) {
            tabBar.classList.add('d-none');
            if (activeView !== 'auto') { activeView = 'auto'; }
            return;
        }
        tabBar.classList.remove('d-none');

        var mk = function (key, icon, label) {
            var li = el('li', 'nav-item');
            var a = el('a', 'nav-link' + (String(activeView) === String(key) ? ' active' : ''), null);
            a.href = '#';
            if (icon) { a.appendChild(el('i', 'bi ' + icon + ' me-1')); }
            a.appendChild(document.createTextNode(label));
            a.addEventListener('click', function (e) {
                e.preventDefault();
                activeView = String(key);
                try { localStorage.setItem(TAB_KEY, activeView); } catch (e2) {}
                renderTabs();
                renderBank();
            });
            li.appendChild(a);
            return li;
        };

        for (var i = 0; i < views.length; i++) {
            tabBar.appendChild(mk(views[i].id, views[i].icon || 'bi-broadcast-pin', views[i].name));
        }
        tabBar.appendChild(mk('auto', 'bi-grid', 'All Channels'));
    }

    function currentView() {
        if (activeView === 'auto') { return null; }
        for (var i = 0; i < views.length; i++) {
            if (String(views[i].id) === String(activeView)) { return views[i]; }
        }
        return null;
    }

    // ── Positioned rendering (b2.5 free-form views) ──────────────
    // Same grid math as the designer: outer canvas = 12 columns of the
    // bank width × 20px rows; inner strip grid = 12 columns × 14px rows.
    var OUTER_CELL = 20;
    var INNER_CELL = 14;

    function pct(units) { return (units / 12 * 100) + '%'; }

    function renderComponent(comp, ch) {
        var props = comp.props || {};
        var caps = ch.capabilities || {};
        var node;
        if (comp.type === 'label') {
            node = el('div', 'ccp ccp-label', props.text || ch.short_label || ch.label);
            if (props.bg) { node.style.background = props.bg; }
            if (props.fg) { node.style.color = props.fg; }
        } else if (comp.type === 'led') {
            node = el('div', 'ccp ccp-led');
            var led = el('span', 'console-led console-led-' + (ch.state || 'unknown'));
            led.title = 'Status: ' + (ch.state || 'unknown');
            node.appendChild(led);
        } else if (comp.type === 'activity') {
            node = el('div', 'ccp ccp-activity');
            var act = el('span', 'console-activity-text' + (ch.last_rx_at ? '' : ' text-body-secondary'),
                ch.last_rx_at
                    ? (ch.last_caller ? ch.last_caller + ' · ' : '') + relTime(ch.last_rx_at)
                    : 'no recent activity');
            node.appendChild(act);
        } else if (comp.type === 'ptt') {
            node = el('button', 'ccp ccp-ptt console-ptt', props.text || 'PTT');
            node.type = 'button';
            node.style.background = props.color || '#dc3545';
            if (!canTx) {
                node.disabled = true;
                node.title = 'Listen-only (no TX permission)';
            } else if (ch.adapter === 'zello') {
                node.title = 'Opens the Zello widget for PTT';
                node.addEventListener('click', function () {
                    if (window.EventBus) { window.EventBus.emit('zello:toggle'); }
                });
            } else if (ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local') {
                node.setAttribute('data-action', 'radio');
                node.title = 'Opens the Radio widget for PTT';
            } else {
                node.disabled = true;
                node.title = 'In-strip PTT arrives with the audio bus (Phase 114c)';
            }
        } else if (comp.type === 'text') {
            node = el('div', 'ccp ccp-text');
            var feed = el('div', 'console-strip-feed ccp-feed');
            node.appendChild(feed);
            if (caps.text_tx && canSend) {
                var form = el('div', 'input-group input-group-sm console-send-row');
                var inp = document.createElement('input');
                inp.type = 'text';
                inp.className = 'form-control form-control-sm';
                inp.placeholder = 'Send…';
                inp.maxLength = 500;
                var sb = el('button', 'btn btn-sm btn-primary', null);
                sb.type = 'button';
                sb.appendChild(el('i', 'bi bi-send'));
                form.appendChild(inp);
                form.appendChild(sb);
                node.appendChild(form);
                var doSend = function () {
                    var body = inp.value.replace(/^\s+|\s+$/g, '');
                    if (!body) { return; }
                    sb.disabled = true;
                    fetch(API, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', id: ch.id, body: body, csrf_token: csrf })
                    }).then(function (r) { return r.json(); }).then(function (j) {
                        sb.disabled = false;
                        if (j && j.ok) {
                            inp.value = '';
                            loadFeed(ch.id, feed);
                        } else {
                            var msg = (j && (j.error || (j.result && j.result.error))) || 'send failed';
                            showFeedNotice(feed, 'Send failed: ' + msg);
                        }
                    }).catch(function () {
                        sb.disabled = false;
                        showFeedNotice(feed, 'Send failed: network error');
                    });
                };
                sb.addEventListener('click', doSend);
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); doSend(); }
                });
            }
            // Feed boxes are always visible in positioned strips — poll them.
            openFeeds[ch.id] = feed;
            loadFeed(ch.id, feed);
        } else {
            // Future components (monitor/mute/volume/say) — visible, honest,
            // disabled until their backend lands with the audio bus.
            var labels = { monitor: 'Mon', mute: 'Mute', volume: '', say: 'Say' };
            if (comp.type === 'volume') {
                node = el('div', 'ccp ccp-volume ccp-future-rt');
                node.appendChild(el('div', 'ccp-vol-track'));
            } else {
                node = el('button', 'ccp ccp-btn ccp-future-rt',
                    props.text || labels[comp.type] || comp.type);
                node.type = 'button';
                node.disabled = true;
            }
            node.title = 'Available when the audio matrix lands (Phase 114c)';
        }
        node.classList.add('console-comp');
        node.style.position = 'absolute';
        node.style.left = pct(comp.x || 0);
        node.style.width = pct(comp.w || 12);
        node.style.top = ((comp.y || 0) * INNER_CELL) + 'px';
        node.style.height = ((comp.h || 1) * INNER_CELL) + 'px';
        return node;
    }

    function renderPositionedStrip(ch, s) {
        var ov = s.overrides || {};
        var lay = s.layout || { x: 0, y: 0, w: 3, h: 14 };
        var strip = el('div', 'console-strip console-strip-abs');
        strip.setAttribute('data-channel-id', ch.id);
        var accent = ov.color || ch.color;
        if (accent) { strip.style.borderTopColor = accent; }
        strip.style.left = pct(lay.x);
        strip.style.width = 'calc(' + pct(lay.w) + ' - 8px)';
        strip.style.top = (lay.y * OUTER_CELL) + 'px';
        strip.style.height = (lay.h * OUTER_CELL) + 'px';

        var inner = el('div', 'console-strip-inner');
        var comps = s.components || [];
        for (var i = 0; i < comps.length; i++) {
            inner.appendChild(renderComponent(comps[i], ch));
        }
        strip.appendChild(inner);

        if ((parseInt(ch.enabled, 10) || 0) !== 1) {
            strip.classList.add('console-strip-disabled');
            strip.appendChild(el('div', 'console-strip-off-note', 'Channel disabled'));
        }
        if (ch.regulatory_class === 'amateur') {
            var reg = el('div', 'console-strip-reg console-strip-reg-abs', 'AMATEUR');
            reg.title = 'Amateur radio channel — station ID required';
            strip.appendChild(reg);
        }
        return strip;
    }

    // ── Bank render + refresh loop ───────────────────────────────
    function renderBank() {
        bank.innerHTML = '';
        openFeeds = {};
        var count = document.getElementById('consoleChannelCount');
        var view = currentView();
        var rendered = 0;

        if (view) {
            bank.classList.add('console-bank-abs');
            var maxY = 0;
            for (var i = 0; i < view.strips.length; i++) {
                var s = view.strips[i];
                var ch = channelsById[s.channel_id];
                if (!ch) { continue; } // channel removed since publish — fail soft
                bank.appendChild(renderPositionedStrip(ch, s));
                var lay = s.layout || {};
                var bottom = (lay.y || 0) + (lay.h || 14);
                if (bottom > maxY) { maxY = bottom; }
                rendered++;
            }
            bank.style.height = ((maxY + 1) * OUTER_CELL) + 'px';
            if (!rendered) {
                bank.style.height = '';
                bank.appendChild(el('div', 'text-body-secondary p-4',
                    'This view has no strips yet. Open the designer to add channels.'));
            }
        } else {
            bank.classList.remove('console-bank-abs');
            bank.style.height = '';
            if (activeView !== 'auto') {
                // Saved tab no longer exists — fall back.
                activeView = 'auto';
                renderTabs();
            }
            for (var k = 0; k < channels.length; k++) {
                if (int0(channels[k].enabled) !== 1) { continue; } // auto view: enabled only
                bank.appendChild(renderStrip(channels[k], null));
                rendered++;
            }
            if (!rendered) {
                bank.appendChild(el('div', 'text-body-secondary p-4',
                    'No channels enabled. Configure channels in Settings, then Sync Channels.'));
            }
        }
        if (count) { count.textContent = String(rendered); }
    }

    // In-place status update — a full re-render would destroy the send
    // input while the dispatcher is typing. Only rebuild the bank when
    // the channel SET changes; otherwise just repaint LED + activity.
    function updateInPlace(list) {
        for (var i = 0; i < list.length; i++) {
            var ch = list[i];
            var strips = bank.querySelectorAll('[data-channel-id="' + ch.id + '"]');
            for (var k = 0; k < strips.length; k++) {
                var led = strips[k].querySelector('.console-led');
                if (led) {
                    led.className = 'console-led console-led-' + (ch.state || 'unknown');
                    led.title = 'Status: ' + (ch.state || 'unknown');
                }
                var act = strips[k].querySelector('.console-activity-text');
                if (act) {
                    if (ch.last_rx_at) {
                        act.className = 'console-activity-text';
                        act.textContent = (ch.last_caller ? ch.last_caller + ' · ' : '') + relTime(ch.last_rx_at);
                    } else {
                        act.className = 'console-activity-text text-body-secondary';
                        act.textContent = 'no recent activity';
                    }
                }
            }
        }
    }

    function indexChannels(list) {
        channels = list;
        channelsById = {};
        for (var i = 0; i < list.length; i++) { channelsById[list[i].id] = list[i]; }
    }

    function sameChannelSet(list) {
        if (list.length !== channels.length) { return false; }
        for (var i = 0; i < list.length; i++) {
            if (!channels[i] || channels[i].id !== list[i].id) { return false; }
        }
        return channels.length > 0;
    }

    // Full list (not enabled-only): designer views must render a greyed
    // "Channel disabled" strip instead of silently dropping it.
    function refresh(withProbe) {
        fetch(API + (withProbe ? '?probe=1' : ''))
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.channels) { return; }
                if (sameChannelSet(j.channels)) {
                    indexChannels(j.channels);
                    updateInPlace(j.channels);
                    return;
                }
                indexChannels(j.channels);
                renderBank();
            })
            .catch(function () { /* transient */ });
    }

    function loadViews(then) {
        fetch(VIEWS_API)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                views = (j && j.views) || [];
                renderTabs();
                if (then) { then(); }
            })
            .catch(function () { if (then) { then(); } });
    }

    // Sync button (designer permission only — rendered server-side)
    var syncBtn = document.getElementById('consoleSyncBtn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function () {
            syncBtn.disabled = true;
            fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'sync', csrf_token: csrf })
            }).then(function (r) { return r.json(); }).then(function () {
                syncBtn.disabled = false;
                refresh(true);
            }).catch(function () { syncBtn.disabled = false; });
        });
    }

    // Feed auto-refresh for open drawers
    setInterval(function () {
        for (var id in openFeeds) {
            if (Object.prototype.hasOwnProperty.call(openFeeds, id) && openFeeds[id]) {
                loadFeed(id, openFeeds[id]);
            }
        }
    }, FEED_MS);

    // Status refresh loop
    setInterval(function () {
        refreshCount++;
        refresh(refreshCount % PROBE_EVERY === 0);
    }, REFRESH_MS);

    // Initial load: channels first (so the bank can render), then views.
    fetch(API + '?probe=1')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j && j.channels) { indexChannels(j.channels); }
            loadViews(function () { renderBank(); });
        })
        .catch(function () {
            loadViews(function () { renderBank(); });
        });
})();
