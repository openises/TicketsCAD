<?php
/**
 * Dead-control audit gate (GH #91, 2026-08-19).
 *
 * Drives the REAL tool (tools/dead_control_audit.php) against fixture
 * trees via --path, same convention as tests/test_rbac_permission_audit.php
 * and tests/test_ui_consistency_audit.php: the tool always validates
 * against the REAL live schema/database (a fixture can't invent a table),
 * but --path lets file SCANNING run over a throwaway directory instead of
 * the real app tree, so a probe can never leak into (or race with) the
 * live working tree.
 *
 * Usage: php tests/test_dead_control_audit.php
 *
 * @requires-db
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/dead_control_audit.php';

// Loaded before any output, same reason the tool itself loads config.php
// before touching $argv-driven behavior: session ini directives warn once
// a byte has already been sent.
require_once $base . '/config.php';

echo "=== Dead-control audit gate (GH #91) ===\n\n";
$pass = 0;
$fail = 0;
function dca_ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function dca_bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function dca_true($c, string $n, string $why = ''): void { $c ? dca_ok($n) : dca_bad($n, $why); }

/** Run the audit; return [exitCode, output]. */
function dca_run(string $tool, array $args = []): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

// ── Fixtures live outside the repo so a crash can't leave one behind for
//    the real app-tree run below to trip over ───────────────────────────
$tmp = sys_get_temp_dir() . '/dca_fixtures_' . getmypid();
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// ═══════════════════════════════════════════════════════════════════════
// (a) Settings-key check — the exact GH #91 bug shape: a control that
// saves, with nothing on the other end.
// ═══════════════════════════════════════════════════════════════════════

// A dead control: data-key with no matching get_variable() anywhere.
$dead = $tmp . '/dead'; @mkdir($dead . '/inc', 0777, true);
file_put_contents($dead . '/settings.php', <<<'PHP'
<?php
echo '<input type="checkbox" data-key="totally_fake_dead_control_xyz">';
PHP);
[$code, $out] = dca_run($tool, ['--settings-only', '--path=' . $dead]);
dca_true($code === 1, 'a data-key control with no reader is flagged (exit 1)', "exit $code");
dca_true(
    strpos($out, 'totally_fake_dead_control_xyz') !== false,
    'the finding names the dead key',
    $out
);

// A live control: data-key WITH a matching get_variable() read.
$live = $tmp . '/live'; @mkdir($live . '/inc', 0777, true);
file_put_contents($live . '/settings.php', <<<'PHP'
<?php
echo '<input type="checkbox" data-key="totally_fake_live_control_xyz">';
PHP);
file_put_contents($live . '/inc/consumer.php', <<<'PHP'
<?php
$v = get_variable('totally_fake_live_control_xyz');
if ($v === '1') { do_the_thing(); }
PHP);
[$lcode, $lout] = dca_run($tool, ['--settings-only', '--path=' . $live]);
dca_true($lcode === 0, 'a data-key control WITH a get_variable() reader is not flagged',
    "exit $lcode; output:\n$lout");

// The generic-form-echo illusion must not count as a read: a page reading
// its OWN posted value back into itself (the exact mechanism that made
// Chat Bridge/Severity Levels look alive) is not evidence of real use.
// settings.php is excluded from the read scan for this reason.
$illusion = $tmp . '/illusion'; @mkdir($illusion, 0777, true);
file_put_contents($illusion . '/settings.php', <<<'PHP'
<?php
echo '<input type="text" data-key="totally_fake_illusion_control_xyz">';
// A hand-rolled echo-back, same shape applySettingsToForm() produces —
// must NOT be mistaken for a real application-code read.
$s = ['totally_fake_illusion_control_xyz' => 'x'];
echo $s['totally_fake_illusion_control_xyz'];
PHP);
[$icode, $iout] = dca_run($tool, ['--settings-only', '--path=' . $illusion]);
dca_true($icode === 1,
    "a control's own page echoing its posted value back does not count as a real read",
    "exit $icode; output:\n$iout");

// ═══════════════════════════════════════════════════════════════════════
// (b) Column check — against the REAL schema (a fixture can't invent a
// table), scoped to a real, known-empty-of-findings table so the test
// doesn't depend on the wider app's own baseline staying in sync.
// ═══════════════════════════════════════════════════════════════════════
[$ccode, $cout] = dca_run($tool, ['--columns-only', '--table=user']);
dca_true($ccode === 0, 'user table has zero NEW column findings post Phase 147 cleanup',
    $cout);
dca_true(strpos($cout, "0 distinct finding(s)") !== false || strpos($cout, ", 0 new") !== false,
    'user table column check reports zero findings', $cout);

// The --include-orphaned mode (opt-in, not part of the default/CI-gated
// run) should still find the 7 MARKED columns when explicitly asked —
// proves the check fires, and that it is genuinely opt-in (the default
// run above did NOT need a baseline entry for them).
[$ocode, $oout] = dca_run($tool, ['--columns-only', '--table=user', '--include-orphaned', '--all']);
foreach (['name_mi', 'title_id', 'addr_street', 'addr_city', 'addr_st', 'phone_s', 'email_s'] as $col) {
    dca_true(strpos($oout, "user.$col") !== false,
        "--include-orphaned still finds the marked (reserved) user.$col column", $oout);
}
dca_true(
    strpos($oout, 'user.pers') === false
    && strpos($oout, 'user.disp') === false
    && strpos($oout, 'user.teams') === false
    && strpos($oout, 'user.reporting') === false
    && strpos($oout, 'user.open_at') === false
    && strpos($oout, 'user.ticket_per_page') === false
    && strpos($oout, 'user.sortorder') === false
    && strpos($oout, 'user.sort_desc') === false
    && strpos($oout, 'user.browser') === false
    && strpos($oout, 'user.sid') === false,
    'the 10 DROPPED columns no longer appear at all (they do not exist)',
    $oout
);
dca_true(strpos($oout, 'user.level') === false,
    'user.level is not flagged — it has a real (migration-only) read path', $oout);

// ═══════════════════════════════════════════════════════════════════════
// The real app tree — every genuinely-fixed finding stays fixed; only
// documented, explained baseline entries remain.
// ═══════════════════════════════════════════════════════════════════════
[$rcode, $rout] = dca_run($tool);
$tail = implode("\n", array_slice(explode("\n", $rout), -15));
dca_true($rcode === 0, 'no NEW dead-control findings in the app tree', $tail);

foreach (['tools/dead_control_settings_baseline.txt', 'tools/dead_control_column_baseline.txt'] as $bf) {
    dca_true(is_file($base . '/' . $bf), "$bf exists");
}

// chat_bridge_* must be explicitly present in the settings baseline
// (GH #89 concurrency note) — if GH #89's own reader lands and this
// baseline entry becomes genuinely stale, this assertion is exactly what
// should catch it on a future run: still passing either way (baseline
// membership is inert once a real reader exists), but never silently
// dropped by an unrelated edit.
$settingsBaseline = (string) @file_get_contents($base . '/tools/dead_control_settings_baseline.txt');
foreach (['chat_bridge_telegram', 'chat_bridge_slack', 'chat_bridge_email', 'chat_bridge_mesh'] as $k) {
    dca_true(strpos($settingsBaseline, "setting:$k") !== false,
        "settings baseline documents $k (GH #89, not GH #91's to fix)");
}

// The tool must refuse to run under a web SAPI.
$src = (string) file_get_contents($tool);
dca_true(
    strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false,
    "$tool carries the canonical CLI-only guard"
);

// ═══════════════════════════════════════════════════════════════════════
// Phase 147 migration itself — idempotent, and produces the documented
// outcome against the live database.
// ═══════════════════════════════════════════════════════════════════════
$migration = $base . '/sql/run_phase147_user_table_cleanup.php';
dca_true(is_file($migration), 'sql/run_phase147_user_table_cleanup.php exists');

$prefix = $GLOBALS['db_prefix'] ?? '';
$dropped = ['pers', 'disp', 'teams', 'reporting', 'open_at', 'ticket_per_page',
    'sortorder', 'sort_desc', 'browser', 'sid'];
$stillThere = [];
foreach ($dropped as $c) {
    $exists = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . 'user', $c]
    );
    if ($exists) { $stillThere[] = $c; }
}
dca_true($stillThere === [], 'all 10 dead user columns are dropped from the live schema',
    'still present: ' . implode(', ', $stillThere));

$levelComment = (string) (db_fetch_one(
    "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'level'",
    [$prefix . 'user']
)['COLUMN_COMMENT'] ?? '');
dca_true(strpos($levelComment, 'Phase 147') !== false && stripos($levelComment, 'privileges') === false,
    "user.level's stale 'privileges' comment is corrected", $levelComment);

// Re-run is a no-op (idempotent) — the migration's own output says so.
[$mcode, $mout] = dca_run($migration);
dca_true($mcode === 0, 'sql/run_phase147_user_table_cleanup.php exits 0 on a second run', $mout);
dca_true(
    strpos($mout, 'already absent') !== false && strpos($mout, 'already') !== false,
    'a second run reports every step as already-applied (idempotent)', $mout
);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
