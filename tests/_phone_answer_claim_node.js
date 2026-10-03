'use strict';
// GH#108 S4: ONE Answer. The REAL phone-widget.js and the REAL call-alert.js
// (the Phase 149 banner) in one jsdom window, against a FAKE JsSIP, a fake
// fetch and a fake BroadcastChannel. No PBX: the INVITEs are simulated and
// the audio is never real. What this proves is the wiring -- which request is
// sent, when, and that nothing is sent twice.
var H = require('./_phone_widget_harness.js');
var root = process.argv[2];
var R = H.reporter();

var WSS = 'wss://pbx.example:8089/ws';
var BOUND = { bound: true, extension: '101', label: 'Desk 1', sip_username: '101', sip_password: 'secret-for-test',
              wss_url: WSS, general_number: '100', register_scope: 'every_page' };
var LINKED = '1759421234.12';
var HDR = { 'X-Call-Linkedid': LINKED };

function claimPosts(env) {
    return env.fetchLog.filter(function (f) { return /action=claim_by_provider/.test(f.url); });
}
function bannerClaims(env) {
    return env.fetchLog.filter(function (f) { return /action=claim(&|$)/.test(f.url) && !/claim_by/.test(f.url); });
}
function newIncidentOpens(env) {
    return env.openLog.filter(function (o) { return /new-incident\.php\?call_id=/.test(o.url); });
}

async function widget(extra) {
    var responses = (extra && extra.claimResponses) || [{ success: true, call_id: 41, call: { id: 41 } }];
    var idx = 0;
    var env = await H.build({
        root: root, url: 'http://localhost/newui/console.php', payload: BOUND, csrf: 'csrf-for-test',
        fetchHandler: function (u) {
            if (/action=claim_by_provider/.test(u)) {
                var r = responses[Math.min(idx, responses.length - 1)]; idx++;
                return { status: 200, body: r };
            }
        }
    });
    var ua = env.J.latestUa();
    env.J.register(ua);
    return { env: env, ua: ua };
}

(async function () {
    // ── A. Answer in the widget ────────────────────────────────────────
    var a = await widget();
    var inv = a.env.J.invite(a.ua, '6125551234', HDR);
    a.env.click('#phoneAnswerBtn');
    await a.env.flush();
    R.check('Answer in the widget answers the audio (session.answer once, audio only)',
        inv.answerCalls.length === 1 && inv.answerCalls[0].mediaConstraints.audio === true && inv.answerCalls[0].mediaConstraints.video === false);
    var posts = claimPosts(a.env);
    R.check('...AND claims the matching Phase 149 call with the PBX id from the INVITE header (one request)',
        posts.length === 1 && posts[0].body.provider_call_id === LINKED, JSON.stringify(posts.map(function (p) { return p.body; })));
    R.check('...carrying the CSRF token', posts.length === 1 && posts[0].body.csrf_token === 'csrf-for-test');
    R.check('...as a same-origin POST with a JSON body', posts.length === 1 && posts[0].opts.method === 'POST'
        && posts[0].opts.credentials === 'same-origin' && /json/.test(posts[0].opts.headers['Content-Type']));
    R.check('...and opens the New Incident form for the claimed call in a NEW tab, exactly like the banner',
        newIncidentOpens(a.env).length === 1 && newIncidentOpens(a.env)[0].url === 'new-incident.php?call_id=41' && newIncidentOpens(a.env)[0].name === '_blank',
        JSON.stringify(a.env.openLog));
    a.env.click('#phoneAnswerBtn');
    await a.env.flush();
    R.check('a second click on Answer does not answer or claim twice', inv.answerCalls.length === 1 && claimPosts(a.env).length === 1);
    a.env.close();

    // No header: the PBX is not tagging legs (or this is not an Asterisk call).
    var b = await widget();
    var invB = b.env.J.invite(b.ua, '6125551234', {});
    b.env.click('#phoneAnswerBtn');
    await b.env.flush();
    R.check('with no X-Call-Linkedid header the call is still answered', invB.answerCalls.length === 1);
    R.check('...and NOTHING extra is sent (no claim, no error, no new tab)',
        claimPosts(b.env).length === 0 && newIncidentOpens(b.env).length === 0
        && b.env.fetchLog.filter(function (f) { return !/action=my_extension/.test(f.url); }).length === 0);
    b.env.close();

    // A hostile header value is never forwarded.
    var h = await widget();
    var invH = h.env.J.invite(h.ua, '6125551234', { 'X-Call-Linkedid': '1; DROP TABLE inbound_calls' });
    h.env.click('#phoneAnswerBtn');
    await h.env.flush();
    R.check('a malformed header value is dropped: the call is answered, nothing is sent to the server',
        invH.answerCalls.length === 1 && claimPosts(h.env).length === 0);
    h.env.close();

    // already yours (the banner's Answer got there first), already claimed by someone else, ended.
    var codes = [
        ['already_yours', { success: false, reason: 'already_yours', call_id: 41 }],
        ['already_claimed', { success: false, reason: 'already_claimed', claimed_by_name: 'Pat Dispatcher' }],
        ['already_ended', { success: false, reason: 'already_ended' }]
    ];
    for (var i = 0; i < codes.length; i++) {
        var c = await widget({ claimResponses: [codes[i][1]] });
        var invC = c.env.J.invite(c.ua, '6125551234', HDR);
        c.env.click('#phoneAnswerBtn');
        await c.env.flush();
        R.check('claim answer "' + codes[i][0] + '": the call is still answered and NO second New Incident tab is opened',
            invC.answerCalls.length === 1 && claimPosts(c.env).length === 1 && newIncidentOpens(c.env).length === 0);
        c.env.close();
    }

    // not_found -> one retry after a moment (the bridge's ringing can trail the INVITE), then stop.
    var n = await widget({ claimResponses: [{ success: false, reason: 'not_found' }, { success: true, call_id: 77, call: { id: 77 } }] });
    var invN = n.env.J.invite(n.ua, '6125551234', HDR);
    n.env.click('#phoneAnswerBtn');
    await n.env.flush();
    R.check('not_found: the first claim is sent and nothing is opened yet', claimPosts(n.env).length === 1 && newIncidentOpens(n.env).length === 0);
    await new Promise(function (r) { setTimeout(r, 1800); });
    await n.env.flush();
    R.check('...ONE retry follows, and when the call has appeared it is claimed and opened',
        claimPosts(n.env).length === 2 && newIncidentOpens(n.env).length === 1 && newIncidentOpens(n.env)[0].url === 'new-incident.php?call_id=77',
        'posts=' + claimPosts(n.env).length);
    n.env.close();

    var n2 = await widget({ claimResponses: [{ success: false, reason: 'not_found' }] });
    var invN2 = n2.env.J.invite(n2.ua, '6125551234', HDR);
    n2.env.click('#phoneAnswerBtn');
    await new Promise(function (r) { setTimeout(r, 1800); });
    await n2.env.flush();
    await new Promise(function (r) { setTimeout(r, 1800); });
    R.check('not_found twice (bridge not running): exactly two attempts, never a loop, the call itself unaffected',
        claimPosts(n2.env).length === 2 && invN2.answerCalls.length === 1 && newIncidentOpens(n2.env).length === 0);
    n2.env.close();

    // Decline never claims.
    var d = await widget();
    var invD = d.env.J.invite(d.ua, '6125551234', HDR);
    d.env.click('#phoneDeclineBtn');
    await d.env.flush();
    R.check('Decline does not claim', invD.terminated !== null && claimPosts(d.env).length === 0 && invD.answerCalls.length === 0);
    d.env.close();

    // Mic blocked: nothing is answered or claimed.
    var m = await widget();
    var secure = Object.getOwnPropertyDescriptor(m.env.window, 'isSecureContext');
    Object.defineProperty(m.env.window, 'isSecureContext', { value: false, configurable: true });
    var invM = m.env.J.invite(m.ua, '6125551234', HDR);
    m.env.click('#phoneAnswerBtn');
    await m.env.flush();
    R.check('when the microphone cannot be used, Answer neither answers nor claims (a claimed call nobody can hear would be worse)',
        invM.answerCalls.length === 0 && claimPosts(m.env).length === 0 && /secure page/.test(m.env.$('#phoneFooter').textContent));
    m.env.close();

    // ── B. Answer in the banner (the REAL call-alert.js) ──────────────
    async function withBanner(opts) {
        var calls = [{ id: 41, trunk_id: 1, state: 'ringing', caller_number: '6125551234', called_number: '100',
                       provider_call_id: LINKED, ringing_at: '2026-10-02 10:00:00' }];
        var env = await H.build({
            root: root, url: 'http://localhost/newui/console.php', payload: BOUND, csrf: 'csrf-for-test', skipWidget: true,
            fetchHandler: function (u) {
                if (/action=list_missed/.test(u)) { return { status: 200, body: { calls: [] } }; }
                if (/action=list/.test(u)) { return { status: 200, body: { calls: calls } }; }
                if (/action=claim(&|$)/.test(u) && !/claim_by/.test(u)) { return { status: 200, body: { success: true, call: { id: 41 } } }; }
                if (/action=claim_by_provider/.test(u)) { return { status: 200, body: { success: false, reason: 'already_yours', call_id: 41 } }; }
            }
        });
        var banner = env.document.createElement('div');
        banner.id = 'callAlertBanner';
        env.document.body.appendChild(banner);
        env.window.CALL_ALERT_USER_ID = 7;
        env.window.CALL_ALERT_USER_NAME = 'Test Dispatcher';
        env.window.CALL_ALERT_CSRF = 'csrf-for-test';
        if (opts && opts.noBroadcastChannel) { env.window.BroadcastChannel = undefined; }
        env.load('assets/js/call-alert.js');
        env.load('assets/js/console-workstation.js');
        env.load('assets/js/phone-dial-logic.js');
        env.load('assets/js/phone-widget.js');
        await env.flush(10);
        var ua = env.J.latestUa();
        env.J.register(ua);
        return { env: env, ua: ua };
    }

    var s = await withBanner();
    var inv1 = s.env.J.invite(s.ua, '6125551234', HDR);
    var answerBtn = s.env.$('.call-alert-answer');
    R.check('the banner shows the ringing call with an Answer button', !!answerBtn, s.env.$('#callAlertBanner').innerHTML.slice(0, 120));
    if (answerBtn) { answerBtn.dispatchEvent(new s.env.window.MouseEvent('click', { bubbles: true })); }
    await s.env.flush(10);
    R.check('Answer in the BANNER claims the call (the unchanged Phase 149 claim)', bannerClaims(s.env).length === 1);
    R.check('...and answers the matching audio leg in the browser phone: ONE click, not two', inv1.answerCalls.length === 1);
    R.check('...without the phone claiming a second time', claimPosts(s.env).length === 0);
    R.check('...and exactly one New Incident tab opens (the banner\'s), not two',
        newIncidentOpens(s.env).length === 1, JSON.stringify(newIncidentOpens(s.env)));
    s.env.close();

    // A banner Answer for a DIFFERENT call must not pick up an unrelated ringing leg.
    var s2 = await withBanner();
    var invOther = s2.env.J.invite(s2.ua, '6125550000', { 'X-Call-Linkedid': '9999999999.99' });
    var ab2 = s2.env.$('.call-alert-answer');
    if (ab2) { ab2.dispatchEvent(new s2.env.window.MouseEvent('click', { bubbles: true })); }
    await s2.env.flush(10);
    R.check('a banner Answer for a different call does NOT answer an unrelated ringing leg', invOther.answerCalls.length === 0);
    s2.env.close();

    // A leg with no header is never auto-answered by a banner click.
    var s3 = await withBanner();
    var invNoHdr = s3.env.J.invite(s3.ua, '6125551234', {});
    var ab3 = s3.env.$('.call-alert-answer');
    if (ab3) { ab3.dispatchEvent(new s3.env.window.MouseEvent('click', { bubbles: true })); }
    await s3.env.flush(10);
    R.check('a ringing leg with no correlating header is left for its own Answer click', invNoHdr.answerCalls.length === 0);
    s3.env.close();

    // The page-local fallback (browsers with no BroadcastChannel).
    var s4 = await withBanner({ noBroadcastChannel: true });
    var inv4 = s4.env.J.invite(s4.ua, '6125551234', HDR);
    var ab4 = s4.env.$('.call-alert-answer');
    if (ab4) { ab4.dispatchEvent(new s4.env.window.MouseEvent('click', { bubbles: true })); }
    await s4.env.flush(10);
    R.check('with no BroadcastChannel the same-page window event still answers the leg', inv4.answerCalls.length === 1);
    s4.env.close();

    // A leg that is not ringing any more (already answered) is not answered again by a stray banner message.
    var s5 = await withBanner();
    var inv5 = s5.env.J.invite(s5.ua, '6125551234', HDR);
    s5.env.click('#phoneAnswerBtn');
    await s5.env.flush();
    var before = inv5.answerCalls.length;
    s5.env.window.CallAlert._notifyPhoneAnswered(41);
    await s5.env.flush(6);
    R.check('a banner message for a leg already answered is ignored (no second session.answer)', before === 1 && inv5.answerCalls.length === 1);
    s5.env.close();

    R.done();
})().catch(function (e) { console.log('FAIL|node script crashed|' + (e && e.stack || e)); process.exit(0); });
