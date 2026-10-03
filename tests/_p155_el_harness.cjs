// Phase 155 (GH#145) - drives the REAL Email Lists panel and the REAL SearchableSelect
// in jsdom. Used by tests/test_email_list_modal_wiring.php.
//
// This is Node-side TEST code (.cjs, CommonJS) and is never served to a browser, so the
// "browser JavaScript is ES5" convention does not apply to it; the scripts UNDER test
// (assets/js/email-lists-admin.js, searchable-select.js) are ES5 and the wiring test
// asserts so.
//
// It loads the markup the settings page serves (inc/email-lists-panel.php rendered by
// PHP), the real scripts, and the REAL API's answers (captured by the PHP test from
// api/email-lists.php), then clicks and types like a person. It prints one JSON object:
// what happened, and every request the script made, so PHP can replay the bodies
// against the real endpoint.
//
// Usage: node _p155_el_harness.cjs <root> <fixture.json>
'use strict';
const fs = require('fs');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const fx = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const out = { checks: {}, posts: [], gets: [], errors: [], ss: {} };
const C = out.checks;
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function newWindow(mode) {
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="tok123"></head><body>' + fx.html + '</body></html>',
        { runScripts: 'outside-only', url: 'http://localhost/settings.php', pretendToBeVisual: true });
    const w = dom.window;
    w.__confirm = { answers: [], asked: [], dflt: true };
    w.confirm = function (t) { w.__confirm.asked.push(String(t)); return w.__confirm.answers.length ? w.__confirm.answers.shift() : w.__confirm.dflt; };
    w.__modalShows = {};
    w.bootstrap = {
        Modal: { getOrCreateInstance: function (e) {
            return {
                show: function () { w.__modalShows[e.id] = (w.__modalShows[e.id] || 0) + 1; e.classList.add('show'); e.dispatchEvent(new w.Event('shown.bs.modal')); },
                hide: function () { e.classList.remove('show'); e.dispatchEvent(new w.Event('hidden.bs.modal')); }
            };
        } }
    };
    w.fetch = function (url, opts) {
        opts = opts || {};
        const m = /[?&]action=([a-z_]+)/.exec(url);
        const action = m ? m[1] : '';
        const method = opts.method || 'GET';
        let body = null;
        if (method === 'POST') { try { body = JSON.parse(opts.body); } catch (e) { body = null; } out.posts.push({ action: action, body: body }); }
        else out.gets.push({ action: action, url: url });
        let status = 200; let json = {};
        const arg = function (k) { const r = new RegExp('[?&]' + k + '=([^&]*)').exec(url); return r ? decodeURIComponent(r[1]) : ''; };
        if (mode === 'missing') { status = 503; json = { error: 'Email list tables are missing - run php sql/run_migrations.php', code: 'tables_missing' }; }
        else if (method === 'GET' && action === 'list') json = { lists: fx.lists };
        else if (method === 'GET' && action === 'detail') {
            const d = fx.details[arg('id')];
            if (d) json = d; else { status = 404; json = { error: 'That list does not exist.', code: 'list_missing' }; }
        }
        else if (method === 'GET' && action === 'resolve') json = fx.resolve[arg('id')] || { count: 0, recipients: [], excluded: [] };
        else if (method === 'GET' && action === 'get_options') json = fx.options;
        else if (method === 'GET' && action === 'search_recipients') {
            const key = arg('type') + '|' + arg('q') + '|' + arg('has_email');
            out.lastSearchKey = key;
            json = fx.search[key] || { items: [], more: false };
        }
        else if (method === 'POST' && action === 'create') json = { ok: true, id: fx.createdId, name: body.name, slug: 'x' };
        else if (method === 'POST' && action === 'add_member') json = { ok: true, id: 9001, warnings: fx.addWarnings || [], label: 'Added Label', list_name: 'The List', type: body.member_type, ref_id: body.ref_id || null };
        else if (method === 'POST' && action === 'remove_member') json = { ok: true, id: body.id };
        else if (method === 'POST' && action === 'import_csv') json = { ok: true, added: 2, skipped: 1, duplicates: 1, errors: ['line 2: not an address'] };
        else if (method === 'POST' && action === 'update') json = { ok: true, id: body.id };
        else if (method === 'POST' && action === 'save_options') json = { ok: true, skip_statuses: body.skip_statuses.map(function (s) { return String(s).toLowerCase(); }), require_email_on_add: !!body.require_email_on_add };
        else if (method === 'POST' && action === 'archive') {
            if (fx.archiveInUse && !body.force) { status = 409; json = { error: 'This list is used by 1 notification rule(s): R. Archive anyway?', code: 'in_use', rules: ['R'] }; }
            else json = { ok: true, id: body.id, rules: fx.archiveInUse ? ['R'] : [] };
        }
        else { status = 404; json = { error: 'unrouted ' + method + ' ' + action }; }
        return Promise.resolve({ status: status, json: function () { return Promise.resolve(json); } });
    };
    w.eval(fs.readFileSync(root + '/assets/js/searchable-select.js', 'utf8'));
    w.eval(fs.readFileSync(root + '/assets/js/email-lists-admin.js', 'utf8'));
    return w;
}
function type(w, id, value) {
    const e = w.document.getElementById(id);
    e.value = value;
    e.dispatchEvent(new w.Event('input', { bubbles: true }));
}
function key(w, id, k, extra) {
    const e = typeof id === 'string' ? w.document.getElementById(id) : id;
    const ev = new w.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, extra || {}));
    e.dispatchEvent(ev);
    return ev;
}
function click(w, id) { w.document.getElementById(id).click(); }
function visible(w, id) { const e = w.document.getElementById(id); return !!e && !e.classList.contains('d-none'); }
function text(w, id) { return w.document.getElementById(id).textContent; }
function lastPost(action) { for (let i = out.posts.length - 1; i >= 0; i--) if (out.posts[i].action === action) return out.posts[i]; return null; }
function countPosts(a) { return out.posts.filter(function (p) { return p.action === a; }).length; }
function active(w) { return w.document.activeElement ? w.document.activeElement.id : ''; }

async function main() {
    // ───────────────────────── a missing table ─────────────────────────
    let w = newWindow('missing');
    w.EmailListsAdmin.load();
    await sleep(60);
    C.missing_tables_shows_repair_hint = /run php sql\/run_migrations\.php/.test(text(w, 'emailListsBody')) && w.document.querySelectorAll('#emailListsBody table').length === 0;

    // ───────────────────────── the list table ─────────────────────────
    out.posts = []; out.gets = [];
    w = newWindow('ok');
    const doc = w.document;
    w.EmailListsAdmin.load();
    await sleep(80);
    const rows = doc.querySelectorAll('#emailListsBody tbody tr[data-list-id]');
    C.table_one_row_per_list = rows.length === fx.lists.length;
    C.table_shows_entries_addresses_problems_columns = /Entries/.test(text(w, 'emailListsBody')) && /Addresses/.test(text(w, 'emailListsBody')) && /Problems/.test(text(w, 'emailListsBody'));
    C.table_no_member_count_column = !/Members/.test(doc.querySelector('#emailListsBody thead').textContent);
    C.table_xss_list_name_is_text = doc.querySelectorAll('#emailListsBody img, #emailListsBody b, #emailListsBody script').length === 0 && !w.__xss
        && rows[0].textContent.indexOf(fx.lists[0].name) >= 0;
    const problemBadge = doc.querySelectorAll('#emailListsBody .badge.text-bg-warning');
    C.table_problem_badge_has_icon_and_text = problemBadge.length >= 1 && problemBadge[0].querySelector('i.bi') !== null && /\d/.test(problemBadge[0].textContent)
        && problemBadge[0].querySelector('.visually-hidden') !== null;
    C.table_actions_are_buttons_with_names = Array.prototype.every.call(doc.querySelectorAll('#emailListsBody tbody button'), function (b) { return b.type === 'button' && (b.getAttribute('aria-label') || '').length > 0; });
    C.no_inline_onclick_anywhere = doc.querySelectorAll('#panel-email-lists [onclick]').length === 0 && !w.__el_open && !w.__el_rm && !w.__el_archive;
    type(w, 'emailListFilter', 'zzzz-no-such-list');
    C.filter_hides_non_matching = doc.querySelectorAll('#emailListsBody tbody tr[data-list-id]').length === 0 && /No list matches/.test(text(w, 'emailListsBody'));
    type(w, 'emailListFilter', '');
    C.filter_cleared_restores = doc.querySelectorAll('#emailListsBody tbody tr[data-list-id]').length === fx.lists.length;

    // ───────────────────────── new list ─────────────────────────
    click(w, 'btnNewEmailList');
    await sleep(20);
    C.new_modal_opens_and_name_gets_focus = doc.getElementById('elNewModal').classList.contains('show') && active(w) === 'elNewName';
    click(w, 'elNewCreate');
    C.new_empty_name_refused_client_side = countPosts('create') === 0 && visible(w, 'elNewErr');
    doc.getElementById('elNewName').value = 'P155 Created'; doc.getElementById('elNewDesc').value = 'made in the test';
    click(w, 'elNewCreate');
    await sleep(120);
    out.createBody = lastPost('create') ? lastPost('create').body : null;
    C.new_posts_name_and_description_with_csrf = !!lastPost('create') && lastPost('create').body.name === 'P155 Created' && lastPost('create').body.description === 'made in the test' && lastPost('create').body.csrf_token === 'tok123';
    C.new_success_opens_manage_for_the_new_list = !doc.getElementById('elNewModal').classList.contains('show') && doc.getElementById('elManageModal').classList.contains('show');
    doc.getElementById('elManageModal').dispatchEvent(new w.Event('hidden.bs.modal'));
    doc.getElementById('elManageModal').classList.remove('show');
    w.__modalShows = {};
    await sleep(120);                       // closing Manage reloads the table; wait for it

    // ───────────────────────── manage: render ─────────────────────────
    out.gets = []; out.posts = [];
    const A = fx.mainListId;
    doc.querySelector('#emailListsBody tr[data-list-id="' + A + '"] button').click();
    await sleep(120);
    C.manage_opens_once = w.__modalShows.elManageModal === 1 && doc.getElementById('elManageModal').classList.contains('show');
    C.manage_focus_goes_to_the_type_radio_after_shown = active(w) === 'elTypeMember';
    C.manage_title_names_list = text(w, 'elManageTitle').indexOf(fx.details[A].list.name) >= 0 && text(w, 'elManageTitle').indexOf('Manage list') === 0;
    const sum = doc.getElementById('elSummary');
    C.banner_headline = /resolves to \d+ unique address/.test(sum.textContent) && sum.getAttribute('aria-live') === 'polite';
    C.banner_is_warning_when_problems = (fx.details[A].summary.problems > 0) === sum.classList.contains('alert-warning');
    C.banner_lists_problem_counts = /no email address/.test(sum.textContent) && /deleted/.test(sum.textContent);
    const erows = doc.querySelectorAll('#elEntriesBody tr[data-entry-id]');
    C.entries_one_row_per_entry = erows.length === fx.details[A].members.length;
    C.entries_problems_first_as_the_server_ordered = erows[0].textContent.indexOf(fx.details[A].members[0].label) >= 0;
    C.entries_xss_member_name_is_text = doc.querySelectorAll('#elEntriesBody img, #elEntriesBody b, #elEntriesBody script').length === 0 && !w.__xss
        && doc.getElementById('elEntriesBody').textContent.indexOf(fx.xssLabel) >= 0;
    C.entries_orphan_says_deleted_record_never_null = /\(deleted record\)/.test(doc.getElementById('elEntriesBody').textContent) && !/\bnull\b|undefined/.test(doc.getElementById('elEntriesBody').textContent);
    const body = doc.getElementById('elEntriesBody');
    C.entries_status_has_icon_and_word = Array.prototype.every.call(erows, function (r) { const cells = r.querySelectorAll('td'); return cells[2].querySelector('i.bi') !== null && cells[2].textContent.trim().length > 1; });
    C.entries_fix_in_roster_link = !!body.querySelector('a[href^="roster.php?id="]') && /Fix in Roster/.test(body.textContent);
    C.entries_edit_in_contacts_link_for_a_contact_problem = fx.hasContactProblem ? /Edit in Contacts/.test(body.textContent) : true;
    C.entries_remove_buttons_named = Array.prototype.every.call(erows, function (r) { const b = r.querySelector('button[aria-label^="Remove"]'); return b && b.type === 'button'; });
    const subBtn = Array.prototype.filter.call(body.querySelectorAll('button.btn-link'), function (b) { return /address/.test(b.textContent); })[0];
    C.entries_sublist_gives_a_link_that_opens_it = !!subBtn;
    C.edit_form_prefilled = doc.getElementById('elEditName').value === fx.details[A].list.name;

    // ───────────────────────── manage: add by picker ─────────────────────────
    out.gets = []; out.posts = [];
    doc.getElementById('elPickerInput').focus();
    doc.getElementById('elPickerInput').dispatchEvent(new w.Event('focus'));
    await sleep(60);
    C.picker_click_to_browse_queries_with_empty_text = out.gets.some(function (g) { return g.action === 'search_recipients' && /type=member/.test(g.url) && /[?&]q=(&|$)/.test(g.url) && /list_id=/.test(g.url); });
    type(w, 'elPickerInput', fx.queries.member);
    await sleep(320);
    C.picker_typing_queries_server_with_text_and_list = out.gets.some(function (g) { return g.action === 'search_recipients' && g.url.indexOf('q=' + encodeURIComponent(fx.queries.member)) >= 0; });
    const opts = doc.querySelectorAll('#elPickerInput ~ .searchable-select-list li[role="option"].searchable-select-item');
    const optsReal = Array.prototype.filter.call(opts, function (o) { return o.getAttribute('data-idx') !== '-1'; });
    C.picker_shows_server_results = optsReal.length === fx.search['member|' + fx.queries.member + '|0'].items.length;
    C.picker_disabled_rows_show_reason_in_text = !!doc.querySelector('#elPickerInput ~ .searchable-select-list li[aria-disabled="true"]') && /already on this list/.test(doc.querySelector('#elPickerInput ~ .searchable-select-list').textContent);
    C.picker_no_none_row_in_a_picker_that_adds = doc.querySelector('#elPickerInput ~ .searchable-select-list li.empty-option') === null;
    C.picker_rows_are_text_not_markup = doc.querySelectorAll('#elPickerInput ~ .searchable-select-list img').length === 0 && !w.__xss;
    C.picker_aria = doc.getElementById('elPickerInput').getAttribute('role') === 'combobox' && doc.getElementById('elPickerInput').getAttribute('aria-expanded') === 'true' && doc.querySelector('#elPickerInput ~ .searchable-select-list').getAttribute('role') === 'listbox';
    // Esc closes ONLY the picker, never the modal behind it
    let modalSawEsc = false;
    doc.getElementById('elManageModal').addEventListener('keydown', function (e) { if (e.key === 'Escape') modalSawEsc = true; });
    key(w, 'elPickerInput', 'Escape');           // first Escape clears the typed text
    key(w, 'elPickerInput', 'Escape');           // second closes the list
    C.escape_in_picker_does_not_reach_the_modal = modalSawEsc === false;
    C.escape_closed_the_picker_and_the_modal_is_still_open = doc.querySelector('#elPickerInput ~ .searchable-select-list').classList.contains('d-none') && doc.getElementById('elManageModal').classList.contains('show');

    // choose the first ENABLED member by keyboard: Enter commits, focus moves to Add
    type(w, 'elPickerInput', fx.queries.member);
    await sleep(320);
    key(w, 'elPickerInput', 'Enter');          // the first ENABLED row is highlighted by default
    await sleep(20);
    C.enter_commits_the_highlight_and_moves_focus_to_add = active(w) === 'elAddBtn' && doc.getElementById('elAddBtn').disabled === false;
    C.add_button_plain_when_member_has_email = text(w, 'elAddBtnText') === 'Add';
    const showsBeforeAdd = w.__modalShows.elManageModal;
    click(w, 'elAddBtn');
    await sleep(140);
    const ap = lastPost('add_member');
    out.addMemberBody = ap ? ap.body : null;
    C.add_member_payload_is_member_with_ref_id_NOT_inline = !!ap && ap.body.member_type === 'member' && typeof ap.body.ref_id === 'number' && ap.body.ref_id === fx.expectedFirstEnabledMemberId && !('inline_email' in ap.body) && ap.body.list_id === A;
    C.add_success_message_names_what_was_added = /Added Added Label to The List/.test(text(w, 'elAddMsg'));
    C.add_refreshes_in_place_without_reshowing_the_modal = out.gets.filter(function (g) { return g.action === 'detail'; }).length >= 1 && w.__modalShows.elManageModal === showsBeforeAdd;
    C.add_clears_picker_and_refocuses_it = doc.getElementById('elPickerInput').value === '' && active(w) === 'elPickerInput' && doc.getElementById('elAddBtn').disabled === true;

    // a member with no email: the button says so
    type(w, 'elPickerInput', fx.queries.noEmail);
    await sleep(320);
    key(w, 'elPickerInput', 'Enter');
    await sleep(20);
    C.add_button_says_add_anyway_for_a_member_with_no_email = text(w, 'elAddBtnText') === 'Add anyway (no email on file)';
    fx.addWarnings = ['no_email'];
    click(w, 'elAddBtn');
    await sleep(140);
    C.add_no_email_success_mentions_the_gap = /no email address on file yet/.test(text(w, 'elAddMsg'));
    fx.addWarnings = [];
    out.addNoEmailBody = lastPost('add_member').body;

    // contact
    doc.getElementById('elTypeConstituent').checked = true;
    doc.getElementById('elTypeConstituent').dispatchEvent(new w.Event('change', { bubbles: true }));
    C.contact_shows_the_has_email_filter_checked_by_default = visible(w, 'elHasEmailWrap') && doc.getElementById('elHasEmail').checked === true;
    out.gets = [];
    type(w, 'elPickerInput', fx.queries.constituent);
    await sleep(320);
    C.contact_search_sends_has_email_1 = out.gets.some(function (g) { return /type=constituent/.test(g.url) && /has_email=1/.test(g.url); });
    doc.getElementById('elHasEmail').checked = false;
    doc.getElementById('elHasEmail').dispatchEvent(new w.Event('change', { bubbles: true }));
    out.gets = [];
    type(w, 'elPickerInput', fx.queries.constituent);
    await sleep(320);
    C.unticking_the_filter_sends_has_email_0 = out.gets.some(function (g) { return /type=constituent/.test(g.url) && /has_email=0/.test(g.url); });
    doc.getElementById('elHasEmail').checked = true;
    doc.getElementById('elHasEmail').dispatchEvent(new w.Event('change', { bubbles: true }));
    type(w, 'elPickerInput', fx.queries.constituent);
    await sleep(320);
    key(w, 'elPickerInput', 'Enter');
    await sleep(20);
    click(w, 'elAddBtn');
    await sleep(120);
    out.addConstituentBody = lastPost('add_member').body;
    C.add_member_payload_for_a_contact = out.addConstituentBody.member_type === 'constituent' && typeof out.addConstituentBody.ref_id === 'number';

    // sub-list
    doc.getElementById('elTypeList').checked = true;
    doc.getElementById('elTypeList').dispatchEvent(new w.Event('change', { bubbles: true }));
    C.sublist_hides_the_contact_filter = !visible(w, 'elHasEmailWrap');
    type(w, 'elPickerInput', fx.queries.list);
    await sleep(320);
    key(w, 'elPickerInput', 'Enter');
    await sleep(20);
    C.sublist_add_button_plain = text(w, 'elAddBtnText') === 'Add';
    click(w, 'elAddBtn');
    await sleep(120);
    out.addListBody = lastPost('add_member').body;
    C.add_member_payload_for_a_sublist = out.addListBody.member_type === 'list' && typeof out.addListBody.ref_id === 'number';

    // typed address
    doc.getElementById('elTypeInline').checked = true;
    doc.getElementById('elTypeInline').dispatchEvent(new w.Event('change', { bubbles: true }));
    C.address_type_swaps_picker_for_email_and_name_fields = !visible(w, 'elPickerBlock') && visible(w, 'elInlineBlock') && doc.getElementById('elAddBtn').disabled === false;
    click(w, 'elAddBtn');
    await sleep(30);
    C.address_empty_refused_client_side = /Type an email address/.test(text(w, 'elAddMsg')) && !out.posts.some(function (p) { return p.body && p.body.inline_email === ''; });
    doc.getElementById('elInlineEmail').value = '  typed@example.invalid ';
    doc.getElementById('elInlineName').value = 'Typed Person';
    key(w, 'elInlineEmail', 'Enter');
    await sleep(120);
    out.addInlineBody = lastPost('add_member').body;
    C.add_member_payload_for_a_typed_address = out.addInlineBody.member_type === 'inline' && out.addInlineBody.inline_email === 'typed@example.invalid' && out.addInlineBody.display_name === 'Typed Person' && !('ref_id' in out.addInlineBody);
    C.address_fields_cleared_after_add = doc.getElementById('elInlineEmail').value === '' && active(w) === 'elInlineEmail';

    // ───────────────────────── remove ─────────────────────────
    out.posts = []; out.gets = [];
    w.__confirm.answers = [false];
    doc.querySelector('#elEntriesBody button[aria-label^="Remove"]').click();
    await sleep(20);
    C.remove_declined_sends_nothing = countPosts('remove_member') === 0 && /^Remove "/.test(w.__confirm.asked[w.__confirm.asked.length - 1]);
    w.__confirm.answers = [true];
    const secondRemove = doc.querySelectorAll('#elEntriesBody button[aria-label^="Remove"]')[1];
    secondRemove.click();
    await sleep(150);
    const rm = lastPost('remove_member');
    out.removeBody = rm ? rm.body : null;
    C.remove_posts_entry_id_and_list_id = !!rm && typeof rm.body.id === 'number' && rm.body.list_id === A;
    C.remove_refreshes_in_place = out.gets.some(function (g) { return g.action === 'detail'; });
    C.remove_moves_focus_to_a_remove_button = (doc.activeElement.getAttribute('aria-label') || '').indexOf('Remove') === 0;

    // ───────────────────────── preview, csv, rename ─────────────────────────
    out.gets = [];
    doc.getElementById('elPreviewDetails').open = true;
    doc.getElementById('elPreviewDetails').dispatchEvent(new w.Event('toggle'));
    await sleep(60);
    C.preview_reads_resolve_and_lists_recipients_as_text = out.gets.some(function (g) { return g.action === 'resolve'; })
        && text(w, 'elPreviewBody').indexOf(fx.resolve[A].recipients[0].email) >= 0 && doc.querySelectorAll('#elPreviewBody img').length === 0;
    C.preview_lists_what_was_left_out_with_a_reason = fx.resolve[A].excluded.length === 0 || /Left out/.test(text(w, 'elPreviewBody'));
    doc.getElementById('elCsvText').value = 'a@example.invalid,A\nnot an address\nb@example.invalid';
    click(w, 'elCsvBtn');
    await sleep(120);
    out.csvBody = lastPost('import_csv').body;
    C.csv_posts_list_id_and_text = out.csvBody.list_id === A && /a@example.invalid/.test(out.csvBody.csv_text);
    C.csv_result_reports_added_skipped_duplicates_and_errors = /Added 2, skipped 1, already on the list 1/.test(text(w, 'elCsvResult')) && /line 2/.test(text(w, 'elCsvResult'));
    doc.getElementById('elEditName').value = 'P155 Renamed';
    click(w, 'elEditSave');
    await sleep(120);
    out.updateBody = lastPost('update').body;
    C.rename_posts_id_name_description = out.updateBody.id === A && out.updateBody.name === 'P155 Renamed' && typeof out.updateBody.description === 'string';

    // sub-list link opens that list (no second modal stacking)
    doc.getElementById('elManageModal').dispatchEvent(new w.Event('hidden.bs.modal'));
    doc.getElementById('elManageModal').classList.remove('show');

    // ───────────────────────── options ─────────────────────────
    out.gets = []; out.posts = [];
    click(w, 'elBtnOptions');
    await sleep(80);
    C.options_load_on_first_open = out.gets.some(function (g) { return g.action === 'get_options'; });
    const boxes = doc.querySelectorAll('#elSkipStatuses input[type=checkbox]');
    C.options_one_checkbox_per_status_label = boxes.length >= fx.options.status_labels.length;
    C.options_prechecked_from_the_server = Array.prototype.every.call(boxes, function (b) { return b.checked === (fx.options.skip_statuses.indexOf(b.value.toLowerCase()) >= 0); });
    C.options_each_checkbox_has_a_label = Array.prototype.every.call(boxes, function (b) { return doc.querySelector('label[for="' + b.id + '"]'); });
    boxes[0].checked = !boxes[0].checked;
    doc.getElementById('elRequireEmail').checked = true;
    click(w, 'elOptionsSave');
    await sleep(120);
    out.optionsBody = lastPost('save_options').body;
    C.options_post_the_ticked_labels_and_the_flag = Array.isArray(out.optionsBody.skip_statuses) && out.optionsBody.require_email_on_add === true
        && out.optionsBody.skip_statuses.length === Array.prototype.filter.call(boxes, function (b) { return b.checked; }).length;
    C.options_saved_message = /Saved/.test(text(w, 'elOptionsMsg'));

    // ───────────────────────── archive ─────────────────────────
    out.posts = [];
    w.__confirm.answers = [false];
    doc.querySelector('#emailListsBody tr[data-list-id] button[aria-label^="Archive"]').click();
    await sleep(20);
    C.archive_declined_sends_nothing = countPosts('archive') === 0;
    fx.archiveInUse = true;
    w.__confirm.answers = [true, true];
    doc.querySelector('#emailListsBody tr[data-list-id] button[aria-label^="Archive"]').click();
    await sleep(160);
    const arch = out.posts.filter(function (p) { return p.action === 'archive'; });
    out.archiveBodies = arch.map(function (p) { return p.body; });
    C.archive_in_use_asks_again_then_forces = arch.length === 2 && arch[0].body.force === false && arch[1].body.force === true && /Archive anyway/.test(w.__confirm.asked[w.__confirm.asked.length - 1]);
    fx.archiveInUse = false;

    // nothing ever set the flags a stored-XSS payload would set
    C.no_xss_flag_ever_set = !w.__xss;

    // ───────────────────────── SearchableSelect on its own ─────────────────────────
    const w2 = new JSDOM('<!doctype html><body><div class="searchable-select-wrap position-relative"><input id="i" class="searchable-select-input"><input type="hidden" id="h"><ul class="searchable-select-list list-group d-none"></ul></div></body>', { runScripts: 'outside-only' }).window;
    w2.eval(fs.readFileSync(root + '/assets/js/searchable-select.js', 'utf8'));
    const d2 = w2.document;
    const I = d2.getElementById('i'), H = d2.getElementById('h');
    function k2(k) { const ev = new w2.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }); I.dispatchEvent(ev); return ev; }
    function in2(v) { I.value = v; I.dispatchEvent(new w2.Event('input', { bubbles: true })); }
    // (a) back-compat: no new options => local filtering, Enter commits, the "none" row is there
    const local = w2.SearchableSelect.attach(I, H, [{ id: 1, n: 'Alpha' }, { id: 2, n: 'Beta' }, { id: 3, n: 'Alphabet' }], {
        getLabel: function (x) { return x.n; }, getSearchText: function (x) { return x.n.toLowerCase(); }
    });
    I.dispatchEvent(new w2.Event('focus'));
    out.ss.compat_none_row_present = d2.querySelector('li.empty-option') !== null;
    in2('alph');
    out.ss.compat_local_filter = d2.querySelectorAll('li[data-idx]:not([data-idx="-1"])').length === 2;
    k2('Enter');
    out.ss.compat_enter_commits_first = H.value === '1' && I.value === 'Alpha' && local.getSelectedItem().id === 1;
    out.ss.compat_aria_attrs = I.getAttribute('role') === 'combobox' && I.getAttribute('aria-controls') === d2.querySelector('ul').id && d2.querySelector('ul').getAttribute('role') === 'listbox';
    in2('be'); k2('Escape');
    out.ss.compat_escape_clears_text_first = I.value === '';
    local.destroy();
    // (b) remote: stale answers are ignored, debounce, disabled rows
    d2.getElementById('i').value = ''; H.value = '';
    const calls = [];
    let answerLater = null;
    const remote = w2.SearchableSelect.attach(I, H, [], {
        queryDelay: 30,
        hideEmptyOption: true,
        onQuery: function (q, set) { calls.push(q); if (q === 'slow') answerLater = set; else set([{ id: 7, n: 'Fast ' + q, off: false }, { id: 8, n: 'Off ' + q, off: true, why: 'because' }, { id: 9, n: 'Third ' + q, off: false }]); },
        getLabel: function (x) { return x.n; }, getValue: function (x) { return String(x.id); },
        isDisabled: function (x) { return x.off; }, getDisabledReason: function (x) { return x.why; },
        getSubLabel: function (x) { return x.sub || ''; }
    });
    in2('s'); in2('sl'); in2('slow');
    await new Promise(function (r) { setTimeout(r, 120); });
    out.ss.remote_debounced_to_one_call = calls.length === 1 && calls[0] === 'slow';
    in2('fast');
    await new Promise(function (r) { setTimeout(r, 120); });
    if (answerLater) answerLater([{ id: 99, n: 'STALE', off: false }]);        // the earlier, slower request answers late
    out.ss.remote_ignores_a_stale_answer = d2.querySelector('ul').textContent.indexOf('STALE') === -1 && d2.querySelector('ul').textContent.indexOf('Fast fast') >= 0;
    out.ss.remote_no_none_row_when_hidden = d2.querySelector('li.empty-option') === null;
    out.ss.remote_disabled_row_is_marked_with_reason = d2.querySelector('li[aria-disabled="true"]') !== null && /because/.test(d2.querySelector('ul').textContent);
    k2('ArrowDown');                                                            // 0 is highlighted (Fast); Down must SKIP the disabled row 1 and land on Third
    const act = d2.querySelector('li.active');
    out.ss.remote_arrow_skips_disabled = !!act && /Third/.test(act.textContent);
    k2('ArrowUp');
    out.ss.remote_arrow_up_skips_disabled_too = /Fast/.test(d2.querySelector('li.active').textContent);
    d2.querySelector('li[aria-disabled="true"]').click();
    out.ss.remote_clicking_disabled_commits_nothing = H.value === '';
    let committed = null;
    k2('Enter');
    out.ss.remote_enter_commits_first_enabled = H.value === '7';
    remote.destroy();
    // (c) a label that is markup renders as text, and Escape stops where it was consumed
    I.value = ''; H.value = '';
    const evil = w2.SearchableSelect.attach(I, H, [{ id: 1, n: '<img src=x onerror="window.__xss3=1"> name' }], { getLabel: function (x) { return x.n; }, getSubLabel: function () { return '<b>sub</b>'; } });
    I.dispatchEvent(new w2.Event('focus'));
    out.ss.labels_are_escaped = d2.querySelectorAll('ul img, ul b').length === 0 && !w2.__xss3 && /<img src=x/.test(d2.querySelector('ul').textContent);
    let reached = false;
    d2.body.addEventListener('keydown', function (e) { if (e.key === 'Escape') reached = true; });
    k2('Escape');                                                               // list open: consumed
    out.ss.escape_consumed_when_it_closes_the_list = reached === false;
    k2('Escape');                                                               // list closed: not the picker's business, passes through
    out.ss.escape_passes_through_when_nothing_to_close = reached === true;
}

main().then(function () { console.log(JSON.stringify(out)); process.exit(0); },
    function (e) { out.errors.push(String(e && e.stack || e)); console.log(JSON.stringify(out)); process.exit(0); });
