<?php
/**
 * Major Incidents Integration Tests
 *
 * Usage: php tools/test_major_incidents.php
 *
 * Tests table creation, CRUD operations, linking/unlinking, and cascading close.
 * Cleans up all test data when finished.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/audit.php';

echo "=== Major Incidents Integration Tests ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

// Track IDs for cleanup
$test_major_id  = null;
$test_ticket_ids = [];

// ── Test 1: Tables exist ──
echo "[Test 1] major_incidents table exists... ";
try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_major_incidents`");
    echo "PASS\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

echo "[Test 2] major_incident_links table exists... ";
try {
    db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links`");
    echo "PASS\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 3: Create major incident ──
echo "[Test 3] Create major incident... ";
try {
    $now = date('Y-m-d H:i:s');
    db_query(
        "INSERT INTO `{$prefix}newui_major_incidents`
            (`name`, `description`, `commander`, `severity`, `status`, `lat`, `lng`, `created_at`, `updated_at`)
         VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?)",
        ['TEST Major Fire', 'Test description for major fire event', null, 2, 40.7128, -74.0060, $now, $now]
    );
    $test_major_id = (int) db_insert_id();
    if ($test_major_id > 0) {
        echo "PASS (id={$test_major_id})\n";
        $pass++;
    } else {
        echo "FAIL: no insert ID\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 4: Read major incident back ──
echo "[Test 4] Read major incident back... ";
try {
    $row = db_fetch_one(
        "SELECT * FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
        [$test_major_id]
    );
    if ($row && $row['name'] === 'TEST Major Fire' && $row['status'] === 'open' && (int) $row['severity'] === 2) {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: unexpected data\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 5: Create test tickets to link ──
echo "[Test 5] Create test tickets for linking... ";
try {
    for ($i = 1; $i <= 3; $i++) {
        db_query(
            "INSERT INTO `{$prefix}ticket`
                (`scope`, `description`, `status`, `severity`, `date`, `updated`, `_by`, `owner`, `in_types_id`)
             VALUES (?, ?, 2, 1, ?, ?, 1, 0, 1)",
            ["TEST Link Ticket {$i}", "Test ticket {$i} for major incident linking", $now, $now]
        );
        $test_ticket_ids[] = (int) db_insert_id();
    }
    if (count($test_ticket_ids) === 3 && $test_ticket_ids[0] > 0) {
        echo "PASS (ids=" . implode(',', $test_ticket_ids) . ")\n";
        $pass++;
    } else {
        echo "FAIL: could not create test tickets\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 6: Link incidents to major incident ──
echo "[Test 6] Link incidents to major incident... ";
$link_ok = true;
try {
    foreach ($test_ticket_ids as $tid) {
        db_query(
            "INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
             VALUES (?, ?, 1, ?)",
            [$test_major_id, $tid, $now]
        );
    }
    $count = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` WHERE `major_id` = ?",
        [$test_major_id]
    );
    if ($count === 3) {
        echo "PASS ({$count} links)\n";
        $pass++;
    } else {
        echo "FAIL: expected 3 links, got {$count}\n";
        $fail++;
        $link_ok = false;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
    $link_ok = false;
}

// ── Test 7: Unique constraint prevents duplicate link ──
echo "[Test 7] Unique constraint on duplicate link... ";
try {
    db_query(
        "INSERT INTO `{$prefix}newui_major_incident_links` (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
         VALUES (?, ?, 1, ?)",
        [$test_major_id, $test_ticket_ids[0], $now]
    );
    echo "FAIL: duplicate insert should have thrown\n";
    $fail++;
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
        echo "PASS (duplicate rejected)\n";
        $pass++;
    } else {
        echo "FAIL: unexpected error: " . $e->getMessage() . "\n";
        $fail++;
    }
}

// ── Test 8: List linked incidents ──
echo "[Test 8] List linked incidents via JOIN... ";
try {
    $linked = db_fetch_all(
        "SELECT l.`ticket_id`, t.`scope`
           FROM `{$prefix}newui_major_incident_links` l
           JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
          WHERE l.`major_id` = ?
          ORDER BY l.`linked_at` ASC",
        [$test_major_id]
    );
    if (count($linked) === 3 && strpos($linked[0]['scope'], 'TEST Link Ticket') === 0) {
        echo "PASS (" . count($linked) . " rows)\n";
        $pass++;
    } else {
        echo "FAIL: expected 3 linked tickets\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 9: Unlink one incident ──
echo "[Test 9] Unlink one incident... ";
try {
    $unlink_tid = $test_ticket_ids[2]; // unlink the third one
    db_query(
        "DELETE FROM `{$prefix}newui_major_incident_links`
          WHERE `major_id` = ? AND `ticket_id` = ?",
        [$test_major_id, $unlink_tid]
    );
    $remaining = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}newui_major_incident_links` WHERE `major_id` = ?",
        [$test_major_id]
    );
    if ($remaining === 2) {
        echo "PASS ({$remaining} remaining)\n";
        $pass++;
    } else {
        echo "FAIL: expected 2 remaining, got {$remaining}\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 10: Update major incident ──
echo "[Test 10] Update major incident fields... ";
try {
    db_query(
        "UPDATE `{$prefix}newui_major_incidents`
            SET `name` = ?, `severity` = ?, `updated_at` = ?
          WHERE `id` = ?",
        ['TEST Major Fire UPDATED', 1, $now, $test_major_id]
    );
    $updated = db_fetch_one(
        "SELECT `name`, `severity` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
        [$test_major_id]
    );
    if ($updated && $updated['name'] === 'TEST Major Fire UPDATED' && (int) $updated['severity'] === 1) {
        echo "PASS\n";
        $pass++;
    } else {
        echo "FAIL: update did not persist\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ── Test 11: Close major incident (cascading close) ──
echo "[Test 11] Close major incident (cascade)... ";
try {
    // Close the major incident
    db_query(
        "UPDATE `{$prefix}newui_major_incidents`
            SET `status` = 'closed', `closed_at` = ?, `updated_at` = ?
          WHERE `id` = ?",
        [$now, $now, $test_major_id]
    );

    // Cascade: close all linked open tickets
    $linked_open = db_fetch_all(
        "SELECT l.`ticket_id`
           FROM `{$prefix}newui_major_incident_links` l
           JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
          WHERE l.`major_id` = ? AND t.`status` = 2",
        [$test_major_id]
    );
    foreach ($linked_open as $lt) {
        db_query(
            "UPDATE `{$prefix}ticket` SET `status` = 1, `problemend` = ?, `updated` = ? WHERE `id` = ?",
            [$now, $now, (int) $lt['ticket_id']]
        );
    }

    // Verify major incident is closed
    $mi = db_fetch_one(
        "SELECT `status`, `closed_at` FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
        [$test_major_id]
    );

    // Verify linked tickets are closed
    $still_open = (int) db_fetch_value(
        "SELECT COUNT(*)
           FROM `{$prefix}newui_major_incident_links` l
           JOIN `{$prefix}ticket` t ON t.`id` = l.`ticket_id`
          WHERE l.`major_id` = ? AND t.`status` = 2",
        [$test_major_id]
    );

    if ($mi['status'] === 'closed' && $mi['closed_at'] !== null && $still_open === 0) {
        echo "PASS (major closed, 0 linked tickets still open)\n";
        $pass++;
    } else {
        echo "FAIL: status={$mi['status']}, still_open={$still_open}\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// ══════════════════════════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════════════════════════
echo "\n--- Cleanup ---\n";

// Remove links
try {
    db_query(
        "DELETE FROM `{$prefix}newui_major_incident_links` WHERE `major_id` = ?",
        [$test_major_id]
    );
    echo "Cleaned major_incident_links\n";
} catch (Exception $e) {
    echo "Cleanup warning (links): " . $e->getMessage() . "\n";
}

// Remove major incident
if ($test_major_id) {
    try {
        db_query(
            "DELETE FROM `{$prefix}newui_major_incidents` WHERE `id` = ?",
            [$test_major_id]
        );
        echo "Cleaned major_incidents (id={$test_major_id})\n";
    } catch (Exception $e) {
        echo "Cleanup warning (major): " . $e->getMessage() . "\n";
    }
}

// Remove test tickets
if (!empty($test_ticket_ids)) {
    try {
        $placeholders = implode(',', array_fill(0, count($test_ticket_ids), '?'));
        db_query(
            "DELETE FROM `{$prefix}ticket` WHERE `id` IN ({$placeholders})",
            $test_ticket_ids
        );
        echo "Cleaned test tickets (ids=" . implode(',', $test_ticket_ids) . ")\n";
    } catch (Exception $e) {
        echo "Cleanup warning (tickets): " . $e->getMessage() . "\n";
    }
}

// ── Summary ──
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
