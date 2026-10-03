<?php
/**
 * Phase 155 (GH#144) - REGRESSION for the recipient defects D7-D11.
 *
 *   D7  email-list recipients never resolved (see test_email_list_notification_engine.php)
 *   D8  an SMS rule given a USER was handed the user's EMAIL as the phone number;
 *       chat got the email instead of a user id
 *   D9  a typed phone number made of digits ("5551234567") passed is_numeric(), was
 *       treated as USER ID 5551234567, found no such user, and was dropped in silence
 *   D10 `user.phone_m` (the only number the engine read) is written by no screen:
 *       every user resolved a NULL number on every install
 *   D11 default preferences were "SMS off" and notification_preferences has no writer,
 *       so an administrator naming a person for SMS got silence - and a skip wrote no
 *       log row, so nothing anywhere said why
 *
 * Also the PREFERENCE MATRIX for the two modes (the notification_prefs_mode setting
 * is a CONTROL: flipping it changes the outcome for the same user), the high-alert
 * bypass (quiet hours and opt-outs never silence a life-safety callout), and the
 * rule that EVERY skip is logged with a reason.
 *
 * Rules are created through the REAL api/notification-rules.php; delivery goes
 * through a capturing stub; the events are fired by the real engine entry points.
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

require_once __DIR__ . '/../inc/notification-engine.php';
p155_install_cleanup();
p155_install_capture();
p155_scheduler(true);
p155_remember_setting('notification_prefs_mode');
p155_remember_setting('notification_email_format');
p155_on_cleanup("DELETE FROM `{$prefix}messages` WHERE `subject` LIKE 'P155%'");

echo "=== Phase 155 / GH#144 - recipients: addresses per channel, preferences, skips (D7-D11) ===\n\n";
$admin = test_admin_user_id();

echo "--- D9: parsing a recipient entry (pure) ---\n";
$cases = [
    ['user:12',               ['kind' => 'user', 'user_id' => 12]],
    [12,                      ['kind' => 'user', 'user_id' => 12]],
    ['12',                    ['kind' => 'user', 'user_id' => 12]],
    ['5551234567',            ['kind' => 'tel', 'address' => '5551234567']],      // ten digits: a PHONE, not user 5551234567
    ['tel:5551234567',        ['kind' => 'tel', 'address' => '5551234567']],
    ['tel:+1 (555) 123-4567', ['kind' => 'tel', 'address' => '+15551234567']],
    ['555-123-4567',          ['kind' => 'tel', 'address' => '5551234567']],
    ['email:Ops@Example.org', ['kind' => 'email', 'address' => 'Ops@Example.org']],
    ['ops@example.org',       ['kind' => 'email', 'address' => 'ops@example.org']],
];
foreach ($cases as [$in, $want]) {
    p155_t('parse ' . json_encode($in) . ' -> ' . json_encode($want), _notification_parse_recipient_entry($in) === $want);
}
foreach (['', '   ', 'not a thing', 'user:0', 'user:abc', 'email:not-an-email', 'tel:12', 'tel:5551234567890123456', 0, -4, 3.5, null, true, ['a']] as $bad) {
    p155_t('parse ' . json_encode($bad) . ' -> null (reported as invalid, never guessed at)', _notification_parse_recipient_entry($bad) === null);
}
p155_t('a phone number may not carry URL/injection characters (it is pasted into an SMS provider request)',
    notification_normalize_phone('5551234&x=1') === null && notification_normalize_phone('5551234567#') === null
    && notification_normalize_phone("5551234567\r\nX: y") === null && notification_normalize_phone('555123') === null);

echo "\n--- D8/D10: the address FOR THE CHANNEL, and the phone fallback chain ---\n";
$uBoth   = p155_make_user(900155401, 'p155_both', 'p155.both@example.invalid', '5551110001', '5551110002');
$uPrimary = p155_make_user(900155402, 'p155_primary', 'p155.primary@example.invalid', null, '(555) 111-0003');
$uRoster = p155_make_user(900155403, 'p155_roster', 'p155.roster@example.invalid', null, null);
$uNone   = p155_make_user(900155404, 'p155_none', null, null, null);
$rosterMember = p155_make_member('P155R', 'Cell', 'p155.rostermail@example.invalid', null, $uRoster, '', '5551110004');
$c = notification_user_contacts([$uBoth, $uPrimary, $uRoster, $uNone, 987654321]);
p155_t('phone: phone_m is preferred', ($c[$uBoth]['phone'] ?? '') === '5551110001');
p155_t('phone: falls back to phone_p (the only number the profile editor writes), normalised', ($c[$uPrimary]['phone'] ?? '') === '5551110003');
p155_t('phone: falls back to the linked roster member\'s cell number', ($c[$uRoster]['phone'] ?? '') === '5551110004');
p155_t('phone: a user with none resolves to an empty string (and is skipped later, with a reason)', ($c[$uNone]['phone'] ?? 'x') === '');
p155_t('email: falls back to the roster member\'s email when the account has none', ($c[$uNone]['email'] ?? 'x') === '');
p155_t('a user id that does not exist is simply absent', !isset($c[987654321]));

// A chat/SMS/email/push rule each naming the same user: what does each channel get?
$tid = (int) p155_make_incident(['scope' => 'P155 recipients'])['id'];
$mk = static function (string $channel, array $recipients) use ($admin) {
    return p155_create_rule(['name' => 'P155 rec ' . $channel, 'event_type' => 'incident_create', 'channel' => $channel, 'recipients' => $recipients,
        'subject_template' => 'P155 {scope}', 'body_template' => 'P155 body'], $admin);
};
$drop = static function (): void {
    global $prefix;
    db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 rec %'");
    p155_reset_deliveries();
};
$fire = static function () use ($tid): array {
    return notification_check('incident_create', ['ticket_id' => $tid]);
};

$mk('sms', ['user:' . $uBoth]);
$res = $fire();
p155_t('SMS rule + a USER -> the message goes to the user\'s PHONE (it used to go to their email)', p155_sent_to('sms') === ['5551110001']);
$drop();
$mk('email', ['user:' . $uBoth]);
$fire();
p155_t('email rule + a user -> the user\'s EMAIL', p155_sent_to('email') === ['p155.both@example.invalid']);
$drop();
$mk('local_chat', ['user:' . $uBoth]);
$fire();
p155_t('chat rule + a user -> the user ID (it used to get the email address)', p155_sent_to('local_chat') === [(string) $uBoth]);
$drop();
$mk('push', ['user:' . $uBoth]);
$fire();
// Any push send whose user list is exactly this user. NOT "the first send captured": the flush that
// $fire() runs also replays whatever other delivery is due in the shared queue (a retry left by an
// earlier test file's deliberately failing delivery), and that stale row can come first - the
// assertion then failed only inside the full suite, never alone. What this proves is unchanged: the
// push rule hands push.php the user id list it reads.
$pushHit = null;
foreach (($GLOBALS['P155_SENT'] ?? []) as $__s) {
    if (($__s['channel'] ?? '') === 'push' && ($__s['uids'] ?? null) === [$uBoth]) { $pushHit = $__s; break; }
}
$push = $pushHit;
p155_t('push rule + a user -> the user id list push.php actually reads (_recipient_user_ids)', $push !== null);
$diagOut = '';
if ($push === null) {
    // Collected here, printed at the very end of the file so a runner that shows only the tail of a
    // failing file's output still shows it.
    $diagOut .= "  DIAG push capture: " . json_encode($GLOBALS['P155_SENT'] ?? null) . "
";
    $diagOut .= "  DIAG expected user id: " . (int) $uBoth . "
";
    $diagOut .= "  DIAG breaker: " . (string) db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'notify_rule_breaker' LIMIT 1") . "
";
    $diagOut .= "  DIAG last log rows: " . json_encode(db_fetch_all("SELECT `channel`, `status`, `recipient`, LEFT(`error`, 90) AS `error` FROM `{$prefix}notification_log` ORDER BY `id` DESC LIMIT 4")) . "
";
}
$drop();

echo "\n--- D9 end to end: typed phone numbers reach the SMS channel ---\n";
// A user who exists when the rule is saved and is deleted afterwards (the API refuses a user that does not exist).
$uGone = p155_make_user(900155405, 'p155_gone', 'p155.gone@example.invalid', '5553330001', null);
$ruleId = $mk('sms', ['tel:5551234567', 'user:' . $uGone]);
// A LEGACY hand-made row can also hold the bare 10-digit form - the API would write tel:, but rows made by hand exist.
db_query("UPDATE `{$prefix}notification_rules` SET `recipients` = ? WHERE `id` = ?", [json_encode(['tel:5551234567', '5559876543', 'user:' . $uGone]), $ruleId]);
db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$uGone]);
$fire();
$sent = p155_sent_to('sms');
p155_t('a 10-digit number (canonical tel: OR legacy bare) is a phone and is texted (it was mistaken for a user id and dropped)',
    in_array('5551234567', $sent, true) && in_array('5559876543', $sent, true));
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `recipient` = ? AND `status` = 'skipped' ORDER BY `id` DESC LIMIT 1", ['user:' . $uGone]);
p155_t('a user deleted after the rule was saved is skipped, and the skipped row says so', stripos($why, 'no longer exists') !== false);
$drop();

echo "\n--- every skip is LOGGED with a reason (D10/D11) ---\n";
$ruleId = $mk('sms', ['user:' . $uNone, 'user:' . $uBoth]);
// The API refuses an email address on an SMS rule; a hand-made legacy row can hold one. The engine must skip it WITH a reason.
db_query("UPDATE `{$prefix}notification_rules` SET `recipients` = ? WHERE `id` = ?", [json_encode(['user:' . $uNone, 'someone@example.invalid', 'user:' . $uBoth]), $ruleId]);
$fire();
$rows = db_fetch_all("SELECT `recipient`, `status`, `error` FROM `{$prefix}notification_log` WHERE `rule_id` IN (SELECT `id` FROM `{$prefix}notification_rules` WHERE `name` = 'P155 rec sms')");
$byRecipient = [];
foreach ($rows as $r) $byRecipient[$r['recipient']] = $r;
p155_t('the user with no mobile number: a skipped row, reason "no mobile number"', ($byRecipient['user:' . $uNone]['status'] ?? '') === 'skipped'
    && stripos((string) ($byRecipient['user:' . $uNone]['error'] ?? ''), 'no mobile number') !== false);
p155_t('an email address on an SMS rule: a skipped row, reason "not a phone number"', ($byRecipient['someone@example.invalid']['status'] ?? '') === 'skipped'
    && stripos((string) ($byRecipient['someone@example.invalid']['error'] ?? ''), 'not a phone number') !== false);
p155_t('the user who CAN be texted: queued then sent', ($byRecipient['5551110001']['status'] ?? '') === 'sent');
p155_t('NO skipped row has an empty reason', count(array_filter($rows, static function ($r) { return $r['status'] === 'skipped' && trim((string) $r['error']) === ''; })) === 0);
$drop();
$mk('email', ['user:' . $uNone]);
$fire();
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `status` = 'skipped' AND `recipient` = ? ORDER BY `id` DESC LIMIT 1", ['user:' . $uNone]);
p155_t('an email rule naming a user with no email: skipped with "no email address on file"', stripos($why, 'no email address') !== false);
$drop();

echo "\n--- preference matrix: explicit_only (default) vs defaults_apply ---\n";
$userSms = p155_make_user(900155411, 'p155_prefs', 'p155.prefs@example.invalid', '5552220001', null);
$smsTo = '5552220001';
$prefRule = static function () use ($mk, $userSms) { return $mk('sms', ['user:' . $userSms]); };
$count = static function () use ($smsTo): int { return count(array_filter(p155_sent_to('sms'), static function ($t) use ($smsTo) { return $t === $smsTo; })); };

p155_set_setting('notification_prefs_mode', 'explicit_only');
$prefRule();
$fire();
p155_t('explicit_only, NO saved preference row: the administrator naming you IS consent - the SMS is sent', $count() === 1);
$drop();

p155_set_setting('notification_prefs_mode', 'defaults_apply');
$prefRule();
$fire();
p155_t('CONTROL defaults_apply, same user, same rule: SMS defaults to OFF - nothing is sent', $count() === 0);
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `status` = 'skipped' AND `recipient` = ? ORDER BY `id` DESC LIMIT 1", [$smsTo]);
p155_t('...and the log SAYS why (the old engine skipped in silence)', stripos($why, 'not enabled SMS') !== false);
$drop();

db_query("INSERT INTO `{$prefix}notification_preferences` (`user_id`, `channel_email`, `channel_sms`, `channel_chat`) VALUES (?, 1, 0, 1)", [$userSms]);
p155_set_setting('notification_prefs_mode', 'explicit_only');
$prefRule();
$fire();
p155_t('explicit_only WITH a saved opt-out (SMS off): the saved preference is honoured - nothing is sent', $count() === 0);
$drop();

db_query("UPDATE `{$prefix}notification_preferences` SET `channel_sms` = 1 WHERE `user_id` = ?", [$userSms]);
$prefRule();
$fire();
p155_t('a saved preference that turns SMS ON: sent', $count() === 1);
$drop();

db_query("UPDATE `{$prefix}notification_preferences` SET `quiet_start` = '00:00:00', `quiet_end` = '23:59:59' WHERE `user_id` = ?", [$userSms]);
$prefRule();
$fire();
p155_t('quiet hours (an all-day window) are honoured for a normal incident - nothing is sent', $count() === 0);
$why = (string) db_fetch_value("SELECT `error` FROM `{$prefix}notification_log` WHERE `status` = 'skipped' AND `recipient` = ? ORDER BY `id` DESC LIMIT 1", [$smsTo]);
p155_t('...logged as quiet hours', stripos($why, 'quiet hours') !== false);
$drop();

echo "\n--- the high-alert bypass: a life-safety callout is never silenced ---\n";
// quiet hours still all-day, channel_sms still 1; now opt out of SMS too
db_query("UPDATE `{$prefix}notification_preferences` SET `channel_sms` = 0 WHERE `user_id` = ?", [$userSms]);
$tidHigh = (int) p155_make_incident(['scope' => 'P155 high alert', 'severity' => 2])['id'];   // 2 = Critical = is_high_alert on a fresh install
$mk('sms', ['user:' . $userSms]);
notification_check('incident_create', ['ticket_id' => $tidHigh]);
p155_t('a HIGH-ALERT incident is delivered despite an SMS opt-out AND all-day quiet hours', $count() === 1);
$drop();
db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 rec %'");
p155_create_rule(['name' => 'P155 rec sev', 'event_type' => 'severity_high', 'channel' => 'sms', 'recipients' => ['user:' . $userSms]], $admin);
notification_check('severity_high', ['ticket_id' => $tid]);   // the event itself bypasses, whatever the incident's level
p155_t('the severity_high EVENT bypasses preferences whatever the incident\'s own level', $count() === 1);
$drop();
$mk('sms', ['user:' . $userSms]);
notification_check('incident_create', ['ticket_id' => $tid]);
p155_t('CONTROL: the same user and rule for a NORMAL incident is still silenced', $count() === 0);
$drop();

echo "\n--- email preference applies to the email channel only ---\n";
db_query("UPDATE `{$prefix}notification_preferences` SET `channel_sms` = 1, `channel_email` = 0, `quiet_start` = NULL, `quiet_end` = NULL WHERE `user_id` = ?", [$userSms]);
$mk('email', ['user:' . $userSms]);
$fire();
p155_t('an email opt-out silences an EMAIL rule', p155_sent_to('email') === []);
$drop();
$mk('sms', ['user:' . $userSms]);
$fire();
p155_t('...but not an SMS rule', $count() === 1);
$drop();

echo "\n--- hygiene ---\n";
$src = file_get_contents(__DIR__ . '/../inc/notification-engine.php');
p155_t('the engine no longer uses is_numeric() to decide a recipient is a user id',
    !preg_match('/is_numeric\(\s*\$entry\s*\)/', php_strip_whitespace(__DIR__ . '/../inc/notification-engine.php')));
p155_t('no empty catch block in the engine', !preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src)));

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user` WHERE `id` >= 900155000") === 0);
if ($diagOut !== '') echo "
" . $diagOut;
p155_done();
