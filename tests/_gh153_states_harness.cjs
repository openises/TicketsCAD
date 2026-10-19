// GH#153 - drives the REAL browser scripts in jsdom. Used by
// tests/test_gh153_states_wiring.php.
//
// Node-side TEST code (.cjs, CommonJS), never served to a browser, so the
// browser "ES5 only" rule does not apply here; the scripts under test are ES5
// and the wiring test asserts so.
//
// Modes:
//   select  assets/js/states-select.js (TCADStates) against the four answers
//           api/incident-types.php can give: a list, an EMPTIED list
//           (states_from_db true), an old server without the flag, no server.
//   geo     the New Incident geocoder -> State helper (selectStateFromAddress),
//           extracted from assets/js/new-incident.js by the PHP test.
//   admin   states-admin.php's markup + assets/js/states-admin.js with the
//           REAL API's answers (computed by the PHP test from inc/states.php),
//           clicked through like a person: list, wizard (quick pick -> mode ->
//           preview -> apply -> label), import (errors, then a good file),
//           inline edit, delete with the usage warning, add.
//
// Usage: node _gh153_states_harness.cjs <mode> <root> <fixture.json>
// Prints ONE JSON object: { checks: {label: bool}, posts: [...], gets: [...], errors: [...] }.
'use strict';
const fs = require('fs');
const { JSDOM } = require('jsdom');

const mode = process.argv[2];
const root = process.argv[3];
const fx = JSON.parse(fs.readFileSync(process.argv[4], 'utf8'));
const out = { checks: {}, posts: [], gets: [], errors: [] };
const C = out.checks;
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function reply(status, json) {
    return Promise.resolve({ ok: status >= 200 && status < 300, status: status,
        text: function () { return Promise.resolve(JSON.stringify(json)); },
        json: function () { return Promise.resolve(json); } });
}

async function modeSelect() {
    const src = fs.readFileSync(root + '/assets/js/states-select.js', 'utf8');
    async function run(answer, selected) {
        const dom = new JSDOM('<!doctype html><select id="s"></select>', { runScripts: 'outside-only', url: 'http://localhost/x.php' });
        const w = dom.window;
        w.fetch = function () { return answer === 'reject' ? Promise.reject(new Error('offline')) : reply(200, answer); };
        w.eval(src);
        const sel = w.document.getElementById('s');
        if (selected) { sel.setAttribute('data-selected', selected); }
        await w.TCADStates.fill(sel);
        return Array.prototype.map.call(sel.options, function (o) { return o.value; });
    }
    let v = await run({ states: [{ code: 'ON', name: 'Ontario' }, { code: 'QC', name: 'Quebec' }], states_from_db: true });
    C['a configured list is offered as is'] = JSON.stringify(v) === JSON.stringify(['', 'ON', 'QC']);
    v = await run({ states: [], states_from_db: true });
    C['an EMPTIED list (states_from_db true) stays empty - no US states substituted'] = JSON.stringify(v) === JSON.stringify(['']);
    v = await run({ states: [] });
    C['an empty answer from a server without the flag still falls back to the US list'] = v.length === 52 && v.indexOf('MN') !== -1;
    v = await run('reject');
    C['no server at all falls back to the US list'] = v.length === 52;
    v = await run({ states: [{ code: 'ON', name: 'Ontario' }], states_from_db: true }, 'MN');
    C['a saved code that is no longer on the list is still shown (records never blank)'] = JSON.stringify(v) === JSON.stringify(['', 'ON', 'MN']);
    v = await run({ states: [], states_from_db: true }, 'MN');
    C['...even when the list is empty'] = JSON.stringify(v) === JSON.stringify(['', 'MN']);
}

async function modeGeo() {
    const dom = new JSDOM('<!doctype html><select id="state"><option value="">-</option>'
        + '<option value="ON">Ontario</option><option value="QC">Quebec</option><option value="QX">Québec Ville</option>'
        + '<option value="MIC">Michoacán de Ocampo</option><option value="MN">Minnesota</option>'
        + '<option value="NLE">Nuevo León</option></select>', { runScripts: 'outside-only' });
    const w = dom.window;
    w.eval(fx.helper + '\nwindow.__pick = selectStateFromAddress;');
    const sel = w.document.getElementById('state');
    function pick(addr) { sel.value = ''; const r = w.__pick(sel, addr); return [r, sel.value]; }
    let r;
    r = pick({ state: 'Minnesota', 'ISO3166-2-lvl4': 'US-MN' });
    C['US: "Minnesota" -> MN (unchanged behaviour)'] = r[0] === true && r[1] === 'MN';
    r = pick({ state: 'Ontario', 'ISO3166-2-lvl4': 'CA-ON' });
    C['Canada: "Ontario" -> ON'] = r[1] === 'ON';
    r = pick({ state: 'Québec', 'ISO3166-2-lvl4': 'CA-QC' });
    C['Canada: the geocoder\'s "Québec" -> QC ("Quebec" on the list), by name without accents'] = r[1] === 'QC';
    r = pick({ state: 'Michoacán', 'ISO3166-2-lvl4': 'MX-MIC' });
    C['Mexico: the geocoder\'s short "Michoacán" -> MIC, by the ISO code'] = r[1] === 'MIC';
    r = pick({ state: 'nuevo leon' });
    C['accent- and case-insensitive name: "nuevo leon" -> NLE'] = r[1] === 'NLE';
    r = pick({ state: 'mn' });
    C['a code typed as the state still matches'] = r[1] === 'MN';
    r = pick({ state: '', 'ISO3166-2-lvl4': 'US-MN' });
    C['no state name, ISO code only -> MN'] = r[1] === 'MN';
    r = pick({ state: 'Bavaria', 'ISO3166-2-lvl4': 'DE-BY' });
    C['nothing matching -> the field is left alone, false returned'] = r[0] === false && r[1] === '';
    r = pick(null);
    C['no address -> false, no error'] = r[0] === false;
}

async function modeAdmin() {
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="tok153"></head><body>' + fx.html + '</body></html>',
        { runScripts: 'outside-only', url: 'http://localhost/states-admin.php', pretendToBeVisual: true });
    const w = dom.window;
    const d = w.document;
    w.__confirm = [];
    w.confirm = function (t) { w.__confirm.push(String(t)); return true; };
    w.__locations = [];
    w.bootstrap = { Modal: function (e) {
        this.show = function () { e.classList.add('show'); };
        this.hide = function () { e.classList.remove('show'); };
    } };
    let importAnswer = 'bad';
    w.fetch = function (url, opts) {
        opts = opts || {};
        const method = opts.method || 'GET';
        if (method === 'POST') {
            let body = null;
            try { body = JSON.parse(opts.body); } catch (e) { body = null; }
            out.posts.push(body);
            const a = body && body.action;
            if (a === 'preset_preview') { return reply(200, fx.preview); }
            if (a === 'preset_apply') { return reply(200, fx.apply); }
            if (a === 'import_preview') { return reply(200, importAnswer === 'bad' ? fx.import_bad : fx.import_good); }
            if (a === 'import_apply') { return reply(200, fx.apply); }
            if (a === 'set_label') { return reply(200, { ok: true, label: body.label, lang: body.lang, previous: 'State' }); }
            if (a === 'update') { return reply(200, { ok: true, changed: true, id: body.id, code: body.code, name: body.name, old_code: body.code, old_code_usage: 0 }); }
            if (a === 'delete') { return reply(200, { ok: true, deleted: { id: body.id }, usage: 2 }); }
            if (a === 'create') { return reply(200, { ok: true, state: { id: 999, code: String(body.code).toUpperCase(), name: body.name } }); }
            return reply(404, { error: 'Unknown action' });
        }
        out.gets.push(url);
        const m = /[?&]action=([a-z_]+)/.exec(url);
        const a = m ? m[1] : '';
        if (a === 'list') { return reply(200, fx.list); }
        if (a === 'presets') { return reply(200, fx.presets); }
        if (a === 'usage') { return reply(200, fx.usage); }
        return reply(404, { error: 'Unknown action' });
    };
    w.addEventListener('error', function (e) { out.errors.push(String(e.message)); });
    try {
        w.eval(fs.readFileSync(root + '/assets/js/states-admin.js', 'utf8'));
    } catch (e) { out.errors.push('load: ' + e.message); }
    await sleep(50);

    const rows = d.querySelectorAll('#stRows tr');
    C['the list renders one row per entry'] = rows.length === fx.list.states.length;
    C['the count badge shows the total'] = d.getElementById('stCount').textContent === String(fx.list.count);
    C['a name holding markup is shown as text, never parsed (no <img> in the table)'] = d.querySelector('#stRows img') === null
        && d.getElementById('stRows').textContent.indexOf('<img') !== -1 && !w.__xss;
    C['the field label card shows the current label'] = d.getElementById('stLabelCurrent').textContent === '"' + fx.list.field_label + '"';
    const choiceVals = Array.prototype.map.call(d.getElementById('stLabelChoice').options, function (o) { return o.value; });
    C['...with State / Province / ... and "something else"'] = choiceVals.indexOf('Province') !== -1 && choiceVals.indexOf('__custom__') !== -1;
    C['the built-in lists are offered as CSV downloads'] = d.querySelectorAll('#stPresetDownloads a').length === fx.presets.presets.length
        && d.querySelector('#stPresetDownloads a').getAttribute('href').indexOf('api/states.php?action=preset_csv&preset=') === 0;
    C['the schema banner is hidden when the table is healthy'] = d.getElementById('stSchemaBanner').classList.contains('d-none');

    // filter
    const filter = d.getElementById('stFilter');
    filter.value = 'minne';
    filter.dispatchEvent(new w.Event('input'));
    C['the filter narrows the table'] = d.querySelectorAll('#stRows tr').length === 1;
    filter.value = '';
    filter.dispatchEvent(new w.Event('input'));

    // ── wizard ──
    d.getElementById('stBtnWizard').click();
    C['the wizard opens on step 1'] = d.getElementById('stWizard').classList.contains('show') && !d.getElementById('stWizStep1').classList.contains('d-none');
    const picks = d.querySelectorAll('#stQuickPicks button');
    C['every quick pick is a button'] = picks.length === fx.presets.quick_picks.length;
    let canada = null;
    Array.prototype.forEach.call(picks, function (b) { if (b.textContent === 'Canada') { canada = b; } });
    canada.click();
    C['one click on "Canada" jumps to "Replace or add"'] = !d.getElementById('stWizStep3').classList.contains('d-none')
        && d.getElementById('stPreset_ca').checked === true;
    d.getElementById('stWizNext').click();
    await sleep(30);
    const pv = out.posts[out.posts.length - 1];
    C['Next asks the server for a preview of exactly that choice'] = pv.action === 'preset_preview' && pv.mode === 'replace'
        && JSON.stringify(pv.selection) === JSON.stringify({ ca: [] }) && pv.csrf_token === 'tok153';
    const heads = Array.prototype.map.call(d.querySelectorAll('#stWizPreview h6'), function (h) { return h.textContent; });
    C['the preview lists what is added and removed'] = heads.indexOf('To add (' + fx.preview.adds.length + ')') !== -1
        && heads.indexOf('To remove (' + fx.preview.removes.length + ')') !== -1;
    const previewText = d.getElementById('stWizPreview').textContent;
    C['...and, for a removed code, how many saved records use it and where'] = previewText.indexOf('3 — Incidents: 2, Members: 1') !== -1;
    C['...and that saved records keep their code'] = previewText.indexOf('Saved records are never changed') !== -1;
    C['Apply is offered after the preview'] = !d.getElementById('stWizApply').classList.contains('d-none');
    d.getElementById('stWizApply').click();
    await sleep(30);
    const ap = out.posts[out.posts.length - 1];
    C['Apply sends the same choice and the preview\'s list hash'] = ap.action === 'preset_apply' && ap.list_hash === fx.preview.list_hash
        && JSON.stringify(ap.selection) === JSON.stringify({ ca: [] });
    C['the result step says what changed and suggests "Province"'] = !d.getElementById('stWizStep5').classList.contains('d-none')
        && d.getElementById('stWizResult').textContent.indexOf(fx.apply.added + ' added') !== -1
        && d.getElementById('stWizLabelChoice').value === 'Province';
    d.getElementById('stWizSetLabel').click();
    await sleep(30);
    const sl = out.posts[out.posts.length - 1];
    C['Set label sends the suggested label'] = sl.action === 'set_label' && sl.label === 'Province' && sl.lang === fx.list.label_lang;

    // ── import ──
    d.getElementById('stBtnImport').click();
    d.getElementById('stImportText').value = 'code,name\nON,Ontario\non,Again\n';
    d.getElementById('stImportPreviewBtn').click();
    await sleep(30);
    const ip = out.posts[out.posts.length - 1];
    C['import preview sends the pasted text and the mode'] = ip.action === 'import_preview' && ip.csv.indexOf('ON,Ontario') !== -1 && ip.mode === 'replace';
    C['a bad file shows each problem with its line and offers no Apply'] = d.getElementById('stImportPreview').textContent.indexOf('Line 3:') !== -1
        && d.getElementById('stImportApply').classList.contains('d-none');
    importAnswer = 'good';
    d.getElementById('stImportPreviewBtn').click();
    await sleep(30);
    C['a good file shows the preview and offers Apply'] = !d.getElementById('stImportApply').classList.contains('d-none');
    d.getElementById('stImportApply').click();
    await sleep(30);
    const ia = out.posts[out.posts.length - 1];
    C['import apply sends the text, the mode and the list hash'] = ia.action === 'import_apply' && ia.list_hash === fx.import_good.list_hash && ia.csv.indexOf('ON,Ontario') !== -1;

    // ── inline edit ──
    await sleep(20);
    const firstEdit = d.querySelector('#stRows tr td.text-end button');
    firstEdit.click();
    const nameInput = d.getElementById('stEditName');
    C['Edit turns the row into inputs'] = !!nameInput && !!d.getElementById('stEditCode');
    nameInput.value = 'Renamed';
    d.querySelector('#stRows tr.table-active button.btn-success').click();
    await sleep(30);
    const up = out.posts[out.posts.length - 1];
    C['Save sends update with the row id, code and new name'] = up.action === 'update' && up.id === fx.list.states[0].id && up.name === 'Renamed'
        && up.code === fx.list.states[0].code;

    // ── delete with the usage warning ──
    await sleep(20);
    const delBtn = d.querySelectorAll('#stRows tr td.text-end button')[1];
    delBtn.click();
    await sleep(40);
    C['Delete first asks how many records use the code, and says they keep it'] = w.__confirm.length === 1
        && w.__confirm[0].indexOf('2 saved records use this code') !== -1 && w.__confirm[0].indexOf('keep the code') !== -1;
    const dl = out.posts[out.posts.length - 1];
    C['...then sends delete for that row'] = dl.action === 'delete' && dl.id === fx.list.states[0].id;

    // ── add ──
    d.getElementById('stBtnAdd').click();
    d.getElementById('stAddCode').value = 'zq';
    d.getElementById('stAddName').value = 'Zed';
    d.getElementById('stAddSave').click();
    await sleep(30);
    const cr = out.posts[out.posts.length - 1];
    C['Add sends create with the code and name'] = cr.action === 'create' && cr.code === 'zq' && cr.name === 'Zed';

    C['every POST carried the page\'s CSRF token'] = out.posts.every(function (p) { return p.csrf_token === 'tok153'; });
    C['no script errors'] = out.errors.length === 0;
}

(async function () {
    try {
        if (mode === 'select') { await modeSelect(); }
        else if (mode === 'geo') { await modeGeo(); }
        else if (mode === 'admin') { await modeAdmin(); }
        else { out.errors.push('unknown mode'); }
    } catch (e) {
        out.errors.push('harness: ' + (e && e.stack ? e.stack : e));
    }
    process.stdout.write(JSON.stringify(out));
})();
