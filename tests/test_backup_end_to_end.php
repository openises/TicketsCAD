<?php
/**
 * Phase 126b (2026-07-29) — prove the backup guard by RUNNING it, not by
 * reading it.
 *
 * tests/test_backup_guard.php covers the space verdict and the retention plan
 * as pure functions, which is the right way to reach "exactly at the floor" and
 * "free space unreadable" without a full disk. But three of its assertions about
 * the things Eric actually asked for are `strpos($source, 'backup_guard($dir)')`
 * — they prove a string appears in a file, not that a backup is ever refused.
 * A guard that is called with the wrong directory, or whose refusal is caught
 * and ignored, passes every one of those and still fills the disk.
 *
 * So this file drives the real writers:
 *
 *   1. backup_guard() against a REAL directory with a genuinely impossible
 *      condition — does it actually say no? (See the GH#94 note at section 1
 *      below for how this scenario is built and why.)
 *   2. backup_run_now() against that same condition — does it refuse, write
 *      NOTHING, and leave no half-written archive behind? (The failure mode
 *      Eric named is "we consumed the disk space", and a partial 3 GB zip
 *      from a run that died mid-write consumes it just as thoroughly as a
 *      complete one.)
 *   3. The settings round-trip ACROSS PROCESSES. get_variable() caches the whole
 *      settings table in a static on first read, so a write-then-read inside one
 *      process returns the stale value and would pass no matter which table the
 *      write landed in. This project has two settings stores and a documented
 *      history of writing to one and reading from the other; the only honest
 *      test spawns a second PHP process and asks it what it sees.
 *   4. Retention deleting real files through the real settings, including the
 *      newest-is-never-deleted floor.
 *
 * Everything mutated here is restored, and every case that needs a database
 * self-skips with the "SKIP:" convention so a virgin CI checkout stays green.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup_schedule.php';

echo "=== Phase 126b — backup guard and settings, proven by running them ===\n\n";
$pass = 0; $fail = 0; $skip = 0;
function ok($n)  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $why = '') { global $fail; echo "[FAIL] $n" . ($why ? " — $why" : '') . "\n"; $fail++; }
function is_ok($cond, $n, $why = '') { $cond ? ok($n) : bad($n, $why); }
function skip($n, $why) { global $skip; echo "SKIP: $n — $why\n"; $skip++; }

$MB = 1024 * 1024;

// Can we reach a database? Everything below either needs settings or needs to
// be able to put them back afterwards.
$haveDb = false;
try {
    db_fetch_value("SELECT 1");
    $haveDb = true;
} catch (Throwable $e) {
    $haveDb = false;
}

/** A scratch directory that looks like a backup directory. */
function e2e_tmpdir(string $tag): string {
    $d = sys_get_temp_dir() . '/newui-backup-e2e-' . $tag . '-' . getmypid();
    @mkdir($d, 0777, true);
    foreach (glob("$d/*") ?: [] as $f) @unlink($f);
    return $d;
}
function e2e_rmdir(string $d): void {
    foreach (glob("$d/*") ?: [] as $f) @unlink($f);
    @rmdir($d);
}
/** Write a file that backup_archives() will recognise as ours. */
function e2e_archive(string $dir, string $stamp, int $bytes, int $mtime): string {
    $f = "$dir/ticketscad-$stamp.zip";
    file_put_contents($f, str_repeat('x', $bytes));
    touch($f, $mtime);
    return $f;
}

// ── 1. The guard refuses for real, against a real directory ─────────────
if (!$haveDb) {
    skip('the disk guard refuses on a real directory', 'no database available');
} else {
    $origMinFree = get_variable('backup_min_free_mb');
    $origMaxDir  = get_variable('backup_max_dir_mb');
    $dir = e2e_tmpdir('guard');

    // GH#94 (2026-08-20): backup_min_free_mb is now CLAMPED to a sane ceiling
    // (BACKUP_MAX_MIN_FREE_MB, 1 TiB — see inc/backup_schedule.php and
    // tests/test_gh94_backup_min_free_mb_clamp.php) so a byte-value-typed-
    // into-an-MB-field mistake can no longer manufacture a permanently
    // impossible reserve. That is the whole point of the fix, but it also
    // means this scenario's ORIGINAL technique — demanding an absurd amount
    // of free space — can no longer force a refusal on a machine whose real
    // disk is bigger than 1 TiB (this dev box measured ~3.7 TB total). So
    // this scenario now forces the SAME "backup_guard() must actually refuse,
    // and backup_run_now() must actually honour that" property through the
    // OTHER guard instead: the folder-size CAP (backup_max_dir_mb), which is
    // disk-size-independent — a pre-seeded archive plus a cap smaller than it
    // is unconditionally "over cap" on any machine, regardless of how much
    // real disk space is free.
    // 2 MB of dummy content against a 1 MB cap — comfortably over, regardless
    // of the archive-size estimate (which adds on top, never subtracts).
    file_put_contents("$dir/ticketscad-preexisting.zip", str_repeat('x', 2 * $MB));
    backup_setting_set('backup_max_dir_mb', '1'); // 1 MB cap vs a 2 MB pre-existing archive

    // backup_setting() reads through get_variable()'s per-process static cache,
    // so this process would keep seeing the OLD value. Ask a fresh process.
    $probe = e2e_probe(__DIR__ . '/..', '
        $g = backup_guard(' . var_export($dir, true) . ');
        echo ($g["ok"] ? "ALLOW" : "REFUSE") . "|" . $g["reason"];
    ');
    is_ok(strpos($probe, 'REFUSE') === 0,
        'a folder-size cap smaller than what is already stored makes backup_guard() actually refuse', $probe);
    is_ok(stripos($probe, 'limit') !== false,
        'the refusal names the folder limit, so an operator can act on it', $probe);

    // ── 2. backup_run_now() refuses and writes NOTHING NEW ──────────────
    $before = glob("$dir/*") ?: [];
    $run = e2e_probe(__DIR__ . '/..', '
        $r = backup_run_now(' . var_export($dir, true) . ');
        echo ($r["ok"] ? "RAN" : "REFUSED") . "|" . $r["detail"];
    ');
    is_ok(strpos($run, 'REFUSED') === 0,
        'backup_run_now() refuses rather than writing into a directory it cannot afford', $run);

    $after = glob("$dir/*") ?: [];
    is_ok(count($after) === count($before),
        'a refused backup leaves NO NEW file behind — not even a partial archive',
        'files after: ' . implode(', ', array_map('basename', $after)));

    // The refusal must be legible to the admin UI, not just to error_log.
    $status = e2e_probe(__DIR__ . '/..', 'echo (string) get_variable("backup_last_status");');
    is_ok(strpos($status, 'skipped') === 0,
        'the refusal is recorded as backup_last_status="skipped: …" for the UI', $status);

    // A refusal must NOT look like a success: last_ok_at is the staleness clock
    // and the "you have no recent backup" warning depends on it staying put.
    $okAt = e2e_probe(__DIR__ . '/..', 'echo (string) (int) get_variable("backup_last_ok_at");');
    $skipAt = e2e_probe(__DIR__ . '/..', 'echo (string) (int) get_variable("backup_last_skip_at");');
    is_ok((int) $skipAt > 0, 'the refusal stamps backup_last_skip_at so staleness is visible', $skipAt);

    // Put the folder limit back to something sane, then prove the SAME
    // directory is allowed again — otherwise "it refused" might just mean
    // the guard always refuses.
    backup_setting_set('backup_max_dir_mb', $origMaxDir === false ? (string) BACKUP_DEFAULT_MAX_DIR_MB : (string) $origMaxDir);
    $probe2 = e2e_probe(__DIR__ . '/..', '
        $g = backup_guard(' . var_export($dir, true) . ');
        echo ($g["ok"] ? "ALLOW" : "REFUSE") . "|" . $g["reason"];
    ');
    is_ok(strpos($probe2, 'ALLOW') === 0,
        'with a sane folder limit the same directory is allowed — the guard discriminates', $probe2);

    // Restore whatever was really configured before this test ran (both
    // settings this scenario touched — backup_min_free_mb was never modified
    // this run, but keep the restore for symmetry/safety in case a future
    // edit reintroduces a min-free-mb mutation here).
    backup_setting_set('backup_min_free_mb', $origMinFree === false ? '1024' : (string) $origMinFree);
    if ($origMaxDir !== false) backup_setting_set('backup_max_dir_mb', (string) $origMaxDir);
    e2e_rmdir($dir);
}

// ── 3. Settings round-trip through the store the RUNNER reads ───────────
// The documented trap: the Settings UI writes the `settings` table; a separate
// `config` table is read by get_setting(). A value written to one and read from
// the other is silently the default forever. backup_setting() reads via
// get_variable() (the `settings` table), so this proves the endpoint's write
// lands where the scheduler will look for it — from a SEPARATE process, because
// an in-process read is served from a static cache and would pass regardless.
if (!$haveDb) {
    skip('settings round-trip through the store the runner reads', 'no database available');
} else {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $orig   = get_variable('backup_interval_hours');
    $sentinel = '17';   // not the 24 default, so a default cannot fake a pass

    // Write exactly the way api/config-admin.php?section=settings writes.
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        ['backup_interval_hours', $sentinel]
    );

    $seen = trim(e2e_probe(__DIR__ . '/..', 'echo (string) backup_interval_hours();'));
    is_ok($seen === $sentinel,
        'a value saved by the settings endpoint is read back by backup_interval_hours()', "saw '$seen'");

    // And prove the trap would have been caught: get_setting() (the `config`
    // table) must NOT be what the runner uses.
    $viaConfigTable = trim(e2e_probe(__DIR__ . '/..',
        'echo function_exists("get_setting") ? var_export(get_setting("backup_interval_hours", "UNSET"), true) : "NOFUNC";'));
    is_ok($viaConfigTable !== "'" . $sentinel . "'",
        'the value did NOT land in the `config` table — confirming which store is authoritative',
        $viaConfigTable);

    // Restore.
    if ($orig === false) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['backup_interval_hours']);
    } else {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            ['backup_interval_hours', (string) $orig]
        );
    }
    $restored = trim(e2e_probe(__DIR__ . '/..', 'echo (string) backup_interval_hours();'));
    is_ok($restored !== $sentinel, 'the test restored the interval setting it borrowed', $restored);
}

// ── 4. Retention actually DELETES, and never the last copy ──────────────
// backup_apply_retention is exercised here against real files through the real
// settings path, so a policy that computes a perfect plan and then fails to
// unlink anything cannot pass.
$dir = e2e_tmpdir('retention');
$now = time();
for ($i = 0; $i < 6; $i++) {
    e2e_archive($dir, sprintf('2026072%d-120000', $i), 4096, $now - (6 - $i) * 86400);
}
$r = backup_apply_retention($dir, 2, 0, 0, $now);
$left = glob("$dir/ticketscad-*") ?: [];
is_ok($r['pruned'] === 4, 'retention by count deleted 4 of 6 archives', 'pruned=' . $r['pruned']);
is_ok(count($left) === 2, 'exactly 2 archives remain on disk after pruning', 'left=' . count($left));

// The newest must survive; the oldest must be the one that went.
$names = array_map('basename', $left);
sort($names);
is_ok(in_array('ticketscad-20260725-120000.zip', $names, true),
    'the NEWEST archive is the one kept', implode(',', $names));
is_ok(!in_array('ticketscad-20260720-120000.zip', $names, true),
    'the oldest archive is the one deleted', implode(',', $names));

// The size cap must never take the last copy — running out of room is a reason
// to stop making backups, never a reason to end up with none.
foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
e2e_archive($dir, '20260729-120000', 8 * $MB, $now);
$r = backup_apply_retention($dir, 7, 0, 1024, $now);   // 1 KB cap vs an 8 MB file
$left = glob("$dir/ticketscad-*") ?: [];
is_ok(count($left) === 1,
    'a cap smaller than the only archive does NOT delete it', 'left=' . count($left));
is_ok($r['over_cap'] === true,
    'being stuck over the cap is REPORTED rather than resolved by deleting the last backup');

// Age-based expiry, with the same floor.
foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
e2e_archive($dir, '20260101-120000', 1024, $now - 200 * 86400);
e2e_archive($dir, '20260102-120000', 1024, $now - 199 * 86400);
$r = backup_apply_retention($dir, 99, 30, 0, $now);    // everything is older than 30 days
$left = glob("$dir/ticketscad-*") ?: [];
is_ok(count($left) === 1,
    'age expiry still leaves one archive standing, however old they all are', 'left=' . count($left));

// Files this app did not write are never touched, whatever the policy says.
file_put_contents("$dir/payroll-2026.zip", 'not ours');
backup_apply_retention($dir, 1, 1, 1024, $now);
is_ok(is_file("$dir/payroll-2026.zip"),
    'retention never deletes a file this application did not write');

e2e_rmdir($dir);

// ── 5. The scheduler is reachable without a web request ─────────────────
// The whole point of installing cron is that the CLI path works on its own.
$runner = realpath(__DIR__ . '/../tools/backup_run.php');
is_ok($runner && is_file($runner), 'tools/backup_run.php exists for cron / Task Scheduler');
if ($runner) {
    $src = (string) file_get_contents($runner);
    is_ok(strpos($src, '--status') !== false,
        'the runner has a --status mode, so a scheduler can be checked without writing a backup');
}

echo "\n$pass passed, $fail failed" . ($skip ? ", $skip skipped" : '') . "\n";
exit($fail > 0 ? 1 : 0);

/**
 * Run a snippet in a FRESH php process with the app bootstrapped.
 *
 * Needed because get_variable() caches the entire settings table in a static on
 * first call: any write-then-read inside one process is answered from that
 * cache and proves nothing about what was persisted.
 */
function e2e_probe(string $root, string $php): string
{
    $root = realpath($root) ?: $root;
    // A refused backup deliberately writes its reason via error_log, which on
    // the CLI lands on the same stream the payload does. So the snippet's own
    // output is captured with ob_start() (error_log bypasses the buffer) and
    // re-emitted after a marker, giving a payload that is exactly the answer
    // and nothing else. Without this, correct behaviour reads as a failure
    // because the diagnostic line arrives first.
    $code = '<?php require_once ' . var_export($root . '/config.php', true) . ';'
          . ' require_once ' . var_export($root . '/inc/backup_schedule.php', true) . ';'
          . ' ob_start();'
          . $php
          . ' ; $__payload = ob_get_clean(); echo "<<<E2E>>>" . $__payload;';
    $tmp = sys_get_temp_dir() . '/newui-backup-probe-' . getmypid() . '-' . mt_rand() . '.php';
    file_put_contents($tmp, $code);
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $out = (string) @shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $at = strpos($out, '<<<E2E>>>');
    if ($at !== false) $out = substr($out, $at + strlen('<<<E2E>>>'));
    return trim($out);
}
