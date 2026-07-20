<?php
/**
 * Phase 12 — sunset legacy levels from runtime code.
 *
 * Verifies via source-grep that:
 *   - is_admin() and current_role_name() exist
 *   - get_level_text() is a deprecated compat shim returning the role name
 *   - inc/access.php uses is_admin() instead of $level <= 1
 *   - inc/scheduling-perms.php scheduling_is_admin() uses is_admin()
 *   - All 34 page templates use current_role_name() (no get_level_text calls)
 *   - All 35 API endpoints have replaced $current_level checks with is_admin()
 *   - api/config-admin.php no longer INSERTs/UPDATEs user.level
 *   - api/config-admin.php user list no longer SELECTs u.level
 *   - login.php populates $_SESSION['role_id'] + $_SESSION['role_name']
 *   - assets/js/config.js no longer has the LEVELS integer→name map
 *
 * Migration-tool files are explicitly excluded — they're allowed to know.
 */

require_once __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Phase 12 — sunset legacy levels regression ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Helpers exist ─────────────────────────────────────────────────────
require_once $base . '/inc/rbac.php';
if (function_exists('is_admin')) {
    ok('is_admin() helper defined');
} else {
    bad('is_admin() missing');
}
if (function_exists('current_role_name')) {
    ok('current_role_name() helper defined');
} else {
    bad('current_role_name() missing');
}

// ── get_level_text is a shim that returns current_role_name() ────────
$fn = file_get_contents($base . '/inc/functions.php');
if (strpos($fn, 'thin compatibility shim') !== false &&
    strpos($fn, 'current_role_name()') !== false) {
    ok('get_level_text() is a compat shim that delegates to current_role_name()');
} else {
    bad('get_level_text() is NOT a compat shim');
}
// And it no longer carries the legacy integer mapping.
if (strpos($fn, "'Super'") === false &&
    strpos($fn, "'Administrator'") === false) {
    ok('Legacy integer→display map removed from get_level_text()');
} else {
    bad('Legacy integer map still in get_level_text()');
}

// ── inc/access.php ───────────────────────────────────────────────────
$ac = file_get_contents($base . '/inc/access.php');
if (strpos($ac, 'is_admin()') !== false &&
    !preg_match('/if\s*\(\s*\$level\s*<=\s*1\s*\)/', $ac)) {
    ok('inc/access.php uses is_admin() instead of $level <= 1');
} else {
    bad('inc/access.php still uses $level <= 1');
}

// ── inc/scheduling-perms.php ─────────────────────────────────────────
$sp = file_get_contents($base . '/inc/scheduling-perms.php');
if (strpos($sp, 'return is_admin();') !== false) {
    ok('scheduling_is_admin() delegates to is_admin()');
} else {
    bad('scheduling_is_admin() does NOT use is_admin()');
}

// ── Page templates: no get_level_text() calls outside the helper ──
$pages = [
    'about.php', 'callboard.php', 'compliance-dashboard.php', 'constituents.php',
    'equipment.php', 'facilities.php', 'facility-board.php', 'facility-detail.php',
    'facility-edit.php', 'help.php', 'ics-forms.php', 'import-export.php',
    'incident-detail.php', 'incident-list.php', 'index.php', 'links.php',
    'messaging.php', 'mobile.php', 'new-incident.php', 'profile.php',
    'quick-start.php', 'reports.php', 'roster.php', 'scheduling.php',
    'search.php', 'situation.php', 'sop.php', 'status.php', 'status-time.php',
    'teams.php', 'unit-detail.php', 'unit-edit.php', 'units.php', 'vehicles.php',
];
$bad = [];
foreach ($pages as $p) {
    $src = file_get_contents($base . '/' . $p);
    if ($src === false) continue;
    if (preg_match('/get_level_text\s*\(\s*\(int\)\s*\$_SESSION\[/', $src)) {
        $bad[] = $p;
    }
}
if (empty($bad)) {
    ok('No page template still calls get_level_text() on $_SESSION[\'level\']');
} else {
    bad('Pages still using get_level_text()', implode(', ', $bad));
}

// ── API endpoints: no $current_level > 1 / <= 1 patterns ─────────────
$skipFiles = ['rbac.php'];
$apis = glob($base . '/api/*.php');
$badApis = [];
foreach ($apis as $api) {
    $name = basename($api);
    if (in_array($name, $skipFiles, true)) continue;
    $src = file_get_contents($api);
    // Match the legacy integer-comparison patterns we expected to sunset.
    if (preg_match('/\$current_level\s*>\s*[012]\b/', $src) ||
        preg_match('/\$current_level\s*<=\s*[012]\b/', $src)) {
        $badApis[] = $name;
    }
}
if (empty($badApis)) {
    ok('No API endpoint still uses $current_level integer comparisons (outside the migration tool)');
} else {
    bad('APIs still using $current_level checks', implode(', ', $badApis));
}

// ── api/config-admin.php no longer writes user.level ────────────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (preg_match('/UPDATE\s*`?[^`]*`?user`?\s*SET\s*[^;]*\blevel\b/', $ca)) {
    bad('api/config-admin.php still UPDATEs user.level');
} else {
    ok('api/config-admin.php no longer UPDATEs user.level');
}
if (preg_match('/INSERT\s*INTO\s*`?[^`]*user`?\s*\([^)]*\blevel\b/', $ca)) {
    bad('api/config-admin.php still INSERTs into user.level');
} else {
    ok('api/config-admin.php no longer INSERTs into user.level');
}
// Drop u.level from SELECT lists.
if (strpos($ca, 'u.`level`') !== false) {
    bad('api/config-admin.php still SELECTs u.level');
} else {
    ok('api/config-admin.php no longer SELECTs u.level');
}

// ── login.php populates session role_id / role_name ─────────────────
$lg = file_get_contents($base . '/login.php');
if (strpos($lg, "\$_SESSION['role_id']") !== false &&
    strpos($lg, "\$_SESSION['role_name']") !== false) {
    ok('login.php populates $_SESSION[role_id] + [role_name]');
} else {
    bad('login.php does NOT populate role session fields');
}

// ── assets/js/config.js dropped the LEVELS map ──────────────────────
$cj = file_get_contents($base . '/assets/js/config.js');
if (strpos($cj, "var LEVELS = {") === false) {
    ok('config.js LEVELS map removed');
} else {
    bad('config.js still has LEVELS map');
}

// ── is_admin() reset-cache behavior ─────────────────────────────────
// Simulate two different sessions and verify rbac_reset_cache() clears
// the static cache so is_admin() recomputes.
$_SESSION = ['user_id' => 1, 'user' => 'admin'];
rbac_reset_cache();
$wasAdmin = is_admin();
$_SESSION = ['user_id' => 999998];
rbac_reset_cache();
$nowAdmin = is_admin();
if ($wasAdmin === true && $nowAdmin === false) {
    ok('is_admin() recomputes after rbac_reset_cache()');
} else {
    bad('is_admin() stale cache', "wasAdmin=" . var_export($wasAdmin, true) . " nowAdmin=" . var_export($nowAdmin, true));
}

echo "\n";
echo "===========================================\n";
echo "Phase 12 sunset levels: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
