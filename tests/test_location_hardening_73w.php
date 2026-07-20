<?php
/**
 * Phase 73w regression tests — 4 HIGH findings from the
 * location/OwnTracks/APRS audit.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── push_pending now admin-only ───────────────────────────────────
$otc = file_get_contents(__DIR__ . '/../api/owntracks-config.php');
tcheck(preg_match("/action === 'push_pending'[^{]*\\{\\s*_admin_check\\(\\);/", $otc) === 1,
    'owntracks-config push_pending requires _admin_check()');

// ── geofences RBAC + uid + notify_users validation ────────────────
$gf = file_get_contents(__DIR__ . '/../api/geofences.php');
tcheck(strpos($gf, "rbac_can('action.manage_map')") !== false
    && strpos($gf, "rbac_can('action.manage_geofences')") !== false,
    'geofences POST gated by manage_map/geofences');
tcheck(strpos($gf, '$createdBy = (int) ($GLOBALS[\'current_user_id\'] ?? $_SESSION[\'user_id\'] ?? 0);') !== false,
    'geofences creates use explicit $createdBy from globals/session');
tcheck(strpos($gf, 'array_map(\'intval\', $notifyUsers)') !== false
    && strpos($gf, 'user` WHERE id IN') !== false,
    'geofences validates notify_users against the user table');

// ── mesh _mesh_sanitize_name + applied at ingest ──────────────────
$mesh = file_get_contents(__DIR__ . '/../api/mesh.php');
tcheck(strpos($mesh, 'function _mesh_sanitize_name(') !== false,
    'mesh.php defines _mesh_sanitize_name helper');
tcheck(strpos($mesh, "str_replace(['<', '>'], '', \$val)") !== false,
    'mesh sanitizer strips angle brackets');
$applied = substr_count($mesh, '_mesh_sanitize_name($n[\'short_name\']');
tcheck($applied >= 2,
    'mesh ingest uses sanitizer for short_name in both branches (got ' . $applied . ')');
$applied = substr_count($mesh, '_mesh_sanitize_name($n[\'long_name\']');
tcheck($applied >= 2,
    'mesh ingest uses sanitizer for long_name in both branches (got ' . $applied . ')');

// ── helper behaviour spot-check ──────────────────────────────────
// Load just the helper without booting the API stack.
if (!function_exists('_mesh_sanitize_name')) {
    preg_match('/function _mesh_sanitize_name\(.*?\n\}\n/s', $mesh, $m);
    eval($m[0]);
}
tcheck(_mesh_sanitize_name('<script>alert(1)</script>foo', 32) === 'scriptalert(1)/scriptfoo',
    'sanitizer strips angle brackets in payload');
tcheck(_mesh_sanitize_name(null, 32) === null,
    'sanitizer passes null through');
tcheck(_mesh_sanitize_name('   ', 32) === null,
    'sanitizer returns null for whitespace-only input');
tcheck(_mesh_sanitize_name("Echo \xF0\x9F\x93\xA1 base", 32) !== null,
    'sanitizer keeps multibyte / emoji content');

echo "Phase 73w hardening regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
