<?php
/**
 * Webhook retries and the stale-work cutoff (2026-10-19).
 *
 * Until the Docker `scheduler` service existed, nothing in the Docker
 * deployment ran tools/webhook_retry_tick.php — so on every Docker install,
 * every webhook delivery that failed once was never retried. Turning the job
 * on would have made its first run retry ALL of them, months later, telling
 * the receiving system about incidents long closed as if they had just
 * happened. Every other sweep already refuses to act on work older than
 * sched_stale_cutoff_min (inc/scheduled-jobs.php); webhook_process_retries()
 * did not.
 *
 * Proven here by driving the REAL webhook_process_retries() against real
 * rows:
 *   - a delivery whose retry has been due for hours is dead-lettered, not
 *     sent, with the reason written into the row (so "why did my receiver
 *     never get this?" has an answer) and is still replayable;
 *   - a delivery that failed two minutes ago is retried as before;
 *   - a delivery that ran out of attempts is dead-lettered by the existing
 *     pass, with no "expired" reason;
 *   - the job reports the count, and the tick records a heartbeat so
 *     Settings → System Health can show it;
 *   - sched_job_required('webhook_retry') turns on exactly when a failed
 *     delivery is waiting.
 *
 * The fresh delivery points at 127.0.0.1, which the SSRF guard refuses
 * before any network call, so "retried" is deterministic and offline.
 *
 * Run: php tests/test_webhook_retry_stale_cutoff.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/webhooks.php';
require_once __DIR__ . '/../inc/scheduled-jobs.php';
require_once __DIR__ . '/_test_fixture_guard.php';

$pass = 0;
$fail = 0;
function ws(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] {$label}\n"; }
    else       { $fail++; echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

echo "=== Webhook retries honour the stale-work cutoff ===\n\n";
$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE dead_lettered_at IS NULL");
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}webhook_subscriptions`");
} catch (Throwable $e) {
    echo "SKIP: webhook tables (with the Phase 94 dead-letter columns) are not installed\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$cutoff = sched_stale_cutoff_min();
if ($cutoff <= 0 || $cutoff > 120) {
    echo "SKIP: sched_stale_cutoff_min is {$cutoff} on this install; the fixtures assume the cutoff is on and under 2 hours\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$tag = 'wsc_' . getmypid() . '_' . bin2hex(random_bytes(3));
db_query(
    "INSERT INTO `{$prefix}webhook_subscriptions`
        (`name`, `target_url`, `hmac_secret`, `event_filters_json`, `active`, `retry_policy_json`)
     VALUES (?, 'http://127.0.0.1:1/stale-cutoff-test', 'wsc-secret', ?, 1, ?)",
    [$tag, json_encode(['incident.created']), json_encode(['max_attempts' => 5])]);
$sub = (int) db_insert_id();
test_fixture_guard_track('webhook_subscriptions', $sub);
test_fixture_guard_track_where('webhook_deliveries', 'subscription_id = ?', [$sub]);

/** A failed delivery row, $ageMin minutes old, on attempt $attempt. */
function ws_delivery(int $sub, int $attempt, int $ageMin, string $error): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query(
        "INSERT INTO `{$prefix}webhook_deliveries`
            (`subscription_id`, `webhook_id`, `event_type`, `payload`, `attempt`, `status`, `error`,
             `delivery_uid`, `created_at`)
         VALUES (?, ?, 'incident.created', '{\"t\":1}', ?, 'failed', ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))",
        [$sub, $sub, $attempt, $error, bin2hex(random_bytes(8)), $ageMin]);
    return (int) db_insert_id();
}

$stale     = ws_delivery($sub, 1, 180, 'connection refused');   // retry due 3 hours ago
$fresh     = ws_delivery($sub, 1, 2, 'connection refused');     // retry due 1 minute ago
$exhausted = ws_delivery($sub, 5, 180, 'HTTP 500');              // out of attempts

$req = sched_job_required('webhook_retry');
ws('with failed deliveries waiting, the retry job is required', $req['required'] === true, json_encode($req));
ws('and it says why', stripos($req['why'], 'waiting to be retried') !== false);

$r = webhook_process_retries();
ws('webhook_process_retries() runs without error', empty($r['error']), json_encode($r));
ws('it reports how many deliveries it expired', ($r['expired'] ?? 0) >= 1, json_encode($r));

$row = static function (int $id): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return db_fetch_one("SELECT * FROM `{$prefix}webhook_deliveries` WHERE id = ?", [$id]) ?: [];
};

$s = $row($stale);
ws('a retry overdue by hours is NOT sent — it is dead-lettered', ($s['status'] ?? '') === 'dead_letter', json_encode($s));
ws('...with a dead_lettered_at stamp (so it shows in the dead-letter list and can be replayed)',
    !empty($s['dead_lettered_at']));
ws('...and the reason written into the row', strpos((string) ($s['error'] ?? ''), 'expired:') === 0, (string) ($s['error'] ?? ''));
ws('the reason names the governing setting', strpos((string) ($s['error'] ?? ''), 'sched_stale_cutoff_min=' . $cutoff) !== false);
ws('the original error is kept', strpos((string) ($s['error'] ?? ''), 'connection refused') !== false);
$retryOfStale = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ? AND delivery_uid = ? AND id <> ?",
    [$sub, $s['delivery_uid'] ?? '', $stale]);
ws('no new delivery attempt was created for it', $retryOfStale === 0);

$f = $row($fresh);
ws('a delivery that failed two minutes ago is retried as before', ($f['status'] ?? '') === 'retried', json_encode($f));
$retryOfFresh = db_fetch_one(
    "SELECT * FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ? AND delivery_uid = ? AND id <> ?",
    [$sub, $f['delivery_uid'] ?? '', $fresh]);
ws('...as attempt 2 of the same logical delivery', (int) ($retryOfFresh['attempt'] ?? 0) === 2);

$x = $row($exhausted);
ws('a delivery out of attempts is still dead-lettered by the existing pass', ($x['status'] ?? '') === 'dead_letter');
ws('...without an "expired" reason (it was not stale, it was out of attempts)',
    strpos((string) ($x['error'] ?? ''), 'expired:') === false, (string) ($x['error'] ?? ''));

// A retry policy WITHOUT a max_attempts key (several tests and older rows store
// {"max_retries":0}). PHP defaulted max_attempts to 5 but the SQL passes read
// NULL from JSON_EXTRACT, and `attempt >= NULL` is never true — so such a
// delivery was retried by PHP's rules yet never dead-lettered by SQL's, and
// the stale pass would have skipped it entirely. Both now default to 5.
db_query(
    "INSERT INTO `{$prefix}webhook_subscriptions`
        (`name`, `target_url`, `hmac_secret`, `event_filters_json`, `active`, `retry_policy_json`)
     VALUES (?, 'http://127.0.0.1:1/stale-cutoff-test-2', 'wsc-secret', ?, 1, ?)",
    [$tag . '_b', json_encode(['incident.created']), json_encode(['max_retries' => 0])]);
$sub2 = (int) db_insert_id();
test_fixture_guard_track('webhook_subscriptions', $sub2);
test_fixture_guard_track_where('webhook_deliveries', 'subscription_id = ?', [$sub2]);
$stale2     = ws_delivery($sub2, 1, 180, 'connection refused');
$exhausted2 = ws_delivery($sub2, 5, 180, 'HTTP 500');
webhook_process_retries();
ws('with no max_attempts in the policy, a stale retry is still expired, not sent',
    ($row($stale2)['status'] ?? '') === 'dead_letter' && strpos((string) $row($stale2)['error'], 'expired:') === 0,
    json_encode($row($stale2)));
ws('with no max_attempts in the policy, the default of 5 attempts still dead-letters',
    ($row($exhausted2)['status'] ?? '') === 'dead_letter', json_encode($row($exhausted2)));

$dlc = (int) db_fetch_value("SELECT dead_letter_count FROM `{$prefix}webhook_subscriptions` WHERE id = ?", [$sub]);
ws('the subscription\'s dead-letter count includes the expired delivery', $dlc >= 2, "dead_letter_count={$dlc}");

$again = webhook_process_retries();
ws('a second run finds nothing new to expire for this subscription',
    $row($stale)['status'] === 'dead_letter' && $row($stale)['error'] === $s['error']);

// The real tick: heartbeat for Settings → System Health.
if (sched_table_exists()) {
    $before = (int) (sched_job_last('webhook_retry')['run_count'] ?? 0);
    $null = stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null';
    $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/tools/webhook_retry_tick.php'],
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pp, null, null,
        ['bypass_shell' => true]);
    $code = is_resource($p) ? proc_close($p) : -1;
    $after = sched_job_last('webhook_retry');
    ws('the real tick exits 0', $code === 0, "exit {$code}");
    ws('the real tick records a heartbeat', (int) ($after['run_count'] ?? 0) === $before + 1);
    ws('the heartbeat detail counts expiries', strpos((string) ($after['last_detail'] ?? ''), 'expired') !== false);
} else {
    echo "SKIP: scheduled_job_runs missing — heartbeat not checked\n";
}

$reg = sched_job_registry();
ws('webhook_retry is a registered job (so the Status page shows it)', isset($reg['webhook_retry']));
ws('it runs every minute', ($reg['webhook_retry']['interval_s'] ?? 0) === 60);

// Clean up now (the fixture guard is the backstop).
foreach ([$sub, $sub2] as $sid) {
    db_query("DELETE FROM `{$prefix}webhook_deliveries` WHERE subscription_id = ?", [$sid]);
    db_query("DELETE FROM `{$prefix}webhook_subscriptions` WHERE id = ?", [$sid]);
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
