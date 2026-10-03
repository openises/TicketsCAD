// Phase 155 (GH#144) - drives the REAL Notification Rules panel in jsdom.
//
// Used by tests/test_notification_rules_panel_wiring.php. It loads the markup the
// real settings page serves (inc/notification-rules-panel.php rendered by PHP) and the
// REAL assets/js/notification-rules.js, feeds it the REAL API's answers (the PHP test
// captured them from api/notification-rules.php), clicks and types like a person, and
// prints one JSON object: what happened, and every request the script made, so PHP
// can replay those request bodies against the real endpoint.
//
// This is Node-side TEST code (.cjs, CommonJS) and is never served to a browser, so the
// "browser JavaScript is ES5" convention does not apply to it; the script UNDER test
// (assets/js/notification-rules.js) is ES5 and the wiring test asserts so.
//
// Usage: node _p155_panel_harness.cjs <root> <fixture.json>
'use strict';
const fs = require('fs');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const fx = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const out = { checks: {}, posts: [], gets: [], errors: [] };
const C = out.checks;

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function newWindow(mode) {
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="tok123"></head><body>'
        + '<div class="config-tab-list"><button class="config-tab-link" data-tab="email-config"></button>'
        + '<button class="config-tab-link" data-tab="message-routing"></button></div>'
        + fx.html + '</body></html>', { runScripts: 'outside-only', url: 'http://localhost/settings.php', pretendToBeVisual: true });
    const w = dom.window;
    w.__confirm = { answer: true, asked: [] };
    w.confirm = function (t) { w.__confirm.asked.push(String(t)); return w.__confirm.answer; };
    w.__tabClicks = [];
    Array.prototype.forEach.call(w.document.querySelectorAll('.config-tab-link'), function (b) {
        b.addEventListener('click', function () { w.__tabClicks.push(b.getAttribute('data-tab')); });
    });
    // Minimal Bootstrap: Modal.show/hide flip the .show class and fire the events the page waits for.
    w.bootstrap = {
        Modal: { getOrCreateInstance: function (e) {
            return {
                show: function () { e.classList.add('show'); e.dispatchEvent(new w.Event('shown.bs.modal')); },
                hide: function () { e.classList.remove('show'); e.dispatchEvent(new w.Event('hidden.bs.modal')); }
            };
        } },
        Collapse: { getOrCreateInstance: function (e) {
            return { hide: function () { e.classList.remove('show'); }, show: function () { e.classList.add('show'); } };
        } }
    };
    w.fetch = function (url, opts) {
        opts = opts || {};
        const m = /[?&]action=([a-z_]+)/.exec(url);
        const action = m ? m[1] : '';
        const method = opts.method || 'GET';
        let body = null;
        if (method === 'POST') { try { body = JSON.parse(opts.body); } catch (e) { body = null; } out.posts.push({ action: action, body: body, headers: opts.headers }); }
        else out.gets.push({ action: action, url: url });
        let status = 200; let json = {};
        if (mode === 'denied') { status = 403; json = { error: 'Forbidden' }; }
        else if (method === 'GET' && action === 'meta') json = fx.meta;
        else if (method === 'GET' && action === 'list') json = { rules: fx.rules };
        else if (method === 'GET' && action === 'queue') json = fx.queue;
        else if (method === 'GET' && action === 'users') {
            const ids = /[?&]ids=([^&]*)/.exec(url); const q = /[?&]q=([^&]*)/.exec(url);
            let us = fx.users;
            if (ids) { const set = decodeURIComponent(ids[1]).split(','); us = us.filter(function (u) { return set.indexOf(String(u.id)) >= 0; }); }
            else if (q) { const needle = decodeURIComponent(q[1]).toLowerCase(); us = us.filter(function (u) { return (u.name + ' ' + u.login).toLowerCase().indexOf(needle) >= 0; }); }
            else us = [];
            json = { users: us };
        }
        else if (method === 'GET' && action === 'log') json = fx.log;
        else if (method === 'POST' && action === 'preview') json = fx.preview;
        else if (method === 'POST' && (action === 'create' || action === 'update')) {
            json = { ok: true, warnings: ['a warning from the server'], id: body && body.id ? body.id : 9001,
                     rule: { id: 9001, name: body ? body.name : '' } };
        }
        else if (method === 'POST' && action === 'toggle') json = { ok: true, warnings: [], id: body.id, active: body.active ? 1 : 0 };
        else if (method === 'POST' && action === 'delete') json = { ok: true, warnings: [], id: body.id };
        else if (method === 'POST' && action === 'duplicate') json = { ok: true, id: 9002, rule: { id: 9002, name: 'copy' }, warnings: [] };
        else if (method === 'POST' && action === 'save_settings') json = { ok: true, warnings: [], settings: { email_format: body.email_format, prefs_mode: body.prefs_mode, retention_days: body.retention_days } };
        else if (method === 'POST' && action === 'test_send') {
            if (fx.testSendConfirmFirst && !body.confirm_real && (w.__shared || body.mode === 'rule')) { status = 409; json = { error: 'Needs confirmation.', code: 'confirm_required' }; }
            else json = { ok: true, mode: body.mode, failed: 0, warnings: [], sent: [{ address: 'me@example.invalid', ok: true, error: null, log_id: 1 }], skipped: [{ address: 'x', reason: 'no number' }] };
        }
        else { status = 404; json = { error: 'unrouted ' + method + ' ' + action }; }
        return Promise.resolve({ status: status, json: function () { return Promise.resolve(json); } });
    };
    w.eval(fs.readFileSync(root + '/assets/js/notification-rules.js', 'utf8'));
    return w;
}

function type(w, id, value) {
    const e = w.document.getElementById(id);
    e.value = value;
    e.dispatchEvent(new w.Event('input', { bubbles: true }));
    e.dispatchEvent(new w.Event('change', { bubbles: true }));
}
function click(w, id) { w.document.getElementById(id).click(); }
function visible(w, id) { const e = w.document.getElementById(id); return !!e && !e.classList.contains('d-none'); }
function text(w, id) { return w.document.getElementById(id).textContent; }
function lastPost(action) { for (let i = out.posts.length - 1; i >= 0; i--) if (out.posts[i].action === action) return out.posts[i]; return null; }
function countPosts(action) { return out.posts.filter(function (p) { return p.action === action; }).length; }

async function main() {
    // ───────────────────────── denied ─────────────────────────
    let w = newWindow('denied');
    w.NotificationRulesAdmin.init();
    await sleep(60);
    C.denied_shows_message = visible(w, 'nrDenied');
    C.denied_hides_main = !visible(w, 'nrMain');
    C.denied_never_posts = out.posts.length === 0;

    // ───────────────────────── normal ─────────────────────────
    out.posts = []; out.gets = [];
    w = newWindow('ok');
    w.NotificationRulesAdmin.init();
    w.NotificationRulesAdmin.init();            // idempotent
    await sleep(120);
    const doc = w.document;
    C.init_loads_meta_list_once = out.gets.filter(function (g) { return g.action === 'meta'; }).length === 1
        && out.gets.filter(function (g) { return g.action === 'list'; }).length === 1;
    C.denied_hidden = !visible(w, 'nrDenied');
    const rows = doc.querySelectorAll('#nrTbody tr[data-rule-id]');
    C.rows_match_rules = rows.length === fx.rules.length;
    C.row_text_is_text = rows[0] && rows[0].textContent.indexOf(fx.rules[0].name) >= 0;
    C.no_injected_elements_in_table = doc.querySelectorAll('#nrTbody img, #nrTbody script, #nrTbody svg').length === 0 && !w.__xss;
    C.user_name_not_markup = doc.querySelectorAll('#nrTbody b, #nrTbody i.injected').length === 0;
    C.badges = doc.querySelectorAll('#nrChannelBadges button').length === fx.meta.channels.length;
    C.queue_strip_text = /waiting/.test(text(w, 'nrQueueInfo'));
    C.sched_banner_matches_state = visible(w, 'nrSchedBanner') === !fx.queue.scheduler_live;
    C.breaker_banner_shown_when_open = visible(w, 'nrBreakerBanner') === !!fx.breakerOpen;
    C.presets = doc.querySelectorAll('#nrPresetMenu button').length === fx.meta.presets.length;
    C.settings_card_filled = doc.getElementById('nrSetFormat').value === fx.meta.settings.email_format
        && Number(doc.getElementById('nrSetRetention').value) === fx.meta.settings.retention_days;
    C.channel_badge_navigates = (function () {
        const emailCh = fx.meta.channels.filter(function (c) { return c.tab === 'email-config'; })[0];
        const badge = Array.prototype.filter.call(doc.querySelectorAll('#nrChannelBadges button'), function (b) { return b.textContent.indexOf(emailCh.name) === 0; })[0];
        badge.click();
        return w.__tabClicks.indexOf('email-config') >= 0;
    })();
    C.info_box_goto_links_navigate = (function () {
        doc.querySelector('[data-nr-goto="message-routing"]').click();
        return w.__tabClicks.indexOf('message-routing') >= 0;
    })();

    // switch toggle
    const sw = doc.querySelector('#nrTbody tr[data-rule-id] input[type=checkbox]');
    const wasOn = sw.checked;
    sw.checked = !wasOn; sw.dispatchEvent(new w.Event('change', { bubbles: true }));
    await sleep(40);
    C.toggle_posts_rule_id_and_state = !!lastPost('toggle') && lastPost('toggle').body.id === fx.rules[0].id && !!lastPost('toggle').body.active === !wasOn;

    // delete: declined, then confirmed
    const delBtns = function () { return doc.querySelectorAll('#nrTbody tr[data-rule-id] button[aria-label^="Delete rule"]'); };
    w.__confirm.answer = false;
    delBtns()[delBtns().length - 1].click();
    await sleep(30);
    C.delete_declined_sends_nothing = countPosts('delete') === 0 && /Delete the rule/.test(w.__confirm.asked[0] || '');
    w.__confirm.answer = true;
    delBtns()[delBtns().length - 1].click();
    await sleep(60);
    C.delete_confirmed_posts = countPosts('delete') === 1 && lastPost('delete').body.id === fx.rules[fx.rules.length - 1].id;
    C.delete_removes_row_in_place = doc.querySelectorAll('#nrTbody tr[data-rule-id]').length === fx.rules.length - 1;

    // info box: dismiss persists
    click(w, 'nrInfoDismiss');
    C.info_dismiss_hides_and_remembers = !doc.getElementById('nrInfoBody').classList.contains('show') && w.localStorage.getItem('nr_info_dismissed') === '1';

    // ───────────────────────── new rule ─────────────────────────
    click(w, 'nrBtnNew');
    await sleep(40);
    C.modal_opens = doc.getElementById('nrRuleModal').classList.contains('show');
    C.event_options = doc.getElementById('nrEvent').options.length === fx.meta.events.length;
    C.channel_options = doc.getElementById('nrChannel').options.length === fx.meta.channels.length;
    type(w, 'nrEvent', 'unit_assign');
    C.once_visible_for_unit_assign = visible(w, 'nrOnceWrap');
    type(w, 'nrEvent', 'has_broadcast');
    C.filters_hidden_for_non_incident_event = !visible(w, 'nrFilters');
    type(w, 'nrEvent', 'incident_create');
    C.filters_visible_for_incident_event = visible(w, 'nrFilters');
    C.placeholder_is_event_default = doc.getElementById('nrBody').placeholder === fx.meta.events.filter(function (e) { return e.id === 'incident_create'; })[0].default_body;

    // channel kinds
    type(w, 'nrChannel', 'email');
    C.email_shows_email_user_list = visible(w, 'nrAddEmail') && visible(w, 'nrAddUser') && visible(w, 'nrAddList') && !visible(w, 'nrAddPhone');
    type(w, 'nrChannel', 'sms');
    C.sms_shows_phone_user_only = visible(w, 'nrAddPhone') && visible(w, 'nrAddUser') && !visible(w, 'nrAddEmail') && !visible(w, 'nrAddList');
    type(w, 'nrChannel', 'slack');
    C.shared_hides_recipients = !visible(w, 'nrRecipBlock') && visible(w, 'nrSharedNote');
    type(w, 'nrChannel', 'email');

    // typed email: invalid then valid
    type(w, 'nrEmailIn', 'not-an-email');
    click(w, 'nrEmailAdd');
    C.bad_email_refused = doc.getElementById('nrEmailIn').classList.contains('is-invalid') && visible(w, 'nrFormErrors')
        && doc.querySelectorAll('#nrChips .badge').length === 0;
    type(w, 'nrEmailIn', 'a@example.invalid, B@Example.invalid; a@example.invalid');
    click(w, 'nrEmailAdd');
    C.emails_added_deduped = doc.querySelectorAll('#nrChips .badge').length === 2;
    C.chip_text_is_text = doc.querySelectorAll('#nrChips .badge')[0].textContent.indexOf('a@example.invalid') >= 0;
    // remove one
    doc.querySelector('#nrChips .badge button').click();
    C.chip_removed = doc.querySelectorAll('#nrChips .badge').length === 1;
    C.chip_remove_has_aria = /^Remove /.test(doc.querySelector('#nrChips .badge button') ? doc.querySelector('#nrChips .badge button').getAttribute('aria-label') : '');

    // user search
    const u0 = fx.users[0];
    type(w, 'nrUserQ', u0.login.slice(0, 5));
    await sleep(380);
    const hits = doc.querySelectorAll('#nrUserResults button');
    C.user_search_calls_endpoint = out.gets.some(function (g) { return g.action === 'users' && g.url.indexOf('q=') >= 0; });
    C.user_results_listed = hits.length >= 1 && visible(w, 'nrUserResults');
    C.user_result_markup_is_text = doc.querySelectorAll('#nrUserResults b, #nrUserResults img').length === 0;
    hits[0].click();
    await sleep(20);
    C.user_added_as_chip = doc.querySelectorAll('#nrChips .badge').length === 2 && !visible(w, 'nrUserResults');
    type(w, 'nrUserQ', fx.noEmailLogin);
    await sleep(380);
    doc.querySelectorAll('#nrUserResults button')[0].click();
    await sleep(20);
    const warnChip = Array.prototype.filter.call(doc.querySelectorAll('#nrChips .badge'), function (b) { return b.querySelector('i[aria-label="no email address on file"]'); });
    C.user_without_email_flagged_on_email_channel = warnChip.length === 1;
    // take that chip off again so the later counts stay simple
    warnChip[0].querySelector('button').click();

    // phone on sms channel
    type(w, 'nrChannel', 'sms');
    C.switching_to_sms_prunes_email_chips = doc.querySelectorAll('#nrChips .badge').length === 1;   // only the user fits
    type(w, 'nrPhoneIn', '12');
    click(w, 'nrPhoneAdd');
    C.bad_phone_refused = doc.getElementById('nrPhoneIn').classList.contains('is-invalid');
    type(w, 'nrPhoneIn', '+1 (555) 123-4567');
    click(w, 'nrPhoneAdd');
    C.phone_added_normalised = doc.querySelectorAll('#nrChips .badge').length === 2 && /\+15551234567/.test(text(w, 'nrChips'));
    type(w, 'nrChannel', 'email');

    // placeholder chip inserts at the caret in the last field typed in
    doc.getElementById('nrBody').focus();
    doc.getElementById('nrBody').value = 'AB';
    doc.getElementById('nrBody').setSelectionRange(1, 1);
    doc.querySelector('#nrPlaceholders button').click();
    C.placeholder_inserted_at_caret = /^A\{[a-z_]+\}B$/.test(doc.getElementById('nrBody').value);
    C.counts_update = text(w, 'nrBodyCount') === String(doc.getElementById('nrBody').value.length);

    // preview was requested with the form's content
    type(w, 'nrName', 'P155 wired rule');
    type(w, 'nrSubject', 'Subject {incident_type}');
    type(w, 'nrBody', '{street|clean};{city}');
    await sleep(700);
    const pv = lastPost('preview');
    C.preview_posts_form = !!pv && pv.body.name === 'P155 wired rule' && pv.body.body_template === '{street|clean};{city}' && pv.body.sample === 'synthetic';
    C.preview_rendered = text(w, 'nrPrevSubject') === fx.preview.rendered.subject && text(w, 'nrPrevBody') === fx.preview.rendered.body;
    C.preview_text_not_markup = doc.querySelectorAll('#nrPrevBody b, #nrPrevBody img, #nrPrevList img').length === 0 && !w.__xss;
    C.preview_lists_deliveries = doc.querySelectorAll('#nrPrevList li').length >= 1;
    C.preview_warnings = visible(w, 'nrWarnings') === ((fx.preview.warnings || []).length > 0);

    // save (create)
    type(w, 'nrSeverity', '');
    click(w, 'nrBtnSave');
    await sleep(80);
    const cr = lastPost('create');
    C.create_posted = !!cr;
    out.createBody = cr ? cr.body : null;
    C.create_has_csrf = !!cr && cr.body.csrf_token === 'tok123';
    C.create_closes_modal = !doc.getElementById('nrRuleModal').classList.contains('show');
    C.create_success_message_with_server_warning = /saved/.test(text(w, 'nrMsg')) && /a warning from the server/.test(text(w, 'nrMsg'));

    // Ctrl+Enter saves; empty name is refused client-side
    click(w, 'nrBtnNew');
    await sleep(30);
    const before = countPosts('create');
    doc.getElementById('nrRuleModal').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await sleep(30);
    C.empty_name_refused_client_side = countPosts('create') === before && /name/.test(text(w, 'nrFormErrors'));
    type(w, 'nrName', 'P155 ctrl enter');
    doc.getElementById('nrRuleModal').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true, bubbles: true }));
    await sleep(60);
    C.ctrl_enter_saves = countPosts('create') === before + 1;

    // preset
    click(w, 'nrBtnNew'); await sleep(20);
    doc.querySelector('#nrPresetMenu button').click();
    await sleep(40);
    const pre = fx.meta.presets[0].rule;
    C.preset_prefills = doc.getElementById('nrName').value === pre.name && doc.getElementById('nrEvent').value === pre.event_type
        && doc.getElementById('nrBody').value === pre.body_template && doc.getElementById('nrOnce').checked === !!pre.once_per_incident
        && visible(w, 'nrPresetNote');
    // preset text is shown as text
    C.preset_note_text = text(w, 'nrPresetNote') === fx.meta.presets[0].description;
    doc.getElementById('nrRuleModal').dispatchEvent(new w.Event('hidden.bs.modal'));

    // edit an existing rule: fields come from the rule, update (not create) is posted with its id
    doc.querySelector('#nrTbody tr[data-rule-id] button.btn-link').click();
    await sleep(80);
    const target = fx.rules[0];
    C.edit_fills_fields = doc.getElementById('nrName').value === target.name && doc.getElementById('nrEvent').value === target.event_type
        && doc.getElementById('nrChannel').value === target.channel;
    C.edit_resolves_user_names_into_chips = doc.querySelectorAll('#nrChips .badge').length === target.recipients.length
        && text(w, 'nrChips').indexOf(fx.users[0].name) >= 0;
    C.edit_title = text(w, 'nrRuleTitle') === 'Edit rule';
    click(w, 'nrBtnSave');
    await sleep(70);
    const up = lastPost('update');
    C.edit_posts_update_with_id = !!up && up.body.id === target.id;
    out.updateBody = up ? up.body : null;

    // test send: to me goes straight through; to real recipients asks first
    click(w, 'nrBtnNew'); await sleep(20);
    type(w, 'nrName', 'P155 test'); type(w, 'nrEmailIn', 'real@example.invalid'); click(w, 'nrEmailAdd');
    await sleep(700);
    click(w, 'nrTestMe');
    await sleep(70);
    const tm = lastPost('test_send');
    C.test_me_posts_mode_me = !!tm && tm.body.mode === 'me' && !tm.body.confirm_real;
    C.test_result_text = /Sent to me@example.invalid/.test(text(w, 'nrTestResult')) && /Skipped x: no number/.test(text(w, 'nrTestResult'));
    w.__confirm.answer = false; w.__confirm.asked = [];
    const nTests = countPosts('test_send');
    click(w, 'nrTestRule');
    await sleep(40);
    // the destinations it lists are the ones the real Preview said it would send to
    const previewDests = fx.preview.deliveries.filter(function (d) { return d.status === 'queued'; }).map(function (d) { return d.address; });
    C.test_real_asks_first_listing_destinations = previewDests.length > 0 && w.__confirm.asked.length === 1
        && previewDests.every(function (a) { return w.__confirm.asked[0].indexOf(a) >= 0; }) && /paged/.test(w.__confirm.asked[0]);
    C.test_real_declined_sends_nothing = countPosts('test_send') === nTests;
    w.__confirm.answer = true;
    click(w, 'nrTestRule');
    await sleep(70);
    const tr = lastPost('test_send');
    C.test_real_confirmed_flags_confirm_real = countPosts('test_send') === nTests + 1 && tr.body.mode === 'rule' && tr.body.confirm_real === true;
    doc.getElementById('nrRuleModal').dispatchEvent(new w.Event('hidden.bs.modal'));

    // saved rule's row Test button: straight to me
    const nT2 = countPosts('test_send');
    doc.querySelector('#nrTbody tr[data-rule-id] button[aria-label^="Send a test"]').click();
    await sleep(70);
    C.row_test_posts_me = countPosts('test_send') === nT2 + 1 && lastPost('test_send').body.mode === 'me';

    // duplicate
    doc.querySelector('#nrTbody tr[data-rule-id] button[aria-label^="Duplicate rule"]').click();
    await sleep(60);
    C.duplicate_posts = countPosts('duplicate') === 1;

    // delivery settings
    type(w, 'nrSetFormat', 'html'); type(w, 'nrSetPrefs', 'defaults_apply'); type(w, 'nrSetRetention', '30');
    click(w, 'nrSetSave');
    await sleep(60);
    const ss = lastPost('save_settings');
    C.settings_payload = !!ss && ss.body.email_format === 'html' && ss.body.prefs_mode === 'defaults_apply' && ss.body.retention_days === 30;
    C.settings_saved_message = /Saved/.test(text(w, 'nrSetMsg'));

    // delivery log
    click(w, 'nrBtnLog');
    await sleep(80);
    const lrows = doc.querySelectorAll('#nrLogBody tr');
    C.log_loaded = out.gets.some(function (g) { return g.action === 'log'; }) && lrows.length >= fx.log.rows.length;
    C.log_markup_is_text = doc.querySelectorAll('#nrLogBody img, #nrLogBody script').length === 0 && !w.__xss2;
    C.log_body_hidden_until_asked = doc.querySelectorAll('#nrLogBody tr.d-none').length === fx.log.rows.length;
    const tg = doc.querySelector('#nrLogBody button');
    tg.click();
    const openPre = doc.querySelectorAll('#nrLogBody tr:not(.d-none) pre');
    C.log_body_expands_as_text = openPre.length === 1 && openPre[0].textContent.indexOf(fx.log.rows[0].body.slice(0, 20)) === 0 && tg.getAttribute('aria-expanded') === 'true';
    C.log_ticket_link = (function () { const a = doc.querySelector('#nrLogBody a'); return !a || /^incident-detail\.php\?id=\d+$/.test(a.getAttribute('href')); })();
    const LABEL = { queued: 'Waiting', sent: 'Sent', failed: 'Failed', skipped: 'Skipped', killed: 'Cancelled', expired: 'Expired' };
    C.log_status_labels = fx.log.rows.every(function (r, i) {
        const badge = doc.querySelectorAll('#nrLogBody tr:not(.d-none)')[0] && doc.querySelectorAll('#nrLogBody span.badge')[i];
        return badge && badge.textContent === LABEL[r.effective_status];
    });
    type(w, 'nrLogStatus', 'failed');
    click(w, 'nrLogApply');
    await sleep(60);
    C.log_filter_sent_to_server = out.gets.some(function (g) { return g.action === 'log' && g.url.indexOf('status=failed') >= 0; });

    // a11y: every control reachable has a name
    const unlabeled = [];
    Array.prototype.forEach.call(doc.querySelectorAll('#panel-notifications input, #panel-notifications select, #panel-notifications textarea'), function (e) {
        if (!e.id) return;
        const lab = doc.querySelector('#panel-notifications label[for="' + e.id + '"]');
        if (!lab && !e.getAttribute('aria-label')) unlabeled.push(e.id);
    });
    out.unlabeled = unlabeled;
    C.no_xss_flag_ever_set = !w.__xss && !w.__xss2;

    // shared-channel test: server asks, page asks, then confirms
    fx.testSendConfirmFirst = true;
    click(w, 'nrBtnNew'); await sleep(20);
    type(w, 'nrName', 'P155 shared'); type(w, 'nrChannel', 'slack');
    w.__shared = true;
    w.__confirm.answer = true; w.__confirm.asked = [];
    click(w, 'nrTestMe');
    await sleep(120);
    C.shared_test_asks_after_409_then_resends_with_confirm = w.__confirm.asked.length === 1 && lastPost('test_send').body.confirm_real === true;
}

main().then(function () { console.log(JSON.stringify(out)); process.exit(0); },
    function (e) { out.errors.push(String(e && e.stack || e)); console.log(JSON.stringify(out)); process.exit(0); });
