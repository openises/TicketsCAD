<?php
/**
 * Chat Bridge Tests (GH#89, public repo — follow-up to GH#84)
 *
 * Covers inc/chat-bridge.php (chat_bridge_sync(), chat_bridge_definitions())
 * and the hard DM-exclusion guard added to inc/router.php
 * (_router_message_is_local_chat_dm(), router_evaluate(), router_test()):
 *
 * - message_routes.managed / .managed_key schema (self-heal + migration)
 * - chat_bridge_definitions() shape (4 keys, correct dest channels, unique
 *   managed_keys)
 * - chat_bridge_sync(): checkbox off -> no row created
 * - chat_bridge_sync(): checkbox on -> exactly one managed row created with
 *   the right source/dest/direction/managed/managed_key
 * - chat_bridge_sync() is idempotent (re-running with no change creates no
 *   duplicate)
 * - "sync never clobbers a hand-created row" — a hand-created local_chat
 *   route with a DIFFERENT managed_key (or managed=0) is left completely
 *   untouched when a checkbox is toggled
 * - disable-not-delete: toggling a checkbox off disables its managed row
 *   without deleting it, and toggling back on does not reset admin
 *   customizations made to it (description/filters) in the interim
 * - THE SECURITY-CRITICAL PART: a direct/private local_chat message is
 *   never forwarded by ANY enabled local_chat route, proven by driving the
 *   REAL entry point (broker_send('local_chat', ...) -> _chat_send() ->
 *   router_evaluate()), not by hand-seeding router state — with a
 *   contrasting case proving a genuine public room message IS considered
 *   for forwarding by the same rules (so the guard is a true DM filter,
 *   not something that blocks everything)
 * - _router_message_is_local_chat_dm() unit-level truth table
 * - router_test() (dry-run) mirrors the same DM exclusion
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/sse.php';
require __DIR__ . '/../inc/broker.php';
require __DIR__ . '/../inc/chat-bridge.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Chat Bridge Tests (GH#89) ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function cb_assert(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) {
        echo "[$label] PASS\n";
        $pass++;
    } else {
        echo "[$label] FAIL" . ($detail !== '' ? " — $detail" : '') . "\n";
        $fail++;
    }
}

// Ensure prerequisite tables exist (mirrors tests/test_routing.php).
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}chat_messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` INT NOT NULL DEFAULT 0,
        `user_name` VARCHAR(64) NOT NULL DEFAULT 'system', `channel` VARCHAR(64) NOT NULL DEFAULT 'general',
        `recipient` VARCHAR(64) NOT NULL DEFAULT 'all', `body` TEXT NOT NULL,
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'text', `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `ticket_id` INT DEFAULT NULL, `signal_id` INT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY, `channel` VARCHAR(64) NOT NULL,
        `direction` ENUM('inbound','outbound') NOT NULL DEFAULT 'outbound',
        `msg_type` VARCHAR(32) NOT NULL DEFAULT 'general', `sender` VARCHAR(128) NOT NULL DEFAULT 'system',
        `recipient` VARCHAR(256) NOT NULL DEFAULT '', `subject` VARCHAR(256) DEFAULT '',
        `body` TEXT NOT NULL, `priority` VARCHAR(16) NOT NULL DEFAULT 'normal',
        `status` VARCHAR(32) NOT NULL DEFAULT 'pending', `error` TEXT DEFAULT NULL,
        `payload` TEXT DEFAULT NULL, `delivered_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_channel` (`channel`), KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

_router_ensure_tables();
_chat_bridge_ensure_columns();

// ── Fixture bookkeeping: save + restore the live chat_bridge_* settings,
//    delete + recreate our own managed rows, so a run of this test never
//    permanently disturbs a shared dev database another session is also
//    using. ─────────────────────────────────────────────────────────
$definitions = chat_bridge_definitions();
// array_values() matters here: $definitions is keyed by setting name
// (chat_bridge_slack, ...), and array_map() preserves those string keys.
// PDO's positional (?) binding needs a plain 0-indexed array.
$managedKeys = array_values(array_map(function ($d) { return $d['managed_key']; }, $definitions));
$settingKeys = array_keys($definitions);

$origSettings = [];
foreach ($settingKeys as $k) {
    $v = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
    $origSettings[$k] = ($v === null || $v === false) ? null : $v;
}

function cb_set_setting(string $key, string $value): void {
    global $prefix;
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
        [$key, $value]
    );
}

function cb_delete_managed(array $managedKeys): void {
    global $prefix;
    if (empty($managedKeys)) return;
    $ph = implode(', ', array_fill(0, count($managedKeys), '?'));
    db_query("DELETE FROM `{$prefix}message_routes` WHERE `managed_key` IN ($ph)", $managedKeys);
}

function cb_restore_settings(array $orig): void {
    global $prefix;
    foreach ($orig as $k => $v) {
        if ($v === null) {
            db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$k]);
        } else {
            db_query(
                "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$k, $v]
            );
        }
    }
}

// Start from a clean slate: all four boxes "off", no managed rows.
cb_delete_managed($managedKeys);
db_query("DELETE FROM `{$prefix}message_routes` WHERE `name` LIKE 'TEST\\_CB\\_%' ESCAPE '\\\\'");
db_query("DELETE FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE '%CHAT_BRIDGE_TEST%'");
db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE '%CHAT_BRIDGE_TEST%'");
foreach ($settingKeys as $k) { cb_set_setting($k, '0'); }

// ── Test 1: schema — managed / managed_key columns exist ──
$cols = db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'message_routes']
);
$colNames = array_map(function ($c) { return strtolower($c['COLUMN_NAME']); }, $cols);
cb_assert('1. message_routes.managed column exists', in_array('managed', $colNames, true));
cb_assert('2. message_routes.managed_key column exists', in_array('managed_key', $colNames, true));

$idx = db_fetch_all(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'managed_key'",
    [$prefix . 'message_routes']
);
cb_assert('3. managed_key has a unique index', !empty($idx));

// ── Test 4: chat_bridge_definitions() shape ──
cb_assert('4a. exactly 4 checkbox definitions', count($definitions) === 4);
cb_assert('4b. all 4 canonical setting keys present', empty(array_diff(
    ['chat_bridge_telegram', 'chat_bridge_slack', 'chat_bridge_email', 'chat_bridge_mesh'],
    array_keys($definitions)
)));
$destChannels = array_map(function ($d) { return $d['dest_channel']; }, $definitions);
sort($destChannels);
cb_assert('4c. dest channels are exactly slack/telegram/email/meshtastic',
    $destChannels === ['email', 'meshtastic', 'slack', 'telegram'],
    implode(',', $destChannels));
cb_assert('4d. managed_keys are unique', count($managedKeys) === count(array_unique($managedKeys)));

// ── Test 5: checkbox off -> sync creates nothing ──
$sync = chat_bridge_sync();
$rowCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}message_routes` WHERE `managed_key` IN (" .
    implode(',', array_fill(0, count($managedKeys), '?')) . ")", $managedKeys
);
cb_assert('5. all boxes off -> zero managed rows after sync', $rowCount === 0 && $sync['created'] === 0,
    "created={$sync['created']} rowCount=$rowCount");

// ── Test 6: checkbox on -> creates exactly one correctly-shaped row ──
cb_set_setting('chat_bridge_slack', '1');
$sync = chat_bridge_sync();
$slackRoute = db_fetch_one(
    "SELECT * FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:slack']
);
cb_assert('6a. sync reports one created', $sync['created'] === 1, json_encode($sync));
cb_assert('6b. row exists', $slackRoute !== null);
if ($slackRoute) {
    cb_assert('6c. source_channel = local_chat', $slackRoute['source_channel'] === 'local_chat');
    cb_assert('6d. dest_channel = slack', $slackRoute['dest_channel'] === 'slack');
    cb_assert('6e. enabled = 1', (int) $slackRoute['enabled'] === 1);
    cb_assert('6f. managed = 1', (int) $slackRoute['managed'] === 1);
    cb_assert('6g. direction = outbound', $slackRoute['direction'] === 'outbound');
}
$slackRouteId = $slackRoute['id'] ?? 0;

// ── Test 7: idempotent — re-running sync with no change creates no duplicate ──
$sync2 = chat_bridge_sync();
$dupeCount = (int) db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:slack']
);
cb_assert('7. re-sync with no change -> still exactly 1 row, same id', $dupeCount === 1 && (
    (int) db_fetch_value("SELECT `id` FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:slack']) === $slackRouteId
), "dupeCount=$dupeCount sync2=" . json_encode($sync2));

// ── Test 8: sync never clobbers a hand-created row ──
// Simulate an admin who already built their OWN local_chat -> slack rule
// by hand (managed=0, no managed_key) before ever touching this checkbox.
db_query(
    "INSERT INTO `{$prefix}message_routes`
        (`name`, `description`, `enabled`, `priority`, `source_channel`, `dest_channel`,
         `direction`, `filters_json`, `managed`, `managed_key`, `created_by`)
     VALUES ('TEST_CB_Handmade_Slack_Rule', 'hand-built by an admin', 1, 50, 'local_chat', 'slack',
             'both', '{\"keywords\":[\"mayday\"]}', 0, NULL, 1)"
);
$handmadeId = (int) db_insert_id();

cb_set_setting('chat_bridge_telegram', '1');
$sync = chat_bridge_sync();
$handmadeAfter = db_fetch_one("SELECT * FROM `{$prefix}message_routes` WHERE `id` = ?", [$handmadeId]);
$telegramRoute = db_fetch_one("SELECT * FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:telegram']);
cb_assert('8a. hand-made rule still exists, completely unchanged',
    $handmadeAfter !== null
    && $handmadeAfter['name'] === 'TEST_CB_Handmade_Slack_Rule'
    && $handmadeAfter['description'] === 'hand-built by an admin'
    && (int) $handmadeAfter['enabled'] === 1
    && (int) $handmadeAfter['managed'] === 0
    && $handmadeAfter['managed_key'] === null
);
cb_assert('8b. a SEPARATE managed telegram row was created alongside it',
    $telegramRoute !== null && (int) $telegramRoute['managed'] === 1);
cb_assert('8c. checking the box did not touch the pre-existing slack row',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}message_routes` WHERE `dest_channel` = 'slack'") === 2,
    'expected exactly 2 slack-destination rows (1 hand-made + 1 managed)');

// ── Test 9: disable-not-delete + admin customization survives ──
db_query(
    "UPDATE `{$prefix}message_routes` SET `description` = ?, `filters_json` = ? WHERE `managed_key` = ?",
    ['ADMIN_CUSTOMIZED description', '{"keywords":["mci"]}', 'chat_bridge:slack']
);
cb_set_setting('chat_bridge_slack', '0');
chat_bridge_sync();
$afterDisable = db_fetch_one("SELECT * FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:slack']);
cb_assert('9a. toggling off DISABLES the row (not delete)', $afterDisable !== null && (int) $afterDisable['enabled'] === 0);
cb_assert('9b. customization survives being disabled',
    $afterDisable['description'] === 'ADMIN_CUSTOMIZED description'
    && $afterDisable['filters_json'] === '{"keywords":["mci"]}');

cb_set_setting('chat_bridge_slack', '1');
chat_bridge_sync();
$afterReenable = db_fetch_one("SELECT * FROM `{$prefix}message_routes` WHERE `managed_key` = ?", ['chat_bridge:slack']);
cb_assert('9c. toggling back on RE-ENABLES the SAME row', $afterReenable !== null
    && (int) $afterReenable['id'] === $slackRouteId && (int) $afterReenable['enabled'] === 1);
cb_assert('9d. customization survives re-enabling too (not reset to system default)',
    $afterReenable['description'] === 'ADMIN_CUSTOMIZED description'
    && $afterReenable['filters_json'] === '{"keywords":["mci"]}');

// ── Test 10: _router_message_is_local_chat_dm() truth table (unit-level) ──
cb_assert('10a. numeric positive to -> DM', _router_message_is_local_chat_dm(['to' => '5']) === true);
cb_assert('10b. numeric positive int to -> DM', _router_message_is_local_chat_dm(['to' => 5]) === true);
cb_assert("10c. to='all' -> not DM", _router_message_is_local_chat_dm(['to' => 'all']) === false);
cb_assert("10d. to='general' (room name) -> not DM", _router_message_is_local_chat_dm(['to' => 'general']) === false);
cb_assert('10e. missing to (defaults to all) -> not DM', _router_message_is_local_chat_dm([]) === false);
cb_assert("10f. to='0' -> not DM (invalid user id)", _router_message_is_local_chat_dm(['to' => '0']) === false);
cb_assert("10g. to='-5' -> not DM (negative invalid)", _router_message_is_local_chat_dm(['to' => '-5']) === false);
cb_assert("10h. to='' (empty string) -> not DM", _router_message_is_local_chat_dm(['to' => '']) === false);

// ── Test 11: router_test() dry-run mirrors the DM guard ──
// Ensure at least one enabled local_chat route exists for this channel
// (slack + telegram managed rows are enabled at this point in the run).
$dryRunDm = router_test('local_chat', 'outbound', ['to' => '9', 'body' => 'x', 'channel' => 'general']);
cb_assert('11. router_test() on a DM payload returns no matches', $dryRunDm === []);

// ── Test 12: THE SECURITY-CRITICAL TEST — a real DM sent through the
//    real entry point is never forwarded, while a real public room
//    message through the SAME entry point and the SAME enabled routes
//    IS considered for forwarding (proves this is a true DM filter, not
//    something that silently blocks everything). ──
$dmMarker = 'CHAT_BRIDGE_TEST DM secret ' . uniqid();
$roomMarker = 'CHAT_BRIDGE_TEST room broadcast ' . uniqid();

// A real target user id — the router's job is only to decide DM-ness from
// the numeric shape of `to`, not to validate the id resolves to a real
// account, so any positive integer exercises the guard identically.
$dmResult = broker_send('local_chat', [
    'body'    => $dmMarker,
    'to'      => '999999',
    'channel' => 'general',
    'type'    => 'text',
]);
cb_assert('12a. sending the DM itself still succeeds (only bridging is blocked)',
    ($dmResult['success'] ?? false) === true, json_encode($dmResult));

$dmLogRows = db_fetch_all(
    "SELECT * FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE ?",
    ['%' . substr($dmMarker, 0, 50) . '%']
);
$dmForwardedAny = false;
$dmAllSkippedForDmReason = !empty($dmLogRows);
foreach ($dmLogRows as $r) {
    if ($r['status'] === 'forwarded') $dmForwardedAny = true;
    if ($r['status'] !== 'skipped' || strpos((string) $r['error'], 'never bridged') === false) {
        $dmAllSkippedForDmReason = false;
    }
}
cb_assert('12b. the DM produced at least one routing_log entry (the guard actually ran)', !empty($dmLogRows),
    'expected at least one candidate route for local_chat (slack/telegram managed rows should be enabled here)');
cb_assert('12c. NOTHING was ever forwarded for the DM', $dmForwardedAny === false);
cb_assert('12d. every candidate route logged "skipped" with the DM-exclusion reason', $dmAllSkippedForDmReason);

$roomResult = broker_send('local_chat', [
    'body'    => $roomMarker,
    'to'      => 'all',
    'channel' => 'general',
    'type'    => 'text',
]);
cb_assert('12e. sending the public room message succeeds',
    ($roomResult['success'] ?? false) === true, json_encode($roomResult));

$roomLogRows = db_fetch_all(
    "SELECT * FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE ?",
    ['%' . substr($roomMarker, 0, 50) . '%']
);
$roomNeverGotDmReason = true;
foreach ($roomLogRows as $r) {
    if ($r['status'] === 'skipped' && strpos((string) $r['error'], 'never bridged') !== false) {
        $roomNeverGotDmReason = false;
    }
}
cb_assert('12f. the public room message produced routing_log entries too (same candidate routes)', !empty($roomLogRows));
cb_assert('12g. the public room message was NEVER skipped for the DM-exclusion reason', $roomNeverGotDmReason,
    'a genuinely public message must not be caught by the DM guard, or the guard is over-broad');

// ── Cleanup: never leave the shared dev DB in a state another session
//    (or a later run of this file) has to work around. ──
echo "\nCleaning up test data...\n";
cb_delete_managed($managedKeys);
db_query("DELETE FROM `{$prefix}message_routes` WHERE `id` = ?", [$handmadeId]);
db_query("DELETE FROM `{$prefix}message_routes` WHERE `name` LIKE 'TEST\\_CB\\_%' ESCAPE '\\\\'");
db_query("DELETE FROM `{$prefix}routing_log` WHERE `payload_summary` LIKE '%CHAT_BRIDGE_TEST%'");
db_query("DELETE FROM `{$prefix}chat_messages` WHERE `body` LIKE '%CHAT_BRIDGE_TEST%'");
cb_restore_settings($origSettings);

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
