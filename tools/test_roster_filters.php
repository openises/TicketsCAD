<?php
/**
 * Roster Filter Tests
 *
 * Verifies member_types, member_status, and teams tables return data,
 * team membership via junction table works, and filter queries
 * by team_id, type, and status function correctly.
 *
 * Usage: php tools/test_roster_filters.php
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

echo "=== Roster Filter Tests ===\n\n";

// ── Test 1: member_types returns data ─────────────────────────
echo "-- Lookup Tables --\n";

try {
    $types = db_fetch_all("SELECT * FROM `{$prefix}member_types` ORDER BY id");
    test('member_types table has data', count($types) > 0);
    if (count($types) > 0) {
        // Check required columns
        $firstRow = $types[0];
        test('member_types has id field', isset($firstRow['id']));
        test('member_types has name field', isset($firstRow['name']));
        test('member_types has color field', isset($firstRow['color']));
    }
} catch (Exception $e) {
    test('member_types query: ' . $e->getMessage(), false);
}

// ── Test 2: member_status returns data ────────────────────────
try {
    $statuses = db_fetch_all("SELECT * FROM `{$prefix}member_status` ORDER BY id");
    test('member_status table has data', count($statuses) > 0);
    if (count($statuses) > 0) {
        $firstRow = $statuses[0];
        test('member_status has id field', isset($firstRow['id']));
        // Column is status_val in some installs, name in others
        $hasLabel = isset($firstRow['name']) || isset($firstRow['status_val']);
        test('member_status has name or status_val field', $hasLabel);
        test('member_status has color field', isset($firstRow['color']));
    }
} catch (Exception $e) {
    test('member_status query: ' . $e->getMessage(), false);
}

// ── Test 3: teams returns data ────────────────────────────────
try {
    $teams = db_fetch_all("SELECT * FROM `{$prefix}teams`");
    test('teams table has data', count($teams) > 0);
    if (count($teams) > 0) {
        $firstRow = $teams[0];
        test('teams has id field', isset($firstRow['id']));
        test('teams has name field', isset($firstRow['name']));
    }
} catch (Exception $e) {
    test('teams query: ' . $e->getMessage(), false);
}

// ── Test 4: team_members junction table ───────────────────────
echo "\n-- Team Members Junction --\n";

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'team_members']
    );
    test('team_members junction table exists', $tbl !== null);
} catch (Exception $e) {
    test('team_members table check', false);
}

// Verify junction table columns
try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}team_members`");
    $colNames = array_column($cols, 'Field');
    test('team_members has team_id column', in_array('team_id', $colNames));
    test('team_members has member_id column', in_array('member_id', $colNames));
    test('team_members has role column', in_array('role', $colNames));
} catch (Exception $e) {
    test('team_members column check', false);
}

// ── Test 5: Insert test data for filter tests ─────────────────
echo "\n-- Filter Query Tests --\n";

$testMemberId = null;
$testTeamId = null;
$testTypeId = null;
$testStatusId = null;
$testJunctionId = null;

try {
    // Get an existing type and status for our test member
    $type = db_fetch_one("SELECT id FROM `{$prefix}member_types` LIMIT 1");
    $status = db_fetch_one("SELECT id FROM `{$prefix}member_status` LIMIT 1");
    $team = db_fetch_one("SELECT id FROM `{$prefix}teams` LIMIT 1");

    $testTypeId = $type ? (int) $type['id'] : null;
    $testStatusId = $status ? (int) $status['id'] : null;
    $testTeamId = $team ? (int) $team['id'] : null;

    // Discover any of these named columns that have been added as VIRTUAL
    // aliases by tools/install_fresh.php — we have to write to the source
    // legacy field column when that's the case.
    $genCols = db_fetch_all(
        "SELECT COLUMN_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member'
           AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION != ''
           AND COLUMN_NAME IN ('first_name', 'last_name', 'available', 'member_type_id')"
    );
    $fieldMap = [];
    foreach ($genCols as $gc) {
        $fieldMap[$gc['COLUMN_NAME']] = trim($gc['GENERATION_EXPRESSION'], '` ');
    }
    $col = function ($logical) use ($fieldMap) {
        return $fieldMap[$logical] ?? $logical;
    };

    // member_type_id may be a virtual alias of field3; if so, set field3 directly.
    $insertCols = [
        $col('first_name')     => 'FilterTest',
        $col('last_name')      => 'RosterUser',
        $col('available')      => 'Yes',
        $col('member_type_id') => $testTypeId,
        // member_status_id is a real column either way
        'member_status_id'     => $testStatusId,
        'created_at'           => date('Y-m-d H:i:s'),
        'updated_at'           => date('Y-m-d H:i:s'),
    ];
    $colNames = array_keys($insertCols);
    $colSql   = '`' . implode('`, `', $colNames) . '`';
    $placeholders = rtrim(str_repeat('?, ', count($colNames)), ', ');
    db_query("INSERT INTO `member` ($colSql) VALUES ($placeholders)", array_values($insertCols));
    $testMemberId = (int) db_insert_id();
    test('Test member created for filters', $testMemberId > 0);

    // Add to team via junction table
    if ($testTeamId) {
        db_query(
            "INSERT INTO `{$prefix}team_members` (team_id, member_id, role)
             VALUES (?, ?, 'Member')",
            [$testTeamId, $testMemberId]
        );
        $testJunctionId = (int) db_insert_id();
        test('Member added to team via junction', $testJunctionId > 0);
    } else {
        test('(skipped - no teams for junction test)', true);
    }
} catch (Exception $e) {
    test('Test data setup: ' . $e->getMessage(), false);
}

// ── Test 6: Members have team_ids when enriched via junction ──
try {
    if ($testTeamId && $testMemberId) {
        $teamIds = db_fetch_all(
            "SELECT tm.team_id FROM `{$prefix}team_members` tm
             WHERE tm.member_id = ?",
            [$testMemberId]
        );
        $ids = array_column($teamIds, 'team_id');
        test('Member has team_ids via junction', in_array($testTeamId, $ids));
    } else {
        test('(skipped - no test data for junction enrichment)', true);
    }
} catch (Exception $e) {
    test('Team IDs enrichment: ' . $e->getMessage(), false);
}

// ── Test 7: Filter by team_id using junction table ────────────
try {
    if ($testTeamId) {
        $members = db_fetch_all(
            "SELECT DISTINCT m.id, m.first_name, m.last_name
             FROM `{$prefix}member` m
             INNER JOIN `{$prefix}team_members` tm ON tm.member_id = m.id
             WHERE tm.team_id = ?
               AND (m.deleted_at IS NULL)",
            [$testTeamId]
        );
        $memberIds = array_column($members, 'id');
        test('Filter by team_id returns test member', in_array($testMemberId, $memberIds));
        test('Filter by team_id returns results', count($members) >= 1);
    } else {
        test('(skipped - no team for filter test)', true);
        test('(skipped)', true);
    }
} catch (Exception $e) {
    test('Filter by team_id: ' . $e->getMessage(), false);
}

// ── Test 8: Filter by member_type_id ──────────────────────────
try {
    if ($testTypeId) {
        $byType = db_fetch_all(
            "SELECT id FROM `{$prefix}member`
             WHERE member_type_id = ? AND (deleted_at IS NULL)",
            [$testTypeId]
        );
        $found = false;
        foreach ($byType as $row) {
            if ((int) $row['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }
        test('Filter by type returns test member', $found);
    } else {
        test('(skipped - no type for filter test)', true);
    }
} catch (Exception $e) {
    test('Filter by type: ' . $e->getMessage(), false);
}

// ── Test 9: Filter by member_status_id ────────────────────────
try {
    if ($testStatusId) {
        $byStatus = db_fetch_all(
            "SELECT id FROM `{$prefix}member`
             WHERE member_status_id = ? AND (deleted_at IS NULL)",
            [$testStatusId]
        );
        $found = false;
        foreach ($byStatus as $row) {
            if ((int) $row['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }
        test('Filter by status returns test member', $found);
    } else {
        test('(skipped - no status for filter test)', true);
    }
} catch (Exception $e) {
    test('Filter by status: ' . $e->getMessage(), false);
}

// ── Test 10: Combined filter (type + team) ────────────────────
try {
    if ($testTypeId && $testTeamId) {
        $combined = db_fetch_all(
            "SELECT DISTINCT m.id
             FROM `{$prefix}member` m
             INNER JOIN `{$prefix}team_members` tm ON tm.member_id = m.id
             WHERE m.member_type_id = ? AND tm.team_id = ?
               AND (m.deleted_at IS NULL)",
            [$testTypeId, $testTeamId]
        );
        $found = false;
        foreach ($combined as $row) {
            if ((int) $row['id'] === $testMemberId) {
                $found = true;
                break;
            }
        }
        test('Combined filter (type + team) returns test member', $found);
    } else {
        test('(skipped - no data for combined filter)', true);
    }
} catch (Exception $e) {
    test('Combined filter: ' . $e->getMessage(), false);
}

// ── Cleanup ───────────────────────────────────────────────────
try {
    if ($testJunctionId) {
        db_query("DELETE FROM `{$prefix}team_members` WHERE id = ?", [$testJunctionId]);
    }
    if ($testMemberId) {
        db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$testMemberId]);
    }
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
