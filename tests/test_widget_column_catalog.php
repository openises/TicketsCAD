<?php
/**
 * GH #63 regression — dashboard widget column catalogs must match their
 * table templates.
 *
 * The per-user column picker (ScreenPrefs) hides columns by nth-child CSS and
 * persists a per-screen catalog. If a widget table's <th data-col-id> set
 * drifts from the server-side prefs_screen_defaults() catalog for that screen,
 * two silent failures follow: (1) a column with no catalog entry can't be
 * toggled/persisted, and (2) prefs_get() rebuilds saved prefs by iterating the
 * catalog, so a screen missing from the catalog DROPS the user's saved layout
 * on next load. This test asserts, for every dashboard widget with a picker,
 * that the catalog column ids match the template's data-col-id ids EXACTLY and
 * in the same order (order matters — the picker hides by position).
 *
 * Usage: php tests/test_widget_column_catalog.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/screen-prefs.php';

$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

$defaults = prefs_screen_defaults();
$indexHtml = file_get_contents(__DIR__ . '/../index.php');

// screen 'widget-<suffix>' <-> table id '<suffix>WidgetTable'
$widgets = ['widget-incidents', 'widget-responders', 'widget-facilities'];

foreach ($widgets as $screen) {
    $suffix = substr($screen, strlen('widget-'));
    $tableId = $suffix . 'WidgetTable';

    // Catalog ids (in pos order).
    $catCols = $defaults[$screen]['columns'] ?? null;
    test("$screen: catalog exists", is_array($catCols) && count($catCols) > 0);
    if (!is_array($catCols)) { continue; }
    usort($catCols, function ($a, $b) { return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0); });
    $catIds = array_map(function ($c) { return $c['id']; }, $catCols);

    // Template data-col-id ids (in document order) for this table.
    // Isolate the <table id="..."> ... </table> block, then pull data-col-id.
    $tplIds = [];
    if (preg_match('/<table[^>]*\bid="' . preg_quote($tableId, '/') . '"[\s\S]*?<\/table>/i',
                   $indexHtml, $m)) {
        preg_match_all('/data-col-id="([a-z0-9_]+)"/i', $m[0], $mm);
        $tplIds = $mm[1];
    }
    test("$screen: template table #$tableId found with data-col-id cells", count($tplIds) > 0);

    // Exact match, same order.
    test("$screen: catalog ids == template data-col-id ids (order-sensitive)",
        $catIds === $tplIds);
    if ($catIds !== $tplIds) {
        echo "      catalog:  " . implode(',', $catIds) . "\n";
        echo "      template: " . implode(',', $tplIds) . "\n";
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
