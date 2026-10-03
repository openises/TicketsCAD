'use strict';
// GH#108 (S1 hardening): the widget's dial / answer path and its plain-English
// error states. Real widget in jsdom against a FAKE JsSIP -- no PBX, no real
// audio: everything below proves what the WIDGET asks JsSIP to do and what it
// tells the operator when JsSIP reports a failure, nothing about real media.
var H = require('./_phone_widget_harness.js');
var root = process.argv[2];
var R = H.reporter();

var WSS = 'wss://pbx.example:8089/ws';
var BOUND = { bound: true, extension: '101', label: 'Desk 1', sip_username: '101', sip_password: 'secret-for-test',
                wss_url: WSS, general_number: '100', register_scope: 'every_page' };

async function ready(extra) {
    var env = await H.build(Object.assign({ root: root, url: 'http://localhost/newui/console.php', payload: BOUND }, extra || {}));
    return env;
}
function footer(env) { return env.$('#phoneFooter').textContent; }

(async function () {
    // ── Dialing: the original GH#108 complaint ("outgoing calls need a separate SIP client") ──
    var a = await ready();
    var ua = a.J.latestUa();
    a.J.register(ua);
    a.$('#phoneDialInput').value = '4155551212';
    a.click('#phoneCallBtn');
    R.check('Call dials through ua.call with the typed number (the widget IS the SIP client)',
        a.J.calls.length === 1 && a.J.calls[0].target === '4155551212', JSON.stringify(a.J.calls.map(function (c) { return c.target; })));
    R.check('...asking for audio only (no video), with an ICE config object',
        a.J.calls[0].opts.mediaConstraints.audio === true && a.J.calls[0].opts.mediaConstraints.video === false
        && Array.isArray(a.J.calls[0].opts.pcConfig.iceServers));
    R.check('...and the in-call panel replaces the dial pad', a.shown('#phoneIncall') && !a.shown('#phoneDialpad'));

    a.J.calls[0].session.emit('failed', { cause: 'Busy' });
    R.check('a busy line reads "Busy." and the dial pad returns',
        /Busy\./.test(footer(a)) && a.shown('#phoneDialpad') && !a.shown('#phoneIncall'), footer(a));

    // Enter key dials; the general-number button dials the configured number.
    a.$('#phoneDialInput').value = '102';
    a.$('#phoneDialInput').dispatchEvent(new a.window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    R.check('Enter in the number box dials (keyboard-first)', a.J.calls.length === 2 && a.J.calls[1].target === '102');
    a.J.calls[1].session.terminate();
    a.click('#phoneGeneralBtn');
    R.check('the general-number shortcut dials the configured general number',
        a.J.calls.length === 3 && a.J.calls[2].target === '100');
    a.J.calls[2].session.terminate();
    a.$('#phoneDialInput').value = '   ';
    a.click('#phoneCallBtn');
    R.check('a blank number dials nothing', a.J.calls.length === 3);

    // Not registered yet -> say so instead of silently doing nothing.
    var b = await ready();
    b.$('#phoneDialInput').value = '102';
    b.click('#phoneCallBtn');
    R.check('dialing before the PBX has accepted the registration says why, instead of doing nothing',
        b.J.calls.length === 0 && /not registered/.test(footer(b)), footer(b));
    b.close();

    // ── Microphone: refuse early, in words ──────────────────────────────
    var c = await ready({ secure: false });
    c.J.register(c.J.latestUa());
    c.$('#phoneDialInput').value = '102';
    c.click('#phoneCallBtn');
    R.check('on a non-secure (plain http) page a call is refused with the real reason, not attempted',
        c.J.calls.length === 0 && /secure page/.test(footer(c)) && /https/.test(footer(c)), footer(c));
    c.close();

    var d = await ready({ mediaDevices: false });
    d.J.register(d.J.latestUa());
    d.$('#phoneDialInput').value = '102';
    d.click('#phoneCallBtn');
    R.check('a browser with no media devices is told so and no call is attempted',
        d.J.calls.length === 0 && /microphone/.test(footer(d)), footer(d));
    d.close();

    a.$('#phoneDialInput').value = '103';
    a.click('#phoneCallBtn');
    a.J.calls[3].session.emit('failed', { cause: 'User Denied Media Access' });
    R.check('a denied microphone permission is explained and says how to fix it',
        /Microphone blocked/.test(footer(a)) && /Allow microphone access/.test(footer(a)), footer(a));
    a.$('#phoneDialInput').value = '104';
    a.click('#phoneCallBtn');
    a.J.calls[4].session.emit('failed', { cause: 'Not Found' });
    R.check('an unknown number reads as such', /does not exist on the PBX/.test(footer(a)), footer(a));
    a.$('#phoneDialInput').value = '105';
    a.click('#phoneCallBtn');
    a.J.calls[5].session.terminate();

    // A second call while on one.
    a.$('#phoneDialInput').value = '106';
    a.click('#phoneCallBtn');
    var callsBefore = a.J.calls.length;
    a.window.document.querySelector('#phoneDialInput').value = '107';
    a.click('#phoneCallBtn');
    R.check('while a call is up, another Call click does not start a second ua.call',
        a.J.calls.length === callsBefore, 'calls=' + a.J.calls.length);
    a.J.calls[a.J.calls.length - 1].session.terminate();
    a.close();

    // ── Registration and connection failures ────────────────────────────
    var e = await ready();
    var eua = e.J.latestUa();
    eua.emit('registrationFailed', { cause: 'Authentication Error' });
    R.check('a rejected password points the administrator at the Phone Extensions password',
        /password/.test(footer(e)) && /Phone Extensions/.test(footer(e)), footer(e));
    eua.emit('registrationFailed', { cause: 'Connection Error' });
    R.check('a connection error gives the one-time certificate address (https, same host and port)',
        /https:\/\/pbx\.example:8089\//.test(footer(e)) && /certificate/.test(footer(e)), footer(e));
    eua.emit('disconnected', {});
    R.check('a dropped socket says the connection was lost and that it reconnects',
        /Lost the connection/.test(footer(e)) && /Reconnecting/.test(footer(e)), footer(e));
    R.check('...and the status dot is red', /status-disconnected/.test(e.$('.phone-status-badge').className));
    e.J.register(eua);
    R.check('a later successful REGISTER clears the failure text', /Registered as 101/.test(footer(e)), footer(e));
    e.close();

    var f = await ready({ payload: Object.assign({}, BOUND, { wss_url: '' }) });
    R.check('no PBX address configured: says so and asks for an administrator, creates no user agent',
        f.J.uas.length === 0 && /not configured/.test(footer(f)), footer(f));
    f.close();

    var g = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: BOUND, skipWidget: true });
    g.J.throwOnUa = 'Invalid sip_uri for the test';
    g.load('assets/js/console-workstation.js'); g.load('assets/js/phone-dial-logic.js'); g.load('assets/js/phone-widget.js');
    await g.flush();
    R.check('invalid phone settings (the user agent constructor throws) become a readable message, not a console error',
        /not valid/.test(footer(g)) && /Invalid sip_uri/.test(footer(g)), footer(g));
    g.close();

    var h = await ready({ fetchHandler: function (u) {
        if (/action=my_extension/.test(u)) { return { status: 500, body: { error: 'lookup failed' } }; }
    } });
    R.check('a server error loading the phone settings is reported with its status',
        /Could not load the phone settings/.test(footer(h)) && /HTTP 500/.test(footer(h)), footer(h));
    R.check('...and no user agent is created', h.J.uas.length === 0);
    h.close();

    // ── Incoming: ring display, decline, caller hang-up ────────────────
    var i = await ready();
    var iua = i.J.latestUa();
    i.J.register(iua);
    var inv = i.J.invite(iua, '6125551234', {}, 'ACME DISPATCH');
    R.check('an incoming call shows the caller (name and number) and the Answer/Decline buttons',
        i.shown('#phoneIncoming') && /ACME DISPATCH <6125551234>/.test(i.$('#phoneIncomingFrom').textContent));
    R.check('...the Answer button gets keyboard focus so Enter answers',
        i.document.activeElement === i.$('#phoneAnswerBtn'));
    R.check('...the footer announces it (aria-live status region)',
        /Incoming call/.test(footer(i)) && i.$('#phoneFooter').getAttribute('role') === 'status' && i.$('#phoneFooter').getAttribute('aria-live') === 'polite');
    i.click('#phoneDeclineBtn');
    R.check('Decline terminates the session and clears the banner', inv.terminated !== null && !i.shown('#phoneIncoming'));

    var inv2 = i.J.invite(iua, '6125559999', {});
    inv2.emit('failed', { cause: 'Canceled' });
    R.check('a caller who hangs up before the answer is reported as cancelled and the UI resets',
        /Call cancelled/.test(footer(i)) && !i.shown('#phoneIncoming') && i.shown('#phoneDialpad'), footer(i));

    // A second INVITE while on a call is rejected busy.
    var inv3 = i.J.invite(iua, '6125551111', {});
    i.click('#phoneAnswerBtn');
    var inv4 = i.J.invite(iua, '6125552222', {});
    R.check('a second incoming call while one is up is rejected with 486 Busy Here',
        inv4.terminated && inv4.terminated.status_code === 486, JSON.stringify(inv4.terminated));
    R.check('...and the first call is untouched', inv3.terminated === null);
    i.close();

    // Mic-blocked answer: do not pretend.
    var j = await ready({ secure: false });
    var jua = j.J.latestUa();
    j.J.register(jua);
    var invj = j.J.invite(jua, '6125553333', {});
    j.click('#phoneAnswerBtn');
    R.check('answering on a non-secure page does not call session.answer and says why',
        invj.answerCalls.length === 0 && /secure page/.test(footer(j)), footer(j));
    j.close();

    R.done();
})().catch(function (e) { console.log('FAIL|node script crashed|' + (e && e.stack || e)); process.exit(0); });
