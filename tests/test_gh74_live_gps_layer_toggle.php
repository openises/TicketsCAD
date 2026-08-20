<?php
/**
 * GH #74 / GH #73 — live-GPS tracking overlay must be toggleable, and the
 * choice must actually persist (cbyrdmo, 2026-08-17, from a live volunteer
 * fire/EMS EOC deployment; fix contributed by ethanhawkes-gif, PR #75).
 *
 * Reported behavior (GH #73): "If I disable units on the [layers] menu, the
 * units still appear on the map. They are now orange instead of green."
 * GH #74 is the same root cause, filed separately with the map-layer-control
 * angle: the popup on those markers reads "Unit — Last fix: Ns ago".
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
 * screen with no control to hide them — exactly what the operator saw in both
 * issues.
 *
 * Fix: register the tracking group as its own toggleable overlay ("Units —
 * live GPS", default on, so nothing changes for an install that leaves it
 * alone) and register it with MapLayerPrefs so the choice persists per user
 * like every other overlay.
 *
 * A gap in the upstream PR's own claim, closed while porting: MapLayerPrefs
 * only persists ids present in inc/map-layer-prefs.php's map_layer_catalog().
 * map_layer_prefs_set() silently drops any id NOT in that catalog
 * (`if (isset($catalog[$id]))`), so registering 'units_live' client-side
 * without also cataloguing it server-side would toggle correctly for the
 * current page load (Leaflet's own layer control adds/removes the layer
 * directly) but silently forget the choice on every reload — the exact
 * "setting with no consumer" shape this project has been bitten by before
 * (see the tile_mode pitfall in CLAUDE.md). 'units_live' is now catalogued in
 * inc/map-layer-prefs.php AND assets/js/map-layer-prefs.js's SHIPPED_DEFAULTS
 * fallback; section 3 below proves the round trip actually persists.
 *
 * Static guards over situation.php's inline JS (behavioral JS in a PHP file;
 * no headless DOM here, matching tests/test_situation_map_fixes.php) plus a
 * real PHP round trip through the catalog/writer/reader for the persistence
 * gap.
 * Usage: php tests/test_gh74_live_gps_layer_toggle.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/map-layer-prefs.php';

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== GH #74 / GH #73 (live-GPS overlay toggle + persistence) ===\n\n";

$s = @file_get_contents($base . '/situation.php');
if ($s === false) { t('situation.php readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// ── 1. The tracking group is added to the layer control so it can be hidden ──
// It must be sourced from the tracker itself (getLayerGroup), not a fresh
// throwaway group, or the checkbox would toggle nothing.
t("GH#74: the live-GPS tracker layer is registered in sitLayersControl via getLayerGroup()",
    (bool) preg_match('/tracker\.getLayerGroup\(\)/', $s)
        && (bool) preg_match('/sitLayersControl\.addOverlay\(\s*liveGpsLayer/', $s));

t("GH#74: the new overlay carries a distinct 'Units — live GPS' label (not confusable with 'Units (EOC)')",
    strpos($s, 'Units — live GPS') !== false);

// ── 2. The wiring is guarded so a build without the control/tracker is a no-op ──
t("GH#74: registration is guarded on sitLayersControl AND tracker.getLayerGroup existing",
    (bool) preg_match('/if\s*\(\s*sitLayersControl\s*&&\s*typeof\s+tracker\.getLayerGroup\s*===\s*[\'"]function[\'"]\s*\)/', $s));

t("GH#74: the live-GPS overlay is registered with MapLayerPrefs so its state can persist per user",
    (bool) preg_match('/MapLayerPrefs\.register\(\s*map\s*,\s*[\'"]units_live[\'"]\s*,\s*liveGpsLayer\s*\)/', $s));

// ── regression: the existing EOC layer wiring is untouched ──
t("GH#74 regression: unitMarkers is still registered as the 'Units (EOC)' overlay",
    (bool) preg_match('/sitLayersControl\.addOverlay\(\s*unitMarkers/', $s)
        && strpos($s, 'Units (EOC)') !== false);

t("GH#74 regression: UnitTracking overlay is still added to the map (default-on unchanged)",
    (bool) preg_match('/UnitTracking\.init\(map/', $s)
        && (bool) preg_match('/tracker\.start\(\)/', $s));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. 'units_live' is catalogued server-side, so the toggle SURVIVES A RELOAD --\n";
// ─────────────────────────────────────────────────────────────────────────
// This is the gap the upstream PR's own description claimed was covered
// ("persist the choice per-user like the other overlays") but the catalog
// never actually gained the id. Prove it the same way test_map_layer_prefs.php
// proves every other layer: drive the REAL writer, read back with the REAL
// reader — no hand-seeded row.

$catalog = map_layer_catalog();
t("GH#74: 'units_live' is a catalogued layer id (map_layer_catalog())",
    isset($catalog['units_live']));
t("GH#74: 'units_live' defaults to visible (matches UnitTracking.init()'s addTo(map) — no behavior change on upgrade)",
    isset($catalog['units_live']) && $catalog['units_live']['default'] === true);

$jsSrc = (string) @file_get_contents($base . '/assets/js/map-layer-prefs.js');
t("GH#74: the JS SHIPPED_DEFAULTS fallback also knows 'units_live' (must match the PHP catalog per that file's own docblock)",
    (bool) preg_match('/SHIPPED_DEFAULTS\s*=\s*\{[^}]*\bunits_live\s*:\s*true\b/s', $jsSrc));

$dbUp = false;
try { db_fetch_value("SELECT 1"); $dbUp = true; } catch (Throwable $e) { $dbUp = false; }

$prefix = $GLOBALS['db_prefix'] ?? '';
$tableUp = false;
if ($dbUp) {
    try {
        $tableUp = (bool) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . 'user_screen_prefs']
        );
    } catch (Throwable $e) { $tableUp = false; }
}

if (!$dbUp || !$tableUp) {
    echo "SKIP: no database or user_screen_prefs table — persistence round-trip not exercised\n";
} else {
    require_once __DIR__ . '/_test_admin.php';
    $uid = test_admin_user_id();

    // Preserve whatever this user already had, and restore it at the end —
    // never silently rewrite a real person's saved preferences.
    $hadRow = null;
    try {
        $hadRow = db_fetch_value(
            "SELECT prefs_json FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ? LIMIT 1",
            [$uid, MAP_LAYER_PREFS_SCREEN]
        );
    } catch (Throwable $e) {}

    map_layer_prefs_reset($uid);

    // THE round trip that the un-catalogued id could never complete: turn the
    // live-GPS overlay OFF, then re-read through the real reader.
    $wrote = map_layer_prefs_set($uid, ['units_live' => false]);
    t("GH#74: map_layer_prefs_set() reports success for 'units_live'", $wrote === true);

    $after = map_layer_prefs_get($uid);
    t("GH#74: 'units_live' OFF survives a re-read through the real reader (the persistence half of the PR's own claim)",
        isset($after['visible']['units_live']) && $after['visible']['units_live'] === false);

    $rawRow = null;
    try {
        $rawRow = db_fetch_value(
            "SELECT prefs_json FROM `{$prefix}user_screen_prefs` WHERE user_id = ? AND screen = ? LIMIT 1",
            [$uid, MAP_LAYER_PREFS_SCREEN]
        );
    } catch (Throwable $e) {}
    t("GH#74: the stored row actually names 'units_live' (not silently dropped by the catalog filter)",
        is_string($rawRow) && strpos($rawRow, 'units_live') !== false);

    // Turning it back on must persist too.
    map_layer_prefs_set($uid, ['units_live' => true]);
    t("GH#74: 'units_live' ON persists as well (both directions)",
        map_layer_prefs_get($uid)['visible']['units_live'] === true);

    // Restore the user's original row.
    map_layer_prefs_reset($uid);
    try {
        if ($hadRow !== null && $hadRow !== false) {
            db_query("INSERT INTO `{$prefix}user_screen_prefs` (user_id, screen, prefs_json)
                      VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE prefs_json = VALUES(prefs_json)",
                     [$uid, MAP_LAYER_PREFS_SCREEN, $hadRow]);
        }
    } catch (Throwable $e) {}
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
