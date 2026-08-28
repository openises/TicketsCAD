<?php
/**
 * Found while investigating a GH#115 CI failure (2026-08-26) -- a
 * SEPARATE, pre-existing, unrelated bug in run_00_rbac.php, not caused
 * by GH#115's own change but caught by the same "does this actually
 * work on a genuinely fresh install" scrutiny.
 *
 * sql/run_00_rbac.php's admin_only-seeding UPDATE statements ran against
 * a column that didn't exist yet on a fresh install: sql/run_migrations.php
 * runs run_*.php scripts in ksort() (alphabetical filename) order, and
 * "run_00_rbac.php" sorts BEFORE "run_rbac_v2.php" -- but it's
 * run_rbac_v2.php that adds the admin_only column in the first place.
 * The resulting "Unknown column 'admin_only'" was silently swallowed by
 * a catch block (echoing only a [WARN], never failing the script), and
 * because sql/run_migrations.php tracks run_00_rbac.php as already-applied
 * after that first run, it was never retried once run_rbac_v2.php later
 * created the column in the SAME pass -- so on every genuinely fresh
 * install, console.design/action.intercom_unlock/action.manage_matrix
 * (and everything else in the tier-1/tier-2 lists) were permanently
 * left at the column's own DEFAULT 0, a real admin-only privilege
 * classification gap. Caught live by
 * tests/test_rbac_exclusion_leak_audit.php's classification-drift check
 * on CI's fresh database, never on an already-migrated one (this dev
 * database's values were already correct, just not by this
 * deterministic path -- reproduced identically on a CI rerun, ruling
 * out a flake).
 *
 * Fixed the same way ensure_org_id_column() already handles this class
 * of ordering problem: run_00_rbac.php now creates the admin_only column
 * itself if it's still missing, so it no longer depends on running
 * after run_rbac_v2.php to do its own job. run_rbac_v2.php's own later
 * ADD COLUMN step already checks existence first (rrbv2_col_exists()),
 * so this doesn't introduce a duplicate-column conflict.
 *
 * @requires-db
 * Usage: php tests/test_run00_rbac_admin_only_self_sufficient.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== run_00_rbac.php creates admin_only itself, independent of run_rbac_v2.php's own ordering ===\n\n";

$src = file_get_contents(__DIR__ . '/../sql/run_00_rbac.php');

// ── 1. Structural: the column-existence check + guarded ALTER appear
// BEFORE the UPDATE statements that depend on the column existing. ─────
$updatePos = strpos($src, "SET admin_only = 2 WHERE code IN");
$guardPos  = strpos($src, "information_schema.COLUMNS");
if ($guardPos !== false && $updatePos !== false && $guardPos < $updatePos) {
    ok('the admin_only existence check appears in source BEFORE the seeding UPDATE statements');
} else {
    bad('the existence check is missing or in the wrong position relative to the UPDATE statements');
}
(strpos($src, "ADD COLUMN `admin_only`") !== false)
    ? ok('run_00_rbac.php can create the admin_only column itself if missing')
    : bad('run_00_rbac.php does not guard-create the admin_only column', 'GH#115-adjacent CI regression — a fresh install would silently fail to classify any admin-only permission again');

// ── 2. Functional: run the REAL script as a subprocess against the real
// dev database and confirm it reports success (not the silent [WARN]
// this bug produced), then confirm the actual values are set correctly
// for the three codes CI caught. ────────────────────────────────────────
$php = PHP_BINARY ?: 'php';
$descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open([$php, __DIR__ . '/../sql/run_00_rbac.php'], $descriptorSpec, $pipes);
$out = ''; $exit = -1;
if (is_resource($proc)) {
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
}
if ($exit === 0) {
    ok('the real script exits 0 against the real dev database');
} else {
    bad('the real script did not exit 0', "exit={$exit}");
}
if (strpos($out, '[WARN] admin_only classification') === false) {
    ok('no [WARN] admin_only classification failure — the exact symptom of the bug, gone');
} else {
    bad('the [WARN] admin_only classification failure still appears', trim($out));
}

$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $codes = ['console.design', 'action.intercom_unlock', 'action.manage_matrix'];
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $rows = db_fetch_all(
        "SELECT code, admin_only FROM `{$prefix}permissions` WHERE code IN ($ph)",
        $codes
    );
    $byCode = [];
    foreach ($rows as $r) { $byCode[$r['code']] = (int) $r['admin_only']; }
    $allTier1 = true;
    foreach ($codes as $c) {
        if (($byCode[$c] ?? 0) < 1) { $allTier1 = false; }
    }
    if ($allTier1 && count($byCode) === count($codes)) {
        ok('console.design, action.intercom_unlock, and action.manage_matrix all have admin_only >= 1 — the exact three codes the live CI failure flagged at admin_only=0');
    } else {
        bad('one or more of the three CI-flagged codes is still not tier-1+', var_export($byCode, true));
    }
} catch (Throwable $e) {
    bad('permissions lookup threw', $e->getMessage());
}

// ── 3. The real gate this whole thing feeds: the exclusion-leak audit
// itself reports zero classification drift against the live database. ──
try {
    $auditOut = shell_exec('"' . $php . '" ' . escapeshellarg(__DIR__ . '/../tools/rbac_exclusion_leak_audit.php') . ' 2>&1');
    if ($auditOut !== null && strpos($auditOut, 'classification-drift check: 0 drift') !== false) {
        ok('tools/rbac_exclusion_leak_audit.php reports 0 classification drifts against the live database');
    } else {
        bad('the exclusion-leak audit still reports drift', trim((string) $auditOut));
    }
} catch (Throwable $e) {
    bad('running the exclusion-leak audit threw', $e->getMessage());
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
