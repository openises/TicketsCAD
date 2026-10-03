<?php
/**
 * GH#147 -- `incident.status_changed` is a real, mapped webhook event, written
 * by the status WRITER.
 *
 * rjonesbsink: "No webhook fires when an incident's status changes ... there's
 * no general status-change signal for anything else (e.g. scheduled -> active)."
 * The event existed only in docs/WEBHOOKS-INTEGRATOR-GUIDE.md's "aspirational"
 * list; nothing in inc/webhooks.php mapped any audit row to it.
 *
 * Drives the REAL incident_update_status_internal() through every transition
 * (2->1, 1->2, 2->3, 3->2, 3->1, 1->3) and asserts, for each, EXACTLY ONE
 * `incident|status_change|ticket` audit row with the documented payload -- the
 * integrator contract. Also proves the legacy close/reopen/update mappings are
 * unchanged (existing subscribers must see no difference) and that the payload
 * never carries incident text.
 *
 * @requires-db
 * Usage: php tests/test_gh147_status_changed_event.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/webhooks.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#147 -- incident.status_changed ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
$_SESSION['user_id'] = $adminId;
$_SESSION['user']    = 'gh147-test-user';

// ── 1. The allowlist ─────────────────────────────────────────────────────
echo "--- webhook allowlist ---\n";
t("incident|status_change|ticket maps to incident.status_changed",
    _audit_to_webhook_event('incident', 'status_change', 'ticket') === 'incident.status_changed');
t('the legacy mappings are unchanged: create', _audit_to_webhook_event('incident', 'create', 'ticket') === 'incident.created');
t('the legacy mappings are unchanged: update', _audit_to_webhook_event('incident', 'update', 'ticket') === 'incident.updated');
t('the legacy mappings are unchanged: close', _audit_to_webhook_event('incident', 'close', 'ticket') === 'incident.closed');
t('the legacy mappings are unchanged: reopen', _audit_to_webhook_event('incident', 'reopen', 'ticket') === 'incident.reopened');
t('the legacy mappings are unchanged: delete', _audit_to_webhook_event('incident', 'delete', 'ticket') === 'incident.deleted');
t('no other activity name leaks onto the new event',
    _audit_to_webhook_event('incident', 'status_changed', 'ticket') === null
    && _audit_to_webhook_event('incident', 'status_change', 'responder') === null);

// ── 2. Every transition emits exactly one row, with the documented payload ─
echo "\n--- every transition, through the real writer ---\n";
db_query("INSERT INTO `{$prefix}ticket_disposition` (`status_val`, `description`, `code`, `active`) VALUES ('GH147 Test Disposition', 'gh147', 'GH147TEST', 1)");
$dispId = (int) db_insert_id();
test_fixture_guard_track('ticket_disposition', $dispId);

$unit = p155_make_unit('GH147-A');
$tid  = p155_make_ticket(2, null, 'GH147 SECRET SCOPE 123 Main St');
db_query("UPDATE `{$prefix}ticket` SET street = '123 Main St', description = 'GH147 SECRET DESCRIPTION', city = 'Springfield' WHERE id = ?", [$tid]);
$a = assign_create_internal($tid, $unit, '', $adminId);
t('fixture: a unit is assigned so the close has something to clear', empty($a['errors']) && !empty($a['id']));

$labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];
$transitionName = function (int $o, int $n): string {
    return $n === 1 ? 'closed' : ($n === 3 ? 'scheduled' : ($o === 1 ? 'reopened' : 'activated'));
};
$booked = date('Y-m-d H:i:s', time() + 7200);

$steps = [
    // [from, to, extra]
    [2, 1, ['disposition_id' => $dispId]],
    [1, 2, []],
    [2, 3, ['booked_date' => $booked]],
    [3, 2, []],
    [2, 1, ['skip_disposition_check' => true]],   // 2->1 again (we need a 3->1 too, below)
    [1, 3, ['booked_date' => $booked]],
    [3, 1, ['skip_disposition_check' => true]],
];
foreach ($steps as [$from, $to, $extra]) {
    $cur = (int) db_fetch_value("SELECT status FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($cur !== $from) {
        t("setup: ticket is at status $from before the $from->$to step (is at $cur)", false);
        continue;
    }
    $before = count(p155_audit_rows($tid, 'status_change'));
    $res = incident_update_status_internal($tid, $to, $adminId, $extra + ['source' => 'ui']);
    t("{$from}->{$to}: the writer reports a real change",
        empty($res['errors']) && $res['status_changed'] === true && (int) $res['old_status'] === $from, json_encode($res));
    $rows = p155_audit_rows($tid, 'status_change');
    t("{$from}->{$to}: EXACTLY ONE new status_change row", count($rows) === $before + 1, (string) (count($rows) - $before));
    $last = $rows ? $rows[count($rows) - 1] : ['d' => [], 'summary' => '', 'details' => ''];
    $d = $last['d'];
    t("{$from}->{$to}: old/new status and both labels",
        ($d['old_status'] ?? null) === $from && ($d['new_status'] ?? null) === $to
        && ($d['old_status_label'] ?? '') === $labels[$from] && ($d['new_status_label'] ?? '') === $labels[$to]);
    t("{$from}->{$to}: transition name is '" . $transitionName($from, $to) . "'", ($d['transition'] ?? '') === $transitionName($from, $to));
    t("{$from}->{$to}: ticket_id, case number, source=ui, actor_type=user",
        ($d['ticket_id'] ?? null) === $tid && !empty($d['incident_number'])
        && ($d['source'] ?? '') === 'ui' && ($d['actor_type'] ?? '') === 'user');
    t("{$from}->{$to}: actor is the acting user (the session)", (int) $last['user_id'] === $adminId && ($d['actor_id'] ?? null) === $adminId);
    if ($from === 3 || $to === 3) {
        t("{$from}->{$to}: booked_date is in the payload", !empty($d['booked_date']));
    } else {
        t("{$from}->{$to}: booked_date is absent (neither side is Scheduled)", !array_key_exists('booked_date', $d));
    }
    if ($to === 1) {
        t("{$from}->{$to}: problemend and the close counts are present",
            !empty($d['problemend']) && array_key_exists('cleared_assigns', $d) && array_key_exists('reset_responders', $d));
        t("{$from}->{$to}: a 'disposition' key is always present on a close", array_key_exists('disposition', $d));
    } else {
        t("{$from}->{$to}: no close-only keys on a non-close", !array_key_exists('problemend', $d) && !array_key_exists('disposition', $d));
    }
    t("{$from}->{$to}: the payload carries no incident text (scope/address/description)",
        stripos($last['details'] . $last['summary'], 'SECRET') === false
        && stripos($last['details'], 'Main St') === false && stripos($last['details'], 'Springfield') === false);
    t("{$from}->{$to}: the summary reads from the case number and labels",
        strpos((string) $last['summary'], (string) ($d['incident_number'] ?? '?')) !== false
        && strpos((string) $last['summary'], $labels[$from]) !== false && strpos((string) $last['summary'], $labels[$to]) !== false);
}

// The first close: disposition detail and the cleared unit.
$firstClose = null;
foreach (p155_audit_rows($tid, 'status_change') as $r) { if (($r['d']['new_status'] ?? 0) === 1) { $firstClose = $r; break; } }
$fd = $firstClose ? $firstClose['d'] : [];
t('the first close carries the disposition as {id, code, label}',
    isset($fd['disposition']) && is_array($fd['disposition'])
    && $fd['disposition']['id'] === $dispId && $fd['disposition']['code'] === 'GH147TEST'
    && $fd['disposition']['label'] === 'GH147 Test Disposition', json_encode($fd['disposition'] ?? null));
t('and says how many units the close cleared', ($fd['cleared_assigns'] ?? 0) === 1 && ($fd['reset_responders'] ?? 0) === 1, json_encode($fd));

// ── 3. An External-API-shaped call ───────────────────────────────────────
echo "\n--- External API shaped context ---\n";
$t2 = p155_make_ticket(2, null, 'GH147 ext');
$res = incident_update_status_internal($t2, 1, $adminId, [
    'skip_disposition_check' => true, 'source' => 'external_api', 'actor_type' => 'api_token', 'token_id' => 4711]);
$r2 = p155_audit_rows($t2, 'status_change');
$d2 = $r2 ? $r2[0]['d'] : [];
t("source=external_api, actor_type=api_token, token_id and via_external_api are in the payload",
    ($d2['source'] ?? '') === 'external_api' && ($d2['actor_type'] ?? '') === 'api_token'
    && ($d2['token_id'] ?? null) === 4711 && ($d2['via_external_api'] ?? null) === true, json_encode($d2));

// ── 4. The system actor ──────────────────────────────────────────────────
$t3 = p155_make_ticket(2, null, 'GH147 sys');
incident_update_status_internal($t3, 1, 0, ['skip_disposition_check' => true, 'source' => 'auto_close', 'actor_type' => 'system']);
$r3 = p155_audit_rows($t3, 'status_change');
t('a system-sourced change names System with a NULL user id even with a dispatcher session mounted',
    $r3 && $r3[0]['user_name'] === 'System' && $r3[0]['user_id'] === null && ($r3[0]['d']['actor_type'] ?? '') === 'system');

// ── 5. A refused change emits nothing ────────────────────────────────────
echo "\n--- refusals emit nothing ---\n";
$t4 = p155_make_ticket(2, null, 'GH147 refused');
$bad = incident_update_status_internal($t4, 3, $adminId, ['source' => 'ui']);   // 3 needs a booked_date
t('scheduling without a booked_date is refused', !empty($bad['errors']));
t('and writes no status_change row', count(p155_audit_rows($t4, 'status_change')) === 0);
$bad2 = incident_update_status_internal($t4, 9, $adminId, []);
t('an invalid status is refused', !empty($bad2['errors']) && count(p155_audit_rows($t4, 'status_change')) === 0);
$missing = incident_update_status_internal(2147480000, 1, $adminId, []);
t('a ticket that does not exist is an error, not a silent success', !empty($missing['errors']));

// ── 6. The preflight mirrors the writer ──────────────────────────────────
echo "\n--- incident_status_change_preflight() ---\n";
$t5 = p155_make_ticket(2, null, 'GH147 preflight');
t('a plain close passes the preflight', incident_status_change_preflight($t5, 1) === []);
t('status 7 fails it', incident_status_change_preflight($t5, 7) !== []);
t('scheduling without booked_date fails it', incident_status_change_preflight($t5, 3) !== []);
t('scheduling with one passes', incident_status_change_preflight($t5, 3, ['booked_date' => $booked]) === []);
t('an unknown disposition fails a close', incident_status_change_preflight($t5, 1, ['disposition_id' => 2147480000]) !== []);
t('a valid disposition passes a close', incident_status_change_preflight($t5, 1, ['disposition_id' => $dispId]) === []);
t('a missing ticket fails it', incident_status_change_preflight(2147480000, 1) !== []);

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
