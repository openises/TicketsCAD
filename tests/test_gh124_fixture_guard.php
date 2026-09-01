<?php
/**
 * test_gh124_fixture_guard.php — GH#124 (reported 2026-08-28).
 *
 * Proves tests/_test_fixture_guard.php — the shared shutdown-registered
 * fixture-cleanup helper built to close the structural gap that let
 * test_gh118_assign_remove_ticketid.php leak 13 incidents / 26 responders
 * / 26 assigns / 47 action rows onto a live dispatch board whenever it
 * fataled before reaching its own trailing teardown (GH#120's disabled-
 * shell_exec() trigger, already fixed separately — the STRUCTURAL gap is
 * what this file exists to prove closed).
 *
 * Section 1 (static) — the helper defines the expected public API, and
 *   test_fixture_guard_arm() guards register_shutdown_function() behind a
 *   `static` flag (the idempotency the shared-helper design requires).
 * Section 2 (functional, in-process) — track()/track_where()/
 *   track_cleanup() register correctly; a manually-invoked sweep removes
 *   exactly what was registered; a SECOND sweep call is a silent no-op
 *   (idempotent — matches "safe alongside a test's own existing trailing
 *   teardown", since that teardown running first is exactly this shape);
 *   ids <= 0 are silently ignored.
 * Section 3 (THE CRITICAL PROOF — real subprocess, real fatal) — a fresh
 *   PHP process registers one fixture via each of the three APIs
 *   (track/track_where/track_cleanup), then calls an undefined function.
 *   PHP fatals. From OUTSIDE that process, this test confirms all three
 *   fixtures were removed anyway — proving the shutdown handler survives
 *   a fatal Error, not merely an exception a try/catch could have caught.
 * Section 4 (negative control) — an otherwise-identical subprocess that
 *   creates a fixture but never registers it with the guard, then fatals
 *   the same way. The row survives. This proves the harness can actually
 *   detect the ABSENCE of protection — it is not just observing some
 *   unrelated cleanup mechanism and mistaking it for this one.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/_test_fixture_guard.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$tag    = 'gh124fg_' . getmypid() . '_' . mt_rand(1000, 9999);

echo "=== GH#124 — shared fixture-guard helper (tests/_test_fixture_guard.php) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

$cleanupResponderIds = []; // rows THIS process creates directly (not via a subprocess)

/**
 * Run a PHP file as a real subprocess and return [combinedOutput, exitCode].
 *
 * Mirrors tools/check-schema.php's own run_via_proc_open() deliberately: a
 * SHARED tmpfile() sink for both stdout and stderr, never a pipe, per this
 * project's own documented lesson that stream_set_blocking() is a no-op on
 * a proc_open pipe on Windows (CLAUDE.md, "stream_set_blocking() IS A
 * NO-OP..." / GH#28) — a pipe can deadlock a small-output subprocess just
 * as surely as a large one if nothing ever reads it in time. This test's
 * whole point is proving a fatal error still lets a shutdown handler run
 * to completion, so the runner harness itself must not be the thing that
 * hangs.
 */
function gh124_run_php(string $scriptPath): array {
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $sink = tmpfile();
    if ($sink === false) return ['(could not open a temp sink)', 127];
    $descriptors = [0 => ['pipe', 'r'], 1 => $sink, 2 => $sink];
    $pipes = [];
    $proc = @proc_open([$phpBin, $scriptPath], $descriptors, $pipes);
    if (!is_resource($proc)) { fclose($sink); return ['(failed to start subprocess)', 127]; }
    fclose($pipes[0]);
    $exit = proc_close($proc);
    rewind($sink);
    $out = rtrim((string) stream_get_contents($sink), "\r\n");
    fclose($sink);
    return [$out, $exit];
}

try {
    // ─────────────────────────────────────────────────────────────────
    echo "-- 1. Static: the helper defines the expected API and guards ".
         "register_shutdown_function() behind a static flag --\n";
    // ─────────────────────────────────────────────────────────────────
    $guardSrc = (string) file_get_contents(__DIR__ . '/_test_fixture_guard.php');

    is_true(function_exists('test_fixture_guard_track'), 'test_fixture_guard_track() is defined');
    is_true(function_exists('test_fixture_guard_track_where'), 'test_fixture_guard_track_where() is defined');
    is_true(function_exists('test_fixture_guard_track_cleanup'), 'test_fixture_guard_track_cleanup() is defined');
    is_true(function_exists('test_fixture_guard_arm'), 'test_fixture_guard_arm() is defined');
    is_true(function_exists('test_fixture_guard_sweep'), 'test_fixture_guard_sweep() is defined');

    $armPos = strpos($guardSrc, 'function test_fixture_guard_arm(');
    is_true($armPos !== false, 'located test_fixture_guard_arm() in source');
    if ($armPos !== false) {
        $armBody = substr($guardSrc, $armPos, 400);
        is_true(strpos($armBody, 'static $armed') !== false,
            'FIX: test_fixture_guard_arm() uses a static guard, not a plain module-level flag — '
            . 'safe to call repeatedly across many test files in one process');
        is_true(strpos($armBody, 'register_shutdown_function') !== false,
            'test_fixture_guard_arm() actually registers the shutdown sweep');
    }
    is_true(strpos($guardSrc, "if (!function_exists('test_fixture_guard_track'))") !== false,
        'every public function is function_exists()-guarded, like tests/_test_admin.php — '
        . 'safe to require_once from many test files without a redeclaration fatal');

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 2. Functional (in-process): track/track_where/track_cleanup + idempotent sweep --\n";
    // ─────────────────────────────────────────────────────────────────

    // 2a. ids <= 0 are silently ignored (never call db_query for a bogus id).
    test_fixture_guard_track('responder', 0);
    test_fixture_guard_track('responder', -5);
    ok('ids <= 0 registered without throwing (silently ignored)');

    // 2b. track() removes a real row on sweep.
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'GH124FGA', 'test', 1, NOW(), NOW())", [$tag . '_track']);
    $ridTrack = (int) db_insert_id();
    test_fixture_guard_track('responder', $ridTrack);

    // 2c. track_where() removes whatever matches the WHERE clause on sweep —
    // proven with a second, independent row so it's not just re-testing 2b.
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'GH124FGB', 'test', 1, NOW(), NOW())", [$tag . '_where']);
    $ridWhere = (int) db_insert_id();
    test_fixture_guard_track_where('responder', 'name = ?', [$tag . '_where']);

    // 2d. track_cleanup() runs an arbitrary closure on sweep.
    $cleanupRan = false;
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES (?, 'GH124FGC', 'test', 1, NOW(), NOW())", [$tag . '_cb']);
    $ridCb = (int) db_insert_id();
    test_fixture_guard_track_cleanup(function () use (&$cleanupRan, $prefix, $ridCb) {
        $cleanupRan = true;
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$ridCb]);
    }, 'gh124 in-process closure fixture');

    is_true(
        (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id IN (?, ?, ?)", [$ridTrack, $ridWhere, $ridCb]) === 3,
        'all three fixtures exist before the sweep runs (sanity)'
    );

    // Manually invoke the sweep (never call this from a real test — the
    // shutdown handler does it automatically; this file drives it directly
    // to prove the sweep function itself works, ahead of the real
    // shutdown-survives-a-fatal proof in Section 3).
    test_fixture_guard_sweep();

    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$ridTrack]) === 0,
        'FIX: track()-registered row was removed by the sweep');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$ridWhere]) === 0,
        'FIX: track_where()-registered row was removed by the sweep');
    is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$ridCb]) === 0,
        'FIX: track_cleanup()-registered closure ran and removed its row');
    is_true($cleanupRan === true, 'the registered closure actually executed');

    // 2e. Idempotent: calling the sweep again is a silent no-op (proves it
    // is safe alongside a test's own trailing teardown running FIRST, which
    // is exactly "someone already deleted this row" from the sweep's point
    // of view).
    ob_start();
    test_fixture_guard_sweep();
    $secondSweepOutput = ob_get_clean();
    is_true(trim($secondSweepOutput) === '',
        'FIX: a second sweep call with nothing left to clean prints nothing (idempotent, silent)',
        'got: ' . $secondSweepOutput);

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 3. THE CRITICAL PROOF: a real subprocess registers fixtures, then " .
         "fatals via an undefined function call — do they survive? --\n";
    // ─────────────────────────────────────────────────────────────────
    $posTag = $tag . '_fatal';
    $subprocessSrc = "<?php\n"
        . "require '{$base}/config.php';\n"
        . "require_once '{$base}/inc/db.php';\n"
        . "require_once '{$base}/tests/_test_fixture_guard.php';\n"
        . "\$prefix = \$GLOBALS['db_prefix'] ?? '';\n"
        // by-id fixture
        . "db_query(\"INSERT INTO `{\$prefix}responder` (name, handle, description, un_status_id, status_updated, updated) VALUES (?, 'GH124FGT', 'test', 1, NOW(), NOW())\", ['{$posTag}_byid']);\n"
        . "\$ridById = (int) db_insert_id();\n"
        . "test_fixture_guard_track('responder', \$ridById);\n"
        . "echo 'BYID_ID=' . \$ridById . \"\\n\";\n"
        // by-where fixture
        . "db_query(\"INSERT INTO `{\$prefix}responder` (name, handle, description, un_status_id, status_updated, updated) VALUES (?, 'GH124FGT', 'test', 1, NOW(), NOW())\", ['{$posTag}_bywhere']);\n"
        . "\$ridWhere = (int) db_insert_id();\n"
        . "test_fixture_guard_track_where('responder', 'name = ?', ['{$posTag}_bywhere']);\n"
        . "echo 'BYWHERE_ID=' . \$ridWhere . \"\\n\";\n"
        // by-closure fixture
        . "db_query(\"INSERT INTO `{\$prefix}responder` (name, handle, description, un_status_id, status_updated, updated) VALUES (?, 'GH124FGT', 'test', 1, NOW(), NOW())\", ['{$posTag}_bycb']);\n"
        . "\$ridCb = (int) db_insert_id();\n"
        . "test_fixture_guard_track_cleanup(function () use (\$prefix, \$ridCb) { db_query(\"DELETE FROM `{\$prefix}responder` WHERE id = ?\", [\$ridCb]); });\n"
        . "echo 'BYCB_ID=' . \$ridCb . \"\\n\";\n"
        . "flush();\n"
        // The fatal itself: an undefined function call is an uncatchable
        // (by try/catch(Throwable) OR by @) fatal Error in every PHP
        // version this project supports — exactly the GH#120 shape, and
        // the issue's own suggested reproduction.
        . "gh124_this_function_does_not_exist_and_never_will();\n"
        . "echo 'UNREACHABLE_LINE_SHOULD_NEVER_PRINT';\n";
    $posScript = sys_get_temp_dir() . '/tcad_gh124_fatal_survival_' . getmypid() . '.php';
    file_put_contents($posScript, $subprocessSrc);
    [$posOut, $posExit] = gh124_run_php($posScript);
    @unlink($posScript);

    is_true($posExit !== 0, 'the subprocess actually exited non-zero (it really did fatal)',
        "exit={$posExit}");
    is_true(strpos($posOut, 'UNREACHABLE_LINE_SHOULD_NEVER_PRINT') === false,
        'the line after the fatal call never ran (confirms this was a real fatal, not a caught error)');
    is_true(preg_match('/Fatal error/i', $posOut) === 1,
        'the subprocess output shows a genuine PHP fatal error', substr($posOut, 0, 300));
    is_true(strpos($posOut, '[fixture-guard] shutdown sweep removed') !== false,
        'the fixture guard\'s own shutdown sweep ran and reported cleaning up', substr($posOut, 0, 500));

    preg_match('/BYID_ID=(\d+)/', $posOut, $mById);
    preg_match('/BYWHERE_ID=(\d+)/', $posOut, $mWhere);
    preg_match('/BYCB_ID=(\d+)/', $posOut, $mCb);
    $posRidById = (int) ($mById[1] ?? 0);
    $posRidWhere = (int) ($mWhere[1] ?? 0);
    $posRidCb = (int) ($mCb[1] ?? 0);
    is_true($posRidById > 0 && $posRidWhere > 0 && $posRidCb > 0,
        'the subprocess reported all three fixture ids before fataling',
        "byid={$posRidById} bywhere={$posRidWhere} bycb={$posRidCb}");

    if ($posRidById > 0) {
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$posRidById]) === 0,
            'FIX: track()-registered fixture was removed DESPITE the subprocess fataling '
            . '(this is the whole point of GH#124 — a trailing try/catch could never do this)');
        // In case the assertion above is ever wrong (regression), don't
        // leave a real row behind from THIS test run either way.
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$posRidById]);
    }
    if ($posRidWhere > 0) {
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$posRidWhere]) === 0,
            'FIX: track_where()-registered fixture was removed DESPITE the subprocess fataling');
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$posRidWhere]);
    }
    if ($posRidCb > 0) {
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$posRidCb]) === 0,
            'FIX: track_cleanup()-registered closure ran DESPITE the subprocess fataling');
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$posRidCb]);
    }

    // ─────────────────────────────────────────────────────────────────
    echo "\n-- 4. NEGATIVE CONTROL: the identical fatal, but the fixture was " .
         "never registered — it must NOT be cleaned up --\n";
    // (proves this harness can detect the ABSENCE of protection, not just
    //  happen to observe some unrelated cleanup mechanism) ──
    // ─────────────────────────────────────────────────────────────────
    $negTag = $tag . '_negctrl';
    $negSrc = "<?php\n"
        . "require '{$base}/config.php';\n"
        . "require_once '{$base}/inc/db.php';\n"
        // Deliberately NOT requiring _test_fixture_guard.php at all.
        . "\$prefix = \$GLOBALS['db_prefix'] ?? '';\n"
        . "db_query(\"INSERT INTO `{\$prefix}responder` (name, handle, description, un_status_id, status_updated, updated) VALUES (?, 'GH124FGN', 'test', 1, NOW(), NOW())\", ['{$negTag}']);\n"
        . "echo 'NEG_ID=' . ((int) db_insert_id()) . \"\\n\";\n"
        . "flush();\n"
        . "gh124_this_function_does_not_exist_and_never_will();\n";
    $negScript = sys_get_temp_dir() . '/tcad_gh124_negctrl_' . getmypid() . '.php';
    file_put_contents($negScript, $negSrc);
    [$negOut, $negExit] = gh124_run_php($negScript);
    @unlink($negScript);

    is_true($negExit !== 0, 'negative control subprocess also genuinely fataled', "exit={$negExit}");
    preg_match('/NEG_ID=(\d+)/', $negOut, $mNeg);
    $negRid = (int) ($mNeg[1] ?? 0);
    is_true($negRid > 0, 'negative control subprocess reported its fixture id before fataling');

    if ($negRid > 0) {
        is_true((int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder` WHERE id = ?", [$negRid]) === 1,
            'NEGATIVE CONTROL: an UNREGISTERED fixture survives the exact same fatal — '
            . 'proves the guard, not luck or an unrelated mechanism, is what cleaned up Section 3');
        $cleanupResponderIds[] = $negRid; // this one really is on us to remove
    }

} catch (Throwable $e) {
    bad('fixture/harness path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown (defense in depth — only the deliberately-unprotected
// negative-control row should ever actually need this) ──
try {
    foreach ($cleanupResponderIds as $rid) {
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    }
    // Belt-and-suspenders sweep of anything tagged with THIS run's unique
    // $tag that somehow survived everything above.
    db_query("DELETE FROM `{$prefix}responder` WHERE name LIKE ?", [$tag . '%']);
} catch (Throwable $e) {
    echo "  Teardown warning: " . $e->getMessage() . "\n";
}

exit($fail === 0 ? 0 : 1);
