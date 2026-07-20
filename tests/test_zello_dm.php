<?php
/**
 * Phase E (messaging-send-gaps-2026-06) — Zello user-DM + inbound reply tests.
 *
 * Covers the three Phase-E send/reply contracts WITHOUT hitting the network
 * (the upstream WebSocket connection is replaced with a capturing fake):
 *
 *   1. ZelloUpstream::sendTextMessage() builds the right Zello JSON —
 *        - channel send: {command,seq,text,channel}        (no `for`)
 *        - user DM:      {command,seq,text,channel,for:user} (the `for` field)
 *   2. ZelloProxyApp::pollZelloOutbox() routes a queued row correctly:
 *        - a channel row  → sendTextMessage(body, channel, '')   (no recipient)
 *        - a user-DM row  → sendTextMessage(body, channel, user) (recipient set)
 *      and marks the row sent + mirrors it into zello_messages.
 *   3. The Zello inbox surfaces an inbound DM reply-ably, and a reply queues
 *      the right zello_outbox row (channel vs user) — the api/zello-inbox.php
 *      DB contract, exercised directly.
 *   4. Migration sql/run_zello_dm.php is idempotent + adds the recipient col.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';

// The proxy classes call a global plog() (defined in zello-proxy.php's CLI
// bootstrap). Provide a no-op so they load + run under the test harness.
if (!function_exists('plog')) {
    function plog($msg) { /* silent in tests */ }
}

// Ratchet lives in composer's vendor/ — not present on a fresh checkout
// or CI unless `composer install` ran. Skip cleanly instead of fataling.
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "SKIP: vendor/autoload.php not found (run 'composer install' to enable Zello proxy tests)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../proxy/ZelloUpstream.php';
require __DIR__ . '/../proxy/ZelloProxyApp.php';

use NewUI\Proxy\ZelloUpstream;
use NewUI\Proxy\ZelloProxyApp;

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Zello DM + Inbound Reply Tests (Phase E) ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function t_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

/**
 * A fake Zello upstream WS connection: captures every send() payload so a
 * test can assert what command JSON would have gone on the wire — no network.
 */
class FakeWsConn {
    public $sent = [];
    public function send($data) { $this->sent[] = (string) $data; }
    public function close() {}
}

// ── Ensure schema: zello tables + Phase D outbox + Phase E recipient col ──
try {
    // Base zello tables (idempotent — mirrors sql/zello_tables.sql).
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}zello_messages` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `channel` VARCHAR(100) NOT NULL DEFAULT '',
        `direction` ENUM('incoming','outgoing') NOT NULL DEFAULT 'incoming',
        `message_type` VARCHAR(20) NOT NULL DEFAULT 'text',
        `sender_username` VARCHAR(100) NOT NULL DEFAULT '',
        `sender_display` VARCHAR(100) NOT NULL DEFAULT '',
        `content` TEXT, `incident_id` INT UNSIGNED DEFAULT NULL,
        `duration_ms` INT DEFAULT NULL, `media_url` VARCHAR(255) DEFAULT NULL,
        `created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_channel (`channel`), INDEX idx_created (`created`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* may exist */ }

// Run the Phase-D + Phase-E migrations the way an install would.
ob_start();
require __DIR__ . '/../sql/run_route_subaddress.php';
require __DIR__ . '/../sql/run_zello_dm.php';
ob_end_clean();

// Clean up any leftover test rows.
db_query("DELETE FROM `{$prefix}zello_outbox`   WHERE `body` LIKE 'ZTEST_%'");
db_query("DELETE FROM `{$prefix}zello_messages` WHERE `content` LIKE 'ZTEST_%'");

// ──────────────────────────────────────────────────────────────────────
// 1. Migration idempotency + recipient column present
// ──────────────────────────────────────────────────────────────────────
echo "-- Migration --\n";
$hasRecip = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'recipient'",
    [$prefix . 'zello_messages']
);
t_ok("run_zello_dm.php adds zello_messages.recipient", $hasRecip);

// Re-run → must not error (idempotent).
$idemOk = true;
try {
    ob_start();
    require __DIR__ . '/../sql/run_zello_dm.php';
    ob_end_clean();
} catch (Throwable $e) { $idemOk = false; ob_end_clean(); }
t_ok("run_zello_dm.php is idempotent (re-run clean)", $idemOk);

// ──────────────────────────────────────────────────────────────────────
// 2. ZelloUpstream::sendTextMessage — channel vs `for` DM JSON
// ──────────────────────────────────────────────────────────────────────
echo "\n-- ZelloUpstream::sendTextMessage --\n";

$loop = React\EventLoop\Loop::get();
$up = new ZelloUpstream($loop, [], function () {}, function () {}, function () {});

// Inject a fake authenticated upstream connection via reflection.
$ref = new ReflectionClass($up);
$pUp = $ref->getProperty('upstream');      $pUp->setAccessible(true);
$pAu = $ref->getProperty('authenticated'); $pAu->setAccessible(true);
$fake = new FakeWsConn();
$pUp->setValue($up, $fake);
$pAu->setValue($up, true);

// Channel send (no recipient).
$fake->sent = [];
$ret = $up->sendTextMessage('ZTEST_channel', 'Dispatch');
$chanCmd = json_decode($fake->sent[0] ?? '{}', true);
t_ok("channel send returns true", $ret === true);
t_ok("channel send command = send_text_message", ($chanCmd['command'] ?? '') === 'send_text_message');
t_ok("channel send carries channel", ($chanCmd['channel'] ?? '') === 'Dispatch');
t_ok("channel send has NO `for` field (broadcast)", !array_key_exists('for', $chanCmd));
t_ok("channel send carries text", ($chanCmd['text'] ?? '') === 'ZTEST_channel');

// User DM (recipient set → `for`).
$fake->sent = [];
$ret = $up->sendTextMessage('ZTEST_dm', 'Dispatch', 'unit12');
$dmCmd = json_decode($fake->sent[0] ?? '{}', true);
t_ok("user DM returns true", $ret === true);
t_ok("user DM sets `for` = recipient username", ($dmCmd['for'] ?? '') === 'unit12');
t_ok("user DM still carries channel context (Zello requires it)", ($dmCmd['channel'] ?? '') === 'Dispatch');
t_ok("user DM carries text", ($dmCmd['text'] ?? '') === 'ZTEST_dm');

// Empty recipient string must behave exactly like a channel send.
$fake->sent = [];
$up->sendTextMessage('ZTEST_empty', 'Dispatch', '');
$emptyCmd = json_decode($fake->sent[0] ?? '{}', true);
t_ok("empty recipient omits `for`", !array_key_exists('for', $emptyCmd));

// Not authenticated → send refused (returns false, nothing on the wire).
$pAu->setValue($up, false);
$fake->sent = [];
$ret = $up->sendTextMessage('ZTEST_noauth', 'Dispatch', 'unit12');
t_ok("send refused when not authenticated", $ret === false && count($fake->sent) === 0);
$pAu->setValue($up, true);

// ──────────────────────────────────────────────────────────────────────
// 3. ZelloProxyApp::pollZelloOutbox — channel vs user routing
// ──────────────────────────────────────────────────────────────────────
echo "\n-- ZelloProxyApp::pollZelloOutbox routing --\n";

/**
 * A fake ZelloUpstream that captures sendTextMessage() args instead of
 * relaying. Lets us assert the proxy passes the recipient through for a
 * user-DM row and an empty recipient for a channel row.
 */
class FakeUpstream {
    public $calls = [];
    public function isConnected(): bool { return true; }
    public function sendTextMessage(string $text, string $channel = '', string $recipient = ''): bool {
        $this->calls[] = ['text' => $text, 'channel' => $channel, 'recipient' => $recipient];
        return true;
    }
}

// Build a ZelloProxyApp without running Ratchet's server. The constructor
// only needs the loop, config, PDO + prefix; we inject a fake upstream.
// inc/db.php exposes the singleton PDO via db().
$pdo = function_exists('db') ? db() : null;
t_ok("test has a PDO handle for the proxy", $pdo instanceof PDO);

if ($pdo instanceof PDO) {
    $app = new ZelloProxyApp($loop, ['zello_dispatch_channel' => 'DefaultCh'], $pdo, $prefix);
    $appRef = new ReflectionClass($app);
    $pAppUp = $appRef->getProperty('upstream'); $pAppUp->setAccessible(true);

    // ── channel row ──
    db_query("INSERT INTO `{$prefix}zello_outbox`
                (kind, channel, recipient, body, status, source)
              VALUES ('text', 'Dispatch', '', 'ZTEST_outbox_channel', 'queued', 'router')");
    $chOid = (int) db_insert_id();

    $fakeUp1 = new FakeUpstream();
    $pAppUp->setValue($app, $fakeUp1);
    $app->pollZelloOutbox();

    $chRow = db_fetch_one("SELECT status FROM `{$prefix}zello_outbox` WHERE id = ?", [$chOid]);
    $chCall = $fakeUp1->calls[0] ?? null;
    t_ok("channel outbox row marked sent", ($chRow['status'] ?? '') === 'sent');
    t_ok("channel outbox relayed with empty recipient",
        $chCall && $chCall['recipient'] === '' && $chCall['channel'] === 'Dispatch');
    $chMirror = db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}zello_messages`
          WHERE content = 'ZTEST_outbox_channel' AND direction = 'outgoing'");
    t_ok("channel send mirrored into zello_messages", (int) $chMirror === 1);

    // ── user-DM row ──
    db_query("INSERT INTO `{$prefix}zello_outbox`
                (kind, channel, recipient, body, status, source)
              VALUES ('text', 'Dispatch', 'unit12', 'ZTEST_outbox_dm', 'queued', 'router')");
    $dmOid = (int) db_insert_id();

    $fakeUp2 = new FakeUpstream();
    $pAppUp->setValue($app, $fakeUp2);
    $app->pollZelloOutbox();

    $dmRow  = db_fetch_one("SELECT status FROM `{$prefix}zello_outbox` WHERE id = ?", [$dmOid]);
    $dmCall = $fakeUp2->calls[0] ?? null;
    t_ok("user-DM outbox row marked sent", ($dmRow['status'] ?? '') === 'sent');
    t_ok("user-DM outbox relayed with recipient = unit12",
        $dmCall && $dmCall['recipient'] === 'unit12' && $dmCall['channel'] === 'Dispatch');
    $dmMirror = db_fetch_one(
        "SELECT recipient FROM `{$prefix}zello_messages`
          WHERE content = 'ZTEST_outbox_dm' AND direction = 'outgoing' LIMIT 1");
    t_ok("user-DM send mirrored into zello_messages with recipient",
        $dmMirror && ($dmMirror['recipient'] ?? '') === 'unit12');

    // Blank channel falls back to the proxy's default dispatch channel.
    db_query("INSERT INTO `{$prefix}zello_outbox`
                (kind, channel, recipient, body, status, source)
              VALUES ('text', '', '', 'ZTEST_outbox_default', 'queued', 'router')");
    $defOid = (int) db_insert_id();
    $fakeUp3 = new FakeUpstream();
    $pAppUp->setValue($app, $fakeUp3);
    $app->pollZelloOutbox();
    $defCall = $fakeUp3->calls[0] ?? null;
    t_ok("blank-channel row falls back to default dispatch channel",
        $defCall && $defCall['channel'] === 'DefaultCh');
}

// ──────────────────────────────────────────────────────────────────────
// 4. Inbound surfacing + reply queue contract (api/zello-inbox.php logic)
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Inbound surfacing + reply --\n";

// Seed an inbound CHANNEL text and an inbound DM (recipient = sender = the
// DM partner, the way the proxy records it).
db_query("INSERT INTO `{$prefix}zello_messages`
            (channel, recipient, direction, message_type, sender_username, sender_display, content)
          VALUES ('Dispatch', '', 'incoming', 'text', 'fieldbob', 'Field Bob', 'ZTEST_in_channel')");
$inChanId = (int) db_insert_id();

db_query("INSERT INTO `{$prefix}zello_messages`
            (channel, recipient, direction, message_type, sender_username, sender_display, content)
          VALUES ('Dispatch', 'fieldbob', 'incoming', 'text', 'fieldbob', 'Field Bob', 'ZTEST_in_dm')");
$inDmId = (int) db_insert_id();

// The inbox surfacing query/logic (mirrors api/zello-inbox.php inbox action).
$rows = db_fetch_all(
    "SELECT `id`, `channel`, `recipient`, `sender_username`, `sender_display`, `content`
       FROM `{$prefix}zello_messages`
      WHERE `direction` = 'incoming' AND `message_type` = 'text'
        AND `content` IN ('ZTEST_in_channel','ZTEST_in_dm')
      ORDER BY `id` DESC"
);
$byContent = [];
foreach ($rows as $r) {
    $isDm = ($r['recipient'] !== '' && $r['recipient'] !== null);
    $r['is_dm']        = $isDm;
    $r['reply_kind']   = $isDm ? 'user' : 'channel';
    $r['reply_target'] = $isDm ? $r['sender_username'] : $r['channel'];
    $byContent[$r['content']] = $r;
}
t_ok("inbound channel row surfaces (reply_kind=channel)",
    isset($byContent['ZTEST_in_channel']) && $byContent['ZTEST_in_channel']['reply_kind'] === 'channel');
t_ok("inbound channel reply targets the channel",
    isset($byContent['ZTEST_in_channel']) && $byContent['ZTEST_in_channel']['reply_target'] === 'Dispatch');
t_ok("inbound DM row surfaces (reply_kind=user)",
    isset($byContent['ZTEST_in_dm']) && $byContent['ZTEST_in_dm']['reply_kind'] === 'user');
t_ok("inbound DM reply targets the sender for a DM-back",
    isset($byContent['ZTEST_in_dm']) && $byContent['ZTEST_in_dm']['reply_target'] === 'fieldbob');

// Reply to the CHANNEL inbound (mode defaults to channel) → zello_outbox row
// with empty recipient.
db_query("INSERT INTO `{$prefix}zello_outbox`
            (kind, channel, recipient, body, status, source)
          VALUES ('text', ?, '', 'ZTEST_reply_channel', 'queued', 'inbox')",
    [$byContent['ZTEST_in_channel']['channel']]);
$rcOid = (int) db_insert_id();
$rcRow = db_fetch_one("SELECT channel, recipient FROM `{$prefix}zello_outbox` WHERE id = ?", [$rcOid]);
t_ok("channel reply queues zello_outbox with channel + no recipient",
    $rcRow && $rcRow['channel'] === 'Dispatch' && $rcRow['recipient'] === '');

// Reply to the DM inbound (mode defaults to user) → zello_outbox row with
// recipient = the original sender.
db_query("INSERT INTO `{$prefix}zello_outbox`
            (kind, channel, recipient, body, status, source)
          VALUES ('text', ?, ?, 'ZTEST_reply_dm', 'queued', 'inbox')",
    [$byContent['ZTEST_in_dm']['channel'], $byContent['ZTEST_in_dm']['sender_username']]);
$rdOid = (int) db_insert_id();
$rdRow = db_fetch_one("SELECT channel, recipient FROM `{$prefix}zello_outbox` WHERE id = ?", [$rdOid]);
t_ok("DM reply queues zello_outbox with recipient = original sender",
    $rdRow && $rdRow['recipient'] === 'fieldbob' && $rdRow['channel'] === 'Dispatch');

// ──────────────────────────────────────────────────────────────────────
// 5. Source-level wiring guards (catch silent regressions)
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Source wiring --\n";
$upSrc    = file_get_contents(__DIR__ . '/../proxy/ZelloUpstream.php');
$appSrc   = file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');
$inboxSrc = file_get_contents(__DIR__ . '/../api/zello-inbox.php');

t_ok("ZelloUpstream::sendTextMessage signature takes \$recipient",
    (bool) preg_match('/function sendTextMessage\([^)]*\$recipient/s', $upSrc));
t_ok("ZelloUpstream sets the `for` field for a DM",
    strpos($upSrc, "\$cmd['for']") !== false);
t_ok("pollZelloOutbox reads the recipient column + passes it through",
    strpos($appSrc, "row['recipient']") !== false
    && (bool) preg_match('/sendTextMessage\(\s*\$body,\s*\$channel,\s*\$recipient/', $appSrc));
t_ok("zello-inbox.php has inbox + reply + reply_status actions",
    strpos($inboxSrc, "=== 'inbox'") !== false
    && strpos($inboxSrc, "=== 'reply'") !== false
    && strpos($inboxSrc, "=== 'reply_status'") !== false);
t_ok("zello-inbox.php reply verifies CSRF",
    strpos($inboxSrc, 'csrf_verify') !== false);
t_ok("zello-inbox.php never sends RF itself (queues zello_outbox only)",
    strpos($inboxSrc, 'INSERT INTO') !== false
    && strpos($inboxSrc, 'zello_outbox') !== false
    && strpos($inboxSrc, 'sendTextMessage') === false);

// ── Cleanup ──
db_query("DELETE FROM `{$prefix}zello_outbox`   WHERE `body` LIKE 'ZTEST_%'");
db_query("DELETE FROM `{$prefix}zello_messages` WHERE `content` LIKE 'ZTEST_%'");

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
