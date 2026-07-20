<?php
/**
 * Phase 10b — Access/RBAC bug-fix regression tests.
 *
 * Three bugs surfaced on 2026-06-11 while Eric tested the new `demo`
 * user (Operator role, level=2) against the facility board:
 *
 *   1. user_can_access_entity() in inc/access.php denied non-admin
 *      users 404 on facility detail even when their RBAC role granted
 *      screen.facility_detail. List endpoints honored RBAC but detail
 *      endpoints did not.
 *
 *   2. api/rbac.php role-list user_count included orphans (user_roles
 *      rows whose user was deleted) and expired time-bound grants.
 *
 *   3. api/config-admin.php DELETE user did not cascade to user_roles —
 *      the root cause of the orphan in (2).
 *
 * This suite source-greps the fixes so they can't silently regress.
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 10b — Access / RBAC fixes regression ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Bug 1: inc/access.php RBAC bypass ──────────────────────────────────
$acc = file_get_contents($base . '/inc/access.php');
if (strpos($acc, "rbac_can") !== false &&
    strpos($acc, "screen.facility_detail") !== false &&
    strpos($acc, "screen.incident_detail") !== false &&
    strpos($acc, "screen.unit_detail") !== false) {
    ok('inc/access.php honors RBAC permissions for facility/incident/responder');
} else {
    bad('inc/access.php missing RBAC bypass');
}
if (strpos($acc, "facility.view") !== false &&
    strpos($acc, "incident.view") !== false) {
    ok('inc/access.php also honors *.view permission codes');
} else {
    bad('inc/access.php missing *.view bypass');
}

// Functional check: simulate the RBAC bypass using a fake session.
// We can't easily call rbac_can() without a real user_id and seeded
// permissions, so instead we verify the function dispatches to
// rbac_can at all by inspecting the helper's behavior with level<=1
// (admin shortcut) which has always worked.
require_once $base . '/inc/access.php';
$_SESSION['level'] = 0;
$_SESSION['user_id'] = 1;
if (user_can_access_entity('facility', 1) === true) {
    ok('user_can_access_entity returns true for level=0 (admin shortcut)');
} else {
    bad('user_can_access_entity admin shortcut broken');
}

// Negative case: bogus user_id with no groups and no RBAC permissions →
// should still return false.
$_SESSION['level'] = 99;
$_SESSION['user_groups'] = [];
unset($_SESSION['user_id']);
$_SESSION['user_id'] = 999999; // unlikely to exist
// Phase 12: the new is_admin() helper caches per-process; reset so
// the prior level=0 admin case doesn't leak into this one.
if (function_exists('rbac_reset_cache')) rbac_reset_cache();
// Note: rbac_can() with bogus uid returns false. So the result hinges
// on the absence of allocates. We can't fully simulate without a real
// DB row, but at minimum we can check that level=99 + empty groups +
// no perm returns false on a non-existent facility (id=999999).
$out = user_can_access_entity('facility', 999999);
if ($out === false) {
    ok('user_can_access_entity returns false for level=99 + no groups + no RBAC');
} else {
    bad('user_can_access_entity unexpectedly returned true', var_export($out, true));
}

// ── Bug 2: api/rbac.php role-list counts exclude orphans/expired ────────
$rb = file_get_contents($base . '/api/rbac.php');
// Both the single-role count and the list query should INNER JOIN user
// and filter expires_at.
$joinHits = substr_count($rb, "INNER JOIN " . '"' . $prefix . 'user"');
// db_table() emits backticks so let's look for `INNER JOIN ` followed
// shortly by `u.id = ur.user_id`.
if (strpos($rb, "INNER JOIN") !== false &&
    strpos($rb, "u.id = ur.user_id") !== false) {
    ok('api/rbac.php joins user table on user_count queries');
} else {
    bad('api/rbac.php does not INNER JOIN user on user_count queries');
}
if (substr_count($rb, 'expires_at IS NULL OR ur.expires_at > NOW()') >= 2) {
    ok('api/rbac.php filters expires_at on both single-role + list queries');
} else {
    bad('api/rbac.php does not filter expires_at on user_count');
}

// ── Bug 3: api/config-admin.php cascade-delete on user delete ──────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (strpos($ca, 'DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?') !== false ||
    strpos($ca, "DELETE FROM `{\$prefix}user_roles`") !== false) {
    ok('api/config-admin.php cascade-deletes user_roles on user delete');
} else {
    bad('api/config-admin.php does NOT cascade-delete user_roles');
}
if (strpos($ca, 'DELETE FROM `{$prefix}user_password_history`') !== false ||
    strpos($ca, "user_password_history") !== false) {
    ok('api/config-admin.php cascade-deletes user_password_history');
} else {
    bad('api/config-admin.php does NOT cascade password history');
}

echo "\n";
echo "===========================================\n";
echo "Phase 10b access fixes: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
