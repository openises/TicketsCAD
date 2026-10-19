// GH#139 - drives the REAL EOC display (situation.php) markup code in jsdom.
//
// Used by tests/test_gh139_eoc_markups_single_draw.php. It loads the REAL
// assets/vendor/leaflet/leaflet.js and assets/js/map-prefs.js, then takes three
// pieces straight out of the REAL situation.php:
//   - the draw toolbar + markup panel markup,
//   - the initMap() block that attaches the per-category markup overlays to
//     the layer control (MapPrefs.addMarkupOverlays),
//   - the "Map Draw Controls & Markups" script (draw tools + markup panel),
// runs them against an in-memory api/map-overlay-categories.php +
// api/map-markups.php, clicks like a person, and prints one JSON object of
// named checks for the PHP test to assert.
//
// The bug it pins down: every saved markup was drawn TWICE on the EOC display,
// once by the layer control's category groups and once by the markup panel's
// own layer, so switching a category off in the layer control left a copy on
// the map that had to be switched off a second time in the panel.
//
// Node-side TEST code (.cjs, CommonJS), never served to a browser, so the
// "browser JavaScript is ES5" convention does not apply here.
//
// Usage: node _gh139_eoc_markups_harness.cjs <root>
'use strict';
const fs = require('fs');
const { JSDOM } = require('jsdom');

const root = process.argv[2];
const out = { checks: {}, notes: {}, errors: [] };
const C = out.checks;

function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

const page = fs.readFileSync(root + '/situation.php', 'utf8');
const leafletSrc = fs.readFileSync(root + '/assets/vendor/leaflet/leaflet.js', 'utf8');
const mapPrefsSrc = fs.readFileSync(root + '/assets/js/map-prefs.js', 'utf8');

function extract(re, what) {
    const m = page.match(re);
    if (!m) { throw new Error('could not find ' + what + ' in situation.php'); }
    return m[1] !== undefined ? m[1] : m[0];
}
// The toolbar + panel markup (any PHP around it is dropped).
const toolbarHtml = extract(/<!-- Draw Toolbar -->[\s\S]*?<div id="markupsList">[\s\S]*?<\/div>\s*<\/div>/, 'the draw toolbar / markup panel')
    .replace(/<\?php[\s\S]*?\?>/g, '');
// The initMap() statement that attaches the category overlays.
const attachJs = extract(/if \(window\.MapPrefs && typeof window\.MapPrefs\.addMarkupOverlays === 'function'[\s\S]*?_ticketsMarkupAdded = true;\s*\}/, 'the addMarkupOverlays call');
// The draw-tools / markup-panel script; its one PHP echo is the manage-map flag.
const drawJs = extract(/<!-- Map Draw Controls & Markups -->\s*<script>([\s\S]*?)<\/script>/, 'the draw-tools script')
    .replace(/<\?php[\s\S]*?\?>/g, 'true');

// ── In-memory API (shared across "page loads", like the real database) ──
const EVIL_NAME = 'GH139 Evil" onfocus="window.__xss=1" x="<img src=x onerror=window.__xss2=1>';
const db = {
    nextId: 10,
    categories: [
        { id: 1, name: 'Region Boundary', color: '#1976d2', default_visible: 1 },
        { id: 2, name: 'Parade Routes', color: '#ff9800', default_visible: 0 }
    ],
    markups: [
        { id: 1, line_name: 'GH139 Zone A', line_type: 'P', line_status: 1, line_cat_id: 1, category_id: 1, category_name: 'Region Boundary',
          line_data: JSON.stringify([[44.98, -93.28], [44.98, -93.24], [44.96, -93.24], [44.96, -93.28]]), line_color: '#0055FF',
          line_width: 2, line_opacity: 0.7, filled: 1, fill_color: '#0055FF', fill_opacity: 0.2, line_ident: '' },
        { id: 2, line_name: 'GH139 EOC marker', line_type: 'M', line_status: 1, line_cat_id: 0, category_id: null, category_name: null,
          line_data: JSON.stringify([[44.97, -93.26]]), line_color: '#FF0000', line_width: 2, line_opacity: 1, filled: 0,
          fill_color: '', fill_opacity: 0.2, line_ident: '' },
        // A name and colour a user with map rights could type, aimed at the
        // attributes the edit panel writes them into.
        { id: 9, line_name: EVIL_NAME, line_type: 'L', line_status: 1, line_cat_id: 0, category_id: null, category_name: null,
          line_data: JSON.stringify([[44.95, -93.30], [44.95, -93.20]]), line_color: 'red" onmouseover="window.__xss3=1',
          line_width: 2, line_opacity: 1, filled: 0, fill_color: '', fill_opacity: 0.2, line_ident: '' }
    ],
    posts: []
};

function reply(obj, status) {
    const st = status || 200;
    return Promise.resolve({ ok: st >= 200 && st < 300, status: st, json: function () { return Promise.resolve(JSON.parse(JSON.stringify(obj))); } });
}

function fakeFetch(url, opts) {
    const method = ((opts && opts.method) || 'GET').toUpperCase();
    const u = String(url);
    if (u.indexOf('api/map-overlay-categories.php') === 0) {
        return reply({ categories: db.categories });
    }
    if (u.indexOf('api/map-markups.php') === 0 && method === 'GET') {
        const q = u.match(/[?&]id=(\d+)/);
        if (q) {
            const one = db.markups.find(function (m) { return m.id === parseInt(q[1], 10); });
            return one ? reply({ markup: one }) : reply({ error: 'Markup not found' }, 404);
        }
        return reply({ markups: db.markups });
    }
    if (u.indexOf('api/map-markups.php') === 0 && method === 'POST') {
        const body = JSON.parse(opts.body || '{}');
        db.posts.push(body);
        if (body.action === 'save') {
            if (body.id) {
                const m = db.markups.find(function (x) { return x.id === parseInt(body.id, 10); });
                if (!m) return reply({ error: 'Markup not found' }, 404);
                if (body.name) m.line_name = body.name;
                return reply({ success: true, id: m.id });
            }
            const cat = parseInt(body.category_id || 0, 10);
            const row = { id: db.nextId++, line_name: body.name, line_type: body.type, line_status: body.visible === 0 ? 0 : 1,
                line_cat_id: cat, category_id: cat || null, category_name: null, line_data: body.coordinates,
                line_color: body.color, line_width: body.width, line_opacity: body.opacity, filled: body.filled,
                fill_color: body.fill_color, fill_opacity: body.fill_opacity, line_ident: body.ident || '' };
            db.markups.push(row);
            return reply({ success: true, id: row.id });
        }
        if (body.action === 'toggle_visibility') {
            const m = db.markups.find(function (x) { return x.id === parseInt(body.id, 10); });
            if (!m) return reply({ error: 'Markup not found' }, 404);
            m.line_status = (body.visible !== undefined) ? (parseInt(body.visible, 10) === 1 ? 1 : 0) : (m.line_status === 1 ? 0 : 1);
            return reply({ success: true, id: m.id, visible: m.line_status });
        }
        if (body.action === 'delete') {
            db.markups = db.markups.filter(function (x) { return x.id !== parseInt(body.id, 10); });
            return reply({ success: true });
        }
        return reply({ error: 'Unknown action' }, 400);
    }
    return reply({}, 404);
}

// ── One "page load" of the EOC display ──
async function loadPage(storedLayers) {
    const dom = new JSDOM('<!doctype html><html><head><meta name="csrf-token" content="tok139"></head><body>'
        + '<div id="sitMap" style="width:800px;height:600px"></div>' + toolbarHtml + '</body></html>',
        { runScripts: 'outside-only', url: 'http://127.0.0.1/situation.php', pretendToBeVisual: true });
    const w = dom.window;
    // jsdom has no SVG geometry; Leaflet only feature-detects it.
    w.SVGElement.prototype.createSVGRect = function () { return {}; };
    w.fetch = fakeFetch;
    w.prompt = function () { return w.__promptAnswer || null; };
    w.alert = function (t) { (w.__alerts = w.__alerts || []).push(String(t)); };
    w.confirm = function () { return true; };
    if (storedLayers) w.localStorage.setItem('newui_map_layers', storedLayers);
    w.addEventListener('error', function (e) { out.errors.push(String(e.message || e)); });

    w.eval(leafletSrc);
    w.eval(mapPrefsSrc);
    // What initMap() has built by the time it reaches the markup overlays.
    w.eval("var map = L.map('sitMap').setView([44.97, -93.26], 13); window._sitMap = map;"
         + "var sitLayersControl = L.control.layers({}, {}, { collapsed: true, position: 'topright' }).addTo(map);");
    w.eval(attachJs);
    w.eval(drawJs);
    await sleep(700); // initDraw runs after 300 ms; then the fetches settle
    return w;
}

// How many times a markup (by name) is drawn on the map right now.
function drawn(w, name) {
    let n = 0;
    w._sitMap.eachLayer(function (l) {
        if (!(l instanceof w.L.Marker) && !(l instanceof w.L.Path)) return;
        let txt = '';
        const tt = l.getTooltip && l.getTooltip();
        if (tt && typeof tt.getContent() === 'string') txt += tt.getContent();
        const pp = l.getPopup && l.getPopup();
        if (pp && typeof pp.getContent() === 'string') txt += ' ' + pp.getContent();
        if (txt.indexOf(name) >= 0) n++;
    });
    return n;
}
// The layer control rebuilds its list whenever a layer is added or removed by
// code, so look the checkbox up fresh every time rather than holding on to it.
function overlayInput(w, labelPart) {
    const labels = Array.prototype.slice.call(w.document.querySelectorAll('.leaflet-control-layers-overlays label'));
    const lab = labels.find(function (l) { return l.textContent.indexOf(labelPart) >= 0; });
    return lab ? lab.querySelector('input') : null;
}
function clickOverlay(w, labelPart) {
    const inp = overlayInput(w, labelPart);
    if (inp) inp.click();
    return !!inp;
}
function overlayOn(w, labelPart) {
    const inp = overlayInput(w, labelPart);
    return inp ? inp.checked === true : null;
}
function panelRow(w, namePart) {
    const rows = Array.prototype.slice.call(w.document.querySelectorAll('#markupsList .markup-item'));
    return rows.find(function (r) { return r.textContent.indexOf(namePart) >= 0; }) || null;
}

(async function main() {
    try {
        // ── Load 1: a user who has never chosen anything ──
        let w = await loadPage(null);
        C.zone_drawn_once = drawn(w, 'GH139 Zone A') === 1;
        C.eoc_marker_drawn_once = drawn(w, 'GH139 EOC marker') === 1;
        out.notes.load1 = { zone: drawn(w, 'GH139 Zone A'), marker: drawn(w, 'GH139 EOC marker') };
        C.layer_control_lists_category_and_uncategorised =
            !!overlayInput(w, 'Region Boundary') && !!overlayInput(w, 'Uncategorised markups');
        C.uncategorised_on_by_default_here = overlayOn(w, 'Uncategorised markups') === true;

        // Turning a category off in the layer control removes EVERY drawing of it.
        clickOverlay(w, 'Region Boundary');
        C.category_off_removes_it_from_map = drawn(w, 'GH139 Zone A') === 0;
        clickOverlay(w, 'Uncategorised markups');
        C.uncategorised_off_removes_it_from_map = drawn(w, 'GH139 EOC marker') === 0;
        out.notes.afterOff = { zone: drawn(w, 'GH139 Zone A'), marker: drawn(w, 'GH139 EOC marker') };
        // ...and back on draws it once, not twice.
        clickOverlay(w, 'Region Boundary');
        C.category_back_on_draws_once = drawn(w, 'GH139 Zone A') === 1;
        clickOverlay(w, 'Region Boundary'); // leave it off for the reload check
        const stored = w.localStorage.getItem('newui_map_layers');

        // The edit panel edits; it has no show/hide checkboxes of its own.
        w.document.getElementById('toggleMarkups').click();
        await sleep(200);
        const panel = w.document.getElementById('markupsPanel');
        C.panel_opens = panel.classList.contains('show');
        C.panel_has_no_visibility_checkboxes = panel.querySelectorAll('input[type="checkbox"]').length === 0;
        C.panel_lists_markups = !!panelRow(w, 'GH139 Zone A') && !!panelRow(w, 'GH139 EOC marker');
        // A markup name / colour containing quotes and tags stays text.
        const list = w.document.getElementById('markupsList');
        const evilRow = panelRow(w, 'GH139 Evil');
        const evilBtn = evilRow && evilRow.querySelector('[data-edit-markup]');
        const injected = list.querySelectorAll('img, [onfocus], [onmouseover], [onerror], [x]').length;
        C.panel_markup_name_and_colour_stay_text = !!evilRow && injected === 0
            && !!evilBtn && evilBtn.getAttribute('data-markup-name') === EVIL_NAME
            && w.__xss === undefined && w.__xss2 === undefined && w.__xss3 === undefined;
        out.notes.injected = injected;
        w.close();

        // ── Load 2: the same user reloads (a wall display does) ──
        w = await loadPage(stored);
        C.turned_off_category_stays_off_after_reload =
            overlayOn(w, 'Region Boundary') === false && drawn(w, 'GH139 Zone A') === 0;
        C.turned_off_uncategorised_stays_off_after_reload =
            overlayOn(w, 'Uncategorised markups') === false && drawn(w, 'GH139 EOC marker') === 0;
        out.notes.load2 = { zone: drawn(w, 'GH139 Zone A'), marker: drawn(w, 'GH139 EOC marker'), stored: stored };

        // Draw a new marker with the toolbar while "Uncategorised" is off: it is
        // saved, drawn exactly once, and its group is switched on so the user
        // sees what they drew.
        w.__promptAnswer = 'GH139 New marker';
        w.document.getElementById('drawMarker').click();
        w._sitMap.fire('click', { latlng: w.L.latLng(44.965, -93.25) });
        await sleep(400);
        const saved = db.posts.filter(function (p) { return p.action === 'save' && p.name === 'GH139 New marker'; });
        C.new_markup_saved = saved.length === 1;
        C.new_markup_drawn_once = drawn(w, 'GH139 New marker') === 1;
        C.new_markup_group_switched_on = overlayOn(w, 'Uncategorised markups') === true;
        out.notes.afterDraw = { newMarker: drawn(w, 'GH139 New marker') };

        // Hide-for-everyone (an edit of the saved markup) removes it from the map.
        clickOverlay(w, 'Region Boundary'); // the user turns the category back on
        C.category_on_again_draws_once = drawn(w, 'GH139 Zone A') === 1;
        w.document.getElementById('toggleMarkups').click();
        await sleep(200);
        let row = panelRow(w, 'GH139 Zone A');
        const visBtn = row && row.querySelector('[data-vis-markup]');
        C.panel_has_hide_for_everyone_button = !!visBtn;
        if (visBtn) { visBtn.click(); await sleep(300); }
        const visPost = db.posts.filter(function (p) { return p.action === 'toggle_visibility'; }).pop();
        C.hide_sends_explicit_visible_0 = !!visPost && visPost.visible === 0;
        C.hidden_markup_removed_from_map = drawn(w, 'GH139 Zone A') === 0;
        row = panelRow(w, 'GH139 Zone A');
        C.hidden_markup_marked_in_panel = !!row && row.textContent.indexOf('hidden for everyone') >= 0;
        const showBtn = row && row.querySelector('[data-vis-markup]');
        if (showBtn) { showBtn.click(); await sleep(300); }
        C.shown_again_drawn_once = drawn(w, 'GH139 Zone A') === 1;

        // Rename redraws in place (still once); delete removes it.
        w.__promptAnswer = 'GH139 Renamed marker';
        row = panelRow(w, 'GH139 New marker');
        const ed = row && row.querySelector('[data-edit-markup]');
        if (ed) { ed.click(); await sleep(400); }
        C.renamed_markup_drawn_once = drawn(w, 'GH139 Renamed marker') === 1 && drawn(w, 'GH139 New marker') === 0;
        row = panelRow(w, 'GH139 Renamed marker');
        const del = row && row.querySelector('[data-del-markup]');
        if (del) { del.click(); await sleep(400); }
        C.deleted_markup_removed_from_map = drawn(w, 'GH139 Renamed marker') === 0;
        C.zone_still_drawn_once_after_edits = drawn(w, 'GH139 Zone A') === 1;
        out.notes.end = { zone: drawn(w, 'GH139 Zone A'), marker: drawn(w, 'GH139 EOC marker') };
        w.close();
    } catch (e) {
        out.errors.push(String(e && e.stack ? e.stack : e));
    }
    process.stdout.write(JSON.stringify(out) + '\n');
})();
