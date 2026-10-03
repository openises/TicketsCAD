<?php
/**
 * Phase 155 (S0) -- audit_log()'s trailing $actor parameter.
 *
 * Background work (a scheduled-incident activation, an auto-close sweep) used
 * to be attributed to whichever dispatcher's browser request it happened to
 * run inside, because audit_log() always read the session. A false statement
 * in the one record whose job is to say who did what.
 *
 * This proves, through the REAL audit_log():
 *   - with no $actor, behaviour is exactly what it always was (the session user)
 *   - with AUDIT_ACTOR_SYSTEM the row says "System" with a NULL user id, EVEN
 *     when a dispatcher's session is mounted
 *   - an explicit ['id'=>N,'name'=>'x'] override is honoured
 *   - the webhook/push envelope's actor fields follow the override
 *
 * @requires-db
 * Usage: php tests/test_audit_actor_override.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/_p155_helpers.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== Phase 155 (S0) -- audit_log() actor override ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$tag = 'p155actor' . getmypid();
test_fixture_guard_track_where('newui_audit_log', "target_type = 'p155_actor_test'", []);

$row = function (string $activity) use ($prefix, $tag) {
    return db_fetch_one(
        "SELECT user_id, user_name, summary FROM `{$prefix}newui_audit_log`
          WHERE target_type = 'p155_actor_test' AND target_id = ? AND activity = ? ORDER BY id DESC LIMIT 1",
        [$tag, $activity]);
};

// A dispatcher's session is mounted for the whole test, exactly the situation
// the bug lived in: background work running inside somebody's request.
$_SESSION['user_id'] = 4242;
$_SESSION['user']    = 'dispatcher.jane';

audit_log('system', 'p155_default', 'p155_actor_test', $tag, 'default actor');
$d = $row('p155_default');
t('no $actor: the row names the SESSION user (behaviour unchanged for every existing caller)',
    $d && (int) $d['user_id'] === 4242 && $d['user_name'] === 'dispatcher.jane');

audit_log('system', 'p155_system', 'p155_actor_test', $tag, 'system actor', null, AUDIT_INFO, AUDIT_ACTOR_SYSTEM);
$s = $row('p155_system');
t('AUDIT_ACTOR_SYSTEM: the row says System, with NO user id, even though a dispatcher session is mounted',
    $s && $s['user_id'] === null && $s['user_name'] === 'System');

audit_log('system', 'p155_explicit', 'p155_actor_test', $tag, 'explicit actor', null, AUDIT_INFO,
    ['id' => 77, 'name' => 'integration-bot']);
$e = $row('p155_explicit');
t('an explicit actor override is honoured', $e && (int) $e['user_id'] === 77 && $e['user_name'] === 'integration-bot');

audit_log('system', 'p155_nullid', 'p155_actor_test', $tag, 'name only', null, AUDIT_INFO, ['name' => 'cron']);
$n = $row('p155_nullid');
t('an override with only a name leaves the user id NULL (never inherits the session id)',
    $n && $n['user_id'] === null && $n['user_name'] === 'cron');

t('the constant is the documented shape', AUDIT_ACTOR_SYSTEM === ['id' => null, 'name' => 'System']);

// The webhook envelope carries actor_id/actor_name from the same variables, so
// it must follow the override too. Drive the real fan-out with a mapped event
// and read what it queued.
$sub = null;
try {
    db_query(
        "INSERT INTO `{$prefix}webhook_subscriptions` (`name`, `target_url`, `hmac_secret`, `event_filters_json`, `active`, `retry_policy_json`)
         VALUES (?, 'http://127.0.0.1:1/p155-unreachable', 'p155', ?, 1, ?)",
        ['p155_actor_' . getmypid(), json_encode(['incident.status_changed']), json_encode(['max_retries' => 0])]);
    $sub = (int) db_insert_id();
    test_fixture_guard_track('webhook_subscriptions', $sub);
    test_fixture_guard_track_where('webhook_deliveries', 'subscription_id = ?', [$sub]);
    require_once __DIR__ . '/../inc/notify-fanout.php';
    p155_track_fanout_queue();
    notify_fanout_forget_channel_cache();
    $tid = p155_make_ticket(2, null, 'P155 actor envelope');
    audit_log('incident', 'status_change', 'ticket', $tid, 'envelope check', ['ticket_id' => $tid],
        AUDIT_INFO, AUDIT_ACTOR_SYSTEM);
    p155_drain();
    $body = db_fetch_value(
        "SELECT payload FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ? ORDER BY id DESC LIMIT 1", [$sub]);
    $env = $body ? json_decode((string) $body, true) : null;
    $data = is_array($env) ? ($env['data'] ?? []) : [];
    t('the webhook envelope says actor_name System with a null actor_id',
        is_array($data) && ($data['actor_name'] ?? null) === 'System' && array_key_exists('actor_id', $data) && $data['actor_id'] === null,
        (string) $body);
} catch (Throwable $ex) {
    t('webhook envelope check ran', false, $ex->getMessage());
}

p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
