<?php
/**
 * GH #78 — dashboard "Live Tracking" toggle is broken two independent ways
 * (Eric Osterberg, 2026-08-18, re-filed after #73/#74 because the EOC
 * dashboard's own attempt at the same fix never worked).
 *
 * Reported behavior: units still show as unconditional "orange dots" on the
 * main dashboard map even with no way to hide them — no working toggle
 * exists, though the code LOOKS like it tries to add one.
 *
 * Root cause, confirmed by re-reading assets/js/app.js against the report:
 *
 *   1. `L.control.layers(baseLayers, overlays, ...)` is constructed and
 *      added to the map early (around line 1075). The "Live Tracking" entry
 *      used to be spliced into that same `overlays` object ~150 lines later,
 *      well after construction. Leaflet's layer control only reads the
 *      `overlays` object AT CONSTRUCTION TIME — a later mutation of the same
 *      object never reaches the already-rendered checkbox list. This file
 *      already uses the correct post-construction pattern elsewhere (markup
 *      categories, via `layersControl.addOverlay(...)`), so "Live Tracking"
 *      simply never appeared as an option.
 *
 *   2. Even if it had appeared, it would have controlled the WRONG layer.
 *      The old code built a brand-new, empty `L.layerGroup()` and put THAT
 *      in the overlays object. The real unit markers are drawn into
 *      UnitTracking's own internal layer group (created inside
 *      UnitTracking.init(), added straight to the map) — `init()` doesn't
 *      accept or use an externally supplied group. The decoy group was
 *      therefore permanently empty and never referenced again.
 *
 * This is the identical root cause GH #74/#73 already fixed on
 * situation.php (see tests/test_gh74_live_gps_layer_toggle.php) — the fix
 * here follows that file's already-proven pattern exactly: pull the
 * tracker's REAL layer group via getLayerGroup() and register it with the
 * already-built control via addOverlay(), plus MapLayerPrefs so the choice
 * persists per-user under the SAME 'units_live' catalog id situation.php
 * already uses (no new catalog entry needed — proven server-side by
 * test_gh74_live_gps_layer_toggle.php's own persistence round trip).
 *
 * Static guards over app.js's inline JS (no headless DOM here, matching the
 * house convention set by tests/test_situation_map_fixes.php and
 * tests/test_gh74_live_gps_layer_toggle.php for this exact class of bug).
 * Usage: php tests/test_gh78_dashboard_live_gps_layer_toggle.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== GH #78 (dashboard Live Tracking overlay toggle) ===\n\n";

$s = @file_get_contents($base . '/assets/js/app.js');
if ($s === false) { t('assets/js/app.js readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// ── 1. The tracking group is added to the layer control so it can be hidden ──
// Must be sourced from the tracker itself (getLayerGroup), not a fresh
// throwaway group, or the checkbox would toggle nothing (bug #2 above).
t("GH#78: the live-GPS tracker layer is registered in layersControl via getLayerGroup()",
    (bool) preg_match('/window\._unitTracker\.getLayerGroup\(\)/', $s)
        && (bool) preg_match('/layersControl\.addOverlay\(\s*liveGpsLayer/', $s));

t("GH#78: the overlay carries the 'Live Tracking' label",
    strpos($s, 'Live Tracking') !== false);

// ── 2. addOverlay() is called, not just a mutation of the overlays object ──
// This is bug #1: a bare `overlays[...] = liveGpsLayer` assignment alone
// never reaches an already-built layer control. Prove BOTH exist and that
// addOverlay is actually invoked, not merely mentioned in a comment.
t("GH#78: layersControl.addOverlay() is called with the real tracker layer (not just an overlays[] mutation)",
    (bool) preg_match('/\n\s*layersControl\.addOverlay\(\s*liveGpsLayer,/', $s));

// ── 3. The old decoy pattern is gone ──
t("GH#78 regression guard: the old empty decoy layer group ('trackerLayer') is gone",
    strpos($s, 'trackerLayer') === false);

// ── 4. The wiring is guarded so a build without the control/tracker is a no-op ──
t("GH#78: registration is guarded on layersControl AND getLayerGroup existing",
    (bool) preg_match('/if\s*\(\s*layersControl\s*&&\s*typeof\s+window\._unitTracker\.getLayerGroup\s*===\s*[\'"]function[\'"]\s*\)/', $s));

t("GH#78: the live-GPS overlay is registered with MapLayerPrefs under the SAME 'units_live' id situation.php uses, so it persists per user",
    (bool) preg_match('/MapLayerPrefs\.register\(\s*map\s*,\s*[\'"]units_live[\'"]\s*,\s*liveGpsLayer\s*\)/', $s));

// ── regression: UnitTracking itself is still started the same way ──
t("GH#78 regression: UnitTracking.init() + start() are still called unconditionally (default-on unchanged)",
    (bool) preg_match('/UnitTracking\.init\(map,/', $s)
        && (bool) preg_match('/window\._unitTracker\.start\(\)/', $s));

// ── regression: the pre-existing markup-category addOverlay pattern is untouched ──
t("GH#78 regression: the markup-category layersControl.addOverlay() call this fix modeled itself on is untouched",
    (bool) preg_match('/layersControl\.addOverlay\(\s*grp,\s*label\s*\)/', $s));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
