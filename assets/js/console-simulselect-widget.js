/**
 * NewUI v4.0 — Simulselect group-transmit widget (2026-09-08)
 *
 * Unification plan step 3, specs/phase-152-comms-console-v2/tasks.md:
 * Eric asked for "select which channel my transmission will be delivered
 * on" from any page, not just the full Communications Console. A
 * 3-persona design review (fire/EMS dispatcher, ARES/RACES net-control
 * operator, sysadmin/maintainer) unanimously rejected redefining what the
 * Zello/Radio widgets' own "Push to Talk" buttons do (a control's meaning
 * must never depend on state invisible from the control itself — the
 * fire/EMS persona's own words). This widget is the safe shape instead:
 * a genuinely NEW, additive control, built entirely on top of the
 * already-existing Simulselect mechanism (console-audio.js /
 * console-audio-logic.js), which was ALREADY the safe pattern — its
 * master PTT has always been a separate button from the widgets' own,
 * never a redefinition of them. All this does is make that existing
 * mechanism reachable from a small navbar dropdown instead of only the
 * full console.php strip bank.
 *
 * Per the ARES persona's explicit request: a visible "TX target" readout
 * naming exactly which channels a press will fire on, never a settings
 * checkbox the operator has to go hunt for.
 *
 * Depends on window.ConsoleAudioLogic + window.ConsoleAudio (both loaded
 * globally via inc/navbar.php as of this same change) and, for matrix-
 * backed channels (DMR-via-matrix, the dispatcher intercom), window.
 * ConsoleMatrix (console-mic.js, also global). Degrades to "no channels
 * available" if none of those are present rather than throwing — matches
 * this project's standing schema/dependency-resilience discipline.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var root = document.getElementById('simulselectWidget');
    if (!root) { return; } // RBAC hid it server-side (no screen.console) -- nothing to do

    var Logic = window.ConsoleAudioLogic;
    var Audio = window.ConsoleAudio;
    if (!Logic || !Audio) { return; } // shouldn't happen given load order, but never throw on a missing dependency

    var canTx = root.getAttribute('data-can-tx') === '1';
    var listEl = document.getElementById('simulselectChannelList');
    var badgeEl = document.getElementById('navSimulselectBadge');
    var readoutEl = document.getElementById('simulselectTargetReadout');
    var pttBtn = document.getElementById('simulselectPttBtn');
    var navToggleBtn = document.getElementById('navSimulselectBtn');
    var panelContent = document.getElementById('simulselectPanelContent');
    var detachBtn = document.getElementById('simulselectDetach');

    var channels = [];       // TX-capable channels only: [{id, label, adapter, capabilities}]
    var pttActive = false;

    function txCapable(ch) {
        return !!(ch && ch.capabilities && ch.capabilities.voice_tx);
    }

    function el(tag, cls) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        return e;
    }

    function renderList() {
        listEl.innerHTML = '';
        if (!channels.length) {
            var empty = el('div', 'text-center text-body-secondary py-2 small');
            empty.textContent = 'No transmit-capable channels available.';
            listEl.appendChild(empty);
            return;
        }
        // 2026-09-08 (SKYWARN-net-control persona review finding): Zello
        // and Radio are each a single global widget, so checking a SECOND
        // channel of the same adapter family cannot actually transmit on
        // it -- only the first one Audio.simulselectTargets() resolves
        // will fire. Mark any checked-but-excluded box so the control's
        // own visible state never promises more than a press will deliver
        // (the same "never invisible state" rule this widget was built
        // around in the first place).
        var resolvedIds = {};
        var targets = Audio.simulselectTargets();
        for (var t = 0; t < targets.length; t++) { resolvedIds[String(targets[t].id)] = true; }
        for (var i = 0; i < channels.length; i++) {
            (function (ch) {
                var row = el('div', 'form-check');
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = 'simulselectChk' + ch.id;
                var st = Audio.getState(ch.id);
                input.checked = !!st.simulselect;
                input.addEventListener('change', function () {
                    Audio.setSimulselect(ch.id, input.checked);
                });
                var label = document.createElement('label');
                label.className = 'form-check-label small';
                label.setAttribute('for', input.id);
                label.textContent = ch.label || ch.adapter;
                row.appendChild(input);
                row.appendChild(label);
                if (input.checked && !resolvedIds[String(ch.id)]) {
                    var warn = el('span', 'text-warning small ms-1');
                    warn.title = 'Only one ' + (ch.adapter || 'channel') + ' channel can transmit at a time -- another checked channel already has it. This one will NOT fire.';
                    warn.textContent = '(will not transmit)';
                    row.appendChild(warn);
                }
                listEl.appendChild(row);
            })(channels[i]);
        }
    }

    function renderReadout() {
        var targets = Audio.simulselectTargets();
        if (badgeEl) {
            if (targets.length) {
                badgeEl.textContent = String(targets.length);
                badgeEl.classList.remove('d-none');
            } else {
                badgeEl.classList.add('d-none');
            }
        }
        if (readoutEl) {
            readoutEl.textContent = targets.length
                ? 'Will transmit on: ' + targets.map(function (t) { return t.label; }).join(', ')
                : 'No channels selected.';
        }
        if (pttBtn) {
            pttBtn.disabled = !canTx || !targets.length;
            pttBtn.title = !canTx
                ? 'Listen-only (no action.console_tx permission)'
                : (targets.length ? 'Hold to transmit on: ' + targets.map(function (t) { return t.label; }).join(', ') : 'Select at least one channel first');
        }
    }

    function renderPanel() {
        renderList();
        renderReadout();
    }

    function wirePtt() {
        if (!pttBtn) { return; }
        var start = function (e) {
            e.preventDefault();
            if (pttBtn.disabled || pttActive) { return; }
            pttActive = true;
            Audio.simulselectPttStart();
            pttBtn.classList.add('active');
        };
        var stop = function () {
            if (!pttActive) { return; }
            pttActive = false;
            Audio.simulselectPttStop();
            pttBtn.classList.remove('active');
        };
        pttBtn.addEventListener('mousedown', start);
        pttBtn.addEventListener('touchstart', start, { passive: false });
        pttBtn.addEventListener('mouseup', stop);
        pttBtn.addEventListener('mouseleave', stop);
        pttBtn.addEventListener('touchend', stop);
        pttBtn.addEventListener('touchcancel', stop);
    }

    function boot() {
        fetch('api/channels.php')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var all = (j && j.channels) || [];
                channels = all.filter(txCapable);
                Audio.registerChannels(all); // register everything, not just TX-capable -- matches console.js's own convention and keeps simulselectMembers()'s txCapableIds filter meaningful
                Audio.load(renderPanel);
                Audio.subscribe(renderPanel);
            })
            .catch(function () {
                listEl.innerHTML = '';
                var err = el('div', 'text-center text-danger py-2 small');
                err.textContent = 'Could not load channels.';
                listEl.appendChild(err);
            });
    }

    // Detach into its own window (2026-09-08, Eric's request) -- see
    // assets/js/window-detach.js's own docblock. Only #simulselectPanel
    // Content moves, never the outer Bootstrap .dropdown-menu wrapper
    // (see the markup's own comment in inc/navbar.php for why). While
    // detached, the nav toggle button is disabled so the now-empty
    // dropdown can't be confusingly reopened in the main window.
    var detachHandle = null;
    function wireDetach() {
        if (!detachBtn || !panelContent || !window.WindowDetach) { return; }
        detachBtn.addEventListener('click', function (e) {
            e.stopPropagation(); // stop this click from also toggling/closing the Bootstrap dropdown
            if (detachHandle) { return; }
            if (navToggleBtn) { navToggleBtn.disabled = true; }
            detachHandle = window.WindowDetach.open(panelContent, {
                width: 320, height: 420, title: 'Group Transmit — TicketsCAD',
                onClose: function (reason) {
                    detachHandle = null;
                    if (navToggleBtn) { navToggleBtn.disabled = false; }
                    if (reason === 'unsupported') {
                        console.warn('[console-simulselect-widget] could not detach -- the browser blocked the popup window');
                    }
                }
            });
        });
    }

    wirePtt();
    wireDetach();
    boot();
})();
