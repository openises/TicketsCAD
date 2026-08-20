<?php
/**
 * GH #84 (2026-08-19) — a notification rule can now target ANY registered
 * broker channel, not just email/sms/local_chat.
 *
 * WHAT THIS IS DEFENDING AGAINST
 * -------------------------------
 * notification_rules.channel was `ENUM('email','sms','local_chat','all')`
 * (sql/notification_rules.sql), so a rule could never even STORE 'slack',
 * 'telegram', 'push', 'aprs', 'dmr', 'meshtastic', 'meshcore', or 'smtp' —
 * on NewUI's strict-mode connection (inc/db.php sets no sql_mode override)
 * that INSERT fails outright rather than silently truncating. Separately,
 * inc/notification-engine.php:98 hardcoded 'all' to
 * ['email', 'sms', 'local_chat'], so even widening the enum wouldn't have
 * made 'all' mean all. Neither restriction was in inc/broker.php's
 * dispatch (broker_send()), which is fully generic against every channel
 * registered in inc/channels/*.php — 11 today.
 *
 * Fixed by:
 *   - sql/notification_rules.sql: channel is now VARCHAR(20), validated
 *     conceptually against $_broker_channels (see api/routing.php's
 *     'save_enabled_channels' action for the established pattern once an
 *     admin API for these rules exists).
 *   - sql/run_phase145_notification_rules_channel_widen.php: idempotent
 *     ALTER for installs that already have the ENUM.
 *   - inc/notification-engine.php: 'all' resolves to
 *     array_keys($_broker_channels) dynamically instead of a hardcoded
 *     list (_notification_resolve_rule_channels()).
 *   - Slack/Telegram post to ONE server-configured destination regardless
 *     of `to` (see their own "destination is pinned to configuration"
 *     docblocks) — declared `'shared_destination' => true` on their
 *     broker_register() call and fired once per rule match instead of
 *     once per recipient (_notification_classify_channels()), so widening
 *     the constraint doesn't turn into duplicate-posting spam the moment a
 *     rule has more than one recipient.
 *   - Push (inc/channels/push.php) doesn't read `to` at all — it requires
 *     a resolved `_recipient_user_ids` array (the shape the routing
 *     engine's predicate resolver normally produces). notification_check()
 *     now populates it per recipient so push is genuinely reachable, not
 *     just schema-legal.
 *   - _notification_resolve_recipients(): found while testing the above —
 *     a numeric (user-id) recipient never resolved on any install, because
 *     the lookup selected a `user.cell` column that has never existed
 *     (`user` has phone_p/phone_s/phone_m — the schema-mismatch pattern
 *     this file's CLAUDE.md documents extensively elsewhere). Every
 *     user-id recipient silently dropped out of every rule, on every
 *     channel, before this fix.
 *
 * HOW IT TESTS
 * ------------
 * There is no admin UI or writer function for notification_rules today
 * (grepped — settings.php's "Notification Rules" panel is a described-but-
 * unbuilt stub; confirmed independently by the GH #84 reporter and by
 * Eric's own follow-up comment on the issue). A raw INSERT is therefore
 * the only "real" path that exists for a rule to come into being — exactly
 * how the reporter and Eric both verified the bug — so these tests insert
 * rule rows directly and then drive the REAL notification_check(), the
 * same function api/incident-*.php calls after a live dispatch action.
 * Every rule this file creates is deleted again, individually, right after
 * its assertions run.
 *
 * @requires-db
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/notification-engine.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0; $skipped = 0;
function ok(string $m): void   { global $pass; $pass++; echo "  PASS: $m\n"; }
function bad(string $m): void  { global $fail; $fail++; echo "  FAIL: $m\n"; }
function is_ok($cond, string $m): void { $cond ? ok($m) : bad($m); }
function skip(string $m): void { global $skipped; $skipped++; echo "  SKIP: $m\n"; }
function done(): void {
    global $pass, $fail, $skipped;
    echo "\n=== $pass passed, $fail failed, $skipped skipped ===\n";
    exit($fail > 0 ? 1 : 0);
}

echo "\n=== GH #84 — notification rules can target any registered broker channel ===\n";

// The full set this issue is about — matches the reporter's own dump of
// $_broker_channels and inc/channels/*.php's actual broker_register() calls.
$ALL_ELEVEN = ['aprs', 'dmr', 'email', 'local_chat', 'meshcore', 'meshtastic',
               'push', 'slack', 'sms', 'smtp', 'telegram'];

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Pure logic: 'all' resolves dynamically, not to a hardcoded list --\n";

is_ok(_notification_resolve_rule_channels('slack', ['slack' => [], 'email' => []]) === ['slack'],
      'a specific channel resolves to itself');

$fakeRegistry = ['email' => [], 'sms' => [], 'local_chat' => [], 'gh84_probe' => []];
$resolved = _notification_resolve_rule_channels('all', $fakeRegistry);
sort($resolved);
is_ok($resolved === ['email', 'gh84_probe', 'local_chat', 'sms'],
      "'all' resolves to every registered channel, including one that didn't exist"
      . " when this file's original hardcoded list (['email','sms','local_chat']) was written");

is_ok(_notification_resolve_rule_channels('all', []) === [],
      'an empty registry (broker.php not loaded) resolves all to nothing, not a crash');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Pure logic: shared-destination channels are classified apart from per-recipient ones --\n";

$registry = [
    'email'  => [],
    'slack'  => ['shared_destination' => true],
    'push'   => [],
    'telegram' => ['shared_destination' => true],
];
[$shared, $perRecipient] = _notification_classify_channels(
    ['email', 'slack', 'push', 'telegram'], $registry);
sort($shared); sort($perRecipient);
is_ok($shared === ['slack', 'telegram'], 'slack + telegram land in the shared bucket');
is_ok($perRecipient === ['email', 'push'], 'email + push land in the per-recipient bucket');

[$shared2, $perRecipient2] = _notification_classify_channels(['email'], $registry);
is_ok($shared2 === [] && $perRecipient2 === ['email'],
      'a channel with no shared_destination flag classifies as per-recipient by default');

// ─────────────────────────────────────────────────────────────────────────
// Everything below needs a database and the real broker.
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) { skip('No database available — the rest of this file needs one'); done(); }

global $_broker_channels;
if (!isset($_broker_channels) || !is_array($_broker_channels)) {
    skip('$_broker_channels not populated — inc/broker.php did not load as expected');
    done();
}

$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}notification_rules` LIMIT 1");
} catch (Exception $e) {
    skip("notification_rules table not available (" . $e->getMessage() . ") — run php sql/run_migrations.php");
    done();
}

// ── Fixture bookkeeping ─────────────────────────────────────────────────
$createdRuleIds = [];
function gh84_insert_rule(string $channel, array $recipientIds = [], string $name = 'GH84 TEST'): int {
    global $prefix, $createdRuleIds;
    db_query(
        "INSERT INTO `{$prefix}notification_rules`
            (`name`, `event_type`, `channel`, `recipients`, `active`)
         VALUES (?, 'incident_create', ?, ?, 1)",
        [$name, $channel, json_encode($recipientIds)]
    );
    $id = (int) db_insert_id();
    $createdRuleIds[] = $id;
    return $id;
}
function gh84_cleanup(): void {
    global $prefix, $createdRuleIds;
    foreach (array_unique($createdRuleIds) as $id) {
        try { db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]); } catch (Exception $e) {}
        try { db_query("DELETE FROM `{$prefix}notification_log` WHERE rule_id = ?", [$id]); } catch (Exception $e) {}
    }
}
register_shutdown_function('gh84_cleanup');

$adminId = test_admin_user_id();

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The schema no longer rejects a non-{email,sms,local_chat,all} channel --\n";

$row = db_fetch_one(
    "SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'channel'",
    [$prefix . 'notification_rules']
);
is_ok($row !== null, 'notification_rules.channel column exists');
is_ok($row && strtolower($row['DATA_TYPE']) !== 'enum',
      "channel column is no longer an ENUM (is now {$row['COLUMN_TYPE']})");

foreach (array_merge($ALL_ELEVEN, ['all']) as $ch) {
    $id = gh84_insert_rule($ch, [], "GH84 TEST schema {$ch}");
    $stored = db_fetch_value("SELECT channel FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);
    is_ok($stored === $ch, "channel '{$ch}' round-trips through the column unchanged (got '{$stored}')");
}
gh84_cleanup();
$createdRuleIds = [];

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Every one of the 11 registered channels is reachable from a rule --\n";
echo "   (a rule targeting it dispatches through the REAL broker, not an\n";
echo "    'Unknown channel' rejection — whatever it fails on after that is\n";
echo "    that channel's own configuration, not the rule mechanism)\n";

foreach ($ALL_ELEVEN as $ch) {
    // SMS defaults to OFF in _notification_get_user_prefs() when a
    // recipient has a user_id and no notification_preferences row exists
    // (see section 8 below) — correct, pre-existing behaviour, not
    // something GH #84 touches. Use a bare phone-number recipient for
    // that one channel so this reachability check isn't entangled with
    // the (unrelated, already-covered) preference gate; every other
    // channel uses the admin account, whose defaults allow it through.
    // Not '+15551234567' — PHP's is_numeric() treats a leading '+' plus
    // all digits as numeric, so _notification_resolve_recipients() would
    // misroute it into the user-id lookup branch instead of the phone
    // branch. Dashes keep it unambiguously a phone string.
    $recipient = ($ch === 'sms') ? '555-123-4567' : $adminId;
    $id = gh84_insert_rule($ch, [$recipient], "GH84 TEST reach {$ch}");
    $results = notification_check('incident_create', [
        'ticket_id' => 999999, 'scope' => 'GH84 test', 'severity' => 1,
    ]);
    $mine = array_values(array_filter($results, function ($r) use ($id) { return $r['rule_id'] === $id; }));

    if ($ch === 'push' && !empty($mine) && ($mine[0]['error'] ?? '') === 'push requires a user account; recipient has no user_id') {
        // Shouldn't happen (we gave it a real user id) — but if it does,
        // fail loudly rather than silently pass via the empty() branch below.
        bad("push: recipient had a user_id but was still treated as address-only");
    }

    is_ok(!empty($mine), "channel '{$ch}': notification_check() produced at least one dispatch attempt");
    foreach ($mine as $r) {
        is_ok(($r['error'] ?? '') !== "Unknown channel: {$ch}",
              "channel '{$ch}': broker_send() reached the real handler (not rejected as unregistered)");
    }
    db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);
}
$createdRuleIds = [];

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. push: notification_check() resolves _recipient_user_ids, not just 'to' --\n";

$id = gh84_insert_rule('push', [$adminId], 'GH84 TEST push shim');
$results = notification_check('incident_create', ['ticket_id' => 999998, 'scope' => 'GH84 push test']);
$mine = array_values(array_filter($results, function ($r) use ($id) { return $r['rule_id'] === $id; }));
is_ok(count($mine) === 1, 'exactly one push attempt for one recipient with a user_id');
if (!empty($mine)) {
    is_ok(($mine[0]['error'] ?? '') !== 'push channel requires a recipient predicate; no user IDs resolved',
          "push was NOT rejected for lacking a recipient predicate — got: "
          . var_export($mine[0]['error'] ?? null, true));
}
db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);

echo "\n-- 5b. push: a recipient with no user_id is skipped, not attempted --\n";

db_query(
    "INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`)
     VALUES ('GH84 TEST push no-uid', 'incident_create', 'push', ?, 1)",
    [json_encode(['someone@example.org'])]
);
$id = (int) db_insert_id();
$createdRuleIds[] = $id;
$results = notification_check('incident_create', ['ticket_id' => 999997, 'scope' => 'GH84 push no-uid test']);
$mine = array_values(array_filter($results, function ($r) use ($id) { return $r['rule_id'] === $id; }));
is_ok(empty($mine), 'an address-only recipient produces no push dispatch attempt in the results');
$logRow = db_fetch_one("SELECT status, error FROM `{$prefix}notification_log` WHERE rule_id = ? ORDER BY id DESC LIMIT 1", [$id]);
is_ok($logRow && $logRow['status'] === 'skipped',
      'and it is recorded in notification_log as skipped, not silently dropped'
      . ($logRow ? " (got status={$logRow['status']})" : ' (no log row at all)'));
db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);
db_query("DELETE FROM `{$prefix}notification_log` WHERE rule_id = ?", [$id]);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Shared-destination channels fire ONCE per rule, not once per recipient --\n";

$callCount = 0;
broker_register('gh84_test_shared', [
    'name'    => 'GH84 Test Shared',
    'send'    => function (array $message) use (&$callCount) { $callCount++; return ['success' => true]; },
    'receive' => null,
    'status'  => function () { return 'active'; },
    'shared_destination' => true,
]);

// Three DIFFERENT recipients on one rule — before this fix's restructuring,
// a shared-destination channel would have been invoked once per recipient.
$secondUser = null;
try {
    $secondUser = db_fetch_value("SELECT id FROM `{$prefix}user` WHERE id != ? ORDER BY id LIMIT 1", [$adminId]);
} catch (Exception $e) {}
$recipientIds = $secondUser ? [$adminId, (int) $secondUser] : [$adminId];
if (count($recipientIds) < 2) {
    // Only one real user account on this install — pad with a couple of
    // literal email addresses, which _notification_resolve_recipients()
    // resolves independently of the user table.
    db_query(
        "INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`)
         VALUES ('GH84 TEST shared once', 'incident_create', 'gh84_test_shared', ?, 1)",
        [json_encode(array_merge($recipientIds, ['a@example.org', 'b@example.org']))]
    );
} else {
    db_query(
        "INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`)
         VALUES ('GH84 TEST shared once', 'incident_create', 'gh84_test_shared', ?, 1)",
        [json_encode($recipientIds)]
    );
}
$id = (int) db_insert_id();
$createdRuleIds[] = $id;

notification_check('incident_create', ['ticket_id' => 999996, 'scope' => 'GH84 shared test']);
is_ok($callCount === 1,
      "a shared-destination channel with multiple recipients on the rule fired exactly once (got {$callCount})"
      . " — the duplicate-post bug this fix's restructuring specifically prevents");
db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);

echo "\n-- 6b. ...and fires even with ZERO recipients configured --\n";

$callCount = 0;
db_query(
    "INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`)
     VALUES ('GH84 TEST shared no recipients', 'incident_create', 'gh84_test_shared', NULL, 1)"
);
$id = (int) db_insert_id();
$createdRuleIds[] = $id;
notification_check('incident_create', ['ticket_id' => 999995, 'scope' => 'GH84 shared no-recipients test']);
is_ok($callCount === 1,
      'a shared-destination channel fires even when the rule has no recipients at all'
      . ' (there is exactly one destination either way) — got ' . $callCount);
db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. 'all' includes a freshly-registered channel with zero code changes here --\n";

$probeCalls = 0;
broker_register('gh84_test_all_probe', [
    'name' => 'GH84 Test All Probe',
    'send' => function (array $m) use (&$probeCalls) { $probeCalls++; return ['success' => true]; },
    'receive' => null, 'status' => function () { return 'active'; },
]);

db_query(
    "INSERT INTO `{$prefix}notification_rules` (`name`, `event_type`, `channel`, `recipients`, `active`)
     VALUES ('GH84 TEST all dynamic', 'incident_create', 'all', ?, 1)",
    [json_encode([$adminId])]
);
$id = (int) db_insert_id();
$createdRuleIds[] = $id;
notification_check('incident_create', ['ticket_id' => 999994, 'scope' => 'GH84 all-dynamic test']);
is_ok($probeCalls >= 1,
      "'all' reached a channel ('gh84_test_all_probe') that was registered AFTER this test process started"
      . ' — proves the expansion is computed live, not from a fixed list (got ' . $probeCalls . ' call(s))');
db_query("DELETE FROM `{$prefix}notification_rules` WHERE id = ?", [$id]);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 8. Regression: preference gate + quiet hours still work for email/sms/local_chat --\n";

// _notification_get_user_prefs() defaults to email+chat on, SMS off when no
// notification_preferences row exists — exercise that default directly
// rather than depending on this install's actual preference rows.
$defaultPrefs = _notification_get_user_prefs(999999999); // no such user — falls to defaults
is_ok($defaultPrefs['channel_email'] === 1 && $defaultPrefs['channel_sms'] === 0 && $defaultPrefs['channel_chat'] === 1,
      'default preferences unchanged: email+chat on, SMS off');

is_ok(_notification_in_quiet_hours(['quiet_start' => '00:00:00', 'quiet_end' => '23:59:59']) === true,
      'quiet-hours check still works (an all-day window reports true)');
is_ok(_notification_in_quiet_hours(['quiet_start' => null, 'quiet_end' => null]) === false,
      'no quiet hours configured reports false, as before');

done();
