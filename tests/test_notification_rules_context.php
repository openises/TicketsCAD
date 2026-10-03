<?php
/**
 * Phase 155 (GH#144) - REGRESSION for the notification context defects
 * D1 / D2 / D3 / D4 found when the "complete" engine was read end to end.
 *
 *   D1  `unit_assign` supplied only {ticket_id, scope, responder}: {street} {city}
 *       {incident_type} {severity} rendered EMPTY on dispatch - an Active911 page
 *       with no address.
 *   D2  The severity / incident-type filters were applied only `if isset(context
 *       value)`. `unit_assign` never supplied them, so a "Fire incidents only"
 *       rule fired for EVERY dispatch.
 *   D3  `incident_close` hard-coded `'severity' => 0`: a rule filtered to any
 *       other severity could never match a close.
 *   D4  `{incident_type}` was blank in EVERY notification, even incident_create:
 *       the caller read `in_types.name` and the column is `type`.
 *
 * Every event here is fired by a REAL WRITER (assign_create_internal,
 * incident_update_status_internal, incident_create_internal), rules are created
 * through the REAL api/notification-rules.php, and delivery goes through a
 * capturing stub - never a hand-built context standing in for what a writer
 * produces. Reverting notification_build_context() makes these fail.
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
p155_install_cleanup();
p155_install_capture();
p155_scheduler(true);      // a live sweep: the writer queues, the test drains explicitly
p155_on_cleanup("DELETE FROM `{$prefix}messages` WHERE `subject` LIKE 'P155%'");

echo "=== Phase 155 / GH#144 - notification context (D1-D4) ===\n\n";
$admin = test_admin_user_id();

// An incident with a distinctive address, type and a non-default severity - created
// BEFORE any rule exists, so creating it fires nothing.
$inc = p155_make_incident(['in_types_id' => 1, 'severity' => 1, 'scope' => 'P155 ctx incident',
    'street' => '45 Oak Ave', 'city' => 'Riverton']);
$tid = (int) $inc['id'];
$unit = p155_make_responder('P155 Unit Alpha');
$typeName = (string) db_fetch_value("SELECT `type` FROM `{$prefix}in_types` WHERE `id` = 1");
$sevLabel = severity_label(1);
p155_t('fixture: a real incident exists with a type name and a non-default severity', $tid > 0 && $typeName !== '' && $sevLabel !== '');

function ctx_rule(array $over, int $admin): int
{
    return p155_create_rule($over + ['name' => 'P155 ctx rule', 'event_type' => 'unit_assign', 'channel' => 'email',
        'recipients' => ['email:ctx@example.invalid'],
        'subject_template' => 'P155 {incident_type}|{severity_label}|{street}|{city}',
        'body_template' => 'P155 body {incident_type}/{severity}/{severity_label}/{street}/{city}/{address}/{responder}'], $admin);
}
function ctx_drop_rules(): void
{
    global $prefix;
    db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` = 'P155 ctx rule'");
}
function ctx_assign(int $tid, int $unit): void
{
    global $prefix;
    db_query("DELETE FROM `{$prefix}assigns` WHERE `ticket_id` = ?", [$tid]);
    $r = assign_create_internal($tid, $unit, '', 0);
    p155_t('(the real assignment writer succeeded)', !empty($r['id']));
}

echo "--- D1/D4: dispatch text is complete ---\n";
ctx_rule([], $admin);
ctx_assign($tid, $unit);
p155_drain();
$m = $GLOBALS['P155_SENT'][0] ?? null;
p155_t('a unit_assign notification was produced by the REAL assignment writer', $m !== null);
p155_t('{street} and {city} are filled in on dispatch (D1 - they rendered EMPTY)',
    strpos((string) ($m['body'] ?? ''), '45 Oak Ave') !== false && strpos((string) ($m['body'] ?? ''), 'Riverton') !== false);
p155_t('{address} joins them', strpos((string) ($m['body'] ?? ''), '45 Oak Ave Riverton') !== false);
p155_t('{incident_type} is the type NAME, not blank (D4 - the column is `type`, not `name`)',
    strpos((string) ($m['subject'] ?? ''), 'P155 ' . $typeName . '|') === 0);
p155_t('{severity} and {severity_label} reflect the incident (' . $sevLabel . ')',
    strpos((string) ($m['body'] ?? ''), '/1/' . $sevLabel . '/') !== false);
p155_t('{responder} is the unit that was dispatched', strpos((string) ($m['body'] ?? ''), 'P155 Unit Alpha') !== false);
ctx_drop_rules(); p155_reset_deliveries();

echo "\n--- D2: filters apply on unit_assign (they used to be ignored) ---\n";
$tests = [
    'severity filter = the incident\'s severity (control: must fire)'  => [['severity_filter' => 1], true],
    'severity filter = a DIFFERENT severity (must NOT fire)'           => [['severity_filter' => 2], false],
    'incident-type filter = the incident\'s type (control: must fire)' => [['incident_type_filter' => 1], true],
    'incident-type filter = a DIFFERENT type (must NOT fire)'          => [['incident_type_filter' => 2], false],
    'both filters matching (control: must fire)'                       => [['severity_filter' => 1, 'incident_type_filter' => 1], true],
    'matching severity but WRONG type (must NOT fire)'                 => [['severity_filter' => 1, 'incident_type_filter' => 2], false],
];
foreach ($tests as $label => [$over, $shouldFire]) {
    ctx_rule($over, $admin);
    ctx_assign($tid, $unit);
    p155_drain();
    $fired = count($GLOBALS['P155_SENT']) > 0;
    p155_t($label, $fired === $shouldFire);
    ctx_drop_rules(); p155_reset_deliveries();
}

echo "\n--- D3: filtered close / status rules can match (severity was hard-coded 0) ---\n";
// The incident is severity 1. incident_close rules filtered to 1 / 2:
ctx_rule(['event_type' => 'incident_close', 'severity_filter' => 1, 'name' => 'P155 ctx rule'], $admin);
$r = incident_update_status_internal($tid, 1, 0);
p155_t('(the real close writer succeeded)', !empty($r['updated']));
p155_drain();
p155_t('a close rule filtered to the incident\'s severity DOES fire (before the fix it never could)', count($GLOBALS['P155_SENT']) === 1);
$closeBody = (string) ($GLOBALS['P155_SENT'][0]['body'] ?? '');
p155_t('...and the close text carries the type', strpos($closeBody, $typeName) !== false || strpos((string) ($GLOBALS['P155_SENT'][0]['subject'] ?? ''), $typeName) !== false);
ctx_drop_rules(); p155_reset_deliveries();

incident_update_status_internal($tid, 2, 0);             // reopen (itself fires nothing: no rule)
ctx_rule(['event_type' => 'incident_close', 'severity_filter' => 2], $admin);
incident_update_status_internal($tid, 1, 0);
p155_drain();
p155_t('a close rule filtered to a DIFFERENT severity does not fire', count($GLOBALS['P155_SENT']) === 0);
ctx_drop_rules(); p155_reset_deliveries();

ctx_rule(['event_type' => 'incident_status', 'incident_type_filter' => 2], $admin);
incident_update_status_internal($tid, 2, 0);             // reopen -> incident_status
p155_drain();
p155_t('an incident_status rule filtered to a DIFFERENT incident type does not fire', count($GLOBALS['P155_SENT']) === 0);
ctx_drop_rules(); p155_reset_deliveries();
ctx_rule(['event_type' => 'incident_status', 'incident_type_filter' => 1], $admin);
incident_update_status_internal($tid, 1, 0);             // close: not an incident_status event
incident_update_status_internal($tid, 2, 0);             // reopen -> incident_status
p155_drain();
p155_t('an incident_status rule filtered to the incident\'s type fires on a reopen (and only that)', count($GLOBALS['P155_SENT']) === 1);
ctx_drop_rules(); p155_reset_deliveries();

echo "\n--- D4 on incident_create: the type name is in the very first notification ---\n";
ctx_rule(['event_type' => 'incident_create', 'subject_template' => 'P155 new {incident_type} at {street}',
          'body_template' => 'P155 {incident_type} {incident_number} {address} {severity_label}'], $admin);
$new = p155_make_incident(['in_types_id' => 2, 'severity' => 0, 'scope' => 'P155 ctx created', 'street' => '9 Elm St', 'city' => 'Lakeside']);
p155_drain();
$typeName2 = (string) db_fetch_value("SELECT `type` FROM `{$prefix}in_types` WHERE `id` = 2");
$cm = $GLOBALS['P155_SENT'][0] ?? null;
p155_t('creating an incident through the REAL writer fires incident_create exactly once', count($GLOBALS['P155_SENT']) === 1);
p155_t('the subject carries the type name and street', ($cm['subject'] ?? '') === 'P155 new ' . $typeName2 . ' at 9 Elm St');
p155_t('the body carries the stamped case number (read from the ticket after it was allocated), address and severity',
    strpos((string) ($cm['body'] ?? ''), (string) db_fetch_value("SELECT `incident_number` FROM `{$prefix}ticket` WHERE `id` = ?", [(int) $new['id']])) !== false
    && strpos((string) ($cm['body'] ?? ''), '9 Elm St Lakeside') !== false);
ctx_drop_rules(); p155_reset_deliveries();

echo "\n--- a vanished ticket: filters fail CLOSED ---\n";
require_once __DIR__ . '/../inc/notification-engine.php';
ctx_rule(['event_type' => 'incident_close', 'severity_filter' => 1], $admin);
$ghost = notification_check('incident_close', ['ticket_id' => 987654321]);
p155_t('a filtered rule does NOT match an event whose ticket cannot be loaded (it used to match: the check was skipped)', $ghost === []);
ctx_drop_rules(); p155_reset_deliveries();

echo "\n--- an incident in the wastebasket is not described in a notification ---\n";
$wb = p155_make_incident(['scope' => 'P155 wastebasket incident', 'street' => '9 Secret Lane', 'city' => 'Hidden']);
$wbId = (int) $wb['id'];
$before = notification_build_context(['ticket_id' => $wbId, 'event_id' => 'incident_close']);
p155_t('fixture: while the incident is live the context describes it', ($before['street'] ?? '') === '9 Secret Lane' && ($before['scope'] ?? '') === 'P155 wastebasket incident');
db_query("UPDATE `{$prefix}ticket` SET `deleted_at` = NOW() WHERE `id` = ?", [$wbId]);
$after = notification_build_context(['ticket_id' => $wbId, 'event_id' => 'incident_close']);
p155_t('once it is soft-deleted the context carries NONE of its facts (no address, no scope)', ($after['street'] ?? '') === '' && ($after['scope'] ?? '') === '');
ctx_rule(['event_type' => 'incident_close', 'severity_filter' => 0], $admin);
p155_t('...and a filtered rule does not match it (the same fail-closed path as a vanished ticket)', notification_check('incident_close', ['ticket_id' => $wbId]) === []);
ctx_drop_rules(); p155_reset_deliveries();

echo "\n--- hygiene ---\n";
$epSrc = file_get_contents(__DIR__ . '/../inc/notification-engine.php');
p155_t('the engine no longer reads a type "name" column anywhere', !preg_match('/\[[\'"]name[\'"]\]\s*\?\?\s*[\'"]{2}/', $epSrc) || strpos($epSrc, 'in_types') !== false);
foreach (['api/incident-create.php', 'api/incident-assign.php', 'api/incident-update.php'] as $f) {
    $s = file_get_contents(__DIR__ . '/../' . $f);
    $called = false;
    foreach (token_get_all($s) as $tk) {
        if (is_array($tk) && $tk[0] === T_STRING && in_array($tk[1], ['notification_check', 'notification_hook', 'notification_fire'], true)) $called = true;
    }
    p155_t("{$f} no longer fires notifications itself (the writers do - firing here too would double every message)", !$called);
}

p155_cleanup();
$left = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` = 'P155 ctx rule'");
p155_t('fixtures are gone after cleanup (verified by querying)', $left === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `scope` LIKE 'P155%'") === 0);
p155_done();
