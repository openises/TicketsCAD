<?php
/**
 * GH #65 (Ron Jones, 2026-08-15) — reopening a closed incident silently
 * re-closed it within seconds, with no audit trail of the second close.
 * Two independent causes, both fixed here.
 *
 * CAUSE 1. A manual close only ever wrote `status = 1` — it never touched
 * `ticket.auto_close_scheduled_at`, which the all-clear path
 * (auto_close_maybe_schedule()) may already have armed for +grace
 * seconds. The marker survived, inert, on a Closed ticket (the sweep's
 * WHERE clause requires status <> 1, so nothing acts on it while
 * closed). Reopening set status back to 2 with the marker still in the
 * past, and the very next sweep — api/stream.php runs one on every SSE
 * tick — silently re-closed it.
 * FIX: inc/incident-write.php's closing branch now calls the new
 * inc/auto_close.php::auto_close_clear_on_close() after every close,
 * manual or automated.
 *
 * CAUSE 2. Every audit_log() call in inc/auto_close.php was guarded with
 * a bare `function_exists('audit_log')`, no lazy require first. From
 * api/incidents.php (which reaches audit.php transitively) the guard was
 * always true; from api/stream.php (which never loads audit.php) it was
 * always false — the sweep's own close-audit entry silently never wrote,
 * on the single most frequent caller.
 * FIX: a shared auto_close_ensure_audit() helper attempts the same lazy
 * require inc/incident-write.php already uses elsewhere, before every
 * function_exists() check in this file.
 *
 * Drives the real functions against a real test ticket for cause 1
 * (matching this project's "reproduce via the real writer" convention),
 * and drives cause 2's fix from a genuinely audit.php-less PHP process
 * (a subprocess that requires ONLY config.php + auto_close.php, exactly
 * api/stream.php's own include set) so the test can't pass by exercising
 * a require path the bug never actually hit.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/auto_close.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function g65(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#65: reopen-then-silent-reclose ===\n\n";

// ── Cause 1: a real test ticket, driven through the real functions ────
$testUserId = (int) (db_fetch_value("SELECT MIN(id) FROM `{$prefix}user`") ?: 1);
$testTypeId = (int) (db_fetch_value("SELECT MIN(id) FROM `{$prefix}in_types`") ?: 1);
db_query(
    "INSERT INTO `{$prefix}ticket`
        (`in_types_id`, `street`, `city`, `state`, `status`, `severity`, `scope`, `description`, `date`, `updated`)
     VALUES (?, 'GH65 Test St', 'Testville', 'MN', 2, 3, 'test', 'GH#65 regression test ticket', NOW(), NOW())",
    [$testTypeId]
);
$tid = (int) db_insert_id();

if (!$tid) {
    echo "[FAIL] could not create a test ticket — aborting live checks\n";
    echo "\n" . ($pass + 1) . " passed, " . ($fail + 1) . " failed\n";
    exit(1);
}

try {
    // Simulate the all-clear path having armed a schedule an hour ago
    // (well past any grace period), exactly the state a real "clear the
    // last unit, then close by hand within the grace window" sequence
    // leaves behind.
    $staleFireAt = date('Y-m-d H:i:s', time() - 3600);
    db_query(
        "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = ? WHERE id = ?",
        [$staleFireAt, $tid]
    );

    // Manual close — the exact path a dispatcher's Close button drives.
    $closeResult = incident_update_status_internal($tid, 1, $testUserId, ['skip_disposition_check' => true]);
    g65('manual close succeeds', empty($closeResult['errors']), implode(',', $closeResult['errors'] ?? []));

    $markerAfterClose = db_fetch_value(
        "SELECT auto_close_scheduled_at FROM `{$prefix}ticket` WHERE id = ?", [$tid]
    );
    g65('CAUSE 1 FIX: a manual close clears a stale auto_close_scheduled_at marker',
        $markerAfterClose === null,
        'marker survived close as: ' . var_export($markerAfterClose, true));

    // Reopen — this is the step that used to race the stale marker.
    $reopenResult = incident_update_status_internal($tid, 2, $testUserId);
    g65('reopen succeeds', empty($reopenResult['errors']), implode(',', $reopenResult['errors'] ?? []));

    $statusAfterReopen = (int) db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    g65('ticket is Open (status=2) immediately after reopening', $statusAfterReopen === 2);

    // The sweep must find NOTHING to do for this ticket — no armed
    // marker survived the close, so it was never a candidate.
    $sweepResult = auto_close_sweep(50);
    $statusAfterSweep = (int) db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    g65('a sweep run immediately after reopening does NOT silently re-close the ticket',
        $statusAfterSweep === 2,
        'status after sweep: ' . $statusAfterSweep . ' (2=Open expected, 1=Closed means the bug reproduced)');
} finally {
    // Always clean up the test ticket, pass or fail.
    db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE target_type = 'ticket' AND target_id = ?", [(string) $tid]);
}

// ── Cause 2: the lazy-require guard, from a genuinely audit.php-less
//    process — the exact shape of api/stream.php's include set. ────────
$probe = <<<'PHP'
require_once %s;
require_once %s . '/inc/auto_close.php';
// Deliberately NOT requiring inc/audit.php — this is api/stream.php's
// own include set (api_guard/config/rbac/session-bootstrap/sse/
// auto_close, never audit.php, directly or transitively).
echo function_exists('audit_log') ? 'PRE:yes' : 'PRE:no';
echo "\n";
$result = auto_close_ensure_audit();
echo $result ? 'ENSURE:true' : 'ENSURE:false';
echo "\n";
echo function_exists('audit_log') ? 'POST:yes' : 'POST:no';
PHP;

$configPath = var_export(__DIR__ . '/../config.php', true);
$rootPath = var_export(dirname(__DIR__), true);
$probeFile = tempnam(sys_get_temp_dir(), 'gh65_probe_') . '.php';
file_put_contents($probeFile, "<?php\n" . sprintf($probe, $configPath, $rootPath) . "\n");

$phpBin = PHP_BINARY;
$out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($probeFile) . ' 2>&1');
@unlink($probeFile);

$lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
g65('CAUSE 2 probe ran without a fatal error', strpos((string) $out, 'Fatal error') === false, (string) $out);
g65('CAUSE 2 FIX: audit_log() is genuinely undefined before the helper runs (proves this is a real audit.php-less context, not a false pass)',
    in_array('PRE:no', $lines, true), (string) $out);
g65('CAUSE 2 FIX: auto_close_ensure_audit() lazily loads audit.php and returns true',
    in_array('ENSURE:true', $lines, true), (string) $out);
g65('CAUSE 2 FIX: audit_log() is defined after the helper runs',
    in_array('POST:yes', $lines, true), (string) $out);

// ── Retroactive repair migration (sql/run_gh65_clear_stale_auto_close.php) ─
// A ticket that was already closed with the marker armed BEFORE this fix
// deployed is still a live trap -- reopening it races the same stale
// timer, since auto_close_clear_on_close() only fires on a NEW close.
// The one-time migration clears any such row directly.
db_query(
    "INSERT INTO `{$prefix}ticket`
        (`in_types_id`, `street`, `city`, `state`, `status`, `severity`, `scope`, `description`, `date`, `updated`)
     VALUES (?, 'GH65 repair-migration test St', 'Testville', 'MN', 1, 3, 'test', 'GH#65 repair-migration test ticket', NOW(), NOW())",
    [$testTypeId]
);
$repairTid = (int) db_insert_id();
try {
    // Pre-fix state directly: a CLOSED ticket carrying an armed marker --
    // exactly the shape auto_close_clear_on_close() now prevents going
    // forward, and exactly what Ron found 7 instances of on his install.
    db_query(
        "UPDATE `{$prefix}ticket` SET auto_close_scheduled_at = ? WHERE id = ?",
        [date('Y-m-d H:i:s', time() - 3600), $repairTid]
    );

    $migrationOut = shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sql/run_gh65_clear_stale_auto_close.php') . ' 2>&1'
    );
    g65('the repair migration runs cleanly', strpos((string) $migrationOut, '[WARN]') === false, (string) $migrationOut);

    $markerAfterRepair = db_fetch_value(
        "SELECT auto_close_scheduled_at FROM `{$prefix}ticket` WHERE id = ?", [$repairTid]
    );
    g65('the repair migration clears an armed marker on an already-closed ticket',
        $markerAfterRepair === null,
        'marker survived repair as: ' . var_export($markerAfterRepair, true));

    // Idempotent: a second run must report nothing left to do and touch
    // nothing (the WHERE clause only ever matches rows still in the bad
    // state — already-repaired rows never match again).
    $secondRunOut = shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../sql/run_gh65_clear_stale_auto_close.php') . ' 2>&1'
    );
    g65('a second run of the repair migration is a clean no-op',
        strpos((string) $secondRunOut, '[SKIP]') !== false,
        (string) $secondRunOut);
} finally {
    db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$repairTid]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
