/*
 * Behaviour harness for tests/test_voice_bridge_console_ui.php (Phase 155).
 *
 * Runs the REAL assets/js/console-mic.js and assets/js/console.js inside
 * jsdom against stubbed fetch() responses and a stub EventBus, then reports
 * what the DOM looks like as one JSON object on stdout. Nothing here
 * re-implements console logic: the strips are rendered by the shipped code.
 *
 * usage: node _p155_console_strip.js <repo-root>
 * needs: the `jsdom` package (NODE_PATH).
 */
'use strict';
var fs = require('fs');
var path = require('path');
var JSDOM = require('jsdom').JSDOM;

var root = process.argv[2];
var micSrc = fs.readFileSync(path.join(root, 'assets/js/console-mic.js'), 'utf8');
var consoleSrc = fs.readFileSync(path.join(root, 'assets/js/console.js'), 'utf8');

var CHANNELS = [
    { id: 1, channel_key: 'dvm:p25', adapter: 'dvmproject', label: 'County P25', short_label: null, enabled: 1,
      regulatory_class: 'amateur', state: 'unknown', last_rx_at: null, last_caller: null,
      capabilities: { voice_rx: true }, config: { mode: 'p25', talkgroup: '9001', listen_only: true } },
    { id: 2, channel_key: 'dmr_bm:3127', adapter: 'dmr_bm', label: 'DMR TG 3127', short_label: null, enabled: 1,
      regulatory_class: 'amateur', state: 'connected', last_rx_at: null, last_caller: null,
      capabilities: { voice_rx: true, voice_tx: true, ptt_floor: true }, config: {} }
];

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function boot(canTx, extra) {
    extra = extra || {};
    var dom = new JSDOM(
        '<!doctype html><html><head><meta name="csrf-token" content="t"></head>' +
        '<body data-can-tx="' + (canTx ? '1' : '0') + '" data-can-send="0" data-can-patch="0">' +
        '<ul id="consoleTabs" class="d-none"></ul><div id="consoleBank"></div><span id="consoleChannelCount"></span>' +
        '</body></html>',
        { url: 'http://localhost/console.php', runScripts: 'outside-only', pretendToBeVisual: true });
    var w = dom.window;
    var handlers = {};
    var gum = { calls: 0 };
    var sockets = [];
    w.EventBus = {
        on: function (ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); },
        emit: function (ev, d) { (handlers[ev] || []).forEach(function (f) { f(d); }); }
    };
    w.__emit = w.EventBus.emit;
    w.fetch = function (url) {
        var u = String(url);
        var body = {};
        if (u.indexOf('api/channels.php') === 0) { body = { channels: CHANNELS }; }
        else if (u.indexOf('api/console-views.php') === 0) {
            body = extra.views ? { views: extra.views, my_views: [] } : { views: [], my_views: [{ id: 5, name: 'Mine', strips: [] }] };
        }
        else if (u.indexOf('api/console-session.php') === 0) { body = { ok: true, session_token: 'tok', ws_url: 'ws://127.0.0.1:1/x' }; }
        return Promise.resolve({ status: 200, json: function () { return Promise.resolve(body); } });
    };
    w.WebSocket = function (url) {
        this.url = url; this.readyState = 0; this.sent = [];
        this.send = function (d) { this.sent.push(d); };
        this.addEventListener = function () {};
        sockets.push(this);
    };
    w.WebSocket.OPEN = 1;
    if (!w.navigator.mediaDevices) { Object.defineProperty(w.navigator, 'mediaDevices', { value: {}, configurable: true }); }
    w.navigator.mediaDevices.getUserMedia = function () { gum.calls++; return Promise.reject(new Error('denied in test')); };
    w.eval(micSrc);
    if (extra.matrixAudioOn) {
        // Stand-in for console-audio.js: every channel reports Matrix Audio
        // engaged, which is the state in which a matrix-backed strip grows a
        // real PTT button (if it can transmit at all).
        w.ConsoleAudio = {
            registerChannels: function () {},
            getState: function () { return { selected: false, mon: true, muted: false, volume: 100, simulselect: false, matrixAudio: true }; },
            anySelected: function () { return false; },
            textProminence: function () { return 'normal'; },
            setMatrixAudio: function (id, v, cb) { if (cb) { cb(true); } },
            subscribe: function () {},
            load: function (cb) { if (cb) { cb(); } },
            simulselectMembers: function () { return []; }
        };
    }
    w.eval(consoleSrc);
    return { dom: dom, w: w, gum: gum, sockets: sockets };
}

function strip(w, id) { return w.document.querySelector('[data-channel-id="' + id + '"]'); }
function q(el, sel) { return el ? el.querySelector(sel) : null; }

(async function () {
    var out = {};

    // ── no TX permission ────────────────────────────────────────────
    var a = boot(false);
    await sleep(80);
    var sA = strip(a.w, 1), sB = strip(a.w, 2);
    out.strips_rendered = !!(sA && sB);
    var note = q(sA, '.console-listen-only-note');
    out.dvm_note_text = note ? note.textContent : null;
    out.dvm_has_ptt_button = !!q(sA, '.console-ptt');
    out.dvm_has_launcher_mark = sA ? sA.hasAttribute('data-launcher') : null;
    out.dvm_has_real_ptt_mark = sA ? sA.hasAttribute('data-real-ptt') : null;
    var tog = q(sA, '.console-matrix-audio-toggle');
    out.dvm_toggle_present_without_tx = !!tog;
    out.dvm_toggle_label = tog ? q(tog, '.form-check-label').textContent : null;
    var lamp = q(sA, '.console-rx-lamp');
    out.dvm_rx_lamp_present = !!lamp;
    out.dvm_rx_lamp_text = lamp ? lamp.textContent : null;
    out.dvm_rx_lamp_initially_on = lamp ? lamp.classList.contains('console-rx-lamp-on') : null;
    out.dvm_audio_block_present = !!q(sA, '.console-audio-controls');
    out.dvm_reg_badge = (q(sA, '.console-strip-reg') || {}).textContent || null;
    out.dmr_reg_badge = (q(sB, '.console-strip-reg') || {}).textContent || null;
    out.dmr_toggle_present_without_tx = !!q(sB, '.console-matrix-audio-toggle');
    out.dmr_note_no_tx = (q(sB, '.console-strip-note') || {}).textContent || null;

    // ── RX lamp via the event bus ───────────────────────────────────
    a.w.__emit('comm:rx_state', { channel_id: 1, rx: 'started' });
    out.lamp_on_after_started = q(strip(a.w, 1), '.console-rx-lamp').classList.contains('console-rx-lamp-on');
    out.other_strip_lamp_untouched = !q(strip(a.w, 2), '.console-rx-lamp') || !q(strip(a.w, 2), '.console-rx-lamp').classList.contains('console-rx-lamp-on');
    // a repaint of the whole bank must not lose a lit lamp
    var tabs = a.w.document.querySelectorAll('#consoleTabs a');
    out.tab_links = tabs.length;
    if (tabs.length) { tabs[tabs.length - 1].dispatchEvent(new a.w.Event('click', { cancelable: true })); }
    out.lamp_on_after_repaint = q(strip(a.w, 1), '.console-rx-lamp').classList.contains('console-rx-lamp-on');
    a.w.__emit('comm:rx_state', { channel_id: 1, rx: 'ended' });
    out.lamp_off_after_ended = !q(strip(a.w, 1), '.console-rx-lamp').classList.contains('console-rx-lamp-on');
    a.w.__emit('comm:rx_state', { channel_id: 99, rx: 'started' });     // unknown channel: must not throw
    a.w.__emit('comm:rx_state', null);                                   // junk: must not throw
    out.junk_events_survived = true;

    // ── connect() without TX permission never asks for the microphone ──
    var connectOk = null;
    a.w.ConsoleMatrix.connect(function (ok) { connectOk = ok; });
    await sleep(80);
    out.no_tx_getusermedia_calls = a.gum.calls;
    out.no_tx_websocket_opened = a.sockets.length;

    // ── with TX permission ──────────────────────────────────────────
    var b = boot(true);
    await sleep(80);
    var sA2 = strip(b.w, 1), sB2 = strip(b.w, 2);
    out.tx_dvm_has_ptt_button = !!q(sA2, '.console-ptt');
    out.tx_dvm_has_real_ptt_mark = sA2 ? sA2.hasAttribute('data-real-ptt') : null;
    out.tx_dvm_toggle_present = !!q(sA2, '.console-matrix-audio-toggle');
    out.tx_dmr_toggle_present = !!q(sB2, '.console-matrix-audio-toggle');
    b.w.ConsoleMatrix.connect(function () {});
    await sleep(80);
    out.tx_getusermedia_calls = b.gum.calls;

    // ── Matrix Audio engaged on every strip (TX permission held) ────
    // A designer-authored view that asks for a PTT on BOTH strips: even then a
    // channel that cannot transmit must not grow one.
    var pttShow = { ptt: true, sel: true, mon: true, mute: true, vol: true, text: false, vu: false, recall: false, patchchips: false };
    var c = boot(true, {
        matrixAudioOn: true,
        views: [{ id: 7, name: 'PTT view', strips: [
            { channel_id: 1, overrides: {}, show: pttShow, hotkey: null, width: 1 },
            { channel_id: 2, overrides: {}, show: pttShow, hotkey: null, width: 1 }
        ] }]
    });
    await sleep(80);
    var sA3 = strip(c.w, 1), sB3 = strip(c.w, 2);
    out.engaged_dmr_has_ptt_button = !!q(sB3, '.console-ptt');
    out.engaged_dmr_has_real_ptt_mark = sB3 ? sB3.hasAttribute('data-real-ptt') : null;
    out.engaged_dvm_has_ptt_button = !!q(sA3, '.console-ptt');
    out.engaged_dvm_has_real_ptt_mark = sA3 ? sA3.hasAttribute('data-real-ptt') : null;
    var noteEngaged = q(sA3, '.console-listen-only-note');
    out.engaged_dvm_note_text = noteEngaged ? noteEngaged.textContent : null;

    // ── parity ──────────────────────────────────────────────────────
    out.mic_isMatrixBacked = {
        dvmproject: a.w.ConsoleMatrix.isMatrixBacked('dvmproject'),
        usrp_bridge: a.w.ConsoleMatrix.isMatrixBacked('usrp_bridge'),
        zello: a.w.ConsoleMatrix.isMatrixBacked('zello')
    };

    process.stdout.write(JSON.stringify(out) + '\n');
    process.exit(0);
})().catch(function (e) {
    process.stdout.write(JSON.stringify({ harness_error: String(e && e.stack || e) }) + '\n');
    process.exit(0);
});
