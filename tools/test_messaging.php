<?php
/**
 * NewUI v4.0 - Internal Messaging Tests
 *
 * Tests the internal_messages and message_recipients tables,
 * insert/read/update operations, and unread count queries.
 *
 * Usage: php tools/test_messaging.php
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

echo "=== Internal Messaging Tests ===\n\n";

// ── Test 1: Tables exist ──────────────────────────────────
try {
    $tables = db_fetch_all("SHOW TABLES LIKE '%internal_messages'");
    test('internal_messages table exists', count($tables) > 0);
} catch (Exception $e) {
    test('internal_messages table exists', false);
}

try {
    $tables = db_fetch_all("SHOW TABLES LIKE '%message_recipients'");
    test('message_recipients table exists', count($tables) > 0);
} catch (Exception $e) {
    test('message_recipients table exists', false);
}

// ── Test 2: internal_messages columns ─────────────────────
try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}internal_messages`");
    $colNames = array_column($cols, 'Field');
    test('internal_messages has id column', in_array('id', $colNames));
    test('internal_messages has from_user_id column', in_array('from_user_id', $colNames));
    test('internal_messages has subject column', in_array('subject', $colNames));
    test('internal_messages has body column', in_array('body', $colNames));
    test('internal_messages has priority column', in_array('priority', $colNames));
    test('internal_messages has incident_id column', in_array('incident_id', $colNames));
    test('internal_messages has is_broadcast column', in_array('is_broadcast', $colNames));
    test('internal_messages has created_at column', in_array('created_at', $colNames));
} catch (Exception $e) {
    test('internal_messages column check', false);
}

// ── Test 3: message_recipients columns ────────────────────
try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}message_recipients`");
    $colNames = array_column($cols, 'Field');
    test('message_recipients has id column', in_array('id', $colNames));
    test('message_recipients has message_id column', in_array('message_id', $colNames));
    test('message_recipients has to_user_id column', in_array('to_user_id', $colNames));
    test('message_recipients has read_at column', in_array('read_at', $colNames));
    test('message_recipients has deleted_at column', in_array('deleted_at', $colNames));
} catch (Exception $e) {
    test('message_recipients column check', false);
}

// ── Test 4: Insert a message ──────────────────────────────
$testMsgId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}internal_messages` (from_user_id, subject, body, priority) VALUES (?, ?, ?, ?)",
        [1, 'Test Message Subject', 'This is a test message body.', 'normal']
    );
    $testMsgId = (int) db_insert_id();
    test('Insert message succeeds', $testMsgId > 0);
} catch (Exception $e) {
    test('Insert message succeeds', false);
}

// ── Test 5: Insert recipients ─────────────────────────────
try {
    db_query(
        "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id) VALUES (?, ?)",
        [$testMsgId, 2]
    );
    db_query(
        "INSERT INTO `{$prefix}message_recipients` (message_id, to_user_id) VALUES (?, ?)",
        [$testMsgId, 3]
    );
    $recipCount = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients` WHERE message_id = ?",
        [$testMsgId]
    );
    test('Insert recipients succeeds (2 recipients)', $recipCount === 2);
} catch (Exception $e) {
    test('Insert recipients succeeds', false);
}

// ── Test 6: Unread count query ────────────────────────────
try {
    $unread = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients`
         WHERE to_user_id = ? AND read_at IS NULL AND deleted_at IS NULL",
        [2]
    );
    test('Unread count for user 2 is 1', $unread === 1);
} catch (Exception $e) {
    test('Unread count query', false);
}

// ── Test 7: Mark as read ──────────────────────────────────
try {
    db_query(
        "UPDATE `{$prefix}message_recipients` SET read_at = NOW() WHERE message_id = ? AND to_user_id = ?",
        [$testMsgId, 2]
    );
    $unread = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients`
         WHERE to_user_id = ? AND read_at IS NULL AND deleted_at IS NULL",
        [2]
    );
    test('Mark as read reduces unread count to 0', $unread === 0);
} catch (Exception $e) {
    test('Mark as read', false);
}

// ── Test 8: Soft delete ───────────────────────────────────
try {
    db_query(
        "UPDATE `{$prefix}message_recipients` SET deleted_at = NOW() WHERE message_id = ? AND to_user_id = ?",
        [$testMsgId, 3]
    );
    $visible = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}message_recipients`
         WHERE to_user_id = ? AND deleted_at IS NULL",
        [3]
    );
    test('Soft delete removes from inbox (visible = 0)', $visible === 0);
} catch (Exception $e) {
    test('Soft delete', false);
}

// ── Test 9: Inbox query with join ─────────────────────────
try {
    $inbox = db_fetch_all(
        "SELECT m.id, m.subject, m.priority, mr.read_at
         FROM `{$prefix}message_recipients` mr
         INNER JOIN `{$prefix}internal_messages` m ON m.id = mr.message_id
         WHERE mr.to_user_id = ? AND mr.deleted_at IS NULL
         ORDER BY m.created_at DESC",
        [2]
    );
    test('Inbox query returns message for user 2', count($inbox) === 1);
    test('Inbox message has correct subject', $inbox[0]['subject'] === 'Test Message Subject');
} catch (Exception $e) {
    test('Inbox query', false);
}

// ── Test 10: Sent query ───────────────────────────────────
try {
    $sent = db_fetch_all(
        "SELECT m.id, m.subject FROM `{$prefix}internal_messages` m WHERE m.from_user_id = ?",
        [1]
    );
    test('Sent query returns message for user 1', count($sent) >= 1);
} catch (Exception $e) {
    test('Sent query', false);
}

// ── Test 11: Broadcast message ────────────────────────────
$broadcastId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}internal_messages` (from_user_id, subject, body, priority, is_broadcast) VALUES (?, ?, ?, 'urgent', 1)",
        [1, 'HAS Broadcast Test', 'Emergency test broadcast']
    );
    $broadcastId = (int) db_insert_id();
    $msg = db_fetch_one(
        "SELECT * FROM `{$prefix}internal_messages` WHERE id = ?",
        [$broadcastId]
    );
    test('Broadcast message created', $msg !== null);
    test('Broadcast priority is urgent', $msg['priority'] === 'urgent');
    test('Broadcast is_broadcast flag set', (int) $msg['is_broadcast'] === 1);
} catch (Exception $e) {
    test('Broadcast message', false);
}

// ── Test 12: Priority ENUM values ─────────────────────────
try {
    db_query(
        "INSERT INTO `{$prefix}internal_messages` (from_user_id, subject, body, priority) VALUES (?, ?, ?, ?)",
        [1, 'High Priority Test', 'Test high', 'high']
    );
    $highId = (int) db_insert_id();
    $highMsg = db_fetch_one("SELECT priority FROM `{$prefix}internal_messages` WHERE id = ?", [$highId]);
    test('High priority accepted', $highMsg['priority'] === 'high');

    // Cleanup
    db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$highId]);
} catch (Exception $e) {
    test('Priority ENUM values', false);
}

// ── Test 13: Index exists for unread count ────────────────
try {
    $indexes = db_fetch_all("SHOW INDEX FROM `{$prefix}message_recipients`");
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    test('idx_mr_unread index exists', in_array('idx_mr_unread', $indexNames));
    test('idx_mr_to_user index exists', in_array('idx_mr_to_user', $indexNames));
} catch (Exception $e) {
    test('Index check', false);
}

// ── Cleanup test data ─────────────────────────────────────
try {
    if ($testMsgId) {
        db_query("DELETE FROM `{$prefix}message_recipients` WHERE message_id = ?", [$testMsgId]);
        db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$testMsgId]);
    }
    if ($broadcastId) {
        db_query("DELETE FROM `{$prefix}internal_messages` WHERE id = ?", [$broadcastId]);
    }
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
