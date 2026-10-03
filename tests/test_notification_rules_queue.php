<?php
/**
 * Phase 155 (GH#144 Phase B) - the delivery QUEUE for Notification Rules.
 *
 * WHAT THIS DEFENDS AGAINST. The engine used to send inside the dispatcher's own
 * request: one blocking SMTP session per recipient. A 100-address list and a dead
 * mail relay is minutes of a held PHP worker for a single "create incident" - the
 * stall inc/notify-fanout.php measured and removed for webhooks and push on
 * 2026-07-31. Rule deliveries now go to a queue (pending_routed_messages, channel
 * _notify_rule), one notification_log row each, and a sweep replays them.
 *
 * Every scenario drives the REAL path: a rule is created through the real
 * api/notification-rules.php, the real writer fires the real event, and the real
 * sweep (pending_sweep -> notification_delivery_process_row) replays it. The only
 * test double is the channel adapter, and only where a deterministic failure is
 * needed - the black-holed relay section uses the REAL SMTP adapter.
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
try { db_fetch_value("SELECT queue_id FROM `{$prefix}notification_log` LIMIT 1"); }
catch (Throwable $e) { p155_skip('Phase 155 schema missing - run php sql/run_migrations.php'); }

require_once __DIR__ . '/../inc/notification-engine.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/security-labels.php';
require_once __DIR__ . '/../inc/email-list-write.php';

p155_install_cleanup();
$auditFloor = (int) db_fetch_value("SELECT COALESCE(MAX(`id`), 0) FROM `{$prefix}newui_audit_log`");
p155_on_cleanup("DELETE FROM `{$prefix}newui_audit_log` WHERE `id` > ? AND (`activity` LIKE 'notification_rule.%' OR `summary` LIKE '%P155%' OR `target_type` = 'pending_message')", [$auditFloor]);
foreach (['notify_rule_breaker', 'notification_log_retention_days', 'email_mode', 'smtp_host', 'smtp_port', 'smtp_encryption'] as $s) p155_remember_setting($s);
notification_breaker_reset();

// Rules left active by anything else must not fire during this file, and the
// "no rules" probe at the end needs there to be none. Restored by id at cleanup.
$activeBefore = array_map('intval', array_column(db_fetch_all("SELECT `id` FROM `{$prefix}notification_rules` WHERE `active` = 1 AND `name` NOT LIKE 'P155%'"), 'id'));
if ($activeBefore) {
    db_query("UPDATE `{$prefix}notification_rules` SET `active` = 0 WHERE `id` IN (" . implode(',', $activeBefore) . ")");
    p155_on_cleanup("UPDATE `{$prefix}notification_rules` SET `active` = 1 WHERE `id` IN (" . implode(',', $activeBefore) . ")");
}

echo "=== Phase 155 / GH#144 - the delivery queue ===\n\n";
$admin = test_admin_user_id();

function q_rule(array $over = []): int
{
    global $admin;
    return p155_create_rule($over + ['name' => 'P155 queue rule', 'event_type' => 'incident_create', 'channel' => 'email',
        'recipients' => ['email:q1@example.invalid', 'email:q2@example.invalid', 'email:q3@example.invalid'],
        'subject_template' => 'P155 queue #{ticket_id}', 'body_template' => 'P155 queue body'], $admin);
}
function q_drop(): void
{
    global $prefix;
    db_query("DELETE FROM `{$prefix}notification_log` WHERE `rule_id` IN (SELECT `id` FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 queue%')");
    db_query("DELETE FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155 queue%'");
    p155_reset_deliveries();
    notification_breaker_reset();
    $GLOBALS['P155_Q_FAIL'] = [];
}
function q_logs(int $tid): array
{
    global $prefix;
    return db_fetch_all("SELECT * FROM `{$prefix}notification_log` WHERE `ticket_id` = ? ORDER BY `id`", [$tid]);
}
function q_queue(int $tid, string $status = 'pending'): array
{
    global $prefix;
    return db_fetch_all("SELECT * FROM `{$prefix}pending_routed_messages` WHERE `ticket_id` = ? AND `channel` = ? AND `status` = ? ORDER BY `id`",
        [$tid, NOTIFY_RULE_CHANNEL, $status]);
}
function q_count(array $rows, string $status): int
{
    $n = 0;
    foreach ($rows as $r) if ($r['status'] === $status) $n++;
    return $n;
}
function q_sent_count(string $channel): int
{
    return count(p155_sent_to($channel));
}

// ─────────────────────────────────────────────────────────────────────────
// A black-holed relay, with the REAL SMTP adapter (nothing captured yet)
// ─────────────────────────────────────────────────────────────────────────
echo "--- a dead mail relay (RFC 5737 TEST-NET-3, the real SMTP adapter) ---\n";
foreach (['email_mode' => 'smtp', 'smtp_host' => '203.0.113.1', 'smtp_port' => '25', 'smtp_encryption' => 'none'] as $k => $v) p155_set_setting($k, $v);
$five = ['email:bh1@example.invalid', 'email:bh2@example.invalid', 'email:bh3@example.invalid', 'email:bh4@example.invalid', 'email:bh5@example.invalid'];

p155_scheduler(true);
q_rule(['name' => 'P155 queue blackhole', 'channel' => 'smtp', 'recipients' => $five]);
$t0 = microtime(true);
$res = p155_make_incident(['scope' => 'P155 queue blackhole live']);
$elapsedLive = microtime(true) - $t0;
$tidBh = (int) $res['id'];
// The proof that the dispatch path made no network attempt is NOT the clock (this
// suite runs on a shared, busy machine): an attempt against the relay would have
// left a failure on the channel's breaker and a "retrying" note on a log row.
$bhNoAttempt = !isset(notification_breaker_status()['smtp']);
foreach (q_logs($tidBh) as $l) if (strpos((string) $l['error'], 'retrying') !== false) $bhNoAttempt = false;
p155_t('scheduler LIVE: creating an incident that notifies 5 people through a dead relay made NO delivery attempt on the dispatch path', $bhNoAttempt);
p155_t(sprintf('...and returned in %.2f s (five attempts at the 10 s connect timeout would be 50 s)', $elapsedLive), $elapsedLive < 5.0);
p155_t('...all 5 deliveries are QUEUED (log rows queued, queue rows pending), none lost', q_count(q_logs($tidBh), 'queued') === 5 && count(q_queue($tidBh)) === 5);

$t0 = microtime(true);
$sum = notification_delivery_drain(3.0, 500);
$elapsedDrain = microtime(true) - $t0;
p155_t(sprintf('a sweep with a 3 s budget against the dead relay stops in %.1f s, not after 5 full timeouts', $elapsedDrain), $elapsedDrain < 8.0);
$logsAfter = q_logs($tidBh);
p155_t('...and a transient network failure leaves every delivery QUEUED (it will be retried, not dropped, not marked failed)',
    q_count($logsAfter, 'queued') === 5 && q_count($logsAfter, 'failed') === 0 && count(q_queue($tidBh)) === 5);
$retrying = 0;
foreach ($logsAfter as $l) if (strpos((string) $l['error'], 'retrying:') === 0) $retrying++;
p155_t('...the first attempts are recorded on the log row as "retrying: <reason>" so the operator can see why', $retrying >= 1);
$bh = notification_breaker_status();
p155_t('...and the failure is counted against the SMTP channel only', isset($bh['smtp']) && $bh['smtp']['last_error'] !== '');
q_drop();

p155_scheduler(false);
q_rule(['name' => 'P155 queue blackhole', 'channel' => 'smtp', 'recipients' => $five]);
$t0 = microtime(true);
$res = p155_make_incident(['scope' => 'P155 queue blackhole dead']);
$elapsedDead = microtime(true) - $t0;
$tidBh2 = (int) $res['id'];
p155_t(sprintf('scheduler DEAD: the same create makes ONE bounded inline attempt and returns in %.1f s (budget 3 s) - not 5 x the 10 s connect timeout', $elapsedDead), $elapsedDead < 9.0);
p155_t('...and nothing is lost: every delivery is still queued for the next sweep', q_count(q_logs($tidBh2), 'queued') === 5);
q_drop();

foreach (['email_mode', 'smtp_host', 'smtp_port', 'smtp_encryption'] as $s) {
    // restore now so nothing below can reach the network through the real adapter
    $o = $GLOBALS['P155_FIX']['settings'][$s] ?? null;
    p155_set_setting($s, $o);
}

// From here on the adapters are capturing stubs, so failures are deterministic.
p155_install_capture(['email', 'smtp', 'sms', 'local_chat', 'slack', 'telegram', 'push'], static function ($code, $m) {
    $e = $GLOBALS['P155_Q_FAIL'][$code] ?? null;
    return $e === null ? null : ['success' => false, 'error' => $e];
});
$GLOBALS['P155_Q_FAIL'] = [];

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- scheduler live: dispatch only queues; the sweep delivers ---\n";
p155_scheduler(true);
$depth0 = notify_queue_depth()['pending'];
q_rule();
$res = p155_make_incident(['scope' => 'P155 queue live']);
$tid = (int) $res['id'];
p155_t('creating the incident sent NOTHING (the broker was never called on the dispatch path)', count($GLOBALS['P155_SENT']) === 0);
$logs = q_logs($tid); $queue = q_queue($tid);
p155_t('3 recipients -> 3 log rows `queued` and 3 queue rows `pending` on channel _notify_rule', count($logs) === 3 && q_count($logs, 'queued') === 3 && count($queue) === 3);
$linked = 0;
foreach ($logs as $l) foreach ($queue as $q) if ((int) $l['queue_id'] === (int) $q['id']) $linked++;
p155_t('each log row is linked to its own queue row (notification_log.queue_id)', $linked === 3);
$payload = json_decode((string) $queue[0]['body'], true);
p155_t('the queue row carries routing facts only (log id, channel, address) - the text lives in the log row, in one place',
    isset($payload['log_id'], $payload['channel']) && $payload['channel'] === 'email' && strpos((string) $queue[0]['body'], 'P155 queue body') === false);
p155_t('the Status-page queue depth counts rule deliveries too (it counted only the fan-out half before)', notify_queue_depth()['pending'] - $depth0 === 3);

$sum = p155_drain();
p155_t('the sweep delivers all 3', $sum['sent'] === 3 && q_sent_count('email') === 3);
$logs = q_logs($tid);
p155_t('...log rows are `sent` with a sent_at, queue rows are `sent`, nothing left pending',
    q_count($logs, 'sent') === 3 && count(q_queue($tid)) === 0 && count(q_queue($tid, 'sent')) === 3 && !empty($logs[0]['sent_at']));
p155_t('...the three addresses are exactly the three configured', p155_sent_to('email') === ['q1@example.invalid', 'q2@example.invalid', 'q3@example.invalid']);

$sum2 = p155_drain();
p155_t('a second sweep finds nothing to do', $sum2['considered'] === 0 && q_sent_count('email') === 3);
$replayed = notification_delivery_replay($queue[0]);
p155_t('IDEMPOTENT: replaying a row whose log is already `sent` reports ok and does NOT send again (a sweep killed mid-way and re-run cannot double-send)',
    $replayed['ok'] === true && q_sent_count('email') === 3);
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- scheduler dead: a bounded inline attempt delivers at once ---\n";
p155_scheduler(false);
q_rule();
$res = p155_make_incident(['scope' => 'P155 queue dead ok']);
$tid = (int) $res['id'];
p155_t('with no timer running the deliveries still go out within the request', q_sent_count('email') === 3 && q_count(q_logs($tid), 'sent') === 3);
p155_t('...and the queue is empty afterwards', count(q_queue($tid)) === 0);
q_drop();

echo "\n--- scheduler dead + a failing channel: bounded by the per-channel breaker ---\n";
$GLOBALS['P155_Q_FAIL'] = ['email' => 'Connection failed: Connection timed out (110)'];
q_rule(['recipients' => $five]);
$res = p155_make_incident(['scope' => 'P155 queue dead fail']);
$tid = (int) $res['id'];
p155_t('5 recipients on a dead channel cost TWO attempts, not five (the breaker opens after two consecutive transient failures)', q_sent_count('email') === 2);
p155_t('...every delivery is still queued - nothing dropped, nothing marked failed', q_count(q_logs($tid), 'queued') === 5 && count(q_queue($tid)) === 5);
$st = notification_breaker_status();
p155_t('...and the breaker for `email` is OPEN with the reason on record', !empty($st['email']['open']) && strpos($st['email']['last_error'], 'timed out') !== false);
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- one dead channel must not pause the others; the breaker survives between sweeps ---\n";
p155_scheduler(true);
q_rule(['name' => 'P155 queue email', 'recipients' => $five]);
q_rule(['name' => 'P155 queue slack', 'channel' => 'slack', 'recipients' => []]);
$res = p155_make_incident(['scope' => 'P155 queue isolation']);
$tid = (int) $res['id'];
p155_t('5 email deliveries + 1 Slack post are queued', count(q_queue($tid)) === 6);
$GLOBALS['P155_Q_FAIL'] = ['email' => 'Connection failed: Connection timed out (110)'];
$sum = p155_drain();
p155_t('first sweep: the dead email channel is attempted TWICE then the other 3 are deferred, and Slack is delivered',
    q_sent_count('email') === 2 && q_sent_count('slack') === 1 && $sum['deferred'] === 3);
$logs = q_logs($tid);
$slackLog = null; foreach ($logs as $l) if ($l['channel'] === 'slack') $slackLog = $l;
p155_t('...the Slack post is `sent` while the email deliveries stay queued', $slackLog !== null && $slackLog['status'] === 'sent' && q_count($logs, 'queued') === 5);
$sum = p155_drain();
p155_t('second sweep: the breaker was PERSISTED - zero further attempts on the open channel', q_sent_count('email') === 2 && $sum['deferred'] === 5);

$GLOBALS['P155_Q_FAIL'] = [];
$sum = notification_delivery_drain(null, 500, time() + 90);
p155_t('after the cool-off the next sweep probes with one delivery, finds the channel back, and sends the rest', q_sent_count('email') === 2 + 5 && $sum['sent'] === 5);
p155_t('...all five are now `sent` and the breaker for `email` is cleared', q_count(q_logs($tid), 'sent') === 6 && !isset(notification_breaker_status()['email']));
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- permanent failures fail at once; transient ones are retried ---\n";
$GLOBALS['P155_Q_FAIL'] = ['email' => 'SMTP is not configured: no host'];
q_rule(['recipients' => ['email:perm@example.invalid']]);
$res = p155_make_incident(['scope' => 'P155 queue permanent']);
$tid = (int) $res['id'];
$sum = p155_drain();
$logs = q_logs($tid);
p155_t('a configuration failure marks the log row `failed` immediately, with the reason', count($logs) === 1 && $logs[0]['status'] === 'failed' && strpos((string) $logs[0]['error'], 'not configured') !== false);
p155_t('...and the queue row `failed` too (it will not be retried - retrying cannot fix a missing configuration)', count(q_queue($tid, 'failed')) === 1 && count(q_queue($tid)) === 0);
$before = q_sent_count('email');
p155_drain();
p155_t('...a later sweep does not try it again', q_sent_count('email') === $before);
p155_t('...and a permanent failure does NOT count toward opening the breaker', !isset(notification_breaker_status()['email']));
q_drop();

$GLOBALS['P155_Q_FAIL'] = ['email' => 'HTTP 503: service unavailable'];
q_rule(['recipients' => ['email:trans@example.invalid']]);
$res = p155_make_incident(['scope' => 'P155 queue transient']);
$tid = (int) $res['id'];
p155_drain();
$logs = q_logs($tid);
p155_t('a transient failure keeps the log row `queued`, annotated "retrying: ..."', $logs[0]['status'] === 'queued' && strpos((string) $logs[0]['error'], 'retrying:') === 0);
$q = q_queue($tid);
p155_t('...and the queue row stays `pending` with the same note', count($q) === 1 && strpos((string) $q[0]['send_error'], 'retrying:') === 0);
$GLOBALS['P155_Q_FAIL'] = [];
notification_breaker_reset();
p155_drain();
$logs = q_logs($tid);
p155_t('when the network is back the very next sweep delivers it: `sent`, and the stale "retrying" note is cleared', $logs[0]['status'] === 'sent' && $logs[0]['error'] === null);
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- the stale cutoff: a callout two hours late is worse than none ---\n";
q_rule();
$res = p155_make_incident(['scope' => 'P155 queue stale']);
$tid = (int) $res['id'];
db_query("UPDATE `{$prefix}pending_routed_messages` SET `scheduled_send_at` = ? WHERE `ticket_id` = ? AND `channel` = ?",
    [date('Y-m-d H:i:s', time() - 3 * 3600), $tid, NOTIFY_RULE_CHANNEL]);
$sum = p155_drain();
p155_t('3-hour-old deliveries are EXPIRED, not sent', $sum['expired'] === 3 && count($GLOBALS['P155_SENT']) === 0);
p155_t('...the queue rows read `expired`', count(q_queue($tid, 'expired')) === 3);
$logs = q_logs($tid);
p155_t('...and the LOG rows say so (`failed`, "not delivered - ...") instead of staying `queued` for ever', q_count($logs, 'failed') === 3 && strpos((string) $logs[0]['error'], 'not delivered') === 0);
q_drop();

echo "\n--- once_per_incident counts what was queued or sent, not what expired ---\n";
$uA = p155_make_responder('P155 Queue A');
$uB = p155_make_responder('P155 Queue B');
$uC = p155_make_responder('P155 Queue C');
$res = p155_make_incident(['scope' => 'P155 queue once']);
$tid = (int) $res['id'];
q_rule(['event_type' => 'unit_assign', 'once_per_incident' => true, 'recipients' => ['email:once@example.invalid']]);
assign_create_internal($tid, $uA, '', 0);
p155_t('the first unit assigned queues one notification', count(q_queue($tid)) === 1);
assign_create_internal($tid, $uB, '', 0);
p155_t('a queued delivery already counts as "notified": the second unit does not queue another', count(q_queue($tid)) === 1);
db_query("UPDATE `{$prefix}pending_routed_messages` SET `scheduled_send_at` = ? WHERE `ticket_id` = ? AND `channel` = ?",
    [date('Y-m-d H:i:s', time() - 3 * 3600), $tid, NOTIFY_RULE_CHANNEL]);
p155_drain();
assign_create_internal($tid, $uC, '', 0);
p155_t('once the first one EXPIRED (nobody was told) a later unit notifies again - a failed delivery has not notified anyone',
    count(q_queue($tid)) === 1 && q_count(q_logs($tid), 'failed') === 1);
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- security labels (Phase 18e): the same gate Message Routing applies ---\n";
$mem = p155_make_member('P155Queue', 'Listed', 'listed@example.invalid');
$listId = p155_make_list('P155 Queue List');
email_list_add_entry_internal($listId, 'member', ['ref_id' => $mem], 0);

$res = p155_make_incident(['scope' => 'P155 queue restricted']);
$tidR = (int) $res['id'];
$ov = seclabel_apply_override($tidR, 2, 'p155 test', $admin);
p155_t('fixture: the incident carries the Restricted label (list/broadcast blocked, direct allowed after a 30 s delay)', !empty($ov['ok']));
q_rule(['name' => 'P155 queue list', 'event_type' => 'incident_close', 'recipients' => [], 'email_list_id' => $listId]);
q_rule(['name' => 'P155 queue named', 'event_type' => 'incident_close', 'recipients' => ['email:named@example.invalid']]);
incident_update_status_internal($tidR, 1, 0);
$logs = q_logs($tidR);
$listLog = null; $namedLog = null;
foreach ($logs as $l) {
    if ($l['recipient'] === 'listed@example.invalid') $listLog = $l;
    if ($l['recipient'] === 'named@example.invalid') $namedLog = $l;
}
p155_t('a LIST delivery on a Restricted incident is SKIPPED, and the log names the label',
    $listLog !== null && $listLog['status'] === 'skipped' && stripos((string) $listLog['error'], 'security label') !== false && stripos((string) $listLog['error'], 'Restricted') !== false);
p155_t('...a blocked delivery never reaches the queue', $listLog !== null && empty($listLog['queue_id']));
p155_t('a NAMED recipient is allowed on that label and is QUEUED', $namedLog !== null && $namedLog['status'] === 'queued' && !empty($namedLog['queue_id']));
$q = q_queue($tidR);
$due = $q ? strtotime((string) $q[0]['scheduled_send_at']) : 0;
p155_t('...held for the label\'s 30 s send delay (scheduled_send_at is in the future)', count($q) === 1 && $due >= time() + 20 && $due <= time() + 45);
$sum = p155_drain();
p155_t('a sweep BEFORE the delay has run sends nothing (the delay is the policy)', $sum['considered'] === 0 && count($GLOBALS['P155_SENT']) === 0);
$killed = pending_kill((int) $q[0]['id'], $admin, 'p155 recalled');
$namedLog = db_fetch_one("SELECT * FROM `{$prefix}notification_log` WHERE `ticket_id` = ? AND `recipient` = 'named@example.invalid'", [$tidR]);
p155_t('KILLING the held row during the delay marks its log row `skipped` ("killed during the security-label send delay") - not `queued` for ever',
    $killed === true && $namedLog['status'] === 'skipped' && strpos((string) $namedLog['error'], 'killed during') === 0);
p155_t('...and a killed row is never sent even after the delay', (function () use ($tidR) {
    notification_delivery_drain(null, 500, time() + 300);
    return count($GLOBALS['P155_SENT']) === 0;
})());
q_drop();

$res = p155_make_incident(['scope' => 'P155 queue confidential']);
$tidC = (int) $res['id'];
seclabel_apply_override($tidC, 3, 'p155 test', $admin);
q_rule(['name' => 'P155 queue named', 'event_type' => 'incident_close', 'recipients' => ['email:conf@example.invalid']]);
incident_update_status_internal($tidC, 1, 0);
p155_drain();
p155_t('CONTROL: a delayed delivery is not due in the next minute...', count($GLOBALS['P155_SENT']) === 0 && count(q_queue($tidC)) === 1);
notification_delivery_drain(null, 500, time() + 90);
p155_t('...and goes out once the 60 s delay has elapsed (the gate delays, it does not discard)', p155_sent_to('email') === ['conf@example.invalid'] && q_count(q_logs($tidC), 'sent') === 1);
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- retention (notification_log_retention_days) ---\n";
p155_set_setting('notification_log_retention_days', '30');
$mk = static function (string $status, int $ageDays): int {
    global $prefix;
    $id = notification_log_insert(['rule_id' => null, 'event_type' => 'incident_create', 'ticket_id' => null, 'channel' => 'email',
        'recipient' => 'retention@example.invalid', 'subject' => 'P155 retention ' . $status . ' ' . $ageDays, 'body' => 'P155',
        'status' => $status, 'error' => null]);
    db_query("UPDATE `{$prefix}notification_log` SET `sent_at` = ? WHERE `id` = ?", [date('Y-m-d H:i:s', time() - $ageDays * 86400), $id]);
    return (int) $id;
};
$oldSent = $mk('sent', 60); $oldFailed = $mk('failed', 60); $oldQueued = $mk('queued', 60); $recent = $mk('sent', 5);
$exists = static function (int $id): bool {
    global $prefix;
    return (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_log` WHERE `id` = ?", [$id]) === 1;
};
$deleted = notification_log_purge();
p155_t('rows older than the retention window are purged (sent and failed)', !$exists($oldSent) && !$exists($oldFailed) && $deleted >= 2);
p155_t('...a row still `queued` is live queue state and is NEVER purged, however old', $exists($oldQueued));
p155_t('...a recent row is kept', $exists($recent));
p155_set_setting('notification_log_retention_days', '0');
$oldAgain = $mk('sent', 400);
p155_t('retention 0 means keep everything', notification_log_purge() === 0 && $exists($oldAgain));

// The setting must actually be CONSUMED by something that runs. A retention control
// whose purge nothing calls would read, write and test fine and do nothing - the
// "setting that was never wired to anything" disease (CLAUDE.md, tile_mode).
p155_set_setting('notification_log_retention_days', '30');
p155_scheduler(false);                     // registers the heartbeat row for restore; the tick below rewrites it
$stale = $mk('sent', 90);
$tick = p155_run_php([__DIR__ . '/../tools/pending_messages_tick.php']);
p155_t('the scheduled tick (tools/pending_messages_tick.php) itself runs the purge', $tick['code'] === 0 && !$exists($stale));
p155_t('...and reports how many rows it removed in its job detail line', strpos($tick['out'], 'log_purged=') !== false);
p155_t('...while leaving the recent row and the still-queued row alone', $exists($recent) && $exists($oldQueued));
q_drop();

// ─────────────────────────────────────────────────────────────────────────
echo "\n--- with no rules the hook costs one indexed query and loads nothing ---\n";
$activeNow = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `active` = 1");
p155_t('precondition: no active rule exists (this install\'s own rules were set aside for this file)', $activeNow === 0);
$r = p155_run_php([__DIR__ . '/_p155_noop_probe.php']);
$probe = json_decode(trim($r['out']), true);
p155_t('the probe ran', is_array($probe) && $r['code'] === 0);
if (is_array($probe) && !empty($probe['ticket_id'])) $GLOBALS['P155_FIX']['tickets'][] = (int) $probe['ticket_id'];
p155_t('one notification_hook() call with no rules runs exactly ONE query', is_array($probe) && $probe['selects'] === 1);
p155_t('...and does NOT load the engine (and with it the whole message broker)', is_array($probe) && $probe['engine_loaded_after_hook'] === false);
p155_t('creating a real incident through the real writer with no rules still never loads the engine', is_array($probe) && $probe['engine_loaded_after_incident'] === false);

p155_cleanup();
p155_t('fixtures are gone after cleanup (verified by querying)',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}notification_rules` WHERE `name` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `scope` LIKE 'P155%'") === 0
    && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}pending_routed_messages` WHERE `channel` = ?", [NOTIFY_RULE_CHANNEL]) === 0);
p155_done();
