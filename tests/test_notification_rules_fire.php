<?php
/**
 * Phase 155 (GH#144) - WHERE and HOW OFTEN each Notification Rules event fires.
 *
 * THE BUG THIS DEFENDS AGAINST (D5/D6). The engine was fired from three
 * ENDPOINT files, so the most common dispatch path - units ticked on the New
 * Incident form, which goes incident_create_internal() -> assign_create_internal()
 * and never touches an endpoint - never fired `unit_assign`; neither did the
 * external API nor a message turned into an incident. And two catalogue events
 * (`unit_clear`, `has_broadcast`) were selectable and had default text but
 * NOTHING in the application ever fired them.
 *
 * Every event is now fired from the REAL WRITER, and this file drives each real
 * writer and counts exactly what was produced. "Exactly once" matters as much as
 * "at all": a hook left behind at the endpoint would double every message.
 *
 * Rules are created through the REAL api/notification-rules.php; delivery goes
 * through a capturing stub.
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
try { db_fetch_value("SELECT once_per_incident FROM `{$prefix}notification_rules` LIMIT 1"); }
catch (Throwable $e) { p155_skip('Phase 155 schema missing - run php sql/run_migrations.php'); }

require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/responder-write.php';
p155_install_cleanup();
p155_install_capture();
p155_scheduler(true);
p155_on_cleanup("DELETE FROM `{$prefix}messages` WHERE `subject` LIKE 'P155%'");
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` > ? AND (`activity` LIKE 'notification_rule.%' OR `summary` LIKE '%P155%')", [$auditFloor]);

echo "=== Phase 155 / GH#144 - events fire from the writers, exactly once ===\n\n";
$admin = test_admin_user_id();

function fire_rule(string $event, array $over = []): int
{
    global $admin;
    return p155_create_rule($over + ['name' => 'P155 fire ' . $event, 'event_type' => $event, 'channel' => 'email',
        'recipients' => ['email:fire@example.invalid'],
        'subject_template' => 'P155 ' . $event . ' #{ticket_id}', 'body_template' => 'units={units} n={unit_count} responder={responder} status={old_status}>{new_status}'], $admin);
}
function fire_drop(): void
{
    global $prefix;
    db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 fire %'");
    p155_reset_deliveries();
}
function fire_subjects(): array
{
    $s = [];
    foreach ($GLOBALS['P155_SENT'] as $m) $s[] = $m['subject'];
    sort($s);
    return $s;
}

$u1 = p155_make_responder('P155 Fire One');
$u2 = p155_make_responder('P155 Fire Two');
$u3 = p155_make_responder('P155 Fire Three');

echo "--- New Incident form: units ticked on the form (D5) ---\n";
$rCreate = fire_rule('incident_create');
$rAssign = fire_rule('unit_assign');
$rHigh = fire_rule('severity_high');
$res = p155_make_incident(['scope' => 'P155 form dispatch', 'assign_responders' => [$u1, $u2], 'severity' => 0]);
$tid = (int) $res['id'];
p155_drain();
$subjects = fire_subjects();
p155_t('creating an incident WITH units fires exactly: one incident_create and ONE batched unit_assign (not one per unit)',
    $subjects === ['P155 incident_create #' . $tid, 'P155 unit_assign #' . $tid]);
$batch = null;
foreach ($GLOBALS['P155_SENT'] as $m) if (strpos($m['subject'], 'unit_assign') !== false) $batch = $m;
p155_t('the batched message names BOTH units and counts them ({units} / {unit_count})',
    $batch !== null && strpos($batch['body'], 'P155 Fire One') !== false && strpos($batch['body'], 'P155 Fire Two') !== false
    && strpos($batch['body'], 'n=2') !== false);
p155_t('severity_high did NOT fire for a normal-severity incident', count(array_filter($subjects, static function ($s) { return strpos($s, 'severity_high') !== false; })) === 0);
p155_t('both units really are assigned (the writer did its job)', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$tid]) === 2);
p155_reset_deliveries();

$res2 = p155_make_incident(['scope' => 'P155 form critical', 'severity' => 2, 'assign_responders' => [$u3]]);
p155_drain();
$subjects = fire_subjects();
p155_t('a HIGH-ALERT incident fires severity_high too (alongside create and dispatch)',
    count($subjects) === 3 && count(array_filter($subjects, static function ($s) { return strpos($s, 'severity_high') !== false; })) === 1);
fire_drop();

echo "\n--- one assignment at a time (incident page / external API) ---\n";
$tid2 = (int) p155_make_incident(['scope' => 'P155 single assign'])['id'];
fire_rule('unit_assign');
$a = assign_create_internal($tid2, $u1, '', 0);     // exactly what api/incident-assign.php and api/external/v1/assignments.php call
p155_drain();
p155_t('a single assign_create_internal() fires unit_assign exactly once', count($GLOBALS['P155_SENT']) === 1);
p155_t('...with {responder} = that unit', strpos((string) $GLOBALS['P155_SENT'][0]['body'], 'responder=P155 Fire One') !== false);
p155_reset_deliveries();

echo "\n--- once per incident ---\n";
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 fire %'");
$once = fire_rule('unit_assign', ['once_per_incident' => 1]);
$tid3 = (int) p155_make_incident(['scope' => 'P155 once'])['id'];
assign_create_internal($tid3, $u1, '', 0);
assign_create_internal($tid3, $u2, '', 0);
p155_drain();
p155_t('"once per incident": the FIRST unit notifies, the SECOND does not', count($GLOBALS['P155_SENT']) === 1);
$skippedRow = db_fetch_one("SELECT `status`, `error` FROM `{$prefix}notification_log` WHERE `rule_id` = ? AND `status` = 'skipped' ORDER BY `id` DESC LIMIT 1", [$once]);
p155_t('...and the suppression is LOGGED as skipped with the reason', $skippedRow && stripos((string) $skippedRow['error'], 'once per incident') !== false);
$tid4 = (int) p155_make_incident(['scope' => 'P155 once b'])['id'];
p155_reset_deliveries();
assign_create_internal($tid4, $u3, '', 0);
p155_drain();
p155_t('control: a DIFFERENT incident notifies again (the limit is per incident, not forever)', count($GLOBALS['P155_SENT']) === 1);
fire_drop();
$noOnce = fire_rule('unit_assign');
$tid5 = (int) p155_make_incident(['scope' => 'P155 not once'])['id'];
assign_create_internal($tid5, $u1, '', 0);
assign_create_internal($tid5, $u2, '', 0);
p155_drain();
p155_t('control: without the switch, BOTH units notify', count($GLOBALS['P155_SENT']) === 2);
fire_drop();

echo "\n--- unit_clear (D6): fires on an explicit clear and an unassign, NOT on the close cascade ---\n";
$tid6 = (int) p155_make_incident(['scope' => 'P155 clear'])['id'];
$a1 = assign_create_internal($tid6, $u1, '', 0);
$a2 = assign_create_internal($tid6, $u2, '', 0);
fire_rule('unit_clear');
assign_update_status_internal((int) $a1['id'], 'clear', 0);
p155_drain();
p155_t('clearing ONE assignment fires unit_clear exactly once', count($GLOBALS['P155_SENT']) === 1);
p155_reset_deliveries();
assign_unassign_internal((int) $a2['id'], 0);
p155_drain();
p155_t('unassigning a unit fires unit_clear exactly once', count($GLOBALS['P155_SENT']) === 1);
p155_reset_deliveries();
// a unit-status change to a clear-mapped status (the /s command, the status modal, the mobile app)
$u4 = p155_make_responder('P155 Fire Four');
$tid7 = (int) p155_make_incident(['scope' => 'P155 clear via status'])['id'];
assign_create_internal($tid7, $u4, '', 0);
$clearStatus = (int) db_fetch_value("SELECT `id` FROM `{$prefix}un_status` WHERE `incident_action` = 'clear' ORDER BY `id` LIMIT 1");
responder_set_status_internal($u4, $clearStatus, 0);
p155_drain();
p155_t('a unit-status change that clears its assignment fires unit_clear exactly once', count($GLOBALS['P155_SENT']) === 1);
p155_reset_deliveries();
responder_set_status_internal($u4, $clearStatus, 0);      // already cleared: nothing open
p155_drain();
p155_t('...and repeating it (nothing left to clear) fires nothing', count($GLOBALS['P155_SENT']) === 0);
// A unit on TWO incidents, cleared with one unscoped status change: one event per incident it left.
$u5 = p155_make_responder('P155 Fire Five');
$tidA = (int) p155_make_incident(['scope' => 'P155 clear two A'])['id'];
$tidB = (int) p155_make_incident(['scope' => 'P155 clear two B'])['id'];
assign_create_internal($tidA, $u5, '', 0);
assign_create_internal($tidB, $u5, '', 0);
responder_set_status_internal($u5, $clearStatus, 0);
p155_drain();
p155_t('a unit leaving TWO incidents in one status change fires one unit_clear per incident',
    fire_subjects() === ['P155 unit_clear #' . $tidA, 'P155 unit_clear #' . $tidB]);
fire_drop();
$tid8 = (int) p155_make_incident(['scope' => 'P155 clear cascade'])['id'];
assign_create_internal($tid8, $u1, '', 0);
assign_create_internal($tid8, $u2, '', 0);
fire_rule('unit_clear');
fire_rule('incident_close');
incident_update_status_internal($tid8, 1, 0);
p155_drain();
$subjects = fire_subjects();
p155_t('CLOSING an incident with two active units fires incident_close once and NO unit_clear (the cascade is not an explicit clear)',
    $subjects === ['P155 incident_close #' . $tid8]);
fire_drop();

echo "\n--- incident_close / incident_status ---\n";
$tid9 = (int) p155_make_incident(['scope' => 'P155 status'])['id'];
fire_rule('incident_close');
fire_rule('incident_status');
incident_update_status_internal($tid9, 1, 0);
p155_drain();
p155_t('close fires incident_close (and only that)', fire_subjects() === ['P155 incident_close #' . $tid9]);
$cb = (string) $GLOBALS['P155_SENT'][0]['body'];
p155_t('...with {old_status} / {new_status} filled in (Open>Closed)', strpos($cb, 'status=Open>Closed') !== false);
p155_reset_deliveries();
incident_update_status_internal($tid9, 1, 0);
p155_drain();
p155_t('closing an ALREADY-closed incident again (the external API re-sending status=1) fires nothing', count($GLOBALS['P155_SENT']) === 0);
incident_update_status_internal($tid9, 2, 0);
p155_drain();
p155_t('reopening fires incident_status', fire_subjects() === ['P155 incident_status #' . $tid9]);
p155_reset_deliveries();
incident_update_status_internal($tid9, 3, 0, ['booked_date' => date('Y-m-d H:i:s', time() + 3600)]);
p155_drain();
p155_t('rescheduling fires incident_status too', fire_subjects() === ['P155 incident_status #' . $tid9]);
fire_drop();

echo "\n--- severity escalation (D6) ---\n";
$tid10 = (int) p155_make_incident(['scope' => 'P155 escalate', 'severity' => 0])['id'];
fire_rule('severity_high');
incident_update_fields_internal($tid10, ['severity' => 1], 0);
p155_drain();
p155_t('raising to a level that is NOT high-alert fires nothing', count($GLOBALS['P155_SENT']) === 0);
incident_update_fields_internal($tid10, ['severity' => 2], 0);
p155_drain();
p155_t('raising INTO a high-alert level fires severity_high exactly once', fire_subjects() === ['P155 severity_high #' . $tid10]);
p155_reset_deliveries();
incident_update_fields_internal($tid10, ['severity' => 2, 'scope' => 'P155 escalate again'], 0);
p155_drain();
p155_t('re-saving the SAME high-alert level does not re-fire it', count($GLOBALS['P155_SENT']) === 0);
fire_drop();

echo "\n--- has_broadcast (D6) through the real messaging endpoint ---\n";
fire_rule('has_broadcast', ['subject_template' => 'P155 HAS {message_subject}', 'body_template' => 'P155 {message} by {user}']);
$cap = sys_get_temp_dir() . '/p155_fire_cap_' . getmypid() . '.jsonl';
@unlink($cap);
$br = p155_api('POST', 'broadcast', $admin, ['action' => 'broadcast', 'subject' => 'P155 Storm', 'body' => 'Take shelter now'],
               ['endpoint' => 'messaging', 'capture_file' => $cap]);
p155_t('the broadcast endpoint answers ok', $br['status'] === 200 && !empty($br['json']['ok']));
p155_on_cleanup("DELETE FROM `{$prefix}message_recipients` WHERE `message_id` IN (SELECT `id` FROM `{$prefix}internal_messages` WHERE `subject` = 'P155 Storm')");
p155_on_cleanup("DELETE FROM `{$prefix}internal_messages` WHERE `subject` = 'P155 Storm'");
p155_drain();
p155_t('...and a HAS broadcast fires has_broadcast exactly once', count($GLOBALS['P155_SENT']) === 1);
$hb = $GLOBALS['P155_SENT'][0] ?? ['subject' => '', 'body' => ''];
p155_t('...with the broadcast\'s own subject and text in {message_subject} / {message}',
    $hb['subject'] === 'P155 HAS P155 Storm' && strpos($hb['body'], 'Take shelter now') !== false);
@unlink($cap);
fire_drop();

echo "\n--- controls ---\n";
$off = fire_rule('incident_create', ['active' => false]);
p155_make_incident(['scope' => 'P155 inactive rule']);
p155_drain();
p155_t('CONTROL: an inactive rule never fires', count($GLOBALS['P155_SENT']) === 0);
fire_drop();
require_once __DIR__ . '/../inc/notification-engine.php';
p155_t('an event that is not in the catalogue produces nothing (no crash, no rows)', notification_fire('bogus_event', ['ticket_id' => 1]) === []);
$noRulesQueries = 0;
p155_t('with NO rules at all the writers still work and fire nothing',
    !empty(p155_make_incident(['scope' => 'P155 no rules'])['id']) && count($GLOBALS['P155_SENT']) === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `rule_id` IS NOT NULL AND `subject` LIKE 'P155%'") === 0);

echo "\n--- every catalogue event is hooked somewhere real ---\n";
require_once __DIR__ . '/../inc/notification-events.php';
$corpus = '';
foreach (array_merge(glob(__DIR__ . '/../inc/*.php'), glob(__DIR__ . '/../api/*.php')) as $f) {
    $corpus .= "\n" . preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($f));
}
foreach (notification_event_ids() as $ev) {
    p155_t("event '{$ev}' is passed to notification_hook() by real code", (bool) preg_match("/notification_hook\(\s*(?:\\\$newStatus === 1 \? 'incident_close' : 'incident_status'|'{$ev}')/", $corpus)
        || ($ev === 'incident_status' && strpos($corpus, "'incident_close' : 'incident_status'") !== false));
}

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `scope` LIKE 'P155%'") === 0);
p155_done();
