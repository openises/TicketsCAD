/**
 * NewUI v4.0 — Console rebuild: physical PTT (Phase 152 prerequisite #8).
 *
 * "No server changes; this is pure client wiring on top of the already-
 * generalized PTT path" (plan.md) — this file was deliberately deferred
 * until the Console rebuild shipped a real strip to bind to (tasks.md's
 * own note: "there is no console-hid.js that could exist right now that
 * would do anything but hold a dead reference to a DOM element that
 * doesn't exist yet"). Strips exist now; this closes the prerequisite.
 *
 * ELIGIBILITY: a hardware pedal or the keyboard fallback keys whichever
 * currently-SELECTED strip(s) carry the data-real-ptt="1" attribute -- a
 * marker console.js sets ONLY when that strip's real matrix audio is
 * engaged AND the operator holds TX permission (assets/js/console.js's
 * own comment at the point it's set). Deliberately NOT inferred from the
 * ABSENCE of data-launcher="1": a text-only channel also lacks that
 * attribute (console.js only computes launcher/real-PTT status inside
 * its own show.ptt && (voice_tx || voice_rx) branch) but is never a real
 * PTT target either, so a double-negative check would wrongly key a
 * hardware press at a channel with no audio to send at all.
 *
 * "Stomping a pedal on a launcher strip must never silently do nothing"
 * (tasks.md's own framing of this requirement): when NO currently-
 * selected strip is real-PTT-eligible, this file shows a brief, visible
 * on-screen notice rather than doing nothing at all — so an operator who
 * presses a pedal expecting to transmit, but has only a launcher (Zello/
 * unjoined-matrix) strip selected, gets an honest "nothing to key" signal
 * instead of silent confusion about whether the hardware itself is
 * broken.
 *
 * TWO input paths, per plan.md's own requirement:
 *   1. Gamepad API polling -- many USB foot switches enumerate as a
 *      simple 1-button gamepad; button 0 is the conventional mapping.
 *   2. A documented keyboard-hold fallback for switches/pedals that
 *      instead emulate a keystroke (common on cheap USB foot pedals),
 *      or for an operator with no pedal at all. Deliberately NOT the
 *      Space bar (zello-widget.js / radio-widget.js already bind Space
 *      to PTT for their OWN legacy singleton-widget audio) and NOT any
 *      of F1-F12 (admin-assignable per-strip Select hotkeys, console.js)
 *      -- the backtick/grave key is unclaimed anywhere else in this app
 *      and easy to find by feel without looking at the keyboard.
 *
 * Both paths converge on the SAME startPtt()/stopPtt() -- one real
 * ConsoleMatrix.talkStart()/talkEnd() call per eligible channel, the
 * identical function every mouse/touch PTT button on the page already
 * calls, never a parallel reimplementation.
 *
 * Live-verification against real USB foot-switch hardware happens on the
 * validation instance, by hand -- no CI fixture can press a real pedal.
 * Document the exact device(s) tested (make/model, Gamepad-vs-keystroke
 * emulation) in specs/phase-152-comms-console-v2/tasks.md when that
 * verification runs, per this prerequisite's own explicit instruction to
 * flag untested hardware rather than claim broad support.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var GAMEPAD_BUTTON_INDEX = 0;
    var GAMEPAD_POLL_INTERVAL_MS = 50;
    // The grave/backtick key -- written via fromCharCode so this file's
    // own source never contains a literal backtick byte (this project's
    // ES5 lint convention flags ANY backtick as a possible template
    // literal; this one is a single string-value character, not
    // interpolation, but the naive check can't tell the difference).
    var FALLBACK_KEY = String.fromCharCode(96);
    var NO_TARGET_NOTICE_MS = 2000;

    var keyHeld = false;
    var gamepadHeld = false;
    var activeChannelIds = [];
    var noticeEl = null;
    var noticeTimer = null;

    function eligibleChannelIds() {
        var out = [];
        if (!window.ConsoleAudio) { return out; }
        var strips = document.querySelectorAll('[data-real-ptt="1"]');
        for (var i = 0; i < strips.length; i++) {
            var id = strips[i].getAttribute('data-channel-id');
            if (!id) { continue; }
            var state = window.ConsoleAudio.getState(id);
            if (state && state.selected) { out.push(id); }
        }
        return out;
    }

    function showNoTargetNotice() {
        if (!noticeEl) {
            noticeEl = document.createElement('div');
            noticeEl.className = 'console-hid-notice';
            document.body.appendChild(noticeEl);
        }
        noticeEl.textContent = 'PTT pressed — no selected channel has live audio to transmit on';
        noticeEl.classList.add('console-hid-notice-visible');
        if (noticeTimer) { window.clearTimeout(noticeTimer); }
        noticeTimer = window.setTimeout(function () {
            noticeEl.classList.remove('console-hid-notice-visible');
        }, NO_TARGET_NOTICE_MS);
    }

    function startPtt() {
        if (activeChannelIds.length) { return; } // already keyed by the other input path
        var ids = eligibleChannelIds();
        if (!ids.length) {
            showNoTargetNotice();
            return;
        }
        activeChannelIds = ids;
        for (var i = 0; i < ids.length; i++) {
            if (window.ConsoleMatrix) { window.ConsoleMatrix.talkStart(ids[i]); }
        }
    }

    function stopPtt() {
        for (var i = 0; i < activeChannelIds.length; i++) {
            if (window.ConsoleMatrix) { window.ConsoleMatrix.talkEnd(activeChannelIds[i]); }
        }
        activeChannelIds = [];
    }

    // ── Keyboard fallback ────────────────────────────────────────────
    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) { return; }
        if (e.key !== FALLBACK_KEY || e.repeat) { return; }
        keyHeld = true;
        startPtt();
    });
    document.addEventListener('keyup', function (e) {
        if (e.key !== FALLBACK_KEY) { return; }
        keyHeld = false;
        if (!gamepadHeld) { stopPtt(); }
    });

    // ── Gamepad / USB-foot-switch polling ────────────────────────────
    function pollGamepads() {
        if (!navigator.getGamepads) { return; }
        var pads = navigator.getGamepads();
        var anyPressed = false;
        for (var i = 0; i < pads.length; i++) {
            var pad = pads[i];
            if (pad && pad.buttons && pad.buttons[GAMEPAD_BUTTON_INDEX] && pad.buttons[GAMEPAD_BUTTON_INDEX].pressed) {
                anyPressed = true;
                break;
            }
        }
        if (anyPressed && !gamepadHeld) {
            gamepadHeld = true;
            startPtt();
        } else if (!anyPressed && gamepadHeld) {
            gamepadHeld = false;
            if (!keyHeld) { stopPtt(); }
        }
    }
    if (navigator.getGamepads) {
        window.setInterval(pollGamepads, GAMEPAD_POLL_INTERVAL_MS);
    }

    // Exposed for tests and for a future settings/help panel that might
    // want to show the currently-eligible target set or the bound key.
    window.ConsoleHid = {
        eligibleChannelIds: eligibleChannelIds,
        FALLBACK_KEY: FALLBACK_KEY,
        GAMEPAD_BUTTON_INDEX: GAMEPAD_BUTTON_INDEX,
    };
})();
