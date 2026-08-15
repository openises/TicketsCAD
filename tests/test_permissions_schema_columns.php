<?php
/**
 * permissions table schema-ordering regression (2026-08-15).
 *
 * ORIGIN. tools/rbac_permission_audit.php's first CI run (a genuinely
 * fresh database, unlike any long-lived local dev DB) found six
 * permission codes missing that a local check had reported as present:
 * action.manage_par, action.manage_mesh_bridges, action.kill_pending_
 * message, action.recall_routed_message, action.set_incident_security,
 * action.manage_security_labels.
 *
 * ROOT CAUSE. sql/rbac.sql's original CREATE TABLE `permissions` only
 * had id/code/name/category/description. resource, verb, and
 * deprecated_alias_of were added LATER by sql/run_rbac_v2.php (an
 * idempotency-guarded ADD COLUMN). sql/run_migrations.php discovers and
 * runs every sql/run_*.php in lexicographic order, and five phase
 * scripts that seed a permission USING those three columns --
 * run_04_phase35_mesh_bridge.php, run_phase16a_par_schema.php,
 * run_phase18a_security_labels.php, run_phase80d_time_entries.php,
 * run_phase138_public_board.php -- all sort BEFORE run_rbac_v2.php
 * ("run_04"/"run_phase*" < "run_rbac_v2" lexicographically). On a
 * genuinely fresh install, each of those five ran its INSERT against a
 * `permissions` table that didn't have resource/verb/deprecated_alias_of
 * yet, threw "Unknown column", and was silently swallowed by the
 * script's own outer try/catch (a [WARN], not a halt) -- so the
 * migration "succeeded" while the permission row was never created.
 * Any long-lived dev database that had already run run_rbac_v2.php
 * before those five scripts existed never showed the symptom, which is
 * exactly why this went undetected until a real fresh-install run.
 *
 * FIX. The three columns are now part of the table from its first
 * CREATE in sql/rbac.sql, so no migration's run order can ever race
 * them again -- this guards the whole CLASS of the bug, not just the
 * five scripts that happened to hit it today. run_rbac_v2.php's own
 * ADD COLUMN step is untouched and remains the upgrade path for any
 * install that already ran the base schema before this fix landed.
 *
 * This test is intentionally schema-only (reads the .sql text, not a
 * live database) so it catches the bug class on a plain PHP CLI with no
 * database available, and so a future contributor who reverts the base
 * schema without noticing the ordering dependency gets caught here
 * rather than by the next fresh-install CI run.
 */

$root = dirname(__DIR__);
$rbacSql = (string) file_get_contents($root . '/sql/rbac.sql');

$pass = 0; $fail = 0;
function tps(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== permissions table schema-ordering regression ===\n\n";

if (!preg_match('/CREATE TABLE IF NOT EXISTS `permissions` \((.*?)\) ENGINE=InnoDB/s', $rbacSql, $m)) {
    echo "[FAIL] could not isolate the permissions CREATE TABLE block from sql/rbac.sql\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$createBlock = $m[1];

foreach (['resource', 'verb', 'deprecated_alias_of'] as $col) {
    tps("permissions table's original CREATE includes \`$col\` (not added by a later migration)",
        (bool) preg_match('/`' . preg_quote($col, '/') . '`/', $createBlock));
}

// Every phase script known to have hit this exact bug must still be able
// to insert using these columns -- confirms the fix is the one those
// scripts actually need, not just a schema change that happens to look
// plausible.
$affectedScripts = [
    'run_04_phase35_mesh_bridge.php',
    'run_phase16a_par_schema.php',
    'run_phase18a_security_labels.php',
    'run_phase80d_time_entries.php',
    'run_phase138_public_board.php',
];
foreach ($affectedScripts as $script) {
    $path = $root . '/sql/' . $script;
    tps("$script exists and still references the resource/verb columns it needs",
        is_file($path) && (bool) preg_match('/\bresource\b.*\bverb\b|\bverb\b.*\bresource\b/s',
            (string) file_get_contents($path)));
}

// Every one of those scripts must sort BEFORE run_rbac_v2.php -- if this
// ever flips (a rename, a new file), the columns must already be safe in
// the base schema regardless, but this documents WHY the bug existed so
// a future reader isn't left re-deriving it.
$allMigrations = glob($root . '/sql/run_*.php') ?: [];
$names = array_map('basename', $allMigrations);
sort($names);
$rbacV2Index = array_search('run_rbac_v2.php', $names, true);
tps('run_rbac_v2.php exists in sql/', $rbacV2Index !== false);
if ($rbacV2Index !== false) {
    foreach ($affectedScripts as $script) {
        $idx = array_search($script, $names, true);
        tps("$script sorts before run_rbac_v2.php (confirms it needed this fix)",
            $idx !== false && $idx < $rbacV2Index);
    }
}

// run_rbac_v2.php's own ADD COLUMN step must remain idempotency-guarded
// (existing installs that ran the OLD base schema still need it).
$rbacV2Src = (string) file_get_contents($root . '/sql/run_rbac_v2.php');
tps('run_rbac_v2.php still guards its resource/verb/deprecated_alias_of ADD COLUMN with an existence check',
    strpos($rbacV2Src, "rrbv2_col_exists('permissions', \$col)") !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
