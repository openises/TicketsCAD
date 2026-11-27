'use strict';
// GH#108 (panel-review section 4): the browser phone's ephemeral-credential
// behaviour. Real widget + real phone-dial-logic.js in jsdom, FAKE JsSIP (no
// PBX): everything about registration here is simulated. Proves that
//   - in ephemeral mode (payload carries expires_at) the widget registers with
//     the MINTED credential, schedules a refresh well before expiry, and on
//     refresh re-fetches and re-registers with the NEW credential;
//   - in static mode (no expires_at) the widget registers with the stored
//     password and schedules NO refresh timer -- i.e. byte-identical to before.
var H = require('./_phone_widget_harness.js');
var L = require('../assets/js/phone-dial-logic.js');
var root = process.argv[2];
var R = H.reporter();

var WSS = 'wss://pbx.example:8089/ws';
var REFRESH_FLOOR = 60000;   // the real refresh delay for a 600s TTL is ~540s; a small retry timer is well under this

// A stateful payload: first my_extension fetch -> ephemeral cred #1, the
// refresh fetch -> cred #2 (distinct username/password/expiry). The widget
// consumes these verbatim; it never recomputes the HMAC, so marker values are
// enough to prove which credential it used.
function ephemeralPayloads(nowSec) {
    var creds = [
        { sip_username: (nowSec + 600) + ':101', sip_password: 'HMAC-ONE', expires_at: nowSec + 600 },
        { sip_username: (nowSec + 1200) + ':101', sip_password: 'HMAC-TWO', expires_at: nowSec + 1200 }
    ];
    var i = 0;
    return function () {
        var c = creds[Math.min(i, creds.length - 1)];
        i++;
        return {
            bound: true, extension: '101', label: 'Desk 1',
            sip_username: c.sip_username, sip_password: c.sip_password, expires_at: c.expires_at,
            wss_url: WSS, general_number: '100', register_scope: 'phone_page'
        };
    };
}

function staticPayload() {
    return {
        bound: true, extension: '101', label: 'Desk 1',
        sip_username: '101', sip_password: 'stored-secret',
        wss_url: WSS, general_number: '100', register_scope: 'phone_page'
    };
}

// Build a standalone Phone window (always registers), but capture every
// window.setTimeout the widget schedules so we can find and fire the
// credential-refresh timer without waiting minutes.
async function buildCapturing(payload) {
    var env = await H.build({ root: root, url: 'http://localhost/newui/phone.php', standalone: true,
                              payload: payload, skipWidget: true });
    env.timers = [];
    var realST = env.window.setTimeout;
    env.window.setTimeout = function (fn, delay) {
        env.timers.push({ fn: fn, delay: delay });
        return realST(fn, delay);
    };
    env.load('assets/js/console-workstation.js');
    env.load('assets/js/phone-dial-logic.js');
    env.load('assets/js/phone-widget.js');
    await env.flush();
    return env;
}

function bigTimers(env) {
    return env.timers.filter(function (t) { return typeof t.delay === 'number' && t.delay >= REFRESH_FLOOR; });
}

(async function () {
    var nowSec = Math.floor(Date.now() / 1000);

    // ── Pure logic: credentialRefreshDelayMs / isAuthFailure ───────────
    R.check('credentialRefreshDelayMs: no expiry (static mode) -> null',
        L.credentialRefreshDelayMs(0, nowSec) === null && L.credentialRefreshDelayMs(undefined, nowSec) === null
        && L.credentialRefreshDelayMs(null, nowSec) === null);
    R.check('credentialRefreshDelayMs: a comfortable TTL refreshes a 60s margin early',
        L.credentialRefreshDelayMs(nowSec + 600, nowSec) === 540000, String(L.credentialRefreshDelayMs(nowSec + 600, nowSec)));
    R.check('credentialRefreshDelayMs: a short TTL uses a third-of-life margin',
        L.credentialRefreshDelayMs(nowSec + 60, nowSec) === 40000, String(L.credentialRefreshDelayMs(nowSec + 60, nowSec)));
    R.check('credentialRefreshDelayMs: an already-expired/tiny TTL floors at 1000ms (never 0 or negative)',
        L.credentialRefreshDelayMs(nowSec + 3, nowSec) === 1000 && L.credentialRefreshDelayMs(nowSec - 100, nowSec) === 1000);
    R.check('credentialRefreshDelayMs: a caller-supplied floor is honoured',
        L.credentialRefreshDelayMs(nowSec - 100, nowSec, 250) === 250);
    R.check('isAuthFailure: only auth rejections',
        L.isAuthFailure('Authentication Error') === true && L.isAuthFailure('Rejected') === true
        && L.isAuthFailure('Connection Error') === false && L.isAuthFailure('') === false && L.isAuthFailure(undefined) === false);

    // ── Static mode: unchanged, and NO refresh timer ───────────────────
    var st = await buildCapturing(staticPayload());
    var stUa = st.J.latestUa();
    R.check('static mode: the widget registers with the STORED password (unchanged)',
        !!stUa && stUa.started && stUa.config.password === 'stored-secret' && stUa.config.uri === 'sip:101@pbx.example',
        stUa && (stUa.config.uri + ' / ' + stUa.config.password));
    R.check('static mode: NO credential-refresh timer is scheduled (byte-identical to before)',
        bigTimers(st).length === 0, 'big timers=' + bigTimers(st).length);
    st.close();

    // ── Ephemeral mode: register with the minted cred, refresh, re-register ──
    var ep = await buildCapturing(ephemeralPayloads(nowSec));
    var ua1 = ep.J.latestUa();
    R.check('ephemeral mode: the widget registers with the MINTED credential, not a stored password',
        !!ua1 && ua1.started && ua1.config.password === 'HMAC-ONE'
        && ua1.config.uri === 'sip:' + (nowSec + 600) + ':101@pbx.example',
        ua1 && (ua1.config.uri + ' / ' + ua1.config.password));
    ep.J.register(ua1);
    // The delay is ~540000ms for a 600s TTL (refresh 60s before expiry); allow a
    // small window so a second-boundary tick during the test does not flake it.
    var refreshDelay = bigTimers(ep).length >= 1 ? bigTimers(ep)[0].delay : -1;
    R.check('ephemeral mode: a refresh timer IS scheduled, comfortably before expiry',
        refreshDelay >= REFRESH_FLOOR && refreshDelay <= 600000 && refreshDelay >= 535000,
        'delay=' + refreshDelay);

    // Fire the refresh: it re-fetches (payload #2) and re-registers with cred #2.
    var uaCountBefore = ep.J.uas.length;
    bigTimers(ep)[0].fn();
    await ep.flush();
    var ua2 = ep.J.latestUa();
    R.check('ephemeral refresh: a NEW user agent registers with the REFRESHED credential',
        ep.J.uas.length === uaCountBefore + 1 && ua2.config.password === 'HMAC-TWO'
        && ua2.config.uri === 'sip:' + (nowSec + 1200) + ':101@pbx.example',
        ua2 && (ua2.config.uri + ' / ' + ua2.config.password));
    R.check('ephemeral refresh: the OLD user agent is stopped (no double registration)',
        ua1.stopped === true, 'old stopped=' + ua1.stopped);
    R.check('ephemeral refresh: the next refresh is scheduled again',
        bigTimers(ep).length >= 2, 'big timers=' + bigTimers(ep).length);
    ep.close();

    // ── Ephemeral: an auth rejection triggers one re-fetch + retry ─────
    var af = await buildCapturing(ephemeralPayloads(nowSec));
    var afUa1 = af.J.latestUa();
    var afCountBefore = af.J.uas.length;
    afUa1.emit('registrationFailed', { cause: 'Authentication Error' });
    await af.flush();
    var afUa2 = af.J.latestUa();
    R.check('ephemeral: an auth rejection re-fetches a fresh credential and re-registers once',
        af.J.uas.length === afCountBefore + 1 && afUa2.config.password === 'HMAC-TWO',
        'uas=' + af.J.uas.length + ' pw=' + (afUa2 && afUa2.config.password));
    // A SECOND auth failure must not loop forever (retried-once flag).
    var afCount2 = af.J.uas.length;
    afUa2.emit('registrationFailed', { cause: 'Authentication Error' });
    await af.flush();
    R.check('ephemeral: a repeated auth rejection does NOT loop (retried only once until a success)',
        af.J.uas.length === afCount2, 'uas=' + af.J.uas.length);
    af.close();

    R.done();
})().catch(function (e) { console.log('FAIL|node script crashed|' + (e && e.stack || e)); process.exit(0); });
