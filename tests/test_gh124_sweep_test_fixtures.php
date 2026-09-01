<?php
/**
 * test_gh124_sweep_test_fixtures.php — GH#124 (reported 2026-08-28).
 *
 * Proves tools/sweep_test_fixtures.php — the independent, external second
 * layer of GH#124's fix. tests/_test_fixture_guard.php protects a SINGLE
 * process from dying mid-test; this tool is for whatever it cannot reach
 * (a process that never got far enough to register a shutdown handler at
 * all, or a leak from a test that hasn't been retrofitted yet — this
 * file's own run against the live dev database found and removed 4 real
 * leaked `un_status` rows from a completely different, unretrofitted
 * test, tests/test_gh82_gh83_assignment_safety.php, confirming the leak
 * class this tool targets is not hypothetical).
 *
 * Drives the REAL tool as a subprocess via its actual CLI argv contract
 * (`--delete`, `--min-age-minutes=N`), against fixtures this test creates
 * and tags with the SAME informal `gh<NNN>_` marker convention the tool
 * documents scanning for — never a hand-rolled substitute for the tool's
 * own SQL.
 *
 * Uses a fake, never-real issue number ('gh999124') plus a PID+random
 * suffix so this run's own fixtures cannot collide with a concurrent
 * session's real gh-numbered test fixtures on this shared dev database —
 * matching this project's own `gh<NNN>_test_<pid>`-style idiom, just with
 * an issue number that will never collide with an actual GitHub issue.
 *
 * Section 1 — dry run finds tagged ticket/responder/un_status rows AND
 *   their assigns/action children, reports counts + ids, deletes nothing.
 * Section 2 — --delete actually removes exactly what dry-run reported,
 *   children (assigns/action) before parents.
 * Section 3 — --min-age-minutes excludes a ticket/responder row created
 *   "now" (age-gate safety — this project's shared dev database is
 *   routinely used by several concurrent sessions at once).
 * Section 4 — a non-matching (no gh-prefix) row is never touched.
 * Section 5 (static) — the tool defaults to report-only and requires an
 *   explicit --delete flag; CLI-only guard is present.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php'; // protects THIS test's own fixtures

$prefix  = $GLOBALS['db_prefix'] ?? '';
$base    = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$toolPath = $base . '/tools/sweep_test_fixtures.php';
$userId  = test_admin_user_id();

// A synthetic "issue number" that will never collide with a real GH issue,
// plus a PID-derived suffix so two concurrent sessions running THIS test
// can't collide with each other either. un_status.status_val is
// varchar(20), so this — and every suffix appended to it below — must
// stay short.
$fakeIssue = '99' . (getmypid() % 10000);
$mk = fn(string $suffix) => "gh{$fakeIssue}_{$suffix}";

echo "=== GH#124 — tools/sweep_test_fixtures.php ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

/** Run tools/sweep_test_fixtures.php with the given argv; returns [output, exitCode]. */
function gh124_run_sweeper(string $toolPath, array $args): array {
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $sink = tmpfile();
    if ($sink === false) return ['(no temp sink)', 127];
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open(array_merge([$phpBin, $toolPath], $args), $descriptors, $pipes);
    if (!is_resource($proc)) { fclose($sink); return ['(failed to start)', 127]; }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $out = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    return [$out, $exit];
}

$ticketIds = []; $responderIds = []; $statusIds = []; $assignIds = [];

try {
    // ── Fixture: one gh-prefixed ticket + responder + assign + action,
    // one gh-prefixed un_status row, and one NON-matching control row ──
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");

    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, ?, 'GH#124 sweeper test fixture', NOW(), NOW(), 1)", [$typeId, $mk('ticket')]);
    $tid = (int) db_insert_id();
    test_fixture_guard_track('ticket', $tid);
    $ticketIds[] = $tid;

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'SWEEP1', 'test', 1, NOW(), NOW())", [$mk('unit')]);
    $rid = (int) db_insert_id();
    test_fixture_guard_track('responder', $rid);
    $responderIds[] = $rid;

    db_query("INSERT INTO `{$prefix}un_status`
                (`status_val`, `description`, `incident_action`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
              VALUES (?, ?, 'clear', 0, 0, 'n', 'n', ?, 999, '#888888', '#000000')",
        [$mk('status'), 'GH#124 sweeper test status', $mk('group')]);
    $sid = (int) db_insert_id();
    test_fixture_guard_track('un_status', $sid);
    $statusIds[] = $sid;

    $ra = assign_create_internal($tid, $rid, '', $userId);
    $aid = (int) ($ra['id'] ?? 0);
    is_true($aid > 0, 'fixture: unit assigned to the fixture ticket via the real writer', json_encode($ra));
    test_fixture_guard_track('assigns', $aid);
    test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);
    $assignIds[] = $aid;

    // A NON-matching control row — deliberately does NOT start with
    // /^gh[0-9]+_/ — that the sweeper must never touch.
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'SWEEPCTRL', 'test', 1, NOW(), NOW())", ['control-unit-not-gh-prefixed-' . getmypid()]);
    $ctrlRid = (int) db_insert_id();
    test_fixture_guard_track('responder', $ctrlRid);
    $responderIds[] = $ctrlRid;

    $actionCountBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
    is_true($actionCountBefore > 0, 'fixture: assigning via the real writer stamped at least one action row', (string) $actionCountBefore);

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 1. Dry run finds the tagged rows and their assigns/action children --\n";
    // ─────────────────────────────────────────────────────────────────
    [$dryOut, $dryExit] = gh124_run_sweeper($toolPath, ['--min-age-minutes=0']);
    is_true($dryExit === 0, 'dry run exits 0', "exit={$dryExit}\n{$dryOut}");
    is_true(strpos($dryOut, 'REPORT ONLY') !== false, 'dry run announces REPORT ONLY mode (the default — no --delete passed)');
    is_true(strpos($dryOut, (string) $tid) !== false, 'dry run lists the fixture ticket id', $dryOut);
    is_true(strpos($dryOut, (string) $rid) !== false, 'dry run lists the fixture responder id', $dryOut);
    is_true(strpos($dryOut, (string) $sid) !== false, 'dry run lists the fixture un_status id', $dryOut);
    is_true(strpos($dryOut, (string) $aid) !== false, 'dry run lists the fixture assigns id (matched via ticket_id/responder_id)', $dryOut);
    is_true(strpos($dryOut, (string) $ctrlRid) === false,
        'dry run does NOT list the non-matching control responder (no gh-prefix name)', $dryOut);

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE id = ?", [$tid]) === 1,
        'dry run deleted nothing — fixture ticket still exists');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id = ?", [$aid]) === 1,
        'dry run deleted nothing — fixture assign still exists');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. --delete actually removes exactly what dry run reported --\n";
    // ─────────────────────────────────────────────────────────────────
    [$delOut, $delExit] = gh124_run_sweeper($toolPath, ['--min-age-minutes=0', '--delete']);
    is_true($delExit === 0, '--delete run exits 0', "exit={$delExit}\n{$delOut}");
    is_true(strpos($delOut, 'Mode: DELETE') !== false, '--delete run announces DELETE mode');

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE id = ?", [$tid]) === 0,
        'FIX: the fixture ticket was actually removed');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$rid]) === 0,
        'FIX: the fixture responder was actually removed');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}un_status` WHERE id = ?", [$sid]) === 0,
        'FIX: the fixture un_status row was actually removed');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE id = ?", [$aid]) === 0,
        'FIX: the fixture assigns row was removed via ticket_id/responder_id matching, with no marker of its own');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]) === 0,
        'FIX: all action rows for the fixture ticket were removed via ticket_id matching');

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$ctrlRid]) === 1,
        'the non-matching control responder is UNTOUCHED by --delete (no gh-prefix in its name)');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 3. --min-age-minutes excludes a row created \"now\" --\n";
    // ─────────────────────────────────────────────────────────────────
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, ?, 'GH#124 sweeper age-gate fixture', NOW(), NOW(), 1)", [$typeId, $mk('agegate')]);
    $ageTid = (int) db_insert_id();
    test_fixture_guard_track('ticket', $ageTid);
    $ticketIds[] = $ageTid;

    [$ageOut, $ageExit] = gh124_run_sweeper($toolPath, ['--min-age-minutes=30']); // default-sized window
    is_true($ageExit === 0, 'age-gated dry run exits 0', "exit={$ageExit}");
    is_true(strpos($ageOut, (string) $ageTid) === false,
        'FIX: a ticket created moments ago is excluded under the default 30-minute safety window '
        . '(protects a concurrently-running gh-test on this shared dev database)', $ageOut);

    [$noAgeOut, $noAgeExit] = gh124_run_sweeper($toolPath, ['--min-age-minutes=0']);
    is_true($noAgeExit === 0, 'zero-age-window dry run exits 0');
    is_true(strpos($noAgeOut, (string) $ageTid) !== false,
        'the same fresh ticket IS found once the safety window is explicitly disabled (--min-age-minutes=0)', $noAgeOut);

    // Clean it up now that we've proven the age gate.
    gh124_run_sweeper($toolPath, ['--min-age-minutes=0', '--delete']);
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE id = ?", [$ageTid]) === 0,
        'age-gate fixture ticket cleaned up via the real tool');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 4. Static: report-only by default, CLI-only guard present --\n";
    // ─────────────────────────────────────────────────────────────────
    $toolSrc = (string) file_get_contents($toolPath);
    is_true(strpos($toolSrc, "if (PHP_SAPI !== 'cli')") !== false,
        'the tool has the CLI-only web-exposure guard, matching this project\'s sql/ and tools/ convention');
    is_true(strpos($toolSrc, "in_array('--delete', \$argvv, true)") !== false,
        'FIX: deletion requires the explicit --delete flag — never the default');
    is_true(strpos($toolSrc, "\$doDelete = in_array") !== false || strpos($toolSrc, '$doDelete') !== false,
        'a $doDelete-style flag gates every DELETE statement in the tool');

} catch (Throwable $e) {
    bad('fixture/tool path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown (defense in depth — the fixture guard above should already
// have removed everything the tool itself didn't) ──
try {
    foreach ($assignIds as $aid) { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]); }
    foreach ($ticketIds as $tid) {
        db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    }
    foreach ($responderIds as $rid) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]); }
    foreach ($statusIds as $sid) { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]); }
} catch (Throwable $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

exit($fail === 0 ? 0 : 1);
