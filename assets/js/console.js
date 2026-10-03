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

    var toastEl = document.getElementById('consoleToast');
    var toastTimer = null;
    function showToast(type, message) {
        if (!toastEl) { return; }
        toastEl.className = 'alert alert-' + type + ' py-2 mb-3';
        toastEl.textContent = message;
        if (toastTimer) { window.clearTimeout(toastTimer); }
        toastTimer = window.setTimeout(function () {
            toastEl.classList.add('d-none');
        }, 4000);
    }

    // Phase 155 (GH#151/GH#129) — channel ids currently RECEIVING audio from
    // a digital voice bridge (comm:rx_state). Kept here, not in the DOM, so
    // a strip repaint (renderBank()) does not lose a lamp that is lit. A
    // watchdog clears an entry whose 'ended' event never arrived (those
    // notifications are best-effort), so a lamp can never stay lit for ever.
    var RX_LAMP_WATCHDOG_MS = 10 * 60 * 1000;
    var rxActive = {};           // channelId -> watchdog timer id
    var channels = [];           // last fetched channel list (enabled only)
    var channelsById = {};
    var views = [];              // shared views from the designer
    var myViews = [];             // Phase 114b3 — the caller's own personal views
    var activeView = 'auto';     // 'auto' or a view id (string)
    var hadStoredView = false;   // true only if the operator has EXPLICITLY picked a tab before (see loadViews())
    var openFeeds = {};          // channelId -> feed element while drawer open
    var refreshCount = 0;

    try {
        var storedView = localStorage.getItem(TAB_KEY);
        if (storedView) { activeView = storedView; hadStoredView = true; }
    } catch (e) {}

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

    // Phase 152 (Console rebuild) — default {show:...} template for a
    // channel with no designer-authored strip (the "All Channels" auto
    // view) — everything the channel is capable of, matching what
    // console_strip_show_from_legacy_controls() derives server-side for a
    // b2-style "voice" bundle, plus sel:true (the auto view's strips have
    // always shown Select unconditionally; a designer-authored strip may
    // now turn it off — see console_strip_template_needs()'s null entry for
    // 'sel' in inc/console-views.php for why sel stopped being hardcoded
    // chrome).
    function defaultShowTemplate(caps) {
        return {
            ptt: !!caps.voice_tx, sel: true,
            mon: !!caps.voice_rx, mute: !!caps.voice_rx, vol: !!caps.voice_rx,
            text: !!(caps.text_rx || caps.text_tx || caps.source),
            vu: false, recall: false, patchchips: false
        };
    }

    // ── Select / Simulselect / Monitor / Mute / Volume ──────────────
    // Phase 152: Select (and the Simulselect checkbox that rides inside
    // its chrome) is a normal, independently toggleable show-flag now
    // (tpl.show.sel — see console_strip_template_needs()'s null entry for
    // 'sel' in inc/console-views.php, meaning no capability gate, just an
    // admin choice) rather than forced-on universal chrome; the "All Channels"
    // auto view's defaultShowTemplate() sets it true to preserve the
    // pre-152 look there. Monitor/Mute/Volume are likewise independently
    // toggleable per strip (buildAudioControlsBlock()'s show param).
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
    // Phase 152: each sub-control is independently toggleable per the
    // strip's own {show:...} template now (a designer-authored strip may
    // show Volume without Mon, etc.) — show picks which of the three
    // render; omitted flags default all-on (the "All Channels" auto view
    // has no template to consult and always showed all three).
    function buildAudioControlsBlock(ch, show) {
        show = show || { mon: true, mute: true, vol: true };
        var wrap = el('div', 'console-audio-controls');
        var st = audioState(ch.id);

        if (show.mon) {
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
        }

        if (show.mute) {
            var muteBtn = el('button', 'btn btn-sm console-audio-btn console-mute-btn' + (st.muted ? ' active' : ''), 'Mute');
            muteBtn.type = 'button';
            muteBtn.title = st.muted ? 'Muted — click to unmute' : 'Click to mute this channel';
            muteBtn.setAttribute('aria-pressed', st.muted ? 'true' : 'false');
            muteBtn.addEventListener('click', function () {
                if (!window.ConsoleAudio) { return; }
                window.ConsoleAudio.setMuted(ch.id, !window.ConsoleAudio.getState(ch.id).muted);
            });
            wrap.appendChild(muteBtn);
        }

        if (show.vol) {
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
        }

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

    // Patch rail (Console rebuild) — repaint checkbox state + the patched
    // badge on ALREADY-RENDERED strips without a full renderBank(), so
    // another operator's patch action (arriving live via SSE) never
    // disrupts THIS operator's open text drawer or in-progress typing.
    // The rail's own confirm-bar Couple/Cancel click IS this operator's
    // own deliberate action, so it uses this same light repaint too
    // rather than a full rebuild — no reason to prefer one over the other
    // once this function exists.
    function paintPatchState() {
        if (!window.ConsolePatchRail) { return; }
        var strips = bank.querySelectorAll('[data-channel-id]');
        for (var i = 0; i < strips.length; i++) {
            var chId = strips[i].getAttribute('data-channel-id');
            var chk = strips[i].querySelector('.console-strip-patch-select');
            if (chk) { chk.checked = window.ConsolePatchRail.isSelected(chId); }
            var existingBadge = strips[i].querySelector('.console-strip-patched-badge');
            var shouldShow = strips[i].getAttribute('data-patchchips-shown') === '1'
                && window.ConsolePatchRail.isPatched(chId);
            if (shouldShow && !existingBadge) {
                var badge = el('span', 'console-strip-patched-badge', 'Patched');
                badge.title = 'This channel is part of an active standing patch or group';
                var head = strips[i].querySelector('.console-strip-head');
                var led = head ? head.querySelector('.console-led') : null;
                if (head) { head.insertBefore(badge, led); }
            } else if (!shouldShow && existingBadge) {
                existingBadge.parentNode.removeChild(existingBadge);
            }
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

    // ── Strip rendering (Phase 152 — ONE renderer, no free-form canvas) ──
    // Every strip, in every view (auto or designer-authored), renders
    // through this one function now — renderPositionedStrip()/
    // renderComponent() and the pixel-geometry math they needed are gone
    // (unanimous 5-persona rejection of free-drag at the strip level, see
    // specs/phase-152-comms-console-v2/tasks.md's "Console rebuild"
    // section). A strip's only geometry is width (1 or 2 columns in the
    // existing flex-wrap bank — see console.css's .console-strip-wide,
    // unchanged); its content is driven entirely by tpl.show, the same
    // {ptt,sel,mon,mute,vol,text,vu,recall,patchchips} bag
    // console_view_save_strips()/console_view_attach_strips() persist and
    // return (inc/console-views.php). Activity + status LED are now
    // universal chrome (no show-flag governs them, matching Select's own
    // prior "always there" treatment) since every strip — real or
    // launcher — benefits from knowing when a channel last had traffic.
    //
    // tpl: {overrides:{label,short_label,color,ptt_color}, show:{...},
    //       hotkey:'F5'|'A'|null, width:1|2}
    function renderStrip(ch, tpl) {
        var ov = (tpl && tpl.overrides) || {};
        var show = (tpl && tpl.show) || {};
        var accent = ov.color || ch.color;
        var pttColor = ov.ptt_color || accent;

        var strip = el('div', 'console-strip' + ((tpl && tpl.width === 2) ? ' console-strip-wide' : ''));
        strip.setAttribute('data-channel-id', ch.id);
        if (show.patchchips) { strip.setAttribute('data-patchchips-shown', '1'); }
        if (tpl && tpl.hotkey) { strip.setAttribute('data-hotkey', tpl.hotkey); }
        if (accent) { strip.style.borderTopColor = accent; }

        var head = el('div', 'console-strip-head');
        // Patch rail (Console rebuild) — checkbox-select coupling creation
        // (5-persona review, unanimous rejection of drag-to-patch). Only
        // offered to an action.patch_create/action.manage_matrix holder;
        // console-patch-rail.js owns the actual selection state so this
        // strip and the confirm bar always agree.
        if (window.ConsolePatchRail && window.ConsolePatchRail.canPatch()) {
            var patchChk = document.createElement('input');
            patchChk.type = 'checkbox';
            patchChk.className = 'form-check-input console-strip-patch-select';
            patchChk.title = 'Select this channel to couple it with another';
            patchChk.checked = window.ConsolePatchRail.isSelected(ch.id);
            patchChk.addEventListener('change', function () {
                window.ConsolePatchRail.toggleSelect(ch.id, patchChk.checked);
            });
            head.appendChild(patchChk);
        }
        head.appendChild(el('i', 'bi ' + adapterIcon(ch.adapter) + ' me-1'));
        var lbl = el('span', 'console-strip-label',
            ov.short_label || ov.label || ch.short_label || ch.label);
        lbl.title = (ov.label || ch.label) + ' (' + ch.adapter + ')'
            + (tpl && tpl.hotkey ? ' — hotkey ' + tpl.hotkey : '');
        head.appendChild(lbl);
        // tpl.show.patchchips (Console rebuild) — a real badge now, backed
        // by the patch rail's own live route/group state, replacing the
        // designer's former disabled "future" checkbox for this flag.
        if (show.patchchips && window.ConsolePatchRail && window.ConsolePatchRail.isPatched(ch.id)) {
            var patchedBadge = el('span', 'console-strip-patched-badge', 'Patched');
            patchedBadge.title = 'This channel is part of an active standing patch or group';
            head.appendChild(patchedBadge);
        }
        var led = el('span', 'console-led console-led-' + (ch.state || 'unknown'));
        led.title = 'Status: ' + (ch.state || 'unknown');
        head.appendChild(led);
        // Phase 155 — RX lamp for channels that report audio arriving. Hidden
        // by CSS until lit, and labelled "RX" so it never relies on colour.
        if ((ch.capabilities || {}).voice_rx) {
            var rxLamp = el('span', 'console-rx-lamp' + (rxActive[ch.id] ? ' console-rx-lamp-on' : ''), 'RX');
            rxLamp.title = 'Receiving audio now';
            rxLamp.setAttribute('role', 'status');
            head.appendChild(rxLamp);
        }
        strip.appendChild(head);
        if (show.sel) { strip.appendChild(buildSelectChrome(ch)); }

        if (ch.regulatory_class === 'amateur') {
            // Phase 155: a matrix-backed channel that cannot transmit (a digital
            // voice bridge in this release) has no station-ID obligation of its
            // own, so "ID required" would mislead; say what it is instead.
            var badgeListenOnly = !!(window.ConsoleMatrix && window.ConsoleMatrix.isMatrixBacked(ch.adapter))
                && !(ch.capabilities || {}).voice_tx;
            var regBadge = el('div', 'console-strip-reg', badgeListenOnly ? 'AMATEUR — listen-only' : 'AMATEUR — ID required');
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

        // Activity line — universal chrome now (also the in-place refresh
        // target; see updateInPlace()).
        var act = el('div', 'console-strip-activity');
        if (ch.last_rx_at) {
            act.appendChild(el('span', 'console-activity-text',
                (ch.last_caller ? ch.last_caller + ' · ' : '') + relTime(ch.last_rx_at)));
        } else {
            act.appendChild(el('span', 'console-activity-text text-body-secondary', 'no recent activity'));
        }
        strip.appendChild(act);

        var caps = ch.capabilities || {};
        var controlsBox = el('div', 'console-strip-controls');

        // Phase 152 — a matrix-backed channel (DMR today; see console-
        // mic.js's own docblock for why Zello never appears here) whose
        // operator has explicitly engaged Matrix Audio gets a REAL PTT
        // wired to the browser-leg session instead of the launcher. Off by
        // default (console-audio-logic.js's defaultState()) — an operator
        // who never touches the toggle sees exactly today's behavior.
        var isMatrixBacked = !!(window.ConsoleMatrix && window.ConsoleMatrix.isMatrixBacked(ch.adapter));
        var matrixAudioOn = isMatrixBacked && !!audioState(ch.id).matrixAudio;
        // Phase 155 (GH#151/GH#129): a matrix-backed channel that cannot
        // transmit (a digital voice bridge in this release). Decided by the
        // row's own capabilities, never by adapter name, so it stays right
        // when a later release gives these channels voice_tx.
        var listenOnlyMatrix = isMatrixBacked && !caps.voice_tx;
        if (listenOnlyMatrix) {
            // Say so in words (never colour alone) and say how to hear it —
            // there is no legacy widget to launch. Shown whatever the strip's
            // template: the auto "All Channels" view has no PTT block at all
            // for a channel that cannot transmit.
            var loMode = ch.config && ch.config.mode ? String(ch.config.mode).toUpperCase() : '';
            var loTg = ch.config && ch.config.talkgroup ? ' · TG ' + ch.config.talkgroup : '';
            controlsBox.appendChild(el('div', 'console-strip-note console-listen-only-note',
                'Listen-only' + (loMode ? ' · ' + loMode : '') + loTg
                + (matrixAudioOn ? '' : ' — turn on Listen below to hear it')));
        }

        if (show.ptt && (caps.voice_tx || caps.voice_rx)) {
            // Phase 152 persona review (the veteran's addition): while a
            // channel's audio still rides the shared Zello/Radio SINGLETON
            // widget, this button is a LAUNCHER, not a real PTT — it must
            // look visually distinct (no PTT accent color, an outline
            // style instead of console-ptt's solid red) and must be
            // structurally excluded from whatever a future physical-PTT/
            // footswitch binding (console-hid.js) treats as an eligible
            // TX target, so stomping a pedal on a launcher strip can never
            // silently do nothing. data-launcher marks that exclusion for
            // that future code to query; console-strip-launcher marks it
            // for CSS. Only zello/dmr_bm/dmr_local resolve to a launcher
            // today — anything else with voice capability but no adapter-
            // specific handler is an honest "not wired yet" note, same as
            // before.
            var isLauncher = (ch.adapter === 'zello' || (isMatrixBacked && !matrixAudioOn && !listenOnlyMatrix));
            if (isLauncher) { strip.classList.add('console-strip-launcher'); strip.setAttribute('data-launcher', '1'); }
            // Phase 152 prerequisite #8 (console-hid.js, physical PTT) --
            // the ONE marker that means "a hardware pedal/hotkey press on
            // THIS strip would actually key real audio right now": real
            // matrix audio engaged AND this operator holds TX permission.
            // Deliberately separate from data-launcher's absence (a text-
            // only channel also lacks data-launcher but is never a real
            // PTT target either) -- console-hid.js queries this attribute
            // directly rather than inferring eligibility from other state.
            if (matrixAudioOn && canTx && !listenOnlyMatrix) { strip.setAttribute('data-real-ptt', '1'); }
            if (matrixAudioOn && !listenOnlyMatrix) {
                // Real, independent PTT over the browser-leg session — held
                // down, not clicked, matching every other real PTT control
                // in this app (simulselect, radio-widget.js's own button).
                var mb = el('button', 'btn btn-sm console-ptt', null);
                mb.type = 'button';
                mb.appendChild(el('i', 'bi bi-broadcast me-1'));
                mb.appendChild(document.createTextNode('PTT'));
                if (pttColor) { mb.style.background = pttColor; }
                if (!canTx) {
                    mb.disabled = true;
                } else {
                    var mbStart = function (e) { e.preventDefault(); window.ConsoleMatrix.talkStart(ch.id); mb.classList.add('console-simulselect-active'); };
                    var mbStop = function () { window.ConsoleMatrix.talkEnd(ch.id); mb.classList.remove('console-simulselect-active'); };
                    mb.addEventListener('mousedown', mbStart);
                    mb.addEventListener('touchstart', mbStart, { passive: false });
                    mb.addEventListener('mouseup', mbStop);
                    mb.addEventListener('mouseleave', mbStop);
                    mb.addEventListener('touchend', mbStop);
                    mb.addEventListener('touchcancel', mbStop);
                }
                controlsBox.appendChild(mb);
            } else if (listenOnlyMatrix) {
                // Nothing to add: the listen-only note is already on the strip
                // (above), and the generic "arrives with the audio bus" note
                // below would be wrong for a channel that is finished, just
                // not able to transmit.
            } else if (ch.adapter === 'zello') {
                var zb = el('button', 'btn btn-sm console-launcher-btn', null);
                zb.type = 'button';
                zb.appendChild(el('i', 'bi bi-mic-fill me-1'));
                zb.appendChild(document.createTextNode('Open Zello'));
                zb.title = 'Opens the shared Zello widget for PTT — not an independent per-strip control yet';
                zb.addEventListener('click', function () {
                    if (window.EventBus) { window.EventBus.emit('zello:toggle'); }
                });
                controlsBox.appendChild(zb);
            } else if (ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local') {
                var rb = el('button', 'btn btn-sm console-launcher-btn', null);
                rb.type = 'button';
                rb.setAttribute('data-action', 'radio'); // radio-widget global delegator
                rb.appendChild(el('i', 'bi bi-broadcast me-1'));
                rb.appendChild(document.createTextNode('Open Radio'));
                rb.title = 'Opens the shared Radio widget for PTT — not an independent per-strip control yet';
                controlsBox.appendChild(rb);
            } else if (ch.adapter === 'intercom_dd') {
                // No legacy widget to launch here at all — unlike Zello/DMR
                // above, there is no fallback action; the "Matrix Audio"
                // toggle rendered below (retitled "Join Intercom" for this
                // adapter) is the only way onto this channel.
                controlsBox.appendChild(el('div', 'console-strip-note',
                    'Turn on Join Intercom below to talk on this channel'));
            } else {
                controlsBox.appendChild(el('div', 'console-strip-note',
                    'Voice controls arrive with the audio bus (Phase 114c+)'));
            }
            if (!canTx && !listenOnlyMatrix) {
                controlsBox.appendChild(el('div', 'console-strip-note', 'Listen-only (no TX permission)'));
            }
        }
        // Matrix Audio toggle — only offered for a channel the audio matrix
        // actually has a leg for. Engaging it is an explicit per-operator,
        // per-strip choice (never automatic): it lazily connects the
        // session (first real mic-permission prompt happens here, never on
        // page load) and switches this ONE channel from the shared
        // singleton widget to its own independent route — see console-
        // audio.js's applyAudio() for why the two are mutually exclusive.
        if (isMatrixBacked && (canTx || listenOnlyMatrix)) {
            // intercom_dd has no legacy widget to be mutually exclusive
            // WITH (see console-audio.js's isMatrixCapable) — reworded so
            // the toggle reads as "join the party line", not "replace a
            // widget that doesn't exist for this channel".
            var isIntercomDd = (ch.adapter === 'intercom_dd');
            var maLbl = el('label', 'console-matrix-audio-toggle form-check form-check-inline mb-0', null);
            var maInp = document.createElement('input');
            maInp.type = 'checkbox';
            maInp.className = 'form-check-input';
            maInp.checked = matrixAudioOn;
            if (isIntercomDd) {
                maInp.title = matrixAudioOn
                    ? 'You are joined to the dispatcher intercom — PTT/Monitor/Mute/Volume act on this channel'
                    : 'Join the dispatcher intercom (uses your microphone)';
            } else if (listenOnlyMatrix) {
                maInp.title = matrixAudioOn
                    ? 'You are listening to this channel through the audio matrix — Monitor/Mute/Volume act on it'
                    : 'Listen to this channel through the audio matrix (it cannot transmit)';
            } else {
                maInp.title = matrixAudioOn
                    ? 'Independent matrix audio is ON for this strip — PTT/Monitor/Mute/Volume act on THIS channel alone'
                    : 'Turn on independent audio for this channel (uses your microphone; replaces the shared radio widget for THIS strip only)';
            }
            maInp.addEventListener('change', function () {
                var want = maInp.checked;
                maInp.disabled = true;
                window.ConsoleAudio.setMatrixAudio(ch.id, want, function (ok) {
                    maInp.disabled = false;
                    if (!ok) { maInp.checked = !want; }
                    renderBank();
                });
            });
            maLbl.appendChild(maInp);
            maLbl.appendChild(el('span', 'form-check-label small',
                isIntercomDd ? 'Join Intercom' : (listenOnlyMatrix ? 'Listen' : 'Matrix Audio')));
            controlsBox.appendChild(maLbl);
        }
        // Recall tab's per-strip shortcut (plan.md: "a one-button
        // shortcut into the same per-adapter replay endpoints the Recall
        // tab already uses") — voice-capable adapters only; text-only
        // channels already show their history inline via the Feed drawer
        // below, no separate "replay" concept applies to them.
        if (show.recall && (ch.adapter === 'zello' || ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local')) {
            var replayBtn = el('button', 'btn btn-sm btn-outline-secondary console-strip-replay', null);
            replayBtn.type = 'button';
            replayBtn.title = 'Play the most recent recorded traffic on this channel';
            replayBtn.appendChild(el('i', 'bi bi-play-fill'));
            replayBtn.addEventListener('click', function () {
                if (window.ConsoleRecall) { window.ConsoleRecall.replayLatestForChannel(ch); }
            });
            controlsBox.appendChild(replayBtn);
        }
        // Phase 114b3/152 — real Mon/Mute/Volume, independently toggleable
        // per the strip's own template now, for every channel this console
        // can actually receive audio from (Zello + DMR today; see console-
        // audio.js's docblock for the honest scope of what "real" means
        // while each adapter is still a singleton widget).
        if ((show.mon || show.mute || show.vol) && caps.voice_rx
            && (ch.adapter === 'zello' || ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local' || listenOnlyMatrix)) {
            controlsBox.appendChild(buildAudioControlsBlock(ch, show));
        }

        // Text drawer
        if (show.text && (caps.text_rx || caps.text_tx || caps.source)) {
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

    // ── Bank render + refresh loop ───────────────────────────────
    // Phase 152: both branches now render through the SAME renderStrip()
    // — a designer-authored view supplies its own {overrides,show,hotkey,
    // width} per strip (already the exact shape renderStrip() expects,
    // straight from console_view_attach_strips()); the auto view
    // synthesizes an equivalent template per channel via
    // defaultShowTemplate(). The bank stays the plain flex-wrap flow
    // (console.css's .console-bank/.console-strip-wide, unchanged) in
    // BOTH cases — there is no more absolute-canvas mode.
    function renderBank() {
        bank.innerHTML = '';
        openFeeds = {};
        var count = document.getElementById('consoleChannelCount');
        var view = currentView();
        var rendered = 0;

        if (view) {
            for (var i = 0; i < view.strips.length; i++) {
                var s = view.strips[i];
                var ch = channelsById[s.channel_id];
                if (!ch) { continue; } // channel removed since publish — fail soft
                bank.appendChild(renderStrip(ch, s));
                rendered++;
            }
            if (!rendered) {
                bank.appendChild(el('div', 'text-body-secondary p-4',
                    'This view has no strips yet. Open the designer to add channels.'));
            }
        } else {
            if (activeView !== 'auto') {
                // Saved tab no longer exists — fall back.
                activeView = 'auto';
                renderTabs();
            }
            for (var k = 0; k < channels.length; k++) {
                if (int0(channels[k].enabled) !== 1) { continue; } // auto view: enabled only
                var autoCh = channels[k];
                bank.appendChild(renderStrip(autoCh, { overrides: {}, show: defaultShowTemplate(autoCh.capabilities || {}), hotkey: null, width: 1 }));
                rendered++;
            }
            if (!rendered) {
                bank.appendChild(el('div', 'text-body-secondary p-4',
                    'No channels enabled. Configure channels in Settings, then Sync Channels.'));
            }
        }
        if (count) { count.textContent = String(rendered); }
        paintAudioState();
        paintPatchState();
        renderSimulselectBar();
    }

    // ── Hotkeys (Phase 152) ─────────────────────────────────────────
    // A strip's optional hotkey (F1-F12 or a single character, assigned
    // in the designer) toggles Select on that strip — the same real,
    // already-wired action the Sel button drives. This is deliberately
    // the SAME action for every strip regardless of launcher/real-PTT
    // status: hardware-PTT/footswitch binding (console-hid.js, a later
    // task) is a separate mechanism that will need to exclude launcher
    // strips (data-launcher="1") from its own eligible-target set — see
    // renderStrip()'s docblock — but a keyboard hotkey merely changing
    // which channel has the operator's attention in the mix is safe and
    // useful on a launcher strip too. Ignored while focus is in a text
    // input/textarea so typing a message never triggers a strip switch.
    document.addEventListener('keydown', function (e) {
        if (!window.ConsoleAudio) { return; }
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) { return; }
        var key = String(e.key).toUpperCase().replace(/"/g, '');
        var strip = bank.querySelector('[data-hotkey="' + key + '"]');
        if (!strip) { return; }
        e.preventDefault();
        var chId = strip.getAttribute('data-channel-id');
        window.ConsoleAudio.setSelected(chId, !window.ConsoleAudio.getState(chId).selected);
    });

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
                // If the operator has never explicitly picked a tab (no
                // stored preference at all) and an admin has published at
                // least one shared view, land on that view instead of the
                // generic auto-generated "All Channels" fallback --
                // otherwise every per-strip designer customization (label/
                // accent-color overrides, show-flags) is invisible until the
                // operator happens to click over to the named tab by hand.
                // "All Channels" is itself a real, rememberable choice
                // (hadStoredView is only false when the key was genuinely
                // absent, never when it was explicitly set to 'auto') so an
                // operator who deliberately prefers it is never overridden.
                if (!hadStoredView && activeView === 'auto' && views.length) {
                    activeView = String(views[0].id);
                }
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
            }).then(function (r) { return r.json(); }).then(function (j) {
                syncBtn.disabled = false;
                if (j && j.ok) {
                    var r = j.result || {};
                    showToast('success', 'Sync complete: ' + (r.created || 0) + ' created, '
                        + (r.updated || 0) + ' updated, ' + (r.pruned || 0) + ' pruned.');
                } else {
                    showToast('danger', (j && j.error) || 'Sync failed.');
                }
                refresh(true);
            }).catch(function () {
                syncBtn.disabled = false;
                showToast('danger', 'Sync failed (network error).');
            });
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

    // Phase 152 persona review #1 (the veteran's strongest point): an
    // operator's eyes are on the CAD/map during an incident, not the
    // strip, so TX confirmation needs a real, fast (<300ms) TONE, and a
    // disconnect needs a PERSISTENT alarm (sound + visual), not a quiet
    // gray strip. Both fire off the browser-leg's own server-confirmed
    // events (console-mic.js's tx_started/tx_ended and connection-state
    // notifications) — never a client-side click alone, matching
    // prerequisite #7's whole reason for existing.
    var matrixAlarmBar = null;
    function matrixAlarmEl() {
        if (matrixAlarmBar) { return matrixAlarmBar; }
        matrixAlarmBar = el('div', 'console-matrix-alarm d-none');
        matrixAlarmBar.setAttribute('role', 'alert');
        (document.getElementById('consoleSimulselectBar') || bank.parentNode || bank)
            .parentNode.insertBefore(matrixAlarmBar, bank);
        return matrixAlarmBar;
    }
    function beep(freq, ms) {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.frequency.value = freq;
            gain.gain.value = 0.15;
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            setTimeout(function () { osc.stop(); ctx.close(); }, ms);
        } catch (e) { /* no Web Audio available — visual-only fallback below still fires */ }
    }
    if (window.ConsoleMatrix) {
        window.ConsoleMatrix.subscribeTxState(function (chanId, tx) {
            if (tx === 'started') { beep(880, 120); }
        });
        window.ConsoleMatrix.subscribeConnState(function (state) {
            var bar = matrixAlarmEl();
            if (state === 'disconnected') {
                bar.textContent = 'Independent matrix audio DISCONNECTED — reconnecting… PTT/Monitor on Matrix Audio strips is silent until this clears.';
                bar.classList.remove('d-none');
                beep(220, 400);
            } else if (state === 'connected') {
                bar.classList.add('d-none');
            }
            // 'failed' (never-yet-connected, e.g. install has no matrix
            // service deployed, or the operator declined the mic prompt)
            // is deliberately silent here — it's the default, correct
            // state on most installs today, not an alarm-worthy event.
        });
    }

    // Phase 155 (GH#151/GH#129) — comm:rx_state from api/matrix-channel-state.php:
    // audio began/stopped arriving from a digital voice bridge. channel_id is
    // the comm_channels id the strips are keyed on.
    function paintRxLamp(channelId, on) {
        var strips = bank.querySelectorAll('[data-channel-id="' + channelId + '"]');
        for (var i = 0; i < strips.length; i++) {
            var lamp = strips[i].querySelector('.console-rx-lamp');
            if (lamp) { lamp.classList.toggle('console-rx-lamp-on', !!on); }
        }
    }

    function onRxState(d) {
        if (!d || d.channel_id === undefined || d.channel_id === null) { return; }
        var id = String(d.channel_id);
        if (rxActive[id]) { window.clearTimeout(rxActive[id]); delete rxActive[id]; }
        if (d.rx === 'started') {
            rxActive[id] = window.setTimeout(function () {
                delete rxActive[id];
                paintRxLamp(id, false);
            }, RX_LAMP_WATCHDOG_MS);
            paintRxLamp(id, true);
        } else {
            paintRxLamp(id, false);
        }
    }

    // Patch rail (Console rebuild) — console-patch-rail.js emits these
    // LOCAL (non-SSE) events on this same page; a light repaint, never a
    // full renderBank(), so this never disrupts another strip's open text
    // drawer or in-progress typing (see paintPatchState()'s own docblock).
    // event-bus.js loads via inc/navbar.php's loadGlobal() (a dynamically-
    // injected <script>, no ordering guarantee vs. this page's own static
    // tags) — poll briefly rather than assume it's ready, same reasoning
    // as console-patch-rail.js's own waitForEventBus().
    (function waitForEventBusThen(triesLeft) {
        if (window.EventBus) {
            window.EventBus.on('console:selection-clear', paintPatchState);
            window.EventBus.on('console:patches-changed', paintPatchState);
            // Phase 155 — RX lamp for digital voice bridge channels.
            window.EventBus.on('comm:rx_state', onRxState);
            return;
        }
        if (triesLeft > 0) { setTimeout(function () { waitForEventBusThen(triesLeft - 1); }, 100); }
    })(50);

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
