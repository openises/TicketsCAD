'use strict';
// GH#108 S1: WHERE the phone registers (phone_register_scope) and the
// single-registrant election. Real widget + real phone-dial-logic.js in jsdom,
// FAKE JsSIP (no PBX): everything about registration here is simulated.
var H = require('./_phone_widget_harness.js');
var root = process.argv[2];
var R = H.reporter();

var WSS = 'wss://pbx.example:8089/ws';
function bound(scope) {
    return { bound: true, extension: '101', label: 'Desk 1', sip_username: '101', sip_password: 'secret-for-test',
             wss_url: WSS, general_number: '100', register_scope: scope };
}
function unbound(scope) {
    return { bound: false, wss_url: WSS, general_number: '100', register_scope: scope };
}
var KEY = 'ticketscad_phone_registrant';
function seedRegistrant(id, standalone) {
    var o = {};
    o[KEY] = JSON.stringify({ id: id, standalone: standalone, at: Date.now() });
    return o;
}

(async function () {
    // ── phone_page (the default): only console.php / phone.php register ──
    var dash = await H.build({ root: root, url: 'http://localhost/newui/index.php', payload: bound('phone_page') });
    R.check('phone_page on the dashboard: NO SIP user agent is created', dash.J.uas.length === 0, 'uas=' + dash.J.uas.length);
    R.check('...the widget says the phone is active in the Console or Phone window',
        dash.shown('#phoneElsewherePanel') && /Console or Phone window/.test(dash.$('#phoneElsewhereText').textContent));
    R.check('...the misleading "not bound" panel is NOT shown', !dash.shown('#phoneUnboundPanel'));
    R.check('...the dial pad body is hidden', !dash.shown('#phoneBody'));
    R.check('...and there is a real link to the Phone window',
        /phone\.php$/.test(dash.$('#phoneElsewhereLink').getAttribute('href')) && dash.$('#phoneElsewhereLink').getAttribute('target') === 'ticketscad_phone');
    R.check('...registersHere() is false', dash.window.PhoneWidget.registersHere() === false);
    dash.close();

    var dashUnbound = await H.build({ root: root, url: 'http://localhost/newui/index.php', payload: unbound('phone_page') });
    R.check('phone_page + an UNBOUND workstation on the dashboard: the elsewhere notice, not the unbound token panel',
        dashUnbound.shown('#phoneElsewherePanel') && !dashUnbound.shown('#phoneUnboundPanel'));
    dashUnbound.close();

    var cons = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: bound('phone_page') });
    var cua = cons.J.latestUa();
    R.check('phone_page on console.php: a user agent is created and started', !!cua && cua.started);
    R.check('...registering as the bound extension at the PBX host',
        cua && cua.config.uri === 'sip:101@pbx.example' && cua.config.register === true && cua.config.password === 'secret-for-test',
        cua && cua.config.uri);
    R.check('...the elsewhere notice is hidden and the dial pad shown',
        !cons.shown('#phoneElsewherePanel') && cons.shown('#phoneBody'));
    R.check('...registersHere() is true', cons.window.PhoneWidget.registersHere() === true);
    cons.J.register(cua);
    R.check('...a REGISTER success reads "Registered as 101" in the footer',
        /Registered as 101/.test(cons.$('#phoneFooter').textContent), cons.$('#phoneFooter').textContent);
    cons.close();

    var consUnbound = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: unbound('phone_page') });
    R.check('phone_page + unbound on console.php: the unbound panel with the token IS shown (this is where binding happens)',
        consUnbound.shown('#phoneUnboundPanel') && consUnbound.$('#phoneMyToken').value !== '');
    R.check('...and no user agent is created for an unbound workstation', consUnbound.J.uas.length === 0);
    consUnbound.close();

    var ph = await H.build({ root: root, url: 'http://localhost/newui/phone.php', standalone: true, payload: bound('phone_page') });
    R.check('the standalone Phone window registers under phone_page', !!ph.J.latestUa() && ph.J.latestUa().started);
    R.check('...the widget fills the window (standalone class) and is open without a toggle',
        ph.$('.phone-widget').classList.contains('phone-standalone') && ph.widgetOpen());
    R.check('...and the number box has keyboard focus, so an operator can just type a number',
        ph.document.activeElement === ph.$('#phoneDialInput'));
    ph.close();

    // ── every_page: registers everywhere ───────────────────────────────
    var every = await H.build({ root: root, url: 'http://localhost/newui/units.php', payload: bound('every_page') });
    R.check('every_page on an ordinary page: a user agent IS created', !!every.J.latestUa() && every.J.latestUa().started);
    R.check('...no elsewhere notice', !every.shown('#phoneElsewherePanel'));
    every.close();

    // The same payload, different scope value, different outcome -- the setting changes behaviour.
    var a = await H.build({ root: root, url: 'http://localhost/newui/units.php', payload: bound('phone_page') });
    var b = await H.build({ root: root, url: 'http://localhost/newui/units.php', payload: bound('every_page') });
    R.check('same page, same extension: phone_page -> 0 user agents, every_page -> 1',
        a.J.uas.length === 0 && b.J.uas.length === 1, a.J.uas.length + '/' + b.J.uas.length);
    a.close(); b.close();

    var odd = await H.build({ root: root, url: 'http://localhost/newui/units.php', payload: bound('nonsense') });
    R.check('an unknown scope value behaves as the safe default (phone_page)', odd.J.uas.length === 0);
    odd.close();

    // ── The navbar button (EventBus phone:toggle) ──────────────────────
    function withBus(env) {
        var handlers = {};
        env.window.EventBus = { on: function (e, fn) { (handlers[e] = handlers[e] || []).push(fn); },
                                emit: function (e) { (handlers[e] || []).forEach(function (fn) { fn(); }); } };
        return env;
    }
    async function buildWithBus(url, payload, extra) {
        var env = await H.build(Object.assign({ root: root, url: url, payload: payload, skipWidget: true }, extra || {}));
        withBus(env);
        env.load('assets/js/console-workstation.js');
        env.load('assets/js/phone-dial-logic.js');
        env.load('assets/js/phone-widget.js');
        await env.flush();
        return env;
    }
    var navDash = await buildWithBus('http://localhost/newui/index.php', bound('phone_page'));
    navDash.window.EventBus.emit('phone:toggle');
    R.check('navbar phone button on a non-registering page opens the Phone window',
        navDash.openLog.length === 1 && /phone\.php$/.test(navDash.openLog[0].url) && navDash.openLog[0].name === 'ticketscad_phone',
        JSON.stringify(navDash.openLog));
    R.check('...and does not just pop an inert widget', !navDash.widgetOpen());
    navDash.close();

    var navCons = await buildWithBus('http://localhost/newui/console.php', bound('phone_page'));
    navCons.window.EventBus.emit('phone:toggle');
    R.check('navbar phone button on console.php toggles the widget (no window.open)',
        navCons.openLog.length === 0 && navCons.widgetOpen());
    navCons.close();

    var blocked = await buildWithBus('http://localhost/newui/console.php', bound('phone_page'), { popupBlocked: true });
    blocked.click('#phoneOpenWindow');
    R.check('a blocked pop-up is reported in plain words, not silently',
        /blocked the Phone window/.test(blocked.$('#phoneFooter').textContent), blocked.$('#phoneFooter').textContent);
    blocked.close();

    // ── Single-registrant election ─────────────────────────────────────
    var e1 = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: bound('phone_page') });
    var rec = JSON.parse(e1.window.localStorage.getItem(KEY) || 'null');
    R.check('the registering window writes its registrant record', !!rec && rec.standalone === false && typeof rec.id === 'string');

    e1.window.localStorage.setItem(KEY, JSON.stringify({ id: 'other-window', standalone: true, at: Date.now() }));
    e1.storageEvent(KEY);
    R.check('a fresh STANDALONE registrant elsewhere makes the Console window yield (its user agent is stopped)',
        e1.J.uas[0].stopped === true, 'stopped=' + e1.J.uas[0].stopped);
    R.check('...and it says another window handles the calls',
        e1.shown('#phoneElsewherePanel') && /another window/.test(e1.$('#phoneElsewhereText').textContent));

    // The stopped user agent finishes its own unregister/disconnect later; those late events
    // must not repaint the status as if it were the live registration.
    e1.J.uas[0].emit('registered', {});
    e1.J.uas[0].emit('registrationFailed', { cause: 'Connection Error' });
    R.check('late events from the user agent this window stopped are ignored (the footer is not repainted)',
        !/Registered as/.test(e1.$('#phoneFooter').textContent) && !/Cannot reach the PBX/.test(e1.$('#phoneFooter').textContent),
        e1.$('#phoneFooter').textContent);

    e1.window.localStorage.setItem(KEY, JSON.stringify({ id: 'other-window', standalone: true, at: Date.now() - 60000 }));
    e1.storageEvent(KEY);
    R.check('when that registrant goes STALE (window closed) this window registers again',
        e1.J.uas.length === 2 && e1.J.uas[1].started && !e1.J.uas[1].stopped, 'uas=' + e1.J.uas.length);
    R.check('...and the elsewhere notice goes away', !e1.shown('#phoneElsewherePanel'));
    e1.window.dispatchEvent(new e1.window.Event('pagehide'));
    R.check('leaving the page releases the registration record (so another window can take over at once)',
        e1.window.localStorage.getItem(KEY) === null);
    R.check('...and stops its user agent', e1.J.uas[1].stopped === true);
    e1.close();

    // The standalone window outranks a Console tab that registered first.
    var e2 = await H.build({ root: root, url: 'http://localhost/newui/phone.php', standalone: true, payload: bound('phone_page'),
        storage: seedRegistrant('console-tab', false) });
    R.check('the standalone Phone window takes the registration over from a fresh non-standalone holder',
        e2.J.activeUas().length === 1, 'active=' + e2.J.activeUas().length);
    e2.close();

    // A fresh non-standalone record blocks another non-standalone window.
    var e3 = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: bound('phone_page'),
        storage: seedRegistrant('another-console', false) });
    R.check('a second ordinary window does NOT register while another holds it fresh',
        e3.J.uas.length === 0 && e3.shown('#phoneElsewherePanel'), 'uas=' + e3.J.uas.length);
    e3.close();

    // Never tear down a live call.
    var e4 = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: bound('phone_page') });
    var ua4 = e4.J.latestUa();
    e4.J.register(ua4);
    e4.$('#phoneDialInput').value = '102';
    e4.click('#phoneCallBtn');
    R.check('a call placed from the registered window reaches ua.call', e4.J.calls.length === 1);
    e4.window.localStorage.setItem(KEY, JSON.stringify({ id: 'other-window', standalone: true, at: Date.now() }));
    e4.storageEvent(KEY);
    R.check('a window told to yield does NOT stop its user agent in the middle of a call', ua4.stopped === false);
    e4.J.calls[0].session.terminate();
    R.check('...it yields as soon as the call ends', ua4.stopped === true, 'stopped=' + ua4.stopped);
    e4.close();

    R.done();
})().catch(function (e) { console.log('FAIL|node script crashed|' + (e && e.stack || e)); process.exit(0); });
