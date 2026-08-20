<?php
/**
 * Situation map fixes — units-count flap (#57) + zoom-lock (#58)
 * (Eric, 2026-07-05, from live your deployment EOC feedback)
 *
 * #57  Units badge flickered 10<->0 because two writers owned #cntUnits:
 *      loadUnits() (roster count) and unit-tracking.js onUpdate (units with a
 *      live GPS location = 0). The tracker must no longer write the badge.
 *
 * #58  The situation map reset zoom/center every few seconds during an event.
 *      The user-view lock only engaged on gestures carrying a browser
 *      originalEvent, so the on-screen +/- zoom buttons never locked and the
 *      next incident refresh snapped the view back. The lock now trips on any
 *      view change that isn't one of our own programmatic fits.
 *
 * Static guards over situation.php's inline JS (behavioral JS in a PHP file;
 * no headless DOM here). Usage: php tests/test_situation_map_fixes.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== Situation map fixes (#57 units flap, #58 zoom lock) ===\n\n";

$s = @file_get_contents($base . '/situation.php');
if ($s === false) { t('situation.php readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// ── #57 — the tracking overlay must not write the header badge ──
// loadUnits() owns #cntUnits; the tracker onUpdate must not set it.
$onUpdate = '';
if (preg_match('/onUpdate:\s*function\s*\(units\)\s*\{(.*?)\}/s', $s, $m)) { $onUpdate = $m[1]; }
t("#57: unit-tracking onUpdate no longer writes #cntUnits",
    $onUpdate !== '' && strpos($onUpdate, "getElementById('cntUnits')") === false
        && strpos($onUpdate, '.textContent') === false);
t("#57: loadUnits() still owns the #cntUnits badge",
    (bool) preg_match("/function loadUnits\(\).*?getElementById\('cntUnits'\)\.textContent\s*=\s*unitCount/s", $s));

// ── #58 — programmatic-fit flag replaces the originalEvent heuristic ──
t("#58: a _programmaticView flag + _progFit helper exist",
    strpos($s, 'var _programmaticView') !== false &&
    (bool) preg_match('/function _progFit\(fn\)/', $s));
t("#58: _lockView locks on non-programmatic changes (not the old originalEvent check)",
    (bool) preg_match('/function _lockView\([^)]*\)\s*\{\s*if\s*\(_programmaticView\)\s*return/s', $s) &&
    !preg_match('/function _lockView\(e\)\s*\{\s*if\s*\(e\s*&&\s*e\.originalEvent\)/s', $s));
// Every code-initiated view change must be wrapped so it doesn't self-lock.
t("#58: the incident auto-fit fitBounds is wrapped in _progFit",
    (bool) preg_match('/_progFit\(function\s*\(\)\s*\{\s*map\.fitBounds/s', $s));
t("#58: click-to-zoom goes through _progFit (via _focusEoc) so it doesn't self-lock",
    (bool) preg_match('/function _focusEoc\([^)]*\)\s*\{.*?_progFit\(function\s*\(\)\s*\{\s*mapVar\(\)\.setView\(latlng, zoom\)/s', $s));
// The manual lock, once set, must gate the auto-fit (unchanged invariant).
t("#58: autoFitIncidents still bails when the view is user-locked",
    (bool) preg_match('/function autoFitIncidents\([^)]*\)\s*\{[^}]*?_userLockedView\s*&&\s*!force/s', $s));

// ── #53 follow-up — RainViewer radar must cap at its native zoom (7) ──
// Otherwise z8+ paints "Zoom Level Not Supported" placeholder tiles.
t("#53: RainViewer radar layer sets maxNativeZoom 7 (no 'Zoom Level Not Supported' tiles)",
    (bool) preg_match('/var radarLayer = L\.tileLayer\([^)]*maxNativeZoom:\s*7/s', $s));

// ── #53 — NOAA/NWS MRMS radar (US hi-res, dynamic) via ArcGIS export ──
t("#53: NOAA MRMS radar layer added via a bbox-computing tile layer",
    strpos($s, 'mapservices.weather.noaa.gov') !== false &&
    strpos($s, 'L.TileLayer.extend') !== false &&
    (bool) preg_match('/getTileUrl:\s*function\s*\(coords\)/', $s));
t("#53: NOAA export request is well-formed (bboxSR/imageSR 3857, f=image)",
    (bool) preg_match('/bboxSR=3857&imageSR=3857/', $s) &&
    strpos($s, '&f=image') !== false);
t("#53: both radar layers are offered in the layer control",
    strpos($s, "'Radar — US (NWS)': noaaRadarLayer") !== false &&
    strpos($s, "'Radar — Global': radarLayer") !== false);

// ── #59 — click a row to zoom, click again to restore the prior view ──
t("#59: a _focusEoc helper saves the pre-zoom view and restores on repeat click",
    (bool) preg_match('/function _focusEoc\([^)]*\)\s*\{.*?_preFocusView\s*=\s*\{\s*center:.*?getCenter\(\).*?zoom:.*?getZoom\(\)/s', $s) &&
    (bool) preg_match('/_focusedKey === key.*?setView\(pv\.center, pv\.zoom\)/s', $s));
t("#59: restore is programmatic (_progFit) so it doesn't fight the #58 view-lock",
    (bool) preg_match('/_focusedKey === key.*?_progFit\(function\s*\(\)\s*\{\s*mapVar\(\)\.setView\(pv\.center/s', $s));
t("#59: units/facilities AND incident clicks both route through _focusEoc",
    substr_count($s, '_focusEoc(') >= 2 &&
    strpos($s, "_focusEoc('incident:'") !== false &&
    strpos($s, "_focusEoc(kind + ':' + id") !== false);

// ── Configurability (QA-agent gap) — refresh cadences are settings-driven ──
t("situation refresh intervals are configurable (get_setting), not hard-coded",
    (bool) preg_match('/\$sitUnitRefreshSecs\s*=\s*max\([^,]+,\s*\(int\)\s*get_setting\(.situation_unit_refresh_secs/', $s) &&
    (bool) preg_match('/\$sitBoardRefreshSecs\s*=\s*max\([^,]+,\s*\(int\)\s*get_setting\(.situation_board_refresh_secs/', $s) &&
    strpos($s, 'refreshInterval: <?php echo (int) ($sitUnitRefreshSecs') !== false &&
    strpos($s, '}, <?php echo (int) ($sitBoardRefreshSecs') !== false);

// ── #60/#46 — situation control gets the shared per-category markup overlays ──
t("#60: situation.php attaches MapPrefs.addMarkupOverlays (dashboard-parity layer toggles)",
    (bool) preg_match('/MapPrefs\.addMarkupOverlays\(map, sitLayersControl\)/', $s) &&
    strpos($s, '_ticketsMarkupAdded') !== false);

// ── #49 — facility status colours come from the CONFIGURED colours ──
// The (correct) Facilities page uses f.bg_color / f.text_color / f.status_name
// straight from api/facilities.php. Situation must do the same — no hardcoded
// name→colour override (the old FAC_STATUS_COLOR map was the bug).
t("#49: no hardcoded FAC_STATUS_COLOR override map remains",
    strpos($s, 'FAC_STATUS_COLOR') === false);
t("#49: facStatusBits uses the API's configured bg_color/text_color directly",
    (bool) preg_match('/function facStatusBits\(f\)\s*\{.*?f\.bg_color\s*\?\s*f\.bg_color.*?f\.text_color\s*\?\s*f\.text_color/s', $s));
t("#49: a statusless facility still renders a muted em-dash pill (not white)",
    (bool) preg_match('/hasStatus.*?&mdash;/s', $s));
t("#49: both the marker AND the list render facility status via facStatusBits",
    substr_count($s, 'facStatusBits(f)') >= 2);

// ── #58 (round 4) — locked-view padlock + reset on off-screen new incident ──
t("#58: the recenter/lock control shows a closed padlock (bi-lock-fill), not a star",
    strpos($s, 'sitRecenterCtl') !== false &&
    (bool) preg_match('/sitRecenterCtl[\s\S]{0,700}bi-lock-fill/', $s) &&
    strpos($s, '&#9733;') === false);
t("#58: the off-screen-reset flag is admin-configurable (get_variable, default ON)",
    strpos($s, "get_variable('situation_reset_on_offscreen')") !== false &&
    (bool) preg_match('/\$sitResetOffscreen\s*=\s*\(\$sitResetOffscreenRaw === false/', $s) &&
    strpos($s, 'var SIT_RESET_ON_OFFSCREEN = <?php echo (int) $sitResetOffscreen; ?>;') !== false);
t("#58: loadIncidents detects a NEW incident outside the current map bounds",
    (bool) preg_match('/_seenIncidentIds && !_seenIncidentIds\[_iid\]/', $s) &&
    strpos($s, '.getBounds().contains([_nla, _nln])') !== false);
t("#58: an off-screen new incident on a LOCKED view unlocks + forces a re-fit (when enabled)",
    (bool) preg_match('/if \(SIT_RESET_ON_OFFSCREEN && _offscreenNew && _userLockedView\)\s*\{[\s\S]{0,160}_userLockedView = false/', $s) &&
    (bool) preg_match('/_offscreenNew[\s\S]{0,200}_lastFitSig = null/', $s));
t("#58: the offscreen check is skipped on first load (guarded by _seenIncidentIds)",
    strpos($s, 'var _seenIncidentIds = null;') !== false);

$setpg = @file_get_contents($base . '/settings.php');
t("#58: settings.php#map-defaults exposes the reset-on-offscreen toggle",
    $setpg !== false &&
    strpos($setpg, 'data-key="situation_reset_on_offscreen"') !== false &&
    (bool) preg_match('/setSitResetOffscreen[\s\S]{0,400}Reset the locked view/', $setpg));

// ── GH#47 — Units (EOC) / Facilities (EOC) never appeared in the layer
// control. Both ensureUnitLayer() and ensureFacilityLayer() gated their
// addOverlay() call on window._mapLayersControl -- a global only the
// dashboard's app.js ever sets, and situation.php doesn't load app.js. The
// markers were still added to the map (line above each check), so the
// symptom was "no way to turn them off", not "they don't show". Fixed to
// use the local sitLayersControl every other overlay on this page already
// registers against (MapImageOverlays, event zones, weather alerts, etc.).
t("GH#47: ensureUnitLayer registers Units (EOC) against the local sitLayersControl, not window._mapLayersControl",
    (bool) preg_match('/function ensureUnitLayer\(\)[\s\S]{0,600}?if \(sitLayersControl && !window\._eocUnitsInCtl\)[\s\S]{0,200}?sitLayersControl\.addOverlay\(unitMarkers/', $s));
t("GH#47: ensureFacilityLayer registers Facilities (EOC) against the local sitLayersControl, not window._mapLayersControl",
    (bool) preg_match('/function ensureFacilityLayer\(\)[\s\S]{0,400}?if \(sitLayersControl && !window\._eocFacsInCtl\)[\s\S]{0,200}?sitLayersControl\.addOverlay\(facMarkers/', $s));
t("GH#47: window._mapLayersControl is no longer referenced anywhere in situation.php",
    strpos($s, 'window._mapLayersControl') === false);

// ── GH#47 ROUND 2 (2026-08-14) — the round-1 fix above still didn't work.
// It correctly swapped the dead `window._mapLayersControl` reference for
// `sitLayersControl`, the variable every other overlay on this page uses --
// but `sitLayersControl` was declared with `var` INSIDE initMap() (a
// function-scoped local), while ensureUnitLayer()/ensureFacilityLayer() are
// SIBLING top-level functions that can't see it. Reported unreachable in
// production (v4.2.17) by cbyrdmo on GH#47; reproduced live via browser on
// both your-server.example.com and local XAMPP -- calling ensureUnitLayer()
// threw "sitLayersControl is not defined", silently swallowed by
// loadUnits()'s empty .catch(). The round-1 test above (checking the RIGHT
// NAME is referenced) passed the whole time this was broken, because it
// never checked WHERE that name was declared. Fixed the same way map/
// tileLayer/markerGroup already work: declare at top-level scope, assign
// (not re-declare) inside initMap().
t("GH#47 round 2: sitLayersControl is declared at top-level scope (shared, like map/tileLayer/markerGroup), not just referenced by name",
    (bool) preg_match('/\n {4}var sitLayersControl;\n/', $s));
t("GH#47 round 2: initMap() ASSIGNS to the shared sitLayersControl (no `var`), matching how `map = L.map(...)` already works",
    (bool) preg_match('/\n {8}sitLayersControl = L\.control\.layers\(/', $s));
t("GH#47 round 2: no local re-declaration (`var sitLayersControl =`) remains anywhere -- that would shadow the shared one again",
    strpos($s, 'var sitLayersControl =') === false);
t("GH#47 round 2: loadUnits()'s fetch chain no longer swallows errors silently (this is exactly what hid the bug for 3 days)",
    (bool) preg_match('/function loadUnits\(\)[\s\S]{0,600}?\.catch\(function\s*\(err\)\s*\{\s*console\.error\(/', $s));
t("GH#47 round 2: loadFacilities()'s fetch chain no longer swallows errors silently",
    (bool) preg_match('/function loadFacilities\(\)[\s\S]{0,600}?\.catch\(function\s*\(err\)\s*\{\s*console\.error\(/', $s));

// ── GH#71 (cbyrdmo, 2026-08-17; fix by ethanhawkes-gif, PR #72) — the EOC
// Units "Updated" cell must reflect fresh GPS tracking activity, not just the
// last STATUS change. api/responders.php already resolves and sends
// `last_track`; the cell just never looked at it.
t("GH#71: sitLatestAgo() picks the newest of several timestamps rather than the first truthy one",
    strpos($s, 'function sitLatestAgo()') !== false);
t("GH#71: the EOC Units Updated cell uses sitLatestAgo(last_track, status_updated, updated), not the old status-only sitAgo() call",
    strpos($s, 'sitLatestAgo(u.last_track, u.status_updated, u.updated)') !== false &&
    strpos($s, 'sitAgo(u.status_updated || u.updated)') === false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
