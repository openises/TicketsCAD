'use strict';
// Regression for GH#108 finding F1: the Phone widget could only learn its
// workstation token on console.php, because ConsoleWorkstation was defined
// only there. Drives the REAL widget + REAL console-workstation.js in jsdom
// on a NON-console page. No PBX involved (fake JsSIP, fake fetch).
var H = require('./_phone_widget_harness.js');
var root = process.argv[2];
var R = H.reporter();

var UNBOUND = { bound: false, wss_url: 'wss://pbx.example:8089/ws', general_number: '100', register_scope: 'every_page' };

function tokenParam(env) {
    var f = env.fetchLog.filter(function (x) { return /action=my_extension/.test(x.url); })[0];
    if (!f) { return null; }
    var m = /workstation_token=([^&]*)/.exec(f.url);
    return m ? decodeURIComponent(m[1]) : null;
}

(async function () {
    // 1. A dashboard page (NOT console.php), every_page so the unbound panel is
    //    the thing on screen. The navbar is what loads the token definer.
    var a = await H.build({ root: root, url: 'http://localhost/newui/index.php', payload: UNBOUND });
    var stored = a.window.localStorage.getItem('ticketscad_console_workstation_token');
    R.check('a non-console page resolves a non-empty workstation token',
        !!tokenParam(a) && tokenParam(a).length >= 8, 'sent=' + tokenParam(a));
    R.check('the token sent to my_extension is the one persisted in localStorage',
        stored && tokenParam(a) === stored, 'stored=' + stored);
    R.check('the unbound panel is on screen', a.shown('#phoneUnboundPanel'));
    R.check('the unbound panel\'s token box is NOT empty (the F1 symptom)',
        a.$('#phoneMyToken').value !== '' && a.$('#phoneMyToken').value === stored,
        'value=' + a.$('#phoneMyToken').value);
    a.close();

    // 2. A second page load in the same browser keeps the same identity.
    var b = await H.build({ root: root, url: 'http://localhost/newui/units.php', payload: UNBOUND,
        storage: { ticketscad_console_workstation_token: 'fixed-token-from-an-earlier-page' } });
    R.check('a later page in the same browser sends the same token',
        tokenParam(b) === 'fixed-token-from-an-earlier-page', 'sent=' + tokenParam(b));
    b.close();

    // 3. Sensitivity control: without the definer loaded (the pre-Phase-155
    //    state of every non-console page) the widget CANNOT know its token.
    //    This is what the test would have caught.
    var c = await H.build({ root: root, url: 'http://localhost/newui/index.php', payload: UNBOUND, workstation: false });
    R.check('control: with no token definer loaded, the token is empty (proves the checks above can fail)',
        tokenParam(c) === '', 'sent=' + JSON.stringify(tokenParam(c)));
    R.check('control: ...and the unbound panel\'s token box is empty (the reported symptom)',
        c.$('#phoneMyToken').value === '');
    c.close();

    // 4. The Console workstation bar shows the token (the docs promised it was
    //    "visible on its Console page"; nothing rendered it).
    var d = await H.build({ root: root, url: 'http://localhost/newui/console.php', payload: UNBOUND, skipWidget: true,
        storage: { ticketscad_console_workstation_token: 'console-bar-token-1234' },
        fetchHandler: function (u) {
            if (/console-workstation-mutes\.php/.test(u)) {
                return { status: 200, body: { my_workstation_id: 5, my_label: 'Desk 1', nearby: [], muted_ids: [], discovery_enabled: false } };
            }
        } });
    var bar = d.document.createElement('div');
    bar.id = 'consoleWorkstationBar';
    bar.className = 'd-none';
    d.document.body.appendChild(bar);
    var csrf = d.document.createElement('meta'); csrf.name = 'csrf-token'; csrf.content = 'x'; d.document.head.appendChild(csrf);
    d.load('assets/js/console-workstation.js');
    d.load('assets/js/console-workstation-panel.js');
    await d.flush();
    var tokenBtn = d.$('#consoleWorkstationTokenBtn');
    R.check('the Console workstation bar has a Phone token button', !!tokenBtn);
    R.check('...the token box is not rendered until asked for', !d.$('#consoleWorkstationTokenInput') || d.$('#consoleWorkstationPanelBody').classList.contains('d-none'));
    if (tokenBtn) { tokenBtn.dispatchEvent(new d.window.MouseEvent('click', { bubbles: true })); }
    var box = d.$('#consoleWorkstationTokenInput');
    R.check('clicking it opens the panel with this browser\'s real token in a read-only box',
        !!box && box.value === 'console-bar-token-1234' && box.readOnly === true && !d.$('#consoleWorkstationPanelBody').classList.contains('d-none'),
        box ? box.value : 'no box');
    R.check('...with a copy button that has an accessible name',
        !!d.$('#consoleWorkstationTokenCopy') && /Copy/.test(d.$('#consoleWorkstationTokenCopy').getAttribute('aria-label') || ''));
    d.close();

    R.done();
})().catch(function (e) { console.log('FAIL|node script crashed|' + (e && e.stack || e)); process.exit(0); });
