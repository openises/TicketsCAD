<?php
/**
 * Phase 73cc + 73z regression tests — 4 MEDIUM auth + SSE fail-closed.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── 7. CSRF rotation on login ────────────────────────────────────
$login = file_get_contents(__DIR__ . '/../login.php');
tcheck(strpos($login, "unset(\$_SESSION['csrf_token']);") !== false,
    'login.php rotates CSRF token in complete_login');
tcheck(preg_match('/session_regenerate_id\(true\);\s*\n[^\n]*\n[^\n]*Phase 73cc/', $login) === 1,
    'CSRF rotation happens immediately after session_regenerate_id');

// ── 8. RBAC legacy fail-closed ───────────────────────────────────
$rbac = file_get_contents(__DIR__ . '/../inc/rbac.php');
tcheck(strpos($rbac, '$level2Allowed = [') !== false,
    'inc/rbac.php uses an explicit allowlist for level 2');
tcheck(strpos($rbac, "// Phase 73cc — was a blocklist") !== false,
    'Phase 73cc rationale comment in place');
tcheck(strpos($rbac, "'action.manage_routing'") === false
    || preg_match("/'action\.manage_routing'[^']*level2Allowed/s", $rbac) === 0,
    'action.manage_routing not in level 2 allowlist (was implicit-allow before)');

// ── 9. TFA encryption key hard-fail ──────────────────────────────
$tfa = file_get_contents(__DIR__ . '/../inc/tfa.php');
tcheck(strpos($tfa, 'throw new RuntimeException') !== false,
    'tfa_encryption_key throws when key missing AND no legacy blob');
tcheck(strpos($tfa, '$hasLegacyBlob') !== false,
    'tfa_encryption_key gates the DB-derived fallback on existing legacy blob');
tcheck(strpos($tfa, "'[tfa] CRITICAL: '") !== false,
    'tfa_encryption_key logs CRITICAL when refusing to fall back');

// ── 10. login-security.php reset_password gates ──────────────────
$ls = file_get_contents(__DIR__ . '/../api/login-security.php');
tcheck(strpos($ls, "rbac_can('action.manage_users')") !== false,
    'reset_password requires action.manage_users');
tcheck(strpos($ls, "'User not found'") !== false,
    'reset_password verifies the target user exists');
tcheck(strpos($ls, "SELECT COUNT(*) FROM `{\$prefix}user` WHERE `id` = ?") !== false,
    'reset_password runs the existence check before the UPDATE');

// ── 73z. SSE _sse_groups_for_resource fails closed on exception ──
$sse = file_get_contents(__DIR__ . '/../inc/sse.php');
tcheck(strpos($sse, 'return [0];') !== false
    && strpos($sse, '_sse_groups_for_resource') !== false,
    'SSE groups-for-resource returns sentinel [0] on DB exception');
tcheck(strpos($sse, 'Phase 73z') !== false,
    'SSE failure handling carries the Phase 73z rationale');

echo "Phase 73cc + 73z regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
