/**
 * NewUI v4.0 — Reusable per-screen column manager (Phase 17, 2026-06-11).
 *
 * Exposes window.ScreenPrefs with:
 *
 *   ScreenPrefs.load(screen).then(prefs => ...)
 *   ScreenPrefs.save(screen, prefs).then(...)
 *   ScreenPrefs.openEditor(screen, prefs, onSave)
 *     Renders an in-page modal listing every column with show/hide
 *     toggles and drag-to-reorder via the HTML5 drag API. onSave is
 *     called with the new prefs object after the user clicks Save.
 *
 * Pages provide:
 *   - a screen name (e.g., 'units')
 *   - a way to re-render after prefs change
 *   - HTML that knows how to honor `column.visible` + `column.pos`
 *
 * The modal is built on demand and shared across pages.
 */
(function () {
    'use strict';
    var Prefs = {};

    Prefs.load = function (screen) {
        return fetch('api/screen-prefs.php?screen=' + encodeURIComponent(screen),
                     { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { return (data && data.prefs) ? data.prefs : null; });
    };

    Prefs.save = function (screen, prefs) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var csrf = meta ? meta.getAttribute('content') : '';
        var body = {
            screen: screen,
            columns: prefs.columns,
            sort:    prefs.sort,
            // a beta tester beta 2026-06-29 — the server accepts an `options`
            // block (api/screen-prefs.php line 41) and uses it for
            // per-screen scalar prefs like recent_close_mins. Without
            // forwarding it here the save silently dropped the user's
            // "Keep closed N min" tweak — it always reset to 30 on
            // next load.
            options: prefs.options || {},
            csrf_token: csrf
        };
        return fetch('api/screen-prefs.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    };

    Prefs.reset = function (screen) {
        var meta = document.querySelector('meta[name="csrf-token"]');
        var csrf = meta ? meta.getAttribute('content') : '';
        return fetch('api/screen-prefs.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ screen: screen, reset: true, csrf_token: csrf })
        }).then(function (r) { return r.json(); });
    };

    Prefs.openEditor = function (screen, prefs, onSave) {
        var modalId = 'screenPrefsModal';
        var existing = document.getElementById(modalId);
        if (existing) existing.remove();

        var rows = '';
        for (var i = 0; i < prefs.columns.length; i++) {
            var c = prefs.columns[i];
            rows += '<li class="list-group-item d-flex align-items-center" draggable="true" data-col-id="' + c.id + '">' +
                '<i class="bi bi-grip-vertical text-body-secondary me-2" style="cursor:grab;"></i>' +
                '<div class="form-check form-switch flex-grow-1 mb-0">' +
                    '<input class="form-check-input" type="checkbox" id="sp-col-' + c.id + '"' +
                    (c.visible ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="sp-col-' + c.id + '">' + escHtml(c.label) + '</label>' +
                '</div>' +
                '</li>';
        }

        var html =
            '<div class="modal fade" id="' + modalId + '" tabindex="-1">' +
                '<div class="modal-dialog modal-dialog-scrollable">' +
                    '<div class="modal-content">' +
                        '<div class="modal-header py-2">' +
                            '<h6 class="modal-title">Customize columns</h6>' +
                            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
                        '</div>' +
                        '<div class="modal-body">' +
                            '<p class="text-body-secondary small mb-2">' +
                                'Toggle which columns appear. Drag the handles to reorder.' +
                            '</p>' +
                            '<ul class="list-group" id="spColList">' + rows + '</ul>' +
                        '</div>' +
                        '<div class="modal-footer py-2">' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" id="spResetBtn">' +
                                '<i class="bi bi-arrow-counterclockwise me-1"></i>Reset to defaults' +
                            '</button>' +
                            '<button type="button" class="btn btn-sm btn-success" id="spSaveBtn">' +
                                '<i class="bi bi-check-lg me-1"></i>Save' +
                            '</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.appendChild(wrap.firstChild);

        var modalEl = document.getElementById(modalId);
        var modal = new bootstrap.Modal(modalEl);

        // Drag + drop reordering
        var list = document.getElementById('spColList');
        var dragSrc = null;
        list.querySelectorAll('li').forEach(function (li) {
            li.addEventListener('dragstart', function (e) {
                dragSrc = li;
                li.style.opacity = '0.4';
                e.dataTransfer.effectAllowed = 'move';
            });
            li.addEventListener('dragend', function () { li.style.opacity = '1'; });
            li.addEventListener('dragover', function (e) { e.preventDefault(); });
            li.addEventListener('drop', function (e) {
                e.preventDefault();
                if (!dragSrc || dragSrc === li) return;
                var children = Array.prototype.slice.call(list.children);
                var srcIdx = children.indexOf(dragSrc);
                var tgtIdx = children.indexOf(li);
                if (srcIdx < tgtIdx) list.insertBefore(dragSrc, li.nextSibling);
                else                 list.insertBefore(dragSrc, li);
            });
        });

        document.getElementById('spSaveBtn').addEventListener('click', function () {
            var newCols = [];
            var pos = 0;
            list.querySelectorAll('li').forEach(function (li) {
                var id  = li.getAttribute('data-col-id');
                var cb  = li.querySelector('input[type="checkbox"]');
                var label = (prefs.columns.filter(function (c) { return c.id === id; })[0] || {}).label || id;
                newCols.push({
                    id: id,
                    label: label,
                    visible: cb.checked,
                    pos: pos++
                });
            });
            var nextPrefs = { columns: newCols, sort: prefs.sort };
            Prefs.save(screen, nextPrefs).then(function (resp) {
                if (resp && resp.ok) {
                    modal.hide();
                    if (onSave) onSave(resp.prefs || nextPrefs);
                }
            });
        });

        document.getElementById('spResetBtn').addEventListener('click', function () {
            if (!confirm('Reset column preferences for this screen to defaults?')) return;
            Prefs.reset(screen).then(function () {
                modal.hide();
                Prefs.load(screen).then(function (p) { if (onSave) onSave(p); });
            });
        });

        modal.show();
    };

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    /**
     * Phase 17 follow-on (2026-06-11) — generic table column-prefs helper.
     *
     * Apply column visibility prefs to a static HTML table whose
     * thead's <th> elements carry data-col-id="…" attributes. Cells in
     * the tbody are hidden by nth-child CSS rules, so the existing row
     * renderer doesn't have to know about this at all.
     *
     * Usage:
     *   ScreenPrefs.applyToTable('roster', '#rosterTable', { openerSelector: '#btnRosterCols' });
     *
     * - Discovers columns by reading the table's <th data-col-id>
     * - Loads saved prefs (falls back to the screen catalog defaults)
     * - Injects a <style id="sp-css-<screen>"> block that sets
     *   display:none on hidden columns
     * - Wires the opener button to ScreenPrefs.openEditor()
     * - Re-applies CSS after a save
     *
     * Reordering is NOT supported via this path — table cells can't
     * reorder via CSS the way flex children can. Show/hide is the
     * 80% case for now; reorder is captured as future polish.
     */
    Prefs.applyToTable = function (screen, tableSelector, opts) {
        opts = opts || {};
        var table = document.querySelector(tableSelector);
        if (!table) return;
        var thRows = table.querySelectorAll('thead th[data-col-id]');
        if (!thRows.length) return;

        // Build the column catalog from the live thead so server-side
        // defaults stay in sync with what the page actually exposes.
        var liveCols = [];
        for (var i = 0; i < thRows.length; i++) {
            var th = thRows[i];
            liveCols.push({
                id: th.getAttribute('data-col-id'),
                label: (th.getAttribute('data-col-label') || th.textContent || '').trim().replace(/\s+/g, ' '),
                position: i, // index within thead
            });
        }

        function applyCss(visibleSet) {
            var styleId = 'sp-css-' + screen.replace(/[^a-z0-9_-]/gi, '');
            var existing = document.getElementById(styleId);
            if (existing) existing.remove();
            var rules = [];
            for (var i = 0; i < liveCols.length; i++) {
                if (!visibleSet[liveCols[i].id]) {
                    // nth-child is 1-based. GH #63 — exempt [colspan]
                    // cells: full-width placeholder rows ("Loading…",
                    // "No incidents") span every column with a single
                    // td, and hiding it because column 1 is hidden
                    // made the whole message disappear.
                    var nth = liveCols[i].position + 1;
                    rules.push(tableSelector + ' thead th:nth-child(' + nth + ')' +
                              ', ' + tableSelector + ' tbody td:nth-child(' + nth + '):not([colspan])' +
                              '{display:none !important;}');
                }
            }
            var style = document.createElement('style');
            style.id = styleId;
            style.textContent = rules.join('\n');
            document.head.appendChild(style);
        }

        function refresh() {
            Prefs.load(screen).then(function (prefs) {
                // Merge server prefs (which include catalogue label/pos)
                // with the live cols discovered above. Prefer the live
                // ID list — never hide a column the server hasn't
                // heard of.
                var serverById = {};
                if (prefs && prefs.columns) {
                    for (var i = 0; i < prefs.columns.length; i++) {
                        serverById[prefs.columns[i].id] = prefs.columns[i];
                    }
                }
                var merged = liveCols.map(function (c, idx) {
                    var s = serverById[c.id];
                    return {
                        id: c.id,
                        label: c.label,
                        // If the server has no opinion, default to visible.
                        visible: s ? !!s.visible : true,
                        pos: idx,
                    };
                });
                var vSet = {};
                merged.forEach(function (c) { if (c.visible) vSet[c.id] = true; });
                applyCss(vSet);

                // Wire opener button if provided
                if (opts.openerSelector) {
                    var btn = document.querySelector(opts.openerSelector);
                    if (btn && !btn._spBound) {
                        btn._spBound = true;
                        btn.addEventListener('click', function () {
                            // Build a prefs-like object the editor expects
                            var editorPrefs = {
                                columns: merged,
                                sort: (prefs && prefs.sort) || { col: '', dir: 'asc' },
                            };
                            Prefs.openEditor(screen, editorPrefs, function (newPrefs) {
                                refresh();
                                if (opts.onChange) opts.onChange(newPrefs);
                            });
                        });
                    }
                }
            });
        }

        refresh();
        return refresh;
    };

    window.ScreenPrefs = Prefs;
})();
