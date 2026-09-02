<?php
/**
 * tools/deploy.sh's migration step used to pipe run_migrations.php straight
 * into `grep ... | head -12`. Once `head` printed its 12th matching line and
 * exited, the closed pipe sent SIGPIPE back through `grep` to the `php`
 * process itself — silently KILLING the migration runner mid-run on any
 * deploy with more than 12 matching status lines (every already-applied
 * script re-verifies and prints its own line too, so this was easy to hit
 * long before 12 genuinely NEW migrations existed).
 *
 * Found 2026-09-02: after `bash tools/deploy.sh` printed
 * "-- training deployed: 36f5a10 --" for a real deploy, a direct
 * `php sql/run_migrations.php --list` on that same host still showed
 * run_phase86_major_events.php as [PENDING] — the deploy's own migration
 * step had died before reaching it alphabetically, while the script's final
 * output line still implied success. This is the EXACT failure class
 * deploy.sh's own header comment says it was built to prevent (a migration
 * silently not reaching a host).
 *
 * This test does not touch any live host or the real sql/run_migrations.php
 * — it reproduces the SHELL PIPELINE SHAPE with a small stand-in "migration
 * runner" script and proves, with a real bash subprocess, that the OLD
 * pattern loses work and the NEW (fixed, captured-first) pattern does not.
 * A structural grep of deploy.sh's source is checked too, but the behavioral
 * proof is the part that actually demonstrates the bug and the fix.
 *
 * Usage: php tests/test_deploy_migration_sigpipe.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== deploy.sh migration-step SIGPIPE regression ===\n\n";

$deploySrc = @file_get_contents($base . '/tools/deploy.sh');
if ($deploySrc === false) {
    // tools/deploy.sh is DELIBERATELY excluded from the public release
    // snapshot (tools/release-snapshot.sh's own exclusion list) -- it
    // names internal host SSH aliases/IPs that have no business being
    // public. On the public tree this file legitimately does not exist;
    // that is correct, not a bug, so this is a clean skip, not a failure.
    t('SKIP: tools/deploy.sh not present on this tree (deliberately excluded from the public release snapshot)', true);
    echo "\n=== $passed passed, $failed failed ===\n";
    exit(0);
}

// --- 1. Structural: the old dangerous shape must be gone ---
t('deploy.sh no longer pipes run_migrations.php directly into grep|head',
    !preg_match('/php sql\/run_migrations\.php[^\n]*\|\s*grep[^\n]*\|\s*head/', $deploySrc));

// --- 2. Structural: output is captured into a variable before any filtering ---
t('deploy.sh captures the full migration-runner output into a variable first',
    (bool) preg_match('/MIGRATION_LOG=.*php sql\/run_migrations\.php/', $deploySrc));
t('deploy.sh captures the real exit code ($?) right after the capture',
    (bool) preg_match('/MIGRATION_RC=\\\\?\$\?/', $deploySrc));
t('deploy.sh stops the deploy (exit) when the migration runner failed',
    (bool) preg_match('/MIGRATION_RC.*-ne 0[\s\S]{0,200}?exit/', $deploySrc));

// --- 3. Behavioral: reproduce the exact pipeline shape with a real bash
//        subprocess and a stand-in "migration runner" that prints more than
//        12 matching lines before doing the one thing that matters (writing
//        a tracker file for the LAST, alphabetically-last migration) ---
//
// PHP's escapeshellarg() quotes for the LOCAL platform shell (cmd.exe on
// Windows), not for bash -- and this test builds a script STRING that is
// handed straight to `bash -c` via proc_open()'s array form (bypassing
// cmd.exe entirely, this project's own established Windows-safe-subprocess
// convention). Using escapeshellarg() for text bash itself will parse
// produces cmd-style double-quote escaping bash doesn't understand, which
// silently corrupts any path with no error ("No such file or directory"
// pointing at a mangled path). bash_quote() below does real bash
// single-quote quoting instead, regardless of which OS this test runs on.
function bash_quote($s) {
    return "'" . str_replace("'", "'\\''", $s) . "'";
}
// On this Windows dev box, PHP's proc_open(['bash', ...]) resolves to WSL's
// bash (confirmed: x86_64-pc-linux-gnu, pwd under /mnt/c/...), NOT Git
// Bash -- a completely different filesystem view where a native
// "C:\Users\..." path does not exist at all. bash_path() gives the SAME
// path a Windows-native PHP-file-function argument AND the bash subprocess
// this test spawns can both resolve, on whichever of the two this machine
// actually has. On real Linux (CI, production hosts) sys_get_temp_dir()
// already returns a plain POSIX path that both sides already agree on, so
// no conversion happens there at all -- this is a Windows-dev-box-only
// translation, not a change in what's being proven.
function bash_path($winPath) {
    if (PHP_OS_FAMILY !== 'Windows') {
        return $winPath;
    }
    $p = str_replace('\\', '/', $winPath);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) {
        return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
    }
    return $p;
}
$tmpDir = realpath(sys_get_temp_dir())
    ? str_replace('\\', '/', realpath(sys_get_temp_dir())) . '/deploy_sigpipe_test_' . getmypid()
    : str_replace('\\', '/', sys_get_temp_dir()) . '/deploy_sigpipe_test_' . getmypid();
@mkdir($tmpDir, 0777, true);
$trackerFile = $tmpDir . '/tracker.txt';
$runnerScript = $tmpDir . '/fake_runner.php';

// The real bug's writer is `php sql/run_migrations.php` -- a genuine
// EXTERNAL PROCESS with the default (terminating) SIGPIPE disposition, not
// a shell builtin. Two earlier versions of this test used a bash SCRIPT as
// the stand-in writer instead, and neither reliably reproduced the bug:
// bash's own `echo` builtin can report a write() EPIPE as a mere non-zero
// exit status and keep running the rest of the script, rather than the
// process being killed outright the way an external command experiencing
// the OS-delivered SIGPIPE signal is -- which is a materially different
// (and much less dangerous) failure mode than what actually happened to
// php on the real host. A version routed through `grep` in the middle
// tried to force the danger by sheer output volume (800 padded lines)
// instead; that overflowed grep's stdio buffer reliably on this dev box
// but not on GitHub Actions' runner (a different grep/coreutils build) --
// still not reproducing the ACTUAL mechanism, just a proxy for it.
//
// This version uses a small PHP script -- a real external process with
// PHP CLI's default SIGPIPE disposition, exactly like the real
// sql/run_migrations.php -- confirmed directly to die from SIGPIPE when
// piped into `head` on this dev box. `sleep(1)` before the critical write
// gives `head` an enormous, non-racy margin to have already read its fill
// and exited (reading a dozen short lines takes microseconds on any real
// system) -- no volume tricks, no synchronization gate file, no shell
// builtin ambiguity. This is the actual bug, reproduced with the actual
// kind of process that hit it.
$runnerBody = "<?php\n";
for ($i = 1; $i <= 20; $i++) {
    $runnerBody .= "echo \"[APPLIED] fake_migration_{$i}.php in 10ms\\n\";\n";
}
$runnerBody .= "sleep(1);\n";
$runnerBody .= "echo \"[APPLIED] post-sleep-line -- this write is the one that must fail in the OLD shape\\n\";\n";
$runnerBody .= "file_put_contents(" . var_export($trackerFile, true) . ", 'phase86_final_migration');\n";
$runnerBody .= "echo \"[APPLIED] run_phase86_major_events.php in 10ms\\n\";\n";
$runnerBody .= "echo \"Summary: 21 applied, 0 failed.\\n\";\n";
file_put_contents($runnerScript, $runnerBody);

function reset_tracker($tracker) { @unlink($tracker); }

// Redirect stdout/stderr to TEMP FILES, not pipes -- this project's own
// tests/test_proc_open_pipe_deadlock.php forbids pairing proc_open with
// stream_set_blocking() (a documented no-op on Windows pipes, see
// CLAUDE.md's "PROC_OPEN PIPE DEADLOCK" history), which a "drain with a
// deadline" loop over live pipes can trip into. A file descriptor can
// never fill up and block the writer the way an unread pipe can, so
// polling proc_get_status() with a deadline -- no pipe reads at all -- is
// both simpler and immune to the same class of bug this file exists to
// catch in deploy.sh itself. Runs through a real bash subprocess via an
// argv array (never a shell_exec()'d string) so `|` is interpreted by
// bash's own pipe/signal semantics regardless of the host OS's default
// shell (cmd.exe on Windows would parse '|' itself) -- this codebase's
// established Windows-safe-subprocess convention.
function run_pipeline_to_completion($script) {
    $outFile = tempnam(sys_get_temp_dir(), 'sigpipe_out_');
    $errFile = tempnam(sys_get_temp_dir(), 'sigpipe_err_');
    $descriptors = [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']];
    $proc = proc_open(['bash', '-c', $script], $descriptors, $pipes);
    if (!is_resource($proc)) { @unlink($outFile); @unlink($errFile); return false; }

    // The runner sleeps 1s before its critical write; give it a generous
    // margin beyond that, bounded so a genuinely wedged pipeline can't hang
    // the suite.
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        usleep(20_000);
    }
    proc_close($proc);
    @unlink($outFile);
    @unlink($errFile);
    return true;
}

// PHP_BINARY is a path in THIS process's own OS view -- on this Windows
// dev box that's a native "C:\xampp\...\php.exe", which is a Windows PE
// executable proc_open(['bash', ...]) cannot run AT ALL when "bash"
// resolves to WSL (a genuine separate Linux kernel with its own process
// model -- confirmed on this box: uname reports Microsoft-standard-WSL2,
// and it has no php in its own PATH at all). That is a LOCAL DEV MACHINE
// boundary, not something that exists on real Linux (CI, training,
// your deployment), where "bash" and the process running this very test share
// the same OS and the same php. Rather than guess whether that boundary
// applies, ask bash itself: can it find and run a `php` binary in ITS OWN
// PATH? If yes (true on every real Linux system this suite runs on), use
// that -- it is guaranteed invocable because bash just told us so. If no
// (only possible on this one specific Windows+WSL combination), skip this
// behavioral proof rather than assert a result this environment cannot
// meaningfully produce -- the structural checks above still fully gate
// the suite either way.
$outFile = tempnam(sys_get_temp_dir(), 'sigpipe_which_');
$descriptors = [1 => ['file', $outFile, 'w'], 2 => ['file', '/dev/null', 'w']];
$proc = @proc_open(['bash', '-c', 'command -v php'], $descriptors, $pipes);
$bashHasPhp = false;
if (is_resource($proc)) {
    proc_close($proc);
    $bashHasPhp = trim((string) @file_get_contents($outFile)) !== '';
}
@unlink($outFile);

if (!$bashHasPhp) {
    t('SKIP: no php binary reachable from bash\'s own PATH on this machine -- behavioral proof needs a real Linux bash+php pairing (true on CI and every production host)', true);
} else {
    // OLD (broken) shape: pipe straight into head -12, exactly the danger
    // deploy.sh's real `... | grep ... | head -12` carried (grep removed
    // here only to eliminate its platform-dependent buffering as a
    // confound in THIS test -- the structural checks above already
    // confirm deploy.sh itself no longer routes through grep|head in any
    // shape).
    reset_tracker($trackerFile);
    $oldScript = "php " . bash_quote(bash_path($runnerScript)) . ' | head -12';
    run_pipeline_to_completion($oldScript);
    $oldTrackerWritten = file_exists($trackerFile) && trim((string) file_get_contents($trackerFile)) === 'phase86_final_migration';
    t('OLD pipe-straight-into-head-12 shape reproduces the bug: the final migration tracker entry is LOST',
        $oldTrackerWritten === false);

    // NEW (fixed) shape: capture full output into a variable first, THEN
    // filter for display. head can now only ever close its own copy of an
    // already-finished string, never reach back and kill the real runner.
    reset_tracker($trackerFile);
    $newScript = 'LOG="$(php ' . bash_quote(bash_path($runnerScript)) . ' 2>&1)"; RC=$?; '
        . 'echo "$LOG" | grep -E \'APPLIED|FAILED|Summary|Pending\' | head -12; exit $RC';
    run_pipeline_to_completion($newScript);
    $newTrackerWritten = file_exists($trackerFile) && trim((string) file_get_contents($trackerFile)) === 'phase86_final_migration';
    t('NEW capture-first shape fixes the bug: the final migration tracker entry survives',
        $newTrackerWritten === true);
}

@unlink($runnerScript);
@unlink($trackerFile);
@rmdir($tmpDir);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
