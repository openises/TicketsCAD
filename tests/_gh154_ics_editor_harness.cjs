// GH#154 - drives the REAL ICS forms page script (assets/js/ics-forms.js)
// against the REAL page markup (ics-forms.php, rendered by PHP) and the REAL
// templates (inc/ics-forms-builtin.php), in jsdom. Used by
// tests/test_gh154_ics_editor_jsdom.php.
//
// Node-side TEST code (.cjs, CommonJS), never served to a browser, so the
// browser "ES5 only" rule does not apply here; the script UNDER test is ES5.
//
// For every built-in form type it clicks the hub's New button, checks what the
// editor drew, links an incident through the incident search box (exactly as a
// person types and picks), records what was pre-filled, fills every input,
// presses Save and records the request body - the PHP test replays each body
// against the real endpoint. It also opens saved forms (the real endpoint's
// GET ?id= answers, captured by PHP) and saves them back.
//
// Usage: node _gh154_ics_editor_harness.cjs <root> <fixture.json>
'use strict';
const fs = require('fs');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const fx = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const out = { types: {}, reopen: {}, errors: [], find: {} };
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

const dom = new JSDOM(fx.html, { runScripts: 'outside-only', url: 'http://localhost/ics-forms.php', pretendToBeVisual: true });
const w = dom.window;
w.appPrint = function () {};
w.confirm = function () { return true; };
w.open = function () { return { document: { write: function () {}, close: function () {} }, print: function () {} }; };
let lastPost = null;
let savedList = [];
w.fetch = function (url, opts) {
    opts = opts || {};
    const method = opts.method || 'GET';
    let json = {};
    if (method === 'POST') {
        let body = null;
        try { body = JSON.parse(opts.body); } catch (e) { body = null; }
        lastPost = body;
        json = { ok: true, id: 900001, can_delete: true };
    } else if (/api\/ics-forms\.php\?template=/.test(url)) {
        const t = decodeURIComponent(/template=([^&]+)/.exec(url)[1]);
        json = fx.templates[t] || { error: 'Unknown form type: ' + t };
    } else if (/api\/ics-forms\.php\?id=/.test(url)) {
        const id = /id=(\d+)/.exec(url)[1];
        json = fx.saved[id] || { error: 'Form not found' };
    } else if (/api\/ics-forms\.php\?limit=/.test(url)) {
        json = { forms: savedList, total: savedList.length };
    } else if (/api\/ics-form-types\.php/.test(url)) {
        json = { types: [] };
    } else if (/api\/incidents\.php\?search=/.test(url)) {
        json = { incidents: [fx.incident] };
    } else if (/api\/incident-detail\.php\?id=/.test(url)) {
        json = { incident: fx.incident, assignments: [], actions: [] };
    }
    return Promise.resolve({ json: function () { return Promise.resolve(json); } });
};
w.addEventListener('error', function (e) { out.errors.push(String(e.message || e)); });

function $(id) { return w.document.getElementById(id); }
function fire(el, type) { el.dispatchEvent(new w.Event(type, { bubbles: true })); }

function fillInput(el, val) {
    if (el.type === 'checkbox') { el.checked = true; return; }
    if (el.tagName === 'SELECT') {
        for (let i = 0; i < el.options.length; i++) {
            if (el.options[i].value !== '') { el.value = el.options[i].value; return; }
        }
        return;
    }
    if (el.type === 'date') el.value = '2026-05-06';
    else if (el.type === 'time') el.value = '07:08';
    else if (el.type === 'datetime-local') el.value = '2026-05-06T07:08';
    else if (el.type === 'number') el.value = '3';
    else el.value = val;
}

(async function () {
    try {
        w.eval(fs.readFileSync(root + '/assets/js/ics-forms.js', 'utf8'));
        w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
        await sleep(20);

        // "Find a form": typing filters, Enter opens the first match.
        const find = $('icsFormFind');
        if (find) {
            find.value = 'radio communications';
            fire(find, 'input');
            const visible = [];
            w.document.querySelectorAll('[data-new-form]').forEach(function (b) {
                let el = b, hidden = false;
                while (el && el !== w.document.body) { if (el.style && el.style.display === 'none') { hidden = true; break; } el = el.parentNode; }
                if (!hidden) visible.push(b.getAttribute('data-new-form'));
            });
            out.find.visible = visible;
            find.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
            await sleep(20);
            out.find.opened = ($('editorTitle') || {}).textContent || '';
            $('btnBackToHub').click();
            await sleep(10);
            find.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            out.find.clearedAll = Array.prototype.every.call(w.document.querySelectorAll('[data-form-search]'),
                function (c) { return c.style.display !== 'none'; });
        }

        for (const slug of fx.slugs) {
            const r = { opened: false };
            out.types[slug] = r;
            const btn = w.document.querySelector('[data-new-form="' + slug + '"]');
            r.hasButton = !!btn;
            if (!btn) continue;
            btn.click();
            await sleep(15);
            r.title = ($('editorTitle') || {}).textContent || '';
            r.opened = $('editorSection').style.display !== 'none';
            const tpl = fx.templates[slug];

            // What the editor drew.
            r.missing = [];
            r.signatures = w.document.querySelectorAll('#formFieldsContainer .ics-signature-note').length;
            r.helpCount = w.document.querySelectorAll('#formFieldsContainer .ics-help').length;
            r.fixedRows = {};
            r.defaultRows = {};
            tpl.fields.forEach(function (f) {
                if (f.type === 'section_header' || f.type === 'signature' || f.legacy) return;
                if (f.type === 'table') {
                    const tb = w.document.querySelector('#tbl_' + f.key + ' tbody');
                    if (!tb) { r.missing.push(f.key); return; }
                    if (f.fixed_rows) r.fixedRows[f.key] = tb.querySelectorAll('tr').length;
                    if (f.default_data) r.defaultRows[f.key] = tb.querySelectorAll('tr').length;
                    return;
                }
                if (!$('ics_' + f.key)) r.missing.push(f.key);
            });

            // Link an incident the way a person does: type, wait, pick.
            const search = $('linkIncidentSearch');
            search.value = 'GH154';
            fire(search, 'input');
            await sleep(300);
            const item = w.document.querySelector('.incident-search-item');
            if (item) item.dispatchEvent(new w.MouseEvent('mousedown', { bubbles: true }));
            await sleep(30);
            r.prefill = {};
            w.document.querySelectorAll('#formFieldsContainer [id^="ics_"]').forEach(function (el) {
                if (el.value) r.prefill[el.id.substring(4)] = el.value;
            });
            r.formTitle = $('formTitle').value;

            // Fill everything, then Save.
            w.document.querySelectorAll('#formFieldsContainer input, #formFieldsContainer textarea, #formFieldsContainer select').forEach(function (el) {
                const key = el.id ? el.id.substring(4) : (el.name || '').replace(/\[(\d+)\]\[/, '_$1_').replace(/[\[\]]/g, '');
                fillInput(el, 'J' + slug.replace('-', '') + '_' + key);
            });
            lastPost = null;
            $('btnSave').click();
            await sleep(15);
            r.payload = lastPost;
            $('btnBackToHub').click();
            await sleep(10);
        }

        // Saved forms: open the real endpoint's GET ?id= answer, save it back.
        for (const id of Object.keys(fx.saved)) {
            const row = fx.saved[id];
            savedList = [{ id: Number(id), form_type: row.form_type, title: row.title, status: row.status,
                incident_id: null, created_by_name: 'gh154', updated_at: '2026-10-19 10:00:00', can_delete: false }];
            $('btnBackToHub').click();
            await sleep(15);
            const tr = w.document.querySelector('.saved-form-row[data-form-id="' + id + '"]');
            const r = { opened: !!tr };
            out.reopen[id] = r;
            if (!tr) continue;
            tr.click();
            await sleep(30);
            r.values = {};
            w.document.querySelectorAll('#formFieldsContainer [id^="ics_"]').forEach(function (el) {
                r.values[el.id.substring(4)] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
            });
            r.cells = {};
            w.document.querySelectorAll('#formFieldsContainer tbody [name]').forEach(function (el) {
                r.cells[el.getAttribute('name')] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
            });
            r.legacyBadges = w.document.querySelectorAll('#formFieldsContainer .ics-legacy-badge').length;
            lastPost = null;
            $('btnSave').click();
            await sleep(15);
            r.payload = lastPost;
        }
    } catch (e) {
        out.errors.push(String(e && e.stack ? e.stack : e));
    }
    process.stdout.write(JSON.stringify(out));
})();
