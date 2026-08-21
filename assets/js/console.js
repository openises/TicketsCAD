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
    var FCC_BADGE_REFRESH_MS = 45000;   // Phase 148 — AMATEUR badge live status
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
    var myViews = [];             // Phase 114b3 — the caller's own personal views
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

    // ── Select / Simulselect / Monitor / Mute / Volume (Phase 114b3) ──
    // Select + Simulselect are universal strip chrome — present on EVERY
    // strip in EVERY view (auto and designer-authored alike), not part of
    // the designer's placeable-component palette. Rationale: "which
    // channel has my attention right now" and "which channels page out
    // together" are per-operator RUNTIME behaviors orthogonal to how an
    // admin laid the board out, the same way the LED/label are always
    // present regardless of a designer's choices. Monitor/mute/volume,
    // by contrast, stay individually placeable palette components (see
    // renderComponent() below) because they're genuinely optional per
    // strip in the free-form designer.
    function audioState(chId) {
        return window.ConsoleAudio
            ? window.ConsoleAudio.getState(chId)
            : { selected: false, mon: true, muted: false, volume: 100, simulselect: false };
    }

    // Select + (if voice_tx) Simulselect checkbox. Appended to a strip's
    // header chrome.
    function buildSelectChrome(ch) {
        var wrap = el('div', 'console-select-chrome');
        var st = audioState(ch.id);

        var selBtn = el('button', 'btn btn-sm console-sel-btn' + (st.selected ? ' active' : ''), 'Sel');
        selBtn.type = 'button';
        selBtn.title = 'Select — this channel plays at full volume; other channels drop to monitor level while anything is selected';
        selBtn.setAttribute('aria-pressed', st.selected ? 'true' : 'false');
        selBtn.addEventListener('click', function () {
            if (!window.ConsoleAudio) { return; }
            window.ConsoleAudio.setSelected(ch.id, !window.ConsoleAudio.getState(ch.id).selected);
        });
        wrap.appendChild(selBtn);

        var caps = ch.capabilities || {};
        if (caps.voice_tx) {
            var simLbl = el('label', 'console-simulselect-chk form-check form-check-inline mb-0', null);
            var simInp = document.createElement('input');
            simInp.type = 'checkbox';
            simInp.className = 'form-check-input';
            simInp.checked = !!st.simulselect;
            simInp.title = 'Simulselect — include this channel in the multi-TX paging set';
            simInp.addEventListener('change', function () {
                if (!window.ConsoleAudio) { return; }
                window.ConsoleAudio.setSimulselect(ch.id, simInp.checked);
                renderSimulselectBar();
            });
            simLbl.appendChild(simInp);
            simLbl.appendChild(el('span', 'form-check-label small', 'Sim'));
            wrap.appendChild(simLbl);
        }
        return wrap;
    }

    // Real Mon / Mute / Volume block for a voice_rx-capable channel.
    // Used by the auto/flat strip renderer; the designer's positioned
    // view places these as independent components instead (see
    // renderComponent()).
    function buildAudioControlsBlock(ch) {
        var wrap = el('div', 'console-audio-controls');
        var st = audioState(ch.id);

        var monBtn = el('button', 'btn btn-sm console-audio-btn console-mon-btn' + (st.mon ? ' active' : ''), 'Mon');
        monBtn.type = 'button';
        monBtn.title = st.mon
            ? 'Monitor ON — audible at reduced volume while another channel is selected. Click to silence while unselected.'
            : 'Monitor OFF — silent while this channel is not selected. Click to include it in the background mix again.';
        monBtn.setAttribute('aria-pressed', st.mon ? 'true' : 'false');
        monBtn.addEventListener('click', function () {
            if (!window.ConsoleAudio) { return; }
            window.ConsoleAudio.setMon(ch.id, !window.ConsoleAudio.getState(ch.id).mon);
        });
        wrap.appendChild(monBtn);

        var muteBtn = el('button', 'btn btn-sm console-audio-btn console-mute-btn' + (st.muted ? ' active' : ''), 'Mute');
        muteBtn.type = 'button';
        muteBtn.title = st.muted ? 'Muted — click to unmute' : 'Click to mute this channel';
        muteBtn.setAttribute('aria-pressed', st.muted ? 'true' : 'false');
        muteBtn.addEventListener('click', function () {
            if (!window.ConsoleAudio) { return; }
            window.ConsoleAudio.setMuted(ch.id, !window.ConsoleAudio.getState(ch.id).muted);
        });
        wrap.appendChild(muteBtn);

        var volWrap = el('div', 'console-volume-row');
        var volInp = document.createElement('input');
        volInp.type = 'range';
        volInp.min = '0';
        volInp.max = '100';
        volInp.className = 'form-range console-volume-slider';
        volInp.value = String(st.volume);
        volInp.title = 'Volume';
        volInp.addEventListener('input', function () {
            if (!window.ConsoleAudio) { return; }
            window.ConsoleAudio.setVolume(ch.id, volInp.value);
        });
        volWrap.appendChild(volInp);
        wrap.appendChild(volWrap);

        return wrap;
    }

    // Re-paint pressed/active state + slider values on ALREADY-RENDERED
    // audio chrome without a full re-render (keeps open feed drawers,
    // in-progress typing, etc. intact) — called whenever ConsoleAudio's
    // state changes, and after every renderBank().
    function paintAudioState() {
        var strips = bank.querySelectorAll('[data-channel-id]');
        for (var i = 0; i < strips.length; i++) {
            var chId = strips[i].getAttribute('data-channel-id');
            var st = audioState(chId);

            var selBtn = strips[i].querySelector('.console-sel-btn');
            if (selBtn) {
                selBtn.classList.toggle('active', st.selected);
                selBtn.setAttribute('aria-pressed', st.selected ? 'true' : 'false');
            }
            var simInp = strips[i].querySelector('.console-simulselect-chk input');
            if (simInp) { simInp.checked = st.simulselect; }
            var monBtn = strips[i].querySelector('.console-mon-btn');
            if (monBtn) {
                monBtn.classList.toggle('active', st.mon);
                monBtn.setAttribute('aria-pressed', st.mon ? 'true' : 'false');
            }
            var muteBtn = strips[i].querySelector('.console-mute-btn');
            if (muteBtn) {
                muteBtn.classList.toggle('active', st.muted);
                muteBtn.setAttribute('aria-pressed', st.muted ? 'true' : 'false');
            }
            var volInp = strips[i].querySelector('.console-volume-slider');
            if (volInp && document.activeElement !== volInp) { volInp.value = String(st.volume); }

            // Text-channel prominence (select/mute -> visual weight, never
            // a literal audio concept — see console-audio-logic.js's
            // textProminence() docblock).
            var prom = window.ConsoleAudio ? window.ConsoleAudio.textProminence(chId) : 'normal';
            strips[i].classList.remove('console-strip-prominent', 'console-strip-suppressed');
            if (prom === 'prominent') {
                strips[i].classList.add('console-strip-prominent');
                // Select promotes a text channel's feed to "always visible"
                // — auto-open its drawer if the auto/flat renderer built
                // one and it's currently closed (positioned-view text
                // components are already always-visible, nothing to do).
                var toggle = strips[i].querySelector('.console-text-toggle');
                var drawer = strips[i].querySelector('.console-strip-drawer');
                if (toggle && drawer && drawer.classList.contains('d-none')) { toggle.click(); }
            }
            if (prom === 'suppressed') { strips[i].classList.add('console-strip-suppressed'); }
        }
    }

    // Master "Simulselect PTT" hold-to-talk button — appears only when at
    // least one TX-capable channel is currently a simulselect member.
    // Keys every member's REAL adapter PTT simultaneously (see console-
    // audio.js's own architectural-honesty docblock for exactly what
    // "simultaneously" can mean today).
    function renderSimulselectBar() {
        var bar = document.getElementById('consoleSimulselectBar');
        if (!bar || !window.ConsoleAudio) { return; }
        var members = window.ConsoleAudio.simulselectMembers();
        bar.innerHTML = '';
        if (!members.length) { bar.classList.add('d-none'); return; }
        bar.classList.remove('d-none');
        var names = [];
        for (var i = 0; i < members.length; i++) {
            var c = channelsById[members[i]];
            if (c) { names.push(c.short_label || c.label); }
        }
        var btn = el('button', 'btn btn-danger btn-sm console-simulselect-ptt', null);
        btn.type = 'button';
        btn.appendChild(el('i', 'bi bi-broadcast-pin me-1'));
        btn.appendChild(document.createTextNode('Simulselect PTT (' + members.length + ')'));
        btn.title = 'Hold to transmit on: ' + names.join(', ');
        if (!canTx) {
            btn.disabled = true;
            btn.title = 'Listen-only (no console_tx permission)';
        } else {
            var start = function (e) { e.preventDefault(); window.ConsoleAudio.simulselectPttStart(); btn.classList.add('console-simulselect-active'); };
            var stop = function () { window.ConsoleAudio.simulselectPttStop(); btn.classList.remove('console-simulselect-active'); };
            btn.addEventListener('mousedown', start);
            btn.addEventListener('touchstart', start, { passive: false });
            btn.addEventListener('mouseup', stop);
            btn.addEventListener('mouseleave', stop);
            btn.addEventListener('touchend', stop);
            btn.addEventListener('touchcancel', stop);
        }
        bar.appendChild(btn);
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
        strip.appendChild(buildSelectChrome(ch));

        if (ch.regulatory_class === 'amateur') {
            var regBadge = el('div', 'console-strip-reg', 'AMATEUR — ID required');
            // Phase 148 — FCC 97.119 live status. Only dmr_bm channels carry
            // config.dmr_channel_id (see inc/channel_registry.php); the badge
            // stays static text for any other amateur adapter until it, too,
            // has real enforcement wired to api/dmr-station-id.php.
            if (ch.config && ch.config.dmr_channel_id) {
                regBadge.setAttribute('data-dmr-channel-id', ch.config.dmr_channel_id);
            }
            strip.appendChild(regBadge);
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
            // Phase 114b3 — real Mon/Mute/Volume, for every channel this
            // console can actually receive audio from (Zello + DMR today;
            // see console-audio.js's docblock for the honest scope of what
            // "real" means while each adapter is still a singleton widget).
            if (caps.voice_rx && (ch.adapter === 'zello' || ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local')) {
                controlsBox.appendChild(buildAudioControlsBlock(ch));
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
    // Phase 114b3: personal views (myViews) are shown alongside the
    // shared designer views, prefixed with a person icon. They're
    // view/switch only from here — editing happens in console-designer.php
    // (now open to any screen.console holder for their OWN views; see
    // console-designer.js), keeping console.php focused on running the
    // console rather than duplicating a whole editing UI in the tab bar.
    function renderTabs() {
        if (!tabBar) { return; }
        tabBar.innerHTML = '';
        // Hide the whole bar when no views (shared OR personal) exist —
        // the auto view needs no chrome (b1 look).
        if (!views.length && !myViews.length) {
            tabBar.classList.add('d-none');
            if (activeView !== 'auto') { activeView = 'auto'; }
            return;
        }
        tabBar.classList.remove('d-none');

        var mk = function (key, icon, label, isPersonal) {
            var li = el('li', 'nav-item');
            var a = el('a', 'nav-link' + (String(activeView) === String(key) ? ' active' : ''), null);
            a.href = '#';
            if (isPersonal) { a.appendChild(el('i', 'bi bi-person-fill me-1 console-tab-personal-icon')); }
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
            tabBar.appendChild(mk(views[i].id, views[i].icon || 'bi-broadcast-pin', views[i].name, false));
        }
        for (var m = 0; m < myViews.length; m++) {
            tabBar.appendChild(mk('mine:' + myViews[m].id, myViews[m].icon || 'bi-broadcast-pin', myViews[m].name, true));
        }
        tabBar.appendChild(mk('auto', 'bi-grid', 'All Channels', false));
    }

    function currentView() {
        if (activeView === 'auto') { return null; }
        if (String(activeView).indexOf('mine:') === 0) {
            var myId = String(activeView).slice(5);
            for (var m = 0; m < myViews.length; m++) {
                if (String(myViews[m].id) === myId) { return myViews[m]; }
            }
            return null;
        }
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
        } else if (comp.type === 'monitor') {
            // Phase 114b3 — REAL, wired to ConsoleAudio (console-audio.js).
            var monSt = audioState(ch.id);
            node = el('button', 'ccp ccp-btn console-mon-btn' + (monSt.mon ? ' active' : ''), props.text || 'Mon');
            node.type = 'button';
            node.title = monSt.mon
                ? 'Monitor ON — audible at reduced volume while another channel is selected'
                : 'Monitor OFF — silent while unselected';
            node.setAttribute('aria-pressed', monSt.mon ? 'true' : 'false');
            node.addEventListener('click', function () {
                if (window.ConsoleAudio) { window.ConsoleAudio.setMon(ch.id, !window.ConsoleAudio.getState(ch.id).mon); }
            });
        } else if (comp.type === 'mute') {
            var muteSt = audioState(ch.id);
            node = el('button', 'ccp ccp-btn console-mute-btn' + (muteSt.muted ? ' active' : ''), props.text || 'Mute');
            node.type = 'button';
            node.title = muteSt.muted ? 'Muted — click to unmute' : 'Click to mute this channel';
            node.setAttribute('aria-pressed', muteSt.muted ? 'true' : 'false');
            node.addEventListener('click', function () {
                if (window.ConsoleAudio) { window.ConsoleAudio.setMuted(ch.id, !window.ConsoleAudio.getState(ch.id).muted); }
            });
        } else if (comp.type === 'volume') {
            var volSt = audioState(ch.id);
            node = el('div', 'ccp ccp-volume');
            var volInp2 = document.createElement('input');
            volInp2.type = 'range';
            volInp2.min = '0';
            volInp2.max = '100';
            volInp2.className = 'form-range console-volume-slider';
            volInp2.value = String(volSt.volume);
            volInp2.title = 'Volume';
            volInp2.addEventListener('input', function () {
                if (window.ConsoleAudio) { window.ConsoleAudio.setVolume(ch.id, volInp2.value); }
            });
            node.appendChild(volInp2);
        } else {
            // 'say' (TTS button) — no backend yet, honestly disabled.
            node = el('button', 'ccp ccp-btn ccp-future-rt', props.text || 'Say');
            node.type = 'button';
            node.disabled = true;
            node.title = 'Say (TTS) arrives with a future phase — no backend yet';
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

        // Select + Simulselect — universal chrome, overlaid at the strip
        // level (not part of the designer-placed component set) so it's
        // present regardless of what an admin chose to lay out inside.
        var selChrome = buildSelectChrome(ch);
        selChrome.classList.add('console-select-chrome-abs');
        strip.appendChild(selChrome);

        if ((parseInt(ch.enabled, 10) || 0) !== 1) {
            strip.classList.add('console-strip-disabled');
            strip.appendChild(el('div', 'console-strip-off-note', 'Channel disabled'));
        }
        if (ch.regulatory_class === 'amateur') {
            var reg = el('div', 'console-strip-reg console-strip-reg-abs', 'AMATEUR');
            reg.title = 'Amateur radio channel — station ID required';
            // Phase 148 — see the identical comment in renderStrip() above.
            if (ch.config && ch.config.dmr_channel_id) {
                reg.setAttribute('data-dmr-channel-id', ch.config.dmr_channel_id);
            }
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
        paintAudioState();
        renderSimulselectBar();
    }

    // Phase 148 — FCC 97.119 badge live status. Makes the "AMATEUR — ID
    // required" badge (previously purely decorative — see
    // specs/SPEC-STATUS.md section B3) reflect the logged-in operator's own
    // real countdown state on that channel: a small colored dot + updated
    // title, sourced from the same status inc/fcc_station_id.php computes
    // for the radio widget. One fetch per distinct dmr_channel_id currently
    // on screen (a channel may appear in more than one strip across views).
    function fccRefreshBadges() {
        var badges = bank.querySelectorAll('[data-dmr-channel-id]');
        if (!badges.length) return;
        var seen = {};
        for (var i = 0; i < badges.length; i++) {
            var chId = badges[i].getAttribute('data-dmr-channel-id');
            if (seen[chId]) continue;
            seen[chId] = true;
            fetch('api/dmr-station-id.php?action=status&channel=' + encodeURIComponent(chId),
                { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (st) {
                    if (!st) return;
                    fccPaintBadges(st);
                })
                .catch(function () { /* view-only operator, DMR unconfigured, etc — leave static */ });
        }
    }

    function fccPaintBadges(st) {
        if (!st.channel_id) return;
        var badges = bank.querySelectorAll('[data-dmr-channel-id="' + st.channel_id + '"]');
        var dotClass = 'console-strip-reg-dot-' + (st.zone || 'none');
        var title = st.callsign_present
            ? ('Amateur radio channel — station ID required. '
               + (st.zone === 'none' ? 'No ID on record yet for ' + st.callsign + '.'
                  : st.zone === 'red' ? st.callsign + '’s next transmission must include a callsign.'
                  : st.zone === 'yellow' ? st.callsign + '’s ID interval is closing.'
                  : st.callsign + ' IDed within the last ' + Math.round((st.seconds_since_id || 0) / 60) + ' min.'))
            : 'Amateur radio channel — station ID required. No callsign on file for this operator.';
        for (var i = 0; i < badges.length; i++) {
            badges[i].title = title;
            badges[i].classList.remove(
                'console-strip-reg-dot-none', 'console-strip-reg-dot-green',
                'console-strip-reg-dot-yellow', 'console-strip-reg-dot-red');
            badges[i].classList.add(dotClass);
        }
    }

    // Phase 114b3 — "new activity" flash, mute-aware. lastActivitySeen
    // tracks the last last_rx_at we've already reacted to per channel, so
    // a flash only fires on a genuine transition (not on every poll tick
    // re-showing the same value), and never on the very first load (no
    // "everything just flashed because the page opened" false alarm).
    var lastActivitySeen = {};
    var FLASH_MS = 1500;
    function maybeFlashActivity(ch, stripEls) {
        var prevSeen = lastActivitySeen[ch.id];
        var seenBefore = Object.prototype.hasOwnProperty.call(lastActivitySeen, ch.id);
        lastActivitySeen[ch.id] = ch.last_rx_at || null;
        if (!ch.last_rx_at || ch.last_rx_at === prevSeen || !seenBefore) { return; }
        if (audioState(ch.id).muted) { return; } // mute suppresses the flash — see console-audio-logic.js textProminence()
        for (var i = 0; i < stripEls.length; i++) {
            (function (elx) {
                elx.classList.add('console-strip-flash');
                setTimeout(function () { elx.classList.remove('console-strip-flash'); }, FLASH_MS);
            })(stripEls[i]);
        }
    }

    // In-place status update — a full re-render would destroy the send
    // input while the dispatcher is typing. Only rebuild the bank when
    // the channel SET changes; otherwise just repaint LED + activity.
    function updateInPlace(list) {
        for (var i = 0; i < list.length; i++) {
            var ch = list[i];
            var strips = bank.querySelectorAll('[data-channel-id="' + ch.id + '"]');
            maybeFlashActivity(ch, strips);
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
        if (window.ConsoleAudio) { window.ConsoleAudio.registerChannels(list); }
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
                myViews = (j && j.my_views) || [];
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

    // Phase 148 — FCC 97.119 live badge status. Independent of the
    // channel-refresh loop above (which may skip a full renderBank() via
    // updateInPlace() and so can't be relied on to re-run this) — scans
    // whatever [data-dmr-channel-id] badges currently exist in the DOM
    // each tick, works after either a full render or an in-place update.
    // Fails silently (e.g. a 403 for a view-only operator) — the badge
    // just stays its static "AMATEUR — ID required" text, which is still
    // an accurate claim, just not a live one.
    setInterval(fccRefreshBadges, FCC_BADGE_REFRESH_MS);
    fccRefreshBadges();

    // Phase 114b3 — repaint select/mon/mute/volume chrome + the
    // simulselect bar whenever ConsoleAudio's state changes (a user
    // touching a control, or the persisted state arriving from the
    // server). Kept separate from renderBank()'s own end-of-function call
    // so a state change alone never has to rebuild the whole bank.
    if (window.ConsoleAudio) {
        window.ConsoleAudio.subscribe(function () { paintAudioState(); renderSimulselectBar(); });
        window.ConsoleAudio.load(); // fire-and-forget — subscribe() above repaints once it lands
    }

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
