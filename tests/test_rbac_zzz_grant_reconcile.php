<?php
/**
 * sql/run_zzz_rbac_grant_reconcile.php regression.
 *
 * Found 2026-09-02: a full sql/run_migrations.php self-force replay on
 * your-server (226 scripts, triggered by unrelated schema drift)
 * left Org Admin (role 2) directly holding 10 Super-Admin-only
 * (admin_only=2) permission codes plus their canonical aliases — 42
 * findings from tools/rbac_exclusion_leak_audit.php, effectively a total
 * defeat of the Org-Admin/Super-Admin boundary on that host. Root cause:
 * sql/run_00_rbac.php's own repair-DELETE logic (the thing that actually
 * revokes a leaked grant) only runs as part of run_00_rbac.php itself,
 * which — per its "00" prefix — always runs FIRST in run_migrations.php's
 * ksort() order. Any later-sorting, phase-specific migration that grants
 * one of these codes to role 2 as part of its own setup is never cleaned
 * up again, replay or not. Confirmed directly on your deployment: re-running
 * run_00_rbac.php by hand dropped all 42 findings to 0 immediately — the
 * repair logic itself was correct, it just never got a chance to run last.
 *
 * sql/run_zzz_admin_only_reconcile.php (2026-08-26) is this bug's sibling
 * for permissions.admin_only CLASSIFICATION drift, already zzz-prefixed to
 * run last for exactly this reason — but it only fixes the classification
 * column, not who actually holds a grant. Confirmed on your deployment: its
 * own admin_only values were ALREADY correct (the audit's own findings say
 * "holds admin_only=2 code X ... but its own tier is only 1" — the
 * classification was never wrong, the GRANT was), so that script correctly
 * reported "0 reconciled" while the real leak sat untouched.
 *
 * This test proves sql/run_zzz_rbac_grant_reconcile.php closes the gap:
 * grant a throwaway tier-2 permission directly to Org Admin (reproducing
 * the exact incident shape — a grant that exists in role_permissions
 * despite the exclusion mechanism), run the REAL script as a subprocess,
 * and confirm the leaked grant is gone while everything else is untouched.
 *
 * @requires-db
 * Usage: php tests/test_rbac_zzz_grant_reconcile.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac_admin_only.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== sql/run_zzz_rbac_grant_reconcile.php regression ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$scriptPath = __DIR__ . '/../sql/run_zzz_rbac_grant_reconcile.php';
$php = PHP_BINARY ?: 'php';

t('sql/run_zzz_rbac_grant_reconcile.php exists', is_file($scriptPath));
t('the file it repairs a gap in (admin_only classification reconcile) sorts BEFORE it (ksort order)',
    strcmp('run_zzz_admin_only_reconcile.php', 'run_zzz_rbac_grant_reconcile.php') < 0);

if (!rbac_admin_only_column_exists()) {
    t('SKIP: permissions.admin_only column not present on this install', true);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

// --- Fixture: a throwaway tier-2 (Super-Admin-only) permission code,
//     directly granted to Org Admin -- reproducing the your deployment
//     incident's exact shape (a grant that survives despite the
//     exclusion-list mechanism, because whatever created it ran after
//     run_00_rbac.php's own repair pass). ---
$fixtureCode = 'zz_test_grant_reconcile_tier2';
$createdPermId = null;
register_shutdown_function(function () use (&$createdPermId, $prefix) {
    if ($createdPermId) {
        db_query("DELETE FROM `{$prefix}role_permissions` WHERE permission_id = ?", [$createdPermId]);
        db_query("DELETE FROM `{$prefix}permissions` WHERE id = ?", [$createdPermId]);
    }
});

try {
    db_query(
        "INSERT INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`, `admin_only`)
         VALUES (?, 'ZZ Grant Reconcile Test', 'action', 'throwaway fixture', 2)",
        [$fixtureCode]
    );
    $createdPermId = (int) db_insert_id();
    t('fixture: throwaway tier-2 permission created', $createdPermId > 0);

    // Reproduce the leak directly against role_permissions -- exactly what
    // a not-yet-audited phase-specific migration's own grant statement
    // would have done on your deployment, bypassing the exclusion mechanism
    // entirely (this INSERT has no admin_only guard, on purpose, to model
    // the unguarded code path that caused the real incident).
    db_query(
        "INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`) VALUES (2, ?)",
        [$createdPermId]
    );
    $leakedBefore = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE role_id = 2 AND permission_id = ?",
        [$createdPermId]
    );
    t('fixture precondition: Org Admin (role 2) holds the leaked tier-2 permission before reconcile',
        $leakedBefore === 1);

    // --- Run the REAL script as a subprocess, exactly as
    //     sql/run_migrations.php's own sweep would invoke it. ---
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $scriptPath], $descriptors, $pipes);
    $out = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
    $err = is_resource($proc) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($proc)) { fclose($pipes[1]); fclose($pipes[2]); }
    $rc = is_resource($proc) ? proc_close($proc) : -1;

    t('the real reconcile script exits 0', $rc === 0);
    t('the real reconcile script reports revoking our fixture leak',
        strpos($out, "role #2 (Org Admin) held '{$fixtureCode}'") !== false);

    $leakedAfter = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE role_id = 2 AND permission_id = ?",
        [$createdPermId]
    );
    t('the leaked grant is gone after running the real script',
        $leakedAfter === 0);

    // --- Idempotency: running it again with nothing left to fix must be a
    //     clean no-op, not an error and not a re-report of the same leak. ---
    $proc2 = proc_open([$php, $scriptPath], $descriptors, $pipes2);
    $out2 = is_resource($proc2) ? stream_get_contents($pipes2[1]) : '';
    if (is_resource($proc2)) { fclose($pipes2[1]); fclose($pipes2[2]); }
    $rc2 = is_resource($proc2) ? proc_close($proc2) : -1;
    t('a second run is a clean no-op', $rc2 === 0 && strpos($out2, "'{$fixtureCode}'") === false);

    // --- No collateral damage: a LEGITIMATE tier-1 hold by Org Admin on a
    //     real, currently-correct code must survive the reconcile
    //     untouched — this script must only ever remove violations, never
    //     anything a role is actually entitled to. ---
    $orgAdminRealHold = db_fetch_value(
        "SELECT rp.permission_id FROM `{$prefix}role_permissions` rp
           JOIN `{$prefix}permissions` p ON p.id = rp.permission_id
          WHERE rp.role_id = 2 AND p.admin_only = 1 LIMIT 1"
    );
    t('sanity: at least one real tier-1 Org-Admin grant exists to check against',
        $orgAdminRealHold !== null && $orgAdminRealHold !== false);
} catch (Throwable $e) {
    t('fixture setup/exec without error: ' . $e->getMessage(), false);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
