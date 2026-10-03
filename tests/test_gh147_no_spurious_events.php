<?php
/**
 * GH#147 -- a status event fires for a REAL change only.
 *
 * Three defects this phase found by reading the writer against the issue, each
 * proven here through the real code:
 *
 *   1. An External API `PATCH {status: 1}` on an already-closed incident re-ran
 *      the close UPDATE, OVERWRITING `problemend` (the real close time) and
 *      re-firing incident.closed. An RMS that retried a close silently
 *      corrupted close times.
 *   2. The UI's "re-close heal" path (re-closing an already-closed incident to
 *      clear lingering units) emitted `incident.closed` although nothing had
 *      closed. A general status event must not.
 *   3. The writer read no prior status, so two processes changing the same
 *      incident at once both "won": two audit rows, two webhooks, two cascades.
 *      It is now compare-and-set.
 *
 * Mode-sensitive cases (disposition_required_on_close) run in fresh worker
 * processes -- get_variable() caches the settings table for a process's life.
 *
 * @requires-db
 * Usage: php tests/test_gh147_no_spurious_events.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- no spurious status events ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh147-test-user';
p155_remember_settings(['disposition_required_on_close']);

$sc  = function (int $tid): int { return count(p155_audit_rows($tid, 'status_change')); };
$col = function (int $tid, string $c) use ($prefix) {
    return db_fetch_value("SELECT `{$c}` FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
};

// ── 1. A repeat close does not re-stamp problemend ───────────────────────
echo "--- repeat close (the External API idempotency bug) ---\n";
$t1 = p155_make_ticket(2, null, 'GH147 repeat close');
$first = incident_update_status_internal($t1, 1, $adminId, ['skip_disposition_check' => true, 'source' => 'external_api']);
t('the first close is a real change', $first['status_changed'] === true);
// Pin problemend to a known, clearly-old value (simulates "closed an hour ago"
// without sleeping); a re-stamp would replace it with NOW().
db_query("UPDATE `{$prefix}ticket` SET problemend = '2020-01-02 03:04:05' WHERE id = ?", [$t1]);
$again = incident_update_status_internal($t1, 1, $adminId, ['skip_disposition_check' => true, 'source' => 'external_api']);
t('a repeat close is reported as a no-op, not an error', $again['noop'] === true && $again['status_changed'] === false && empty($again['errors']), json_encode($again));
t("noop_reason is 'unchanged'", $again['noop_reason'] === 'unchanged');
t('problemend is NOT re-stamped (the real close time survives)', (string) $col($t1, 'problemend') === '2020-01-02 03:04:05');
t('and no second status_change row was written', $sc($t1) === 1);

$reopen = incident_update_status_internal($t1, 2, $adminId, ['source' => 'ui']);
t('control: a real reopen still works', $reopen['status_changed'] === true && (int) $col($t1, 'status') === 2);
$reopenAgain = incident_update_status_internal($t1, 2, $adminId, ['source' => 'ui']);
t('a repeat reopen is also a no-op', $reopenAgain['noop'] === true && $sc($t1) === 2);

// ── 2. The disposition-required gate does not fire on a repeat close ──────
echo "\n--- disposition-required gate vs a repeat close ---\n";
$t2 = p155_make_ticket(2, null, 'GH147 disposition gate');
$real = p155_worker('status', ["id=$t2", 'to=1', "user=$adminId", 'set:disposition_required_on_close=1']);
t('control: with the gate ON, a real close without a disposition is refused',
    !empty($real['errors']) && stripos((string) $real['errors'][0], 'disposition') !== false, json_encode($real));
t('and the refused close left the incident Open with no event', (int) $col($t2, 'status') === 2 && $sc($t2) === 0);
db_query("UPDATE `{$prefix}ticket` SET status = 1, problemend = '2020-01-02 03:04:05' WHERE id = ?", [$t2]);   // closed by some earlier route
$repeat = p155_worker('status', ["id=$t2", 'to=1', "user=$adminId", 'set:disposition_required_on_close=1']);
t('with the gate ON, re-closing an ALREADY-closed incident is a no-op, not a "disposition required" refusal',
    empty($repeat['errors']) && !empty($repeat['noop']), json_encode($repeat));
t('and problemend is still the original', (string) $col($t2, 'problemend') === '2020-01-02 03:04:05');
db_query("UPDATE `{$prefix}settings` SET value = '0' WHERE name = 'disposition_required_on_close'");

// ── 3. The UI heal path emits no status event ────────────────────────────
echo "\n--- the UI re-close heal path ---\n";
$unit = p155_make_unit('GH147-HEAL');
$t3 = p155_make_ticket(1, null, 'GH147 heal');
db_query("UPDATE `{$prefix}ticket` SET problemend = NOW() WHERE id = ?", [$t3]);
// A close that predates the cascade left a unit assigned (the stranded state the
// heal exists for). This is the one hand-made row: it IS the legacy bug's state.
db_query("INSERT INTO `{$prefix}assigns` (`as_of`, `status_id`, `ticket_id`, `responder_id`, `user_id`, `dispatched`) VALUES (NOW(), 1, ?, ?, ?, NOW())",
    [$t3, $unit, $adminId]);
$heal = p155_probe('session', 'api/incident-update.php', $adminId,
    ['action' => 'update_status', 'ticket_id' => $t3, 'new_status' => 1]);
t('the heal request succeeds and reports it cleared the lingering unit',
    $heal['http'] === 200 && !empty($heal['json']['success']) && stripos((string) ($heal['json']['message'] ?? ''), 'lingering') !== false,
    $heal['raw']);
t('the lingering assignment is cleared',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00')", [$t3]) === 0);
t('the heal wrote NO incident.status_changed row (nothing changed status)', $sc($t3) === 0);
$closeRows = p155_audit_rows($t3, 'close');
t('(the pre-existing legacy close row for the heal is untouched -- existing subscribers see no change)', count($closeRows) === 1);
$nothing = p155_probe('session', 'api/incident-update.php', $adminId,
    ['action' => 'update_status', 'ticket_id' => $t3, 'new_status' => 1]);
t('re-closing again with nothing to heal is refused as before', $nothing['http'] >= 400 && stripos($nothing['raw'], 'already Closed') !== false, $nothing['raw']);
t('and still no status_change row', $sc($t3) === 0);

// ── 4. Concurrent changes: exactly one winner ────────────────────────────
echo "\n--- compare-and-set under concurrency ---\n";
$wins = 0; $racesRun = 0; $allOnce = true; $loserErrors = 0; $workersParsed = 0;
for ($round = 1; $round <= 3; $round++) {
    $rt = p155_make_ticket(2, null, "GH147 race $round");
    $jobs = [];
    for ($i = 0; $i < 3; $i++) {
        $jobs[] = ['action' => 'status', 'args' => ["id=$rt", 'to=1', "user=$adminId", 'skip_disp=1', 'source=ui']];
    }
    $results = p155_worker_race($jobs);
    $racesRun++;
    $changed = 0;
    foreach ($results as $r) {
        if (!is_array($r)) continue;
        $workersParsed++;
        if (!empty($r['status_changed'])) $changed++;
        if (!empty($r['errors'])) $loserErrors++;
    }
    $wins += $changed;
    if ($changed !== 1 || $sc($rt) !== 1) $allOnce = false;
}
t('across 3 races of 3 concurrent closers each, EXACTLY ONE caller reports the change', $allOnce, "wins=$wins");
t('every loser is a clean no-op (no error), and all 9 workers answered', $loserErrors === 0 && $workersParsed === 9, "errors=$loserErrors parsed=$workersParsed");

// A loser must not have run the close cascade: assign a unit, race the close, one clear only.
$unit2 = p155_make_unit('GH147-RACE');
$rt2 = p155_make_ticket(2, null, 'GH147 race cascade');
assign_create_internal($rt2, $unit2, '', $adminId);
$results = p155_worker_race([
    ['action' => 'status', 'args' => ["id=$rt2", 'to=1', "user=$adminId", 'skip_disp=1']],
    ['action' => 'status', 'args' => ["id=$rt2", 'to=1', "user=$adminId", 'skip_disp=1']],
]);
$cleared = 0;
foreach ($results as $r) { $cleared += (int) ($r['cleared_assigns'] ?? 0); }
t('two racing closes clear the incident\'s unit exactly once between them', $cleared === 1, (string) $cleared);
t('and write exactly one status_change row', $sc($rt2) === 1);

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
