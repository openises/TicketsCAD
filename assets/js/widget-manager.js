/**
 * NewUI v4.0 - Widget Manager
 *
 * Wraps GridStack to manage widget creation, layout save/restore,
 * and the widget toggle toolbar.
 */
var WidgetManager = (function () {
    var grid = null;
    var widgets = {};
    var saveTimer = null;
    var hiddenWidgets = [];

    // Undo stack — stores previous layout states (max 20)
    var undoStack = [];
    var MAX_UNDO = 20;

    // Default layout: 12 columns, 3-column arrangement
    // Left (0-5): Incidents / Responders / Facilities
    // Middle (6): Controls / Communications (single column)
    // Right (7-11): Statistics / Map / Recent Events
    var DEFAULT_LAYOUT = [
        { id: 'incidents',   x: 0, y: 0,  w: 6, h: 5,  minW: 3, minH: 3 },
        { id: 'responders',  x: 0, y: 5,  w: 6, h: 5,  minW: 3, minH: 3 },
        { id: 'facilities',  x: 0, y: 10, w: 6, h: 5,  minW: 3, minH: 3 },
        // Phase 99l (Eric beta 2026-06-29):
        //   - Controls / Communications: minH dropped from 3 to 1 so a
        //     dispatcher can pack the icon buttons into a 1-unit-tall
        //     strip. CSS below makes the icons grid-auto-fit so width
        //     scales cleanly from 1 unit (2 cols) to 4+ units (single
        //     row of 8).
        //   - Statistics: minH bumped from 1 to 2. At 1 unit the labels
        //     truncated and the numbers stacked unreadable; 2 units is
        //     the practical floor where every value is visible.
        { id: 'controls',    x: 6, y: 0,  w: 1, h: 7,  minW: 1, minH: 1 },
        { id: 'comms',       x: 6, y: 7,  w: 1, h: 8,  minW: 1, minH: 1 },
        { id: 'statistics',  x: 7, y: 0,  w: 5, h: 2,  minW: 3, minH: 2 },
        { id: 'map',         x: 7, y: 2,  w: 5, h: 8,  minW: 3, minH: 3 },
        { id: 'log',         x: 7, y: 10, w: 5, h: 5,  minW: 2, minH: 3 },
        { id: 'audit_log',    x: 7, y: 15, w: 4, h: 4,  minW: 2, minH: 3 },
        { id: 'time_entries', x: 0, y: 19, w: 4, h: 4,  minW: 2, minH: 3 },
    ];

    // Icon + English fallback per widget. The visible LABEL is routed through
    // the caption/translation table (dash.widget.* keys, seeded by
    // run_phase08_i18n.php) via window.DASH_WIDGET_TITLES, embedded by
    // index.php. This is what lets a Facility->Clinic rename reach the widget
    // card header (GH #70) — the header shares the SAME dash.widget.<id> key
    // the show/hide toggle button already uses, so both update together.
    var WIDGET_ICONS = {
        statistics:   'bi-graph-up',
        incidents:    'bi-exclamation-triangle',
        responders:   'bi-people',
        facilities:   'bi-building',
        controls:     'bi-sliders',
        comms:        'bi-headset',
        map:          'bi-geo-alt',
        log:          'bi-journal-text',
        audit_log:    'bi-shield-check',
        time_entries: 'bi-clock-history',
    };
    var WIDGET_LABELS_EN = {
        statistics:   'Statistics',
        incidents:    'Incidents',
        responders:   'Responders',
        facilities:   'Facilities',
        controls:     'Controls',
        comms:        'Communications',
        map:          'Map',
        log:          'Recent Events',
        audit_log:    'Recent activity',
        time_entries: 'My time',
    };

    // Build the header HTML (icon + translated label) for a widget id.
    function widgetTitleHtml(id) {
        var captions = window.DASH_WIDGET_TITLES || {};
        var label = captions[id] || WIDGET_LABELS_EN[id] || id;
        var icon = WIDGET_ICONS[id];
        // label may contain the admin's own text — escape it (defensive; the
        // caption table is admin-controlled but headers shouldn't inject HTML).
        var span = document.createElement('span');
        span.textContent = label;
        var safe = span.innerHTML;
        return (icon ? '<i class="bi ' + icon + ' me-1"></i>' : '') + safe;
    }

    function init(savedLayout, savedHidden) {
        hiddenWidgets = savedHidden || [];

        // Phase 72 — tell GridStack itself to collapse to a single
        // column at ≤768px viewport. This is the SUPPORTED way to do
        // mobile reflow (GridStack v9 columnOpts). CSS overrides
        // alone couldn't keep up because GridStack writes inline
        // styles AFTER any cascade settles — and the result was a
        // 12-column desktop layout shoved into a 320px viewport,
        // overflowing horizontally with no usable vertical scroll
        // and the map widget collapsed to ~26px wide.
        var gridConfig = {
            column: 12,
            cellHeight: 60,
            margin: 4,
            animate: true,
            float: false,
            removable: false,
            acceptWidgets: false,
            handle: '.card-header',
        };
        // GridStack v9 syntax — newer name. Older builds use
        // oneColumnSize/disableOneColumnMode. Set both so we work
        // across versions; the unrecognized one is ignored.
        gridConfig.columnOpts = {
            columnMax: 12,
            breakpoints: [{ w: 768, c: 1 }],
            layout: 'list',
        };
        gridConfig.disableOneColumnMode = false;
        gridConfig.oneColumnSize = 768;

        grid = GridStack.init(gridConfig, '#dashboard');

        var layout = savedLayout || DEFAULT_LAYOUT;

        layout.forEach(function (item) {
            if (hiddenWidgets.indexOf(item.id) === -1 && widgetAllowed(item.id)) {
                addWidget(item);
            }
        });

        // Save layout on change (debounced), push undo state first
        grid.on('change', function () {
            pushUndo();
            debouncedSave();
        });

        // Bind widget toggle buttons
        var toggles = document.querySelectorAll('.widget-toggle');
        toggles.forEach(function (btn) {
            var wid = btn.getAttribute('data-widget');
            if (hiddenWidgets.indexOf(wid) !== -1) {
                btn.classList.remove('active');
            }
            btn.addEventListener('click', function () {
                toggleWidget(wid, btn);
            });
        });
    }

    // RBAC widget enforcement (specs/rbac-enforcement-2026-06). ALLOWED_WIDGETS is
    // emitted by index.php from the user's widget.* permissions. Fail-open only when
    // the global is absent (non-dashboard contexts); when present it is authoritative,
    // so a stale saved layout or localStorage entry cannot resurrect a revoked widget.
    function widgetAllowed(id) {
        return typeof ALLOWED_WIDGETS === 'undefined' || ALLOWED_WIDGETS.indexOf(id) !== -1;
    }

    function addWidget(item) {
        // Never render a widget the user isn't permitted to see.
        if (!widgetAllowed(item.id)) {
            return;
        }
        var tpl = document.getElementById('tpl-' + item.id);
        // No template = not permitted (server omits gated templates) or unknown id —
        // skip entirely rather than rendering an empty card.
        if (!tpl) {
            return;
        }
        var content = tpl.innerHTML;

        var title = widgetTitleHtml(item.id);

        // Extra header buttons for specific widgets
        var extraBtns = '';
        if (item.id === 'map') {
            extraBtns = '<a href="situation.php" class="text-body-secondary me-2" style="cursor:pointer;text-decoration:none" title="Open full-screen Situation view">'
                + '<i class="bi bi-arrows-fullscreen"></i></a>';
        }
        if (item.id === 'incidents') {
            // 2026-06-11 — Inline "keep closed N min" control on the
            // widget header. Default 30 min. Saved per-user via the
            // 'dashboard' screen-prefs row, separate from situation's
            // value so users can keep different windows on each
            // surface. The data-service uses the value at fetch time.
            extraBtns = '<span class="d-flex align-items-center me-2" style="font-size:0.7rem;gap:2px;" title="Keep recently-closed incidents in the list for this many minutes (per-user, saved automatically)">'
                + '<span class="text-body-secondary">closed</span>'
                + '<input type="number" id="dashRecentCloseMins" min="0" max="10080" step="15"'
                + '       class="form-control form-control-sm py-0 px-1"'
                + '       style="width:50px;font-size:0.7rem;height:1.4rem;" value="30">'
                + '<span class="text-body-secondary">min</span>'
                + '</span>';
            // Eric 2026-07-07 (#67 icon overload): the Major Incidents
            // button lives here now — it is incident context, not a
            // top-level navbar destination.
            extraBtns += '<a class="btn btn-xs btn-outline-secondary me-2" href="major-incidents.php" '
                + 'title="Major incidents — link incidents under a command structure">'
                + '<i class="bi bi-diagram-3 me-1"></i><span class="action-label">Major</span></a>';
            extraBtns += '<span class="incident-action-bar d-none me-2 d-flex align-items-center gap-1" id="incidentActionBar">'
                + '<button class="btn btn-xs btn-outline-primary action-btn" data-action="dispatch"><i class="bi bi-send me-1"></i><span class="action-label">Dispatch</span><kbd>D</kbd></button>'
                + '<button class="btn btn-xs btn-outline-info action-btn" data-action="view"><i class="bi bi-eye me-1"></i><span class="action-label">View</span><kbd>V</kbd></button>'
                + '<button class="btn btn-xs btn-outline-secondary action-btn" data-action="edit"><i class="bi bi-pencil me-1"></i><span class="action-label">Edit</span><kbd>E</kbd></button>'
                + '<button class="btn btn-xs btn-outline-warning action-btn" data-action="popup"><i class="bi bi-box-arrow-up-right me-1"></i><span class="action-label">Popup</span><kbd>P</kbd></button>'
                + '<button class="btn btn-xs btn-outline-danger action-btn" data-action="close"><i class="bi bi-x-circle me-1"></i><span class="action-label">Close</span><kbd>X</kbd></button>'
                + '<button class="btn btn-xs btn-outline-success action-btn" data-action="units"><i class="bi bi-people me-1"></i><span class="action-label">Units</span><kbd>U</kbd></button>'
                + '</span>';
            // GH #63 (a beta tester) — column picker for this widget's table
            // (ScreenPrefs screen 'widget-incidents'); wired to the
            // shared editor modal at the bottom of addWidget().
            extraBtns += '<span class="text-body-secondary me-2" id="btnIncidentsWidgetCols" style="cursor:pointer" title="Customize columns">'
                + '<i class="bi bi-layout-three-columns"></i></span>';
        }
        // Phase 99n (Eric beta 2026-06-29) — Responders widget gets
        // its own hotkey action bar, mirroring the incident pattern.
        // Buttons:
        //   View (V)     — open unit-detail.php
        //   Edit (E)     — open unit-edit.php
        //   Dispatch (D) — open the unit detail "assign" tab to put
        //                  the unit on an incident
        //   Note (N)     — quick prompt -> action note on the unit's
        //                  currently-assigned incident (no incident
        //                  picker needed when exactly one assignment;
        //                  jumps to unit-detail when 0 or 2+)
        if (item.id === 'responders') {
            extraBtns = '<span class="responder-action-bar d-none me-2 d-flex align-items-center gap-1" id="responderActionBar">'
                + '<button class="btn btn-xs btn-outline-info responder-action-btn" data-resp-action="view"><i class="bi bi-eye me-1"></i><span class="action-label">View</span><kbd>V</kbd></button>'
                + '<button class="btn btn-xs btn-outline-secondary responder-action-btn" data-resp-action="edit"><i class="bi bi-pencil me-1"></i><span class="action-label">Edit</span><kbd>E</kbd></button>'
                + '<button class="btn btn-xs btn-outline-primary responder-action-btn" data-resp-action="dispatch"><i class="bi bi-send me-1"></i><span class="action-label">Dispatch</span><kbd>D</kbd></button>'
                + '<button class="btn btn-xs btn-outline-warning responder-action-btn" data-resp-action="status"><i class="bi bi-shuffle me-1"></i><span class="action-label">Status</span><kbd>S</kbd></button>'
                + '<button class="btn btn-xs btn-outline-success responder-action-btn" data-resp-action="note"><i class="bi bi-chat-left-text me-1"></i><span class="action-label">Note</span><kbd>N</kbd></button>'
                + '</span>';
            // GH #63 (a beta tester) — column picker for this widget's table
            // (ScreenPrefs screen 'widget-responders').
            extraBtns += '<span class="text-body-secondary me-2" id="btnRespondersWidgetCols" style="cursor:pointer" title="Customize columns">'
                + '<i class="bi bi-layout-three-columns"></i></span>';
        }
        // Phase 115 (Eric 2026-07-06) — Facilities widget quick-action bar,
        // same header pattern. Buttons:
        //   View (V)      — facility-detail.php
        //   Edit (E)      — facility-edit.php
        //   Incident@ (I) — new-incident.php?facility=<id> (incident AT here)
        //   Status (S)    — modal: pick a fac_status + optional note
        //   Note (N)      — modal: free-text note to the facility log
        //   Beds (B)      — modal: update bed counts (available/occupied) + note
        if (item.id === 'facilities') {
            extraBtns = '<span class="facility-action-bar d-none me-2 d-flex align-items-center gap-1" id="facilityActionBar">'
                + '<button class="btn btn-xs btn-outline-info facility-action-btn" data-facility-action="view"><i class="bi bi-eye me-1"></i><span class="action-label">View</span><kbd>V</kbd></button>'
                + '<button class="btn btn-xs btn-outline-secondary facility-action-btn" data-facility-action="edit"><i class="bi bi-pencil me-1"></i><span class="action-label">Edit</span><kbd>E</kbd></button>'
                + '<button class="btn btn-xs btn-outline-primary facility-action-btn" data-facility-action="incident"><i class="bi bi-exclamation-triangle me-1"></i><span class="action-label">Incident@</span><kbd>I</kbd></button>'
                + '<button class="btn btn-xs btn-outline-warning facility-action-btn" data-facility-action="status"><i class="bi bi-shuffle me-1"></i><span class="action-label">Status</span><kbd>S</kbd></button>'
                + '<button class="btn btn-xs btn-outline-success facility-action-btn" data-facility-action="note"><i class="bi bi-chat-left-text me-1"></i><span class="action-label">Note</span><kbd>N</kbd></button>'
                + '<button class="btn btn-xs btn-outline-danger facility-action-btn" data-facility-action="beds"><i class="bi bi-hospital me-1"></i><span class="action-label">Beds</span><kbd>B</kbd></button>'
                + '</span>';
            // GH #63 (a beta tester) — column picker for the Facilities widget table
            // (ScreenPrefs screen 'widget-facilities'), incl. the Beds column.
            extraBtns += '<span class="text-body-secondary me-2" id="btnFacilitiesWidgetCols" style="cursor:pointer" title="Customize columns">'
                + '<i class="bi bi-layout-three-columns"></i></span>';
        }

        // GH #65 (a beta tester 2026-07-07) — a quick text filter on the incident /
        // responder / facility widget headers. Types-to-filter the list
        // client-side; invaluable when a queue is huge (e.g. 680 responders).
        if (item.id === 'incidents' || item.id === 'responders' || item.id === 'facilities') {
            extraBtns = '<input type="search" class="form-control form-control-sm widget-filter me-2"'
                + ' data-filter-widget="' + item.id + '" placeholder="Filter…" autocomplete="off"'
                + ' style="height:1.5rem;font-size:0.72rem;max-width:8.5rem;"'
                + ' title="Type to filter this list">' + extraBtns;
        }

        var subtitleSpan = '';
        if (item.id === 'map') {
            subtitleSpan = '<span class="map-focus-label text-body-secondary ms-2 small d-none" id="mapFocusLabel"></span>';
        }

        var html = '<div class="grid-stack-item-content card">'
            + '<div class="card-header py-1 px-2 d-flex align-items-center justify-content-between">'
            + '<span class="small fw-semibold">' + title + subtitleSpan + '</span>'
            + '<span class="d-flex align-items-center gap-1">'
            + extraBtns
            + '<span class="widget-refresh text-body-secondary" data-widget="' + item.id + '" style="cursor:pointer" title="Refresh">'
            + '<i class="bi bi-arrow-clockwise"></i></span>'
            + '</span>'
            + '</div>'
            + '<div class="card-body p-1 overflow-auto">' + content + '</div>'
            + '</div>';

        // Always use current defaults for minW/minH (not stale saved values)
        var def = DEFAULT_LAYOUT.find(function (d) { return d.id === item.id; });
        var el = grid.addWidget({
            id: item.id,
            x: item.x,
            y: item.y,
            w: item.w,
            h: item.h,
            minW: def ? def.minW : (item.minW || 2),
            minH: def ? def.minH : (item.minH || 2),
            content: html,
        });

        widgets[item.id] = el;

        // Wire up refresh button
        var refreshBtn = el.querySelector('.widget-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                EventBus.emit('widget:refresh', { widget: item.id });
            });
        }

        // Wire up map reset view button
        var resetViewBtn = el.querySelector('.map-reset-view');
        if (resetViewBtn) {
            resetViewBtn.addEventListener('click', function () {
                EventBus.emit('map:resetView');
            });
        }

        // GH #63 (a beta tester) — per-user column show/hide on the Incidents
        // and Responders widget tables (ScreenPrefs; server catalog in
        // inc/screen-prefs.php). applyToTable() loads the saved prefs,
        // hides deselected columns via injected nth-child CSS (so the
        // app.js row renderers stay untouched), and binds the header
        // button to the shared editor modal. Runs here rather than
        // once at boot so a widget re-added via the visibility toggle
        // gets its freshly-created button rebound.
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            if (item.id === 'incidents') {
                window.ScreenPrefs.applyToTable('widget-incidents', '#incidentsWidgetTable', { openerSelector: '#btnIncidentsWidgetCols' });
            } else if (item.id === 'responders') {
                window.ScreenPrefs.applyToTable('widget-responders', '#respondersWidgetTable', { openerSelector: '#btnRespondersWidgetCols' });
            } else if (item.id === 'facilities') {
                window.ScreenPrefs.applyToTable('widget-facilities', '#facilitiesWidgetTable', { openerSelector: '#btnFacilitiesWidgetCols' });
            }
        }

        return el;
    }

    function toggleWidget(widgetId, btn) {
        pushUndo();
        var idx = hiddenWidgets.indexOf(widgetId);

        if (idx !== -1) {
            // Show widget
            hiddenWidgets.splice(idx, 1);
            btn.classList.add('active');
            var layoutItem = DEFAULT_LAYOUT.find(function (l) { return l.id === widgetId; });
            if (layoutItem) {
                addWidget(layoutItem);
            }
            // Re-render widget data
            EventBus.emit('widget:shown', { widget: widgetId });
        } else {
            // Hide widget
            hiddenWidgets.push(widgetId);
            btn.classList.remove('active');
            if (widgets[widgetId]) {
                grid.removeWidget(widgets[widgetId]);
                delete widgets[widgetId];
            }
        }

        debouncedSave();
    }

    function getLayout() {
        var items = grid.getGridItems();
        return items.map(function (el) {
            var node = el.gridstackNode;
            // Find defaults to preserve minW/minH
            var def = DEFAULT_LAYOUT.find(function (d) { return d.id === node.id; });
            return {
                id: node.id,
                x: node.x,
                y: node.y,
                w: node.w,
                h: node.h,
                minW: def ? def.minW : (node.minW || 2),
                minH: def ? def.minH : (node.minH || 2),
            };
        });
    }

    function pushUndo() {
        var state = {
            layout: getLayout(),
            hidden: hiddenWidgets.slice()
        };
        undoStack.push(JSON.stringify(state));
        if (undoStack.length > MAX_UNDO) undoStack.shift();
        EventBus.emit('layout:undoChanged', { canUndo: undoStack.length > 0 });
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function saveLayout() {
        var layout = getLayout();
        var csrf = getCsrfToken();
        fetch('api/layout.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({
                layout: layout,
                hidden: hiddenWidgets,
                csrf_token: csrf
            })
        }).catch(function (err) {
            console.error('Layout save error:', err);
        });

        // Also cache in localStorage for instant render on reload
        try {
            localStorage.setItem('newui_layout', JSON.stringify(layout));
            localStorage.setItem('newui_hidden', JSON.stringify(hiddenWidgets));
        } catch (e) {}
    }

    function debouncedSave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveLayout, 1000);
    }

    function getWidget(id) {
        return widgets[id] || null;
    }

    function getHiddenWidgets() {
        return hiddenWidgets;
    }

    function resetToDefaults() {
        // Notify that widgets are being destroyed (map needs to null its instance)
        EventBus.emit('widgets:destroying');

        // Remove all current widgets
        grid.removeAll();
        widgets = {};
        hiddenWidgets = [];

        // Re-add all default widgets
        DEFAULT_LAYOUT.forEach(function (item) {
            addWidget(item);
        });

        // Reset toggle buttons
        document.querySelectorAll('.widget-toggle').forEach(function (btn) {
            btn.classList.add('active');
        });

        // Clear stored layout and map layer preferences
        try {
            localStorage.removeItem('newui_layout');
            localStorage.removeItem('newui_hidden');
            localStorage.removeItem('newui_map_layers');
        } catch (e) {}

        // Save defaults to server
        saveLayout();

        // Re-render widgets with cached data (slight delay for DOM to settle)
        setTimeout(function () {
            DEFAULT_LAYOUT.forEach(function (item) {
                EventBus.emit('widget:shown', { widget: item.id });
            });
        }, 100);
    }

    function onResize(widgetId, callback) {
        grid.on('resizestop', function (event, el) {
            var node = el.gridstackNode;
            if (node && node.id === widgetId) {
                callback(el);
            }
        });
    }

    // ── Undo ────────────────────────────────────────────────────────────

    function undo() {
        if (undoStack.length === 0) return;
        var prev = JSON.parse(undoStack.pop());
        applyLayout(prev.layout, prev.hidden);
        saveLayout();
        EventBus.emit('layout:undoChanged', { canUndo: undoStack.length > 0 });
    }

    function canUndo() {
        return undoStack.length > 0;
    }

    function applyLayout(layout, hidden) {
        // Notify widgets being destroyed (map needs cleanup)
        EventBus.emit('widgets:destroying');

        grid.removeAll();
        widgets = {};
        hiddenWidgets = hidden || [];

        layout.forEach(function (item) {
            if (hiddenWidgets.indexOf(item.id) === -1) {
                addWidget(item);
            }
        });

        // Sync toggle buttons
        document.querySelectorAll('.widget-toggle').forEach(function (btn) {
            var wid = btn.getAttribute('data-widget');
            if (hiddenWidgets.indexOf(wid) !== -1) {
                btn.classList.remove('active');
            } else {
                btn.classList.add('active');
            }
        });

        // Re-render widgets
        setTimeout(function () {
            layout.forEach(function (item) {
                if (hiddenWidgets.indexOf(item.id) === -1) {
                    EventBus.emit('widget:shown', { widget: item.id });
                }
            });
        }, 100);
    }

    // ── Snapshots ──────────────────────────────────────────────────────

    function saveSnapshot(name) {
        var layout = getLayout();
        var csrf = getCsrfToken();
        return fetch('api/layout.php?action=save_snapshot', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({
                layout_name: name,
                layout: layout,
                hidden: hiddenWidgets,
                csrf_token: csrf
            })
        }).then(function (r) { return r.json(); });
    }

    function listSnapshots() {
        return fetch('api/layout.php?action=list_snapshots', {
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function loadSnapshot(name) {
        return fetch('api/layout.php?action=load_snapshot&name=' + encodeURIComponent(name), {
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (data.layout) {
                  pushUndo();
                  applyLayout(data.layout, data.hidden_widgets || []);
                  saveLayout();
              }
              return data;
          });
    }

    function deleteSnapshot(name) {
        var csrf = getCsrfToken();
        return fetch('api/layout.php?action=delete_snapshot&name=' + encodeURIComponent(name) + '&csrf_token=' + encodeURIComponent(csrf), {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf }
        }).then(function (r) { return r.json(); });
    }

    return {
        init: init,
        addWidget: addWidget,
        toggleWidget: toggleWidget,
        getLayout: getLayout,
        saveLayout: saveLayout,
        getWidget: getWidget,
        getHiddenWidgets: getHiddenWidgets,
        onResize: onResize,
        resetToDefaults: resetToDefaults,
        undo: undo,
        canUndo: canUndo,
        saveSnapshot: saveSnapshot,
        listSnapshots: listSnapshots,
        loadSnapshot: loadSnapshot,
        deleteSnapshot: deleteSnapshot,
    };
})();
