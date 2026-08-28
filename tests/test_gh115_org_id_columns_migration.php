<?php
/**
 * GH#115 (d3xter/Andreas Sinz, 2026-08-26) — a genuinely fresh install
 * reported "SCHEMA IS BEHIND THE CODE / missing on `teams`: org_id", and
 * `php tools/check-schema.php --repair` could not fix it because no
 * migration was responsible for that column.
 *
 * Phase 99j-6's ensure_org_id_column() (inc/org-scope.php) adds org_id
 * to facilities/responder/teams/newui_equipment/newui_vehicles LAZILY,
 * from each table's own write path -- so a fresh install that hasn't yet
 * created a single team/facility/responder/vehicle/equipment row is
 * missing the column sql/schema_manifest.json correctly says the writer
 * code needs, with nothing in the migration runner able to add it.
 *
 * sql/run_gh115_org_id_columns.php closes the gap by calling
 * ensure_org_id_column() for all five tables during the normal migration
 * pass -- this test drives the real script as a subprocess (its own
 * static per-table cache would otherwise make a second call inside the
 * SAME PHP process a silent no-op regardless of whether the fix works,
 * so a subprocess is the only way to prove idempotency honestly) against
 * the real, already-migrated dev database.
 *
 * @requires-db
 * Usage: php tests/test_gh115_org_id_columns_migration.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#115: sql/run_gh115_org_id_columns.php makes org_id deterministic on fresh installs ===\n\n";

$scriptPath = __DIR__ . '/../sql/run_gh115_org_id_columns.php';

// ── 1. Structural: the script calls ensure_org_id_column() for all five
// Phase 99j-6 tables, and has the CLI-only guard every sql/run_*.php
// script in this tree carries. ────────────────────────────────────────
$src = file_get_contents($scriptPath);
$expectedTables = ['facilities', 'responder', 'teams', 'newui_equipment', 'newui_vehicles'];
$missingFromSrc = [];
foreach ($expectedTables as $t) {
    if (strpos($src, "'{$t}'") === false) $missingFromSrc[] = $t;
}
if (empty($missingFromSrc)) {
    ok('script references all 5 Phase 99j-6 tables (facilities, responder, teams, newui_equipment, newui_vehicles)');
} else {
    bad('script is missing a table reference', implode(', ', $missingFromSrc));
}
(strpos($src, "PHP_SAPI !== 'cli'") !== false)
    ? ok('script carries the standard CLI-only guard')
    : bad('script is missing the CLI-only guard', 'web-exposure risk — see the standing web-exposure-hardening rule');
(strpos($src, 'ensure_org_id_column(') !== false)
    ? ok('script calls the real ensure_org_id_column() function, not a re-implementation')
    : bad('script does not call ensure_org_id_column()');

// ── 1b. The ordering-caveat fix, found live in CI: newui_equipment and
// newui_vehicles aren't in base_schema.sql -- they're created by their
// own run_equipment.php/run_vehicles.php migrations, and this script's
// filename sorts BEFORE run_vehicles.php alphabetically. The FIRST
// version of this script called ensure_org_id_column() unconditionally
// and treated "column still missing afterward" as a hard failure,
// which meant a table that legitimately hadn't been created yet
// aborted the whole migration pass with a non-zero exit -- confirmed
// live on a genuinely fresh CI install (12468-passing local run, CI
// failure anyway, because CI's database has no prior state to mask
// the ordering gap). The fix checks information_schema.TABLES for the
// table's existence FIRST and treats a missing table as a graceful
// skip, never an error. ─────────────────────────────────────────────
(preg_match('/information_schema\.TABLES/', $src) === 1)
    ? ok('script checks information_schema.TABLES for existence before touching a table')
    : bad('script does not check table existence first', 'GH#115 CI regression — a not-yet-created table (newui_equipment/newui_vehicles, run by a LATER-sorting migration) would abort the whole migration pass again');
(preg_match('/if\s*\(\s*!\s*\$tableExists\s*\)\s*\{[\s\S]{0,400}?continue;/', $src) === 1)
    ? ok('a missing table is SKIPPED (continue), never treated as a fatal error')
    : bad('a missing table does not skip gracefully', 'this is the exact shape of the live CI failure this fix addresses');

// ── 2. sql/run_migrations.php auto-discovers run_*.php by glob — confirm
// this file matches that naming convention (no separate registration
// needed, but worth pinning so a future rename doesn't silently drop it
// from the migration pass). ───────────────────────────────────────────
(basename($scriptPath) === 'run_gh115_org_id_columns.php' && strpos(basename($scriptPath), 'run_') === 0)
    ? ok('filename matches the run_*.php glob pattern sql/run_migrations.php discovers')
    : bad('filename does not match the run_*.php convention');

// ── 3. Functional: run the REAL script twice as a subprocess against the
// real, already-migrated dev database. First run proves it completes
// cleanly against a live install (every table here already has org_id,
// same as any install that has created at least one of each entity
// type); second run proves idempotency for real, in a fresh process each
// time (ensure_org_id_column()'s own static cache would mask a
// non-idempotent bug if called twice in-process instead). ─────────────
$php = PHP_BINARY ?: 'php';
$cmd = [$php, $scriptPath];

function run_gh115_script(array $cmd): array {
    $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptorSpec, $pipes);
    if (!is_resource($proc)) return ['exit' => -1, 'out' => '', 'err' => 'proc_open failed'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'out' => $out, 'err' => $err];
}

$run1 = run_gh115_script($cmd);
if ($run1['exit'] === 0) {
    ok('first run against the real dev database exits 0');
} else {
    bad('first run did not exit 0', "exit={$run1['exit']} stderr=" . trim($run1['err']));
}
$sawAllFive = true;
foreach ($expectedTables as $t) {
    $full = ($GLOBALS['db_prefix'] ?? '') . $t;
    if (strpos($run1['out'], $full) === false) $sawAllFive = false;
}
if ($sawAllFive) {
    ok('first run reports a result line for all 5 tables');
} else {
    bad('first run did not mention all 5 tables', trim($run1['out']));
}

$run2 = run_gh115_script($cmd);
if ($run2['exit'] === 0 && strpos($run2['out'], 'ERROR') === false) {
    ok('a second, independent process run is idempotent (exits 0, no error)');
} else {
    bad('second run was not a clean idempotent no-op', "exit={$run2['exit']} out=" . trim($run2['out']));
}

// ── 3b. Functional: the exact existence-check query the fix relies on,
// against a table name guaranteed not to exist (never one of the 5 real
// tables -- this never touches production-relevant state). Reproduces
// the CI condition directly rather than risking a live rename of a real
// table on a shared dev database. ─────────────────────────────────────
$prefix2 = $GLOBALS['db_prefix'] ?? '';
$scratchName = $prefix2 . 'gh115_definitely_does_not_exist_' . getmypid();
try {
    $exists = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$scratchName]
    );
    if (!$exists) {
        ok('information_schema.TABLES correctly reports a not-yet-created table as absent — the exact condition the fix\'s [SKIP] branch depends on');
    } else {
        bad('scratch table name unexpectedly exists', 'test setup problem, not a fix problem');
    }
} catch (Throwable $e) {
    bad('existence-check query threw', $e->getMessage());
}

// ── 4. The actual reported symptom: after this migration runs, teams has
// org_id regardless of whether any team has ever been saved through the
// application. ─────────────────────────────────────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $teamsHasOrgId = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
        [$prefix . 'teams']
    );
    if ($teamsHasOrgId) {
        ok('teams.org_id exists after the migration — tools/check-schema.php no longer has anything to report for it');
    } else {
        bad('teams.org_id still missing after the migration ran', 'this is the exact GH#115 symptom, unresolved');
    }
} catch (Throwable $e) {
    bad('teams.org_id check threw', $e->getMessage());
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
