<?php
/**
 * NewUI v4.0 - Full-Screen Situation View
 *
 * Opens in a new browser window (target="_blank" from navbar).
 * Full-screen Leaflet map with semi-transparent overlay showing:
 *   - Active incident count, unit count
 *   - List of open incidents with type, address, severity color
 *   - Time-range dropdown (Current, Closed Today/Week/Month/Year)
 * Severity-colored map markers, click-to-zoom, SSE auto-refresh.
 */
require_once __DIR__ . '/config.php';

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
require_once __DIR__ . '/inc/rbac.php';
rbac_require_screen('screen.situation');
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();

// Phase 99o (Eric beta 2026-06-29) — admin-configured label
// ("Incident" / "Case" / "Call" / ...) so the situation list uses
// the same vocabulary as the dashboard widget and detail page.
require_once __DIR__ . '/inc/incident-number.php';
$incNumLabel = incnum_get_label();

$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'situation';

// Configurable auto-refresh cadences (seconds), read server-side so the JS
// has them synchronously. Defaults preserve prior behaviour — unit tracking
// every 10s, incident/board refresh every 15s. Admins can retune per install
// (a busy EOC may want faster polling; a slow uplink, slower). Floored so a
// bad value can't hammer the server.
$sitUnitRefreshSecs  = max(3, (int) get_setting('situation_unit_refresh_secs', 10));
$sitBoardRefreshSecs = max(5, (int) get_setting('situation_board_refresh_secs', 15));

// GH #58 (Eric 2026-07-05) — when the operator has LOCKED the situation view
// (zoomed/panned) and a NEW incident is created outside the current map extents,
// automatically unlock + re-fit so the new event is visible. Admin-configurable
// at settings.php#map-defaults (stored in the settings table via that form);
// default ON so a new incident is never missed. get_variable() returns false
// when unset → treat as enabled.
$sitResetOffscreenRaw = get_variable('situation_reset_on_offscreen');
$sitResetOffscreen = ($sitResetOffscreenRaw === false || $sitResetOffscreenRaw === '')
    ? 1 : ((int) $sitResetOffscreenRaw ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>EOC Display &mdash; Tickets CAD <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/unit-tracking.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/print.css" media="print">

    <style>
        /* Full-screen layout: map fills everything below the navbar */
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; overflow: hidden; }
        /* a beta tester beta 2026-06-29: navbar uses Bootstrap `sticky-top`
           (position: sticky) globally — but in a flex column with
           overflow:hidden, sticky doesn't reserve flex space in some
           browsers, so #sitContainer renders starting at y=0 and the
           overlay slides under the navbar. There's no scroll on this
           page anyway, so static positioning is fine and makes the
           flex column do the right thing: navbar takes its natural
           height, sitContainer fills the rest. */
        #appHeader { flex-shrink: 0; position: static; }
        #sitContainer { position: relative; flex: 1; overflow: hidden; min-height: 50vh; }
        /* Phase 68 — `dvh` lets the container track the visible viewport
           when iOS Safari / Android Chrome collapse the address bar.
           Falls back gracefully when dvh isn't supported. */
        @supports (height: 100dvh) {
            #sitContainer { min-height: 50dvh; }
        }
        #sitMap { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1; }

        /* Semi-transparent overlay panel */
        #sitOverlay {
            position: absolute; top: 10px; left: 10px; z-index: 1000;
            width: 480px; max-height: calc(100% - 20px); overflow-y: auto;
            background: rgba(var(--bs-body-bg-rgb), 0.88);
            border-radius: 8px; padding: 10px 12px;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.35);
        }
        #sitOverlay::-webkit-scrollbar { width: 5px; }
        #sitOverlay::-webkit-scrollbar-thumb { background: var(--bs-border-color); border-radius: 3px; }

        /* Summary bar */
        .sit-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .sit-summary .badge { font-size: 0.72rem; }

        /* Incident table */
        .sit-table { font-size: 0.73rem; margin-bottom: 0; }
        .sit-table th { font-size: 0.63rem; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; }
        .sit-table td { padding: 3px 6px; vertical-align: middle; }
        .sit-row { cursor: pointer; transition: background 0.15s; }
        .sit-row:hover { background: rgba(var(--bs-primary-rgb), 0.12) !important; }
        .sit-row.active { background: rgba(var(--bs-primary-rgb), 0.2) !important; }

        /* Severity dot */
        .sev-dot {
            display: inline-block; width: 10px; height: 10px;
            border-radius: 50%; border: 1px solid rgba(0,0,0,0.2);
        }

        /* Phase 109 Slice D — permanent zone-name labels on the map. Readable
           at command-vehicle distance, no bubble chrome. */
        .sit-zone-label {
            background: transparent; border: none; box-shadow: none;
            font-weight: 700; font-size: 0.85rem; color: #212529;
            text-shadow: 0 0 3px #fff, 0 0 5px #fff;
        }
        .sit-zone-label::before { display: none; }

        /* Collapse toggle icon */
        .sit-toggle { cursor: pointer; user-select: none; }
        .sit-toggle .bi { transition: transform 0.2s; }
        .sit-toggle.collapsed .bi { transform: rotate(-90deg); }

        /* Phase 70 — Mobile layout. Abandon the absolute-positioned
           overlay-over-map design on phones: it tried to do too much
           in too little vertical space and the map kept rendering
           blank because its flex parent settled to 0 height while JS
           ran. Switch to a plain vertical stack: navbar → fixed-
           height map → incidents list that scrolls with the page.
           Supervisors get the same data, just stacked instead of
           layered. */
        @media (max-width: 768px) {
            html, body {
                height: auto;
                overflow: auto !important;
            }
            body { display: block; }
            #sitContainer {
                display: flex;
                flex-direction: column;
                position: static;
                height: auto;
                min-height: 0;
                overflow: visible;
            }
            #sitMap {
                position: relative;
                width: 100%;
                height: 50vh;
                min-height: 280px;
                z-index: 1;
            }
            @supports (height: 100dvh) {
                #sitMap { height: 50dvh; }
            }
            #sitOverlay {
                position: static;
                width: 100%;
                max-height: none;
                border-radius: 0;
                margin: 0;
                padding: 10px 12px;
                background: var(--bs-body-bg);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                box-shadow: none;
                border-top: 1px solid var(--bs-border-color);
                overflow-y: visible;
            }
            /* Map control overlays would collide with the new stacked
               layout — hide the optional draw/markups affordances on
               narrow screens. They're available on the desktop UI. */
            #drawToolbar, #markupsPanel { display: none !important; }
            /* Incident table: wider hit targets and word-break on the
               address column so a long street doesn't blow the layout. */
            .sit-table { font-size: 0.85rem; }
            .sit-table td, .sit-table th { padding: 6px 4px; }
            .sit-row td:nth-child(4) { word-break: break-word; }
        }

        /* 2026-06-11 — Stack map controls vertically along the right
           edge so they don't overlap. Leaflet's layer control occupies
           the top-right corner; the draw toolbar drops below it; the
           markups panel sits to the left of the draw toolbar. */
        .leaflet-top.leaflet-right { z-index: 1010; }
        #drawToolbar {
            position: absolute; top: 200px; right: 10px; z-index: 1000;
            display: flex; flex-direction: column; gap: 4px;
        }
        .draw-btn {
            width: 34px; height: 34px;
            border: 2px solid rgba(0,0,0,0.25);
            border-radius: 4px;
            background: var(--bs-body-bg, #fff);
            color: var(--bs-body-color, #333);
            font-size: 0.85rem;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s, border-color 0.15s;
        }
        .draw-btn:hover { background: var(--bs-secondary-bg); }
        .draw-btn.active { border-color: var(--bs-primary); background: rgba(var(--bs-primary-rgb),0.15); }

        /* Markups toggle panel */
        #markupsPanel {
            position: absolute; top: 200px; right: 55px; z-index: 1000;
            background: rgba(var(--bs-body-bg-rgb), 0.92);
            border-radius: 6px; padding: 8px;
            backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: none; max-height: 300px; overflow-y: auto;
            min-width: 180px; font-size: 0.75rem;
        }
        #markupsPanel.show { display: block; }
        .markup-item { display: flex; align-items: center; gap: 6px; padding: 3px 0; }
        .markup-item label { cursor: pointer; margin: 0; }
        .markup-swatch {
            width: 12px; height: 12px; border-radius: 2px;
            border: 1px solid rgba(0,0,0,0.2); flex-shrink: 0;
        }
    </style>
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<div id="sitContainer">
    <div id="sitMap"></div>

    <div id="sitOverlay">
        <!-- Header row -->
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0 fw-bold" id="sitTitle">
                <i class="bi bi-display me-1"></i>Situation
            </h6>
            <div class="d-flex align-items-center gap-2">
                <select class="form-select form-select-sm" id="sitTimeRange" style="width:auto;font-size:0.72rem;"
                        title="Choose which incidents to show. 'Current' keeps recently-closed incidents on screen for the duration set below.">
                    <option value="0">Current</option>
                    <option value="1">Closed Today</option>
                    <option value="5">Closed This Week</option>
                    <option value="7">Closed This Month</option>
                    <option value="9">Closed This Year</option>
                </select>
                <!-- 2026-06-11 — User-tunable recent-closed window for the
                     'Current' view. Closed incidents stay clickable for
                     this many minutes after closure. Saved per-user
                     via screen-prefs ('situation' screen). -->
                <div class="input-group input-group-sm" id="sitRecentCloseWrap" style="width:auto;font-size:0.72rem;">
                    <span class="input-group-text py-0 px-1" style="font-size:0.7rem;" title="How long recently-closed incidents stay visible">Keep&nbsp;closed</span>
                    <input type="number" min="0" max="10080" step="15" class="form-control form-control-sm py-0 px-1"
                           id="sitRecentCloseMins" style="width:60px;font-size:0.7rem;" value="30">
                    <span class="input-group-text py-0 px-1" style="font-size:0.7rem;">min</span>
                </div>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" id="sitCollapse" title="Toggle panel">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </div>
        </div>

        <!-- Summary counts -->
        <div class="sit-summary mb-2" id="sitSummary">
            <span class="badge bg-primary"><i class="bi bi-exclamation-triangle me-1"></i>Incidents: <span id="cntIncidents">0</span></span>
            <span class="badge bg-info"><i class="bi bi-people me-1"></i>Units: <span id="cntUnits">0</span></span>
            <span class="badge bg-success" id="badgeSev0" title="Normal severity">Normal: <span id="cntSev0">0</span></span>
            <span class="badge bg-warning text-dark" id="badgeSev1" title="Medium severity">Medium: <span id="cntSev1">0</span></span>
            <span class="badge bg-danger" id="badgeSev2" title="High severity">High: <span id="cntSev2">0</span></span>
        </div>

        <!-- Phase 107 (issue #23): tab strip to switch the panel body
             between Incidents / Units / Facilities. All three share
             the same map real-estate; each renders its own list.
             Tab selection persists per-user via localStorage. -->
        <ul class="nav nav-tabs nav-sm mb-2" id="sitTabs" style="font-size:0.72rem;">
            <li class="nav-item"><a class="nav-link active py-1 px-2" href="#" data-sittab="incidents"><i class="bi bi-exclamation-triangle me-1"></i>Incidents</a></li>
            <li class="nav-item"><a class="nav-link py-1 px-2"        href="#" data-sittab="units"><i class="bi bi-truck me-1"></i>Units</a></li>
            <li class="nav-item"><a class="nav-link py-1 px-2"        href="#" data-sittab="facilities"><i class="bi bi-building me-1"></i>Facilities</a></li>
            <li class="nav-item"><a class="nav-link py-1 px-2"        href="#" data-sittab="events"><i class="bi bi-journal-text me-1"></i>Events</a></li>
            <!-- Eric 2026-07-07 (#67): Major moved from the navbar into
                 incident context -->
            <li class="nav-item ms-auto"><a class="nav-link py-1 px-2" href="major-incidents.php"
                title="Major incidents — link incidents under a command structure"><i class="bi bi-diagram-3 me-1"></i>Major</a></li>
        </ul>

        <!-- Incident list -->
        <div id="sitBody" data-sittab-body="incidents">
            <!-- GH #63 (a beta tester) — per-user column customization, same
                 ScreenPrefs infrastructure as units.php/facilities.php. -->
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 mb-1" id="btnSitIncidentCols"
                        title="Customize columns" style="font-size:0.7rem;">
                    <i class="bi bi-layout-three-columns"></i>
                </button>
            </div>
            <table class="table table-sm table-hover sit-table" id="sitIncidentsTable">
                <thead>
                    <tr>
                        <th data-col-id="sev" data-col-label="Sev">Sev</th>
                        <!-- Phase 99o (Eric beta 2026-06-29) — show the
                             admin-configured case number, not the
                             internal id (which dispatchers ignore). -->
                        <th data-col-id="case" data-col-label="<?php echo e($incNumLabel); ?> #"><?php echo e($incNumLabel); ?> #</th>
                        <th data-col-id="scope" data-col-label="Scope">Scope</th>
                        <th data-col-id="type" data-col-label="Type">Type</th>
                        <th data-col-id="address" data-col-label="Address">Address</th>
                        <th data-col-id="units" data-col-label="Units">Units</th>
                        <th data-col-id="updated" data-col-label="Updated">Updated</th>
                    </tr>
                </thead>
                <tbody id="sitIncidentList">
                    <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Phase 107 — Units list. Hidden until the tab is active. -->
        <div id="sitUnitsBody" data-sittab-body="units" style="display:none;">
            <!-- GH #63 (a beta tester) — per-user column customization. -->
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 mb-1" id="btnSitUnitCols"
                        title="Customize columns" style="font-size:0.7rem;">
                    <i class="bi bi-layout-three-columns"></i>
                </button>
            </div>
            <table class="table table-sm table-hover sit-table" id="sitUnitsTable">
                <thead>
                    <tr>
                        <th data-col-id="dot" data-col-label="Status Dot">&nbsp;</th>
                        <th data-col-id="unit" data-col-label="Unit">Unit</th>
                        <th data-col-id="callsign" data-col-label="Callsign">Callsign</th>
                        <th data-col-id="principal" data-col-label="Principal">Principal</th>
                        <th data-col-id="status" data-col-label="Status">Status</th>
                        <th data-col-id="location" data-col-label="Location">Location</th>
                        <th data-col-id="updated" data-col-label="Updated">Updated</th>
                    </tr>
                </thead>
                <tbody id="sitUnitsList">
                    <tr><td colspan="7" class="text-center text-body-secondary py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Phase 107 — Facilities list. Hidden until the tab is active. -->
        <div id="sitFacilitiesBody" data-sittab-body="facilities" style="display:none;">
            <table class="table table-sm table-hover sit-table">
                <thead>
                    <tr>
                        <th>&nbsp;</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Address</th>
                    </tr>
                </thead>
                <tbody id="sitFacilitiesList">
                    <tr><td colspan="5" class="text-center text-body-secondary py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- GH #78 — recent events feed incl. unit + facility notes -->
        <div id="sitEventsBody" data-sittab-body="events" style="display:none;">
            <table class="table table-sm table-hover sit-table" id="sitEventsTable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>By</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody id="sitEventsList">
                    <tr><td colspan="4" class="text-center text-body-secondary py-3">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Draw Toolbar -->
    <div id="drawToolbar">
        <button class="draw-btn" id="drawMarker" title="Place marker"><i class="bi bi-geo-alt"></i></button>
        <button class="draw-btn" id="drawCircle" title="Draw circle"><i class="bi bi-circle"></i></button>
        <button class="draw-btn" id="drawPolyline" title="Draw line"><i class="bi bi-pencil"></i></button>
        <button class="draw-btn" id="drawPolygon" title="Draw polygon"><i class="bi bi-pentagon"></i></button>
        <button class="draw-btn" id="drawFinish" title="Finish polygon/line" style="display:none;background:var(--bs-success);color:#fff;"><i class="bi bi-check-lg"></i></button>
        <button class="draw-btn" id="drawCancel" title="Cancel drawing" style="display:none;"><i class="bi bi-x-lg text-danger"></i></button>
        <hr style="margin:2px 0;border-color:var(--bs-border-color);">
        <button class="draw-btn" id="toggleMarkups" title="Toggle saved markups"><i class="bi bi-layers"></i></button>
    </div>

    <!-- Markups Toggle Panel -->
    <div id="markupsPanel">
        <div class="fw-semibold mb-1"><i class="bi bi-layers me-1"></i>Saved Markups</div>
        <div id="markupsList"><small class="text-body-secondary">Loading...</small></div>
    </div>
</div>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/leaflet-quadkey.js"></script>
<script src="assets/js/map-prefs.js"></script>
<script src="assets/js/map-image-overlays.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/map-image-overlays.js') : NEWUI_VERSION; ?>"></script>
<script src="assets/js/screen-prefs.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/unit-tracking.js"></script>
<script src="assets/js/event-bus.js"></script>
<script src="assets/js/facility-status.js?v=<?php echo function_exists('asset_v') ? asset_v('assets/js/facility-status.js') : NEWUI_VERSION; ?>"></script>

<script>
(function () {
    'use strict';

    // ── State ──
    var map, tileLayer, markerGroup;
    var roadConditionsGroup = null;
    var roadConditionsLoaded = false;
    var incidents = [];
    var unitCount = 0;
    var sevColors = {};
    var defaultLat = 39.8283;
    var defaultLng = -98.5795;
    var defaultZoom = 5;
    var refreshTimer = null;
    var panelCollapsed = false;
    // GH #58 — reset a locked view when a NEW incident lands off-screen.
    var SIT_RESET_ON_OFFSCREEN = <?php echo (int) $sitResetOffscreen; ?>;
    var _seenIncidentIds = null;   // null until the first incident load

    // ── Map Initialization ──
    var osmLight, cartoDark, topoMap; // basemap references for theme switching
    // Eric 2026-07-03 (EOC event) — track whether the user has taken
    // manual control of the view. Once they do, refresh cycles must
    // never re-zoom or re-center; they can hit the ★ button to opt
    // back into auto-fit. _initialFitDone flips true after the very
    // first successful fitBounds so subsequent ticks don't re-fit
    // even if _userLockedView hasn't caught yet.
    var _userLockedView = false;
    var _initialFitDone = false;
    // Eric 2026-07-05 (#58) — mark our OWN fitBounds/setView so the user-view
    // lock can tell them apart from a real user zoom/pan. The old lock keyed
    // off Leaflet's `originalEvent`, which is ABSENT on the on-screen +/- zoom
    // buttons — so zooming with those buttons never locked the view, and the
    // next incident refresh snapped it back (the EOC "resets every few
    // seconds" bug). Now any view change that ISN'T one of our programmatic
    // fits locks the view.
    var _programmaticView = false;
    function _progFit(fn) {
        _programmaticView = true;
        try { fn(); } finally {
            // Clear only after the resulting zoomend/moveend settle. fitBounds
            // animates ~250ms; a bare setTimeout(0) can fire before moveend.
            setTimeout(function () { _programmaticView = false; }, 450);
        }
    }
    // Eric 2026-07-04 — relative auto-fit tightness. The map keeps
    // fitting to include ALL active incidents, but this bias shifts how
    // tight that fit is: + = zoom in closer (fill the screen with the
    // half-mile park), − = zoom out looser (leave margin to watch an
    // approaching storm on radar). Persisted per browser. Re-fit runs
    // only when the incident SET changes (or the bias changes), so it
    // no longer clobbers the view on every idle SSE tick.
    var ZOOM_BIAS_KEY = 'newui_situation_zoom_bias';
    var _zoomBias = 0;
    try {
        var _zb = parseInt(localStorage.getItem(ZOOM_BIAS_KEY), 10);
        if (!isNaN(_zb)) _zoomBias = Math.max(-4, Math.min(4, _zb));
    } catch (e) {}
    var _lastFitSig = null;
    function initMap() {
        map = L.map('sitMap', {
            zoomControl: true,
            attributionControl: true
        }).setView([defaultLat, defaultLng], defaultZoom);
        // GH #76 — auto-hide marker name labels when zoomed out + a toggle.
        if (window.TypeIcons && window.TypeIcons.bindLabelZoom) { window.TypeIcons.bindLabelZoom(map); }

        // Phase 68 — on mobile the container's height settles AFTER
        // L.map() runs (address bar collapse, dvh kick-in, etc.). Without
        // a follow-up invalidateSize the map renders into a 0-height
        // canvas and stays blank. Multiple attempts cover slow phones
        // and address-bar transitions.
        setTimeout(function () { if (map) map.invalidateSize(); }, 100);
        setTimeout(function () { if (map) map.invalidateSize(); }, 500);
        setTimeout(function () { if (map) map.invalidateSize(); }, 1500);

        // Expose for draw controls script
        window._sitMap = map;

        // Base layers
        osmLight = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OSM', maxZoom: 19
        });
        cartoDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; CartoDB', maxZoom: 19
        });
        topoMap = L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenTopoMap', maxZoom: 17
        });

        // Set tileLayer reference and add preferred default basemap
        var prefKey = window.MapPrefs ? window.MapPrefs.getBasemap() : (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'street');
        var prefMap = { street: osmLight, dark: cartoDark, terrain: topoMap };
        tileLayer = prefMap[prefKey] || osmLight;
        tileLayer.addTo(map);

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

        // Road-conditions overlay (maps-comprehensive-2026-06) — toggleable
        // layer plotting roadinfo reports with the condition icon + a popup.
        roadConditionsGroup = L.layerGroup();

        // Issue #53 (a beta tester 2026-07-03) — live precipitation RADAR for
        // the EOC display. RainViewer: free, no API key, global mosaic.
        // Their tile path embeds a frame timestamp, so we fetch the
        // frame catalog and point the layer at the newest 'past' frame,
        // then re-check every 5 minutes so a wall display stays current
        // through a storm without a reload. Color scheme 4 = Universal
        // Blue; trailing 1_1 = smoothed + snow shown.
        // maxNativeZoom: 7 — RainViewer's radar mosaic only renders through
        // zoom 7 (z8+ returns a "Zoom Level Not Supported" placeholder tile
        // everywhere). Without this cap Leaflet keeps requesting radar tiles
        // as you zoom in and paints those placeholders over the map. With it,
        // Leaflet upscales the z7 tile instead — coarse but continuous, no
        // error tiles. maxZoom stays 19 so the base map + markers still zoom
        // in fully. (Eric, 2026-07-05 — #53 follow-up.)
        var radarLayer = L.tileLayer('', { opacity: 0.7, maxZoom: 19, maxNativeZoom: 7, errorTileUrl: '' });
        function refreshRadarFrame() {
            fetch('https://api.rainviewer.com/public/weather-maps.json')
                .then(function (r) { return r.json(); })
                .then(function (cat) {
                    var frames = (cat && cat.radar && cat.radar.past) || [];
                    if (!frames.length) return;
                    var latest = frames[frames.length - 1];
                    var host = cat.host || 'https://tilecache.rainviewer.com';
                    radarLayer.setUrl(host + latest.path + '/256/{z}/{x}/{y}/4/1_1.png');
                })
                .catch(function () { /* offline / blocked — layer stays empty */ });
        }
        refreshRadarFrame();
        setInterval(refreshRadarFrame, 5 * 60 * 1000);

        // NOAA/NWS MRMS base reflectivity (1 km CONUS, quality-controlled,
        // event-driven ~2-min updates). Unlike RainViewer's cached global
        // mosaic (native max zoom 7, coarse when zoomed in), this ArcGIS
        // service renders DYNAMICALLY, so it stays sharp at ANY zoom — ideal
        // for watching weather over a specific event site. WMS is disabled on
        // the endpoint, so we use the ArcGIS REST `export` API via a tile
        // layer that computes each tile's Web-Mercator (EPSG:3857) bbox.
        // US-only coverage; no key. (Eric, 2026-07-05 — #53.)
        var NOAA_MRMS_EXPORT = 'https://mapservices.weather.noaa.gov/eventdriven/rest/services/radar/radar_base_reflectivity/MapServer/export';
        var MERC_HALF = 20037508.342789244; // half the Web-Mercator world extent (m)
        var NoaaRadarLayer = L.TileLayer.extend({
            getTileUrl: function (coords) {
                var span = (2 * MERC_HALF) / Math.pow(2, coords.z);
                var minX = -MERC_HALF + coords.x * span;
                var maxX = minX + span;
                var maxY = MERC_HALF - coords.y * span;
                var minY = maxY - span;
                return NOAA_MRMS_EXPORT
                    + '?bbox=' + minX + ',' + minY + ',' + maxX + ',' + maxY
                    + '&bboxSR=3857&imageSR=3857&size=256,256&format=png32'
                    + '&transparent=true&layers=show:0&f=image&_ts=' + (this._noaaTs || 0);
            }
        });
        var noaaRadarLayer = new NoaaRadarLayer('', { opacity: 0.75, maxZoom: 19, errorTileUrl: '' });
        // Cache-bust + redraw on the MRMS cadence so a wall display stays live.
        function refreshNoaaRadar() {
            noaaRadarLayer._noaaTs = (new Date()).getTime();
            if (map.hasLayer(noaaRadarLayer)) { noaaRadarLayer.redraw(); }
        }
        refreshNoaaRadar();
        setInterval(refreshNoaaRadar, 150000); // ~2.5 min

        var overlays = {
            'Radar — US (NWS)': noaaRadarLayer,
            'Radar — Global': radarLayer,
            'Temperature': weatherTemp,
            'Precipitation': weatherPrecip,
            'Wind': weatherWind,
            'Clouds': weatherClouds,
            '● Road Conditions': roadConditionsGroup
        };

        var sitLayersControl = L.control.layers(baseMaps, overlays, { collapsed: true, position: 'topright' }).addTo(map);

        // GH #43 (Phase 110) — fold any configured event map image
        // overlays into the layer control. Each enabled+positioned
        // overlay becomes a toggleable layer sitting above the base
        // tiles but below markups/units/incidents (own map pane).
        if (window.MapImageOverlays && typeof window.MapImageOverlays.attach === 'function') {
            window.MapImageOverlays.attach(map, sitLayersControl);
        }

        // ── Configured tile provider (specs/configurable-tile-providers-2026-06) ──
        // Fold the admin-configured Tile Provider in as an additional base
        // layer once map-prefs.js has fetched it. Additive + async; the
        // built-in Street/Dark/Terrain options and default are unchanged.
        if (window.MapPrefs && typeof window.MapPrefs.init === 'function') {
            window.MapPrefs.init().then(function () {
                var label = window.MapPrefs.getCustomLabel();
                if (label && !sitLayersControl._ticketsCustomAdded) {
                    sitLayersControl.addBaseLayer(window.MapPrefs.makeLayer('custom'), label);
                    sitLayersControl._ticketsCustomAdded = true;
                }
            });
        }

        // #60/#46 (Eric 2026-07-05) — give the situation control the SAME
        // per-category map-overlay toggles (race markers, zones, parade
        // routes, ...) the dashboard and unit detail/edit maps already have,
        // so an operator can turn individual overlays on/off here too. Shared
        // logic in map-prefs.js; each category persists its own on/off state.
        if (window.MapPrefs && typeof window.MapPrefs.addMarkupOverlays === 'function'
            && !sitLayersControl._ticketsMarkupAdded) {
            window.MapPrefs.addMarkupOverlays(map, sitLayersControl);
            sitLayersControl._ticketsMarkupAdded = true;
        }

        // ── Phase 109 Slice D — event-zone geometry overlay ──
        // Draw the ACTIVE event's zones (points/polygons from event_zones.geo_json,
        // set via the Net Control zones editor) so the big screen shades Zone 3
        // etc. — the map IS the shared operating picture (decision #2). On by
        // default; toggleable in the layer control. Zones without geometry are
        // symbolic-only and simply don't render. Refreshes every 60s.
        var eventZonesGroup = L.layerGroup().addTo(map);
        sitLayersControl.addOverlay(eventZonesGroup,
            '<span style="color:#6f42c1">&#9679;</span> Event Zones');
        function _addZoneLayer(z) {
            var geom;
            try { geom = JSON.parse(z.geo_json); } catch (e) { return; }
            if (!geom) return;
            var color = z.color || '#6f42c1';
            try {
                var layer = L.geoJSON(geom, {
                    style: { color: color, weight: 2, fillColor: color, fillOpacity: 0.15 },
                    pointToLayer: function (f, latlng) {
                        return L.circleMarker(latlng, {
                            radius: 9, color: color, weight: 2,
                            fillColor: color, fillOpacity: 0.5
                        });
                    }
                });
                layer.bindTooltip(z.name, { permanent: true, direction: 'center', className: 'sit-zone-label' });
                eventZonesGroup.addLayer(layer);
            } catch (e) { /* bad geometry — skip the zone, never break the map */ }
        }
        function loadEventZones() {
            fetch('api/active-event.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (ae) {
                    var tid = ae && ae.active_event_ticket_id ? parseInt(ae.active_event_ticket_id, 10) : 0;
                    if (!tid) { eventZonesGroup.clearLayers(); return null; }
                    return fetch('api/event-zones.php?ticket_id=' + tid, { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            eventZonesGroup.clearLayers();
                            var zones = (data && data.zones) || [];
                            for (var zi = 0; zi < zones.length; zi++) {
                                if (zones[zi].hide || !zones[zi].geo_json) continue;
                                _addZoneLayer(zones[zi]);
                            }
                        });
                })
                .catch(function () { /* offline / no perms — layer stays empty */ });
        }
        loadEventZones();
        setInterval(loadEventZones, 60000);

        // ── Phase 112 Phase 4 — active NWS alert polygons + who's inside ──
        // Renders active warning polygons in severity colours; the popup names
        // the units (and active-event zones) currently INSIDE each polygon,
        // computed live by api/weather-alerts.php. Empty unless the install has
        // weather alerts enabled — a weather-off install fetches once per
        // minute and draws nothing.
        var weatherAlertGroup = L.layerGroup().addTo(map);
        sitLayersControl.addOverlay(weatherAlertGroup,
            '<span style="color:#dc3545">&#9650;</span> Weather Alerts');
        var WX_SEV_COLOR = { 'Extreme': '#dc3545', 'Severe': '#fd7e14',
                             'Moderate': '#ffc107', 'Minor': '#6c757d' };
        function _addWeatherAlertLayer(a) {
            var geom;
            try { geom = JSON.parse(a.polygon); } catch (e) { return; }
            if (!geom) return;
            var color = WX_SEV_COLOR[a.severity] || '#6c757d';
            try {
                var layer = L.geoJSON(geom, {
                    style: { color: color, weight: 2, dashArray: '6 4',
                             fillColor: color, fillOpacity: 0.12 }
                });
                var html = '<strong>' + esc(a.event || 'Weather alert') + '</strong>' +
                           '<br>' + esc(a.area_desc || '') +
                           (a.expires ? '<br>Expires: ' + esc(a.expires) : '');
                var ui = a.units_inside || [];
                if (ui.length) {
                    var names = [];
                    for (var n = 0; n < ui.length; n++) names.push(esc(ui[n].unit_identifier));
                    html += '<br><span class="text-danger fw-bold">Units inside: ' + names.join(', ') + '</span>';
                }
                var zi = a.zones_inside || [];
                if (zi.length) {
                    var znames = [];
                    for (var zn = 0; zn < zi.length; zn++) znames.push(esc(zi[zn].name));
                    html += '<br><span class="fw-bold">Zones affected: ' + znames.join(', ') + '</span>';
                }
                layer.bindPopup(html);
                weatherAlertGroup.addLayer(layer);
            } catch (e) { /* bad polygon — skip, never break the map */ }
        }
        function loadWeatherAlertPolys() {
            fetch('api/weather-alerts.php?action=active', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    weatherAlertGroup.clearLayers();
                    if (!data || !data.enabled) return;
                    var alerts = data.alerts || [];
                    for (var wi = 0; wi < alerts.length; wi++) {
                        if (!alerts[wi].polygon) continue;
                        _addWeatherAlertLayer(alerts[wi]);
                    }
                })
                .catch(function () { /* offline — layer stays as-is */ });
        }
        loadWeatherAlertPolys();
        setInterval(loadWeatherAlertPolys, 60000);

        markerGroup = L.featureGroup().addTo(map);

        // Populate the road-conditions layer once on map init.
        loadRoadConditionsOverlay();

        // ── User-view lock (Eric 2026-07-03, EOC storm event) ──
        // Any zoom or pan the user initiates locks the view; refresh
        // ticks stop touching zoom/center from here on out. We check
        // the browser event (originalEvent) so that PROGRAMMATIC
        // fitBounds/setView calls the code makes don't trigger the
        // lock — only real user gestures do.
        // Lock on ANY view change that isn't one of our programmatic fits —
        // this catches the on-screen +/- zoom buttons (no originalEvent),
        // mouse wheel, drag-pan, and pinch alike. Once locked, refresh ticks
        // never touch zoom/center until the operator presses ★ (or a +/-
        // tightness button) to opt back into auto-fit.
        function _lockView() {
            if (_programmaticView) return;   // our own fit, not a user gesture
            _userLockedView = true;
            _initialFitDone = true;
            // #59 P3 — the operator just panned/zoomed by hand; drop any
            // row-focus restore point so a later click on that same row starts
            // fresh instead of snapping back to a now-stale saved view.
            _focusedKey = null; _preFocusView = null;
            _refreshRecenterVisibility();
        }
        map.on('zoomend', _lockView);
        map.on('moveend', _lockView);

        // Persist the user's chosen weather / road-condition overlays
        // across page loads (localStorage). Real EOCs run this view
        // for hours; losing the storm layer on refresh is unusable.
        var LAYER_PREF_KEY = 'newui_situation_overlays';
        function _restoreOverlays() {
            var saved;
            try { saved = JSON.parse(localStorage.getItem(LAYER_PREF_KEY) || '[]'); }
            catch (e) { saved = []; }
            if (!saved.length) return;
            var byName = overlays; // captured from the enclosing scope
            for (var i = 0; i < saved.length; i++) {
                var lyr = byName[saved[i]];
                if (lyr && !map.hasLayer(lyr)) map.addLayer(lyr);
            }
        }
        function _saveOverlays() {
            var enabled = [];
            for (var name in overlays) {
                if (Object.prototype.hasOwnProperty.call(overlays, name)
                    && map.hasLayer(overlays[name])) {
                    enabled.push(name);
                }
            }
            try { localStorage.setItem(LAYER_PREF_KEY, JSON.stringify(enabled)); }
            catch (e) {}
        }
        map.on('overlayadd', _saveOverlays);
        map.on('overlayremove', _saveOverlays);
        _restoreOverlays();

        // ── Recenter control (top-right) — opts back into auto-fit. ──
        // Hidden until the user has locked the view; shown when a
        // dispatcher wants the map to jump back to the incident cloud.
        var RecenterCtl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var div = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                div.id = 'sitRecenterCtl';
                div.style.display = 'none';
                // GH #58 (Eric 2026-07-05) — a closed padlock reads as "the
                // display is locked" far more intuitively than a star. Clicking
                // it unlocks + re-fits to the incidents.
                div.innerHTML = '<a href="#" title="Display locked — click to unlock and re-fit to incidents" '
                    + 'style="width:30px;height:30px;line-height:30px;text-align:center;'
                    + 'font-size:16px;color:#333;text-decoration:none;"><i class="bi bi-lock-fill"></i></a>';
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.on(div, 'click', function (e) {
                    L.DomEvent.preventDefault(e);
                    _userLockedView = false;
                    _initialFitDone = false;
                    refitCurrentIncidents(true);
                    _refreshRecenterVisibility();
                });
                return div;
            }
        });
        map.addControl(new RecenterCtl());

        // ── Auto-fit tightness control (Eric 2026-07-04) ──
        // Separate from Leaflet's native +/− (which zooms the map
        // directly). These bias the AUTO-FIT: how tightly the map hugs
        // the active incidents. + fills the screen with the working
        // area; − leaves margin to watch an approaching storm on radar.
        // The bias persists and re-applies on every auto-fit, and each
        // press also re-enables auto-fit (clears a manual lock).
        function _applyBias(delta) {
            _zoomBias = Math.max(-4, Math.min(4, _zoomBias + delta));
            try { localStorage.setItem(ZOOM_BIAS_KEY, String(_zoomBias)); } catch (e) {}
            _userLockedView = false;          // this IS the user asking to auto-fit
            refitCurrentIncidents(true);
            _refreshRecenterVisibility();
        }
        var FitBiasCtl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var div = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                div.innerHTML =
                    '<a href="#" id="sitFitTighter" title="Auto-fit tighter — zoom in closer on the active area" '
                    + 'style="width:30px;height:30px;line-height:30px;text-align:center;font-size:16px;color:#333;text-decoration:none;">'
                    + '<i class="bi bi-zoom-in"></i></a>'
                    + '<a href="#" id="sitFitLooser" title="Auto-fit looser — zoom out to watch approaching weather" '
                    + 'style="width:30px;height:30px;line-height:30px;text-align:center;font-size:16px;color:#333;text-decoration:none;">'
                    + '<i class="bi bi-zoom-out"></i></a>';
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.on(div.querySelector('#sitFitTighter'), 'click', function (e) {
                    L.DomEvent.preventDefault(e); _applyBias(+1);
                });
                L.DomEvent.on(div.querySelector('#sitFitLooser'), 'click', function (e) {
                    L.DomEvent.preventDefault(e); _applyBias(-1);
                });
                return div;
            }
        });
        map.addControl(new FitBiasCtl());
        window._refreshRecenterVisibility = function () {
            var el = document.getElementById('sitRecenterCtl');
            if (el) el.style.display = _userLockedView ? '' : 'none';
        };
        // Local alias — the map init IIFE closes over this fn.
        function _refreshRecenterVisibility() {
            window._refreshRecenterVisibility();
        }
    }

    // ── Road-conditions overlay loader ──
    function loadRoadConditionsOverlay() {
        if (!roadConditionsGroup || roadConditionsLoaded) return;
        roadConditionsLoaded = true;
        fetch('api/road-conditions.php?map=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var reports = (data && data.reports) || [];
                for (var i = 0; i < reports.length; i++) {
                    var rep = reports[i];
                    var lat = parseFloat(rep.lat), lng = parseFloat(rep.lng);
                    if (!lat || !lng || isNaN(lat) || isNaN(lng)) continue;
                    var iconClass = (rep.condition_icon && /^bi[\s-]/.test(rep.condition_icon))
                        ? rep.condition_icon : 'bi bi-cone-striped';
                    var divHtml = '<div class="road-condition-marker"><i class="' + escAttr(iconClass) + '"></i></div>';
                    var m = L.marker([lat, lng], {
                        icon: L.divIcon({
                            className: 'road-condition-divicon', html: divHtml,
                            iconSize: [28, 28], iconAnchor: [14, 14]
                        })
                    });
                    var detail = rep.condition_description || rep.description || '';
                    var popup = '<div style="min-width:160px">'
                        + '<strong>' + escAttr(rep.condition_title || 'Road Condition') + '</strong>'
                        + (rep.title ? '<br><span class="text-body-secondary">' + escAttr(rep.title) + '</span>' : '')
                        + (detail ? '<br>' + escAttr(detail) : '')
                        + (rep.address ? '<br><i class="bi bi-geo-alt"></i> ' + escAttr(rep.address) : '')
                        + '</div>';
                    m.bindPopup(popup, { maxWidth: 280 });
                    roadConditionsGroup.addLayer(m);
                }
            })
            .catch(function () { roadConditionsLoaded = false; });
    }

    // ── Severity Marker Icon ──
    function sevMarkerIcon(color) {
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="25" height="41" viewBox="0 0 25 41">'
            + '<path d="M12.5 0C5.6 0 0 5.6 0 12.5C0 21.9 12.5 41 12.5 41S25 21.9 25 12.5C25 5.6 19.4 0 12.5 0z" fill="' + escAttr(color) + '" stroke="#333" stroke-width="1"/>'
            + '<circle cx="12.5" cy="12.5" r="6" fill="white" opacity="0.7"/>'
            + '</svg>';
        return L.icon({
            iconUrl: 'data:image/svg+xml;base64,' + btoa(svg),
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [0, -34]
        });
    }

    // 2026-06-11 — per-user recent-closed-window prefs.
    var recentCloseMins = 30;
    function loadSituationPrefs() {
        if (!window.ScreenPrefs) return Promise.resolve();
        return window.ScreenPrefs.load('situation').then(function (p) {
            if (p && p.options && typeof p.options.recent_close_mins !== 'undefined') {
                recentCloseMins = parseInt(p.options.recent_close_mins, 10) || 30;
            }
            var input = document.getElementById('sitRecentCloseMins');
            if (input) input.value = recentCloseMins;
        }).catch(function () {});
    }
    function saveSituationPrefs() {
        if (!window.ScreenPrefs) return;
        window.ScreenPrefs.save('situation', {
            columns: [],
            sort: { col: '', dir: 'asc' },
            options: { recent_close_mins: recentCloseMins }
        });
    }

    // ── Data Loading ──
    function loadIncidents() {
        var func = document.getElementById('sitTimeRange').value;
        var qs = 'func=' + encodeURIComponent(func);
        // Only honor recent-closed window in the "Current" view; it's
        // meaningless for "Closed Today" etc.
        if (String(func) === '0') {
            qs += '&recent_close_mins=' + encodeURIComponent(recentCloseMins);
        }
        fetch('api/incidents.php?' + qs, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                incidents = data.incidents || [];
                // 2026-06-11 — closed rows sink to the bottom of the
                // list so the operator's eye lands on active first.
                incidents.sort(function (a, b) {
                    var aClosed = (a.status === 1) ? 1 : 0;
                    var bClosed = (b.status === 1) ? 1 : 0;
                    if (aClosed !== bClosed) return aClosed - bClosed;
                    return 0; // preserve API order otherwise
                });

                // GH #58 (Eric 2026-07-05) — if a NEW incident lands outside the
                // current (locked) view and the admin enabled it, unlock so the
                // auto-fit below re-frames to include it. Skipped on the very
                // first load (the initial fit already frames everything). Wrapped
                // so a bad row never breaks the refresh loop.
                try {
                    var _curIds = {}, _offscreenNew = false, _m58 = mapVar();
                    for (var _q = 0; _q < incidents.length; _q++) {
                        var _iid = incidents[_q].id;
                        _curIds[_iid] = true;
                        if (_seenIncidentIds && !_seenIncidentIds[_iid]) {
                            var _nla = parseFloat(incidents[_q].lat), _nln = parseFloat(incidents[_q].lng);
                            if (_nla && _nln && _m58 && !_m58.getBounds().contains([_nla, _nln])) {
                                _offscreenNew = true;
                            }
                        }
                    }
                    if (SIT_RESET_ON_OFFSCREEN && _offscreenNew && _userLockedView) {
                        _userLockedView = false;   // let the auto-fit below re-frame
                        _initialFitDone = false;
                        _lastFitSig = null;        // force the next auto-fit
                        if (window._refreshRecenterVisibility) window._refreshRecenterVisibility();
                    }
                    _seenIncidentIds = _curIds;
                } catch (_e58) { /* never break the refresh */ }

                var sevCounts = data.severity_counts || {};

                // Store severity colors from first incident of each level (or defaults)
                for (var i = 0; i < incidents.length; i++) {
                    if (incidents[i].severity_color) {
                        sevColors[incidents[i].severity] = incidents[i].severity_color;
                    }
                }
                if (!sevColors[0]) sevColors[0] = '#00ff00';
                if (!sevColors[1]) sevColors[1] = '#ffff00';
                if (!sevColors[2]) sevColors[2] = '#ff0000';

                // Update counts
                document.getElementById('cntIncidents').textContent = data.count || 0;
                document.getElementById('cntSev0').textContent = sevCounts[0] || 0;
                document.getElementById('cntSev1').textContent = sevCounts[1] || 0;
                document.getElementById('cntSev2').textContent = sevCounts[2] || 0;

                renderIncidentList();
                updateMarkers();
            })
            .catch(function (err) {
                // silently ignore — will retry on next refresh
            });
    }

    // Phase 107 (issue #23) — Units + Facilities on the EOC display.
    // Populated by loadUnits() / loadFacilities() and rendered into
    // both the map (as toggleable marker groups) and the overlay
    // panel (as list tables tab-switched by the tab strip above).
    var units = [];
    var facilities = [];
    var unitMarkers = null;
    var facMarkers = null;

    // Status → color for unit + facility markers. Falls back to a
    // neutral tone if the status isn't in the map. These match the
    // dashboard's conventions so operators moving between screens
    // don't have to relearn colors.
    var UNIT_STATUS_COLOR = {
        'Available': '#198754',   // green
        'On Shift':  '#198754',
        'Enroute':   '#0d6efd',   // blue
        'On Scene':  '#fd7e14',   // orange
        'Staged':    '#6f42c1',   // purple
        'Cleared':   '#6c757d',   // grey
        'OOS':       '#dc3545',   // red
        'Out Of Service': '#dc3545'
    };
    // (No hardcoded facility-status colour map — facility colours come straight
    // from the configured fac_status.bg_color via the API; see facStatusBits.
    // GH #49: a hardcoded map here used to override configured colours.)

    function statusColor(map, status) {
        return map[status] || '#6c757d';
    }

    // Eric 2026-07-07 (EOC Units tab) — relative "ago" for the UPDATED
    // column from a MySQL datetime string. Returns '' for blanks.
    function sitAgo(mysqlDt) {
        if (!mysqlDt) return '';
        var t = new Date(String(mysqlDt).replace(' ', 'T'));
        if (isNaN(t.getTime())) return esc(mysqlDt);
        var s = Math.floor((Date.now() - t.getTime()) / 1000);
        if (s < 0) s = 0;
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) return Math.floor(s / 3600) + 'h ago';
        return Math.floor(s / 86400) + 'd ago';
    }

    // GH #49 (round 3) — respect the CONFIGURED facility-status colors, exactly
    // like the (correct) Facilities page does (facilities.js: uses f.bg_color /
    // f.text_color / f.status_name straight from api/facilities.php). The old
    // code here second-guessed the API with a hardcoded name→color map
    // (Open/Standby/Full/Closed) whenever the configured colour was white/blank,
    // so any custom status rendered a DIFFERENT colour on situation than on the
    // Facilities page. Use the API's colours directly; only fall back to gray
    // when the API genuinely supplied nothing. A statusless facility still gets
    // a muted em-dash pill rather than an empty white badge.
    function facStatusBits(f) {
        // GH #49 — delegate to the shared window.FacilityStatus helper so
        // this screen renders identically to the dashboard / Facilities page
        // / facility board / facility detail. (Inline fallback kept for the
        // case the helper script somehow didn't load.)
        if (window.FacilityStatus) {
            var b = window.FacilityStatus.bits(f);
            return {
                label: b.label, color: b.bg, textColor: b.text, hasStatus: b.hasStatus,
                badgeHtml: window.FacilityStatus.badge(f, { emptyDash: true, fontSize: '0.62rem' })
            };
        }
        var label = f.status_name || f.status_val || f.status || '';
        if (!label) {
            return { label: '', color: '#adb5bd', textColor: '#fff', hasStatus: false,
                badgeHtml: '<span class="badge bg-secondary bg-opacity-25 text-body-secondary" style="font-size:0.62rem;">&mdash;</span>' };
        }
        var bg   = f.bg_color   ? f.bg_color   : '#6c757d';
        var text = f.text_color ? f.text_color : '#fff';
        var badgeHtml = '<span class="badge" style="background:' + escAttr(bg) + ';color:' + escAttr(text) +
                        ';font-size:0.62rem;">' + esc(label) + '</span>';
        return { label: label, color: bg, textColor: text, badgeHtml: badgeHtml, hasStatus: true };
    }

    function ensureUnitLayer() {
        if (!unitMarkers) {
            unitMarkers = L.layerGroup();
            // Auto-add on start; user can toggle via layer control.
            unitMarkers.addTo(mapVar());
            if (window._mapLayersControl && !window._eocUnitsInCtl) {
                window._mapLayersControl.addOverlay(unitMarkers,
                    '<span style="color:#198754">&#9679;</span> Units (EOC)');
                window._eocUnitsInCtl = true;
            }
        }
    }
    function ensureFacilityLayer() {
        if (!facMarkers) {
            facMarkers = L.layerGroup();
            facMarkers.addTo(mapVar());
            if (window._mapLayersControl && !window._eocFacsInCtl) {
                window._mapLayersControl.addOverlay(facMarkers,
                    '<span style="color:#0d6efd">&#9679;</span> Facilities (EOC)');
                window._eocFacsInCtl = true;
            }
        }
    }

    // Situation.php uses a top-level `map` variable that's declared
    // later; grab it defensively so ensureXLayer works from either
    // load timing.
    function mapVar() { return typeof map !== 'undefined' ? map : null; }

    function loadUnits() {
        fetch('api/responders.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = data.responders || data || [];
                units = Array.isArray(list) ? list : [];
                unitCount = units.length;
                document.getElementById('cntUnits').textContent = unitCount;
                renderUnitsList();
                updateUnitMarkers();
            })
            .catch(function () {});
    }

    function loadFacilities() {
        fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = data.facilities || data || [];
                facilities = Array.isArray(list) ? list : [];
                renderFacilitiesList();
                updateFacilityMarkers();
            })
            .catch(function () {});
    }

    // GH #78 — recent events feed (log + unit/facility notes, merged
    // server-side by api/log.php) for the Events tab. Net Control uses this as
    // a running comms log for field notes that aren't status updates.
    var events = [];
    var eventsActivated = false; // QA #7 — true once the Events tab is opened
    function loadRecentEvents() {
        fetch('api/log.php?days=7', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                events = (data && data.entries) ? data.entries : [];
                renderRecentEvents();
            })
            .catch(function () {});
    }
    function renderRecentEvents() {
        var tbody = document.getElementById('sitEventsList');
        if (!tbody) return;
        if (!events.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-body-secondary py-3">No recent events</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < events.length && i < 250; i++) {
            var e = events[i];
            var info = e.info || '';
            // note/log text is fully user-controlled — esc() (text-node based)
            // is the XSS boundary here; only the incident link wraps it.
            var infoCell = (e.ticket_id > 0)
                ? '<a href="incident-detail.php?id=' + parseInt(e.ticket_id, 10) + '" target="_blank" rel="noopener">' + esc(info) + '</a>'
                : esc(info);
            html += '<tr>'
                + '<td class="text-nowrap text-body-secondary" style="font-size:0.68rem;">' + esc(formatTime(e.when)) + '</td>'
                + '<td>' + esc(e.code_type || '') + '</td>'
                + '<td>' + esc(e.by || '') + '</td>'
                + '<td>' + infoCell + '</td>'
                + '</tr>';
        }
        tbody.innerHTML = html;
    }

    function updateUnitMarkers() {
        if (!mapVar()) return;
        ensureUnitLayer();
        unitMarkers.clearLayers();
        for (var i = 0; i < units.length; i++) {
            var u = units[i];
            var lat = parseFloat(u.lat || u.latitude);
            var lng = parseFloat(u.lng || u.longitude);
            if (!lat || !lng) continue;
            // API contract audit 2026-07-07: same dead keys as the Units
            // table (currstatus/status never emitted) — markers rendered
            // gray with "Unknown" popups. Use status_name + the configured
            // per-status colours.
            // QA #8 — the fresh-install un_status seed sets bg_color
            // 'transparent' (the unconfigured default); treat it like empty so
            // the status dot falls through to a real computed colour instead
            // of rendering invisible.
            var color = (u.status_bg_color && u.status_bg_color !== '' && u.status_bg_color !== 'transparent')
                ? u.status_bg_color
                : statusColor(UNIT_STATUS_COLOR, u.status_name || '');
            // GH #82/#76 — configured type glyph in a status-coloured badge +
            // always-on callsign label (this is the command-vehicle display, so
            // reading who's who at a glance matters). Falls back to the dot.
            var icon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                ? window.TypeIcons.markerDivIcon(u.icon, color, { label: (u.callsign || u.name || '') })
                : L.divIcon({
                    className: 'eoc-unit-marker',
                    html: '<div style="width:14px;height:14px;border-radius:50%;background:' + color +
                          ';border:2px solid #fff;box-shadow:0 0 3px rgba(0,0,0,0.4);"></div>',
                    iconSize: [18, 18], iconAnchor: [9, 9]
                });
            var m = L.marker([lat, lng], { icon: icon });
            var callsign = u.callsign ? ' <code>' + esc(u.callsign) + '</code>' : '';
            m.bindPopup(
                '<strong>' + esc(u.name || ('Unit #' + u.id)) + '</strong>' + callsign +
                '<br><span class="badge" style="background:' + color + ';'
                    + (u.status_text_color ? 'color:' + esc(u.status_text_color) + ';' : '')
                    + '">' + esc(u.status_name || 'Unknown') + '</span>' +
                '<br><a href="unit-detail.php?id=' + u.id + '" target="_blank">View Details</a>'
            );
            m._eocId = u.id;
            unitMarkers.addLayer(m);
        }
    }

    function updateFacilityMarkers() {
        if (!mapVar()) return;
        ensureFacilityLayer();
        facMarkers.clearLayers();
        for (var i = 0; i < facilities.length; i++) {
            var f = facilities[i];
            var lat = parseFloat(f.lat || f.latitude);
            var lng = parseFloat(f.lng || f.longitude);
            if (!lat || !lng) continue;
            // a beta tester GH #49 round 2 — resolve label + colors from the
            // API's real fields via facStatusBits (status_name, not
            // status_val; transparent/white defaults fall through).
            var fs = facStatusBits(f);
            // GH #82/#76 — type glyph in a status-coloured square badge + name label.
            var icon = (window.TypeIcons && window.TypeIcons.markerDivIcon)
                ? window.TypeIcons.markerDivIcon(f.type_icon, fs.color, { label: (f.name || ''), square: true })
                : L.divIcon({
                    className: 'eoc-fac-marker',
                    html: '<div style="width:14px;height:14px;background:' + fs.color +
                          ';border:2px solid #fff;box-shadow:0 0 3px rgba(0,0,0,0.4);"></div>',
                    iconSize: [18, 18], iconAnchor: [9, 9]
                });
            var m = L.marker([lat, lng], { icon: icon });
            var addr = (f.street || '') + (f.city ? ', ' + f.city : '');
            m.bindPopup(
                '<strong>' + esc(f.name || ('Facility #' + f.id)) + '</strong>' +
                '<br>' + esc(f.type_name || f.facility_type || '') +
                '<br>' + esc(addr) +
                '<br>' + fs.badgeHtml +
                '<br><a href="facility-edit.php?id=' + f.id + '" target="_blank">View Details</a>'
            );
            m._eocId = f.id;
            facMarkers.addLayer(m);
        }
    }

    function renderUnitsList() {
        var tbody = document.getElementById('sitUnitsList');
        if (!tbody) return;
        if (!units.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No units</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < units.length; i++) {
            var u = units[i];
            // GH #66 — statuses flagged "Hide from Boards" (off-shift on
            // large rosters) are filtered from this listing. Dispatch
            // pickers elsewhere still see every unit.
            if (parseInt(u.hide_from_board, 10) === 1) continue;
            // Eric 2026-07-07 — this table read field names the responders
            // API never emitted (currstatus/status/location/last_seen_ago),
            // so STATUS/LOCATION/UPDATED rendered blank on every install.
            // Use the API's real names: status_name (+ configured colours
            // from Phase 104j), street/city, status_updated/updated.
            // QA #8 — the fresh-install un_status seed sets bg_color
            // 'transparent' (the unconfigured default); treat it like empty so
            // the status dot falls through to a real computed colour instead
            // of rendering invisible.
            var color = (u.status_bg_color && u.status_bg_color !== '' && u.status_bg_color !== 'transparent')
                ? u.status_bg_color
                : statusColor(UNIT_STATUS_COLOR, u.status_name || '');
            var addr = u.city || u.street || '';
            // Eric 2026-07-03 — surface the primary person + their
            // handle so a dispatcher calling "Team Delta" knows who
            // to reach. Falls back to em-dash if unit has no active
            // personnel assignment (solo units, or teams awaiting
            // roster).
            var principal = '—';
            if (u.primary_person && u.primary_person.name) {
                principal = esc(u.primary_person.name);
                if (u.primary_person.handle) {
                    principal += ' <span class="text-body-secondary" style="font-size:0.68rem;">(' +
                                 esc(u.primary_person.handle) + ')</span>';
                }
                if (u.primary_person.role === 'Team Lead') {
                    principal += ' <i class="bi bi-star-fill text-warning" style="font-size:0.6rem;" title="Team Lead"></i>';
                }
            }
            html += '<tr class="sit-row" data-eoc-kind="unit" data-eoc-id="' + u.id + '">' +
                '<td><span class="sev-dot" style="background:' + color + ';border-radius:50%;"></span></td>' +
                '<td>' + esc(u.name || ('Unit #' + u.id)) + '</td>' +
                '<td class="font-monospace" style="font-size:0.7rem;">' + esc(u.callsign || '') + '</td>' +
                '<td>' + principal + '</td>' +
                '<td><span class="badge" style="background:' + color + ';'
                    + (u.status_text_color ? 'color:' + esc(u.status_text_color) + ';' : '')
                    + 'font-size:0.62rem;">' + esc(u.status_name || '') + '</span></td>' +
                '<td>' + esc(addr) + '</td>' +
                '<td class="text-body-secondary" style="font-size:0.68rem;">' + sitAgo(u.status_updated || u.updated) + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    }

    function renderFacilitiesList() {
        var tbody = document.getElementById('sitFacilitiesList');
        if (!tbody) return;
        if (!facilities.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No facilities</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < facilities.length; i++) {
            var f = facilities[i];
            // a beta tester GH #49 round 2 (2026-07-04, reproduced live on
            // training) — the REAL bug: api/facilities.php names the
            // status label `status_name`, but this renderer read
            // `status_val`/`status` (fields that don't exist) — so the
            // badge text was always empty and the old color-map lookup
            // never matched. Also: statusless facilities get the API's
            // default '#ffffff'/'transparent', which is truthy and beat
            // the fallback — an empty WHITE pill (a beta tester's screenshot).
            var fs = facStatusBits(f);
            var addr = (f.street || '') + (f.city ? ', ' + f.city : '');
            html += '<tr class="sit-row" data-eoc-kind="facility" data-eoc-id="' + f.id + '">' +
                '<td><span class="sev-dot" style="background:' + fs.color + ';border-radius:0;"></span></td>' +
                '<td>' + esc(f.name || ('#' + f.id)) + '</td>' +
                '<td>' + esc(f.type_name || f.facility_type || '') + '</td>' +
                '<td>' + fs.badgeHtml + '</td>' +
                '<td>' + esc(addr) + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;
    }

    // Row-click → recenter map on the entity and open its popup.
    // Delegated so we don't rebind on every re-render.
    // ── Click-to-zoom with restore (#59, Eric 2026-07-05) ─────────
    // Clicking a unit / facility / incident row zooms to it; clicking the
    // SAME target again restores the view that was showing before you zoomed
    // in. Both the zoom and the restore go through _progFit(), so neither
    // counts as a manual gesture against the #58 view-lock.
    var _focusedKey = null;     // e.g. "unit:5" currently zoomed-to, or null
    var _preFocusView = null;   // {center, zoom} saved when focus began
    function _focusEoc(key, latlng, zoom) {
        if (!mapVar()) return false;
        if (_focusedKey === key && _preFocusView) {
            var pv = _preFocusView;                 // second click → restore
            _progFit(function () { mapVar().setView(pv.center, pv.zoom); });
            _focusedKey = null; _preFocusView = null;
            return true;
        }
        // Save the wide view only when entering focus from an unfocused state,
        // so restoring (even after hopping between rows) goes all the way back.
        if (_focusedKey === null) {
            _preFocusView = { center: mapVar().getCenter(), zoom: mapVar().getZoom() };
        }
        _focusedKey = key;
        _progFit(function () { mapVar().setView(latlng, zoom); });
        return false;
    }
    function _sitRowActive(row) {
        var all = document.querySelectorAll('.sit-row');
        for (var i = 0; i < all.length; i++) { all[i].classList.remove('active'); }
        if (row) { row.classList.add('active'); }
    }

    document.addEventListener('click', function (ev) {
        var row = ev.target.closest && ev.target.closest('[data-eoc-kind]');
        if (!row) return;
        var kind = row.getAttribute('data-eoc-kind');
        var id = parseInt(row.getAttribute('data-eoc-id'), 10);
        var group = (kind === 'unit') ? unitMarkers : facMarkers;
        if (!group || !mapVar()) return;
        group.eachLayer(function (m) {
            if (m._eocId === id) {
                var restored = _focusEoc(kind + ':' + id, m.getLatLng(), 15);
                if (restored) { m.closePopup(); _sitRowActive(null); }
                else { m.openPopup(); _sitRowActive(row); }
            }
        });
    });

    // Tab switcher — persist selection per user in localStorage so the
    // wall display stays on the last-picked tab across page reloads.
    var TAB_PREF_KEY = 'newui_situation_tab';
    function switchSitTab(name) {
        var tabs = document.querySelectorAll('#sitTabs a[data-sittab]');
        for (var t = 0; t < tabs.length; t++) {
            if (tabs[t].getAttribute('data-sittab') === name) tabs[t].classList.add('active');
            else tabs[t].classList.remove('active');
        }
        var bodies = document.querySelectorAll('[data-sittab-body]');
        for (var b = 0; b < bodies.length; b++) {
            bodies[b].style.display = (bodies[b].getAttribute('data-sittab-body') === name) ? '' : 'none';
        }
        try { localStorage.setItem(TAB_PREF_KEY, name); } catch (e) {}
        // Lazy-load the tab's data on first activation.
        if (name === 'units' && !units.length) loadUnits();
        if (name === 'facilities' && !facilities.length) loadFacilities();
        if (name === 'events') { eventsActivated = true; loadRecentEvents(); } // GH #78 / QA #7
    }
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest && ev.target.closest('#sitTabs a[data-sittab]');
        if (!a) return;
        ev.preventDefault();
        switchSitTab(a.getAttribute('data-sittab'));
    });

    function loadMapConfig() {
        fetch('api/map-config.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (cfg) {
                if (cfg.area_title) {
                    document.getElementById('sitTitle').innerHTML =
                        '<i class="bi bi-display me-1"></i>' + esc(cfg.area_title) + ' &mdash; Situation';
                }
                if (cfg.def_lat && cfg.def_lng) {
                    defaultLat = cfg.def_lat;
                    defaultLng = cfg.def_lng;
                    defaultZoom = cfg.def_zoom || 10;
                    _progFit(function () { map.setView([defaultLat, defaultLng], defaultZoom); });
                }
            })
            .catch(function () {});
    }

    // ── Render Incident List ──
    function renderIncidentList() {
        var tbody = document.getElementById('sitIncidentList');

        if (!incidents.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">No incidents</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];
            var color = sevColors[inc.severity] || '#888';
            var addr = (inc.street || '');
            if (inc.city) addr += (addr ? ', ' : '') + inc.city;

            var hasCoords = inc.lat && inc.lng;
            var addrContent = esc(addr);
            if (hasCoords) {
                addrContent += ' <i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.65rem;"></i>';
            }

            var isClosed = (inc.status === 1);
            var dimStyle = isClosed ? ' style="opacity:0.55;font-style:italic;"' : '';
            // Phase 18d — honor security label masking. Use the
            // server-provided scope_display/address_display values so
            // the wall display never shows scope/address that the label
            // configuration says to hide. Show a small label badge so
            // the EOC viewer knows why the row looks the way it does.
            var sec = inc.security || {};
            var scopeText = (inc.scope_display != null) ? inc.scope_display : (inc.scope || '');
            var addrText  = (inc.address_display != null)
                ? inc.address_display
                : ((inc.street || '') + (inc.city ? ', ' + inc.city : ''));
            if (sec.eoc_show_address === 0) addrContent = esc(addrText); // no geo-icon
            else                            addrContent = esc(addrText) + (hasCoords ? ' <i class="bi bi-geo-alt-fill text-body-tertiary" style="font-size:0.65rem;"></i>' : '');
            var labelBadge = '';
            if (sec.label_id) {
                labelBadge = ' <span class="badge" style="font-size:0.55rem;background:' +
                    escAttr(sec.badge_bg_color || '#6c757d') + ';color:' +
                    escAttr(sec.badge_text_color || '#fff') + ';">' +
                    esc(sec.label_name) + '</span>';
            }
            html += '<tr class="sit-row" data-idx="' + i + '"'
                + ' data-id="' + (inc.id || '') + '"'
                + ' data-lat="' + (inc.lat || '') + '"'
                + ' data-lng="' + (inc.lng || '') + '"'
                + dimStyle + '>';
            html += '<td><span class="sev-dot" style="background:' + escAttr(color) + ';" title="Severity ' + inc.severity + '"></span></td>';
            // Phase 99o (Eric beta 2026-06-29) — case-number cell links
            // to the incident detail page. Falls back to "#<id>" for
            // legacy tickets that pre-date the numbering feature.
            var caseNum = inc.incident_number || ('#' + inc.id);
            // Phase 104b (a beta tester GH #17) — surface PAR overdue on the
            // situation-screen row. par_overdue_secs is 0 unless PAR
            // is opted-in on this incident AND the next check has
            // already passed. Icon inline with the case number so the
            // dispatcher's eye reaches it in the same saccade — no
            // extra column, no reordering. Colour ramps with lateness:
            // amber under 5 min, red past 5 min. Tooltip carries the
            // exact overdue duration for hover disambiguation.
            var parIcon = '';
            var parOD = parseInt(inc.par_overdue_secs || 0, 10);
            if (parOD > 0) {
                var parColor = parOD >= 300 ? 'text-danger' : 'text-warning';
                var parMin = Math.floor(parOD / 60);
                var parLabel = parMin >= 1 ? (parMin + ' min overdue') : (parOD + ' sec overdue');
                parIcon = ' <i class="bi bi-clock-history ' + parColor
                        + '" title="PAR check ' + parLabel + '" style="font-size:0.85rem;"></i>';
            }
            html += '<td><a href="incident-detail.php?id=' + inc.id + '" class="font-monospace small" title="Open detail">'
                 + esc(caseNum) + '</a>' + parIcon + '</td>';
            html += '<td class="sit-scope-cell" style="cursor:pointer;">' + esc(scopeText) + labelBadge + '</td>';
            html += '<td>' + esc(inc.incident_type || '') + '</td>';
            html += '<td class="sit-addr-cell" style="cursor:' + (hasCoords ? 'pointer' : 'default') + ';">' + addrContent + '</td>';
            html += '<td class="text-center">' + (inc.units_assigned || 0) + '</td>';
            html += '<td>' + formatTime(inc.updated) + '</td>';
            html += '</tr>';
        }
        tbody.innerHTML = html;

        // Bind click events for zoom-to-location
        var rows = tbody.querySelectorAll('.sit-row');
        for (var r = 0; r < rows.length; r++) {
            rows[r].addEventListener('click', handleRowClick);
        }
    }

    function handleRowClick(e) {
        var row = this;
        var lat = parseFloat(row.getAttribute('data-lat'));
        var lng = parseFloat(row.getAttribute('data-lng'));
        var incId = row.getAttribute('data-id');

        // Scope/ID cell click -> open detail page (not a zoom action).
        var scopeCell = e.target.closest('.sit-scope-cell');
        if (scopeCell && incId) {
            window.open('incident-detail.php?id=' + incId, '_blank');
            return;
        }

        // Address or row click -> zoom to location; a second click on the same
        // incident restores the pre-zoom view (#59).
        if (lat && lng) {
            var restored = _focusEoc('incident:' + incId, [lat, lng], 16);
            _sitRowActive(restored ? null : row);
        }
    }

    // ── Map Markers ──
    function updateMarkers() {
        markerGroup.clearLayers();

        var bounds = [];
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];
            var lat = parseFloat(inc.lat);
            var lng = parseFloat(inc.lng);
            if (!lat || !lng) continue;

            // Phase 18d — security label controls map marker visibility.
            var sec = inc.security || {};
            if (sec.eoc_show_map_marker === 'hide') continue;

            var color = sevColors[inc.severity] || '#888';
            // 'dim' renders as gray + reduced opacity; popup carries
            // only severity, no scope/address.
            if (sec.eoc_show_map_marker === 'dim') color = '#aaa';
            var icon = sevMarkerIcon(color);
            var marker = L.marker([lat, lng], { icon: icon, opacity: (sec.eoc_show_map_marker === 'dim' ? 0.6 : 1.0) });

            var scopeText = (inc.scope_display != null) ? inc.scope_display : (inc.scope || '');
            var addr = (sec.eoc_show_address === 0)
                ? (sec.eoc_placeholder || '*** Restricted ***')
                : ((inc.street || '') + (inc.city ? ', ' + inc.city : ''));

            var popup;
            if (sec.eoc_show_map_marker === 'dim') {
                popup = '<strong>Severity ' + inc.severity + '</strong>'
                    + (sec.label_name ? '<br><span class="badge" style="background:' + sec.badge_bg_color + ';color:' + sec.badge_text_color + ';">' + esc(sec.label_name) + '</span>' : '');
            } else {
                popup = '<strong>' + esc(scopeText || inc.incident_number || ('#' + inc.id)) + '</strong>'
                    + '<br>' + esc(inc.incident_type || '')
                    + '<br>' + esc(addr)
                    + '<br>Units: ' + (inc.units_assigned || 0)
                    + '<br><a href="incident-detail.php?id=' + inc.id + '" target="_blank">View Details</a>';
            }

            marker.bindPopup(popup);
            markerGroup.addLayer(marker);
            bounds.push([lat, lng]);
        }

        // Eric 2026-07-03/04 — auto-fit that includes every active
        // incident, but (a) only re-fits when the incident SET actually
        // changes so it never clobbers the view on an idle SSE tick,
        // and (b) honors the relative-tightness bias set by the +/−
        // control. See autoFitIncidents().
        autoFitIncidents(bounds, false);
    }

    // Fit the map to `bounds` (array of [lat,lng]), applying _zoomBias.
    // force=true bypasses the "did the set change?" guard (used by the
    // +/− and ★ controls). Respects the manual-view lock unless forced.
    function autoFitIncidents(bounds, force) {
        if (!bounds || !bounds.length) return;
        if (_userLockedView && !force) return;

        // Signature of the plotted positions (+ the current bias) so an
        // idle refresh with the same incidents in the same spots is a
        // no-op, but a new/moved/closed incident — or a bias change —
        // re-fits.
        var sig = _zoomBias + '@';
        for (var i = 0; i < bounds.length; i++) {
            sig += bounds[i][0].toFixed(4) + ',' + bounds[i][1].toFixed(4) + ';';
        }
        if (!force && sig === _lastFitSig) return;
        _lastFitSig = sig;

        // Bias maps to how close the fit is allowed to get and how much
        // breathing room to leave. + = tighter (higher max zoom, less
        // padding); − = looser (lower max zoom, more padding). fitBounds
        // still guarantees ALL incidents are in view.
        var effMaxZoom = Math.max(10, Math.min(18, 14 + _zoomBias));
        var pad = Math.max(12, 40 - _zoomBias * 10);
        _progFit(function () {
            map.fitBounds(bounds, { padding: [pad, pad], maxZoom: effMaxZoom });
        });
        _initialFitDone = true;
    }

    // Recompute bounds from the current incident set and re-fit. Used by
    // the +/− tightness buttons so a bias change takes effect instantly
    // without waiting for the next SSE tick.
    function refitCurrentIncidents(force) {
        var b = [];
        for (var i = 0; i < incidents.length; i++) {
            var la = parseFloat(incidents[i].lat), ln = parseFloat(incidents[i].lng);
            var sec = incidents[i].security || {};
            if (!la || !ln || sec.eoc_show_map_marker === 'hide') continue;
            b.push([la, ln]);
        }
        autoFitIncidents(b, force);
    }

    // ── SSE Real-Time Updates ──
    function initSSE() {
        if (typeof EventBus === 'undefined') return;

        EventBus.on('incident:new', function () {
            loadIncidents();
        });
        EventBus.on('incident:update', function () {
            loadIncidents();
        });
        EventBus.on('incident:close', function () {
            loadIncidents();
            if (typeof loadRecentEvents === 'function') { loadRecentEvents(); }
        });
        // GH #13 — a note is an incident change too; refresh the Events feed so
        // the board reflects it live without waiting for the next poll.
        EventBus.on('incident:note', function () {
            loadIncidents();
            if (typeof loadRecentEvents === 'function') { loadRecentEvents(); }
        });
        EventBus.on('responder:status', function () {
            loadUnits();
        });
        EventBus.on('responder:assign', function () {
            loadIncidents();
            loadUnits();
        });
        EventBus.on('system:refresh', function () {
            loadIncidents();
            loadUnits();
        });

        // Connect SSE
        if (typeof EventBus.connect === 'function') {
            EventBus.connect();
        }
    }

    // ── Utility ──
    function formatTime(dt) {
        if (!dt) return '';
        try {
            // QA #17 — a raw MySQL datetime ('2026-07-08 14:23:01') fails to
            // parse on Safari/WebKit (only the ISO 'T' form is required by
            // spec). Normalize the space→T like sitAgo() and ~30 other sites
            // do, so the Events feed's Time column renders on every browser.
            var d = new Date(typeof dt === 'string' ? dt.replace(' ', 'T') : dt);
            if (isNaN(d.getTime())) return dt;
            var mo = d.getMonth() + 1;
            var day = d.getDate();
            var hh = d.getHours();
            var mm = d.getMinutes();
            return mo + '/' + day + ' '
                + (hh < 10 ? '0' : '') + hh + ':'
                + (mm < 10 ? '0' : '') + mm;
        } catch (e) {
            return dt;
        }
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // ── Panel Collapse Toggle ──
    function initPanelToggle() {
        var btn = document.getElementById('sitCollapse');
        var body = document.getElementById('sitBody');
        var summary = document.getElementById('sitSummary');

        btn.addEventListener('click', function () {
            panelCollapsed = !panelCollapsed;
            body.style.display = panelCollapsed ? 'none' : '';
            summary.style.display = panelCollapsed ? 'none' : '';
            btn.querySelector('i').className = panelCollapsed
                ? 'bi bi-chevron-up'
                : 'bi bi-chevron-down';
        });
    }

    // ── Time Range Change ──
    function initTimeRange() {
        document.getElementById('sitTimeRange').addEventListener('change', function () {
            loadIncidents();
        });
        // 2026-06-11 — recent-closed window: per-user, debounced save.
        var recentInput = document.getElementById('sitRecentCloseMins');
        var saveTimer = null;
        if (recentInput) {
            recentInput.addEventListener('input', function () {
                recentCloseMins = parseInt(recentInput.value, 10) || 0;
                if (saveTimer) clearTimeout(saveTimer);
                saveTimer = setTimeout(function () {
                    saveSituationPrefs();
                    loadIncidents();
                }, 600);
            });
        }
    }

    // ── Theme Toggle Support ──
    function initThemeWatch() {
        // Watch for theme changes from the navbar toggle
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].attributeName === 'data-bs-theme') {
                    var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                    if (isDark) {
                        if (map.hasLayer(osmLight)) map.removeLayer(osmLight);
                        if (map.hasLayer(topoMap)) map.removeLayer(topoMap);
                        if (!map.hasLayer(cartoDark)) cartoDark.addTo(map);
                    } else {
                        if (map.hasLayer(cartoDark)) map.removeLayer(cartoDark);
                        if (map.hasLayer(topoMap)) map.removeLayer(topoMap);
                        if (!map.hasLayer(osmLight)) osmLight.addTo(map);
                    }
                }
            }
        });
        observer.observe(document.documentElement, { attributes: true });
    }

    // ── Keyboard Shortcut: Escape to close window ──
    function initKeyboard() {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                window.close();
            }
        });
    }

    // ── Initialize ──
    function init() {
        initMap();
        initPanelToggle();
        initTimeRange();
        initThemeWatch();
        initKeyboard();

        // GH #63 (a beta tester) — per-user column show/hide on the Incidents
        // and Units tab tables, persisted via the shared ScreenPrefs
        // infrastructure (screen keys 'situation-incidents' /
        // 'situation-units'; server catalog in inc/screen-prefs.php).
        // Hidden columns are removed via injected nth-child CSS, so the
        // render functions below stay untouched.
        if (window.ScreenPrefs && window.ScreenPrefs.applyToTable) {
            window.ScreenPrefs.applyToTable('situation-incidents', '#sitIncidentsTable', { openerSelector: '#btnSitIncidentCols' });
            window.ScreenPrefs.applyToTable('situation-units', '#sitUnitsTable', { openerSelector: '#btnSitUnitCols' });
        }

        // Load map config (default center, area title)
        loadMapConfig();

        // 2026-06-11 — pull this user's saved recent-close window
        // BEFORE the first load so we don't double-request.
        loadSituationPrefs().then(function () {
            loadIncidents();
            loadUnits();
            // Phase 107 (issue #23) — populate the facilities tab
            // eagerly so the list is ready when the operator switches
            // to it. Small payload, one request; no reason to delay.
            loadFacilities();
        });

        // Phase 107 — restore last-selected tab, default to Incidents.
        try {
            var saved = localStorage.getItem(TAB_PREF_KEY);
            if (saved === 'units' || saved === 'facilities' || saved === 'events') {
                switchSitTab(saved); // QA #16 — 'events' was missing here
            }
        } catch (e) {}

        // Start real-time unit tracking overlay (10s refresh)
        if (typeof UnitTracking !== 'undefined') {
            var tracker = UnitTracking.init(map, {
                refreshInterval: <?php echo (int) ($sitUnitRefreshSecs * 1000); ?>,
                showLabels: true,
                showStale: true,
                showTrails: true,
                trailLength: 10,
                onClick: function (unit) {
                    if (unit.responder_id) {
                        window.open('unit-detail.php?id=' + unit.responder_id, '_blank');
                    }
                },
                onUpdate: function (units) {
                    // Eric 2026-07-05 (#57) — do NOT write #cntUnits here. This
                    // callback only receives units with a LIVE GPS location, so
                    // it was overwriting loadUnits()'s correct roster count with
                    // 0 whenever nobody was reporting a position — the badge
                    // flickered 10<->0. loadUnits() is the sole owner of the
                    // header badge now.
                }
            });
            tracker.start();
        }

        // Fallback polling every 15s for incidents (SSE handles real-time when available)
        refreshTimer = setInterval(function () {
            loadIncidents();
            // Phase 107 (issue #23) — refresh units + facilities on
            // the same cadence so the EOC tabs stay in sync with
            // status changes even if SSE isn't hooked up for those
            // event types on a given install.
            loadUnits();
            loadFacilities();
            // GH #78 / QA #7 — refresh the Events feed once its tab has been
            // opened (keyed on eventsActivated, NOT events.length, so an
            // initially-EMPTY feed still refreshes and picks up new rows
            // instead of showing "No recent events" forever).
            if (eventsActivated) loadRecentEvents();
        }, <?php echo (int) ($sitBoardRefreshSecs * 1000); ?>);

        // Connect SSE for real-time updates
        initSSE();

        // Invalidate map size after layout settles
        setTimeout(function () {
            map.invalidateSize();
        }, 200);
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            try { init(); } catch (err) {
                console.error('Situation init failed:', err);
                document.getElementById('sitOverlay').innerHTML =
                    '<div class="alert alert-danger m-3">Failed to initialize situation view: ' + err.message + '</div>';
            }
        });
    } else {
        try { init(); } catch (err) {
            console.error('Situation init failed:', err);
            document.getElementById('sitOverlay').innerHTML =
                '<div class="alert alert-danger m-3">Failed to initialize situation view: ' + err.message + '</div>';
        }
    }
})();
</script>

<!-- Map Draw Controls & Markups -->
<script>
(function() {
    'use strict';

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';
    // GH #74 — expose the map-manage permission so the markup list can show
    // edit/delete affordances only to users who can actually manage markups
    // (the API gates delete/save on action.manage_map anyway).
    var canManageMap = <?php echo (function_exists('rbac_can') && rbac_can('action.manage_map')) ? 'true' : 'false'; ?>;

    // Wait for the Leaflet map instance
    var map = null;
    var drawMode = null; // 'marker', 'circle', 'polyline', 'polygon', null
    var drawPoints = [];
    var drawPreview = null;
    var drawLayer = null;
    var markupGroup = L.layerGroup();
    var markupLayers = {}; // id -> layer

    function getMap() {
        // The map is stored in the Leaflet container
        var container = document.getElementById('sitMap');
        if (container && container._leaflet_id) {
            // Access the map from L.Map instances
            var maps = [];
            container.querySelectorAll('.leaflet-container');
            // Use Leaflet internal: iterate over map panes
            if (typeof L !== 'undefined' && L.Map && container._leaflet_id) {
                return container._leafletMap || null;
            }
        }
        return null;
    }

    // Expose map from the main IIFE via a global hook
    // We'll poll for the map on the #sitMap container
    function waitForMap(cb) {
        var el = document.getElementById('sitMap');
        var attempts = 0;
        var check = setInterval(function() {
            attempts++;
            if (el && el._leaflet_id !== undefined) {
                // Found Leaflet container — get map reference
                // L.Map stores a reference on the container element
                var foundMap = null;
                // Leaflet sets a reference accessible through internal registry
                if (typeof L !== 'undefined') {
                    // Walk L._mapRegistry or use L.map instances
                    // Simpler: L stores map ID on the element, and we can use L.Map.prototype
                    var mapId = el._leaflet_id;
                    // Try direct access
                    if (el._leaflet) {
                        foundMap = el._leaflet;
                    }
                }
                if (!foundMap) {
                    // Fallback: check by querying Leaflet internals
                    // Leaflet doesn't expose a clean registry, so we use an alternative approach
                    // We'll inject our reference from inside the init function
                    if (window._sitMap) {
                        foundMap = window._sitMap;
                    }
                }
                if (foundMap) {
                    clearInterval(check);
                    cb(foundMap);
                } else if (attempts > 50) {
                    clearInterval(check);
                }
            } else if (attempts > 50) {
                clearInterval(check);
            }
        }, 100);
    }

    // We need the map reference. Patch the main IIFE init to expose it.
    // Since the main IIFE already ran, we need another approach.
    // Best option: use L.Map's internal lookup.
    function findLeafletMap() {
        var el = document.getElementById('sitMap');
        if (!el) return null;
        // Leaflet stores map objects with _leaflet_id on the container
        // We can access it through Leaflet's internal map list
        for (var key in el) {
            if (key.indexOf('_leaflet') === 0 && el[key] && typeof el[key].getCenter === 'function') {
                return el[key];
            }
        }
        return null;
    }

    function initDraw() {
        map = findLeafletMap();

        // If we can't find the map via properties, try the window global
        if (!map && window._sitMap) {
            map = window._sitMap;
        }

        if (!map) {
            // Last resort: wait and retry
            setTimeout(initDraw, 200);
            return;
        }

        markupGroup.addTo(map);

        // Load saved markups
        loadMarkups();

        // Wire up toolbar buttons
        document.getElementById('drawMarker').addEventListener('click', function() { startDraw('marker'); });
        document.getElementById('drawCircle').addEventListener('click', function() { startDraw('circle'); });
        document.getElementById('drawPolyline').addEventListener('click', function() { startDraw('polyline'); });
        document.getElementById('drawPolygon').addEventListener('click', function() { startDraw('polygon'); });
        document.getElementById('drawFinish').addEventListener('click', onPolyFinish);
        document.getElementById('drawCancel').addEventListener('click', cancelDraw);
        document.getElementById('toggleMarkups').addEventListener('click', toggleMarkupsPanel);
    }

    function setActiveBtn(mode) {
        var btns = document.querySelectorAll('#drawToolbar .draw-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.remove('active');
        }
        if (mode === 'marker') document.getElementById('drawMarker').classList.add('active');
        if (mode === 'circle') document.getElementById('drawCircle').classList.add('active');
        if (mode === 'polyline') document.getElementById('drawPolyline').classList.add('active');
        if (mode === 'polygon') document.getElementById('drawPolygon').classList.add('active');

        var cancelBtn = document.getElementById('drawCancel');
        var finishBtn = document.getElementById('drawFinish');
        cancelBtn.style.display = mode ? '' : 'none';
        // Show finish button only for polygon/polyline modes
        finishBtn.style.display = (mode === 'polygon' || mode === 'polyline') ? '' : 'none';
    }

    function startDraw(mode) {
        cancelDraw();
        drawMode = mode;
        drawPoints = [];
        setActiveBtn(mode);
        map.getContainer().style.cursor = 'crosshair';

        if (mode === 'marker') {
            map.once('click', onMarkerPlace);
        } else if (mode === 'circle') {
            map.once('click', onCircleCenter);
        } else {
            // polyline/polygon: collect clicks, use Finish button to complete
            map.on('click', onPolyClick);
            map.doubleClickZoom.disable();
        }
    }

    function cancelDraw() {
        drawMode = null;
        drawPoints = [];
        setActiveBtn(null);
        if (map) {
            map.getContainer().style.cursor = '';
            map.off('click', onMarkerPlace);
            map.off('click', onCircleCenter);
            map.off('click', onCircleRadius);
            map.off('mousemove', onCirclePreview);
            map.off('click', onPolyClick);
            map.off('mousemove', onPolyPreview);
            try { map.doubleClickZoom.enable(); } catch (e) {}
        }
        if (drawPreview) {
            try { map.removeLayer(drawPreview); } catch (e) {}
            drawPreview = null;
        }
    }

    // ── Marker ──
    function onMarkerPlace(e) {
        var latlng = e.latlng;
        var name = prompt('Marker name:');
        if (!name) { cancelDraw(); return; }

        saveMarkup({
            name: name,
            type: 'M',
            coordinates: JSON.stringify([[latlng.lat, latlng.lng]]),
            color: '#FF0000',
            visible: 1,
            category_id: 0,
            width: 2,
            opacity: 1,
            filled: 0,
            fill_color: '',
            fill_opacity: 0.2
        });
        cancelDraw();
    }

    // ── Circle ──
    var circleCenter = null;
    function onCircleCenter(e) {
        circleCenter = e.latlng;
        drawPreview = L.circle(circleCenter, { radius: 0, color: '#FF0000', fillColor: '#FF0000', fillOpacity: 0.2 }).addTo(map);
        map.on('mousemove', onCirclePreview);
        map.once('click', onCircleRadius);
    }

    function onCirclePreview(e) {
        if (drawPreview && circleCenter) {
            var r = circleCenter.distanceTo(e.latlng);
            drawPreview.setRadius(r);
        }
    }

    function onCircleRadius(e) {
        map.off('mousemove', onCirclePreview);
        var radius = circleCenter.distanceTo(e.latlng);
        var name = prompt('Circle name (radius: ' + Math.round(radius) + 'm):');
        if (!name) { cancelDraw(); return; }

        saveMarkup({
            name: name,
            type: 'C',
            coordinates: JSON.stringify([[circleCenter.lat, circleCenter.lng]]),
            ident: String(Math.round(radius)),
            color: '#FF0000',
            visible: 1,
            category_id: 0,
            width: 2,
            opacity: 0.5,
            filled: 1,
            fill_color: '#FF0000',
            fill_opacity: 0.2
        });
        cancelDraw();
    }

    // ── Polyline / Polygon ──
    function onPolyClick(e) {
        drawPoints.push([e.latlng.lat, e.latlng.lng]);

        if (drawPreview) {
            map.removeLayer(drawPreview);
        }
        if (drawPoints.length > 1) {
            var opts = { color: '#FF0000', weight: 2, opacity: 0.7 };
            if (drawMode === 'polygon') {
                drawPreview = L.polygon(drawPoints, opts).addTo(map);
            } else {
                drawPreview = L.polyline(drawPoints, opts).addTo(map);
            }
        }

        // Also wire up preview
        map.off('mousemove', onPolyPreview);
        map.on('mousemove', onPolyPreview);
    }

    function onPolyPreview(e) {
        if (drawPoints.length < 1) return;
        var pts = drawPoints.concat([[e.latlng.lat, e.latlng.lng]]);
        if (drawPreview) {
            drawPreview.setLatLngs(pts);
        }
    }

    function onPolyFinish() {
        map.off('click', onPolyClick);
        map.off('mousemove', onPolyPreview);
        map.doubleClickZoom.enable();

        if (drawPoints.length < 2) { cancelDraw(); return; }

        var typeLabel = drawMode === 'polygon' ? 'Polygon' : 'Line';
        var name = prompt(typeLabel + ' name (' + drawPoints.length + ' points):');
        if (!name) { cancelDraw(); return; }

        saveMarkup({
            name: name,
            type: drawMode === 'polygon' ? 'P' : 'L',
            coordinates: JSON.stringify(drawPoints),
            color: '#FF0000',
            visible: 1,
            category_id: 0,
            width: 2,
            opacity: 0.5,
            filled: drawMode === 'polygon' ? 1 : 0,
            fill_color: '#FF0000',
            fill_opacity: 0.2
        });
        cancelDraw();
    }

    // ── Save to API ──
    function saveMarkup(data) {
        data.action = 'save';
        data.csrf_token = csrfToken;

        fetch('api/map-markups.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(data)
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            if (resp.success || resp.id) {
                loadMarkups();
            }
        })
        .catch(function(err) {
            console.error('Save markup failed:', err);
        });
    }

    // ── Load Saved Markups ──
    function loadMarkups() {
        fetch('api/map-markups.php', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var markups = data.markups || [];
                renderMarkupsOnMap(markups);
                renderMarkupsList(markups);
            })
            .catch(function() {});
    }

    function renderMarkupsOnMap(markups) {
        markupGroup.clearLayers();
        markupLayers = {};

        for (var i = 0; i < markups.length; i++) {
            var m = markups[i];
            if (parseInt(m.line_status, 10) !== 1) continue;

            var coords = [];
            try { coords = JSON.parse(m.line_data || '[]'); } catch (e) { continue; }
            if (!coords.length) continue;

            var layer = null;
            var type = (m.line_type || 'P').toUpperCase();
            var style = {
                color: m.line_color || '#FF0000',
                weight: parseInt(m.line_width, 10) || 2,
                opacity: parseFloat(m.line_opacity) || 0.5
            };

            if (parseInt(m.filled, 10) === 1) {
                style.fillColor = m.fill_color || m.line_color || '#FF0000';
                style.fillOpacity = parseFloat(m.fill_opacity) || 0.2;
                style.fill = true;
            }

            if (type === 'M' && coords[0]) {
                layer = L.marker([coords[0][0], coords[0][1]]);
            } else if (type === 'C' && coords[0]) {
                var radius = parseInt(m.line_ident, 10) || 100;
                layer = L.circle([coords[0][0], coords[0][1]], { radius: radius, color: style.color, weight: style.weight, opacity: style.opacity, fillColor: style.fillColor || style.color, fillOpacity: style.fillOpacity || 0.2 });
            } else if (type === 'P') {
                layer = L.polygon(coords, style);
            } else if (type === 'L') {
                layer = L.polyline(coords, style);
            }

            if (layer) {
                layer.bindPopup('<strong>' + escHtml(m.line_name || '') + '</strong><br><small>' + escHtml(m.category_name || '') + '</small>');
                markupGroup.addLayer(layer);
                markupLayers[m.id] = layer;
            }
        }
    }

    function renderMarkupsList(markups) {
        var listEl = document.getElementById('markupsList');
        if (!markups.length) {
            listEl.innerHTML = '<small class="text-body-secondary">No saved markups</small>';
            return;
        }

        var html = '';
        for (var i = 0; i < markups.length; i++) {
            var m = markups[i];
            var checked = parseInt(m.line_status, 10) === 1 ? ' checked' : '';
            var nm = m.line_name || 'Unnamed';
            html += '<div class="markup-item">';
            html += '<input type="checkbox" class="form-check-input" data-markup-id="' + m.id + '"' + checked + ' style="width:14px;height:14px;">';
            html += '<span class="markup-swatch" style="background:' + escHtml(m.line_color || '#FF0000') + ';"></span>';
            html += '<label class="flex-grow-1">' + escHtml(nm) + '</label>';
            // GH #74 — edit (rename) + delete, so markups drawn in the EOC view
            // aren't permanent. Shown only to users who can manage the map.
            if (canManageMap) {
                html += '<button type="button" class="btn btn-sm btn-link p-0 me-1 text-body-secondary" '
                      + 'data-edit-markup="' + m.id + '" data-markup-name="' + escHtml(nm) + '" title="Rename markup">'
                      + '<i class="bi bi-pencil"></i></button>';
                html += '<button type="button" class="btn btn-sm btn-link p-0 text-danger" '
                      + 'data-del-markup="' + m.id + '" data-markup-name="' + escHtml(nm) + '" title="Delete markup">'
                      + '<i class="bi bi-trash"></i></button>';
            }
            html += '</div>';
        }
        listEl.innerHTML = html;

        // Wire up toggle checkboxes
        var checks = listEl.querySelectorAll('input[data-markup-id]');
        for (var c = 0; c < checks.length; c++) {
            checks[c].addEventListener('change', function() {
                var id = this.getAttribute('data-markup-id');
                var visible = this.checked ? 1 : 0;
                toggleMarkupVisibility(id, visible);
            });
        }

        // GH #74 — wire delete + rename buttons.
        var dels = listEl.querySelectorAll('[data-del-markup]');
        for (var d = 0; d < dels.length; d++) {
            dels[d].addEventListener('click', function() {
                var id = this.getAttribute('data-del-markup');
                var nm = this.getAttribute('data-markup-name') || 'this markup';
                if (window.confirm('Delete "' + nm + '"? This cannot be undone.')) {
                    deleteMarkup(id);
                }
            });
        }
        var edits = listEl.querySelectorAll('[data-edit-markup]');
        for (var e = 0; e < edits.length; e++) {
            edits[e].addEventListener('click', function() {
                var id = this.getAttribute('data-edit-markup');
                var cur = this.getAttribute('data-markup-name') || '';
                var next = window.prompt('Rename markup:', cur);
                if (next !== null && next.trim() !== '' && next !== cur) {
                    renameMarkup(id, next.trim());
                }
            });
        }
    }

    function toggleMarkupVisibility(id, visible) {
        fetch('api/map-markups.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ action: 'toggle_visibility', id: parseInt(id, 10), visible: visible, csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function() { loadMarkups(); })
        .catch(function() {});
    }

    // GH #74 — delete a markup (API delete already exists, RBAC + audit gated).
    function deleteMarkup(id) {
        fetch('api/map-markups.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ action: 'delete', id: parseInt(id, 10), csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.error) { alert('Delete failed: ' + res.error); return; }
            loadMarkups();
        })
        .catch(function() { alert('Delete failed.'); });
    }

    // GH #74 — rename a markup. The API 'save' action updates when given an id;
    // fetch the full record first so the other line_* fields aren't wiped.
    function renameMarkup(id, newName) {
        fetch('api/map-markups.php?id=' + parseInt(id, 10), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(m) {
            var rec = (m && m.markup) ? m.markup : m;
            if (!rec || !rec.id) { alert('Could not load markup to rename.'); return; }
            // Pass EVERY field back — the API 'save' action rebuilds all
            // line_* columns with defaults, so omitting any would wipe the
            // user's styling on a rename.
            var payload = {
                action: 'save', id: parseInt(id, 10), csrf_token: csrfToken,
                name: newName,
                type: rec.line_type, coordinates: rec.line_data,
                color: rec.line_color, ident: rec.line_ident,
                visible: parseInt(rec.line_status, 10) === 1 ? 1 : 0,
                category_id: rec.category_id || rec.line_cat_id || 0,
                opacity: rec.line_opacity, width: rec.line_width,
                fill_color: rec.fill_color, fill_opacity: rec.fill_opacity,
                filled: rec.filled,
                use_with_bm: rec.use_with_bm, use_with_r: rec.use_with_r,
                use_with_f: rec.use_with_f, use_with_u_ex: rec.use_with_u_ex,
                use_with_u_rf: rec.use_with_u_rf
            };
            return fetch('api/map-markups.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify(payload)
            }).then(function(r) { return r.json(); })
            .then(function(res) {
                if (res && res.error) { alert('Rename failed: ' + res.error); return; }
                loadMarkups();
            });
        })
        .catch(function() { alert('Rename failed.'); });
    }

    function toggleMarkupsPanel() {
        var panel = document.getElementById('markupsPanel');
        panel.classList.toggle('show');
        if (panel.classList.contains('show')) {
            loadMarkups();
        }
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Start when DOM and map are ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { setTimeout(initDraw, 300); });
    } else {
        setTimeout(initDraw, 300);
    }
})();
</script>

<noscript>
    <div class="alert alert-warning m-3">JavaScript is required for the Situation view. Please enable JavaScript in your browser.</div>
</noscript>

</body>
</html>
