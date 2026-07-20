<?php
/**
 * Smoke test for inc/rbac_grant.php — runs in-process, no HTTP.
 * Throwaway tool; not part of the regression suite.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac_grant.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Testing rbac_grant.php in-process...\n";

$victim = (int) (db_fetch_value("SELECT id FROM `{$prefix}user` WHERE level > 0 ORDER BY id LIMIT 1") ?: 0);
if (!$victim) { echo "No non-admin user; skipping.\n"; exit; }
echo "Test user: #$victim\n";

$before = rbac_user_grants($victim, true);
echo "Existing grants for user #$victim: " . count($before) . "\n";

// 1. Grant Read-Only role (id=5) with 1-hour expiry; CLI bypass (granter=0).
try {
    $gid = rbac_grant_role($victim, 5, 'global', null,
        date('Y-m-d H:i:s', time() + 3600),
        'CLI smoke-test', 0);
    echo "  grant_role ok: grant_id=$gid\n";
} catch (Throwable $e) {
    echo "  grant_role FAILED: " . $e->getMessage() . "\n"; exit(1);
}

$after = rbac_user_grants($victim, false);
$found = null;
foreach ($after as $g) { if ((int) $g['grant_id'] === $gid) { $found = $g; break; } }
echo "  read-back: " . ($found ? 'OK' : 'FAIL') . "\n";
if ($found) echo "    expires_at=" . $found['expires_at'] . " scope=" . $found['scope_kind'] . "\n";

try { rbac_revoke_grant($gid, 'CLI smoke cleanup', 0); echo "  revoke_grant ok\n"; }
catch (Throwable $e) { echo "  revoke_grant FAILED: " . $e->getMessage() . "\n"; exit(1); }

// Validation: past-expiry should throw.
try {
    rbac_grant_role($victim, 5, 'global', null, '2020-01-01 00:00:00', 'past', 0);
    echo "  past-expiry: FAIL (no exception)\n";
} catch (RuntimeException $e) {
    echo "  past-expiry: OK (" . $e->getMessage() . ")\n";
}

// Validation: invalid scope kind should throw.
try {
    rbac_grant_role($victim, 5, 'whatever', null, null, 'bad', 0);
    echo "  invalid scope: FAIL (no exception)\n";
} catch (RuntimeException $e) {
    echo "  invalid scope: OK (" . $e->getMessage() . ")\n";
}

// Validation: org scope without scope_id should throw.
try {
    rbac_grant_role($victim, 5, 'org', null, null, 'bad', 0);
    echo "  org without scope_id: FAIL\n";
} catch (RuntimeException $e) {
    echo "  org without scope_id: OK (" . $e->getMessage() . ")\n";
}

// Privilege escalation: non-super granter trying to grant role they don't fully hold.
$nonSuper = (int) (db_fetch_value("SELECT id FROM `{$prefix}user` WHERE level >= 2 ORDER BY id LIMIT 1") ?: 0);
if ($nonSuper) {
    $can = rbac_can_grant($nonSuper, 1, 'global', null);  // try to grant Super Admin
    echo "  rbac_can_grant(non-super granting Super): " . ($can ? "FAIL (true)" : "OK (false)") . "\n";
} else {
    echo "  rbac_can_grant: skipped (no non-super user)\n";
}

$expired = rbac_expire_due_grants();
echo "  expire_due_grants: swept=$expired\n";

echo "\nAll checks complete.\n";
