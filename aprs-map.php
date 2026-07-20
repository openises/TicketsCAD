<?php
/**
 * NewUI v4.0 — APRS-IS Map (Phase 99g, 2026-06-28).
 *
 * Live map view of stations heard via APRS-IS. Reuses existing
 * Leaflet stack from index.php. Updates every 60 seconds.
 *
 * Sends path (Phase 99a/14) is already wired through Compose form.
 * Receive listener daemon is a separate piece (Python service that
 * subscribes to APRS-IS and writes into location_reports). This page
 * shows whatever's been written there + clearly indicates listener
 * status so admins know if rows aren't coming in.
 *
 * Auth: any logged-in user (matches the dispatch-map permission posture).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

$user     = e($_SESSION['user']);
$level    = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'aprs-map';
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo $csrf; ?>">
    <title>APRS-IS Map — <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack.min.css">
    <link rel="stylesheet" href="assets/vendor/gridstack/gridstack-extra.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <style>
        /* Phase 99h-v5 (2026-06-29) — GridStack widget layout.
           Eric beta: "full widget style layout, like the situation
           page. I might have a very wide monitor for example, and
           want to place this in the upper 1/3 of that wide monitor".
           Each widget (Map / Stations) is draggable + resizable by
           the .card-header grip; positions + sizes persist per-user
           via localStorage. */
        #aprsGrid {
            background: transparent;
        }
        .grid-stack-item-content {
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border-radius: 0.375rem;
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
        }
        .grid-stack-item-content > .card-header {
            cursor: move;
            padding: 4px 10px;
            border-bottom: 1px solid var(--bs-border-color);
            background: var(--bs-tertiary-bg);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }
        .grid-stack-item-content > .card-body {
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #aprsMap {
            flex: 1 1 auto;
            min-height: 0;
        }
        .aprs-widget-body-list {
            padding: 8px;
            overflow: visible !important;
        }
        .aprs-widget-body-list .table-responsive {
            flex: 1 1 auto;
            min-height: 0;
            overflow: auto;
        }
        /* Types-filter dropdown — escape its widget pane */
        #aprsTypeFilterDropdown {
            z-index: 1080;
        }
        /* Phase 99h-v5 fix (2026-06-29): override GridStack's default
           SE resize handle. Eric beta reported a blue `>` floating
           in the corner — that was GridStack's chevron pseudo-element
           ("ui-resizable-se::before" content) painted through our
           border overrides. Replace with a larger, easier-to-grab
           corner triangle that has no pseudo content of its own.
           Also bigger hit area (24px) so the user can grab it
           without precision pixel-hunting. */
        .grid-stack-item > .ui-resizable-se {
            width: 24px !important;
            height: 24px !important;
            right: 0 !important;
            bottom: 0 !important;
            background: none !important;
            background-image: none !important;
            transform: none !important;
            border: none !important;
            opacity: 0.55;
            transition: opacity 0.15s;
        }
        .grid-stack-item > .ui-resizable-se::before,
        .grid-stack-item > .ui-resizable-se::after {
            content: none !important;
            display: none !important;
        }
        /* Diagonal stripe pattern as the visible grip — non-clickable
           on its own, the parent .ui-resizable-se is what catches
           the drag, so the visual sits over the whole hit area. */
        .grid-stack-item > .ui-resizable-se {
            background-image:
                linear-gradient(135deg,
                    transparent 0%, transparent 40%,
                    var(--bs-border-color) 40%, var(--bs-border-color) 45%,
                    transparent 45%, transparent 55%,
                    var(--bs-border-color) 55%, var(--bs-border-color) 60%,
                    transparent 60%, transparent 75%,
                    var(--bs-border-color) 75%, var(--bs-border-color) 80%,
                    transparent 80%) !important;
        }
        .grid-stack-item:hover > .ui-resizable-se {
            opacity: 1;
            background-image:
                linear-gradient(135deg,
                    transparent 0%, transparent 40%,
                    var(--bs-primary) 40%, var(--bs-primary) 45%,
                    transparent 45%, transparent 55%,
                    var(--bs-primary) 55%, var(--bs-primary) 60%,
                    transparent 60%, transparent 75%,
                    var(--bs-primary) 75%, var(--bs-primary) 80%,
                    transparent 80%) !important;
        }
        .aprs-row-clickable { cursor: pointer; }
        .aprs-row-clickable:hover { background: var(--bs-primary-bg-subtle) !important; }
        .aprs-row-active { background: var(--bs-primary-bg-subtle) !important; }
        .aprs-age-fresh { color: var(--bs-success); }
        .aprs-age-stale { color: var(--bs-warning); }
        .aprs-age-cold  { color: var(--bs-secondary); }
        /* Columns dropdown */
        #aprsColsDropdown { min-width: 220px; max-height: 60vh; overflow-y: auto; }
        #aprsColsDropdown .aprs-col-item {
            padding: 4px 12px; cursor: grab;
            display: flex; align-items: center; gap: 6px;
        }
        #aprsColsDropdown .aprs-col-item:hover { background: var(--bs-tertiary-bg); }
        #aprsColsDropdown .aprs-col-item.dragging { opacity: 0.4; }
        #aprsColsDropdown .aprs-col-item.drag-over { border-top: 2px solid var(--bs-primary); }
        #aprsColsDropdown .aprs-col-grip { color: var(--bs-body-tertiary); font-size: 0.85rem; }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>

<div class="container-fluid mt-3">
    <div class="d-flex align-items-center gap-3 mb-3">
        <h5 class="mb-0">
            <i class="bi bi-broadcast-pin me-1 text-primary"></i> APRS-IS Map
        </h5>
        <span id="aprsStatusBadge" class="badge bg-secondary">Loading…</span>
        <span id="aprsStationCount" class="text-body-secondary small"></span>

        <!-- Phase 99h-v2 (2026-06-29) — replaced Map/List view toggle
             with a single split layout (map + table simultaneously
             visible). The old toggle is gone — both panes are always
             present; the drag handle between them lets the user
             resize. Use the Columns dropdown below the map to hide
             columns if more map area is wanted. -->
        <div class="btn-group btn-group-sm ms-2" role="group">
            <button type="button" class="btn btn-outline-secondary" id="aprsColsBtn"
                    data-bs-toggle="dropdown" aria-expanded="false"
                    title="Toggle visible columns + drag-reorder">
                <i class="bi bi-layout-three-columns me-1"></i>Columns
            </button>
            <ul class="dropdown-menu" id="aprsColsDropdown" data-bs-auto-close="outside">
                <li><h6 class="dropdown-header small">Show / reorder columns</h6></li>
                <li><div id="aprsColsList"><!-- populated by JS --></div></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item small" href="#" id="aprsColsReset">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to defaults
                </a></li>
            </ul>
        </div>

        <!-- Motion projection toggle (Eric beta 2026-06-29):
             dead-reckon moving stations between server refreshes.
             Pure client-side — zero server load. Stops animating
             stations whose last fix is >5 min old. Preference
             persisted in localStorage. -->
        <!-- Phase 99h-v5 (2026-06-29) — Eric beta: configurable
             motion-projection timeout (was hardcoded 15 min). Type
             any number of minutes; the projection animates a moving
             station forward from its last fix up to this many
             minutes, then freezes at that extrapolated position. -->
        <div class="form-check form-switch ms-2 mb-0 small" title="Animate moving stations between updates using last known speed + heading.">
            <input class="form-check-input" type="checkbox" id="aprsMotionToggle">
            <label class="form-check-label" for="aprsMotionToggle">
                <i class="bi bi-arrow-right-circle me-1"></i>Motion
            </label>
        </div>
        <div class="d-flex align-items-center gap-1 ms-1 small" title="Motion projection timeout in minutes — markers freeze at the position they'd be at this many minutes after their last fix. Default 15.">
            <input type="number" class="form-control form-control-sm" id="aprsMotionTimeout"
                   min="1" max="240" step="1" value="15"
                   style="width:60px;">
            <span class="text-body-secondary" style="font-size:0.7rem;">min</span>
        </div>

        <!-- Watchlist layer toggle (Eric beta 2026-06-29):
             system-wide curated list of "interesting" APRS callsigns
             (admin-managed via Watch column in List view). Each user
             toggles visibility of the layer in their own view.
             Hidden = all markers render as normal. Visible = watched
             stations get a gold star + thicker border for visual
             distinction (non-watched remain in their normal style). -->
        <div class="form-check form-switch ms-2 mb-0 small" title="Filter map + table to ONLY stations on the watchlist. Admins curate the list via the Watched checkbox in the table.">
            <!-- Phase 99h-v2 fix (2026-06-29): removed default `checked`
                 attribute. Was leaving a mismatch with JS that sets
                 toggle.checked from localStorage (defaults to false) —
                 race window during init where renderStations could
                 read the still-checked DOM and apply an unintended
                 filter. -->
            <input class="form-check-input" type="checkbox" id="aprsWatchLayerToggle">
            <label class="form-check-label" for="aprsWatchLayerToggle">
                <i class="bi bi-star-fill text-warning me-1"></i>Watched only
                <span class="badge bg-secondary ms-1" id="aprsWatchCount" style="font-size:0.6rem;">0</span>
            </label>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2">
            <label class="form-label form-label-sm mb-0 text-body-secondary">Time window:</label>
            <select class="form-select form-select-sm" id="aprsSinceMin" style="width:120px;">
                <option value="15">Last 15 min</option>
                <option value="60" selected>Last hour</option>
                <option value="240">Last 4 h</option>
                <option value="1440">Last 24 h</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="aprsRefreshBtn"
                    title="Refresh now (auto-refreshes every 60s)">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <a href="settings.php#aprs-config" class="btn btn-sm btn-outline-primary"
               title="Open APRS-IS settings">
                <i class="bi bi-gear me-1"></i>Settings
            </a>
        </div>
    </div>

    <!-- Listener-not-running banner. Hidden when listener is healthy. -->
    <div id="aprsListenerWarning" class="alert alert-warning d-none mb-3" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>APRS-IS receive listener is not active.</strong>
        <span id="aprsListenerDetail" class="small ms-1"></span>
        The map shows whatever was last received; new stations won't appear
        until the listener service is running. See
        <a href="docs/LOCATION-PROVIDERS-GUIDE.md" target="_blank" class="alert-link">Location Providers Guide</a>
        for setup instructions.
    </div>

    <!-- Phase 99h-v5 (2026-06-29) — Eric beta: GridStack widget
         layout. Two widgets (Map + Stations) each draggable by
         their card header, resizable by their bottom-right corner.
         User layout persists in localStorage. -->
    <div class="grid-stack" id="aprsGrid">
        <div class="grid-stack-item" gs-id="map" gs-x="0" gs-y="0" gs-w="12" gs-h="7" gs-min-w="3" gs-min-h="3">
            <div class="grid-stack-item-content">
                <div class="card-header">
                    <i class="bi bi-grip-vertical text-body-tertiary"></i>
                    <i class="bi bi-map-fill text-primary"></i>
                    <strong class="small">APRS Map</strong>
                    <span class="ms-auto text-body-secondary" style="font-size:0.7rem;">
                        <span id="aprsStationCountWidget"></span>
                    </span>
                </div>
                <div class="card-body p-0"><div id="aprsMap"></div></div>
            </div>
        </div>
        <div class="grid-stack-item" gs-id="table" gs-x="0" gs-y="7" gs-w="12" gs-h="8" gs-min-w="4" gs-min-h="3">
            <div class="grid-stack-item-content">
                <div class="card-header">
                    <i class="bi bi-grip-vertical text-body-tertiary"></i>
                    <i class="bi bi-table text-primary"></i>
                    <strong class="small">Stations</strong>
                    <span class="ms-auto text-body-secondary" style="font-size:0.7rem;" id="aprsListShownCountInline"></span>
                </div>
                <div class="card-body aprs-widget-body-list">
            <div class="d-flex align-items-center gap-2 mb-2">
                <input type="search" class="form-control form-control-sm" id="aprsListFilter"
                       placeholder="Filter rows (callsign, comment, type)…" style="max-width:300px;">
                <!-- Phase 99h-v3 (2026-06-29) — Eric beta: filter by
                     station type (e.g. hide all weather, all houses).
                     Dropdown lists every type currently in the data;
                     unchecking hides that type from BOTH the table
                     and the map. Persists in localStorage as a deny-
                     list (so new types default to visible). -->
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-toggle="dropdown" data-bs-auto-close="outside"
                            id="aprsTypeFilterBtn">
                        <i class="bi bi-funnel me-1"></i>Types <span id="aprsTypeFilterLabel">(all)</span>
                    </button>
                    <ul class="dropdown-menu" id="aprsTypeFilterDropdown" style="min-width:220px;max-height:60vh;overflow-y:auto;">
                        <li><h6 class="dropdown-header small">Show / hide by station type</h6></li>
                        <li><div id="aprsTypeFilterList"><!-- populated by JS --></div></li>
                        <li><hr class="dropdown-divider"></li>
                        <li class="d-flex gap-2 px-2 pb-1">
                            <a class="dropdown-item small flex-grow-1 text-start" href="#" id="aprsTypeFilterAll">
                                <i class="bi bi-check-all me-1"></i>Show all
                            </a>
                            <a class="dropdown-item small flex-grow-1 text-start" href="#" id="aprsTypeFilterNone">
                                <i class="bi bi-eye-slash me-1"></i>Hide all
                            </a>
                        </li>
                    </ul>
                </div>
                <span class="text-body-secondary small ms-auto" id="aprsListShownCount"></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped mb-0">
                    <!-- Phase 99h-v2 (2026-06-29) — thead is JS-rendered
                         so column visibility + reorder can be applied.
                         Fallback <th> cells below cover the pre-JS render
                         window for screen readers + satisfy Sonar Web:S5256
                         which can't see the runtime replacement. JS
                         overwrites this whole <tr> on load. -->
                    <thead class="table-light sticky-top" style="top:0;">
                        <tr id="aprsListHead">
                            <th scope="col">Callsign</th>
                            <th scope="col">Type</th>
                            <th scope="col">Last heard</th>
                        </tr>
                    </thead>
                    <tbody id="aprsListBody">
                        <tr><td class="text-center text-body-secondary py-3">Loading…</td></tr>
                    </tbody>
            </table>
        </div>
    </div>

    <p class="text-body-secondary small mt-2 mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Color-coded by age (green &lt;5min, yellow 5-30min, grey older).
        <strong>Click any table row</strong> to zoom map + open popup.
        <strong>Drag widgets</strong> by their header bar;
        <strong>resize</strong> from the bottom-right corner.
        <a href="#" id="aprsResetLayout" class="ms-2">Reset layout</a>
    </p>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/vendor/gridstack/gridstack-all.js"></script>
<script>
(function () {
    'use strict';

    var map = null;
    var markers = {};        // callsign → L.Marker
    var stations = [];
    var refreshTimer = null;
    // Phase 99h watchlist state — Set of uppercase callsigns + admin-write flag
    var watchSet = new Set();
    var watchCanWrite = false;

    function init() {
        // Phase 103c (a beta tester GH #3) — respect Settings > Map Defaults
        // instead of the historical Twin Cities constant. We initialise
        // with a placeholder view and immediately overwrite it with the
        // configured lat/lng/zoom once /api/map-config.php responds.
        // Falls back to Twin Cities if the endpoint fails so the map
        // never renders at the (0,0) equator.
        map = L.map('aprsMap', { zoomControl: true }).setView([44.95, -93.10], 9);
        fetch('api/map-config.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                if (!cfg || typeof cfg.def_lat !== 'number' || typeof cfg.def_lng !== 'number') return;
                map.setView([cfg.def_lat, cfg.def_lng], cfg.def_zoom || 9);
            })
            .catch(function () { /* fall back silently to Twin Cities */ });

        // 2026-06-29 (Eric beta) — basemap selector. Adds a layers
        // control to the map's top-right with multiple tile providers.
        // User pick persisted in localStorage. Default = Streets (OSM).
        var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap'
        });
        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Tiles &copy; Esri' }
        );
        // Phase 99h-v5 fix (2026-06-29): switched from OpenTopoMap
        // (CORS/rate-limit issues on training's outbound traffic —
        // Eric beta reported the topo layer wasn't rendering) to
        // Esri's World Topographic Map service, which is free, no
        // auth, no rate-limit for normal use, and CORS-friendly.
        var topo = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: 'Tiles &copy; Esri &mdash; Sources: National Geographic, Esri, DeLorme, etc.' }
        );
        var hybrid_labels = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
            { maxZoom: 19, attribution: '&copy; Esri', opacity: 0.85 }
        );
        var satelliteHybrid = L.layerGroup([satellite, hybrid_labels]);
        var dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap, &copy; CARTO',
            subdomains: 'abcd'
        });
        var light = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap, &copy; CARTO',
            subdomains: 'abcd'
        });

        var baseMaps = {
            'Streets (OSM)':      streets,
            'Satellite':          satellite,
            'Satellite + labels': satelliteHybrid,
            'Topographic':        topo,
            'Light':              light,
            'Dark':               dark
        };

        // Restore saved basemap or fall back to Streets
        var savedBase = localStorage.getItem('aprsMapBasemap') || 'Streets (OSM)';
        var initialLayer = baseMaps[savedBase] || streets;
        initialLayer.addTo(map);

        L.control.layers(baseMaps, null, {
            position: 'topright', collapsed: true
        }).addTo(map);

        // Persist user choice on basemap switch
        map.on('baselayerchange', function (e) {
            localStorage.setItem('aprsMapBasemap', e.name);
        });

        document.getElementById('aprsRefreshBtn').addEventListener('click', loadStations);
        document.getElementById('aprsSinceMin').addEventListener('change', loadStations);

        // Phase 99h-v2 (2026-06-29) — single split layout, no more
        // Map/List view toggle. Setup column header (JS-rendered),
        // columns dropdown, split-pane drag handle, text filter.
        renderListHead();
        renderColsDropdown();
        document.getElementById('aprsColsReset').addEventListener('click', function (e) {
            e.preventDefault(); resetColsConfig();
        });
        initGridStack();

        var filterEl = document.getElementById('aprsListFilter');
        if (filterEl) filterEl.addEventListener('input', renderList);

        // Type-filter dropdown (Phase 99h-v3 / Eric beta 2026-06-29)
        renderTypeFilterDropdown();
        document.getElementById('aprsTypeFilterAll').addEventListener('click', function (e) {
            e.preventDefault();
            hiddenTypes = {};
            saveHiddenTypes();
            renderTypeFilterDropdown();
            renderStations();
            renderList();
        });
        document.getElementById('aprsTypeFilterNone').addEventListener('click', function (e) {
            e.preventDefault();
            hiddenTypes = {};
            _typesInData().forEach(function (t) { hiddenTypes[t] = true; });
            saveHiddenTypes();
            renderTypeFilterDropdown();
            renderStations();
            renderList();
        });

        // Motion projection — toggle UI + dead-reckon interval.
        bindMotionToggle();

        // Watchlist layer — toggle now FILTERS to watched-only
        // (Eric beta 2026-06-29). Preference persisted in localStorage.
        bindWatchLayerToggle();

        loadWatchlist();
        loadStations();
        refreshTimer = setInterval(loadStations, 60000);  // 60s auto-refresh
        // Refresh watchlist every 30s so changes by other admins
        // become visible without a hard page refresh.
        setInterval(loadWatchlist, 30000);
    }

    // ─── Watchlist load / toggle / visibility ─────────────────────
    function loadWatchlist() {
        return fetch('api/aprs-watchlist.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) return;
                watchSet = new Set();
                (data.watchlist || []).forEach(function (w) {
                    watchSet.add((w.callsign || '').toUpperCase());
                });
                watchCanWrite = !!data.can_write;
                var countEl = document.getElementById('aprsWatchCount');
                if (countEl) countEl.textContent = watchSet.size;
                // Single-view layout: always re-render both panes.
                renderStations();
                renderList();
            })
            .catch(function () { /* non-fatal */ });
    }

    function toggleWatch(callsign) {
        if (!watchCanWrite) return;
        var cs = String(callsign).toUpperCase();
        var action = watchSet.has(cs) ? 'remove' : 'add';
        fetch('api/aprs-watchlist.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ action: action, callsign: cs })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Watchlist update failed: ' + data.error);
                return;
            }
            if (action === 'add') watchSet.add(cs); else watchSet.delete(cs);
            var countEl = document.getElementById('aprsWatchCount');
            if (countEl) countEl.textContent = watchSet.size;
            renderStations();
            renderList();
        })
        .catch(function (err) { alert('Network error: ' + err.message); });
    }

    function bindWatchLayerToggle() {
        var toggle = document.getElementById('aprsWatchLayerToggle');
        if (!toggle) return;
        // 2026-06-29 v2: now defaults OFF (no filter). Toggle ON = filter
        // to watched-only stations. Switches role from cosmetic
        // highlight to actual content filter per Eric's clarification:
        // "when it's toggled on, only stations that are on the watch
        // list appear... That would declutter and make it easier to
        // see what you are about to expose to the map layer".
        var saved = localStorage.getItem('aprsWatchLayerVisible');
        toggle.checked = (saved === '1');
        toggle.addEventListener('change', function () {
            localStorage.setItem('aprsWatchLayerVisible', this.checked ? '1' : '0');
            renderStations();
            renderList();
        });
    }
    function watchLayerVisible() {
        // True when the "Watched only" filter is active.
        var t = document.getElementById('aprsWatchLayerToggle');
        return !!(t && t.checked);
    }

    // Sort state — default: freshest first
    var listSort = { key: 'age_sec', dir: 'asc' };

    // Row drill-in / pop-back state (Phase 99h-v2 fix, 2026-06-29).
    // activeRowCallsign = callsign currently zoomed-into via row click.
    // prevMapView = the {center, zoom} the map was at BEFORE the
    // first drill-in, so the toggle-back can restore it. Clearing
    // activeRowCallsign clears prevMapView too.
    var activeRowCallsign = null;
    var prevMapView = null;

    function loadStations() {
        var since = document.getElementById('aprsSinceMin').value || '60';
        fetch('api/aprs-positions.php?since_min=' + encodeURIComponent(since) + '&limit=500',
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    // Phase 99h-v2 fix (2026-06-29): the old branch
                    // wrote into #aprsStationList, which the split-
                    // layout refactor removed. Null-deref threw,
                    // bubbled into .catch, badge stuck red.
                    document.getElementById('aprsStatusBadge').className = 'badge bg-danger';
                    document.getElementById('aprsStatusBadge').textContent = 'Error';
                    var tb = document.getElementById('aprsListBody');
                    if (tb) tb.innerHTML = '<tr><td class="text-center text-danger py-3" colspan="20">' + escapeHtml(data.error) + '</td></tr>';
                    return;
                }
                stations = data.stations || [];
                updateStatus(data);
                renderTypeFilterDropdown();   // refresh checkbox list + counts
                renderStations();
                renderList();
            })
            .catch(function (err) {
                document.getElementById('aprsStatusBadge').className = 'badge bg-danger';
                document.getElementById('aprsStatusBadge').textContent = 'Network error';
            });
    }

    function updateStatus(data) {
        var badge = document.getElementById('aprsStatusBadge');
        var warn  = document.getElementById('aprsListenerWarning');
        var detail = document.getElementById('aprsListenerDetail');
        var count = document.getElementById('aprsStationCount');

        // 2026-06-29 fix: prefer unique_stations_in_window (true
        // distinct count) over data.count (capped by LIMIT).
        var trueCount = (data.unique_stations_in_window != null)
            ? data.unique_stations_in_window
            : data.count;
        count.textContent = trueCount + ' station' + (trueCount === 1 ? '' : 's') + ' in window';

        if (data.listener_status === 'running') {
            badge.className = 'badge bg-success';
            badge.innerHTML = '<i class="bi bi-circle-fill me-1" style="font-size:0.5rem;vertical-align:middle;"></i>Listener active';
            warn.classList.add('d-none');
        } else if (data.listener_status === 'stopped') {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = 'Listener stale';
            warn.classList.remove('d-none');
            detail.textContent = 'Last position received ' + formatAge(data.provider.last_seen_ago_sec) + ' ago.';
        } else {
            badge.className = 'badge bg-secondary';
            badge.textContent = 'Not configured';
            warn.classList.remove('d-none');
            detail.textContent = 'No APRS-IS position rows ever received.';
        }
    }

    function renderStations() {
        // Clear old markers. Phase 99h-v2 fix (2026-06-29): also
        // walk map.eachLayer to catch orphaned L.Marker instances
        // that escaped the `markers` dict — Eric reported 4 stations
        // persisting on the map after toggling the Watched-only filter
        // on with an empty watchlist. Hypothesis: a race between
        // loadWatchlist's .then() and loadStations' .then() (both call
        // renderStations) could overwrite markers[callsign] before the
        // earlier marker was removed. Belt-and-suspenders cleanup
        // below removes any L.Marker on the map regardless of tracking.
        Object.keys(markers).forEach(function (k) { map.removeLayer(markers[k]); });
        markers = {};
        map.eachLayer(function (layer) {
            if (layer instanceof L.Marker) {
                map.removeLayer(layer);
            }
        });

        // Phase 99h-v2 (2026-06-29) — sidebar removed; the table
        // below the map IS the station list now. Map markers
        // honor the Watched-only filter (toggle behavior changed
        // from cosmetic-highlight to actual-filter per Eric).
        var watchedOnly = watchLayerVisible();
        var visibleStations = stations.filter(_typeAllowed);
        if (watchedOnly) {
            visibleStations = visibleStations.filter(function (s) {
                return watchSet.has(String(s.callsign).toUpperCase());
            });
        }

        visibleStations.forEach(function (s) {
            var ageClass = (s.age_sec < 300) ? 'aprs-age-fresh'
                         : (s.age_sec < 1800) ? 'aprs-age-stale'
                         : 'aprs-age-cold';
            var color = (s.age_sec < 300) ? '#198754'
                      : (s.age_sec < 1800) ? '#ffc107'
                      : '#6c757d';

            // Watched stations always get gold styling, regardless
            // of whether the filter is on. Visual signal stays
            // consistent (gold = watched).
            var isWatched = watchSet.has(String(s.callsign).toUpperCase());
            var icon = _aprsBuildIcon(s.symbol, color, isWatched);

            // Phase 99h-v2 fix (2026-06-29): start the marker at the
            // EXTRAPOLATED-NOW position, not the raw last-reported
            // position. Eric beta found high-speed stations (e.g.
            // N0OQA at 20.6 kph reported 11 min ago) would render at
            // base, then on the first motionTick teleport ~4 km away
            // — instantly leaving a zoomed-in viewport. Computing the
            // projected-current position at render time means no
            // first-tick teleport: the marker is already where it
            // should be, motionTick only adds small per-second deltas.
            // baseLat/baseLng in _aprsMotion stay as the LAST-REPORTED
            // fix so motionTick math stays absolute (no per-tick
            // accumulation drift) and stopMotionLoop can still snap
            // back to the real last-known position.
            var speedKph = parseFloat(s.speed) || 0;
            var heading  = parseFloat(s.heading) || 0;
            var initLat  = s.lat, initLng = s.lng;
            if (motionEnabled() && speedKph > 0 && _shouldExtrapolate(s)) {
                var p = _projectPos(s.lat, s.lng, speedKph, heading, Math.min(s.age_sec, APRS_MOTION_TIMEOUT_MIN * 60));
                initLat = p.lat; initLng = p.lng;
            }
            var marker = L.marker([initLat, initLng], { icon: icon }).addTo(map);
            // Phase 99h-v5 fix (2026-06-29): popup units match the
            // table (mph + ft). Eric beta: "can we have the balloon
            // that bubbles up when you click on an object also
            // respect the units setting? This example displays in
            // KM instead of Miles". The listener stores SI (kph + m);
            // we convert at display.
            var popupSpeed = '';
            if (s.speed > 0) {
                var mphVal = (s.speed * 0.621371);
                popupSpeed = '<div class="small">Speed: ' + mphVal.toFixed(1) +
                    ' mph &middot; Heading: ' + Math.round(s.heading) + '&deg;</div>';
            }
            var popupAlt = '';
            if (s.altitude != null && s.altitude > 0) {
                popupAlt = '<div class="small">Alt: ' + Math.round(s.altitude * 3.28084) + ' ft</div>';
            }
            marker.bindPopup(
                '<div><strong>' + escapeHtml(s.callsign) + '</strong></div>' +
                '<div class="small text-body-secondary">' + s.lat.toFixed(5) + ', ' + s.lng.toFixed(5) + '</div>' +
                popupSpeed + popupAlt +
                '<div class="small ' + ageClass + '">' + formatAge(s.age_sec) + ' ago</div>' +
                (isWatched ? '<div class="small text-warning"><i class="bi bi-star-fill"></i> Watched</div>' : '')
            );
            marker._aprsMotion = {
                baseLat: s.lat,
                baseLng: s.lng,
                speedKph: speedKph,
                heading: heading,
                anchorMs: Date.now() - (s.age_sec * 1000),
                isWeather: !_shouldExtrapolate(s)   // suppress for weather symbols
            };
            markers[s.callsign] = marker;
        });

        // Auto-fit bounds to whatever's currently visible (first load only).
        if (visibleStations.length > 0 && !map._aprsBoundsSet) {
            var bounds = L.latLngBounds(visibleStations.map(function (s) { return [s.lat, s.lng]; }));
            map.fitBounds(bounds, { padding: [20, 20], maxZoom: 11 });
            map._aprsBoundsSet = true;
        }
    }

    // ─── Motion projection (dead-reckon) ─────────────────────────
    //   Eric beta 2026-06-29: animate moving stations between
    //   server refreshes using last known speed + heading.
    //   All client-side; zero server load.
    //
    //   Math: small-circle approximation, good for short-horizon
    //   extrapolation (<5 min at amateur-mobile speeds = <8km).
    //     deltaLat = (distKm / 6371) × cos(headingRad) × 180/π
    //     deltaLng = (distKm / 6371) × sin(headingRad) × 180/π / cos(latRad)
    //   Distance accumulates as (speed_kph × elapsed_sec / 3600).
    //
    //   Stale-stop: any station whose last actual fix is older than
    //   APRS_MOTION_TIMEOUT_MIN gets its marker held at its current
    //   position (no further projection). Prevents runaway extrapolation
    //   when a unit has actually stopped.
    // Phase 99h-v2 fixes (2026-06-29 — motion debugging):
    //   1. setStyle({...}) was being called on L.Marker — but
    //      setStyle exists on path layers (Polygon, Circle), NOT
    //      on L.Marker with divIcon. It threw TypeError, breaking
    //      the forEach for that tick. Replaced with divIcon opacity
    //      via mk.getElement().style.opacity.
    //   2. 5-min timeout was too aggressive. Most APRS stations
    //      beacon every 10-15 min when stationary, so 5 min cut
    //      off motion projection for everything Eric was looking
    //      at. Bumped to 15 min.
    //   3. When projection stops (past timeout), freeze the marker
    //      at its LAST extrapolated position — don't snap it back
    //      to base. The user watched it drift; popping back to base
    //      is jarring and undoes the projection they were tracking.
    // User-configurable timeout (Phase 99h-v5, 2026-06-29).
    // Eric beta: "could that be exposed on the page so I could
    // predict the location of an object that maybe hasn't been
    // received for 20 minutes? Or any other time period I want
    // to input?". Range 1-240 min, default 15.
    var APRS_MOTION_TIMEOUT_MIN = (function () {
        var v = parseFloat(localStorage.getItem('aprsMotionTimeoutMin'));
        return (v >= 1 && v <= 240) ? v : 15;
    })();
    function _setMotionTimeout(mins) {
        var v = parseFloat(mins);
        if (!(v >= 1 && v <= 240)) return;
        APRS_MOTION_TIMEOUT_MIN = v;
        localStorage.setItem('aprsMotionTimeoutMin', String(v));
        // Re-render so markers reposition immediately to the new
        // capped-extrapolation position (instead of waiting up to
        // 1 second for the next motionTick).
        renderStations();
    }
    var APRS_MOTION_TICK_MS = 1000;
    var motionTimer = null;

    // Phase 99h-v2 fix (2026-06-29): shared projection helper.
    // Returns {lat,lng} at the position {baseLat,baseLng} would be
    // after `elapsedSec` of travel at speedKph in `heading` degrees.
    // Small-circle approximation, good for short-horizon extrapolation
    // (<15 min at amateur-mobile speeds is <30km).
    function _projectPos(baseLat, baseLng, speedKph, heading, elapsedSec) {
        if (!(speedKph > 0)) return { lat: baseLat, lng: baseLng };
        var earthR = 6371;
        var distKm = (speedKph * elapsedSec) / 3600;
        var bearingRad = heading * Math.PI / 180;
        var latRad = baseLat * Math.PI / 180;
        var deltaLat = (distKm / earthR) * Math.cos(bearingRad) * (180 / Math.PI);
        var deltaLng = (distKm / earthR) * Math.sin(bearingRad) * (180 / Math.PI) / Math.cos(latRad);
        return { lat: baseLat + deltaLat, lng: baseLng + deltaLng };
    }

    // Should this station's speed/heading be used as motion data?
    // Eric beta 2026-06-29: APRS weather stations (symbol '_') encode
    // wind direction + wind speed in the same packet field that
    // mobile stations use for course/speed. aprslib parses both the
    // same way, so the listener stores wind data as speed/heading.
    //
    // Three layers of defense (any one is enough):
    //   1. Symbol char (`_` or `W`) — quick check, works for any
    //      packet with a parseable uncompressed-position symbol.
    //   2. station_type from server — covers any future server-side
    //      classification additions (e.g. MIC-E weather packets).
    //   3. Server-side speed/heading zeroing — already in place;
    //      motionTick's `speedKph <= 0` check is the last line.
    var _STATIC_TYPES = {
        'Weather': 1, 'WX site': 1, 'House': 1, 'Hospital': 1,
        'EOC': 1, 'Repeater': 1, 'Igate': 1, 'TCP/IP': 1,
        'Node': 1, 'Dish': 1, 'Yagi': 1, 'Red Cross': 1
    };

    // Per-user type filter (Phase 99h-v3 / Eric beta 2026-06-29).
    // Deny-list: anything in hiddenTypes is hidden from table+map.
    // New types appear by default (since they're absent from the set).
    var hiddenTypes = (function () {
        try {
            return JSON.parse(localStorage.getItem('aprsHiddenTypes') || '{}') || {};
        } catch (_) { return {}; }
    })();
    function saveHiddenTypes() {
        localStorage.setItem('aprsHiddenTypes', JSON.stringify(hiddenTypes));
    }
    function _typesInData() {
        var seen = {};
        stations.forEach(function (s) {
            var t = s.station_type || 'Other';
            seen[t] = true;
        });
        return Object.keys(seen).sort();
    }
    function _typeAllowed(s) {
        var t = s.station_type || 'Other';
        return !hiddenTypes[t];
    }
    function renderTypeFilterDropdown() {
        var list = document.getElementById('aprsTypeFilterList');
        var label = document.getElementById('aprsTypeFilterLabel');
        if (!list) return;
        var types = _typesInData();
        var hiddenCount = types.filter(function (t) { return hiddenTypes[t]; }).length;
        if (label) {
            if (types.length === 0)         label.textContent = '(none)';
            else if (hiddenCount === 0)     label.textContent = '(all)';
            else                            label.textContent = '(' + (types.length - hiddenCount) + '/' + types.length + ')';
        }
        if (types.length === 0) {
            list.innerHTML = '<div class="px-3 py-2 small text-body-secondary">No data yet</div>';
            return;
        }
        // Count stations per type for context
        var counts = {};
        stations.forEach(function (s) {
            var t = s.station_type || 'Other';
            counts[t] = (counts[t] || 0) + 1;
        });
        var html = '';
        types.forEach(function (t) {
            var checked = !hiddenTypes[t] ? 'checked' : '';
            html += '<label class="dropdown-item small d-flex align-items-center gap-2" style="cursor:pointer;">' +
                '<input type="checkbox" class="form-check-input aprs-type-cb m-0" ' + checked +
                    ' data-type="' + escapeAttr(t) + '">' +
                '<span class="flex-grow-1">' + escapeHtml(t) + '</span>' +
                '<span class="badge bg-secondary" style="font-size:0.65rem;">' + counts[t] + '</span>' +
                '</label>';
        });
        list.innerHTML = html;
        list.querySelectorAll('.aprs-type-cb').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var t = this.getAttribute('data-type');
                if (this.checked) delete hiddenTypes[t];
                else              hiddenTypes[t] = true;
                saveHiddenTypes();
                renderTypeFilterDropdown();   // refresh label + counts
                renderStations();
                renderList();
            });
        });
    }
    function _shouldExtrapolate(s) {
        if (s.station_type && _STATIC_TYPES[s.station_type]) return false;
        if (!s.symbol) return true;
        var c = s.symbol.charAt(1);
        return c !== '_' && c !== 'W';
    }

    function motionEnabled() {
        var toggle = document.getElementById('aprsMotionToggle');
        return !!(toggle && toggle.checked);
    }

    function startMotionLoop() {
        stopMotionLoop();
        if (!motionEnabled()) return;
        motionTimer = setInterval(motionTick, APRS_MOTION_TICK_MS);
    }
    function stopMotionLoop() {
        if (motionTimer) { clearInterval(motionTimer); motionTimer = null; }
        // Snap markers back to base + restore opacity when motion off.
        Object.keys(markers).forEach(function (cs) {
            var mk = markers[cs];
            if (mk && mk._aprsMotion) {
                mk.setLatLng([mk._aprsMotion.baseLat, mk._aprsMotion.baseLng]);
                var el = mk.getElement();
                if (el) el.style.opacity = '1';
                mk._aprsMotion._dimmed = false;
            }
        });
    }

    function motionTick() {
        var nowMs = Date.now();
        var timeoutMs = APRS_MOTION_TIMEOUT_MIN * 60 * 1000;
        Object.keys(markers).forEach(function (cs) {
            var mk = markers[cs];
            if (!mk || !mk._aprsMotion) return;
            var m = mk._aprsMotion;
            if (m.speedKph <= 0) return;   // static — stay put
            if (m.isWeather) return;       // wind data, not motion
            var elapsedMs = nowMs - m.anchorMs;
            // Cap elapsed at timeout so the marker freezes at the
            // last-projected position (no runaway, no snap-back).
            var effElapsedSec = Math.min(elapsedMs, timeoutMs) / 1000;
            var p = _projectPos(m.baseLat, m.baseLng, m.speedKph, m.heading, effElapsedSec);
            mk.setLatLng([p.lat, p.lng]);
            // Dim the icon once we've hit the timeout (one-time).
            if (elapsedMs > timeoutMs && !m._dimmed) {
                var el = mk.getElement();
                if (el) el.style.opacity = '0.55';
                m._dimmed = true;
            }
        });
    }

    function bindMotionToggle() {
        var toggle = document.getElementById('aprsMotionToggle');
        if (!toggle) return;
        var saved = localStorage.getItem('aprsMotionEnabled');
        toggle.checked = (saved === '1');
        toggle.addEventListener('change', function () {
            localStorage.setItem('aprsMotionEnabled', this.checked ? '1' : '0');
            if (this.checked) startMotionLoop();
            else stopMotionLoop();
        });
        if (toggle.checked) startMotionLoop();

        // Motion-timeout input (Phase 99h-v5). Restore saved value
        // + wire change-handler. Debounced to avoid re-rendering
        // on every keystroke.
        var timeoutInput = document.getElementById('aprsMotionTimeout');
        if (timeoutInput) {
            timeoutInput.value = APRS_MOTION_TIMEOUT_MIN;
            var debounce;
            timeoutInput.addEventListener('input', function () {
                clearTimeout(debounce);
                var val = this.value;
                debounce = setTimeout(function () { _setMotionTimeout(val); }, 400);
            });
        }
    }

    // ─── APRS symbol → emoji glyph (Eric beta 2026-06-29) ─────────
    //   APRS standard symbol set: 2 chars (table + char). Table is
    //   '/' (primary) or '\' (alternate, usually emergency variants).
    //   Full set has ~190 symbols; we cover the ~30 most common in
    //   amateur use + fall back to colored dot for the rest.
    //   Reference: http://www.aprs.org/symbols.html
    var APRS_SYMBOLS = {
        // Primary table (table char = '/')
        '/!': '🚓', '/"': '⚠️', '/#': '📡', '/$': '📞', '/%': '🌐',
        '/&': '🛰️',  "/'": '✈️', '/(': '🛰️', '/)': '♿', '/*': '🛷',
        '/+': '➕', '/,': '🏕️', '/-': '🏠', '/.': '❌', '//': '📍',
        '/:': '🔥', '/;': '🏕️', '/<': '🏍️', '/=': '🚂', '/>': '🚗',
        '/?': '🖥️', '/@': '🌀', '/A': '⛑️', '/B': '📋', '/C': '🛶',
        '/E': '👁️', '/F': '🚜', '/G': '🟦', '/H': '🏨', '/I': '💻',
        '/K': '🏫', '/L': '🗼', '/M': '🍎', '/N': '📨', '/O': '🎈',
        '/P': '👮', '/R': '🚙', '/S': '🚀', '/T': '📺', '/U': '🚌',
        '/V': '🚐', '/W': '🌤️', '/X': '🚁', '/Y': '⛵', '/Z': '📧',
        '/[': '🏃', '/]': '📬', '/^': '✈️', '/_': '🌤️',
        '/a': '🚑', '/b': '🚲', '/c': '🚓', '/d': '🚒', '/e': '🐎',
        '/f': '🚒', '/g': '🛩️', '/h': '🏥', '/i': '🏝️', '/j': '🚙',
        '/k': '🚚', '/l': '💻', '/m': '🎤', '/n': '⚫', '/o': '🏛️',
        '/p': '🐕', '/q': '🔲', '/r': '📡', '/s': '🚤', '/t': '⛽',
        '/u': '🚛', '/v': '🚐', '/w': '💧', '/y': '📡', '/z': '🏘️',
        // Alternate table (table char = '\') — emergency variants
        '\\!': '🚨', '\\#': '📡', '\\&': '🛰️', '\\+': '🆘',
        '\\-': '🏚️', '\\.': '⭕', '\\<': '🌀',
        '\\>': '🚗', '\\A': '🚧', '\\E': '🌧️', '\\F': '🌫️',
        '\\H': '🔵', '\\L': '💡', '\\M': '💧', '\\N': '🚫',
        '\\O': '🎈', '\\R': '⚠️', '\\S': '🛰️',
        '\\W': '⚠️', '\\Y': '🌐', '\\^': '✈️', '\\_': '🌪️',
        '\\h': '⚕️', '\\m': '🎤', '\\r': '📡', '\\v': '🚐',
        '\\w': '🌊', '\\y': '🌦️'
    };

    function _aprsBuildIcon(symCode, color, isWatched) {
        // Watched stations get a gold star badge at top-right + thicker
        // gold border. Distinguishes them from the standard age-color
        // visual without losing the age signal entirely.
        var watchOverlay = isWatched
            ? '<div style="position:absolute;top:-6px;right:-6px;font-size:16px;line-height:1;' +
              'text-shadow:0 1px 2px rgba(0,0,0,0.5);" title="On watchlist">⭐</div>'
            : '';
        var borderColor = isWatched ? '#ffc107' : color;
        var borderWidth = isWatched ? '3.5px'   : '2.5px';

        var glyph = APRS_SYMBOLS[symCode] || null;
        var html;
        if (glyph) {
            // Emoji glyph centered on a colored ring matching age class.
            html = '<div style="position:relative;width:28px;height:28px;' +
                   'border-radius:50%;background:#fff;border:' + borderWidth + ' solid ' + borderColor + ';' +
                   'display:flex;align-items:center;justify-content:center;' +
                   'box-shadow:0 1px 3px rgba(0,0,0,0.3);font-size:14px;line-height:1;">' +
                   glyph + watchOverlay + '</div>';
            return L.divIcon({
                html: html, className: 'aprs-icon-glyph' + (isWatched ? ' aprs-icon-watched' : ''),
                iconSize: [28, 28], iconAnchor: [14, 14]
            });
        }
        // Fallback for unknown symbols: small colored dot like before
        // (consistent visual language with the rest of the map).
        var dotSize  = isWatched ? 18 : 14;
        var dotAnch  = dotSize / 2;
        html = '<div style="position:relative;width:' + dotSize + 'px;height:' + dotSize + 'px;border-radius:50%;' +
               'background:' + color + ';border:' + (isWatched ? '2.5px' : '1.5px') + ' solid ' + (isWatched ? '#ffc107' : '#fff') + ';' +
               'box-shadow:0 1px 3px rgba(0,0,0,0.3);">' + watchOverlay + '</div>';
        return L.divIcon({
            html: html, className: 'aprs-icon-dot' + (isWatched ? ' aprs-icon-watched' : ''),
            iconSize: [dotSize, dotSize], iconAnchor: [dotAnch, dotAnch]
        });
    }

    function formatAge(sec) {
        if (sec == null) return 'unknown time';
        if (sec < 60) return sec + 's';
        if (sec < 3600) return Math.floor(sec / 60) + 'm';
        if (sec < 86400) return Math.floor(sec / 3600) + 'h';
        return Math.floor(sec / 86400) + 'd';
    }
    function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function escapeAttr(s) { return String(s).replace(/"/g, '&quot;'); }

    // ─── Column definitions (Phase 99h-v2, 2026-06-29) ───────────
    //   Each column has:
    //     key       — sort key + DOM id
    //     label     — header text
    //     sortable  — has a sort indicator
    //     numeric   — sort numerically (vs case-insensitive string)
    //     defaultVisible — initial visibility before user customization
    //     align     — th + td text alignment ('start'|'end'|'center')
    //     thStyle   — extra inline style on the th
    //     render    — fn(station, ctx) → cell innerHTML
    //   The Watched column uses a checkbox (Eric beta request:
    //   "table to show a column heading that says watched or
    //   monitor. I think you are using a star icon and I'd rather
    //   just have a check box.").
    var COLUMNS = [
        {
            key: '_watched', label: 'Watched', sortable: true,
            defaultVisible: true, align: 'center', thStyle: 'width:80px;',
            render: function (s, ctx) {
                var isW = ctx.watched;
                if (ctx.canWrite) {
                    return '<input type="checkbox" class="form-check-input aprs-watch-cb" ' +
                        'data-cs="' + escapeAttr(s.callsign) + '"' + (isW ? ' checked' : '') +
                        ' title="' + (isW ? 'Remove from watchlist' : 'Add to watchlist') + '">';
                }
                return '<input type="checkbox" class="form-check-input" disabled' +
                    (isW ? ' checked' : '') + ' title="' + (isW ? 'Watched' : 'Not watched') + '">';
            }
        },
        { key: 'callsign', label: 'Callsign', sortable: true, defaultVisible: true,
          render: function (s) { return '<span class="font-monospace fw-semibold">' + escapeHtml(s.callsign) + '</span>'; } },
        { key: 'age_sec', label: 'Last heard', sortable: true, numeric: true, defaultVisible: true,
          render: function (s) {
            var c = (s.age_sec < 300) ? 'text-success' : (s.age_sec < 1800) ? 'text-warning' : 'text-body-secondary';
            return '<span class="small ' + c + '">' + formatAge(s.age_sec) + '</span>';
          } },
        { key: 'reports', label: 'Reports', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) { return '<span class="small">' + (s.reports || 1) + '</span>'; } },
        { key: 'lat', label: 'Lat', sortable: true, numeric: true, defaultVisible: true,
          render: function (s) { return '<span class="font-monospace small">' + (s.lat != null ? s.lat.toFixed(4) : '') + '</span>'; } },
        { key: 'lng', label: 'Lng', sortable: true, numeric: true, defaultVisible: true,
          render: function (s) { return '<span class="font-monospace small">' + (s.lng != null ? s.lng.toFixed(4) : '') + '</span>'; } },
        { key: 'altitude', label: 'Alt', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) {
            var ft = (s.altitude != null) ? Math.round(s.altitude * 3.28084) : null;
            return '<span class="small">' + (ft != null ? ft + ' ft' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'speed', label: 'Speed', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) {
            var mph = Math.round(s.speed * 0.621371);
            return '<span class="small">' + (s.speed > 0 ? mph + ' mph' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'heading', label: 'Hdg', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.heading > 0 ? Math.round(s.heading) + '°' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'symbol', label: 'Sym', sortable: true, defaultVisible: true, align: 'center',
          render: function (s) { return '<span class="font-monospace small" title="APRS symbol code">' + escapeHtml(s.symbol || '') + '</span>'; } },
        // Phase 99h-v3 (2026-06-29) — Eric beta: classify stations
        // + surface weather sensors.
        { key: 'station_type', label: 'Type', sortable: true, defaultVisible: true,
          render: function (s) {
            var t = s.station_type || 'Other';
            var cls = 'badge bg-secondary';
            if (t === 'Weather' || t === 'WX site') cls = 'badge bg-info text-dark';
            else if (t === 'Car' || t === 'Truck' || t === 'Jeep' || t === 'Van' ||
                     t === 'Bus' || t === 'RV' || t === 'Motorcycle' || t === 'HT/Handheld')
                cls = 'badge bg-primary';
            else if (t === 'Repeater' || t === 'Igate' || t === 'TCP/IP' || t === 'Node')
                cls = 'badge bg-success';
            else if (t === 'Fire truck' || t === 'Ambulance' || t === 'Police' ||
                     t === 'Hospital' || t === 'Emergency' || t === 'Red Cross')
                cls = 'badge bg-danger';
            else if (t === 'Aircraft' || t === 'Helicopter' || t === 'Balloon')
                cls = 'badge bg-warning text-dark';
            return '<span class="' + cls + '" style="font-size:0.7rem;">' + escapeHtml(t) + '</span>';
          } },
        { key: 'temp_f', label: 'Temp', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.temp_f != null ? s.temp_f + '°F' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'humidity', label: 'Hum', sortable: true, numeric: true, defaultVisible: false, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.humidity != null ? s.humidity + '%' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'wind_speed_mph', label: 'Wind', sortable: true, numeric: true, defaultVisible: true, align: 'end',
          render: function (s) {
            if (s.wind_speed_mph == null) return '<span class="text-body-tertiary small">—</span>';
            var w = s.wind_speed_mph + ' mph';
            if (s.wind_gust_mph != null && s.wind_gust_mph > s.wind_speed_mph) {
                w += ' (g' + s.wind_gust_mph + ')';
            }
            if (s.wind_dir != null) w += ' @ ' + s.wind_dir + '°';
            return '<span class="small">' + escapeHtml(w) + '</span>';
          } },
        { key: 'pressure_hpa', label: 'Bar', sortable: true, numeric: true, defaultVisible: false, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.pressure_hpa != null ? s.pressure_hpa.toFixed(1) + ' hPa' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'rain_1h_in', label: 'Rain 1h', sortable: true, numeric: true, defaultVisible: false, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.rain_1h_in != null ? s.rain_1h_in.toFixed(2) + '"' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'rain_today_in', label: 'Rain Today', sortable: true, numeric: true, defaultVisible: false, align: 'end',
          render: function (s) {
            return '<span class="small">' + (s.rain_today_in != null ? s.rain_today_in.toFixed(2) + '"' : '<span class="text-body-tertiary">—</span>') + '</span>';
          } },
        { key: 'destination', label: 'Dest', sortable: true, defaultVisible: true,
          render: function (s) { return '<span class="font-monospace small">' + escapeHtml(s.destination || '') + '</span>'; } },
        { key: 'path', label: 'Path', sortable: true, defaultVisible: false,
          render: function (s) {
            return '<span class="font-monospace small text-truncate d-inline-block" style="max-width:160px;" title="' +
                escapeAttr(s.path || '') + '">' + escapeHtml(s.path || '') + '</span>';
          } },
        { key: 'comment', label: 'Comment', sortable: true, defaultVisible: true,
          render: function (s) {
            return '<span class="small text-truncate d-inline-block" style="max-width:240px;" title="' +
                escapeAttr(s.comment || '') + '">' + escapeHtml(s.comment || '') + '</span>';
          } },
        { key: 'raw_data', label: 'Raw', sortable: false, defaultVisible: false,
          render: function (s) {
            return '<details><summary class="text-body-secondary small" style="cursor:pointer;">show</summary>' +
                   '<div class="text-break font-monospace" style="max-width:400px;font-size:0.7rem;">' +
                   escapeHtml(s.raw_data || '') + '</div></details>';
          } }
    ];

    // User-customizable column order + visibility. Defaults derived
    // from COLUMNS[*].defaultVisible. Persisted in localStorage.
    var colConfig = (function loadColConfig() {
        try {
            var saved = JSON.parse(localStorage.getItem('aprsListColConfig') || 'null');
            if (saved && Array.isArray(saved.order) && saved.visible) {
                // Make sure every COLUMNS key is represented (handles
                // future column additions that weren't in saved state).
                COLUMNS.forEach(function (c) {
                    if (saved.order.indexOf(c.key) === -1) saved.order.push(c.key);
                    if (!(c.key in saved.visible)) saved.visible[c.key] = c.defaultVisible;
                });
                return saved;
            }
        } catch (_) {}
        return {
            order: COLUMNS.map(function (c) { return c.key; }),
            visible: COLUMNS.reduce(function (acc, c) { acc[c.key] = c.defaultVisible; return acc; }, {})
        };
    })();
    function saveColConfig() {
        localStorage.setItem('aprsListColConfig', JSON.stringify(colConfig));
    }
    function visibleColumns() {
        return colConfig.order.map(function (k) {
            return COLUMNS.find(function (c) { return c.key === k; });
        }).filter(function (c) { return c && colConfig.visible[c.key]; });
    }

    function renderListHead() {
        var head = document.getElementById('aprsListHead');
        if (!head) return;
        var html = '';
        visibleColumns().forEach(function (c) {
            var align = c.align ? ' text-' + c.align : '';
            var ts = c.thStyle ? ' style="' + c.thStyle + '"' : '';
            if (c.sortable) {
                html += '<th class="aprs-list-sortable' + align + '" data-sort-key="' + c.key + '"' +
                        ts + ' style="cursor:pointer;user-select:none;' + (c.thStyle || '') + '">' +
                        escapeHtml(c.label) + ' <i class="bi bi-arrow-down-up text-body-tertiary small"></i></th>';
            } else {
                html += '<th class="' + align.trim() + '"' + ts + '>' + escapeHtml(c.label) + '</th>';
            }
        });
        head.innerHTML = html;

        // Re-bind sort header clicks (head is re-rendered when cols change)
        head.querySelectorAll('.aprs-list-sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var key = this.getAttribute('data-sort-key');
                if (listSort.key === key) {
                    listSort.dir = (listSort.dir === 'asc') ? 'desc' : 'asc';
                } else {
                    listSort.key = key; listSort.dir = 'asc';
                }
                renderList();
            });
        });
    }

    // ─── List rendering (Phase 99h-v2) ───────────────────────────
    //   Two new behaviors per Eric beta 2026-06-29:
    //   1. Watched switch toggle ON = FILTER to watched-only stations
    //      (declutters for admin curation). OFF = show all.
    //   2. Click a row → zoom map to that station's marker + open
    //      its popup. Mirrors situation-screen unit/incident click.
    function renderList() {
        var tbody = document.getElementById('aprsListBody');
        if (!tbody) return;
        var cols = visibleColumns();
        var filterTxt = (document.getElementById('aprsListFilter') || {}).value || '';
        filterTxt = filterTxt.trim().toLowerCase();

        // Filter chain: type filter → watched-only → text filter
        var rows = stations.filter(_typeAllowed);
        var watchedOnly = watchLayerVisible();
        if (watchedOnly) {
            rows = rows.filter(function (s) { return watchSet.has(String(s.callsign).toUpperCase()); });
        }
        if (filterTxt) {
            // Phase 99h-v2 fix (2026-06-29): drop `path` and `symbol`
            // from the filter haystack. Eric beta: typing "kc0cap"
            // matched KC0QNA-1 because that station's digipeater
            // path included "KC0CAP-10*" — true but confusing since
            // the Path column is hidden by default. Restrict to the
            // three user-meaningful fields (callsign, destination,
            // comment) for predictable "what I typed" matching.
            rows = rows.filter(function (s) {
                var hay = (s.callsign + ' ' + (s.destination || '') + ' ' +
                           (s.comment || '') + ' ' +
                           (s.station_type || '')).toLowerCase();
                return hay.indexOf(filterTxt) !== -1;
            });
        }

        // Sort
        var key = listSort.key, dir = listSort.dir, sign = (dir === 'desc') ? -1 : 1;
        var col = COLUMNS.find(function (c) { return c.key === key; });
        var numeric = col && col.numeric;
        rows.sort(function (a, b) {
            if (key === '_watched') {
                var aw = watchSet.has(String(a.callsign).toUpperCase()) ? 1 : 0;
                var bw = watchSet.has(String(b.callsign).toUpperCase()) ? 1 : 0;
                return -sign * (aw - bw);
            }
            var av = a[key], bv = b[key];
            if (numeric) {
                var an = parseFloat(av); if (isNaN(an)) an = -Infinity;
                var bn = parseFloat(bv); if (isNaN(bn)) bn = -Infinity;
                return sign * (an - bn);
            }
            av = (av == null ? '' : String(av)).toLowerCase();
            bv = (bv == null ? '' : String(bv)).toLowerCase();
            if (av < bv) return -sign;
            if (av > bv) return sign;
            return 0;
        });

        // Update header arrows
        document.querySelectorAll('.aprs-list-sortable').forEach(function (th) {
            var k = th.getAttribute('data-sort-key');
            var icon = th.querySelector('i');
            if (!icon) return;
            icon.className = (k === key)
                ? 'bi ' + (dir === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down') + ' text-primary small'
                : 'bi bi-arrow-down-up text-body-tertiary small';
        });

        // Count display
        var shown = document.getElementById('aprsListShownCount');
        if (shown) {
            var bits = [];
            if (watchedOnly) bits.push('watched only');
            if (filterTxt) bits.push('filtered');
            shown.textContent = rows.length + ' of ' + stations.length + ' station' +
                (stations.length === 1 ? '' : 's') + (bits.length ? ' (' + bits.join(', ') + ')' : '');
        }

        if (rows.length === 0) {
            var msg = (stations.length === 0) ? 'No stations heard in this window.'
                    : watchedOnly ? 'No watched stations in this window. Toggle Watched off, or add some via the checkboxes once stations appear.'
                    : 'No matches for filter.';
            tbody.innerHTML = '<tr><td colspan="' + cols.length + '" class="text-center text-body-secondary py-3">' + msg + '</td></tr>';
            return;
        }

        var ctx = { canWrite: watchCanWrite };
        var html = '';
        rows.forEach(function (s) {
            ctx.watched = watchSet.has(String(s.callsign).toUpperCase());
            html += '<tr class="aprs-row-clickable" data-cs="' + escapeAttr(s.callsign) + '">';
            cols.forEach(function (c) {
                var align = c.align ? ' text-' + c.align : '';
                html += '<td class="' + align.trim() + '">' + c.render(s, ctx) + '</td>';
            });
            html += '</tr>';
        });
        tbody.innerHTML = html;

        // Row click → zoom + open popup (toggle-back on second click
        // of the same row). Skip if the click was on the watch
        // checkbox. Eric beta 2026-06-29: situation-screen pattern —
        // click row to drill in, click same row again to pop back to
        // the previous view. Clicking a DIFFERENT row keeps the
        // original prev view so the third toggle still pops all the
        // way back.
        tbody.querySelectorAll('tr.aprs-row-clickable').forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                if (e.target.closest('.aprs-watch-cb')) return;
                var cs = this.getAttribute('data-cs');
                var marker = markers[cs];
                if (!marker || !map) return;
                if (activeRowCallsign === cs && prevMapView) {
                    // Toggle off — restore previous view
                    map.setView(prevMapView.center, prevMapView.zoom);
                    marker.closePopup();
                    activeRowCallsign = null;
                    prevMapView = null;
                    tbody.querySelectorAll('.aprs-row-active').forEach(function (r) { r.classList.remove('aprs-row-active'); });
                } else {
                    // Zoom in. Capture prev view only on the first
                    // drill-in (so multi-row navigation still pops
                    // back to where the user started).
                    if (!activeRowCallsign) {
                        prevMapView = { center: map.getCenter(), zoom: map.getZoom() };
                    }
                    map.setView(marker.getLatLng(), Math.max(map.getZoom(), 13));
                    marker.openPopup();
                    activeRowCallsign = cs;
                    tbody.querySelectorAll('.aprs-row-active').forEach(function (r) { r.classList.remove('aprs-row-active'); });
                    this.classList.add('aprs-row-active');
                }
            });
        });
        // Watch checkbox toggles
        tbody.querySelectorAll('.aprs-watch-cb').forEach(function (cb) {
            cb.addEventListener('change', function (e) {
                e.stopPropagation();
                toggleWatch(this.getAttribute('data-cs'));
            });
            // Stop click bubbling so row-click doesn't also fire
            cb.addEventListener('click', function (e) { e.stopPropagation(); });
        });
    }

    // ─── Columns dropdown (visibility + drag-to-reorder) ──────────
    function renderColsDropdown() {
        var list = document.getElementById('aprsColsList');
        if (!list) return;
        var html = '';
        colConfig.order.forEach(function (key) {
            var col = COLUMNS.find(function (c) { return c.key === key; });
            if (!col) return;
            var checked = colConfig.visible[key] ? 'checked' : '';
            html += '<div class="aprs-col-item" draggable="true" data-key="' + escapeAttr(key) + '">' +
                '<span class="aprs-col-grip"><i class="bi bi-grip-vertical"></i></span>' +
                '<input type="checkbox" class="form-check-input" ' + checked + '>' +
                '<span class="small flex-grow-1">' + escapeHtml(col.label) + '</span>' +
                '</div>';
        });
        list.innerHTML = html;

        list.querySelectorAll('.aprs-col-item').forEach(function (item) {
            // Checkbox toggle visibility
            var cb = item.querySelector('input[type=checkbox]');
            cb.addEventListener('change', function () {
                var k = item.getAttribute('data-key');
                colConfig.visible[k] = this.checked;
                saveColConfig();
                renderListHead();
                renderList();
            });
            // Click anywhere in the row (except checkbox) toggles too
            item.addEventListener('click', function (e) {
                if (e.target === cb) return;
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            });
            // Drag to reorder
            item.addEventListener('dragstart', function (e) {
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', item.getAttribute('data-key'));
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('dragging');
                list.querySelectorAll('.aprs-col-item').forEach(function (i) { i.classList.remove('drag-over'); });
            });
            item.addEventListener('dragover', function (e) {
                e.preventDefault();
                list.querySelectorAll('.aprs-col-item').forEach(function (i) { i.classList.remove('drag-over'); });
                item.classList.add('drag-over');
            });
            item.addEventListener('drop', function (e) {
                e.preventDefault();
                var draggedKey = e.dataTransfer.getData('text/plain');
                var targetKey = item.getAttribute('data-key');
                if (draggedKey === targetKey) return;
                var fromIdx = colConfig.order.indexOf(draggedKey);
                var toIdx = colConfig.order.indexOf(targetKey);
                if (fromIdx < 0 || toIdx < 0) return;
                colConfig.order.splice(fromIdx, 1);
                colConfig.order.splice(toIdx, 0, draggedKey);
                saveColConfig();
                renderColsDropdown();
                renderListHead();
                renderList();
            });
        });
    }
    function resetColsConfig() {
        colConfig = {
            order: COLUMNS.map(function (c) { return c.key; }),
            visible: COLUMNS.reduce(function (acc, c) { acc[c.key] = c.defaultVisible; return acc; }, {})
        };
        saveColConfig();
        renderColsDropdown();
        renderListHead();
        renderList();
    }

    // ─── GridStack widget layout (Phase 99h-v5, 2026-06-29) ───────
    //   Eric beta: "full widget style layout, like the situation
    //   page... wide monitor... place this in the upper 1/3".
    //   Each widget (Map / Stations) is freely draggable and
    //   resizable. Layout persists per-browser in localStorage.
    var aprsGrid = null;
    function initGridStack() {
        if (typeof GridStack === 'undefined') {
            console.error('GridStack not loaded — layout will be static');
            return;
        }
        aprsGrid = GridStack.init({
            cellHeight: 60,
            margin: 6,
            handle: '.card-header',
            float: true,        // allow free placement (no auto-compact)
            resizable: { handles: 'se' },
            columnOpts: {
                columnMax: 12,
                breakpoints: [{ w: 768, c: 1 }],
                layout: 'list',
            },
        }, '#aprsGrid');

        // Restore saved layout if present
        try {
            var saved = JSON.parse(localStorage.getItem('aprsGridLayout') || 'null');
            if (saved && Array.isArray(saved) && saved.length > 0) {
                aprsGrid.load(saved);
            }
        } catch (_) { /* fall through to default DOM-declared layout */ }

        // Persist on any drag/resize. Also invalidate map size so
        // Leaflet redraws tiles to match the new container dimensions.
        var persist = function () {
            try {
                localStorage.setItem('aprsGridLayout', JSON.stringify(aprsGrid.save(false)));
            } catch (_) {}
            if (map) map.invalidateSize();
        };
        aprsGrid.on('change', persist);
        aprsGrid.on('resizestop', function () { if (map) map.invalidateSize(); });
        aprsGrid.on('dragstop', persist);

        // Reset-layout link
        var resetLink = document.getElementById('aprsResetLayout');
        if (resetLink) {
            resetLink.addEventListener('click', function (e) {
                e.preventDefault();
                localStorage.removeItem('aprsGridLayout');
                location.reload();   // simplest reliable reset
            });
        }

        // Initial Leaflet redraw after the grid lays out
        setTimeout(function () { if (map) map.invalidateSize(); }, 100);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
</body>
</html>
