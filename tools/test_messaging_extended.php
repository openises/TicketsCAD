<?php
/**
 * Extended Messaging Tests
 *
 * Additional tests beyond test_messaging.php covering:
 * - Table existence and schema
 * - Message send with both tables
 * - Unread count queries
 * - Soft delete via deleted_at
 * - Broadcast creates recipients for all users
 *
 * Usage: php tools/test_messaging_extended.php
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0;
$failed = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

echo "=== Extended Messaging Tests ===\n\n";

// ── Test 1: Tables exist ──────────────────────────────────────
echo "-- Table Existence --\n";

try {
    $t1 = db_fetch_all(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'internal_messages']
    );
    test('internal_messages table exists (info_schema)', count($t1) > 0);
} catch (Exception $e) {
    test('internal_messages table exists', false);
}

try {
    $t2 = db_fetch_all(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'message_recipients']
    );
    test('message_recipients table exists (info_schema)', count($t2) > 0);
} catch (Exception $e) {
    test('message_recipients table exists', false);
}

// ── Test 2: Engine is InnoDB ──────────────────────────────────
echo "\n-- Engine Checks --\n";

try {
    $engine1 = db_fetch_one(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'internal_messages']
    );
    test('internal_messages uses InnoDB', strtolower($engine1['ENGINE']) === 'innodb');
} catch (Exception $e) {
    test('internal_messages engine check', false);
}

try {
    $engine2 = db_fetch_one(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'message_recipients']
    );
    test('message_recipients uses InnoDB', strtolower($engine2['ENGINE']) === 'innodb');
} catch (Exception $e) {
    test('message_recipients engine check', false);
}

// ── Test 3: Send a message (INSERT into both tables) ──────────
echo "\n-- Message Send --\n";

$testMsgId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}internal_messages`
         (from_user_id, subject, body, priority)
         VALUES (?, ?, ?, ?)",
        [1, 'Extended Test Message', 'Body of extended test message.', 'normal']
    );
    $testMsgId = (int) db_insert_id();
    test('Message inserted into internal_messages', $testMsgId > 0);

    // Add two recipients
    db_query(
        "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id)
         VALUES (?, ?), (?, ?)",
        [$testMsgId, 2, $testMsgId, 3]
    );
    $recipCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients` WHERE message_id = ?",
        [$testMsgId]
    );
    test('Two recipients created for message', $recipCount === 2);
} catch (Exception $e) {
    test('Message send: ' . $e->getMessage(), false);
}

// ── Test 4: Unread count query works ──────────────────────────
echo "\n-- Unread Count --\n";

try {
    $unreadUser2 = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients`
         WHERE to_user_id = ? AND read_at IS NULL AND deleted_at IS NULL
           AND message_id = ?",
        [2, $testMsgId]
    );
    test('Unread count for user 2 is 1', $unreadUser2 === 1);

    $unreadUser3 = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients`
         WHERE to_user_id = ? AND read_at IS NULL AND deleted_at IS NULL
           AND message_id = ?",
        [3, $testMsgId]
    );
    test('Unread count for user 3 is 1', $unreadUser3 === 1);
} catch (Exception $e) {
    test('Unread count query', false);
}

// ── Test 5: Soft delete sets deleted_at ───────────────────────
echo "\n-- Soft Delete --\n";

try {
    db_query(
        "UPDATE `{$prefix}message_recipients`
         SET deleted_at = NOW()
         WHERE message_id = ? AND to_user_id = ?",
        [$testMsgId, 3]
    );

    // Verify deleted_at is set
    $rec = db_fetch_one(
        "SELECT deleted_at FROM `{$prefix}message_recipients`
         WHERE message_id = ? AND to_user_id = ?",
        [$testMsgId, 3]
    );
    test('Soft delete sets deleted_at', $rec !== null && $rec['deleted_at'] !== null);

    // Verify it is excluded from inbox query
    $inbox = db_fetch_all(
        "SELECT mr.* FROM `{$prefix}message_recipients` mr
         WHERE mr.to_user_id = ? AND mr.deleted_at IS NULL
           AND mr.message_id = ?",
        [3, $testMsgId]
    );
    test('Soft-deleted message excluded from inbox', count($inbox) === 0);

    // User 2 still sees it
    $inbox2 = db_fetch_all(
        "SELECT mr.* FROM `{$prefix}message_recipients` mr
         WHERE mr.to_user_id = ? AND mr.deleted_at IS NULL
           AND mr.message_id = ?",
        [2, $testMsgId]
    );
    test('Non-deleted user 2 still sees message', count($inbox2) === 1);
} catch (Exception $e) {
    test('Soft delete: ' . $e->getMessage(), false);
}

// ── Test 6: Broadcast creates recipients for all users ────────
echo "\n-- Broadcast --\n";

$broadcastId = null;
try {
    // Count all users
    $userCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
    test('User table has records', $userCount > 0);

    // Create broadcast message
    db_query(
        "INSERT INTO `{$prefix}internal_messages`
         (from_user_id, subject, body, priority, is_broadcast)
         VALUES (?, ?, ?, 'urgent', 1)",
        [1, 'Broadcast Test', 'Emergency broadcast test message']
    );
    $broadcastId = (int) db_insert_id();
    test('Broadcast message created', $broadcastId > 0);

    // Simulate creating recipients for all users (as the API would)
    $users = db_fetch_all("SELECT id FROM `{$prefix}user`");
    $insertCount = 0;
    foreach ($users as $u) {
        db_query(
            "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id)
             VALUES (?, ?)",
            [$broadcastId, (int) $u['id']]
        );
        $insertCount++;
    }
    test('Recipients created for all users', $insertCount === $userCount);

    // Verify recipient count matches user count
    $recipTotal = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients` WHERE message_id = ?",
        [$broadcastId]
    );
    test('Recipient count matches user count', $recipTotal === $userCount);

    // Verify the broadcast flag
    $bMsg = db_fetch_one(
        "SELECT is_broadcast, priority FROM `{$prefix}internal_messages` WHERE id = ?",
        [$broadcastId]
    );
    test('Broadcast flag is 1', (int) $bMsg['is_broadcast'] === 1);
    test('Broadcast priority is urgent', $bMsg['priority'] === 'urgent');
} catch (Exception $e) {
    test('Broadcast: ' . $e->getMessage(), false);
}

// ── Test 7: Incident linking on messages ──────────────────────
echo "\n-- Incident Linking --\n";

$incidentMsgId = null;
try {
    $ticket = db_fetch_one("SELECT id FROM `{$prefix}ticket` LIMIT 1");
    if ($ticket) {
        db_query(
            "INSERT INTO `{$prefix}internal_messages`
             (from_user_id, subject, body, incident_id)
             VALUES (?, ?, ?, ?)",
            [1, 'Incident Linked Message', 'Message about incident', (int) $ticket['id']]
        );
        $incidentMsgId = (int) db_insert_id();
        $linked = db_fetch_one(
            "SELECT incident_id FROM `{$prefix}internal_messages` WHERE id = ?",
            [$incidentMsgId]
        );
        test('Message linked to incident', (int) $linked['incident_id'] === (int) $ticket['id']);
    } else {
        test('(skipped - no tickets for incident linking)', true);
    }
} catch (Exception $e) {
    test('Incident linking: ' . $e->getMessage(), false);
}

// ── Cleanup ───────────────────────────────────────────────────
try {
    if ($testMsgId) {
        db_query("DELETE FROM `{$prefix}message_recipients` WHERE message_id = ?", [$testMsgId]);
        db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$testMsgId]);
    }
    if ($broadcastId) {
        db_query("DELETE FROM `{$prefix}message_recipients` WHERE message_id = ?", [$broadcastId]);
        db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$broadcastId]);
    }
    if ($incidentMsgId) {
        db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$incidentMsgId]);
    }
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
