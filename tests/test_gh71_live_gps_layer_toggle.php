<?php
/**
 * GH #71 follow-up — live-GPS tracking overlay must be toggleable
 * (cbyrdmo, 2026-08-17, from a live volunteer fire/EMS EOC deployment).
 *
 * Reported behavior: "If I disable units on the [layers] menu, the units
 * still appear on the map. They are now orange instead of green." The popup
 * on those markers reads "Unit — Last fix: Ns ago".
 *
 * Root cause: situation.php draws units through TWO independent layers.
 *
 *   1. unitMarkers  — the EOC roster layer, coloured by status
 *      (Available/On Shift = green #198754, On Scene = orange, ...). This is
 *      the one registered in the layer control as the "Units (EOC)" checkbox.
 *
 *   2. UnitTracking  — the real-time GPS overlay (assets/js/unit-tracking.js).
 *      UnitTracking.init() does `L.layerGroup().addTo(map)` and hands the
 *      group back via getLayerGroup(), but situation.php never registered it
 *      in the layer control. Its markers are coloured by each unit's tracking
 *      colour (unit.color, e.g. orange) and carry the "— Last fix:" tooltip.
 *
 * Because only layer #1 was behind the "Units (EOC)" checkbox, switching units
 * off removed the green roster markers and left the orange live-GPS markers on
 * screen with no control to hide them — exactly what the operator saw.
 *
 * Fix: register the tracking group as its own toggleable overlay (default on,
 * so nothing changes for installs that leave it alone) and register it with
 * MapLayerPrefs so the choice persists per user like every other overlay.
 *
 * Static guards over situation.php's inline JS (behavioral JS in a PHP file;
 * no headless DOM here, matching tests/test_situation_map_fixes.php).
 * Usage: php tests/test_gh71_live_gps_layer_toggle.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== GH #71 follow-up (live-GPS overlay toggle) ===\n\n";

$s = @file_get_contents($base . '/situation.php');
if ($s === false) { t('situation.php readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// ── the tracking group is added to the layer control so it can be hidden ──
// It must be sourced from the tracker itself (getLayerGroup), not a fresh
// throwaway group, or the checkbox would toggle nothing.
t("#71: the live-GPS tracker layer is registered in sitLayersControl via getLayerGroup()",
    (bool) preg_match('/tracker\.getLayerGroup\(\)/', $s)
        && (bool) preg_match('/sitLayersControl\.addOverlay\(\s*liveGpsLayer/', $s));

t("#71: the new overlay carries a distinct 'Units — live GPS' label (not confusable with 'Units (EOC)')",
    strpos($s, 'Units — live GPS') !== false);

// ── the wiring is guarded so a build without the control/tracker is a no-op ──
t("#71: registration is guarded on sitLayersControl AND tracker.getLayerGroup existing",
    (bool) preg_match('/if\s*\(\s*sitLayersControl\s*&&\s*typeof\s+tracker\.getLayerGroup\s*===\s*[\'"]function[\'"]\s*\)/', $s));

// ── per-user persistence parity with the other overlays ──
t("#71: the live-GPS overlay is registered with MapLayerPrefs so its state persists per user",
    (bool) preg_match('/MapLayerPrefs\.register\(\s*map\s*,\s*[\'"]units_live[\'"]\s*,\s*liveGpsLayer\s*\)/', $s));

// ── regression: the existing EOC layer wiring is untouched ──
t("#71 regression: unitMarkers is still registered as the 'Units (EOC)' overlay",
    (bool) preg_match('/sitLayersControl\.addOverlay\(\s*unitMarkers/', $s)
        && strpos($s, 'Units (EOC)') !== false);

t("#71 regression: UnitTracking overlay is still added to the map (default-on unchanged)",
    (bool) preg_match('/UnitTracking\.init\(map/', $s)
        && (bool) preg_match('/tracker\.start\(\)/', $s));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
