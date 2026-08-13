<?php
/**
 * NewUI v4.0 — Public incident board (Phase 138).
 *
 * PUBLIC, UNAUTHENTICATED BY DESIGN (specs/security/constitution.md rule 2):
 * this is the HTML wrapper an agency's public website links to, or a lobby
 * display loads full-screen. It carries NO credential and NEVER acquires
 * one — no session_start(), no require of inc/auth.php, no nav/sidebar
 * (those all assume a logged-in dispatcher). All real data — every field
 * that has already passed eligibility + redaction — comes from
 * api/public-board.php's JSON over fetch(); this file renders that JSON
 * and nothing else. config.php is required ONLY for the shared HTTP
 * security headers (CSP/HSTS/etc. — inc/security-headers.php) that every
 * NewUI page gets; it does not start a session on its own.
 *
 * <meta name="robots"> below is belt-and-suspenders with the API's own
 * `X-Robots-Tag: noindex, nofollow` response header (plan.md §9) — the
 * header protects the JSON, this tag protects the HTML wrapper itself.
 *
 * Map is collapsed by default behind a "Show map" toggle (value/mission
 * review finding #6) — the stated audience includes rural residents and
 * lobby displays on whatever connection is available, and every fact the
 * map would show is already duplicated as visible text in the cards.
 *
 * All incident-card and title text is built via textContent/DOM methods
 * — NEVER innerHTML or string-concatenated markup — for every field
 * sourced from the API response (security review finding #3). This is
 * the FIRST surface in this application ever reached by an anonymous
 * internet audience; a stored-XSS in a dispatcher-entered field
 * (incident type name, city, org name) must not be able to execute here.
 * See PublicBoardRender below and tests/test_public_board_frontend_safety.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
?><!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Public Incident Board</title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <!-- Leaflet's CSS/JS (~160 KB combined) is loaded ON DEMAND by
         pbInitMapIfNeeded() below, the first time a visitor toggles "Show
         map" — NOT unconditionally here. Value/mission review finding
         (2026-08-13): the map is collapsed by default specifically for
         low-bandwidth visitors (rural residents, lobby displays on
         whatever connection is available); loading the library
         unconditionally on every page view undermined that entire
         rationale for everyone who never opens the map. -->
    <style>
        body { background: #f8f9fa; }
        .pb-wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        .pb-header h1 { font-size: 1.5rem; margin-bottom: .25rem; }
        .pb-card .card-title { margin-bottom: .35rem; }
        .pb-card .pb-type-group { margin-bottom: .5rem; }
        #pbMap { height: 360px; border-radius: .5rem; }
        #pbEmpty { display: none; }
        /* Kiosk/lobby-display friendliness: cards stay readable at arm's
           length without shrinking below mobile width either. */
        @media (min-width: 1400px) {
            .pb-card .card-title { font-size: 1.25rem; }
        }
    </style>
</head>
<body>
<noscript>
    <!-- Value/mission review finding (2026-08-13): a lobby-kiosk or
         public-website-embed visitor with JavaScript disabled or filtered
         (locked-down browser, content filter) used to see a permanent
         "Loading…" with no explanation. This page is entirely
         fetch()-driven by design (never server-rendered incident data —
         see this file's own docblock), so there is no server-side
         fallback to offer; say so plainly instead of looking broken. -->
    <div class="pb-wrap">
        <div class="alert alert-warning mt-3">
            This page requires JavaScript to load incident data. Please
            enable JavaScript, or contact the agency operating this board.
        </div>
    </div>
</noscript>
<div class="pb-wrap">
    <header class="pb-header mb-3">
        <h1 id="pbTitle">Active Incidents</h1>
        <div id="pbStatus" class="text-body-secondary small" aria-live="off">Loading…</div>
    </header>

    <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" id="pbShowMapToggle">
        <label class="form-check-label" for="pbShowMapToggle">Show map</label>
    </div>

    <div id="pbMapWrap" class="mb-4" style="display:none;">
        <div id="pbMap" aria-hidden="true"></div>
    </div>

    <!-- Diff-only announcer (E2): text is set ONLY when the set of
         incident ids actually changes between polls, never on every
         unchanged 15s refresh — see pbAnnouncementText() below. -->
    <div id="pbLiveRegion" class="visually-hidden" aria-live="polite"></div>

    <div id="pbCards" class="row g-3" role="list"></div>
    <div id="pbEmpty" class="text-body-secondary text-center py-5"></div>

    <footer class="text-body-secondary small text-center mt-5">
        Tickets CAD — Public Incident Board · updates automatically
    </footer>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
/**
 * Phase 138 public board — rendering + polling. ES5 IIFE per project
 * convention (no build step, no modules).
 *
 * PublicBoardRender is exposed on window (and via module.exports when
 * running under Node/CommonJS, e.g. tests/test_public_board_frontend_safety.php
 * and tests/test_public_board_diff_announcer.php) so its PURE functions —
 * the ones with no DOM/fetch side effects — can be driven directly by a
 * test harness the same way inc/public-board.php's pb_round_coords() is
 * unit-tested in isolation server-side. createCard() is the one function
 * here that touches the DOM; it takes a `doc` (document-like) argument
 * instead of reading the global `document`, specifically so a test can
 * hand it a minimal stub and inspect the resulting node tree without a
 * real browser.
 */
(function () {
    'use strict';

    // ── Pure functions (testable without a live DOM/browser) ────────────

    /**
     * Human-readable location string from an incident record. Handles
     * every shape pb_build_public_record() can emit: full detail
     * (street_display + city + state), address-masked (street_display and
     * city both hold the SAME placeholder string, so this collapses to
     * just the placeholder rather than repeating it), city-only precision
     * (no street_display key at all), and the presence-only stub (no
     * location keys at all — returns '').
     */
    function pbLocationText(incident) {
        var street = incident && incident.street_display;
        var city   = incident && incident.city;
        var state  = incident && incident.state;
        if (street) {
            if (city && city === street) {
                // eoc_show_address=0 case: street_display and city are the
                // SAME placeholder string (inc/public-board.php rule 2) —
                // show it once, not twice.
                return street;
            }
            var parts = [street];
            if (city) parts.push(city);
            if (state) parts.push(state);
            return parts.join(', ');
        }
        if (city) {
            return state ? (city + ', ' + state) : city;
        }
        return '';
    }

    function pbRelativeTime(iso, nowMs) {
        if (!iso) return 'Unknown time';
        var t = Date.parse(iso);
        if (isNaN(t)) return 'Unknown time';
        var now = (typeof nowMs === 'number') ? nowMs : Date.now();
        var secs = Math.max(0, Math.floor((now - t) / 1000));
        if (secs < 60) return 'Just now';
        var mins = Math.floor(secs / 60);
        if (mins < 60) return mins + ' min ago';
        var hrs = Math.floor(mins / 60);
        return hrs + ' hr' + (hrs === 1 ? '' : 's') + ' ago';
    }

    function pbMetaText(incident, nowMs) {
        var units = (incident && typeof incident.assigned_units === 'number') ? incident.assigned_units : 0;
        var unitWord = (units === 1) ? 'unit' : 'units';
        var when = pbRelativeTime(incident && incident.opened, nowMs);
        return when + ' — ' + units + ' ' + unitWord + ' assigned';
    }

    /**
     * Build the map-marker popup content as a real DOM node — dependency-
     * injected `doc`, same convention as pbCreateCard(), so it is testable
     * with no Leaflet/browser at all (tests/test_public_board_frontend_
     * safety.php). Passed to Leaflet's marker.bindPopup() as a NODE, never
     * a string: Leaflet's DivOverlay content-update path assigns a STRING
     * popup argument to the popup element's raw markup property with NO
     * escaping at all (verified against the vendored Leaflet source — the
     * comment that used to sit on this call site, claiming Leaflet escapes
     * bound popup text by default, was wrong). A DOM node takes Leaflet's
     * other branch (appendChild), which never touches that property.
     */
    function pbPopupContentNode(doc, incident) {
        incident = incident || {};
        var node = doc.createElement('span');
        node.textContent = String(incident.type || 'Incident');
        return node;
    }

    /**
     * Build ONE incident card as real DOM nodes, using ONLY createElement /
     * textContent / setAttribute — never innerHTML, never string-built
     * markup (security review finding #3). Every field that came from the
     * API response (type, type_group, location text, meta text) reaches
     * the DOM exclusively through .textContent, so a value shaped like an
     * inline script-tag payload is inert text, never parsed as markup —
     * this is a property of the DOM API used, not of any escaping this
     * function does itself. (This comment deliberately never spells out
     * the closing-tag byte sequence for this element, even split across
     * words: an HTML parser terminates a script element at the first
     * occurrence of that sequence ANYWHERE in its raw text, including
     * inside a JS comment, with no regard for JS syntax — writing it out
     * here, even as an example, would truncate this very script block.
     * tests/test_public_board_frontend_safety.php asserts this file
     * contains that sequence exactly once, at the true end.)
     *
     * `doc`: a document-like object (real `document`, or a test stub)
     * exposing createElement()/createTextNode(). Dependency-injected
     * rather than reading the global so tests/test_public_board_frontend_
     * safety.php can drive this with no browser at all.
     */
    function pbCreateCard(doc, incident) {
        incident = incident || {};

        var col = doc.createElement('div');
        col.className = 'col-12 col-md-6 col-lg-4';
        col.setAttribute('role', 'listitem');

        var card = doc.createElement('div');
        card.className = 'card h-100 shadow-sm pb-card';
        col.appendChild(card);

        var body = doc.createElement('div');
        body.className = 'card-body';
        card.appendChild(body);

        var title = doc.createElement('h3');
        title.className = 'card-title h5';
        title.textContent = incident.type ? String(incident.type) : 'Incident';
        body.appendChild(title);

        if (incident.type_group) {
            var group = doc.createElement('div');
            group.className = 'pb-type-group text-body-secondary small';
            group.textContent = String(incident.type_group);
            body.appendChild(group);
        }

        if (incident.severity_text) {
            var badge = doc.createElement('span');
            badge.className = 'badge text-bg-secondary mb-2';
            badge.textContent = String(incident.severity_text);
            body.appendChild(badge);
        }

        var locText = pbLocationText(incident);
        if (locText) {
            var loc = doc.createElement('p');
            loc.className = 'card-text mb-1';
            var icon = doc.createElement('i');
            icon.className = 'bi bi-geo-alt me-1';
            icon.setAttribute('aria-hidden', 'true');
            loc.appendChild(icon);
            loc.appendChild(doc.createTextNode(locText));
            body.appendChild(loc);
        }

        var meta = doc.createElement('p');
        meta.className = 'card-text text-body-secondary small mb-0';
        meta.textContent = pbMetaText(incident);
        body.appendChild(meta);

        return col;
    }

    /**
     * Diff two lists of incident ids between polls — pure, no DOM (E2).
     * Used ONLY to decide whether the aria-live region should speak this
     * poll; an unchanged set produces no announcement, so a 15s auto-
     * refresh does not chatter at a screen-reader user on every tick.
     */
    function pbDiffIncidentIds(prevIds, newIds) {
        prevIds = prevIds || [];
        newIds  = newIds  || [];
        var prevSet = {};
        var i;
        for (i = 0; i < prevIds.length; i++) prevSet[prevIds[i]] = true;
        var newSet = {};
        for (i = 0; i < newIds.length; i++) newSet[newIds[i]] = true;
        var added = [];
        var removed = [];
        for (i = 0; i < newIds.length; i++) {
            if (!prevSet[newIds[i]]) added.push(newIds[i]);
        }
        for (i = 0; i < prevIds.length; i++) {
            if (!newSet[prevIds[i]]) removed.push(prevIds[i]);
        }
        return { added: added, removed: removed };
    }

    /** Plain-text announcement for the diff above — '' when nothing changed. */
    function pbAnnouncementText(diff) {
        diff = diff || { added: [], removed: [] };
        var parts = [];
        if (diff.added.length > 0) {
            parts.push(diff.added.length + (diff.added.length === 1 ? ' new incident' : ' new incidents'));
        }
        if (diff.removed.length > 0) {
            parts.push(diff.removed.length + (diff.removed.length === 1 ? ' incident closed' : ' incidents closed'));
        }
        return parts.join('; ');
    }

    /**
     * Leaflet maxZoom cap (E3), driven by the API's OWN precision_level —
     * never a client guess — so "zoom in past what the data supports" is
     * structurally impossible once wired to L.Map.setMaxZoom(), not merely
     * discouraged (plan.md §3 rule 5). null means "no cap" (exact/hidden).
     */
    function pbMaxZoomFor(level) {
        if (level === 'block') return 16;
        if (level === 'city') return 13;
        return null;
    }

    var PublicBoardRender = {
        locationText: pbLocationText,
        metaText: pbMetaText,
        createCard: pbCreateCard,
        popupContentNode: pbPopupContentNode,
        diffIncidentIds: pbDiffIncidentIds,
        announcementText: pbAnnouncementText,
        maxZoomFor: pbMaxZoomFor
    };
    if (typeof window !== 'undefined') { window.PublicBoardRender = PublicBoardRender; }
    if (typeof module !== 'undefined' && module.exports) { module.exports = PublicBoardRender; }

    // ── Everything below touches the live document/network; not unit-
    //    tested directly (no browser in CI) — kept deliberately thin by
    //    delegating all rendering logic to the pure functions above. ──
    if (typeof document === 'undefined') { return; } // running under Node for tests only

    var pbState = {
        etag: null,
        incidentIds: [],
        lastIncidents: [],
        precisionLevel: 'block',
        map: null,
        markerLayer: null,
        leafletLoading: false,
        leafletCallbacks: []
    };

    function pbSetStatus(text) {
        var el = document.getElementById('pbStatus');
        if (el) el.textContent = text;
    }

    function pbClearChildren(el) {
        if (!el) return;
        while (el.firstChild) el.removeChild(el.firstChild);
    }

    function pbRenderBoard(data) {
        var cardsEl = document.getElementById('pbCards');
        var emptyEl = document.getElementById('pbEmpty');
        if (!cardsEl) return;

        var incidents = (data && data.incidents) || [];
        pbClearChildren(cardsEl);

        var newIds = [];
        var i;
        for (i = 0; i < incidents.length; i++) {
            newIds.push(incidents[i].id);
            cardsEl.appendChild(pbCreateCard(document, incidents[i]));
        }

        if (emptyEl) {
            if (incidents.length === 0) {
                emptyEl.textContent = 'No active incidents.';
                emptyEl.style.display = '';
            } else {
                emptyEl.style.display = 'none';
            }
        }

        var diff = pbDiffIncidentIds(pbState.incidentIds, newIds);
        var msg = pbAnnouncementText(diff);
        var liveEl = document.getElementById('pbLiveRegion');
        if (liveEl && msg) liveEl.textContent = msg;

        pbState.incidentIds = newIds;
        pbState.lastIncidents = incidents;

        var titleEl = document.getElementById('pbTitle');
        if (titleEl && data && data.board && data.board.title) {
            titleEl.textContent = String(data.board.title);
        }

        pbState.precisionLevel = (data && data.board && data.board.precision_level) || 'block';
        pbUpdateMap();

        pbSetStatus('Updated ' + new Date().toLocaleTimeString());
    }

    function pbShowUnavailable(msg) {
        var cardsEl = document.getElementById('pbCards');
        var emptyEl = document.getElementById('pbEmpty');
        pbClearChildren(cardsEl);
        if (emptyEl) {
            emptyEl.textContent = msg;
            emptyEl.style.display = '';
        }
        pbSetStatus('');
    }

    function pbUpdateMap() {
        if (!pbState.map || typeof L === 'undefined') return;
        if (pbState.markerLayer) pbState.markerLayer.clearLayers();
        var cap = pbMaxZoomFor(pbState.precisionLevel);
        pbState.map.setMaxZoom(cap || 19);
        if (cap !== null && pbState.map.getZoom() > cap) {
            pbState.map.setZoom(cap);
        }
        var bounds = [];
        var i;
        for (i = 0; i < pbState.lastIncidents.length; i++) {
            var inc = pbState.lastIncidents[i];
            if (typeof inc.lat === 'number' && typeof inc.lng === 'number') {
                var marker = L.marker([inc.lat, inc.lng]);
                marker.bindPopup(pbPopupContentNode(document, inc));
                pbState.markerLayer.addLayer(marker);
                bounds.push([inc.lat, inc.lng]);
            }
        }
        if (bounds.length > 0) {
            try { pbState.map.fitBounds(bounds, { maxZoom: cap || 15, padding: [20, 20] }); } catch (e) { /* ignore */ }
        }
    }

    // Value/mission review finding (2026-08-13) — Leaflet's CSS/JS
    // (~160 KB combined) used to load unconditionally on every page view
    // even though the map itself is collapsed by default. Loaded here,
    // lazily, on the FIRST call after a visitor actually toggles "Show
    // map" — never before. pbState.leafletLoading guards against firing
    // the <script> injection twice if the toggle is flipped on/off/on
    // again before the first load finishes.
    function pbLoadLeafletThen(cb) {
        if (typeof L !== 'undefined') { cb(); return; }
        if (pbState.leafletLoading) {
            pbState.leafletCallbacks.push(cb);
            return;
        }
        pbState.leafletLoading = true;
        pbState.leafletCallbacks = [cb];

        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = 'assets/vendor/leaflet/leaflet.css';
        document.head.appendChild(link);

        var script = document.createElement('script');
        script.src = 'assets/vendor/leaflet/leaflet.js';
        script.onload = function () {
            var cbs = pbState.leafletCallbacks;
            pbState.leafletCallbacks = [];
            var i;
            for (i = 0; i < cbs.length; i++) { cbs[i](); }
        };
        script.onerror = function () {
            // Leave leafletLoading true — a broken/offline load should not
            // retry-hammer the same failing request on every toggle click.
            // The rest of the page (incident cards) is entirely unaffected
            // either way; the map is a supplementary view.
            pbSetStatus('Map failed to load — incident list is still current.');
        };
        document.head.appendChild(script);
    }

    function pbInitMapIfNeeded() {
        if (pbState.map) return;
        var mapEl = document.getElementById('pbMap');
        if (!mapEl) return;
        pbLoadLeafletThen(function () {
            if (pbState.map || typeof L === 'undefined') return;
            // keyboard: false — the collapsed-by-default map must not
            // become a keyboard trap or a Tab-reachable control once shown
            // (E1/E5); it stays a supplementary view, aria-hidden on its
            // container.
            pbState.map = L.map('pbMap', { keyboard: false, attributionControl: true });
            pbState.map.setView([39.5, -98.35], 4); // sane US-wide default until data arrives
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(pbState.map);
            pbState.markerLayer = L.layerGroup().addTo(pbState.map);
            pbUpdateMap();
        });
    }

    function pbFetchBoard() {
        var headers = {};
        if (pbState.etag) headers['If-None-Match'] = pbState.etag;
        fetch('api/public-board.php' + window.location.search, {
            method: 'GET',
            headers: headers,
            cache: 'no-store'
        }).then(function (resp) {
            var etag = resp.headers.get('ETag');
            if (etag) pbState.etag = etag;

            if (resp.status === 304) {
                pbSetStatus('Up to date · ' + new Date().toLocaleTimeString());
                return null;
            }
            if (resp.status === 503) {
                pbShowUnavailable('The public incident board is not enabled for this site.');
                return null;
            }
            if (resp.status === 404) {
                pbShowUnavailable('This board could not be found.');
                return null;
            }
            if (resp.status === 429) {
                pbSetStatus('Too many requests — slowing down.');
                return null;
            }
            if (!resp.ok) {
                pbSetStatus('Could not reach the board — retrying…');
                return null;
            }
            return resp.json();
        }).then(function (data) {
            if (data) pbRenderBoard(data);
        }).catch(function () {
            pbSetStatus('Could not reach the board — retrying…');
        });
    }

    var toggle = document.getElementById('pbShowMapToggle');
    if (toggle) {
        toggle.addEventListener('change', function () {
            var wrap = document.getElementById('pbMapWrap');
            if (!wrap) return;
            if (toggle.checked) {
                wrap.style.display = '';
                pbInitMapIfNeeded();
            } else {
                wrap.style.display = 'none';
            }
        });
    }

    // 15s poll — matches api/public-board.php's Cache-Control: max-age=15
    // (plan.md §9) so a poll never wastes a round trip against a still-fresh
    // cached response.
    pbFetchBoard();
    setInterval(pbFetchBoard, 15000);
})();
</script>
</body>
</html>
