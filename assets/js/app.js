/**
 * NewUI v4.0 - Application Entry Point
 *
 * Initializes EventBus, DataService, ThemeManager, WidgetManager.
 * Wires up data flow from API responses to widget rendering.
 */
(function () {
    'use strict';

    var map = null;
    var mapInitialFitDone = false;
    var markers = { incidents: [], responders: [], facilities: [] };
    var layerGroups = { incidents: null, responders: null, facilities: null, markups: null, roadConditions: null };
    var selectedMarkerRing = null;
    var selectedId = null;
    var selectedType = null;
    var lastBounds = null;
    var searchMarker = null;
    var mapConfig = { def_lat: 39.8283, def_lng: -98.5795, def_zoom: 5, owm_api: '', area_title: '' };

    // ── Widget Sort State ────────────────────────────────────────────
    var widgetData = { incidents: [], responders: [], facilities: [], log: [] };
    var widgetSort = {};  // keyed by tbody ID: { field, dir, type }

    function sortWidgetData(tbodyId, dataKey, renderFn) {
        var table = document.getElementById(tbodyId);
        if (!table) return;
        var thead = table.closest('table').querySelector('thead');
        if (!thead) return;

        thead.querySelectorAll('th.sortable').forEach(function (th) {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                var field = th.getAttribute('data-sort');
                var dtype = th.getAttribute('data-type') || 'string';
                var state = widgetSort[tbodyId] || {};

                // Toggle direction
                var dir = (state.field === field && state.dir === 'asc') ? 'desc' : 'asc';
                widgetSort[tbodyId] = { field: field, dir: dir, type: dtype };

                // Update active header styling
                thead.querySelectorAll('th.sortable').forEach(function (h) {
                    h.classList.remove('sort-active', 'sort-asc', 'sort-desc');
                });
                th.classList.add('sort-active', dir === 'asc' ? 'sort-asc' : 'sort-desc');

                // Sort and re-render
                applySortAndRender(dataKey, renderFn);
            });
        });
    }

    function applySortAndRender(dataKey, renderFn) {
        var tbodyId = dataKey === 'incidents' ? 'incidentsBody'
                    : dataKey === 'responders' ? 'respondersBody'
                    : dataKey === 'facilities' ? 'facilitiesBody'
                    : 'logBody';
        var state = widgetSort[tbodyId];
        if (!state || !widgetData[dataKey]) return;

        var sorted = widgetData[dataKey].slice();
        var field = state.field;
        var dir = state.dir;
        var dtype = state.type;

        sorted.sort(function (a, b) {
            var va = a[field];
            var vb = b[field];

            // Handle location composite field for incidents
            if (field === 'location') {
                va = ((a.street || '') + ' ' + (a.city || '')).trim();
                vb = ((b.street || '') + ' ' + (b.city || '')).trim();
            }

            if (va == null) va = '';
            if (vb == null) vb = '';

            if (dtype === 'number') {
                va = parseFloat(va) || 0;
                vb = parseFloat(vb) || 0;
            } else if (dtype === 'date') {
                va = new Date(va).getTime() || 0;
                vb = new Date(vb).getTime() || 0;
            } else {
                va = String(va).toLowerCase();
                vb = String(vb).toLowerCase();
            }

            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });

        // Build wrapper object matching what render functions expect
        var wrapper = {};
        wrapper[dataKey] = sorted;
        renderFn(wrapper);
    }

    // ── Bootstrap ──────────────────────────────────────────────────────

    function boot() {
        ThemeManager.init();

        // 2026-06-11 — load this user's dashboard screen-prefs so the
        // Incidents widget's recent-closed window is honored from the
        // very first fetch. Stash on a global the data-service reads.
        if (window.ScreenPrefs) {
            window.ScreenPrefs.load('dashboard').then(function (p) {
                window.DashboardPrefs = {
                    recent_close_mins: (p && p.options && p.options.recent_close_mins)
                                       ? parseInt(p.options.recent_close_mins, 10) : 30
                };
            }).catch(function () { window.DashboardPrefs = { recent_close_mins: 30 }; });
        } else {
            window.DashboardPrefs = { recent_close_mins: 30 };
        }

        // Try localStorage first for instant render, then fetch from server
        var savedLayout = null;
        var savedHidden = [];
        try {
            var l = localStorage.getItem('newui_layout');
            var h = localStorage.getItem('newui_hidden');
            if (l) savedLayout = JSON.parse(l);
            if (h) savedHidden = JSON.parse(h);
        } catch (e) {}

        // Also fetch from server (authoritative)
        DataService.fetchJSON('api/layout.php')
            .then(function (data) {
                var layout = data.layout || savedLayout;
                var hidden = data.hidden_widgets || savedHidden;
                WidgetManager.init(layout, hidden);
                startDataFlow();
                bindDashboardRecentClose();  // 2026-06-11
            })
            .catch(function () {
                WidgetManager.init(savedLayout, savedHidden);
                startDataFlow();
                bindDashboardRecentClose();
            });
    }

    // 2026-06-11 — wire the inline "keep closed N min" input on the
    // Incidents widget header. Debounced save (600ms) → persists to
    // screen-prefs and triggers an immediate widget refresh.
    //
    // 2026-06-30 (Eric/a beta tester beta) — race-condition fix: this used to
    // set input.value once from the global DashboardPrefs. But boot()
    // fires ScreenPrefs.load and api/layout.php in parallel, and this
    // function runs when layout resolves. If the prefs fetch is still
    // in flight, DashboardPrefs is undefined and the input reverts to
    // 30 — the user's saved value silently lost on every page load.
    // Fix: re-load prefs here and update the input AFTER the fetch
    // resolves. The browser caches the GET so no extra network cost.
    function bindDashboardRecentClose() {
        var input = document.getElementById('dashRecentCloseMins');
        if (!input) return;
        // Set whatever we have now — fallback to 30 if prefs not loaded yet.
        input.value = (window.DashboardPrefs && window.DashboardPrefs.recent_close_mins) || 30;
        // Re-fetch and apply the authoritative saved value once it lands.
        // ScreenPrefs.load also primes window.DashboardPrefs for other readers.
        if (window.ScreenPrefs) {
            window.ScreenPrefs.load('dashboard').then(function (p) {
                var saved = p && p.options && p.options.recent_close_mins;
                if (saved !== undefined && saved !== null && saved !== '') {
                    var v = parseInt(saved, 10);
                    if (!isNaN(v) && v >= 0) {
                        window.DashboardPrefs = window.DashboardPrefs || {};
                        window.DashboardPrefs.recent_close_mins = v;
                        // Don't clobber if the user already started typing.
                        if (document.activeElement !== input) input.value = v;
                    }
                }
            }).catch(function () { /* keep default */ });
        }
        var saveTimer = null;
        input.addEventListener('input', function () {
            var v = parseInt(input.value, 10);
            if (isNaN(v) || v < 0) return;
            window.DashboardPrefs = { recent_close_mins: v };
            if (saveTimer) clearTimeout(saveTimer);
            saveTimer = setTimeout(function () {
                if (window.ScreenPrefs) {
                    window.ScreenPrefs.save('dashboard', {
                        columns: [],
                        sort: { col: '', dir: 'asc' },
                        options: { recent_close_mins: v }
                    });
                }
                // Trigger an immediate refresh of just the incidents widget.
                DataService.fetchAll(['incidents']).then(function (data) {
                    if (data.incidents) renderIncidents(data.incidents);
                }).catch(function () {});
            }, 600);
        });
    }

    function startDataFlow() {
        // Fetch map config (OWM key, default coords, area title)
        DataService.fetchJSON('api/map-config.php')
            .then(function (cfg) {
                if (cfg) {
                    mapConfig = {
                        def_lat: cfg.def_lat || 39.8283,
                        def_lng: cfg.def_lng || -98.5795,
                        def_zoom: cfg.def_zoom || 5,
                        owm_api: cfg.owm_api || '',
                        area_title: cfg.area_title || ''
                    };
                }
            })
            .catch(function () {});

        var activeWidgets = ['incidents', 'responders', 'facilities', 'log', 'statistics', 'scheduled'];
        DataService.startPolling(activeWidgets, renderAll);

        // Wire up inter-widget events
        EventBus.on('incident:selected', onIncidentSelected);
        EventBus.on('responder:selected', onResponderSelected);
        EventBus.on('facility:selected', onFacilitySelected);
        EventBus.on('selection:cleared', onSelectionCleared);
        EventBus.on('widget:shown', onWidgetShown);
        EventBus.on('widget:refresh', onWidgetRefresh);
        EventBus.on('map:resetView', resetMapView);

        // ── SSE real-time event → widget refresh mapping ──
        EventBus.on('incident:new', function () {
            onWidgetRefresh({ widget: 'incidents' });
            onWidgetRefresh({ widget: 'stats' });
            onWidgetRefresh({ widget: 'log' });
        });
        EventBus.on('incident:update', function () {
            onWidgetRefresh({ widget: 'incidents' });
            onWidgetRefresh({ widget: 'log' });
        });
        EventBus.on('incident:close', function () {
            onWidgetRefresh({ widget: 'incidents' });
            onWidgetRefresh({ widget: 'stats' });
            onWidgetRefresh({ widget: 'log' });
        });
        EventBus.on('incident:note', function () {
            onWidgetRefresh({ widget: 'log' });
        });
        EventBus.on('responder:status', function () {
            onWidgetRefresh({ widget: 'responders' });
        });
        EventBus.on('responder:assign', function () {
            onWidgetRefresh({ widget: 'responders' });
            onWidgetRefresh({ widget: 'incidents' });
        });
        EventBus.on('facility:update', function () {
            onWidgetRefresh({ widget: 'facilities' });
        });
        EventBus.on('system:refresh', function () {
            onWidgetRefresh({ widget: 'incidents' });
            onWidgetRefresh({ widget: 'responders' });
            onWidgetRefresh({ widget: 'facilities' });
            onWidgetRefresh({ widget: 'stats' });
            onWidgetRefresh({ widget: 'log' });
        });

        // ── SSE connection status indicator ──
        EventBus.on('sse:connected', function () {
            var ind = document.getElementById('sseIndicator');
            if (ind) {
                ind.title = 'Real-time updates: connected';
                ind.innerHTML = '<i class="bi bi-circle-fill" style="font-size:0.5rem;color:var(--bs-success)"></i>';
            }
        });
        EventBus.on('sse:disconnected', function () {
            var ind = document.getElementById('sseIndicator');
            if (ind) {
                ind.title = 'Real-time updates: reconnecting...';
                ind.innerHTML = '<i class="bi bi-circle-fill" style="font-size:0.5rem;color:var(--bs-warning)"></i>';
            }
        });
        EventBus.on('sse:offline', function () {
            var ind = document.getElementById('sseIndicator');
            if (ind) {
                ind.title = 'Real-time updates: using polling (SSE unavailable)';
                ind.innerHTML = '<i class="bi bi-circle-fill" style="font-size:0.5rem;color:var(--bs-secondary)"></i>';
            }
        });

        EventBus.on('widgets:destroying', function () {
            selectedMarkerRing = null;
            selectedId = null;
            selectedType = null;
            lastBounds = null;
            searchMarker = null;
            layerGroups = { incidents: null, responders: null, facilities: null, markups: null, roadConditions: null };
            window._roadConditionsLoaded = false;
            if (map) {
                try { map.remove(); } catch (e) {}
                map = null;
                mapInitialFitDone = false;
            }
        });

        // ── Action bar button clicks (event delegation) ──
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.action-btn[data-action]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                executeAction(btn.dataset.action);
                return;
            }
            // Phase 99n: responder action bar clicks
            var rBtn = e.target.closest('.responder-action-btn[data-resp-action]');
            if (rBtn) {
                e.preventDefault();
                e.stopPropagation();
                executeResponderAction(rBtn.dataset.respAction);
                return;
            }
            // Phase 115: facility action bar clicks
            var fBtn = e.target.closest('.facility-action-btn[data-facility-action]');
            if (fBtn) {
                e.preventDefault();
                e.stopPropagation();
                executeFacilityAction(fBtn.dataset.facilityAction);
            }
        });

        // GH #65: quick text-filter inputs on the incident/responder/facility
        // widget headers. Delegated so it survives widget re-creation.
        document.addEventListener('input', function (e) {
            var fi = e.target.closest && e.target.closest('.widget-filter[data-filter-widget]');
            if (!fi) return;
            var widget = fi.getAttribute('data-filter-widget');
            _widgetFilters[widget] = fi.value || '';
            applyWidgetFilter(widget);
        });

        // ── Comms/control panel button clicks (event delegation) ──
        //
        // Issue #51 (2026-07-03): the Controls widget shipped with 7
        // placeholder buttons whose click paths went nowhere. Wired the
        // navigational ones (Units, Facilities, Mail, Print, Settings)
        // to the pages they always implied. RBAC visibility now happens
        // in the PHP template (tpl-controls) so lower-privilege roles
        // see a clean widget instead of dead buttons.
        //
        // Assigned emits an EventBus event so the incidents widget can
        // subscribe when its filter integration lands; until then the
        // button toggles its own `active` class as a visual affordance
        // and any consumer of the event can act.
        document.addEventListener('click', function (e) {
            var ctrlBtn = e.target.closest('.ctrl-btn[data-action]');
            if (ctrlBtn) {
                var action = ctrlBtn.getAttribute('data-action');
                if (action === 'zello') {
                    e.preventDefault();
                    EventBus.emit('zello:toggle');
                }
                if (action === 'chat') {
                    e.preventDefault();
                    EventBus.emit('chat:toggle');
                }
                if (action === 'radio') {
                    e.preventDefault();
                    EventBus.emit('radio:toggle');
                }
                if (action === 'road-conditions') {
                    e.preventDefault();
                    toggleRoadConditions();
                }
                if (action === 'show-assigned') {
                    e.preventDefault();
                    ctrlBtn.classList.toggle('active');
                    var on = ctrlBtn.classList.contains('active');
                    EventBus.emit('incidents:filter-assigned', { active: on });
                }
                if (action === 'open-units') {
                    e.preventDefault();
                    window.location.href = 'units.php';
                }
                if (action === 'open-facilities') {
                    e.preventDefault();
                    window.location.href = 'facilities.php';
                }
                if (action === 'mail') {
                    e.preventDefault();
                    window.location.href = 'messaging.php';
                }
                if (action === 'print') {
                    e.preventDefault();
                    window.print();
                }
                if (action === 'settings') {
                    e.preventDefault();
                    window.location.href = 'settings.php';
                }
                // Other comms actions (sms, alerts) will be wired as built
            }
        });

        // ── Right-click Roads → open the road-conditions manager ──
        // Issue #51: primary click toggles the overlay (dispatchers hit
        // that mid-event); right-click / long-press opens
        // settings.php#road-conditions for CRUD on the entries. Two paths,
        // one button, discriminated by input mode.
        document.addEventListener('contextmenu', function (e) {
            var ctrlBtn = e.target.closest('.ctrl-btn[data-action="road-conditions"]');
            if (ctrlBtn) {
                e.preventDefault();
                window.location.href = 'settings.php#road-conditions';
            }
        });

        // ── Zello unread badge ──
        EventBus.on('zello:unread', function (data) {
            var count = data.count || 0;
            var btns = document.querySelectorAll('.ctrl-btn[data-action="zello"]');
            for (var i = 0; i < btns.length; i++) {
                var existing = btns[i].querySelector('.badge');
                if (count > 0) {
                    if (!existing) {
                        existing = document.createElement('span');
                        existing.className = 'badge bg-danger rounded-pill ms-1';
                        existing.style.fontSize = '0.6rem';
                        existing.style.position = 'absolute';
                        existing.style.top = '-4px';
                        existing.style.right = '-4px';
                        btns[i].style.position = 'relative';
                        btns[i].appendChild(existing);
                    }
                    existing.textContent = count > 99 ? '99+' : count;
                } else {
                    if (existing) existing.remove();
                }
            }
        });

        // Reset layout button
        var resetBtn = document.getElementById('resetLayout');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                WidgetManager.resetToDefaults();
            });
        }

        // ── Undo button ──
        var undoBtn = document.getElementById('undoLayout');
        if (undoBtn) {
            undoBtn.addEventListener('click', function () {
                WidgetManager.undo();
            });
        }
        EventBus.on('layout:undoChanged', function (data) {
            if (undoBtn) undoBtn.disabled = !data.canUndo;
        });

        // ── Snapshot controls ──
        var saveSnapBtn = document.getElementById('saveSnapshot');
        var snapNameInput = document.getElementById('snapshotName');
        var snapshotList = document.getElementById('snapshotList');
        var snapshotMenu = document.getElementById('snapshotMenu');

        if (saveSnapBtn && snapNameInput) {
            saveSnapBtn.addEventListener('click', function () {
                var name = snapNameInput.value.trim();
                if (!name) return;
                WidgetManager.saveSnapshot(name).then(function (result) {
                    if (result && result.saved) {
                        snapNameInput.value = '';
                        refreshSnapshotList();
                    }
                });
            });
            snapNameInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveSnapBtn.click();
                }
                // Stop GridStack from capturing keys
                e.stopPropagation();
            });
        }

        // Refresh snapshot list when dropdown opens
        if (snapshotMenu) {
            snapshotMenu.addEventListener('show.bs.dropdown', refreshSnapshotList);
        }

        function refreshSnapshotList() {
            if (!snapshotList) return;
            WidgetManager.listSnapshots().then(function (data) {
                var snaps = data.snapshots || [];
                if (snaps.length === 0) {
                    snapshotList.innerHTML = '<span class="dropdown-item-text text-body-secondary small">No saved snapshots</span>';
                    return;
                }
                snapshotList.innerHTML = snaps.map(function (s) {
                    return '<div class="d-flex align-items-center px-2 py-1 snapshot-item">'
                        + '<a href="#" class="dropdown-item py-1 px-1 flex-grow-1 snapshot-load" data-name="' + esc(s.layout_name) + '">'
                        + '<i class="bi bi-bookmark-fill me-1"></i>' + esc(s.layout_name)
                        + '<small class="text-body-secondary ms-2">' + formatSnapshotDate(s.updated_at) + '</small>'
                        + '</a>'
                        + '<button class="btn btn-sm btn-link text-danger p-0 ms-1 snapshot-delete" data-name="' + esc(s.layout_name) + '" title="Delete">'
                        + '<i class="bi bi-trash3"></i>'
                        + '</button>'
                        + '</div>';
                }).join('');

                // Wire load clicks
                snapshotList.querySelectorAll('.snapshot-load').forEach(function (link) {
                    link.addEventListener('click', function (e) {
                        e.preventDefault();
                        WidgetManager.loadSnapshot(link.dataset.name);
                    });
                });

                // Wire delete clicks
                snapshotList.querySelectorAll('.snapshot-delete').forEach(function (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        WidgetManager.deleteSnapshot(btn.dataset.name).then(refreshSnapshotList);
                    });
                });
            });
        }

        function formatSnapshotDate(dateStr) {
            if (!dateStr) return '';
            try {
                var d = new Date(dateStr);
                return (d.getMonth() + 1) + '/' + d.getDate() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ''; }
        }

        // Main menu actions
        document.querySelectorAll('.nav-menu-btn[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var action = btn.getAttribute('data-action');
                if (action === 'fullscreen') {
                    if (!document.fullscreenElement) {
                        document.documentElement.requestFullscreen();
                    } else {
                        document.exitFullscreen();
                    }
                }
                // Other menu actions will be wired up as pages are built
            });
        });

        // Map resize handling
        WidgetManager.onResize('map', function () {
            if (map) {
                setTimeout(function () { map.invalidateSize(); }, 100);
            }
        });
    }

    // ── Render Functions ───────────────────────────────────────────────

    function renderAll(data) {
        if (data.statistics) renderStatistics(data.statistics);
        if (data.incidents) {
            widgetData.incidents = data.incidents.incidents || [];
            renderIncidents(data.incidents);
        }
        if (data.responders) {
            widgetData.responders = data.responders.responders || [];
            renderResponders(data.responders);
        }
        if (data.facilities) {
            widgetData.facilities = data.facilities.facilities || [];
            renderFacilities(data.facilities);
        }
        if (data.log) {
            // QA #5 — api/log.php emits the array under `entries`, not `log`.
            // Reading data.log.log left widgetData.log always [], so clicking a
            // Recent Events column header sorted [] and blanked the table until
            // the next poll. Stash the real array so header-sort works.
            widgetData.log = data.log.entries || [];
            renderLog(data.log);
        }
        if (data.incidents || data.responders || data.facilities) {
            renderMap(data);
        }
    }

    function renderStatistics(data) {
        var el = document.querySelector('[data-stat]');
        if (!el) return;

        var stats = {
            open_tickets: data.open_tickets,
            unassigned: data.unassigned,
            on_scene: data.on_scene,
            available_responders: data.available_responders,
            dispatched_not_responding: data.dispatched_not_responding,
            responding_not_on_scene: data.responding_not_on_scene,
            closed_today: data.closed_today,
            avg_to_dispatch: data.avg_to_dispatch_secs ? formatSeconds(data.avg_to_dispatch_secs) : '-',
        };

        Object.keys(stats).forEach(function (key) {
            var el = document.querySelector('[data-stat="' + key + '"]');
            if (el) el.textContent = stats[key];
        });
    }

    // GH #65 (a beta tester 2026-07-07) — per-widget quick text filter. The render
    // functions rebuild tbody.innerHTML on every SSE/poll refresh, so the
    // filter is stored here and re-applied after each render (a raw DOM
    // hide would be wiped by the next render).
    var _widgetFilters = { incidents: '', responders: '', facilities: '' };
    var _widgetBody = { incidents: 'incidentsBody', responders: 'respondersBody', facilities: 'facilitiesBody' };

    function applyWidgetFilter(widget) {
        var bodyId = _widgetBody[widget];
        if (!bodyId) return;
        var tbody = document.getElementById(bodyId);
        if (!tbody) return;
        var q = (_widgetFilters[widget] || '').toLowerCase();
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            if (!q) { rows[i].style.display = ''; continue; }
            rows[i].style.display = (rows[i].textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
        }
    }

    function renderIncidents(data) {
        var tbody = document.getElementById('incidentsBody');
        if (!tbody) return;
        if (!tbody._sortInit) {
            sortWidgetData('incidentsBody', 'incidents', renderIncidents);
            tbody._sortInit = true;
        }

        // 2026-06-11 — sort closed rows to the bottom so active items
        // land at the top regardless of how generous the user's
        // recent-closed window is.
        var sortedIncidents = (data.incidents || []).slice().sort(function (a, b) {
            var aClosed = (a.status === 1) ? 1 : 0;
            var bClosed = (b.status === 1) ? 1 : 0;
            return aClosed - bClosed;
        });

        var rows = sortedIncidents.map(function (inc) {
            var sevStyle = 'background-color:' + inc.severity_color + ';color:#000;padding:2px 6px;border-radius:3px;';
            var pts = inc.patients_count || 0;
            var act = inc.actions_count || 0;
            // Dim closed rows visually so active draws the eye first.
            var rowStyle = (inc.status === 1) ? ' style="opacity:0.55;font-style:italic;"' : '';
            // Phase 99o-v2 (Eric beta 2026-06-29): internal ID column
            // removed — dispatchers don't care about the internal id.
            // The case-number cell is now the link to the detail page,
            // and falls back to "#<id>" only for legacy tickets that
            // pre-date the numbering feature (so the link is always
            // clickable). Internal id stays on the row's data-id
            // attribute so click handlers (selection, map highlight)
            // keep working.
            var linkText = inc.incident_number || ('#' + inc.id);
            // Issue #17 re-reopen (a beta tester 2026-07-04) — the PAR-overdue
            // clock icon only existed on situation.php's incident table.
            // api/incidents.php delivers par_overdue_secs to every
            // consumer (a beta tester's captured payload proved it: 109 secs
            // overdue, no icon anywhere he was looking). Render it on
            // the dashboard incidents widget too, same amber/red ramp.
            var parIcon = '';
            var parOD = parseInt(inc.par_overdue_secs || 0, 10);
            if (parOD > 0) {
                var parColor = parOD >= 300 ? 'text-danger' : 'text-warning';
                var parMin = Math.floor(parOD / 60);
                var parLabel = parMin >= 1 ? (parMin + ' min overdue') : (parOD + ' sec overdue');
                parIcon = ' <i class="bi bi-clock-history ' + parColor
                        + '" title="PAR check ' + parLabel + '" style="font-size:0.85rem;"></i>';
            }
            var caseCell = '<a href="incident-detail.php?id=' + inc.id + '" class="incident-link font-monospace small" title="View incident">'
                         + esc(linkText) + '</a>' + parIcon;
            return '<tr class="incident-row" data-id="' + inc.id + '" data-case-num="' + esc(inc.incident_number || ('#' + inc.id)) + '" data-lat="' + inc.lat + '" data-lng="' + inc.lng + '"' + rowStyle + '>'
                + '<td>' + caseCell + '</td>'
                + '<td>' + esc(inc.scope || '') + '</td>'
                + '<td>' + esc(inc.incident_type || '') + '</td>'
                + '<td>' + esc((inc.street || '') + ' ' + (inc.city || '')) + '</td>'
                + '<td><span style="' + sevStyle + '">' + inc.severity + '</span></td>'
                + '<td>' + inc.units_assigned + '</td>'
                + '<td>' + (pts > 0 ? pts : '-') + '</td>'
                + '<td>' + (act > 0 ? act : '-') + '</td>'
                + '<td class="text-nowrap">' + formatUpdatedTime(inc.updated) + '</td>'
                + '</tr>';
        }).join('');

        tbody.innerHTML = rows;
        applyWidgetFilter('incidents'); // GH #65 — re-apply the header filter

        // Initialize Bootstrap tooltips (fast 100ms show delay)
        tbody.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });

        // Shift+click on incident link → open with focus on assign units
        tbody.querySelectorAll('.incident-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (e.shiftKey) {
                    e.preventDefault();
                    window.location.href = link.href + '&tab=assign';
                }
            });
        });

        // Click handler for incident selection (toggle on re-click)
        tbody.querySelectorAll('.incident-row').forEach(function (row) {
            row.addEventListener('click', function (e) {
                // Don't toggle selection if clicking a link
                if (e.target.closest('a')) return;
                var id = parseInt(row.dataset.id);
                if (selectedType === 'incident' && selectedId === id) {
                    EventBus.emit('selection:cleared');
                } else {
                    EventBus.emit('incident:selected', {
                        id: id,
                        lat: parseFloat(row.dataset.lat),
                        lng: parseFloat(row.dataset.lng)
                    });
                }
            });
        });

        // Preserve selection after re-render
        if (selectedType === 'incident' && selectedId) {
            var selRow = tbody.querySelector('tr[data-id="' + selectedId + '"]');
            if (selRow) {
                selRow.classList.add('selected');
            } else {
                // Selected incident no longer in list — clear selection
                selectedId = null;
                selectedType = null;
                showActionBar(false);
                clearMarkerHighlight();
            }
        }
    }

    function renderResponders(data) {
        var tbody = document.getElementById('respondersBody');
        if (!tbody) return;
        if (!tbody._sortInit) {
            sortWidgetData('respondersBody', 'responders', renderResponders);
            tbody._sortInit = true;
        }
        // Phase 99n (Eric beta 2026-06-29): stash latest responders
        // payload so the hotkey Note action can look up assigned
        // tickets without a re-fetch.
        _lastRespondersData = data.responders || [];

        var rows = (data.responders || []).filter(function (r) {
            // GH #66 — statuses flagged "Hide from Boards" (off-shift on
            // large rosters) are filtered from this widget listing.
            // Dispatch/assignment pickers still see every unit.
            return parseInt(r.hide_from_board, 10) !== 1;
        }).map(function (r) {
            // Phase 104j (a beta tester GH #9) — paint the status cell in
            // the configured un_status.bg_color / text_color instead
            // of leaving it plain text. Matches the mobile view and
            // the incident-detail assigned-responders panel. Falls
            // back to no styling when the status isn't colour-
            // configured, so legacy statuses look unchanged. bg_color
            // is admin-controlled but we still whitelist the accepted
            // character set (CSS colour syntax only) to keep the style
            // attribute safe from injection.
            function safeColor(v) {
                v = String(v || '');
                // Allow: #RRGGBB[AA], rgb(...)/rgba(...), hsl(...)/hsla(...),
                // and CSS named colours (letters only).
                return /^(#[0-9A-Fa-f]{3,8}|rgba?\([\d,.%\s]+\)|hsla?\([\d,.%\s]+\)|[A-Za-z]+)$/.test(v)
                       ? v : '';
            }
            var statusBg   = safeColor(r.status_bg_color);
            var statusFg   = safeColor(r.status_text_color);
            var statusStyle = '';
            if (statusBg && statusBg !== 'transparent') {
                statusStyle = ' style="background:' + statusBg + ';'
                            + (statusFg ? 'color:' + statusFg + ';' : '')
                            + 'font-weight:600;text-align:center;border-radius:3px;"';
            }
            return '<tr class="responder-row" data-id="' + r.id + '" data-lat="' + r.lat + '" data-lng="' + r.lng + '">'
                + '<td>' + esc(r.name || '') + '</td>'
                + '<td>' + esc(r.handle || '') + '</td>'
                + '<td>' + esc(r.type_name || '') + '</td>'
                + '<td' + statusStyle + '>' + esc(r.status_name || '') + '</td>'
                + '<td>' + r.active_assignments + '</td>'
                + '</tr>';
        }).join('');

        tbody.innerHTML = rows;
        applyWidgetFilter('responders'); // GH #65 — re-apply the header filter

        tbody.querySelectorAll('.responder-row').forEach(function (row) {
            row.addEventListener('click', function () {
                var id = parseInt(row.dataset.id);
                if (selectedType === 'responder' && selectedId === id) {
                    EventBus.emit('selection:cleared');
                } else {
                    EventBus.emit('responder:selected', {
                        id: id,
                        lat: parseFloat(row.dataset.lat),
                        lng: parseFloat(row.dataset.lng)
                    });
                }
            });
            row.addEventListener('dblclick', function () {
                var id = parseInt(row.dataset.id);
                window.location.href = 'unit-detail.php?id=' + id;
            });
            row.style.cursor = 'pointer';
        });
    }

    function renderFacilities(data) {
        var tbody = document.getElementById('facilitiesBody');
        if (!tbody) return;
        if (!tbody._sortInit) {
            sortWidgetData('facilitiesBody', 'facilities', renderFacilities);
            tbody._sortInit = true;
        }

        var rows = (data.facilities || []).map(function (f) {
            var hours = f.hours_today || '-';
            // GH #49 — honour the configured fac_status colours, and match
            // the Responders widget's FILLED-CELL style (not a pill) so the
            // two dashboard widgets look consistent. Shared helper = one
            // source of truth; statusless facilities show a plain dash like
            // the Hours column, exactly as an unstatused responder does.
            var fcStyle = '', fcLabel = '-';
            if (window.FacilityStatus) {
                var fc = window.FacilityStatus.cell(f);
                fcStyle = fc.style;
                fcLabel = fc.label || '-';
            } else {
                fcLabel = f.status_name || '-';
            }
            // GH #63 — combined Beds (Available / Occupied) cell, matching the
            // standalone facilities page convention (green avail / warning occ,
            // "--" when neither is configured). beds_a/beds_o are integers from
            // api/facilities.php when the columns exist, or absent (undefined)
            // on legacy installs missing them — the != null guard renders "--"
            // either way, so the column degrades gracefully. Values are numeric
            // (server-cast) so plain concatenation is XSS-safe.
            var bedsCell = '--';
            if (f.beds_a != null || f.beds_o != null) {
                bedsCell = '<span class="text-success fw-bold">' + (f.beds_a != null ? f.beds_a : 0) + '</span>'
                         + ' / <span class="text-warning">' + (f.beds_o != null ? f.beds_o : 0) + '</span>';
            }
            return '<tr class="facility-row" data-id="' + f.id + '" data-lat="' + f.lat + '" data-lng="' + f.lng + '">'
                + '<td>' + esc(f.name || '') + '</td>'
                + '<td>' + esc(f.type_name || '') + '</td>'
                + '<td' + fcStyle + '>' + esc(fcLabel) + '</td>'
                + '<td>' + bedsCell + '</td>'
                + '<td>' + hours + '</td>'
                + '</tr>';
        }).join('');

        tbody.innerHTML = rows;
        applyWidgetFilter('facilities'); // GH #65 — re-apply the header filter

        tbody.querySelectorAll('.facility-row').forEach(function (row) {
            row.addEventListener('click', function () {
                var id = parseInt(row.dataset.id);
                if (selectedType === 'facility' && selectedId === id) {
                    EventBus.emit('selection:cleared');
                } else {
                    EventBus.emit('facility:selected', {
                        id: id,
                        lat: parseFloat(row.dataset.lat),
                        lng: parseFloat(row.dataset.lng)
                    });
                }
            });
            row.addEventListener('dblclick', function () {
                var id = parseInt(row.dataset.id);
                window.location.href = 'facility-detail.php?id=' + id;
            });
            row.style.cursor = 'pointer';
        });
    }

    function renderLog(data) {
        var tbody = document.getElementById('logBody');
        if (!tbody) return;
        if (!tbody._sortInit) {
            sortWidgetData('logBody', 'log', renderLog);
            tbody._sortInit = true;
        }

        var rows = (data.entries || []).slice(0, 200).map(function (e) {
            // Build info column: link to incident detail if ticket_id exists
            var infoHtml = '';
            if (e.ticket_id > 0 && e.info) {
                infoHtml = '<a href="incident-detail.php?id=' + e.ticket_id
                    + '" class="text-decoration-none">'
                    + esc(truncate(e.info, 50)) + '</a>';
            } else {
                infoHtml = esc(truncate(e.info, 60));
            }

            return '<tr>'
                + '<td class="text-nowrap">' + formatTime(e.when) + '</td>'
                + '<td>' + esc(e.code_type) + '</td>'
                + '<td>' + esc(e.by) + '</td>'
                + '<td>' + infoHtml + '</td>'
                + '</tr>';
        }).join('');

        tbody.innerHTML = rows;
    }

    function renderMap(data) {
        var container = document.getElementById('mapContainer');
        if (!container) return;

        if (!map) {
            map = L.map(container, { zoomControl: false }).setView(
                [mapConfig.def_lat, mapConfig.def_lng], mapConfig.def_zoom
            );
            // GH #76 — auto-hide marker name labels when zoomed out + a toggle.
            if (window.TypeIcons && TypeIcons.bindLabelZoom) { TypeIcons.bindLabelZoom(map); }

            // ── Base Layers ──
            var osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            });
            var usgsTopoLayer = L.tileLayer('https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryTopo/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'USGS',
                maxZoom: 20,
            });
            var darkLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; CartoDB',
                maxZoom: 19,
                className: 'dark-tiles',
            });
            // Add the preferred default basemap from user prefs
            var prefKey = window.MapPrefs ? window.MapPrefs.getBasemap() : 'street';
            var prefDash = { street: osmLayer, dark: darkLayer, terrain: usgsTopoLayer };
            (prefDash[prefKey] || osmLayer).addTo(map);

            var baseLayers = {
                'Open Streetmaps': osmLayer,
                'USGS Topo': usgsTopoLayer,
                'Dark': darkLayer
            };

            // ── Data Layer Groups ──
            layerGroups.incidents = L.layerGroup().addTo(map);
            layerGroups.responders = L.layerGroup().addTo(map);
            layerGroups.facilities = L.layerGroup().addTo(map);
            layerGroups.markups = L.layerGroup(); // NOT added to map by default — user toggles on
            layerGroups.roadConditions = L.layerGroup(); // road-conditions overlay — toggled on demand

            // ── Weather Overlays ──
            var overlays = {};
            overlays['<span style="color:#dc3545">&#9679;</span> Incidents'] = layerGroups.incidents;
            overlays['<span style="color:#0d6efd">&#9679;</span> Responders'] = layerGroups.responders;
            overlays['<span style="color:#198754">&#9679;</span> Facilities'] = layerGroups.facilities;
            overlays['<span style="color:#9b59b6">&#9679;</span> Map Markups'] = layerGroups.markups;
            overlays['<span style="color:#fd7e14">&#9679;</span> Road Conditions'] = layerGroups.roadConditions;

            // Grid (graticule)
            if (typeof L.graticule === 'function') {
                overlays['Grid'] = L.graticule({ interval: 0.5 });
            }

            // Weather layers — routed through server-side caching proxy
            // API key stays server-side; all clients share one cache
            if (mapConfig.owm_api && typeof L.OWM !== 'undefined') {
                var proxyBase = 'api/weather-proxy.php?type=tile&layer={layername}&z={z}&x={x}&y={y}';
                var owmOpts = { appId: 'proxy', baseUrl: proxyBase, showLegend: false, opacity: 0.3 };
                overlays['Clouds'] = L.OWM.cloudsClassic(owmOpts);
                overlays['Precipitation'] = L.OWM.precipitationClassic(owmOpts);
                overlays['Rain'] = L.OWM.rainClassic(owmOpts);
                overlays['Pressure'] = L.OWM.pressureContour(
                    { appId: 'proxy', baseUrl: proxyBase, showLegend: false, opacity: 0.8 }
                );
                overlays['Temperature'] = L.OWM.temperature(owmOpts);
                overlays['Wind'] = L.OWM.wind(owmOpts);
                overlays['Snow'] = L.OWM.snow(owmOpts);

                // City Weather — override URL template to use proxy
                var cityWeather = L.OWM.current({ interval: 15, lang: 'en', minZoom: 8, appId: 'proxy' });
                cityWeather._urlTemplate = 'api/weather-proxy.php?type=cities&bbox={minlon},{minlat},{maxlon},{maxlat}';
                overlays['City Weather'] = cityWeather;
            }

            // NEXRAD Radar
            overlays['Radar'] = L.tileLayer.wms('https://mesonet.agron.iastate.edu/cgi-bin/wms/nexrad/n0r.cgi', {
                layers: 'nexrad-n0r-900913',
                format: 'image/png',
                transparent: true,
                attribution: ''
            });

            // Layer control first, then zoom below it
            var layersControl = L.control.layers(baseLayers, overlays, { collapsed: true, position: 'topright' });
            layersControl.addTo(map);
            window._mapLayersControl = layersControl;
            window._mapOverlays = overlays;
            L.control.zoom({ position: 'topright' }).addTo(map);

            // a beta tester GH #43 (2026-07-04) — event map image overlays on the
            // DASHBOARD map too, not just the EOC display. The dashboard
            // builds its own layer control (this one), so attach here.
            // No-op if map-image-overlays.js isn't loaded.
            if (window.MapImageOverlays && typeof window.MapImageOverlays.attach === 'function') {
                window.MapImageOverlays.attach(map, layersControl);
            }

            // ── Configured tile provider (specs/configurable-tile-providers-2026-06) ──
            // The built-in OSM/USGS/Dark base layers above are unchanged. If an
            // admin configured a Tile Provider in Settings, fold it in as an
            // ADDITIONAL selectable base layer once map-prefs.js has fetched it.
            // Additive + async — built-ins and the default selection are untouched
            // whether or not a provider is configured (graceful degradation).
            // Reversible: this whole block can be removed to restore prior behavior.
            if (window.MapPrefs && typeof window.MapPrefs.init === 'function') {
                window.MapPrefs.init().then(function () {
                    var label = window.MapPrefs.getCustomLabel();
                    if (label && !layersControl._ticketsCustomAdded) {
                        layersControl.addBaseLayer(window.MapPrefs.makeLayer('custom'), label);
                        layersControl._ticketsCustomAdded = true;
                    }
                });
            }

            // ── Phase 41: Map overlay category sub-layers ──
            // Each markup category becomes its own toggleable overlay so
            // dispatchers can show/hide precincts, zones, parade routes etc.
            // independently. Categories load async; markups load later and
            // route themselves into the per-category L.LayerGroup.
            if (!window._markupCatsLoadedOnce) {
                window._markupCatsLoadedOnce = true;
                window._markupCatLayers = {};      // catId -> L.layerGroup
                window._markupCatMeta = {};        // catId -> { name, color, default_visible }
                window._markupCatsReady = fetch('api/map-overlay-categories.php?action=list', { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : { categories: [] }; })
                    .then(function (cdata) {
                        var cats = (cdata && cdata.categories) || [];
                        for (var ci = 0; ci < cats.length; ci++) {
                            var c = cats[ci];
                            var grp = L.layerGroup();
                            window._markupCatLayers[c.id] = grp;
                            window._markupCatMeta[c.id] = {
                                name: c.name,
                                color: c.color || '#9b59b6',
                                default_visible: !!parseInt(c.default_visible, 10)
                            };
                            var swatch = '<span style="color:' + (c.color || '#9b59b6') + '">&#9679;</span> ';
                            var label = swatch + (c.name || ('Category ' + c.id));
                            overlays[label] = grp;
                            layersControl.addOverlay(grp, label);
                            if (window._markupCatMeta[c.id].default_visible) {
                                grp.addTo(map);
                            }
                        }
                        return cats;
                    })
                    .catch(function () { return []; });
            }

            // Scale control
            L.control.scale({ position: 'bottomright' }).addTo(map);

            // ─��� Restore saved layer preferences ──
            try {
                var savedPrefs = JSON.parse(localStorage.getItem('newui_map_layers') || 'null');
                if (savedPrefs) {
                    // Restore base layer (remove whichever base was added initially)
                    if (savedPrefs.base && baseLayers[savedPrefs.base]) {
                        Object.keys(baseLayers).forEach(function (name) {
                            if (map.hasLayer(baseLayers[name])) baseLayers[name].remove();
                        });
                        baseLayers[savedPrefs.base].addTo(map);
                    }
                    // Restore active overlays
                    if (savedPrefs.overlays && Array.isArray(savedPrefs.overlays)) {
                        savedPrefs.overlays.forEach(function (name) {
                            if (overlays[name] && !map.hasLayer(overlays[name])) {
                                overlays[name].addTo(map);
                            }
                        });
                    }
                }
            } catch (e) {}

            // ── Save layer preferences on change ──
            function saveMapLayers() {
                var activeBase = '';
                Object.keys(baseLayers).forEach(function (name) {
                    if (map.hasLayer(baseLayers[name])) activeBase = name;
                });
                var activeOverlays = [];
                Object.keys(overlays).forEach(function (name) {
                    if (map.hasLayer(overlays[name])) activeOverlays.push(name);
                });
                try {
                    localStorage.setItem('newui_map_layers', JSON.stringify({
                        base: activeBase,
                        overlays: activeOverlays
                    }));
                } catch (e) {}
            }

            map.on('baselayerchange', saveMapLayers);
            map.on('overlayadd', saveMapLayers);
            map.on('overlayremove', saveMapLayers);

            // Area title
            if (mapConfig.area_title) {
                var TitleControl = L.Control.extend({
                    options: { position: 'bottomright' },
                    onAdd: function () {
                        var div = L.DomUtil.create('div', 'map-area-title');
                        div.textContent = mapConfig.area_title;
                        return div;
                    }
                });
                new TitleControl().addTo(map);
            }

            // Search control
            addMapSearch(map);

            setTimeout(function () { map.invalidateSize(); }, 200);

            // Start real-time unit tracking overlay (10s refresh)
            if (typeof UnitTracking !== 'undefined' && !window._unitTracker) {
                var trackerLayer = L.layerGroup().addTo(map);
                overlays['<span style="color:#ff6600">&#9679;</span> Live Tracking'] = trackerLayer;

                window._unitTracker = UnitTracking.init(map, {
                    refreshInterval: 10000,
                    showLabels: true,
                    showStale: true,
                    showTrails: false,
                    onClick: function (unit) {
                        if (unit.responder_id) {
                            window.location.href = 'unit-detail.php?id=' + unit.responder_id;
                        }
                    }
                });
                window._unitTracker.start();
            }
        }

        // Clear existing markers from layer groups
        Object.keys(markers).forEach(function (key) {
            markers[key].forEach(function (m) {
                if (layerGroups[key]) layerGroups[key].removeLayer(m);
            });
            markers[key] = [];
        });

        var bounds = [];

        // Incident markers (red) with rich popups
        if (data.incidents && data.incidents.incidents) {
            data.incidents.incidents.forEach(function (inc) {
                if (!inc.lat || !inc.lng) return;
                var m = L.circleMarker([inc.lat, inc.lng], {
                    radius: 8, fillColor: inc.severity_color || '#ff0000',
                    color: '#333', weight: 1, fillOpacity: 0.8
                });
                var popup = '<div class="map-popup">'
                    + '<div class="map-popup-title">' + esc(inc.scope || (inc.incident_number || ('#' + inc.id))) + '</div>'
                    + '<div class="map-popup-type">' + esc(inc.incident_type || '') + '</div>'
                    + '<div class="map-popup-detail">'
                    + '<i class="bi bi-geo-alt"></i> ' + esc((inc.street || '') + ' ' + (inc.city || '')) + '<br>'
                    + '<i class="bi bi-speedometer2"></i> Severity: ' + inc.severity
                    + ' &middot; Units: ' + inc.units_assigned
                    + '</div></div>';
                m.bindPopup(popup, { maxWidth: 280 });
                m.on('click', function () {
                    EventBus.emit('incident:selected', { id: inc.id, lat: inc.lat, lng: inc.lng });
                });
                layerGroups.incidents.addLayer(m);
                markers.incidents.push(m);
                bounds.push([inc.lat, inc.lng]);
            });
        }

        // Responder markers (blue)
        if (data.responders && data.responders.responders) {
            data.responders.responders.forEach(function (r) {
                if (!r.lat || !r.lng) return;
                // GH #82/#76 — status-coloured type glyph + name label (was a
                // hardcoded blue dot). Falls back to the dot if unavailable.
                var rColor = r.status_bg_color || (r.status_name === 'Available' ? '#198754' : '#6c757d');
                var rIcon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                    ? window.TypeIcons.markerDivIcon(r.icon, rColor, { label: (r.handle || r.name || '') })
                    : null;
                var m = rIcon
                    ? L.marker([r.lat, r.lng], { icon: rIcon })
                    : L.circleMarker([r.lat, r.lng], {
                        radius: 6, fillColor: '#0d6efd',
                        color: '#333', weight: 1, fillOpacity: 0.7
                    });
                var popup = '<div class="map-popup">'
                    + '<div class="map-popup-title">' + esc(r.name || '') + '</div>'
                    + '<div class="map-popup-type">' + esc(r.handle || '') + '</div>'
                    + '<div class="map-popup-detail">'
                    + '<i class="bi bi-person-badge"></i> ' + esc(r.type_name || '') + '<br>'
                    + '<i class="bi bi-circle-fill" style="color:' + (r.status_name === 'Available' ? '#198754' : '#6c757d') + ';font-size:0.5rem;vertical-align:middle"></i> '
                    + esc(r.status_name || '')
                    + ' &middot; Assignments: ' + r.active_assignments
                    + '</div></div>';
                m.bindPopup(popup, { maxWidth: 280 });
                m.on('click', function () {
                    EventBus.emit('responder:selected', { id: r.id, lat: r.lat, lng: r.lng });
                });
                layerGroups.responders.addLayer(m);
                markers.responders.push(m);
                bounds.push([r.lat, r.lng]);
            });
        }

        // Facility markers (green)
        if (data.facilities && data.facilities.facilities) {
            data.facilities.facilities.forEach(function (f) {
                if (!f.lat || !f.lng) return;
                // GH #82/#76 — status-coloured type glyph + name label (was a
                // hardcoded green dot). Falls back to the dot if unavailable.
                var fColor = (f.bg_color && String(f.bg_color).toLowerCase() !== '#ffffff')
                    ? f.bg_color : '#198754';
                var fIcon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                    ? window.TypeIcons.markerDivIcon(f.type_icon, fColor, { label: (f.name || ''), square: true })
                    : null;
                var m = fIcon
                    ? L.marker([f.lat, f.lng], { icon: fIcon })
                    : L.circleMarker([f.lat, f.lng], {
                        radius: 6, fillColor: '#198754',
                        color: '#333', weight: 1, fillOpacity: 0.7
                    });
                var openIcon = f.is_open === true ? '<i class="bi bi-unlock text-success"></i> Open'
                    : f.is_open === false ? '<i class="bi bi-lock text-danger"></i> Closed'
                    : '';
                var popup = '<div class="map-popup">'
                    + '<div class="map-popup-title">' + esc(f.name || '') + '</div>'
                    + '<div class="map-popup-type">' + esc(f.type_name || '') + '</div>'
                    + '<div class="map-popup-detail">'
                    + '<i class="bi bi-clock"></i> ' + esc(f.hours_today || '-') + ' ' + openIcon + '<br>'
                    + '<i class="bi bi-circle-fill" style="color:' + (f.bg_color || '#6c757d') + ';font-size:0.5rem;vertical-align:middle"></i> '
                    + esc(f.status_name || '')
                    + (f.phone ? '<br><i class="bi bi-telephone"></i> ' + esc(f.phone) : '')
                    + '</div></div>';
                m.bindPopup(popup, { maxWidth: 280 });
                m.on('click', function () {
                    EventBus.emit('facility:selected', { id: f.id, lat: f.lat, lng: f.lng });
                });
                layerGroups.facilities.addLayer(m);
                markers.facilities.push(m);
                bounds.push([f.lat, f.lng]);
            });
        }

        // Load map markups (polygons, circles, lines) — loaded once, not refreshed.
        // Phase 41: route each markup into its category sub-layer when present, so
        // dispatchers can toggle precincts/zones/parade routes etc. independently.
        if (layerGroups.markups && !window._markupsLoaded) {
            window._markupsLoaded = true;
            var _catsReady = window._markupCatsReady || Promise.resolve([]);
            _catsReady.then(function () { return fetch('api/map-markups.php', { credentials: 'same-origin' }); })
                .then(function (r) { return r.json(); })
                .then(function (mdata) {
                    var mkups = mdata.markups || [];
                    for (var mi = 0; mi < mkups.length; mi++) {
                        var mk = mkups[mi];
                        if (!mk.line_data || parseInt(mk.line_status, 10) === 0) continue;
                        try {
                            var coords = JSON.parse(mk.line_data);
                            if (!coords || !coords.length) continue;
                            var mType = (mk.line_type || '').toUpperCase();
                            var mColor = mk.line_color || '#9b59b6';
                            var mFill = mk.fill_color || mColor;
                            var mOpacity = parseFloat(mk.line_opacity) || 0.7;
                            var mFillOpacity = parseFloat(mk.fill_opacity) || 0.15;
                            var mWeight = parseInt(mk.line_width, 10) || 2;
                            var mName = mk.line_name || '';
                            var shape = null;

                            if (mType === 'P' && coords.length >= 3) {
                                shape = L.polygon(coords, {
                                    color: mColor, weight: mWeight, opacity: mOpacity,
                                    fillColor: mFill, fillOpacity: mFillOpacity
                                });
                            } else if (mType === 'C' && coords.length >= 1) {
                                var radius = parseFloat(mk.line_ident) || 500;
                                shape = L.circle(coords[0], {
                                    radius: radius, color: mColor, weight: mWeight, opacity: mOpacity,
                                    fillColor: mFill, fillOpacity: mFillOpacity
                                });
                            } else if (mType === 'L' && coords.length >= 2) {
                                shape = L.polyline(coords, {
                                    color: mColor, weight: mWeight, opacity: mOpacity
                                });
                            } else if (mType === 'M' && coords.length >= 1) {
                                shape = L.marker(coords[0]);
                            }

                            if (shape) {
                                if (mName) {
                                    // Phase 43: zones (filled polygons + circles) get a
                                    // permanent center label so operators see "Zone 3"
                                    // without hovering; lines/markers keep hover-only.
                                    var isZone = (mType === 'P' || mType === 'C');
                                    shape.bindTooltip(esc(mName), {
                                        permanent: isZone,
                                        direction: 'center',
                                        className: isZone ? 'map-zone-label' : ''
                                    });
                                }
                                // Phase 41: prefer mmarkup.category_id (new), fall back
                                // to legacy line_cat_id, then to the catch-all markups
                                // layer when neither is present or the category is gone.
                                var effCat = parseInt(mk.category_id || mk.line_cat_id || 0, 10);
                                var targetLayer = (effCat && window._markupCatLayers && window._markupCatLayers[effCat])
                                    ? window._markupCatLayers[effCat]
                                    : layerGroups.markups;
                                targetLayer.addLayer(shape);
                            }
                        } catch (e) {}
                    }
                })
                .catch(function () {});
        }

        // Road-conditions overlay — loaded once (like markups), toggled by the
        // user via the layer control or the "Roads" control-bar button.
        loadRoadConditions();

        // Only auto-fit on first render; after that, respect user's zoom/pan
        if (bounds.length > 0) {
            lastBounds = L.latLngBounds(bounds);
            if (!mapInitialFitDone) {
                map.fitBounds(lastBounds, { padding: [60, 60], maxZoom: 13 });
                mapInitialFitDone = true;
            }
        }
    }

    // ── Road-conditions overlay (maps-comprehensive-2026-06) ──
    // Plots each roadinfo report (that has coordinates) with its condition
    // icon + a popup. Loaded once; reports without lat/lng are skipped server
    // side. The layer starts hidden — the user toggles it on.
    function loadRoadConditions() {
        if (!layerGroups.roadConditions || window._roadConditionsLoaded) return;
        window._roadConditionsLoaded = true;
        fetch('api/road-conditions.php?map=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var reports = (data && data.reports) || [];
                for (var i = 0; i < reports.length; i++) {
                    addRoadConditionMarker(reports[i]);
                }
            })
            .catch(function () { window._roadConditionsLoaded = false; });
    }

    function addRoadConditionMarker(rep) {
        var lat = parseFloat(rep.lat);
        var lng = parseFloat(rep.lng);
        if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;

        // Use the condition icon (a Bootstrap Icon class name) when present;
        // fall back to a generic cone marker so a typeless report still shows.
        var iconClass = (rep.condition_icon && /^bi[\s-]/.test(rep.condition_icon))
            ? rep.condition_icon
            : 'bi bi-cone-striped';
        var html = '<div class="road-condition-marker" title="' + esc(rep.condition_title || rep.title || 'Road condition') + '">'
            + '<i class="' + esc(iconClass) + '"></i></div>';
        var marker = L.marker([lat, lng], {
            icon: L.divIcon({
                className: 'road-condition-divicon',
                html: html,
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            })
        });

        var popup = '<div class="map-popup">'
            + '<div class="map-popup-title">'
            + '<i class="' + esc(iconClass) + ' me-1"></i>'
            + esc(rep.condition_title || 'Road Condition')
            + '</div>'
            + (rep.title ? '<div class="map-popup-type">' + esc(rep.title) + '</div>' : '')
            + '<div class="map-popup-detail">';
        var detail = rep.condition_description || rep.description || '';
        if (detail) popup += esc(detail) + '<br>';
        if (rep.address) popup += '<i class="bi bi-geo-alt"></i> ' + esc(rep.address);
        popup += '</div></div>';
        marker.bindPopup(popup, { maxWidth: 280 });

        layerGroups.roadConditions.addLayer(marker);
    }

    // ── "Roads" control-bar button (maps-comprehensive-2026-06) ──
    // Toggles the road-conditions overlay on the dashboard map and, when
    // turning it on, fits the view to the plotted reports so the dispatcher
    // sees them immediately.
    function toggleRoadConditions() {
        if (!map || !layerGroups.roadConditions) return;
        var grp = layerGroups.roadConditions;
        if (map.hasLayer(grp)) {
            map.removeLayer(grp);
            return;
        }
        // Ensure data is present, then show + focus.
        function showAndFocus() {
            grp.addTo(map);
            var pts = [];
            grp.eachLayer(function (l) {
                if (l.getLatLng) { var p = l.getLatLng(); pts.push([p.lat, p.lng]); }
            });
            if (pts.length > 0) {
                map.fitBounds(L.latLngBounds(pts), { padding: [60, 60], maxZoom: 14 });
            }
        }
        if (!window._roadConditionsLoaded) {
            window._roadConditionsLoaded = true;
            fetch('api/road-conditions.php?map=1', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var reports = (data && data.reports) || [];
                    for (var i = 0; i < reports.length; i++) addRoadConditionMarker(reports[i]);
                    showAndFocus();
                })
                .catch(function () { window._roadConditionsLoaded = false; });
        } else {
            showAndFocus();
        }
    }

    function addMapSearch(mapInstance) {
        var SearchControl = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function () {
                var container = L.DomUtil.create('div', 'leaflet-bar leaflet-control map-search-control');
                container.innerHTML = '<input type="text" class="map-search-input" placeholder="Search address..." />'
                    + '<button class="map-search-btn" title="Search"><i class="bi bi-search"></i></button>';
                L.DomEvent.disableClickPropagation(container);
                L.DomEvent.disableScrollPropagation(container);

                var input = container.querySelector('.map-search-input');
                var btn = container.querySelector('.map-search-btn');

                function doSearch() {
                    var q = input.value.trim();
                    if (!q) return;
                    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (results) {
                            if (results && results.length > 0) {
                                var r = results[0];
                                var lat = parseFloat(r.lat);
                                var lng = parseFloat(r.lon);
                                if (searchMarker) mapInstance.removeLayer(searchMarker);
                                searchMarker = L.marker([lat, lng]).addTo(mapInstance)
                                    .bindPopup('<b>Search result</b><br>' + esc(r.display_name || q))
                                    .openPopup();
                                mapInstance.setView([lat, lng], 15);
                            } else {
                                input.classList.add('search-no-results');
                                setTimeout(function () { input.classList.remove('search-no-results'); }, 1500);
                            }
                        })
                        .catch(function () {
                            console.warn('Geocoding failed');
                        });
                }

                btn.addEventListener('click', doSearch);
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') doSearch();
                });

                return container;
            }
        });
        new SearchControl().addTo(mapInstance);
    }

    // ── Selection Helpers ────────────────────────────────────────────────

    function clearAllSelections() {
        document.querySelectorAll('.incident-row.selected, .responder-row.selected, .facility-row.selected').forEach(function (r) {
            r.classList.remove('selected');
        });
        clearMarkerHighlight();
    }

    function selectRow(tableId, rowId) {
        clearAllSelections();
        var row = document.querySelector('#' + tableId + ' tr[data-id="' + rowId + '"]');
        if (row) {
            row.classList.add('selected');
            row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    function highlightMarker(lat, lng) {
        clearMarkerHighlight();
        if (!map || !lat || !lng) return;
        selectedMarkerRing = L.circleMarker([lat, lng], {
            radius: 16, fillColor: 'transparent', fillOpacity: 0,
            color: '#ffc107', weight: 3, opacity: 0.9,
            className: 'marker-highlight-ring'
        }).addTo(map);
    }

    function clearMarkerHighlight() {
        if (selectedMarkerRing && map) {
            map.removeLayer(selectedMarkerRing);
            selectedMarkerRing = null;
        }
    }

    function resetMapView() {
        clearAllSelections();
        if (searchMarker && map) {
            map.removeLayer(searchMarker);
            searchMarker = null;
        }
        if (map && lastBounds && lastBounds.isValid()) {
            map.fitBounds(lastBounds, { padding: [60, 60], maxZoom: 13 });
        }
    }

    // ── Event Handlers ─────────────────────────────────────────────────

    function onSelectionCleared() {
        clearAllSelections();
        selectedId = null;
        selectedType = null;
        showActionBar(false);
        showResponderActionBar(false);
        showFacilityActionBar(false);
        updateMapFocusLabel(null);
        if (map && lastBounds && lastBounds.isValid()) {
            map.fitBounds(lastBounds, { padding: [60, 60], maxZoom: 13 });
        }
    }

    function onIncidentSelected(data) {
        selectedId = data.id;
        selectedType = 'incident';
        selectRow('incidentsBody', data.id);
        highlightMarker(data.lat, data.lng);
        showActionBar(true);
        // Phase 99n: hide the responder bar if user switched
        // selection from a responder to an incident.
        showResponderActionBar(false);
        showFacilityActionBar(false); // Phase 115
        // Update map header with incident summary (scope, type, address)
        var row = document.querySelector('#incidentsBody tr[data-id="' + data.id + '"]');
        if (row) {
            var cells = row.querySelectorAll('td');
            var scope = cells[1] ? cells[1].textContent.trim() : '';
            var type = cells[2] ? cells[2].textContent.trim() : '';
            var location = cells[3] ? cells[3].textContent.trim() : '';
            // Phase 99p — prefer the case number; data.incident_number
            // is plumbed from incidents.php for SSE updates too.
            var parts = [data.incident_number || ('#' + data.id)];
            if (scope) parts.push(scope);
            if (type) parts.push('[' + type + ']');
            if (location) parts.push(location);
            updateMapFocusLabel(parts.join(' '));
        }
        if (map && data.lat && data.lng) {
            map.setView([data.lat, data.lng], 15);
        }
    }

    function updateMapFocusLabel(text) {
        var label = document.getElementById('mapFocusLabel');
        if (!label) return;
        if (text) {
            label.textContent = '— ' + text;
            label.classList.remove('d-none');
        } else {
            label.textContent = '';
            label.classList.add('d-none');
        }
    }

    // ── Action Bar ──────────────────────────────────────────────────────

    function showActionBar(visible) {
        var bar = document.getElementById('incidentActionBar');
        if (bar) {
            if (visible) {
                bar.classList.remove('d-none');
            } else {
                bar.classList.add('d-none');
            }
        }
    }

    function showResponderActionBar(visible) {
        var bar = document.getElementById('responderActionBar');
        if (bar) {
            if (visible) bar.classList.remove('d-none');
            else bar.classList.add('d-none');
        }
    }

    // Phase 115 (Eric 2026-07-06) — Facilities widget action bar toggle.
    function showFacilityActionBar(visible) {
        var bar = document.getElementById('facilityActionBar');
        if (bar) {
            if (visible) bar.classList.remove('d-none');
            else bar.classList.add('d-none');
        }
    }

    /**
     * Phase 99n (Eric beta 2026-06-29) — hotkey actions for the
     * Responders widget. Mirror the incident action contract:
     * one method, switch on actionName, no-op when selection is
     * not a responder.
     *
     *   view     -> unit-detail.php?id=<id>
     *   edit     -> unit-edit.php?id=<id>
     *   dispatch -> unit-detail.php?id=<id>&tab=assign  (assign UI)
     *   note     -> if unit has exactly one active assignment, prompt
     *               for note text + post action note tagged to that
     *               incident. If zero or 2+ assignments, jump to unit
     *               detail so the dispatcher can pick the incident.
     */
    function executeResponderAction(actionName) {
        if (!selectedId || selectedType !== 'responder') return;
        var id = selectedId;
        switch (actionName) {
            case 'view':
                window.location.href = 'unit-detail.php?id=' + id;
                break;
            case 'edit':
                window.location.href = 'unit-edit.php?id=' + id;
                break;
            case 'dispatch':
                // Phase 99q (Eric beta 2026-06-29) — dispatcher's
                // expected workflow: phone rings, unit is available,
                // dispatcher hits D, picks an OPEN INCIDENT from a
                // list, unit is now assigned. Previously hit D opened
                // the unit's own detail page (no assign-from-here UI).
                _openResponderDispatchPicker(id);
                break;
            case 'note':
                _openResponderModal(id, 'note');
                break;
            case 'status':
                _openResponderModal(id, 'status');
                break;
        }
    }

    /**
     * Phase 115 (Eric 2026-07-06) — Facilities widget quick actions.
     *   view      -> facility-detail.php
     *   edit      -> facility-edit.php
     *   incident  -> new-incident.php?facility=<id> (new incident AT here)
     *   status    -> modal: pick a fac_status + optional note
     *   note      -> modal: free-text note to the facility log
     *   beds      -> modal: update bed counts (available/occupied) + note
     */
    function executeFacilityAction(actionName) {
        if (!selectedId || selectedType !== 'facility') return;
        var id = selectedId;
        switch (actionName) {
            case 'view':
                window.location.href = 'facility-detail.php?id=' + id;
                break;
            case 'edit':
                window.location.href = 'facility-edit.php?id=' + id;
                break;
            case 'incident':
                // new-incident.js reads ?facility= and pre-selects the
                // "Incident at Facility" dropdown once options load.
                window.location.href = 'new-incident.php?facility=' + id;
                break;
            case 'status':
            case 'note':
            case 'beds':
                _openFacilityModal(id, actionName);
                break;
        }
    }

    // Bootstrap modal instance for the facility quick-action modal (lazy).
    var _facModalInstance = null;
    var _facModalData = null; // { id, mode, statuses, facility }

    /**
     * Open the facility quick-action modal in 'status' | 'note' | 'beds'
     * mode. Fetches the facility's current values + status list first so
     * the form is pre-filled, then wires the shared Apply button.
     */
    function _openFacilityModal(id, mode) {
        var titleEl = document.getElementById('facilityActionModalTitle');
        var bodyEl  = document.getElementById('facilityActionModalBody');
        var applyEl = document.getElementById('facilityActionApply');
        if (!titleEl || !bodyEl || !applyEl) return;

        bodyEl.innerHTML = '<div class="text-secondary small py-2">'
            + '<span class="spinner-border spinner-border-sm me-2"></span>' + _facT('loading', 'Loading…') + '</div>';
        if (!_facModalInstance) {
            _facModalInstance = new bootstrap.Modal(document.getElementById('facilityActionModal'));
        }
        _facModalInstance.show();

        fetch('api/facility-action.php?facility_id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    bodyEl.innerHTML = '<div class="text-danger small">'
                        + esc((data && data.error) || 'Failed to load facility') + '</div>';
                    return;
                }
                _facModalData = { id: id, mode: mode, statuses: data.statuses || [], facility: data.facility || {} };
                var fname = data.facility && data.facility.name ? data.facility.name : ('Facility #' + id);
                titleEl.textContent = fname + ' — '
                    + (mode === 'status' ? _facT('set_status', 'Set Status')
                        : mode === 'beds' ? _facT('bed_counts', 'Bed Counts')
                        : _facT('add_note', 'Add Note'));
                bodyEl.innerHTML = _facModalFormHtml(mode, _facModalData);
                // Disable Apply for the "no statuses configured" empty state.
                var applyNow = document.getElementById('facilityActionApply');
                if (applyNow) {
                    applyNow.disabled = (mode === 'status' && (!_facModalData.statuses || !_facModalData.statuses.length));
                }
                var first = bodyEl.querySelector('select, input, textarea');
                if (first) first.focus();
            })
            .catch(function () {
                bodyEl.innerHTML = '<div class="text-danger small">Network error loading facility.</div>';
            });

        // Rebind Apply to this open (clone to drop old listeners).
        var fresh = applyEl.cloneNode(true);
        applyEl.parentNode.replaceChild(fresh, applyEl);
        fresh.addEventListener('click', _facModalApply);
    }

    // GH #70 — modal strings come from the caption table via the
    // FAC_MODAL_I18N object index.php embeds (server-side t()).
    function _facT(key, fallback) {
        var d = window.FAC_MODAL_I18N || {};
        return d[key] || fallback;
    }

    function _facModalFormHtml(mode, d) {
        var optional = ' <span class="text-secondary">' + _escHtml(_facT('optional', '(optional)')) + '</span>';
        if (mode === 'status') {
            // No facility statuses configured yet (fresh install) — point the
            // admin at Settings rather than showing an empty dropdown.
            if (!d.statuses || !d.statuses.length) {
                return '<div class="alert alert-warning small mb-0">'
                    + _escHtml(_facT('no_statuses',
                        'No facility statuses are configured yet. Add them under Settings → Facilities, then you can set a facility’s status from here.'))
                    + ' <a href="settings.php#facilities">Settings</a></div>';
            }
            var opts = (d.statuses || []).map(function (s) {
                var sel = (parseInt(d.facility.status_id, 10) === s.id) ? ' selected' : '';
                return '<option value="' + s.id + '"' + sel + '>' + _escHtml(s.name) + '</option>';
            }).join('');
            return '<label class="form-label small mb-1">' + _escHtml(_facT('status', 'Status')) + '</label>'
                + '<select class="form-select form-select-sm mb-2" id="facModalStatus">' + opts + '</select>'
                + '<label class="form-label small mb-1">' + _escHtml(_facT('note', 'Note')) + optional + '</label>'
                + '<textarea class="form-control form-control-sm" id="facModalNote" rows="2" '
                + 'placeholder="' + _escHtml(_facT('ph_reason', 'Reason / detail…')) + '"></textarea>';
        }
        if (mode === 'beds') {
            return '<div class="row g-2 mb-2">'
                + '<div class="col-6"><label class="form-label small mb-1">' + _escHtml(_facT('beds_available', 'Beds Available')) + '</label>'
                + '<input type="number" min="0" class="form-control form-control-sm" id="facModalBedsA" '
                + 'value="' + _escHtml(d.facility.beds_a || '') + '"></div>'
                + '<div class="col-6"><label class="form-label small mb-1">' + _escHtml(_facT('beds_occupied', 'Beds Occupied')) + '</label>'
                + '<input type="number" min="0" class="form-control form-control-sm" id="facModalBedsO" '
                + 'value="' + _escHtml(d.facility.beds_o || '') + '"></div></div>'
                + '<label class="form-label small mb-1">' + _escHtml(_facT('note', 'Note')) + optional + '</label>'
                + '<textarea class="form-control form-control-sm" id="facModalNote" rows="2" '
                + 'placeholder="' + _escHtml(_facT('ph_beds', 'Bed/capacity detail…')) + '">' + _escHtml(d.facility.beds_info || '') + '</textarea>';
        }
        // note
        return '<label class="form-label small mb-1">' + _escHtml(_facT('note', 'Note')) + '</label>'
            + '<textarea class="form-control form-control-sm" id="facModalNote" rows="3" '
            + 'placeholder="' + _escHtml(_facT('ph_note', 'Facility note…')) + '"></textarea>';
    }

    function _facModalApply() {
        if (!_facModalData) return;
        var d = _facModalData;
        var payload = { action: d.mode, facility_id: d.id, csrf_token: _csrf() };
        if (d.mode === 'status') {
            var sEl = document.getElementById('facModalStatus');
            payload.status_id = sEl ? parseInt(sEl.value, 10) : 0;
            var nEl = document.getElementById('facModalNote');
            payload.note = nEl ? nEl.value : '';
            if (!payload.status_id) { showBriefToast(_facT('pick_status', 'Pick a status')); return; }
        } else if (d.mode === 'beds') {
            var aEl = document.getElementById('facModalBedsA');
            var oEl = document.getElementById('facModalBedsO');
            var bnEl = document.getElementById('facModalNote');
            if (aEl && aEl.value !== '') payload.beds_a = parseInt(aEl.value, 10);
            if (oEl && oEl.value !== '') payload.beds_o = parseInt(oEl.value, 10);
            if (bnEl) payload.note = bnEl.value;
            if (payload.beds_a === undefined && payload.beds_o === undefined) {
                showBriefToast('Enter a bed count'); return;
            }
        } else { // note
            var tEl = document.getElementById('facModalNote');
            payload.note = tEl ? tEl.value.trim() : '';
            if (!payload.note) { showBriefToast('Enter a note'); return; }
        }

        var applyBtn = document.getElementById('facilityActionApply');
        if (applyBtn) { applyBtn.disabled = true; applyBtn.textContent = 'Saving…'; }

        fetch('api/facility-action.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': _csrf() },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (applyBtn) { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; }
                if (!res.ok || !res.j || !res.j.success) {
                    showBriefToast((res.j && res.j.error) || 'Update failed');
                    return;
                }
                if (_facModalInstance) _facModalInstance.hide();
                showBriefToast(res.j.message || 'Saved');
                // Refresh just the facilities widget so the new status shows.
                DataService.fetchAll(['facilities']).then(function (data) {
                    if (data.facilities) renderFacilities(data.facilities);
                }).catch(function () {});
            })
            .catch(function () {
                if (applyBtn) { applyBtn.disabled = false; applyBtn.textContent = 'Apply'; }
                showBriefToast('Network error');
            });
    }

    /**
     * Resolve the unit's active assignments and post a quick action
     * note when exactly one is active. Otherwise punt to unit detail.
     */
    var _lastRespondersData = [];
    // Cached un_status options (populated lazily on first Status open)
    var _unStatusCache = null;

    function _csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function _escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /**
     * Phase 99n-v2 (Eric beta 2026-06-29) — open the responder
     * quick-action modal. Single entrypoint, mode = 'note' or 'status'.
     * Handles all three assignment-count cases (0 / 1 / N) inside
     * the modal so the user always sees the same shape: pick a row,
     * fill input, hit Apply.
     */
    /**
     * Phase 99q (Eric beta 2026-06-29) — dispatch picker. Opens the
     * responder modal in "dispatch" mode: lists currently OPEN
     * incidents (status=2 or 3), one button per row, click to assign
     * this unit to that incident. Skips incidents the unit is already
     * assigned to (no double-assign noise).
     */
    function _openResponderDispatchPicker(respId) {
        var resp = _lastRespondersData.find(function (r) {
            return parseInt(r.id, 10) === parseInt(respId, 10);
        });
        var modalEl = document.getElementById('responderActionModal');
        var titleEl = document.getElementById('responderActionModalTitle');
        var bodyEl = document.getElementById('responderActionModalBody');
        if (!modalEl || !bodyEl || !titleEl) {
            // Modal element missing — fall through to the legacy
            // unit-detail page so the dispatcher isn't stranded.
            window.location.href = 'unit-detail.php?id=' + respId + '&tab=assign';
            return;
        }
        var handle = (resp && (resp.handle || resp.name)) || ('unit #' + respId);
        titleEl.textContent = 'Dispatch — ' + handle;
        bodyEl.innerHTML = '<div class="text-body-secondary small py-3 text-center">'
            + '<i class="bi bi-hourglass-split me-1"></i>Loading open incidents&hellip;</div>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        // Already-assigned incidents — skip these so we don't show
        // the user a button that would error out as "already assigned".
        var alreadyAssigned = {};
        if (resp && resp.assigned_tickets) {
            for (var ai = 0; ai < resp.assigned_tickets.length; ai++) {
                // QA #6 — assigned_tickets items carry `ticket_id`, not `id`
                // (api/responders.php). Keying on .id set {'undefined':true} so
                // the filter below (i.id) never matched and already-assigned
                // incidents were still offered, causing a dup-assign error.
                alreadyAssigned[String(resp.assigned_tickets[ai].ticket_id)] = true;
            }
        }

        // Reuse the dashboard's incidents endpoint — same payload
        // shape we render in the Incidents widget. Filter to open
        // statuses client-side.
        fetch('api/incidents.php?func=0', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var incidents = (data && data.incidents) || [];
                // Open + Scheduled only (status 2 + 3). Status 1 = Closed.
                incidents = incidents.filter(function (i) {
                    return (i.status === 2 || i.status === 3)
                        && !alreadyAssigned[String(i.id)];
                });
                _renderDispatchPickerBody(respId, handle, incidents, bodyEl);
            })
            .catch(function (err) {
                bodyEl.innerHTML = '<div class="alert alert-danger small">'
                    + 'Failed to load incidents: ' + _escHtml(err.message || String(err))
                    + '</div>';
            });
    }

    function _renderDispatchPickerBody(respId, handle, incidents, bodyEl) {
        if (!incidents.length) {
            bodyEl.innerHTML = '<div class="alert alert-info small mb-0">'
                + 'No open incidents to dispatch ' + _escHtml(handle) + ' to.'
                + '<br>Create a new incident first, or use the unit edit page for a manual assignment.'
                + '</div>';
            return;
        }
        var html = '<p class="text-body-secondary small mb-2">'
                 + 'Select an incident to dispatch <strong>' + _escHtml(handle) + '</strong> to:'
                 + '</p>'
                 + '<div class="list-group">';
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];
            var caseNum = inc.incident_number || ('#' + inc.id);   // Phase 99o/p
            var scope = inc.scope || inc.incident_type || '(no description)';
            var loc = (inc.street || '') + (inc.city ? ' · ' + inc.city : '');
            var sevDot = inc.severity_color
                ? '<span class="d-inline-block rounded-circle me-2" '
                  + 'style="width:8px;height:8px;background:' + _escHtml(inc.severity_color) + ';"></span>'
                : '';
            html += '<button type="button" class="list-group-item list-group-item-action py-2 dispatch-pick-btn" '
                  + 'data-ticket-id="' + inc.id + '" '
                  + 'data-case-num="' + _escHtml(caseNum) + '">'
                  + sevDot
                  + '<span class="font-monospace small text-primary me-2">' + _escHtml(caseNum) + '</span>'
                  + '<span class="fw-semibold">' + _escHtml(scope) + '</span>'
                  + (loc ? '<br><span class="text-body-secondary small ms-3">' + _escHtml(loc) + '</span>' : '')
                  + '</button>';
        }
        html += '</div>';
        bodyEl.innerHTML = html;
        var btns = bodyEl.querySelectorAll('.dispatch-pick-btn');
        for (var b = 0; b < btns.length; b++) {
            btns[b].addEventListener('click', function () {
                var ticketId = parseInt(this.getAttribute('data-ticket-id'), 10);
                var caseNum = this.getAttribute('data-case-num');
                _submitDispatchAssignment(respId, handle, ticketId, caseNum);
            });
        }
    }

    function _submitDispatchAssignment(respId, handle, ticketId, caseNum) {
        // Post to the same endpoint the per-assignment action bar
        // uses. action=assign creates a new assigns row in 'dispatched'
        // state with the current timestamp. Error/success pattern
        // matches the rest of the modal flow in this file: alert() on
        // failure, hide modal + refresh widgets on success.
        fetch('api/incident-assign.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assign',
                ticket_id: ticketId,
                responder_id: respId,
                csrf_token: _csrf()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Failed to dispatch ' + handle + ' to ' + caseNum + ': ' + data.error);
                return;
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('responderActionModal')).hide();
            // Refresh the responders + incidents widgets to reflect
            // the new assignment immediately.
            onWidgetRefresh({ widget: 'responders' });
            onWidgetRefresh({ widget: 'incidents' });
        })
        .catch(function (err) {
            alert('Dispatch failed: ' + (err.message || String(err)));
        });
    }

    function _openResponderModal(respId, mode) {
        var resp = _lastRespondersData.find(function (r) {
            return parseInt(r.id, 10) === parseInt(respId, 10);
        });
        if (!resp) {
            window.location.href = 'unit-detail.php?id=' + respId;
            return;
        }
        var modalEl = document.getElementById('responderActionModal');
        var titleEl = document.getElementById('responderActionModalTitle');
        var bodyEl = document.getElementById('responderActionModalBody');
        if (!modalEl || !bodyEl || !titleEl) return;
        var handle = resp.handle || resp.name || ('unit #' + respId);
        titleEl.textContent = (mode === 'status' ? 'Status — ' : 'Note — ') + handle;
        if (mode === 'note') {
            _renderNoteModalBody(resp, bodyEl);
        } else {
            _renderStatusModalBody(resp, bodyEl);
        }
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }

    function _renderNoteModalBody(resp, bodyEl) {
        var assignedTickets = resp.assigned_tickets || [];
        var handle = resp.handle || resp.name || 'unit';
        var prefill = 'From ' + handle + ': ';
        // Common note textarea
        var html = '<label class="form-label form-label-sm">Note text</label>'
                 + '<textarea id="respNoteText" class="form-control form-control-sm mb-3" rows="3" placeholder="Type the note...">'
                 + _escHtml(prefill) + '</textarea>';

        if (assignedTickets.length === 0) {
            // Phase 99n-v2 fix: no longer alert-and-redirect. Note
            // lands on the unit itself via api/responder-note.php.
            html += '<div class="alert alert-info py-1 small mb-2">'
                 + '<i class="bi bi-info-circle me-1"></i>'
                 + handle + ' is not assigned to any incident. The note will be recorded against the unit.'
                 + '</div>';
            html += '<button class="btn btn-sm btn-success" id="respNoteUnitBtn">'
                 + '<i class="bi bi-check2 me-1"></i>Add note to unit</button>';
        } else {
            html += '<div class="small text-body-secondary mb-2">'
                 + 'Pick the incident to attach the note to:'
                 + '</div>';
            html += '<div class="list-group">';
            assignedTickets.forEach(function (t) {
                var label = '#' + t.ticket_id + (t.ticket_scope ? ' — ' + _escHtml(t.ticket_scope) : '');
                var step = _currentAssignStep(t);
                html += '<button class="list-group-item list-group-item-action py-1 resp-note-incident-btn" '
                     +  'data-ticket-id="' + t.ticket_id + '">'
                     +  '<span class="fw-semibold">' + label + '</span>'
                     +  ' <span class="badge bg-secondary ms-2" style="font-size:0.65rem;">' + step + '</span>'
                     +  '</button>';
            });
            html += '</div>';
        }
        bodyEl.innerHTML = html;

        // Wire buttons
        // Phase 99n-v3-fix (Eric beta 2026-06-29): success check uses
        // `!data.error` instead of `data.success || data.ok`. Endpoints
        // are inconsistent — incident-update.php returns success:true,
        // responder-note.php returns success:true, but responder-status.php
        // returns only {message:...} (no success field). The only thing
        // every endpoint sets uniformly on FAILURE is `error` (via
        // json_error). So treat absence-of-error as success.
        var unitBtn = bodyEl.querySelector('#respNoteUnitBtn');
        if (unitBtn) {
            unitBtn.addEventListener('click', function () {
                var txt = (bodyEl.querySelector('#respNoteText').value || '').trim();
                if (!txt) { alert('Note text is required.'); return; }
                fetch('api/responder-note.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        responder_id: resp.id, note: txt, csrf_token: _csrf()
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { alert(data.error); return; }
                    showBriefToast('Note recorded for ' + handle);
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('responderActionModal')).hide();
                })
                .catch(function () { alert('Network error adding note'); });
            });
        }
        bodyEl.querySelectorAll('.resp-note-incident-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tid = parseInt(this.getAttribute('data-ticket-id'), 10);
                var txt = (bodyEl.querySelector('#respNoteText').value || '').trim();
                if (!txt) { alert('Note text is required.'); return; }
                fetch('api/incident-update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_note',
                        ticket_id: tid,
                        note: txt,
                        csrf_token: _csrf()
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { alert(data.error); return; }
                    // #86 — the server's message already references the case number.
                    showBriefToast(data.message || ('Note added to incident #' + tid));
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('responderActionModal')).hide();
                })
                .catch(function () { alert('Network error adding note'); });
            });
        });
    }

    /**
     * Returns the human label for the unit's progress through an
     * assignment (dispatched -> responding -> on_scene). 'Pending'
     * if none of the timestamps are set.
     */
    function _currentAssignStep(t) {
        if (t.on_scene && _isMeaningfulTs(t.on_scene)) return 'On Scene';
        if (t.responding && _isMeaningfulTs(t.responding)) return 'Responding';
        if (t.dispatched && _isMeaningfulTs(t.dispatched)) return 'Dispatched';
        return 'Pending';
    }
    function _isMeaningfulTs(s) {
        // Legacy DB convention: '0000-00-00 00:00:00' and NULL both mean "unset".
        if (!s) return false;
        if (String(s).indexOf('0000-00-00') === 0) return false;
        return true;
    }

    function _renderStatusModalBody(resp, bodyEl) {
        var assignedTickets = resp.assigned_tickets || [];
        var handle = resp.handle || resp.name || 'unit';
        // Phase 104a (a beta tester GH #19) — Clear stopped being a hardcoded
        // step and got folded back into a proper status change routed
        // through /api/responder-status.php. The button is still shown
        // per-assignment so it fits the existing UI, but its label is
        // now the actual configured clear-mapped status name (usually
        // "Available") pulled from un_status where incident_action='clear'.
        // Clicking it fires the same status-change path the /s command
        // uses, which walks every open assignment for the responder and
        // stamps assigns.clear via responder_set_status_internal()'s
        // Phase 25 incident_action='clear' branch — audit log, geofence,
        // bed automation, etc. all run cleanly. Responding / On Scene
        // stay per-assignment (a unit can genuinely be Responding to A
        // while still On Scene at B).
        var stepKeys = ['responding', 'on_scene', 'clear'];
        var stepLabels = { responding: 'Responding', on_scene: 'On Scene', clear: 'Clear' };
        var stepColors = { responding: 'warning', on_scene: 'info', clear: 'success' };

        var html = '';
        if (assignedTickets.length === 0) {
            html += '<div class="alert alert-info py-1 small mb-2">'
                 + '<i class="bi bi-info-circle me-1"></i>'
                 + handle + ' is not assigned to any incident. Change the unit\'s overall status:'
                 + '</div>';
            html += '<div id="respUnitStatusList" class="d-flex flex-wrap gap-1">'
                 + '<div class="text-body-secondary small">Loading statuses…</div></div>';
        } else {
            html += '<div class="small text-body-secondary mb-2">'
                 + 'Most recently updated assignment first. Click a button to advance the unit\'s status on that incident:'
                 + '</div>';
            html += '<div class="list-group mb-3">';
            assignedTickets.forEach(function (t) {
                var label = '#' + t.ticket_id + (t.ticket_scope ? ' — ' + _escHtml(t.ticket_scope) : '');
                var step = _currentAssignStep(t);
                html += '<div class="list-group-item py-1">'
                     +    '<div class="d-flex align-items-center gap-2 mb-1">'
                     +      '<span class="fw-semibold small">' + label + '</span>'
                     +      '<span class="badge bg-secondary" style="font-size:0.65rem;">' + step + '</span>'
                     +    '</div>'
                     +    '<div class="d-flex flex-wrap gap-1">';
                stepKeys.forEach(function (k) {
                    var disabled = '';
                    var current = _currentAssignStep(t).toLowerCase();
                    // Prevent regressing or repeating the current step.
                    if (k === 'responding' && (current === 'responding' || current === 'on scene')) disabled = ' disabled';
                    else if (k === 'on_scene' && current === 'on scene') disabled = ' disabled';
                    html += '<button class="btn btn-xs btn-outline-' + stepColors[k] +
                             ' resp-assign-step-btn"' + disabled +
                             ' data-assign-id="' + t.assign_id + '"' +
                             ' data-ticket-id="' + t.ticket_id + '"' +
                             ' data-step="' + k + '">' + stepLabels[k] + '</button>';
                });
                html += '</div></div>';
            });
            html += '</div>';
            html += '<details><summary class="small text-body-secondary mb-1">Or change the unit\'s overall status</summary>'
                 + '<div id="respUnitStatusList" class="d-flex flex-wrap gap-1 mt-1">'
                 + '<div class="text-body-secondary small">Loading statuses…</div></div></details>';
        }
        bodyEl.innerHTML = html;

        // Lazy-load un_status options for the unit-status section.
        _loadUnStatusOptions().then(function (options) {
            var listEl = bodyEl.querySelector('#respUnitStatusList');
            if (!listEl) return;
            var oh = options.map(function (o) {
                var color = o.bg_color || '#6c757d';
                return '<button class="btn btn-xs resp-unit-status-btn"'
                     + ' style="background:' + color + ';color:' + (o.text_color || '#fff') + ';"'
                     + ' data-status-id="' + o.id + '"'
                     + ' data-status-val="' + _escHtml(o.status_val) + '">' + _escHtml(o.status_val) + '</button>';
            }).join('');
            listEl.innerHTML = oh || '<div class="text-body-secondary small">No statuses available.</div>';

            listEl.querySelectorAll('.resp-unit-status-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var sid = parseInt(this.getAttribute('data-status-id'), 10);
                    var statusOpt = options.find(function (o) {
                        return parseInt(o.id, 10) === sid;
                    });
                    // Phase 99n-v3 (Eric beta 2026-06-29): if this status
                    // has Phase 95 extra_data config (Transporting wants
                    // a facility, Out of Service wants a reason note,
                    // etc.), open the extra-data sub-prompt before
                    // submitting. Otherwise submit immediately.
                    var edType = statusOpt && statusOpt.extra_data_type;
                    if (edType && edType !== 'none') {
                        _openExtraDataPrompt(resp, statusOpt, function (extraData) {
                            _postUnitStatus(resp, statusOpt, extraData);
                        });
                    } else {
                        _postUnitStatus(resp, statusOpt, null);
                    }
                });
            });
        });

        // Per-assignment step buttons. Phase 99n-v3-fix (Eric beta
        // 2026-06-29): include ticket_id in the payload — api/incident-
        // assign.php enforces a hard ticket_id check BEFORE dispatching
        // by action, so a payload with only assign_id returns "Invalid
        // ticket ID". The widget already knows the ticket_id from the
        // assigned_tickets payload; the button's data-ticket-id attr
        // carries it through.
        bodyEl.querySelectorAll('.resp-assign-step-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var aid = parseInt(this.getAttribute('data-assign-id'), 10);
                var tid = parseInt(this.getAttribute('data-ticket-id'), 10);
                var step = this.getAttribute('data-step');
                this.disabled = true;

                // Phase 104a (a beta tester GH #19) — Clear routes through the
                // proper status change instead of the per-assign
                // shortcut. Responding / On Scene still use the
                // per-assign path (a unit can legitimately be on
                // different steps on different incidents at once).
                if (step === 'clear') {
                    _loadUnStatusOptions().then(function (options) {
                        // Find the configured clear-mapped status.
                        // Phase 25's incident_action='clear' seed lives
                        // on Available/Clear/In-Service — the first
                        // match wins. If none is configured, we fall
                        // back to the old per-assign path so the
                        // button still functions.
                        var clearStatus = options.find(function (o) {
                            return (o.extra_data_type !== undefined)
                                && ((options && options.length) && false);
                        });
                        // Real lookup: prefer un_status.incident_action='clear'.
                        // api/responders.php exposes incident_action alongside
                        // status_val — pick the first one whose incident_action
                        // resolves to 'clear' either directly (server included
                        // it in the payload) or by name-matching Available/Clear.
                        clearStatus = null;
                        options.forEach(function (o) {
                            if (clearStatus) return;
                            var name = (o.status_val || '').toLowerCase();
                            // Server may or may not surface incident_action;
                            // if it does, trust it. Otherwise infer from name.
                            if (o.incident_action === 'clear') { clearStatus = o; return; }
                            if (name === 'available' || name === 'clear' || name === 'in service') {
                                clearStatus = o;
                            }
                        });
                        if (!clearStatus) {
                            // No clear-mapped status — fall back to legacy
                            // per-assign clear so the dispatcher isn't
                            // stuck. Surface a hint in the console for
                            // ops: their un_status needs a row with
                            // incident_action='clear'.
                            console.warn('[status-modal] No un_status has incident_action=clear; ' +
                                'falling back to per-assign clear. ' +
                                'Fix by running sql/run_phase25_un_status_incident_action.php ' +
                                'or by editing an Available-like status in Settings.');
                            return _legacyPerAssignClear(btn, aid, tid, handle);
                        }
                        // Proper status change — walks all open assigns,
                        // stamps clear on each, drops the responder into
                        // the mapped status. Extra_data validation runs
                        // if the mapped status needs it (which is rare —
                        // Available shouldn't require extra data — but if
                        // an admin configured it that way we need to
                        // prompt or the change will fail server-side).
                        var edType = clearStatus.extra_data_type;
                        var edRequired = parseInt(clearStatus.extra_data_required, 10) === 1;
                        if (edType && edType !== 'none' && edRequired) {
                            return _openExtraDataPrompt(resp, clearStatus, function (extra) {
                                if (extra === null) { btn.disabled = false; return; }
                                _postUnitStatus(resp, clearStatus, extra);
                                showBriefToast(handle + ' → ' + clearStatus.status_val);
                                EventBus.emit('widget:refresh', { widget: 'responders' });
                            });
                        }
                        _postUnitStatus(resp, clearStatus, null);
                        showBriefToast(handle + ' → ' + clearStatus.status_val);
                        EventBus.emit('widget:refresh', { widget: 'responders' });
                    });
                    return;
                }

                // Responding / On Scene — per-assign path unchanged.
                fetch('api/incident-assign.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update_status',
                        ticket_id: tid,
                        assign_id: aid,
                        new_status: step,
                        csrf_token: _csrf()
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) {
                        alert(data.error); btn.disabled = false;
                    } else {
                        showBriefToast(handle + ' → ' + step);
                        // Keep modal open so dispatcher can update the
                        // OTHER assignment too (Eric's core use case:
                        // clear from one, responding to next).
                        EventBus.emit('widget:refresh', { widget: 'responders' });
                    }
                })
                .catch(function () { alert('Network error'); btn.disabled = false; });
            });
        });
    }

    function _legacyPerAssignClear(btn, aid, tid, handle) {
        fetch('api/incident-assign.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update_status',
                ticket_id: tid,
                assign_id: aid,
                new_status: 'clear',
                csrf_token: _csrf()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { alert(data.error); btn.disabled = false; }
            else {
                showBriefToast(handle + ' cleared');
                EventBus.emit('widget:refresh', { widget: 'responders' });
            }
        })
        .catch(function () { alert('Network error'); btn.disabled = false; });
    }

    function _loadUnStatusOptions() {
        if (_unStatusCache) return Promise.resolve(_unStatusCache);
        return fetch('api/responders.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var opts = (data.statuses || []).filter(function (s) {
                    return !s.hide || s.hide === 'n';
                });
                _unStatusCache = opts;
                return opts;
            })
            .catch(function () { return []; });
    }

    // Phase 99n-v3 (Eric beta 2026-06-29) — extra_data sub-prompt.
    // Shapes the secondary input modal-body based on the status's
    // configured extra_data_type, then calls the supplied done()
    // with {type, value} on submit (or null on cancel).
    var _facilityCache = null;
    function _loadFacilityOptions() {
        if (_facilityCache) return Promise.resolve(_facilityCache);
        return fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                _facilityCache = data.facilities || data.rows || [];
                return _facilityCache;
            })
            .catch(function () { return []; });
    }

    function _postUnitStatus(resp, statusOpt, extraData) {
        var body = {
            responder_id: resp.id,
            status_id: statusOpt.id,
            csrf_token: _csrf()
        };
        if (extraData) body.extra_data = extraData;
        fetch('api/responder-status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            // responder-status.php returns {message, status_id,
            // status_name, ...} on success — no `success` field.
            // Treat absence of `error` as success.
            if (data.error) {
                alert(data.error);
            } else {
                showBriefToast((resp.handle || resp.name || 'Unit') + ' → ' + statusOpt.status_val);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('responderActionModal')).hide();
                EventBus.emit('widget:refresh', { widget: 'responders' });
            }
        })
        .catch(function () { alert('Network error'); });
    }

    /**
     * Open a secondary input collection on the same modal for the
     * status's extra_data_type. done(extraDataPayload) is invoked
     * with {type, value} on Apply. Cancel re-renders the status
     * picker so the dispatcher can pick a different status.
     */
    function _openExtraDataPrompt(resp, statusOpt, done) {
        var bodyEl = document.getElementById('responderActionModalBody');
        var titleEl = document.getElementById('responderActionModalTitle');
        if (!bodyEl) return;
        var label = statusOpt.extra_data_label || statusOpt.status_val;
        var required = !!statusOpt.extra_data_required;
        var type = statusOpt.extra_data_type;
        var handle = resp.handle || resp.name || 'Unit';
        if (titleEl) titleEl.textContent = handle + ' → ' + statusOpt.status_val;

        var html = '<div class="alert alert-info py-1 small mb-2">'
                 + '<i class="bi bi-info-circle me-1"></i>'
                 + 'Setting status to <strong>' + _escHtml(statusOpt.status_val) + '</strong> needs extra info'
                 + (required ? ' (required).' : ' (optional).')
                 + '</div>'
                 + '<label class="form-label form-label-sm">'
                 + _escHtml(label)
                 + (required ? ' <span class="text-danger">*</span>' : '')
                 + '</label>';

        if (type === 'note') {
            html += '<textarea id="edInput" class="form-control form-control-sm mb-3" rows="3"></textarea>';
        } else if (type === 'numeric') {
            html += '<input type="number" id="edInput" class="form-control form-control-sm mb-3" step="any">';
        } else if (type === 'mileage') {
            html += '<input type="number" id="edInput" class="form-control form-control-sm mb-3" min="0" step="1" placeholder="Odometer reading">';
        } else if (type === 'facility') {
            html += '<select id="edInput" class="form-select form-select-sm mb-3"><option value="">— Loading facilities… —</option></select>';
        } else if (type === 'location') {
            // Use the unit's current lat/lng as the default — most
            // common case is "stamp the unit's current position onto
            // the incident". Dispatcher can edit before submit.
            var lat = resp.lat || '';
            var lng = resp.lng || '';
            html += '<div class="row g-2 mb-3">'
                  + '<div class="col"><input type="number" id="edLat" class="form-control form-control-sm" placeholder="Lat" step="any" value="' + lat + '"></div>'
                  + '<div class="col"><input type="number" id="edLng" class="form-control form-control-sm" placeholder="Lng" step="any" value="' + lng + '"></div>'
                  + '</div>';
        } else {
            html += '<input type="text" id="edInput" class="form-control form-control-sm mb-3">';
        }

        html += '<div class="d-flex gap-2">'
              + '<button class="btn btn-sm btn-primary" id="edApply">'
              + '<i class="bi bi-check2 me-1"></i>Apply</button>';
        if (!required) {
            html += '<button class="btn btn-sm btn-outline-secondary" id="edSkip">'
                  + 'Skip (set status without data)</button>';
        }
        html += '<button class="btn btn-sm btn-outline-secondary ms-auto" id="edBack">'
              + '<i class="bi bi-arrow-left me-1"></i>Back</button>'
              + '</div>';
        bodyEl.innerHTML = html;

        // Lazy-load facility options
        if (type === 'facility') {
            _loadFacilityOptions().then(function (facs) {
                var sel = bodyEl.querySelector('#edInput');
                if (!sel) return;
                var opts = '<option value="">— Select a facility —</option>';
                facs.forEach(function (f) {
                    opts += '<option value="' + f.id + '">' + _escHtml(f.name) + '</option>';
                });
                sel.innerHTML = opts;
            });
        }

        function collect() {
            if (type === 'location') {
                var lat = parseFloat(bodyEl.querySelector('#edLat').value);
                var lng = parseFloat(bodyEl.querySelector('#edLng').value);
                if (isNaN(lat) || isNaN(lng)) return null;
                return [lat, lng];
            }
            var el = bodyEl.querySelector('#edInput');
            if (!el) return null;
            var v = el.value;
            if (type === 'numeric' || type === 'mileage' || type === 'facility') {
                if (v === '' || v == null) return null;
                return parseFloat(v) || (type === 'facility' ? parseInt(v, 10) : null);
            }
            return v;
        }

        bodyEl.querySelector('#edApply').addEventListener('click', function () {
            var v = collect();
            if (required && (v === null || v === '' || (Array.isArray(v) && !v.length))) {
                alert('This field is required.');
                return;
            }
            done({ type: type, value: v });
        });
        var skip = bodyEl.querySelector('#edSkip');
        if (skip) skip.addEventListener('click', function () { done(null); });
        bodyEl.querySelector('#edBack').addEventListener('click', function () {
            _renderStatusModalBody(resp, bodyEl);
        });
    }

    function executeAction(actionName) {
        if (!selectedId || selectedType !== 'incident') return;
        var id = selectedId;
        switch (actionName) {
            case 'dispatch':
            case 'units':
                window.location.href = 'incident-detail.php?id=' + id + '&tab=assign';
                break;
            case 'view':
                window.location.href = 'incident-detail.php?id=' + id;
                break;
            case 'edit':
                window.location.href = 'incident-detail.php?id=' + id + '&tab=edit';
                break;
            case 'popup':
                window.open('incident-detail.php?id=' + id, 'incident_' + id,
                    'width=900,height=700,scrollbars=yes,resizable=yes');
                break;
            case 'close':
                // #86 — reference the incident by its case number (e.g. 2026-0045),
                // not the internal DB row id. The selected row carries data-case-num.
                var _closeRow = document.querySelector('tr[data-id="' + id + '"]');
                var _closeNum = (_closeRow && _closeRow.getAttribute('data-case-num')) || ('#' + id);
                if (confirm('Close incident ' + _closeNum + '?')) {
                    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    var csrf = csrfMeta ? csrfMeta.content : '';
                    fetch('api/incident-update.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'update_status',
                            ticket_id: id,
                            new_status: 1,
                            csrf_token: csrf
                        })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (result) {
                        if (result.success) {
                            EventBus.emit('selection:cleared');
                            EventBus.emit('widget:refresh', { widget: 'incidents' });
                        } else {
                            alert(result.error || 'Failed to close incident');
                        }
                    })
                    .catch(function () { alert('Network error'); });
                }
                break;
        }
    }

    // ── Row selection config: type → { bodyId, rowClass, event } ──
    // Used by the generic selectByOffset/selectFirst methods below.
    var ROW_CONFIG = {
        incident:  { bodyId: 'incidentsBody',   rowClass: 'incident-row',   event: 'incident:selected' },
        responder: { bodyId: 'respondersBody',  rowClass: 'responder-row',  event: 'responder:selected' },
        facility:  { bodyId: 'facilitiesBody',  rowClass: 'facility-row',   event: 'facility:selected' }
    };

    function emitRowSelection(row, eventName) {
        EventBus.emit(eventName, {
            id:  parseInt(row.dataset.id),
            lat: parseFloat(row.dataset.lat),
            lng: parseFloat(row.dataset.lng)
        });
    }

    // Expose for keyboard-nav.js
    window.DashboardActions = {
        executeAction: executeAction,
        executeResponderAction: executeResponderAction,   // Phase 99n
        executeFacilityAction: executeFacilityAction,     // Phase 115
        // Phase 99r (a beta tester beta 2026-06-29) — surface the unit-status
        // helpers so the command bar (/s <handle> <status>) can drive
        // the same flow the modal uses. Keeps the modal as the
        // canonical implementation; command bar is a thin wrapper.
        getRespondersSnapshot: function () { return _lastRespondersData || []; },
        loadUnStatusOptions: _loadUnStatusOptions,
        postUnitStatus: _postUnitStatus,
        getSelectedId: function () { return selectedId; },
        getSelectedType: function () { return selectedType; },
        clearSelection: function () {
            EventBus.emit('selection:cleared');
        },

        /**
         * Generic: move selection by offset (+1 = down, -1 = up) in any widget.
         * @param {string} type  'incident', 'responder', or 'facility'
         * @param {number} offset  Direction (+1 or -1), wraps around
         */
        selectByOffset: function (type, offset) {
            var cfg = ROW_CONFIG[type];
            if (!cfg) return;
            var rows = document.querySelectorAll('#' + cfg.bodyId + ' .' + cfg.rowClass);
            if (!rows.length) return;
            var currentIdx = -1;
            rows.forEach(function (row, i) {
                if (parseInt(row.dataset.id) === selectedId && selectedType === type) {
                    currentIdx = i;
                }
            });
            var newIdx = currentIdx + offset;
            if (newIdx < 0) newIdx = rows.length - 1;
            if (newIdx >= rows.length) newIdx = 0;
            emitRowSelection(rows[newIdx], cfg.event);
        },

        /**
         * Generic: select the first row in any widget.
         * @param {string} type  'incident', 'responder', or 'facility'
         */
        selectFirst: function (type) {
            var cfg = ROW_CONFIG[type];
            if (!cfg) return;
            var row = document.querySelector('#' + cfg.bodyId + ' .' + cfg.rowClass);
            if (row) {
                emitRowSelection(row, cfg.event);
            }
        },

        // Legacy aliases (used by older code / action buttons)
        selectIncidentByOffset: function (n) { this.selectByOffset('incident', n); },
        selectFirstIncident:    function ()  { this.selectFirst('incident'); }
    };

    function onResponderSelected(data) {
        selectedId = data.id;
        selectedType = 'responder';
        selectRow('respondersBody', data.id);
        // Phase 99n (Eric beta 2026-06-29) — show the responder action
        // bar so the dispatcher can press V/E/D/N for quick edits.
        // Hide the incident action bar in case the user switched
        // selection from an incident row.
        showActionBar(false);
        showResponderActionBar(true);
        showFacilityActionBar(false); // Phase 115
        if (data.lat && data.lng) {
            highlightMarker(data.lat, data.lng);
            if (map) map.setView([data.lat, data.lng], 15);
        } else {
            clearMarkerHighlight();
            resetMapToOverview();
            showBriefToast('No location available');
        }
    }

    function onFacilitySelected(data) {
        selectedId = data.id;
        selectedType = 'facility';
        selectRow('facilitiesBody', data.id);
        // Phase 115 — reveal the facility action bar; hide the others in
        // case selection switched from an incident/responder.
        showActionBar(false);
        showResponderActionBar(false);
        showFacilityActionBar(true);
        if (data.lat && data.lng) {
            highlightMarker(data.lat, data.lng);
            if (map) map.setView([data.lat, data.lng], 15);
        } else {
            clearMarkerHighlight();
            resetMapToOverview();
            showBriefToast('No location available');
        }
    }

    /** Zoom map back to the overview bounds (all markers). */
    function resetMapToOverview() {
        if (map && lastBounds && lastBounds.isValid()) {
            map.fitBounds(lastBounds, { padding: [60, 60], maxZoom: 13 });
        }
    }

    /**
     * Show a brief floating toast message that fades out after 2 seconds.
     */
    function showBriefToast(msg) {
        var el = document.getElementById('briefToast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'briefToast';
            el.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);'
                + 'padding:6px 16px;border-radius:6px;font-size:0.85rem;z-index:9999;'
                + 'pointer-events:none;opacity:0;transition:opacity 0.3s;'
                + 'background:var(--bs-body-color);color:var(--bs-body-bg);';
            document.body.appendChild(el);
        }
        el.textContent = msg;
        el.style.opacity = '1';
        clearTimeout(el._timer);
        el._timer = setTimeout(function () { el.style.opacity = '0'; }, 2000);
    }

    function onWidgetShown(data) {
        // Re-render with cached data
        var cached = DataService.getCache(data.widget);
        if (cached) {
            var obj = {};
            obj[data.widget] = cached;
            renderAll(obj);
        }
        if (data.widget === 'map') {
            setTimeout(function () {
                if (map) map.invalidateSize();
            }, 300);
        }
    }

    function onWidgetRefresh(data) {
        DataService.fetchJSON('api/' + data.widget + '.php')
            .then(function (result) {
                var obj = {};
                obj[data.widget] = result;
                renderAll(obj);
            })
            .catch(function (err) {
                console.error('Refresh error:', err);
            });
    }

    // ── Utilities ──────────────────────────────────────────────────────

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function formatTime(dateStr) {
        if (!dateStr) return '-';
        try {
            var d = new Date(dateStr);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateStr;
        }
    }

    function formatUpdatedTime(dateStr) {
        if (!dateStr) return '-';
        try {
            var d = new Date(dateStr);
            var now = new Date();
            var diffMs = now - d;
            var elapsed = formatElapsed(diffMs);
            var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

            if (diffMs > 86400000) {
                // Over 24 hours — show compact date + time in smaller font
                var date = (d.getMonth() + 1) + '/' + d.getDate();
                return '<span class="updated-old" data-bs-toggle="tooltip" data-bs-delay=\'{"show":100,"hide":0}\' title="' + elapsed + ' ago">'
                    + date + ' ' + time + '</span>';
            }
            return '<span data-bs-toggle="tooltip" data-bs-delay=\'{"show":100,"hide":0}\' title="' + elapsed + ' ago">' + time + '</span>';
        } catch (e) {
            return dateStr;
        }
    }

    function formatElapsed(ms) {
        var totalMin = Math.floor(ms / 60000);
        var d = Math.floor(totalMin / 1440);
        var h = Math.floor((totalMin % 1440) / 60);
        var m = totalMin % 60;
        var parts = [];
        if (d > 0) parts.push(d + 'd');
        if (h > 0) parts.push(h + 'h');
        parts.push(m + 'm');
        return parts.join(' ');
    }

    function formatSeconds(secs) {
        if (!secs || secs <= 0) return '-';
        var m = Math.floor(secs / 60);
        var s = Math.floor(secs % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ── Organization Switcher ─────────────────────────────────────────
    function bindOrgSwitcher() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest('.org-switch-link');
            if (!link) return;
            e.preventDefault();

            var orgId = link.getAttribute('data-org-id');
            if (!orgId) return;

            fetch('api/organizations.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_active_org', org_id: parseInt(orgId) })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                }
            })
            .catch(function () {});
        });
    }

    // ── Init ───────────────────────────────────────────────────────────

    // ── Expiration Reminders ────────────────────────────────────────
    function checkExpirationAlerts() {
        fetch('api/compliance.php?action=my_alerts')
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.alerts || data.alerts.length === 0) return;
                renderExpirationBanner(data.alerts);
            })
            .catch(function () { /* non-fatal */ });
    }

    function renderExpirationBanner(alerts) {
        var container = document.getElementById('expirationAlerts');
        if (!container) {
            container = document.createElement('div');
            container.id = 'expirationAlerts';
            var main = document.querySelector('main') || document.querySelector('.container-fluid');
            if (main) main.parentNode.insertBefore(container, main);
        }

        var html = '';
        for (var i = 0; i < alerts.length; i++) {
            var a = alerts[i];
            var cls = a.expired ? 'alert-danger' : 'alert-warning';
            var icon = a.expired ? 'bi-exclamation-triangle-fill' : 'bi-clock-history';
            var label = a.expired ? 'EXPIRED' : 'Expires in ' + a.days_remaining + ' day' + (a.days_remaining !== 1 ? 's' : '');
            var req = a.required ? ' <span class="badge bg-danger" style="font-size:0.6rem;">Required</span>' : '';

            html += '<div class="alert ' + cls + ' alert-dismissible py-2 mb-1 d-flex align-items-center" style="font-size:0.85rem;" role="alert">' +
                '<i class="bi ' + icon + ' me-2"></i>' +
                '<strong>' + escHtml(a.cert_name) + '</strong>' + req +
                ' &mdash; ' + label +
                '<div class="ms-auto d-flex gap-1">' +
                '<button class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;" onclick="window._snoozeAlert(' + a.cert_mc_id + ', 4)" title="Snooze 4 hours">4h</button>' +
                '<button class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;" onclick="window._snoozeAlert(' + a.cert_mc_id + ', 24)" title="Snooze 1 day">1d</button>' +
                '<button class="btn btn-sm btn-outline-secondary" style="font-size:0.7rem;" onclick="window._snoozeAlert(' + a.cert_mc_id + ', 336)" title="Snooze 2 weeks">2w</button>' +
                '</div>' +
                '<button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    // Global snooze handler
    window._snoozeAlert = function (certMcId, hours) {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';
        fetch('api/compliance.php?action=snooze', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({ cert_mc_id: certMcId, hours: hours, csrf_token: csrf })
        }).then(function () {
            // Remove the alert from DOM
            var container = document.getElementById('expirationAlerts');
            if (container) {
                // Re-check alerts (will omit snoozed ones)
                checkExpirationAlerts();
            }
        }).catch(function () {});
    };

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); bindOrgSwitcher(); checkExpirationAlerts(); });
    } else {
        boot();
        bindOrgSwitcher();
        checkExpirationAlerts();
    }
})();
