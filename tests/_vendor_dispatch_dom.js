/**
 * Node harness for tests/test_vendor_settings_observable.php: runs the REAL assets/js/vendor-dispatch.js against the REAL
 * dialog markup (inc/vendor-dispatch-modal.php, rendered by PHP) inside jsdom, with a mocked fetch(), and prints what the
 * dialog did as one JSON object. Needs `jsdom` (CI installs it; the PHP test SKIPs this part without it).
 *
 * Usage: node tests/_vendor_dispatch_dom.js <scenario.json>
 *   scenario.json: { html, js, dialMode, scenario: 'queue' | 'offer' | 'queue_changed' | 'anchor' | 'xss' }
 */
'use strict';
var fs = require('fs');
var JSDOM = require('jsdom').JSDOM;

var input = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
var XSS_NAME = '<img src=x onerror="window.__pwned=1">Evil Towing';

var queueRows = function (headFirst) {
    var a = { provider_id: 11, name: input.scenario === 'xss' ? XSS_NAME : 'Anderson Towing', contact_name: 'Pat', phone: '555-0100', phone_alt: null, service_area: 'North', hours_note: '24h',
              yard_facility_id: null, rank: 1, eligible: true, is_head: true, state: null, last_turn_at: null, suspended_until: null };
    var b = { provider_id: 12, name: 'Bergstrom Wrecker', contact_name: null, phone: '555-0101', phone_alt: null, service_area: null, hours_note: null,
              yard_facility_id: null, rank: 2, eligible: true, is_head: false, state: null, last_turn_at: '2026-01-01 09:14:00', suspended_until: null };
    return headFirst ? [a, b] : [{ provider_id: 12, name: 'Bergstrom Wrecker', contact_name: null, phone: '555-0101', phone_alt: null, service_area: null, hours_note: null,
              yard_facility_id: null, rank: 1, eligible: true, is_head: true, state: null, last_turn_at: '2026-01-01 09:14:00', suspended_until: null }, a];
};

var configPayload = {
    enabled: true, can_manage: false,
    ticket: { id: 42, ref: '26-0042', street: '1 Main St', city: 'Testville', state: 'MN', address_about: 'at Elm', scope: 'x', closed: false },
    service_types: [{ id: 1, code: 'tow', label: 'Tow', needs_destination: 1 }, { id: 2, code: 'lockout', label: 'Lockout', needs_destination: 0 }],
    lists: [{ id: 5, name: 'County rotation', service_type_id: 1, description: null, is_default: 1, mode: 'round_robin' }],
    other_providers: [],
    settings: { allow_override: true, override_requires_reason: true },
    dial_mode: input.dialMode, dispatches: []
};

var pageHtml = '<!doctype html><html><body>' +
    '<button id="btnVendorDispatch" class="d-none"></button>' +
    '<div id="vendorDispatchCard" class="d-none"><span id="vendorDispatchCount"></span><button id="btnVendorDispatchCard"></button><div id="vendorDispatchList"></div></div>' +
    input.html + '<input type="hidden" id="csrfToken" value="tok"></body></html>';

var dom = new JSDOM(pageHtml, { url: 'http://localhost/incident-detail.php?id=42', runScripts: 'outside-only', pretendToBeVisual: true });
var w = dom.window;
var log = [];
var offerBodies = [];

function json(status, body) { return Promise.resolve({ status: status, json: function () { return Promise.resolve(body); } }); }

w.fetch = function (url, opts) {
    var method = (opts && opts.method) || 'GET';
    if (/api\/facilities\.php/.test(url)) return json(200, { facilities: [] });
    if (/api\/vendor-dispatch\.php/.test(url) && method === 'GET') {
        if (/action=config/.test(url)) { log.push('GET config'); return json(200, configPayload); }
        if (/action=queue/.test(url)) { log.push('GET queue'); return json(200, { queue: { list_id: 5, mode: 'round_robin', head_provider_id: 11, candidates: queueRows(true) } }); }
        if (/action=ticket/.test(url)) return json(200, { dispatches: [] });
    }
    if (/api\/vendor-dispatch\.php/.test(url) && method === 'POST') {
        var body = JSON.parse(opts.body);
        log.push('POST ' + body.action);
        if (body.action === 'offer') {
            offerBodies.push(body);
            if (input.scenario === 'queue_changed') {
                return json(409, { error: 'The rotation just moved. Bergstrom Wrecker is next now. Nothing was recorded.', code: 'queue_changed',
                                   queue: { list_id: 5, mode: 'round_robin', head_provider_id: 12, candidates: queueRows(false) } });
            }
            var disp = { id: 7, ref: '26-0042-T1', ordinal: 1, service_type_id: 1, service_label: 'Tow', list_id: 5, status: 'open', vehicle_desc: null, plate: null,
                         plate_state: null, tow_reason: null, dest_facility_id: null, dest_facility_name: null, dest_text: null, provider_id: null, provider_name: null,
                         provider_phone: null, eta_minutes: null, eta_clock: null, assigned_at: null, closed_at: null, notes: null, created_at: '2026-01-01 10:00:00',
                         created_by_name: 'x', pending: [{ event_id: 99, provider_id: 11, provider_name: 'Anderson Towing', provider_phone: '555-0100', event_at: '2026-01-01 10:00:00' }],
                         events: [] };
            return json(200, { success: true, dispatch: disp, event_id: 99, selection_method: 'rotation',
                               queue: { list_id: 5, mode: 'round_robin', head_provider_id: 12, candidates: queueRows(false) } });
        }
    }
    return json(404, { error: 'unexpected request: ' + method + ' ' + url });
};
w.bootstrap = { Modal: { getOrCreateInstance: function () { return { show: function () { log.push('modal.show'); } }; } } };
w.PhoneWidget = { show: function () { log.push('widget.show'); }, placeCall: function (n) { log.push('placeCall:' + n); } };
// The script dials through a hidden, programmatically created anchor (display:none). A tel: click on a VISIBLE number anchor is the
// dispatcher's own click, which the script must intercept itself (record first); logging that one would hide a bypass.
w.document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href^="tel:"]') : null;
    if (a && a.style && a.style.display === 'none') { e.preventDefault(); log.push('tel:' + a.getAttribute('href')); }
}, true);

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
async function until(fn, ms) { var t0 = Date.now(); while (Date.now() - t0 < (ms || 2500)) { if (fn()) return true; await sleep(15); } return false; }
function $(id) { return w.document.getElementById(id); }

(async function () {
    await until(function () { return w.document.readyState === 'complete'; }, 3000);
    w.eval(input.js);
    var out = { scenario: input.scenario, dialMode: input.dialMode };
    out.cardRevealed = await until(function () { return !$('vendorDispatchCard').classList.contains('d-none'); });
    out.buttonRevealed = !$('btnVendorDispatch').classList.contains('d-none');

    $('btnVendorDispatch').click();
    out.queueRendered = await until(function () { return $('vdQueueBody').querySelectorAll('tr').length > 0 && $('vdQueueBody').textContent.length > 20; });

    var rows = [];
    $('vdQueueBody').querySelectorAll('tr').forEach(function (tr) {
        var btn = tr.querySelector('button');
        rows.push({
            text: tr.textContent.replace(/\s+/g, ' ').trim(),
            button: btn ? btn.textContent.trim() : null,
            telAnchors: Array.prototype.map.call(tr.querySelectorAll('a[href^="tel:"]'), function (a) { return a.getAttribute('href'); }),
            dial: Array.prototype.map.call(tr.querySelectorAll('[data-dial]'), function (n) { return { number: n.getAttribute('data-dial'), ctx: n.getAttribute('data-dial-ctx'), tag: n.tagName.toLowerCase() }; })
        });
    });
    out.rows = rows;
    out.imgInQueue = $('vdQueueBody').querySelectorAll('img').length;
    out.pwned = !!w.__pwned;
    out.logoText = $('vdQueueBody').textContent.indexOf('<img') >= 0;
    out.titleText = $('vdTitle').textContent.replace(/\s+/g, ' ').trim();

    if (input.scenario === 'offer' || input.scenario === 'queue_changed' || input.scenario === 'anchor') {
        var before = log.length;
        if (input.scenario === 'anchor') {
            var a = $('vdQueueBody').querySelector('a[href^="tel:"]');
            if (a) a.click();
        } else {
            var b = $('vdQueueBody').querySelector('button');
            if (b) b.click();
        }
        await until(function () { return log.slice(before).some(function (l) { return l === 'POST offer'; }); });
        await sleep(120);
        out.afterClickLog = log.slice(before);
        out.errorText = $('vdError').textContent;
        out.errorShown = !$('vdError').classList.contains('d-none');
        out.queueHeadAfter = ($('vdQueueBody').querySelector('tr td:nth-child(2)') || { textContent: '' }).textContent.replace(/\s+/g, ' ').trim();
        out.pendingShown = !$('vdPendingWrap').classList.contains('d-none');
        out.pendingText = $('vdPendingList').textContent.replace(/\s+/g, ' ').trim();
        out.offerBody = offerBodies[0] || null;
    }
    out.log = log;
    process.stdout.write(JSON.stringify(out));
    process.exit(0);
})().catch(function (e) { process.stderr.write(String(e && e.stack || e)); process.exit(1); });
