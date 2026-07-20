<?php
/**
 * GH #68 + #65 — units.php status-filter classification + dashboard widget
 * text filter. Static wiring guards (the logic is browser JS); the #68
 * classification is checked by re-implementing the same rules on the seed +
 * short-code values so the regression is pinned.
 *
 * Usage: php tests/test_dashboard_filters.php
 */
$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== GH #68 units filter + GH #65 dashboard text filter ===\n\n";

// ── #68: the classifier must handle the SEED groups (av/inserv/unav) AND
//        short custom codes — the exact case the old 'avail' substring missed.
$units = rd($base . '/assets/js/units.js');
t('units.js has the classifyAvailability helper (group-driven, normalized)',
    strpos($units, 'function classifyAvailability') !== false &&
    strpos($units, "replace(/[^a-z]/g, '')") !== false);
t('units.js no longer uses the broken group.indexOf(\'avail\') test',
    strpos($units, "group.indexOf('avail')") === false &&
    strpos($units, "group.indexOf('unavail')") === false);
t('filter uses the classifier + keeps on-call = in_service',
    strpos($units, 'classifyAvailability(u)') !== false &&
    strpos($units, 'active_assignments <= 0 && cls') !== false);

// Re-implement the JS rules here to prove the SEED groups classify correctly
// (av → available, unav → unavailable, inserv → in_service) — the values the
// old code silently failed on.
function _cls(string $group, string $name): string {
    $g = preg_replace('/[^a-z]/', '', strtolower($group));
    $n = preg_replace('/[^a-z]/', '', strtolower($name));
    $isUn = fn($s) => strpos($s, 'un') === 0 || strpos($s, 'off') === 0 || strpos($s, 'out') === 0 || $s === 'na' || strpos($s, 'oos') === 0;
    $isIs = fn($s) => strpos($s, 'inserv') === 0 || strpos($s, 'service') === 0 || $s === 'is' || $s === 'en';
    $isAv = fn($s) => strpos($s, 'av') === 0 || strpos($s, 'avail') === 0 || $s === 'a' || $s === 'rdy' || strpos($s, 'ready') === 0;
    if ($isUn($g) || ($g === '' && $isUn($n))) return 'unavailable';
    if ($isIs($g) || ($g === '' && $isIs($n))) return 'in_service';
    if ($isAv($g) || ($g === '' && $isAv($n))) return 'available';
    return '';
}
t('seed group "av" → available (old code returned nothing)',  _cls('av', 'available') === 'available');
t('seed group "unav" → unavailable (not misread as available)', _cls('unav', 'unavailable') === 'unavailable');
t('seed group "inserv" → in_service',                          _cls('inserv', 'in_service') === 'in_service');
t('no group, short name "AV" → available (a beta tester\'s case)',     _cls('', 'AV') === 'available');
t('no group, "Off Shift" → unavailable',                       _cls('', 'Off Shift') === 'unavailable');

// ── #65: dashboard text filter wiring ───────────────────────────────────────
$wm = rd($base . '/assets/js/widget-manager.js');
t('widget headers get a .widget-filter input on all 3 list widgets',
    substr_count($wm, 'widget-filter') >= 1 &&
    strpos($wm, "data-filter-widget=") !== false &&
    strpos($wm, "item.id === 'incidents' || item.id === 'responders' || item.id === 'facilities'") !== false);
$app = rd($base . '/assets/js/app.js');
t('app.js applies + re-applies the filter after each render',
    strpos($app, 'function applyWidgetFilter') !== false &&
    strpos($app, "applyWidgetFilter('incidents')") !== false &&
    strpos($app, "applyWidgetFilter('responders')") !== false &&
    strpos($app, "applyWidgetFilter('facilities')") !== false);
t('app.js wires the filter input event (delegated)',
    strpos($app, ".widget-filter[data-filter-widget]") !== false &&
    strpos($app, '_widgetFilters') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
