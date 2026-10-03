<?php
/**
 * Phase 155 (GH#144) - api/notification-rules.php through the REAL endpoint
 * (tests/_p155_rules_probe.php: a real session, a real csrf_token, no web server).
 *
 * Proves the authority model (Super Admin only; Org Admin, Dispatcher and
 * Operator are refused on EVERY action; CSRF on every POST; no `is_admin()`
 * fallback), the validation matrix, that every mutation is audited, that
 * Preview sends and writes NOTHING, and the Test-send safety rails (defaults to
 * the administrator only, a real send needs confirmation, throttled).
 *
 * @requires-db
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_p155_notify_helpers.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/notification-engine.php';

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) p155_skip('no database');
$prefix = $GLOBALS['db_prefix'] ?? '';
foreach (['notification_rules', 'notification_log', 'email_lists'] as $t) {
    try { db_fetch_value("SELECT 1 FROM `{$prefix}{$t}` LIMIT 1"); }
    catch (Throwable $e) { p155_skip("{$t} missing - run php sql/run_migrations.php"); }
}
try {
    db_fetch_value("SELECT once_per_incident FROM `{$prefix}notification_rules` LIMIT 1");
} catch (Throwable $e) { p155_skip('notification_rules.once_per_incident missing - run php sql/run_phase155_notification_rules.php'); }

p155_install_cleanup();
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `activity` LIKE 'notification_rule.%' AND `id` > ?", [$auditFloor]);
p155_remember_setting('notification_email_format');
p155_remember_setting('notification_prefs_mode');
p155_remember_setting('notification_log_retention_days');
p155_remember_setting('notify_rule_breaker');

echo "=== Phase 155 / GH#144 - api/notification-rules.php ===\n\n";

$admin = test_admin_user_id();
$orgAdmin = p155_make_user(900155301, 'p155_orgadmin', 'p155.orgadmin@example.invalid', null, null, [2]);
$dispatcher = p155_make_user(900155302, 'p155_dispatcher', 'p155.dispatcher@example.invalid', null, null, [3]);
$operator = p155_make_user(900155303, 'p155_operator', 'p155.operator@example.invalid', null, null, [4]);
$noPhone = p155_make_user(900155304, 'p155_nophone', 'p155.nophone@example.invalid', null, null, []);
$withPhone = p155_make_user(900155305, 'p155_withphone', 'p155.withphone@example.invalid', '5552223333', null, []);

function rule_count(string $like = 'P155%'): int {
    global $prefix;
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE ?", [$like]);
}

echo "--- static: the gate ---\n";
$src = file_get_contents(__DIR__ . '/../api/notification-rules.php');
p155_t("the endpoint gates on rbac_can('action.manage_notification_rules')", strpos($src, "rbac_can('action.manage_notification_rules')") !== false);
$toks = token_get_all($src);
$hasIsAdmin = false;
foreach ($toks as $tk) { if (is_array($tk) && $tk[0] === T_STRING && strtolower($tk[1]) === 'is_admin') $hasIsAdmin = true; }
p155_t('...with NO is_admin() fallback anywhere in the file (tokenized, not grepped - the docblock mentions it)', !$hasIsAdmin);
$adm = file_get_contents(__DIR__ . '/../inc/notification-rules-admin.php');
$hasIsAdmin2 = false;
foreach (token_get_all($adm) as $tk) { if (is_array($tk) && $tk[0] === T_STRING && strtolower($tk[1]) === 'is_admin') $hasIsAdmin2 = true; }
p155_t('...nor in the include that holds the logic', !$hasIsAdmin2);

echo "\n--- RBAC: refused on every action for Org Admin, Dispatcher, Operator ---\n";
$gets = ['meta', 'list', 'get', 'log', 'queue', 'users'];
$posts = ['create', 'update', 'delete', 'toggle', 'duplicate', 'preview', 'test_send', 'save_settings'];
$before = rule_count('%');
foreach (['Org Admin' => $orgAdmin, 'Dispatcher' => $dispatcher, 'Operator' => $operator] as $label => $uid) {
    $allDenied = true; $detail = '';
    foreach ($gets as $a) {
        $r = p155_api('GET', $a, $uid, 'id=1');
        if ($r['status'] !== 403) { $allDenied = false; $detail .= " GET {$a}={$r['status']}"; }
    }
    foreach ($posts as $a) {
        $r = p155_api('POST', $a, $uid, ['id' => 1, 'name' => 'P155 should not exist', 'event_type' => 'incident_create', 'channel' => 'email', 'recipients' => []]);
        if ($r['status'] !== 403) { $allDenied = false; $detail .= " POST {$a}={$r['status']}"; }
    }
    p155_t("{$label}: every action answers 403" . ($detail !== '' ? " ({$detail} )" : ''), $allDenied);
}
p155_t('...and no rule was created by any refused call', rule_count('%') === $before);

echo "\n--- CSRF on every POST ---\n";
$r = p155_api('POST', 'create', $admin, ['name' => 'P155 csrf', 'event_type' => 'incident_create', 'channel' => 'email', 'csrf_token' => 'bogus']);
p155_t('a bogus csrf_token -> 403', $r['status'] === 403);
$r = p155_api('POST', 'create', $admin, ['name' => 'P155 csrf', 'event_type' => 'incident_create', 'channel' => 'email', 'csrf_token' => null]);
p155_t('a missing csrf_token -> 403', $r['status'] === 403);
p155_t('...nothing was created', rule_count('P155 csrf') === 0);

echo "\n--- create / get / list ---\n";
$id = p155_create_rule([
    'name' => 'P155 api basic', 'event_type' => 'unit_assign', 'channel' => 'email',
    'recipients' => ['user:' . $admin, 'EMAIL:Ops@Example.invalid', 'p155.bare@example.invalid', 'ops@example.invalid'],
    'subject_template' => 'P155 {incident_type}', 'body_template' => '{street}', 'once_per_incident' => 1,
], $admin);
$resp = $GLOBALS['P155_LAST_API'];
p155_t('Super Admin can create a rule (HTTP 200)', $id > 0 && $resp['status'] === 200);
$row = db_fetch_one("SELECT * FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]);
$stored = json_decode((string) ($row['recipients'] ?? ''), true);
p155_t('recipients are stored in canonical form and de-duplicated case-insensitively',
    $stored === ['user:' . $admin, 'email:Ops@Example.invalid', 'email:p155.bare@example.invalid']);
p155_t('once_per_incident was stored for an event that supports it', (int) ($row['once_per_incident'] ?? 0) === 1);
p155_t('the rule was created enabled', (int) ($row['active'] ?? 0) === 1);
p155_t('create wrote exactly one audit row', p155_audit_count('notification_rule.create', $id) === 1);

$g = p155_api('GET', 'get', $admin, 'id=' . $id);
p155_t('get returns the rule with canonical recipients', ($g['json']['rule']['name'] ?? '') === 'P155 api basic'
    && ($g['json']['rule']['recipients'] ?? []) === $stored);
$l = p155_api('GET', 'list', $admin, '');
$mine = null;
foreach ($l['json']['rules'] ?? [] as $x) if ((int) $x['id'] === $id) $mine = $x;
p155_t('list includes the rule with last_fired_at null and zero 30-day counts',
    $mine !== null && $mine['last_fired_at'] === null && $mine['sent_30d'] === 0 && $mine['failed_30d'] === 0);
p155_t('get on a missing id -> 404', p155_api('GET', 'get', $admin, 'id=987654321')['status'] === 404);

echo "\n--- meta ---\n";
$m = p155_api('GET', 'meta', $admin, '')['json'] ?? [];
$eventIds = array_map(static function ($e) { return $e['id']; }, $m['events'] ?? []);
p155_t('meta lists all seven catalogue events', count($eventIds) === 7 && in_array('unit_assign', $eventIds, true));
$chCodes = array_map(static function ($c) { return $c['code']; }, $m['channels'] ?? []);
p155_t('meta offers email/sms/chat/push/slack/telegram but never the radio channels or "all"',
    count(array_intersect(['email', 'sms', 'local_chat', 'push', 'slack', 'telegram'], $chCodes)) === 6
    && !array_intersect(['aprs', 'dmr', 'meshtastic', 'meshcore', 'all'], $chCodes));
p155_t('meta carries severities, presets (4), placeholders and the settings',
    !empty($m['severities']) && count($m['presets'] ?? []) === 4 && !empty($m['placeholders']) && isset($m['settings']['email_format']));
p155_t('meta reports that once_per_incident is supported on this schema', !empty($m['supports']['once_per_incident']));

echo "\n--- update / toggle / duplicate / delete ---\n";
$u = p155_api('POST', 'update', $admin, ['id' => $id, 'name' => 'P155 api renamed', 'subject_template' => 'P155 changed']);
p155_t('update succeeds and a partial update keeps the other fields',
    $u['status'] === 200 && ($u['json']['rule']['name'] ?? '') === 'P155 api renamed'
    && ($u['json']['rule']['recipients'] ?? []) === $stored && ($u['json']['rule']['event_type'] ?? '') === 'unit_assign');
p155_t('update wrote exactly one audit row', p155_audit_count('notification_rule.update', $id) === 1);
$det = json_decode((string) db_fetch_value("SELECT `details` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'notification_rule.update' AND `target_id` = ? ORDER BY `id` DESC LIMIT 1", [(string) $id]), true);
p155_t('...recording what changed (from -> to)', isset($det['changed']['name']['from'], $det['changed']['name']['to']) && $det['changed']['name']['to'] === 'P155 api renamed');
$t = p155_api('POST', 'toggle', $admin, ['id' => $id, 'active' => false]);
p155_t('toggle off', $t['status'] === 200 && (int) db_fetch_value("SELECT `active` FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]) === 0);
$t = p155_api('POST', 'toggle', $admin, ['id' => $id]);
p155_t('toggle with no value flips it back on', (int) db_fetch_value("SELECT `active` FROM `{$prefix}notification_rules` WHERE `id` = ?", [$id]) === 1);
p155_t('both toggles were audited', p155_audit_count('notification_rule.toggle', $id) === 2);
$d = p155_api('POST', 'duplicate', $admin, ['id' => $id]);
$dupId = (int) ($d['json']['id'] ?? 0);
if ($dupId > 0) $GLOBALS['P155_FIX']['rules'][] = $dupId;
p155_t('duplicate makes a copy, switched OFF, named "(copy)"',
    $dupId > 0 && (int) db_fetch_value("SELECT `active` FROM `{$prefix}notification_rules` WHERE `id` = ?", [$dupId]) === 0
    && strpos((string) db_fetch_value("SELECT `name` FROM `{$prefix}notification_rules` WHERE `id` = ?", [$dupId]), '(copy)') !== false);
$x = p155_api('POST', 'delete', $admin, ['id' => $dupId]);
p155_t('delete removes the row', $x['status'] === 200 && rule_count('P155 api renamed (copy)') === 0);
p155_t('delete was audited (MEDIUM severity)', p155_audit_count('notification_rule.delete', $dupId) === 1
    && (int) db_fetch_value("SELECT `severity` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'notification_rule.delete' AND `target_id` = ?", [(string) $dupId]) === AUDIT_MEDIUM);
p155_t('toggle / update / delete on a missing id -> 404',
    p155_api('POST', 'toggle', $admin, ['id' => 987654321])['status'] === 404
    && p155_api('POST', 'update', $admin, ['id' => 987654321, 'name' => 'x'])['status'] === 404
    && p155_api('POST', 'delete', $admin, ['id' => 987654321])['status'] === 404);

echo "\n--- validation matrix ---\n";
$before = rule_count('%');
$base = ['name' => 'P155 val', 'event_type' => 'incident_create', 'channel' => 'email', 'recipients' => ['email:a@example.invalid']];
$cases = [
    'an unknown event'                    => ['event_type' => 'bogus_event'],
    'an empty event'                      => ['event_type' => ''],
    'an unknown channel'                  => ['channel' => 'carrier_pigeon'],
    'an empty name'                       => ['name' => '   '],
    'a name longer than 100'              => ['name' => 'P155 ' . str_repeat('x', 100)],
    'an SMS rule that names only emails'  => ['channel' => 'sms', 'recipients' => ['email:a@example.invalid']],
    'an email rule that names a phone'    => ['channel' => 'email', 'recipients' => ['tel:+15551234567']],
    'a chat rule that names an email'     => ['channel' => 'local_chat', 'recipients' => ['email:a@example.invalid']],
    'a Slack rule with recipients'        => ['channel' => 'slack', 'recipients' => ['email:a@example.invalid']],
    'an email list on an SMS rule'        => ['channel' => 'sms', 'recipients' => [], 'email_list_id' => 1],
    'a list that does not exist'          => ['email_list_id' => 987654321],
    'a recipient that parses to nothing'  => ['recipients' => ['not a thing']],
    'a user that does not exist'          => ['recipients' => ['user:987654321']],
    'a severity that does not exist'      => ['severity_filter' => 99],
    'an incident type that does not exist' => ['incident_type_filter' => 987654],
    'a subject over 255'                  => ['subject_template' => str_repeat('s', 300)],
    'a message over 4000'                 => ['body_template' => str_repeat('b', 4001)],
];
$refused = 0; $bad = [];
foreach ($cases as $label => $over) {
    $r = p155_api('POST', 'create', $admin, $over + $base);
    if ($r['status'] === 422 && !empty($r['json']['error']) && ($r['json']['code'] ?? '') === 'validation') $refused++;
    else $bad[] = $label . '=' . $r['status'];
}
p155_t('every invalid rule is refused with HTTP 422 + a message (' . implode(', ', $bad) . ')', $refused === count($cases));
p155_t('...and none was stored', rule_count('%') === $before);

$tooMany = [];
for ($i = 1; $i <= 101; $i++) $tooMany[] = 'email:p155.many' . $i . '@example.invalid';
$r = p155_api('POST', 'create', $admin, ['recipients' => $tooMany] + $base);
p155_t('101 recipients -> refused (use a list)', $r['status'] === 422);

// The rule-count ceiling. 200 rows are inserted directly - they are only filler to reach the limit;
// the CREATE under test goes through the real endpoint.
for ($i = 1; $i <= 200; $i++) {
    db_query("INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`) VALUES (?, 'incident_create', 'email', '[]', 0)", ['P155 filler ' . $i]);
}
$r = p155_api('POST', 'create', $admin, ['name' => 'P155 over the limit'] + $base);
p155_t('the 201st rule is refused (limit 200)', $r['status'] === 409 && ($r['json']['code'] ?? '') === 'limit');
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 filler %'");

echo "\n--- normalisation and warnings (saved, but the administrator is told) ---\n";
$wid = p155_create_rule(['name' => 'P155 warn', 'event_type' => 'has_broadcast', 'channel' => 'email',
    'recipients' => ['email:w@example.invalid'], 'severity_filter' => 1, 'incident_type_filter' => 1, 'once_per_incident' => 1,
    'subject_template' => "P155 line one\nline two", 'body_template' => 'hello {nonsense_token}'], $admin);
$wr = $GLOBALS['P155_LAST_API']['json'] ?? [];
$wrow = db_fetch_one("SELECT * FROM `{$prefix}notification_rules` WHERE `id` = ?", [$wid]);
p155_t('a no-incident event stores NULL filters (they cannot apply)', $wrow && $wrow['severity_filter'] === null && $wrow['incident_type_filter'] === null);
p155_t('...and once_per_incident is switched off where it does not apply', $wrow && (int) $wrow['once_per_incident'] === 0);
p155_t('a multi-line subject is collapsed to one line', $wrow && strpos((string) $wrow['subject_template'], "\n") === false);
$w = implode(' | ', $wr['warnings'] ?? []);
p155_t('warnings say so: filters ignored, once-per-incident off, subject collapsed, unknown placeholder',
    stripos($w, 'filter') !== false && stripos($w, 'first time') !== false && stripos($w, 'one line') !== false && stripos($w, 'nonsense_token') !== false);

$sid = p155_create_rule(['name' => 'P155 sms warn', 'channel' => 'sms', 'recipients' => ['user:' . $noPhone, 'user:' . $withPhone]], $admin);
$sw = implode(' | ', $GLOBALS['P155_LAST_API']['json']['warnings'] ?? []);
p155_t('an SMS rule naming a user with NO mobile number warns about exactly that user',
    $sid > 0 && stripos($sw, 'p155_nophone') !== false && stripos($sw, 'p155_withphone') === false);
$hid = p155_create_rule(['name' => 'P155 no recipients', 'recipients' => []], $admin);
p155_t('a rule with no recipients warns it will never notify anyone',
    stripos(implode(' ', $GLOBALS['P155_LAST_API']['json']['warnings'] ?? []), 'never notify') !== false);
$slackRule = p155_create_rule(['name' => 'P155 slack ok', 'channel' => 'slack', 'recipients' => []], $admin);
p155_t('a Slack rule needs no recipients and saves', $slackRule > 0);
$slackWarn = implode(' ', $GLOBALS['P155_LAST_API']['json']['warnings'] ?? []);
p155_t('...and warns when Slack is not set up on this install, naming the panel by its sidebar label', stripos($slackWarn, 'Slack is not set up yet') !== false
    && stripos($slackWarn, 'open Slack under Communications and Integrations in Settings') !== false);
$smsWarnId = p155_create_rule(['name' => 'P155 sms unset', 'channel' => 'sms', 'recipients' => ['tel:+15551230000']], $admin);
p155_t('...an unconfigured SMS channel names "SMS Configuration", never the internal tab id (sms-config)',
    $smsWarnId > 0 && stripos(implode(' ', $GLOBALS['P155_LAST_API']['json']['warnings'] ?? []), 'open SMS Configuration') !== false
    && stripos(implode(' ', $GLOBALS['P155_LAST_API']['json']['warnings'] ?? []), 'sms-config') === false);

echo "\n--- preview sends nothing and writes nothing ---\n";
$capture = sys_get_temp_dir() . '/p155_capture_' . getmypid() . '.jsonl';
@unlink($capture);
p155_on_cleanup("DELETE FROM `{$prefix}notification_log` WHERE `subject` LIKE '[TEST] %'");
$logBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log`");
$qBefore = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}pending_routed_messages`");
$pv = p155_api('POST', 'preview', $admin, [
    'name' => 'P155 preview', 'event_type' => 'unit_assign', 'channel' => 'sms',
    'recipients' => ['user:' . $noPhone, 'user:' . $withPhone, 'tel:+15551230000', 'email:x@example.invalid'],
    'subject_template' => '', 'body_template' => '{incident_type|clean};{street|clean}', 'sample' => 'synthetic',
], ['capture_file' => $capture]);
$pj = $pv['json'] ?? [];
p155_t('preview answers 200 and renders the sample text', $pv['status'] === 200 && ($pj['rendered']['body'] ?? '') === 'Structure Fire;123 Main St');
$byAddr = [];
foreach ($pj['deliveries'] ?? [] as $dv) $byAddr[$dv['address']] = $dv;
p155_t('preview predicts the per-recipient result: the user with a phone and the typed number are queued',
    ($byAddr['5552223333']['status'] ?? '') === 'queued' && ($byAddr['+15551230000']['status'] ?? '') === 'queued');
p155_t('...the user with no mobile number is predicted skipped with the reason',
    isset($pj['deliveries']) && count(array_filter($pj['deliveries'], static function ($d) { return $d['status'] === 'skipped' && stripos($d['reason'], 'no mobile number') !== false; })) === 1);
p155_t('...the email address on an SMS rule is predicted skipped', count(array_filter($pj['deliveries'] ?? [], static function ($d) { return $d['status'] === 'skipped' && stripos($d['reason'], 'not a phone number') !== false; })) === 1);
p155_t('PREVIEW WROTE NOTHING: notification_log unchanged', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log`") === $logBefore);
p155_t('...and the delivery queue unchanged', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}pending_routed_messages`") === $qBefore);
p155_t('...and nothing was sent', !is_file($capture) || trim((string) file_get_contents($capture)) === '');

echo "\n--- test-send: safe by default ---\n";
$tsLogFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}notification_log`");   // count only rows THIS run writes
$ts = p155_api('POST', 'test_send', $admin, ['name' => 'P155 ts', 'event_type' => 'incident_create', 'channel' => 'email',
    'recipients' => ['email:real.pager@example.invalid', 'email:other.person@example.invalid']], ['capture_file' => $capture]);
$lines = is_file($capture) ? array_filter(array_map('trim', file($capture))) : [];
$sent = array_map(static function ($l) { return json_decode($l, true); }, $lines);
p155_t('default test-send succeeds', $ts['status'] === 200 && !empty($ts['json']['ok']));
p155_t('...and goes ONLY to the administrator\'s own address, not the rule\'s recipients',
    count($sent) === 1 && strtolower((string) $sent[0]['to']) === strtolower((string) db_fetch_value("SELECT `email` FROM `{$prefix}user` WHERE `id` = ?", [$admin])));
p155_t('...with [TEST] on the subject and the message', strpos((string) $sent[0]['subject'], '[TEST] ') === 0 && strpos((string) $sent[0]['body'], '[TEST] ') === 0);
p155_t('...logged in the delivery log as sent', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `id` > ? AND `subject` LIKE '[TEST] %' AND `status` = 'sent'", [$tsLogFloor]) === 1);
p155_t('...and audited', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `activity` = 'notification_rule.test_send' AND `id` > ?", [$auditFloor]) === 1);

@unlink($capture);
$real = p155_api('POST', 'test_send', $admin, ['name' => 'P155 ts', 'event_type' => 'incident_create', 'channel' => 'email',
    'mode' => 'rule', 'recipients' => ['email:real.pager@example.invalid']], ['capture_file' => $capture]);
p155_t('mode "rule" WITHOUT confirm_real is refused (409) and sends nothing', $real['status'] === 409
    && ($real['json']['code'] ?? '') === 'confirm_required' && (!is_file($capture) || trim((string) file_get_contents($capture)) === ''));
$real = p155_api('POST', 'test_send', $admin, ['name' => 'P155 ts', 'event_type' => 'incident_create', 'channel' => 'email',
    'mode' => 'rule', 'confirm_real' => true, 'recipients' => ['email:real.pager@example.invalid']], ['capture_file' => $capture]);
$lines = is_file($capture) ? array_filter(array_map('trim', file($capture))) : [];
p155_t('with confirm_real it goes to the rule\'s real recipient', $real['status'] === 200
    && count($lines) === 1 && strpos((string) $lines[0], 'real.pager@example.invalid') !== false);
@unlink($capture);
$shared = p155_api('POST', 'test_send', $admin, ['name' => 'P155 ts', 'event_type' => 'incident_create', 'channel' => 'slack', 'recipients' => []], ['capture_file' => $capture]);
p155_t('a shared channel (Slack) cannot be redirected, so it ALWAYS needs confirmation', $shared['status'] === 409);

// throttle: the audit table counts this administrator's sends; two have been made so far
$statuses = [];
for ($i = 0; $i < 5; $i++) {
    $r = p155_api('POST', 'test_send', $admin, ['name' => 'P155 ts', 'event_type' => 'incident_create', 'channel' => 'email', 'recipients' => []], ['capture_file' => $capture]);
    $statuses[] = $r['status'];
}
p155_t('test-send is throttled (5 per 5 minutes): the later calls get HTTP 429', in_array(429, $statuses, true));
p155_t('...after exactly five were allowed', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE `activity` = 'notification_rule.test_send' AND `id` > ?", [$auditFloor]) === 5);

echo "\n--- delivery settings ---\n";
$bad1 = p155_api('POST', 'save_settings', $admin, ['email_format' => 'rtf']);
$bad2 = p155_api('POST', 'save_settings', $admin, ['prefs_mode' => 'everybody']);
$bad3 = p155_api('POST', 'save_settings', $admin, ['retention_days' => -5]);
p155_t('an invalid format / mode / retention is refused (422)', $bad1['status'] === 422 && $bad2['status'] === 422 && $bad3['status'] === 422);
$ok = p155_api('POST', 'save_settings', $admin, ['email_format' => 'html', 'prefs_mode' => 'defaults_apply', 'retention_days' => 30]);
p155_t('valid settings save', $ok['status'] === 200 && ($ok['json']['settings']['email_format'] ?? '') === 'html');
p155_t('...and are READ back by the engine (uncached reader)', notification_setting('notification_email_format') === 'html'
    && notification_setting('notification_prefs_mode') === 'defaults_apply' && notification_setting('notification_log_retention_days') === '30');
$sd = json_decode((string) db_fetch_value("SELECT `details` FROM `{$prefix}newui_audit_log` WHERE `activity` = 'notification_rule.settings' ORDER BY `id` DESC LIMIT 1"), true);
p155_t('...the audit row records old -> new', isset($sd['changed']['email_format']['from'], $sd['changed']['email_format']['to']) && $sd['changed']['email_format']['to'] === 'html');
p155_api('POST', 'save_settings', $admin, ['email_format' => 'text', 'prefs_mode' => 'explicit_only', 'retention_days' => 180]);

echo "\n--- delivery log + queue ---\n";
$lg = p155_api('GET', 'log', $admin, 'limit=5');
p155_t('the log endpoint answers with rows + a total', $lg['status'] === 200 && isset($lg['json']['rows'], $lg['json']['total']));
p155_t('log filters by status', p155_api('GET', 'log', $admin, 'status=sent')['status'] === 200
    && count(array_filter(p155_api('GET', 'log', $admin, 'status=failed')['json']['rows'] ?? [], static function ($r) { return $r['status'] !== 'failed'; })) === 0);
$q = p155_api('GET', 'queue', $admin, '');
p155_t('the queue endpoint reports scheduler state and depth', $q['status'] === 200 && isset($q['json']['scheduler_live'], $q['json']['all_pending']));

echo "\n--- recipient picker (action=users) ---\n";
$u1 = p155_make_user(900155311, 'p155_picker_alpha', 'p155.alpha.secret@example.invalid', '5557771111', null, []);
$u2 = p155_make_user(900155312, 'p155_picker_beta', null, null, null, []);
$u3 = p155_make_user(900155313, 'p155_picker%wild', 'p155.wild@example.invalid', null, null, []);
$sr = p155_api('GET', 'users', $admin, 'q=p155_picker_a');
$names = array_map(static function ($u) { return $u['login']; }, $sr['json']['users'] ?? []);
p155_t('searching by login finds the account', $sr['status'] === 200 && in_array('p155_picker_alpha', $names, true));
$alpha = null; foreach ($sr['json']['users'] ?? [] as $u) if ($u['id'] === 900155311) $alpha = $u;
p155_t('...and says whether it can be reached by email and by SMS', $alpha !== null && $alpha['has_email'] === true && $alpha['has_mobile'] === true);
p155_t('...but NEVER returns the address or the number itself (the page needs the fact, not the data)',
    strpos($sr['raw'], 'p155.alpha.secret@example.invalid') === false && strpos($sr['raw'], '5557771111') === false);
$byIds = p155_api('GET', 'users', $admin, 'ids=900155311,900155312');
$flags = []; foreach ($byIds['json']['users'] ?? [] as $u) $flags[$u['id']] = [$u['has_email'], $u['has_mobile']];
p155_t("looking up known ids labels a saved rule's chips, with an account that has no address flagged as such",
    ($flags[900155311] ?? null) === [true, true] && ($flags[900155312] ?? null) === [false, false]);
$wild = p155_api('GET', 'users', $admin, 'q=' . rawurlencode('p155_picker%'));
$wildLogins = array_map(static function ($u) { return $u['login']; }, $wild['json']['users'] ?? []);
p155_t('LIKE wildcards in the query are escaped: "p155_picker%" matches the account containing a literal %, not every p155_picker account',
    $wildLogins === ['p155_picker%wild']);
p155_t('an empty query returns nobody (the endpoint never dumps every account)', (p155_api('GET', 'users', $admin, 'q=')['json']['users'] ?? ['x']) === []);
p155_t('a result list is capped (at most 25)', count(p155_api('GET', 'users', $admin, 'q=p')['json']['users'] ?? []) <= 25);

echo "\n--- hygiene ---\n";
p155_t('no empty catch block in the admin include', !preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $adm));
p155_t('no driver text reaches a response (getMessage never feeds _nra_fail)', !preg_match('/_nra_fail\([^;]*getMessage\(\)/s', $adm));

@unlink($capture);
p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)', rule_count('%') === 0);
p155_done();
