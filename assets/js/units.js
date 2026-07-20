(function () {
    'use strict';

    var map = null;
    var markers = [];
    var markerGroup = null;
    var allUnits = [];
    var currentFilter = 'all';
    var currentZone = 'all';   // Phase 115 (#64) — Units queue zone filter
    var searchQuery = '';
    var screenPrefs = null;       // Phase 17 — loaded once on init
    var countdownTimer = null;    // Refreshes PAR countdown cells

    // Phase 118 (#89) — client-side pagination. Page size defaults to the
    // admin setting (settings.page_size → window.LIST_PAGE_SIZE); the on-page
    // Rows selector overrides it for this view only. pagerSize 0 = show all.
    var pagerPage = 1;
    var pagerSize = (window.LIST_PAGE_SIZE && window.LIST_PAGE_SIZE > 0) ? window.LIST_PAGE_SIZE : 50;
    var pagerLastSig = null;      // filter signature; a change resets to page 1

    // ── Initialization ──
    function init() {
        initPager();   // Phase 118 — wire the pagination footer (static DOM)
        // Phase 17 — load this user's column prefs before first render.
        if (window.ScreenPrefs) {
            window.ScreenPrefs.load('units').then(function (p) {
                screenPrefs = p || { columns: [], sort: { col: 'name', dir: 'asc' } };
                loadUnits();
                initSearch();
                initFilterButtons();
                initZoneFilter();
                initCustomizeButton();
                // Tick the PAR countdown column every second.
                countdownTimer = setInterval(function () {
                    document.querySelectorAll('[data-par-due]').forEach(function (el) {
                        var ts = parseInt(el.getAttribute('data-par-due'), 10);
                        el.textContent = friendlyCountdown(ts);
                    });
                    document.querySelectorAll('[data-par-last]').forEach(function (el) {
                        var ts = parseInt(el.getAttribute('data-par-last'), 10);
                        el.textContent = friendlyTimeAgo(ts);
                    });
                }, 1000);
            });
        } else {
            // Fallback: defaults only, no customization
            screenPrefs = defaultPrefs();
            loadUnits();
            initSearch();
            initFilterButtons();
            initZoneFilter();
        }
    }

    function defaultPrefs() {
        return {
            columns: [
                { id: 'name',             label: 'Name',                          visible: true,  pos: 0 },
                { id: 'handle',           label: 'Handle',                        visible: true,  pos: 1 },
                { id: 'type',             label: 'Type',                          visible: true,  pos: 2 },
                { id: 'status',           label: 'Status',                        visible: true,  pos: 3 },
                { id: 'active',           label: 'Active',                        visible: true,  pos: 4 },
                { id: 'updated',          label: 'Last Updated',                  visible: true,  pos: 5 },
                { id: 'par_last_checkin', label: 'Time since last activity',      visible: false, pos: 6 },
                { id: 'par_next_due',     label: 'Next PAR due',                  visible: false, pos: 7 }
            ],
            sort: { col: 'name', dir: 'asc' }
        };
    }

    function initCustomizeButton() {
        var btn = document.getElementById('btnCustomizeCols');
        if (!btn || !window.ScreenPrefs) return;
        btn.addEventListener('click', function () {
            window.ScreenPrefs.openEditor('units', screenPrefs, function (newPrefs) {
                screenPrefs = newPrefs;
                renderTable();
            });
        });
    }

    function friendlyTimeAgo(ts) {
        if (!ts) return '—';
        var now = Math.floor(Date.now() / 1000);
        var diff = Math.max(0, now - ts);
        if (diff < 60)    return diff + 's ago';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm ago';
        return Math.floor(diff / 86400) + 'd ago';
    }
    function friendlyCountdown(ts) {
        if (!ts) return '—';
        var now = Math.floor(Date.now() / 1000);
        var diff = ts - now;
        if (diff <= 0) return 'OVERDUE';
        var min = Math.floor(diff / 60), sec = diff % 60;
        return (min > 0 ? min + 'm ' : '') + sec + 's';
    }

    // ── Load units from API ──
    function loadUnits() {
        fetch('api/responders.php')
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (data.error) {
                    showAlert(escHtml(data.error), 'danger');
                    document.getElementById('loadingSpinner').classList.add('d-none');
                    return;
                }

                allUnits = data.responders || [];
                document.getElementById('unitCount').textContent = allUnits.length;

                populateZoneFilter();
                renderTable();
                initMap();

                document.getElementById('loadingSpinner').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
            })
            .catch(function (err) {
                showAlert('Failed to load units: ' + escHtml(err.message), 'danger');
                document.getElementById('loadingSpinner').classList.add('d-none');
            });
    }

    // ── Search ──
    function initSearch() {
        var input = document.getElementById('unitSearch');
        if (!input) return;

        var timeout = null;
        input.addEventListener('input', function () {
            if (timeout) clearTimeout(timeout);
            timeout = setTimeout(function () {
                searchQuery = input.value.trim().toLowerCase();
                renderTable();
                updateMapMarkers();
            }, 200);
        });
    }

    // ── Filter buttons ──
    function initFilterButtons() {
        var container = document.getElementById('statusFilter');
        if (!container) return;

        var buttons = container.querySelectorAll('button');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function () {
                for (var j = 0; j < buttons.length; j++) {
                    buttons[j].classList.remove('active');
                }
                this.classList.add('active');
                currentFilter = this.getAttribute('data-filter');
                renderTable();
                updateMapMarkers();
            });
        }
    }

    // ── Zone filter (Phase 115, #64) — populate from the units' current
    //    event zones. Hidden entirely on installs where no unit is in a zone. ──
    function populateZoneFilter() {
        var sel = document.getElementById('unitZoneFilter');
        if (!sel) return;
        var zones = {}, anyNoZone = false, i;
        for (i = 0; i < allUnits.length; i++) {
            var z = allUnits[i].current_zone;
            if (z && z.id) {
                zones[z.id] = z.name || z.code || ('Zone ' + z.id);
            } else {
                anyNoZone = true;
            }
        }
        var ids = Object.keys(zones);
        if (ids.length === 0) { sel.classList.add('d-none'); return; }
        sel.classList.remove('d-none');

        var prev = currentZone;
        sel.innerHTML = '';
        var optAll = document.createElement('option');
        optAll.value = 'all'; optAll.textContent = 'All zones';
        sel.appendChild(optAll);
        ids.sort(function (a, b) { return zones[a].localeCompare(zones[b]); });
        for (i = 0; i < ids.length; i++) {
            var o = document.createElement('option');
            o.value = ids[i]; o.textContent = zones[ids[i]];
            sel.appendChild(o);
        }
        if (anyNoZone) {
            var oNone = document.createElement('option');
            oNone.value = '__none__'; oNone.textContent = '— No zone';
            sel.appendChild(oNone);
        }
        // Preserve the prior choice if it still exists.
        var keep = (prev === 'all') || zones[prev] || (prev === '__none__' && anyNoZone);
        sel.value = keep ? prev : 'all';
        currentZone = sel.value;
    }

    function initZoneFilter() {
        var sel = document.getElementById('unitZoneFilter');
        if (!sel) return;
        sel.addEventListener('change', function () {
            currentZone = this.value;
            renderTable();
            updateMapMarkers();
        });
    }

    /**
     * Classify a unit's status into 'available' | 'in_service' | 'unavailable'
     * | '' (unclassified). GH #68 — driven by the configured un_status.group
     * (the classification field), letters-only normalized, with a status-name
     * fallback so it works regardless of how an install NAMES its statuses
     * ('av', 'AV', 'Available', 'A', …). Order matters: test unavailable
     * before available so 'unav' isn't caught by the 'av' prefix.
     */
    function classifyAvailability(u) {
        // GH #68 round 2 (Eric 2026-07-08): the ADMIN-SET bucket on the
        // unit status (Settings → Unit Statuses → "Units Filter Bucket")
        // is authoritative — no name guessing when it's set. The
        // heuristics below survive only as the fallback for statuses
        // still on "Auto" and for pre-migration installs.
        if (u.units_filter === 'available' || u.units_filter === 'in_service'
            || u.units_filter === 'unavailable') {
            return u.units_filter;
        }
        var g = (u.status_group || '').toLowerCase().replace(/[^a-z]/g, '');
        var n = (u.status_name || '').toLowerCase().replace(/[^a-z]/g, '');
        function isUnavail(s) {
            return s.indexOf('un') === 0 || s.indexOf('off') === 0 || s.indexOf('out') === 0
                || s === 'na' || s.indexOf('oos') === 0;
        }
        function isInServ(s) {
            // GH #68 (a beta tester's real config 2026-07-07): statuses grouped
            // 'call' (Dispatched/Enroute/Arrived/Transporting/...) are
            // working an incident — in service even when the open-
            // assignment check misses them.
            return s.indexOf('inserv') === 0 || s.indexOf('service') === 0 || s === 'is' || s === 'en'
                || s === 'call' || s.indexOf('busy') === 0 || s.indexOf('disp') === 0;
        }
        function isAvail(s) {
            return s.indexOf('av') === 0 || s.indexOf('avail') === 0 || s === 'a' || s === 'rdy' || s.indexOf('ready') === 0;
        }
        if (isUnavail(g) || (g === '' && isUnavail(n))) return 'unavailable';
        if (isInServ(g)  || (g === '' && isInServ(n)))  return 'in_service';
        if (isAvail(g)   || (g === '' && isAvail(n)))   return 'available';
        return '';
    }

    // ── Filter logic ──
    function getFilteredUnits() {
        var filtered = [];
        for (var i = 0; i < allUnits.length; i++) {
            var u = allUnits[i];

            // Search filter
            if (searchQuery) {
                var searchStr = ((u.name || '') + ' ' + (u.handle || '') + ' ' + (u.callsign || '') + ' ' + (u.type_name || '')).toLowerCase();
                if (searchStr.indexOf(searchQuery) === -1) continue;
            }

            // Status filter (GH #68). The old code matched status_name/group
            // against the English substring 'avail'/'unavail', which never
            // matched the SEED groups 'av'/'unav' (or short custom codes like
            // a beta tester's 'AV') — so the Available/Unavailable buttons filtered
            // to nothing on every install. Classify via the configured
            // un_status.group (the intended classification field) with
            // resilient normalization + a status-name fallback.
            if (currentFilter !== 'all') {
                var cls = classifyAvailability(u);
                if (currentFilter === 'in_service') {
                    // On a call OR a status explicitly classified in-service.
                    if (u.active_assignments <= 0 && cls !== 'in_service') continue;
                } else if (cls !== currentFilter) {
                    continue;
                }
            }

            // Zone filter (Phase 115, #64). '__none__' = units not in any zone.
            if (currentZone !== 'all') {
                var zid = u.current_zone ? String(u.current_zone.id) : '__none__';
                if (zid !== currentZone) continue;
            }

            filtered.push(u);
        }
        return filtered;
    }

    // ── Render table (Phase 17 column-aware) ──
    //
    // Renders <thead> + <tbody> from screenPrefs.columns. Each row's
    // <td> content is produced by renderCell() based on column id —
    // the same lookup table that the customize modal exposes.
    function renderTable() {
        var head = document.getElementById('unitsTableHead');
        var tbody = document.getElementById('unitsTableBody');
        if (!tbody || !head) return;

        var visible = (screenPrefs.columns || []).filter(function (c) { return c.visible; });
        // Header
        var hh = '';
        for (var i = 0; i < visible.length; i++) {
            hh += '<th>' + escHtml(visible[i].label) + '</th>';
        }
        head.innerHTML = hh;

        var filtered = getFilteredUnits();

        // Phase 118 — reset to page 1 whenever the filter/search/zone changes
        // (but NOT on a column-customize re-render, which leaves these the same).
        var sig = currentFilter + '|' + currentZone + '|' + searchQuery;
        if (sig !== pagerLastSig) { pagerPage = 1; pagerLastSig = sig; }

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="' + visible.length + '" class="text-center text-body-secondary py-3">No units match the current filter</td></tr>';
            renderPager(0);
            return;
        }

        // Slice the filtered set to the current page (pagerSize 0 = show all).
        var size = (pagerSize > 0) ? pagerSize : filtered.length;
        var pageCount = Math.max(1, Math.ceil(filtered.length / size));
        if (pagerPage > pageCount) pagerPage = pageCount;
        if (pagerPage < 1) pagerPage = 1;
        var pageStart = (pagerPage - 1) * size;
        var pageRows = filtered.slice(pageStart, pageStart + size);

        var html = '';
        for (var r = 0; r < pageRows.length; r++) {
            var u = pageRows[r];
            html += '<tr class="unit-row" data-unit-id="' + u.id + '"' +
                ' data-lat="' + (u.lat || '') + '" data-lng="' + (u.lng || '') + '">';
            for (var c = 0; c < visible.length; c++) {
                html += renderCell(visible[c].id, u);
            }
            html += '</tr>';
        }
        tbody.innerHTML = html;
        renderPager(filtered.length);

        // Bind click handlers: name/handle -> detail, loc icon -> zoom map
        var rows = tbody.querySelectorAll('.unit-row');
        for (var j = 0; j < rows.length; j++) {
            rows[j].addEventListener('click', function (e) {
                var locBtn = e.target.closest('.unit-loc-btn');
                if (locBtn && map) {
                    var lat = parseFloat(this.getAttribute('data-lat'));
                    var lng = parseFloat(this.getAttribute('data-lng'));
                    if (lat && lng) map.setView([lat, lng], 16, { animate: true });
                    return;
                }
                var id = this.getAttribute('data-unit-id');
                window.location.href = 'unit-detail.php?id=' + id;
            });
        }
    }

    // ── Phase 118 (#89) — pagination controls ──
    // Wires the Rows selector + page-nav clicks once. The Rows selector lists
    // the configured default + common sizes + "All"; changing it is a per-view
    // override (does not touch the global setting).
    function initPager() {
        var sizeSel = document.getElementById('unitsPageSize');
        if (sizeSel) {
            var opts = [pagerSize, 25, 50, 100, 200].filter(function (v, i, a) {
                return v > 0 && a.indexOf(v) === i;
            }).sort(function (a, b) { return a - b; });
            var oh = '';
            for (var i = 0; i < opts.length; i++) {
                oh += '<option value="' + opts[i] + '"' + (opts[i] === pagerSize ? ' selected' : '') + '>' + opts[i] + '</option>';
            }
            oh += '<option value="0"' + (pagerSize === 0 ? ' selected' : '') + '>All</option>';
            sizeSel.innerHTML = oh;
            sizeSel.addEventListener('change', function () {
                pagerSize = parseInt(this.value, 10) || 0;
                pagerPage = 1;
                renderTable();
            });
        }
        var nav = document.getElementById('unitsPageNav');
        if (nav) {
            nav.addEventListener('click', function (e) {
                var a = e.target.closest('[data-page]');
                if (!a) return;
                e.preventDefault();
                var p = parseInt(a.getAttribute('data-page'), 10);
                if (!isNaN(p) && p >= 1) { pagerPage = p; renderTable(); }
            });
        }
    }

    // Update the footer summary + page-number nav for the given filtered total.
    function renderPager(total) {
        var pager = document.getElementById('unitsPager');
        var info = document.getElementById('unitsPageInfo');
        var nav = document.getElementById('unitsPageNav');
        if (!pager || !info || !nav) return;

        pager.classList.remove('d-none');
        if (total <= 0) { info.textContent = 'No units to show'; nav.innerHTML = ''; return; }

        var size = (pagerSize > 0) ? pagerSize : total;
        var pageCount = Math.max(1, Math.ceil(total / size));
        if (pagerPage > pageCount) pagerPage = pageCount;
        if (pagerPage < 1) pagerPage = 1;
        var start = (pagerPage - 1) * size;
        var end = Math.min(start + size, total);
        info.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total;

        if (pageCount <= 1) { nav.innerHTML = ''; return; }

        function li(label, page, disabled, active) {
            var cls = 'page-item' + (active ? ' active' : '') + (disabled ? ' disabled' : '');
            var inner = (disabled || active)
                ? '<span class="page-link">' + label + '</span>'
                : '<a class="page-link" href="#" data-page="' + page + '">' + label + '</a>';
            return '<li class="' + cls + '">' + inner + '</li>';
        }
        var h = li('«', pagerPage - 1, pagerPage <= 1, false);
        var from = Math.max(1, pagerPage - 2);
        var to = Math.min(pageCount, pagerPage + 2);
        if (from > 1) { h += li('1', 1, false, false); if (from > 2) h += li('…', 0, true, false); }
        for (var p = from; p <= to; p++) h += li(String(p), p, false, p === pagerPage);
        if (to < pageCount) { if (to < pageCount - 1) h += li('…', 0, true, false); h += li(String(pageCount), pageCount, false, false); }
        h += li('»', pagerPage + 1, pagerPage >= pageCount, false);
        nav.innerHTML = h;
    }

    // 2026-06-28 (Eric beta request) — stale-location indicator.
    // Threshold is configurable per-install via settings.stale_location_threshold_minutes
    // (exposed by inc/navbar.php as window.STALE_LOCATION_MIN); default 30 min.
    var STALE_LOCATION_THRESHOLD_MIN = (window.STALE_LOCATION_MIN && window.STALE_LOCATION_MIN > 0)
        ? window.STALE_LOCATION_MIN : 30;
    function unitLocationFreshness(u) {
        var hasCoords = u.lat && u.lng && !(u.lat === 0 && u.lng === 0);
        if (!hasCoords) {
            return { state: 'none', icon: '<i class="bi bi-geo-alt text-warning" style="font-size:0.85rem;" title="No location data for this unit"></i>' };
        }
        // Use the freshest timestamp we have — the responders API emits
        // `last_track` for the last GPS report (contract audit 2026-07-07:
        // the old keys location_at/last_track_at were never emitted, so
        // freshness always mis-fell to responder.updated).
        var ts = u.last_track || u.updated;
        if (!ts) {
            return { state: 'unknown', icon: '<i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.7rem;" title="Zoom to location"></i>' };
        }
        var updMs = Date.parse(String(ts).replace(' ', 'T') + 'Z');
        if (isNaN(updMs)) {
            return { state: 'fresh', icon: '<i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.7rem;" title="Zoom to location"></i>' };
        }
        var ageMin = (Date.now() - updMs) / 60000;
        if (ageMin > STALE_LOCATION_THRESHOLD_MIN) {
            return {
                state: 'stale',
                icon: '<i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:0.85rem;" title="Location ' + Math.round(ageMin) + ' min old — may be inaccurate"></i> ' +
                      '<i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.7rem;" title="Zoom to last-known location"></i>'
            };
        }
        return {
            state: 'fresh',
            icon: '<i class="bi bi-geo-alt-fill text-success" style="font-size:0.7rem;" title="Live location (' + Math.round(ageMin) + ' min ago)"></i>'
        };
    }

    function renderCell(colId, u) {
        var hasCoords = u.lat && u.lng && !(u.lat === 0 && u.lng === 0);
        // Phase 95-plus (2026-06-28) — replace the plain geo-pin with
        // a freshness-aware indicator: yellow for missing/stale,
        // green for fresh, grey for unknown.
        var freshness = unitLocationFreshness(u);
        var locIcon = freshness.icon;

        switch (colId) {
            case 'name':
                return '<td class="fw-semibold unit-name-cell" style="cursor:pointer;">' + escHtml(u.name) + '</td>';
            case 'handle':
                return '<td class="unit-handle-cell" style="cursor:pointer;">' + escHtml(u.handle || '') + '</td>';
            case 'type':
                return '<td>' + escHtml(u.type_name || '') + '</td>';
            case 'status':
                var statusStyle = '';
                var statusClass = 'badge';
                if (u.status_bg_color || u.status_text_color) {
                    statusStyle = ' style="';
                    if (u.status_bg_color)   statusStyle += 'background-color:' + escHtml(u.status_bg_color) + ';';
                    if (u.status_text_color) statusStyle += 'color:' + escHtml(u.status_text_color) + ';';
                    statusStyle += '"';
                } else {
                    var sn = (u.status_name || '').toLowerCase();
                    if (sn.indexOf('avail') !== -1)            statusClass += ' bg-success';
                    else if (sn.indexOf('unavail') !== -1 || sn.indexOf('off') !== -1) statusClass += ' bg-danger';
                    else                                        statusClass += ' bg-secondary';
                }
                return '<td><span class="' + statusClass + '"' + statusStyle + '>' + escHtml(u.status_name || 'Unknown') + '</span></td>';
            case 'active':
                return '<td class="text-center">' +
                    (u.active_assignments > 0
                        ? '<span class="badge bg-warning text-dark">' + u.active_assignments + '</span>'
                        : '<span class="text-body-tertiary">0</span>') + '</td>';
            case 'updated':
                return '<td class="text-body-secondary">' + formatDateTime(u.status_updated || u.updated) +
                    (locIcon ? ' <span class="unit-loc-btn" style="cursor:pointer;">' + locIcon + '</span>' : '') + '</td>';
            case 'par_last_checkin':
                if (u.par_last_checkin_at) {
                    return '<td class="text-body-secondary" data-par-last="' + u.par_last_checkin_at + '">' +
                        friendlyTimeAgo(u.par_last_checkin_at) + '</td>';
                }
                return '<td class="text-body-tertiary">—</td>';
            case 'par_next_due':
                if (u.par_next_due_at) {
                    return '<td class="text-warning" data-par-due="' + u.par_next_due_at + '">' +
                        friendlyCountdown(u.par_next_due_at) + '</td>';
                }
                return '<td class="text-body-tertiary">—</td>';
            default:
                return '<td></td>';
        }
    }

    // ── Map ──
    function initMap() {
        var container = document.getElementById('unitsMap');
        if (!container || typeof L === 'undefined') return;

        // Fetch map config for default center
        fetch('api/map-config.php')
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                var defLat = cfg.def_lat || 39.8283;
                var defLng = cfg.def_lng || -98.5795;
                var defZoom = cfg.def_zoom || 5;

                map = L.map('unitsMap', { zoomControl: true }).setView([defLat, defLng], defZoom);
                if (window.TypeIcons && TypeIcons.bindLabelZoom) { TypeIcons.bindLabelZoom(map); }  // GH #76

                addMapLayers(map);
                markerGroup = L.featureGroup().addTo(map);

                updateMapMarkers();

                setTimeout(function () { map.invalidateSize(); }, 200);
            })
            .catch(function () {
                map = L.map('unitsMap', { zoomControl: true }).setView([39.8283, -98.5795], 5);
                if (window.TypeIcons && TypeIcons.bindLabelZoom) { TypeIcons.bindLabelZoom(map); }  // GH #76
                addMapLayers(map);
                markerGroup = L.featureGroup().addTo(map);
                updateMapMarkers();
                setTimeout(function () { map.invalidateSize(); }, 200);
            });
    }

    // ── Basemap + Weather Layer Controls ──
    function addMapLayers(mapInstance) {
        // Base layers
        var osmLight = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OSM', maxZoom: 19
        });
        var cartoDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CartoDB', maxZoom: 19
        });
        var topoMap = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenTopoMap', maxZoom: 17
        });

        // Add the preferred default basemap from user prefs
        var prefKey = window.MapPrefs ? window.MapPrefs.getBasemap() : (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'street');
        var prefMap = { street: osmLight, dark: cartoDark, terrain: topoMap };
        (prefMap[prefKey] || osmLight).addTo(mapInstance);

        var baseMaps = {
            'Street Map': osmLight,
            'Dark': cartoDark,
            'Terrain': topoMap
        };

        // Weather overlays via caching proxy (fail gracefully if no API key)
        var weatherOpts = { opacity: 0.5, maxZoom: 19, errorTileUrl: '' };
        var weatherTemp = L.tileLayer('api/weather-proxy.php?type=tile&layer=temp&z={z}&x={x}&y={y}', weatherOpts);
        var weatherPrecip = L.tileLayer('api/weather-proxy.php?type=tile&layer=precipitation_cls&z={z}&x={x}&y={y}', weatherOpts);
        var weatherWind = L.tileLayer('api/weather-proxy.php?type=tile&layer=wind&z={z}&x={x}&y={y}', weatherOpts);
        var weatherClouds = L.tileLayer('api/weather-proxy.php?type=tile&layer=clouds_cls&z={z}&x={x}&y={y}', weatherOpts);

        var overlays = {
            'Temperature': weatherTemp,
            'Precipitation': weatherPrecip,
            'Wind': weatherWind,
            'Clouds': weatherClouds
        };

        var unitsLayersControl = L.control.layers(baseMaps, overlays, { collapsed: true, position: 'topright' }).addTo(mapInstance);

        // ── Configured tile provider (specs/configurable-tile-providers-2026-06) ──
        // Fold the admin-configured Tile Provider in as an additional base
        // layer once map-prefs.js has fetched it. Additive + async; the
        // built-in Street/Dark/Terrain options and default are unchanged.
        if (window.MapPrefs && typeof window.MapPrefs.init === 'function') {
            window.MapPrefs.init().then(function () {
                var label = window.MapPrefs.getCustomLabel();
                if (label && !unitsLayersControl._ticketsCustomAdded) {
                    unitsLayersControl.addBaseLayer(window.MapPrefs.makeLayer('custom'), label);
                    unitsLayersControl._ticketsCustomAdded = true;
                }
            });
        }
    }

    function updateMapMarkers() {
        if (!map || !markerGroup) return;

        markerGroup.clearLayers();
        markers = [];

        var filtered = getFilteredUnits();
        var bounds = [];
        var markerCount = 0;

        for (var i = 0; i < filtered.length; i++) {
            var u = filtered[i];
            if (!u.lat || !u.lng || (u.lat === 0 && u.lng === 0)) continue;

            var color = getStatusColor(u);

            // Phase 26B (2026-06-11) — stale-marker dim. Compute opacity
            // from time since last activity. 0–5 min fresh; >30 min half.
            // Threshold deliberately gentle so a 20-min gap doesn't make
            // the marker disappear.
            var staleInfo = computeStaleness(u);
            // GH #82/#76 — type-icon badge (status-coloured) + always-on name
            // label. Falls back to the plain dot if the shared builder or
            // Leaflet.divIcon isn't available. Marker opacity carries the same
            // staleness dimming the dot used to.
            var tiIcon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                ? window.TypeIcons.markerDivIcon(u.icon, color, { label: (u.handle || u.name || '') })
                : null;
            var marker = tiIcon
                ? L.marker([u.lat, u.lng], { icon: tiIcon, opacity: staleInfo.opacity })
                : L.circleMarker([u.lat, u.lng], {
                    radius: 8,
                    color: color,
                    fillColor: color,
                    fillOpacity: staleInfo.opacity,
                    weight: 2,
                    opacity: staleInfo.opacity
                });

            var popupHtml = '<strong>' + escHtml(u.handle || u.name) + '</strong><br>'
                + '<small>' + escHtml(u.status_name || '') + '</small><br>'
                + '<small class="text-body-secondary">Last activity: ' + staleInfo.ageLabel + '</small><br>'
                + '<a href="unit-detail.php?id=' + u.id + '" class="small">View Details</a>';
            marker.bindPopup(popupHtml);
            marker.bindTooltip('Last activity: ' + staleInfo.ageLabel, { sticky: true });

            marker.addTo(markerGroup);
            markers.push(marker);
            bounds.push([u.lat, u.lng]);
            markerCount++;
        }

        var countEl = document.getElementById('mapMarkerCount');
        if (countEl) countEl.textContent = markerCount + ' on map';

        if (markerGroup.getLayers().length > 1) {
            map.fitBounds(markerGroup.getBounds().pad(0.1), { maxZoom: 14 });
        } else if (markerGroup.getLayers().length === 1) {
            map.setView(bounds[0], 14);
        }
    }

    // Phase 26B (2026-06-11) — stale-marker dimming based on last activity.
    // Reads par_last_checkin_at (unix ts), updated, or status_updated and
    // returns {opacity, ageLabel}.
    //   0–5  min: opacity 0.9
    //   5–15 min: opacity 0.75
    //  15–30 min: opacity 0.55
    //  30–60 min: opacity 0.35
    //   >60 min: opacity 0.2 (still visible, clearly stale)
    //  no timestamp: opacity 0.6 (don't punish; we just don't know)
    function computeStaleness(unit) {
        var ts = null;
        if (unit.par_last_checkin_at) ts = parseInt(unit.par_last_checkin_at, 10) * 1000;
        else if (unit.last_track)     ts = Date.parse(unit.last_track);
        else if (unit.status_updated) ts = Date.parse(unit.status_updated);
        else if (unit.updated)        ts = Date.parse(unit.updated);
        if (!ts || isNaN(ts)) return { opacity: 0.6, ageLabel: 'unknown' };

        var ageMs = Date.now() - ts;
        if (ageMs < 0) ageMs = 0;
        var ageMin = ageMs / 60000;

        var op;
        if (ageMin < 5)       op = 0.9;
        else if (ageMin < 15) op = 0.75;
        else if (ageMin < 30) op = 0.55;
        else if (ageMin < 60) op = 0.35;
        else                  op = 0.2;

        var label;
        if (ageMin < 1) label = 'just now';
        else if (ageMin < 60) label = Math.round(ageMin) + ' min ago';
        else if (ageMin < 1440) label = Math.round(ageMin / 60) + ' h ago';
        else label = Math.round(ageMin / 1440) + ' d ago';

        return { opacity: op, ageLabel: label };
    }

    function getStatusColor(unit) {
        // Use bg_color from un_status if available
        if (unit.status_bg_color) {
            return unit.status_bg_color;
        }

        var sn = (unit.status_name || '').toLowerCase();
        if (sn.indexOf('avail') !== -1) return '#198754';
        if (sn.indexOf('unavail') !== -1 || sn.indexOf('off') !== -1) return '#dc3545';
        if (unit.active_assignments > 0) return '#ffc107';
        return '#6c757d';
    }

    // ── Utilities ──
    function formatDateTime(dt) {
        if (!dt) return '--';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dt;
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return month + '/' + day + '/' + d.getFullYear() + ' ' + hours + ':' + mins;
    }

    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            message +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // 2026-07-03 — expose the reloader so the units.php title-bar
    // action bar can refresh the list after a status/dispatch/note.
    window.loadUnits = loadUnits;

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', init);

})();
