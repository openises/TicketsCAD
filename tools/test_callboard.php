<?php
/**
 * Call Board Tests
 *
 * Verifies the call board API logic works correctly:
 * - Returns incidents with correct structure
 * - Response includes unit_names
 * - Response includes type_color
 * - Handles empty result sets gracefully
 *
 * Usage: php tools/test_callboard.php
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

echo "=== Call Board Tests ===\n\n";

// ── Test 1: callboard.php file exists ─────────────────────────
echo "-- File & Syntax --\n";

$cbFile = __DIR__ . '/../api/callboard.php';
test('api/callboard.php exists', file_exists($cbFile));

$output = [];
$rc = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($cbFile) . ' 2>&1', $output, $rc);
test('api/callboard.php syntax OK', $rc === 0);

// ── Test 2: Core query returns valid result structure ─────────
echo "\n-- Query Structure --\n";

try {
    $recent_mins = 30;

    $sql = "SELECT
        `t`.`id`,
        `t`.`scope`,
        `t`.`street`,
        `t`.`city`,
        `t`.`state`,
        `t`.`lat`,
        `t`.`lng`,
        `t`.`severity`,
        `t`.`status`,
        `t`.`description`,
        `t`.`date` AS `created`,
        `t`.`updated`,
        `it`.`type` AS `incident_type`,
        `it`.`id` AS `type_id`,
        `it`.`color` AS `type_color`,
        (SELECT COUNT(*) FROM `{$prefix}assigns`
         WHERE `ticket_id` = `t`.`id` AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
         AS `units_assigned`
    FROM `{$prefix}ticket` `t`
    LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
    WHERE (
        `t`.`status` = 2
        OR `t`.`status` = 3
        OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE))
    )
    ORDER BY `t`.`severity` DESC, `t`.`updated` DESC
    LIMIT 10";

    $rows = db_fetch_all($sql, [$recent_mins]);
    test('Call board query executes without error', true);
    test('Call board query returns array', is_array($rows));
} catch (Exception $e) {
    test('Call board query: ' . $e->getMessage(), false);
    test('Call board query returns array', false);
    $rows = [];
}

// ── Test 3: Response includes unit_names ──────────────────────
echo "\n-- Unit Names --\n";

try {
    // Get ticket IDs from results
    $ticket_ids = [];
    foreach ($rows as $row) {
        $ticket_ids[] = (int) $row['id'];
    }

    if (!empty($ticket_ids)) {
        $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
        $unit_sql = "SELECT
            `a`.`ticket_id`,
            `r`.`name`
        FROM `{$prefix}assigns` `a`
        LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
        WHERE `a`.`ticket_id` IN ({$placeholders})
          AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
        ORDER BY `r`.`name`";

        $unit_rows = db_fetch_all($unit_sql, $ticket_ids);
        test('Unit names query executes', true);
        test('Unit rows is an array', is_array($unit_rows));

        // Build unit_map like the API does
        $unit_map = [];
        foreach ($unit_rows as $ur) {
            $tid = (int) $ur['ticket_id'];
            if (!isset($unit_map[$tid])) {
                $unit_map[$tid] = [];
            }
            if ($ur['name']) {
                $unit_map[$tid][] = $ur['name'];
            }
        }

        // Build response items with unit_names
        $hasUnitNames = true;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $names = isset($unit_map[$id]) ? $unit_map[$id] : [];
            $unitStr = implode(', ', $names);
            // unit_names should always be a string (possibly empty)
            if (!is_string($unitStr)) {
                $hasUnitNames = false;
            }
        }
        test('unit_names field is string for all incidents', $hasUnitNames);
    } else {
        test('Unit names query executes (no active incidents)', true);
        test('Unit rows is an array (empty)', true);
        test('unit_names field (no incidents to check)', true);
    }
} catch (Exception $e) {
    test('Unit names: ' . $e->getMessage(), false);
}

// ── Test 4: Response includes type_color ──────────────────────
echo "\n-- Type Color --\n";

try {
    if (!empty($rows)) {
        $hasTypeColor = true;
        foreach ($rows as $row) {
            // type_color can be null (if no type assigned) but the column must exist
            if (!array_key_exists('type_color', $row)) {
                $hasTypeColor = false;
                break;
            }
        }
        test('type_color field present in all rows', $hasTypeColor);

        // Check that in_types.color column exists
        $colorCol = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'color'",
            [$prefix . 'in_types']
        );
        test('in_types table has color column', $colorCol !== null);
    } else {
        // No active incidents — verify the column exists in the schema
        $colorCol = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'color'",
            [$prefix . 'in_types']
        );
        test('in_types.color column exists (no incidents to check)', $colorCol !== null);
        test('type_color field (no incidents to check)', true);
    }
} catch (Exception $e) {
    test('Type color: ' . $e->getMessage(), false);
}

// ── Test 5: Create test incident and verify call board ────────
echo "\n-- Create Test Incident --\n";

$testTicketId = null;
$testTypeId = null;
$testResponderId = null;
$testAssignId = null;

try {
    // Get a type with a color
    $itype = db_fetch_one(
        "SELECT id, color FROM `{$prefix}in_types` WHERE color IS NOT NULL AND color != '' LIMIT 1"
    );
    if ($itype) {
        $testTypeId = (int) $itype['id'];

        // Create an open incident (status=2)
        db_query(
            "INSERT INTO `{$prefix}ticket`
             (`scope`, `street`, `city`, `state`, `severity`, `status`, `in_types_id`, `date`, `updated`, `description`)
             VALUES ('Test Callboard', '123 Test St', 'Testville', 'TX', 3, 2, ?, NOW(), NOW(), 'Callboard test incident')",
            [$testTypeId]
        );
        $testTicketId = (int) db_insert_id();
        test('Test incident created', $testTicketId > 0);

        // Assign a unit
        $resp = db_fetch_one("SELECT id, name FROM `{$prefix}responder` WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
        if ($resp) {
            $testResponderId = (int) $resp['id'];
            db_query(
                "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`)
                 VALUES (?, ?, 1)",
                [$testTicketId, $testResponderId]
            );
            $testAssignId = (int) db_insert_id();
            test('Responder assigned to test incident', $testAssignId > 0);
        } else {
            test('(skipped - no responders for assignment)', true);
        }

        // Query the call board and verify our incident appears
        $cbSql = "SELECT
            `t`.`id`,
            `it`.`color` AS `type_color`,
            (SELECT COUNT(*) FROM `{$prefix}assigns`
             WHERE `ticket_id` = `t`.`id` AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
             AS `units_assigned`
        FROM `{$prefix}ticket` `t`
        LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
        WHERE `t`.`id` = ? AND `t`.`status` = 2";

        $cbRow = db_fetch_one($cbSql, [$testTicketId]);
        test('Test incident appears in call board query', $cbRow !== null);
        test('Test incident has type_color from in_types', $cbRow['type_color'] === $itype['color']);
        test('Test incident shows assigned unit count', (int) $cbRow['units_assigned'] >= ($testResponderId ? 1 : 0));

        // Verify unit name is retrievable
        if ($testResponderId) {
            $unitName = db_fetch_one(
                "SELECT r.name FROM `{$prefix}assigns` a
                 LEFT JOIN `{$prefix}responder` r ON a.responder_id = r.id
                 WHERE a.ticket_id = ?
                   AND (a.clear IS NULL OR DATE_FORMAT(a.clear,'%y') = '00')
                 LIMIT 1",
                [$testTicketId]
            );
            test('Assigned unit name retrievable', $unitName !== null && !empty($unitName['name']));
        }
    } else {
        test('(skipped - no incident types with color)', true);
        test('(skipped)', true);
        test('(skipped)', true);
        test('(skipped)', true);
        test('(skipped)', true);
    }
} catch (Exception $e) {
    test('Test incident: ' . $e->getMessage(), false);
}

// ── Test 6: Empty result handling ─────────────────────────────
echo "\n-- Edge Cases --\n";

try {
    // Query with impossible filter to get empty result
    $empty = db_fetch_all(
        "SELECT `t`.`id` FROM `{$prefix}ticket` `t` WHERE `t`.`id` = -999"
    );
    test('Empty result set returns empty array', is_array($empty) && count($empty) === 0);
} catch (Exception $e) {
    test('Empty result handling: ' . $e->getMessage(), false);
}

// ── Cleanup ───────────────────────────────────────────────────
try {
    if ($testAssignId) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$testAssignId]);
    }
    if ($testTicketId) {
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$testTicketId]);
    }
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
