<?php
/**
 * Map Image Overlays Tests (Phase 110 / GH #43)
 *
 * Verifies the map_image_overlays migration is idempotent, the table
 * schema matches the spec, the RBAC permission is seeded and granted,
 * the API/JS/page files carry the required security + rendering
 * primitives, and anchor JSON round-trips through the database.
 *
 * Usage: php tests/test_map_image_overlays.php
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;

function ok($label, $condition) {
    global $pass, $fail;
    if ($condition) {
        echo "[PASS] $label\n";
        $pass++;
    } else {
        echo "[FAIL] $label\n";
        $fail++;
    }
}

echo "=== Map Image Overlays Tests (Phase 110) ===\n\n";

$root      = dirname(__DIR__);
$migration = $root . '/sql/run_map_image_overlays.php';
$apiFile   = $root . '/api/map-image-overlays.php';
$jsFile    = $root . '/assets/js/map-image-overlays.js';
$editorJs  = $root . '/assets/js/map-overlay-editor.js';
$pageFile  = $root . '/map-overlays.php';

// ── Migration file + idempotency ─────────────────────────────
echo "-- Migration --\n";

ok('Migration file exists', is_file($migration));

// Run the migration twice — both runs must be clean ([ERR]-free).
// Paths here contain no spaces, so the command needs no quoting (which
// sidesteps the Windows cmd.exe double-quote stripping quirk).
$phpBin = PHP_BINARY;
$cmd    = $phpBin . ' ' . $migration . ' 2>&1';
if (strpos($phpBin, ' ') !== false || strpos($migration, ' ') !== false) {
    $cmd = '"' . escapeshellarg($phpBin) . ' ' . escapeshellarg($migration) . ' 2>&1"';
}
$out1 = (string) shell_exec($cmd);
$out2 = (string) shell_exec($cmd);

ok('Migration run 1 completes', strpos($out1, 'Done.') !== false);
ok('Migration run 1 has no [ERR]', strpos($out1, '[ERR]') === false);
ok('Migration run 2 (idempotency) completes', strpos($out2, 'Done.') !== false);
ok('Migration run 2 has no [ERR]', strpos($out2, '[ERR]') === false);
ok('Migration reports table ready', strpos($out2, 'map_image_overlays table ready') !== false);

// ── Table schema ─────────────────────────────────────────────
echo "\n-- Table & Schema --\n";

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'map_image_overlays']
    );
    ok('map_image_overlays table exists', $tbl !== null);
} catch (Exception $e) {
    ok('map_image_overlays table exists', false);
}

try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}map_image_overlays`");
    $colNames = array_column($cols, 'Field');
    foreach (['id', 'name', 'file_path', 'mime', 'anchor_json', 'opacity', 'enabled', 'sort_order', 'created_by', 'created_at'] as $c) {
        ok("Column $c exists", in_array($c, $colNames));
    }
} catch (Exception $e) {
    ok('Column check: ' . $e->getMessage(), false);
}

try {
    $opDefault = db_fetch_value(
        "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'opacity'",
        [$prefix . 'map_image_overlays']
    );
    ok('opacity default is 0.70', abs((float) $opDefault - 0.70) < 0.001);
} catch (Exception $e) {
    ok('opacity default check', false);
}

// ── RBAC permission ──────────────────────────────────────────
echo "\n-- RBAC --\n";

$permId = null;
try {
    $perm = db_fetch_one(
        "SELECT * FROM `{$prefix}permissions` WHERE `code` = ?",
        ['action.manage_map_overlays']
    );
    ok('Permission action.manage_map_overlays exists', $perm !== null);
    ok('Permission category is action', $perm !== null && $perm['category'] === 'action');
    $permId = $perm ? (int) $perm['id'] : null;
} catch (Exception $e) {
    ok('Permission row check: ' . $e->getMessage(), false);
}

try {
    $grants = $permId !== null ? (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE permission_id = ?",
        [$permId]
    ) : 0;
    ok('Permission granted to at least one role', $grants >= 1);
} catch (Exception $e) {
    ok('Role grant check: ' . $e->getMessage(), false);
}

// ── API file: security primitives ────────────────────────────
echo "\n-- API --\n";

ok('API file exists', is_file($apiFile));
$apiSrc = is_file($apiFile) ? file_get_contents($apiFile) : '';
ok('API requires auth.php', strpos($apiSrc, "require_once __DIR__ . '/auth.php'") !== false);
ok('API suppresses display_errors', strpos($apiSrc, "ini_set('display_errors', '0')") !== false);
ok('API checks rbac_can(action.manage_map_overlays)', strpos($apiSrc, "rbac_can('action.manage_map_overlays')") !== false);
ok('API verifies CSRF', strpos($apiSrc, 'csrf_verify(') !== false);
ok('API derives MIME via finfo (never $_FILES[type])', strpos($apiSrc, 'finfo_file') !== false && strpos($apiSrc, "\$file['type']") === false);
ok('API uses random filenames (random_bytes)', strpos($apiSrc, 'random_bytes(16)') !== false);
ok('API uses json_error_safe for exceptions', strpos($apiSrc, 'json_error_safe(') !== false);
ok('API realpath-checks delete path', strpos($apiSrc, 'realpath(') !== false);
ok('API writes audit entries', strpos($apiSrc, "audit_log('config'") !== false);
ok('API creates uploads/overlays dir (mkdir)', strpos($apiSrc, '/overlays') !== false && strpos($apiSrc, 'mkdir(') !== false);
ok('API handles PDF conversion (Imagick + gs fallback)', strpos($apiSrc, "class_exists('Imagick')") !== false && strpos($apiSrc, 'escapeshellarg(') !== false);
// Cap raised 20 MB → 100 MB for large event PDFs (a beta tester, GH #43).
ok('API enforces 100 MB cap', strpos($apiSrc, '100 * 1048576') !== false
    && strpos($apiSrc, "\$file['size'] > \$OVERLAY_MAX_BYTES") !== false);

// ── JS layer library ─────────────────────────────────────────
echo "\n-- JS --\n";

ok('map-image-overlays.js exists', is_file($jsFile));
$jsSrc = is_file($jsFile) ? file_get_contents($jsFile) : '';
ok('JS creates eventImagePane', strpos($jsSrc, 'eventImagePane') !== false);
ok('JS pane zIndex is 350 (tiles < image < markups)', strpos($jsSrc, 'zIndex = 350') !== false);
ok('JS exposes window.MapImageOverlays', strpos($jsSrc, 'window.MapImageOverlays') !== false);
ok('JS shares newui_map_layers persistence key', strpos($jsSrc, 'newui_map_layers') !== false);
ok('JS has setAnchors + setOpacity', strpos($jsSrc, 'setAnchors:') !== false && strpos($jsSrc, 'setOpacity:') !== false);
ok('Editor JS exists', is_file($editorJs));

// ── Admin page ───────────────────────────────────────────────
echo "\n-- Page --\n";

ok('map-overlays.php exists', is_file($pageFile));
$pageSrc = is_file($pageFile) ? file_get_contents($pageFile) : '';
ok('Page uses sess_bootstrap_auto', strpos($pageSrc, 'sess_bootstrap_auto()') !== false);
ok('Page gates on action.manage_map_overlays', strpos($pageSrc, 'action.manage_map_overlays') !== false);
ok('Page loads map-image-overlays.js', strpos($pageSrc, 'map-image-overlays.js') !== false);

// ── Anchor JSON round-trip through the DB ────────────────────
echo "\n-- Anchor round-trip --\n";

$testId = null;
try {
    $anchors = [
        'tl' => ['lat' => 44.98, 'lng' => -93.27],
        'tr' => ['lat' => 44.98, 'lng' => -93.25],
        'bl' => ['lat' => 44.96, 'lng' => -93.27],
    ];
    db_query(
        "INSERT INTO `{$prefix}map_image_overlays`
            (name, file_path, mime, anchor_json, opacity, enabled, sort_order, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        ['Test Overlay (unit test)', 'uploads/overlays/test_dummy.png', 'image/png',
         json_encode($anchors), 0.55, 1, 0, 1]
    );
    $testId = (int) db_insert_id();
    ok('Insert overlay row succeeds', $testId > 0);

    $row = db_fetch_one("SELECT * FROM `{$prefix}map_image_overlays` WHERE id = ?", [$testId]);
    ok('Read overlay row back', $row !== null);

    $decoded = json_decode($row['anchor_json'], true);
    ok('anchor_json decodes to array', is_array($decoded));
    ok('Anchors have tl/tr/bl corners', isset($decoded['tl'], $decoded['tr'], $decoded['bl']));
    ok('tl lat round-trips', abs((float) $decoded['tl']['lat'] - 44.98) < 0.0001);
    ok('bl lng round-trips', abs((float) $decoded['bl']['lng'] - (-93.27)) < 0.0001);
    ok('Anchor lat within valid range', (float) $decoded['tl']['lat'] >= -90 && (float) $decoded['tl']['lat'] <= 90);
    ok('Anchor lng within valid range', (float) $decoded['tl']['lng'] >= -180 && (float) $decoded['tl']['lng'] <= 180);
    ok('Opacity stored as 0.55', abs((float) $row['opacity'] - 0.55) < 0.001);
} catch (Exception $e) {
    ok('Anchor round-trip: ' . $e->getMessage(), false);
}

// Defaults: insert a minimal row, defaults must apply.
$testId2 = null;
try {
    db_query(
        "INSERT INTO `{$prefix}map_image_overlays` (name, file_path, mime)
         VALUES (?, ?, ?)",
        ['Test Overlay Defaults (unit test)', 'uploads/overlays/test_dummy2.png', 'image/png']
    );
    $testId2 = (int) db_insert_id();
    $row2 = db_fetch_one("SELECT * FROM `{$prefix}map_image_overlays` WHERE id = ?", [$testId2]);
    ok('Default anchor_json is NULL (unpositioned)', $row2['anchor_json'] === null);
    ok('Default opacity applies (0.70)', abs((float) $row2['opacity'] - 0.70) < 0.001);
    ok('Default enabled applies (1)', (int) $row2['enabled'] === 1);
} catch (Exception $e) {
    ok('Defaults check: ' . $e->getMessage(), false);
}

// ── Cleanup ──────────────────────────────────────────────────
try {
    if ($testId)  db_query("DELETE FROM `{$prefix}map_image_overlays` WHERE id = ?", [$testId]);
    if ($testId2) db_query("DELETE FROM `{$prefix}map_image_overlays` WHERE id = ?", [$testId2]);
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
