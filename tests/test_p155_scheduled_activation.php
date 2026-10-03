<?php
/**
 * Phase 155 (S0) -- scheduled-incident activation is ONE atomic, timer-driven
 * path, safe against concurrent callers.
 *
 * Drives the REAL code throughout: inc/scheduled-incidents.php's activation
 * functions, the real tools/scheduled_incidents_tick.php CLI script, and the
 * real api/incidents.php lazy hook (through the endpoint probe). "Time
 * passing" is simulated by booking the fixture incident in the past, never by
 * sleeping.
 *
 * What this proves (each against the pre-fix code it would have failed):
 *   - a due Scheduled incident becomes Open; a future one, a soft-deleted one
 *     and an already-Open one are left alone
 *   - it is announced EXACTLY ONCE: one incident.status_changed audit row and
 *     one legacy "auto-activated" row, both naming the SYSTEM (the old lazy
 *     block named whichever dispatcher's browser happened to poll)
 *   - concurrent callers (real OS processes, released at the same instant)
 *     produce exactly one announcement per incident
 *   - the 60s tick runs end to end and records its heartbeat
 *   - the tick is only "required" when something is about to need it -- never
 *     for a default install, never for an incident booked months out
 *   - the lazy hook in api/incidents.php activates through the same function
 *   - 3 -> 3 with a new booked_date reschedules without activating
 *
 * @requires-db
 * Usage: php tests/test_p155_scheduled_activation.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/sse.php';
require_once __DIR__ . '/../inc/scheduled-incidents.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== Phase 155 (S0) -- scheduled-incident activation ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();

$status = function (int $tid): int {
    return (int) db_fetch_value("SELECT status FROM `" . ($GLOBALS['db_prefix'] ?? '') . "ticket` WHERE id = ?", [$tid]);
};
$count = function (int $tid, string $activity): int {
    return count(p155_audit_rows($tid, $activity));
};

// ── 1. The activation function ────────────────────────────────────────────
echo "--- incident_activate_due_scheduled() ---\n";
$due     = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)', 'P155 due');
$future  = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 3 HOUR)',   'P155 future');
$deleted = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 5 MINUTE)', 'P155 due but deleted');
db_query("UPDATE `{$prefix}ticket` SET deleted_at = NOW() WHERE id = ?", [$deleted]);
$open    = p155_make_ticket(2, null, 'P155 already open');
$nodate  = p155_make_ticket(3, null, 'P155 scheduled without a booked date');

$r = incident_activate_due_scheduled(50);
t('exactly the one due incident is reported activated', (int) $r['activated'] === 1, json_encode($r));
t('the due Scheduled incident is now Open', $status($due) === 2);
t('a future-booked incident stays Scheduled', $status($future) === 3);
t('a soft-deleted due incident is NOT activated', $status($deleted) === 3);
t('an already-Open incident is untouched', $status($open) === 2);
t('a Scheduled incident with no booked date is untouched', $status($nodate) === 3);

$sc = p155_audit_rows($due, 'status_change');
t('exactly ONE incident.status_changed audit row for the activation', count($sc) === 1, (string) count($sc));
$d = $sc ? $sc[0]['d'] : [];
t('status_change details: Scheduled -> Open, transition=activated',
    ($d['old_status'] ?? null) === 3 && ($d['new_status'] ?? null) === 2
    && ($d['transition'] ?? '') === 'activated'
    && ($d['old_status_label'] ?? '') === 'Scheduled' && ($d['new_status_label'] ?? '') === 'Open');
t("status_change source is 'scheduled_activation' and the actor is the system",
    ($d['source'] ?? '') === 'scheduled_activation' && ($d['actor_type'] ?? '') === 'system');
t('the audit row NAMES THE SYSTEM, not a session user', $sc && $sc[0]['user_name'] === 'System' && $sc[0]['user_id'] === null);
t('booked_date is carried in the payload (a 3-transition)', !empty($d['booked_date']));
t('the payload carries no scope / address / description text',
    !array_key_exists('scope', $d) && !array_key_exists('description', $d) && !array_key_exists('street', $d));

$legacy = array_values(array_filter(p155_audit_rows($due, 'update'),
    function ($row) { return !empty($row['d']['auto_activated']); }));
t('exactly ONE legacy "auto-activated" update row (-> incident.updated)', count($legacy) === 1, (string) count($legacy));
t('the legacy row also names the system', $legacy && $legacy[0]['user_name'] === 'System');
t('the incident timeline gets the status-changed line',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}action` WHERE ticket_id = ? AND action_type = 10 AND description LIKE ?",
        [$due, 'Status changed: Scheduled%']) === 1);
t('problemend is NULL on an activated incident',
    db_fetch_value("SELECT problemend FROM `{$prefix}ticket` WHERE id = ?", [$due]) === null);

// Idempotent: a second sweep announces nothing.
$r2 = incident_activate_due_scheduled(50);
t('a second sweep activates nothing', (int) $r2['activated'] === 0, json_encode($r2));
t('and writes no second status_change row', $count($due, 'status_change') === 1);

// ── 2. Concurrent callers: exactly one winner per incident ───────────────
echo "\n--- concurrency (real OS processes) ---\n";
$race = [];
for ($i = 1; $i <= 3; $i++) { $race[] = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 2 MINUTE)', "P155 race $i"); }

$startAt = microtime(true) + 2.5;   // generous: PHP CLI startup on Windows is slow
$procs = [];
$php = PHP_BINARY ?: 'php';
for ($w = 0; $w < 3; $w++) {
    $o = tempnam(sys_get_temp_dir(), 'p155r');
    $procs[] = ['out' => $o, 'proc' => proc_open(
        [$php, __DIR__ . '/_p155_worker.php', 'activate_due', sprintf('%.4f', $startAt)],
        [0 => ['pipe', 'r'], 1 => ['file', $o, 'w'], 2 => ['file', $o . '.err', 'w']],
        $pipes, null, null, ['bypass_shell' => true]), 'pipes' => $pipes];
}
$totalActivated = 0; $workersOk = 0;
foreach ($procs as $p) {
    fclose($p['pipes'][0]);
    proc_close($p['proc']);
    $j = json_decode(trim((string) @file_get_contents($p['out'])), true);
    if (is_array($j) && isset($j['result']['activated'])) {
        $workersOk++;
        $totalActivated += (int) $j['result']['activated'];
    }
    @unlink($p['out']); @unlink($p['out'] . '.err');
}
t('all three concurrent workers ran', $workersOk === 3, (string) $workersOk);
t('together they activated each of the 3 incidents exactly once', $totalActivated === 3, (string) $totalActivated);
$allOnce = true; $allOpen = true;
foreach ($race as $tid) {
    if ($count($tid, 'status_change') !== 1) $allOnce = false;
    if ($status($tid) !== 2) $allOpen = false;
}
t('EVERY raced incident has exactly one status_change row (no double announcement)', $allOnce);
t('every raced incident is Open', $allOpen);
$legacyOnce = true;
foreach ($race as $tid) {
    $n = count(array_filter(p155_audit_rows($tid, 'update'), function ($row) { return !empty($row['d']['auto_activated']); }));
    if ($n !== 1) $legacyOnce = false;
}
t('and exactly one legacy auto-activated row each (the old lazy block wrote one PER CALLER)', $legacyOnce);

// ── 3. The tick, end to end ──────────────────────────────────────────────
echo "\n--- tools/scheduled_incidents_tick.php ---\n";
$tickT = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 1 MINUTE)', 'P155 tick');
$runBefore = (int) db_fetch_value("SELECT COALESCE(run_count, 0) FROM `{$prefix}scheduled_job_runs` WHERE job_key = 'scheduled_incidents_tick'");
$tick = p155_run_process([__DIR__ . '/../tools/scheduled_incidents_tick.php']);
t('the tick script exits 0', $tick['exit'] === 0, $tick['exit'] . ' ' . substr($tick['stdout'] . $tick['stderr'], 0, 300));
t('the tick activated the due incident', $status($tickT) === 2);
t('and announced it once', $count($tickT, 'status_change') === 1);
$run = db_fetch_one("SELECT last_status, last_detail, run_count FROM `{$prefix}scheduled_job_runs` WHERE job_key = 'scheduled_incidents_tick'");
t('the tick wrote its heartbeat (the Status page reads this)', $run && $run['last_status'] === 'ok' && (int) $run['run_count'] === $runBefore + 1);
t('the heartbeat detail says what it did', $run && strpos((string) $run['last_detail'], 'activated 1 scheduled incident') !== false, (string) ($run['last_detail'] ?? ''));

$notCli = (string) file_get_contents(__DIR__ . '/../tools/scheduled_incidents_tick.php');
t('the tick refuses to run under a web SAPI (CLI-only guard before config.php)',
    (bool) preg_match("/PHP_SAPI !== 'cli'[\\s\\S]{0,80}?require_once __DIR__ \\. '\\/\\.\\.\\/config\\.php'/", $notCli));

// ── 4. Registry + the 'required' rule ────────────────────────────────────
echo "\n--- registry and sched_job_required() ---\n";
$reg = sched_job_registry();
t('scheduled_incidents_tick is a registered job', isset($reg['scheduled_incidents_tick']));
t('it runs every 60 seconds', ($reg['scheduled_incidents_tick']['interval_s'] ?? 0) === 60);
t('its command names the real tick script',
    strpos((string) ($reg['scheduled_incidents_tick']['command'] ?? ''), 'scheduled_incidents_tick.php') !== false);
$bat = (string) file_get_contents(__DIR__ . '/../tools/run-scheduled-jobs.bat');
t('the Windows runner invokes it too', strpos($bat, 'scheduled_incidents_tick.php') !== false);

// The "shipped default is not usage" rule, asserted differentially so it holds
// whatever other fixtures happen to exist: a far-future booking must not change
// the answer; a booking inside 24h must make it required.
$base = sched_job_required('scheduled_incidents_tick');
$far  = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 90 DAY)', 'P155 booked three months out');
$afterFar = sched_job_required('scheduled_incidents_tick');
t('an incident booked three months out does NOT make the job required', $afterFar['required'] === $base['required']);
$soon = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 2 HOUR)', 'P155 booked in two hours');
$afterSoon = sched_job_required('scheduled_incidents_tick');
t('an incident booked within 24 hours makes the job required', $afterSoon['required'] === true, json_encode($afterSoon));
t('and says why', strpos($afterSoon['why'], '24 hours') !== false);
// Remove every fixture that could trigger the rule, then check the default install is not flagged.
foreach ([$far, $soon, $future, $nodate] as $gone) {
    db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$gone]);
}
$clean = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE status = 3 AND booked_date IS NOT NULL
        AND booked_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
        AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
if ($clean === 0) {
    t('with nothing booked soon the job is NOT required (a default install is not flagged)',
        sched_job_required('scheduled_incidents_tick')['required'] === false);
}

// ── 5. The lazy hook uses the same function ──────────────────────────────
echo "\n--- the lazy hook in api/incidents.php ---\n";
$lazyT = p155_make_ticket(3, 'DATE_SUB(NOW(), INTERVAL 4 MINUTE)', 'P155 lazy');
$board = p155_probe('session', 'api/incidents.php', $adminId, [], 'GET', 'func=0');
t('the incident board request succeeds', $board['http'] === 200 && is_array($board['json']), $board['raw']);
t('loading the board activated the due incident', $status($lazyT) === 2);
t('and announced it exactly once', $count($lazyT, 'status_change') === 1);
$board2 = p155_probe('session', 'api/incidents.php', $adminId, [], 'GET', 'func=0');
t('a second board load announces nothing more', $count($lazyT, 'status_change') === 1);
$lazyAudit = array_values(array_filter(p155_audit_rows($lazyT, 'update'), function ($row) { return !empty($row['d']['auto_activated']); }));
t("the lazy path's audit row names the SYSTEM, not the dispatcher whose request ran it",
    $lazyAudit && $lazyAudit[0]['user_name'] === 'System');
$incSrc = (string) file_get_contents(__DIR__ . '/../api/incidents.php');
t('api/incidents.php no longer carries its own raw status UPDATE',
    !preg_match('/UPDATE\s+`?\{\$prefix\}`?ticket`?\s+SET\s+`?status`?\s*=/i', $incSrc));
t('api/incidents.php calls the shared function', strpos($incSrc, 'incident_activate_due_scheduled(') !== false);

// ── 6. Reschedule (3 -> 3) ───────────────────────────────────────────────
echo "\n--- 3 -> 3 reschedule ---\n";
require_once __DIR__ . '/../inc/incident-write.php';
$resch = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 5 HOUR)', 'P155 reschedule');
$newBooked = date('Y-m-d H:i:s', time() + 8 * 3600);
$rr = incident_update_status_internal($resch, 3, $adminId, ['booked_date' => $newBooked, 'source' => 'external_api']);
t('rescheduling a Scheduled incident succeeds', empty($rr['errors']) && !empty($rr['rescheduled']), json_encode($rr));
t('it is NOT a status change', $rr['status_changed'] === false && $status($resch) === 3);
t('the booked_date moved', substr((string) db_fetch_value("SELECT booked_date FROM `{$prefix}ticket` WHERE id = ?", [$resch]), 0, 16) === substr($newBooked, 0, 16));
t('and no status_change row was written', $count($resch, 'status_change') === 0);
$rr2 = incident_update_status_internal($resch, 3, $adminId, ['booked_date' => $newBooked]);
t('repeating the identical reschedule is a clean no-op', !empty($rr2['noop']) && empty($rr2['errors']));
$rr3 = incident_update_status_internal($resch, 3, $adminId, []);
t('a 3 -> 3 request with no booked_date is refused', !empty($rr3['errors']));

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
