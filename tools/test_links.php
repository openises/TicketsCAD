<?php
/**
 * External Links Tests
 *
 * Verifies the external_links table exists, default links are seeded,
 * CRUD operations work, and category grouping functions correctly.
 *
 * Usage: php tools/test_links.php
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

echo "=== External Links Tests ===\n\n";

// ── Test 1: Table exists ──────────────────────────────────────
echo "-- Table & Schema --\n";

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'external_links']
    );
    test('external_links table exists', $tbl !== null);
} catch (Exception $e) {
    test('external_links table exists', false);
}

// ── Test 2: Correct columns ──────────────────────────────────
try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}external_links`");
    $colNames = array_column($cols, 'Field');
    test('external_links has id column', in_array('id', $colNames));
    test('external_links has title column', in_array('title', $colNames));
    test('external_links has url column', in_array('url', $colNames));
    test('external_links has description column', in_array('description', $colNames));
    test('external_links has icon column', in_array('icon', $colNames));
    test('external_links has category column', in_array('category', $colNames));
    test('external_links has sort_order column', in_array('sort_order', $colNames));
    test('external_links has active column', in_array('active', $colNames));
    test('external_links has created_by column', in_array('created_by', $colNames));
    test('external_links has created_at column', in_array('created_at', $colNames));
} catch (Exception $e) {
    test('external_links column check', false);
}

// ── Test 3: Default links are seeded ──────────────────────────
echo "\n-- Seed Data --\n";

try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}external_links`");
    test('external_links has seeded data', $count > 0);
} catch (Exception $e) {
    test('Seed data check: ' . $e->getMessage(), false);
}

// ── Test 4: Create a link ─────────────────────────────────────
echo "\n-- CRUD Operations --\n";

$testLinkId = null;
try {
    db_query(
        "INSERT INTO `{$prefix}external_links`
         (title, url, description, icon, category, sort_order, active, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        ['Test Link', 'https://example.com/test', 'A test link for unit tests', 'bi-globe', 'Testing', 99, 1, 1]
    );
    $testLinkId = (int) db_insert_id();
    test('Create link succeeds', $testLinkId > 0);
} catch (Exception $e) {
    test('Create link: ' . $e->getMessage(), false);
}

// ── Test 5: Read the link back ────────────────────────────────
try {
    $link = db_fetch_one("SELECT * FROM `{$prefix}external_links` WHERE id = ?", [$testLinkId]);
    test('Read link by id succeeds', $link !== null);
    test('Read link has correct title', $link['title'] === 'Test Link');
    test('Read link has correct url', $link['url'] === 'https://example.com/test');
    test('Read link has correct category', $link['category'] === 'Testing');
    test('Read link has correct icon', $link['icon'] === 'bi-globe');
    test('Read link is active', (int) $link['active'] === 1);
} catch (Exception $e) {
    test('Read link: ' . $e->getMessage(), false);
}

// ── Test 6: Update the link ───────────────────────────────────
try {
    db_query(
        "UPDATE `{$prefix}external_links`
         SET title = ?, url = ?, category = ?, sort_order = ?
         WHERE id = ?",
        ['Updated Test Link', 'https://example.com/updated', 'Updated Category', 50, $testLinkId]
    );
    $updated = db_fetch_one("SELECT * FROM `{$prefix}external_links` WHERE id = ?", [$testLinkId]);
    test('Update link title succeeds', $updated['title'] === 'Updated Test Link');
    test('Update link url succeeds', $updated['url'] === 'https://example.com/updated');
    test('Update link category succeeds', $updated['category'] === 'Updated Category');
    test('Update link sort_order succeeds', (int) $updated['sort_order'] === 50);
} catch (Exception $e) {
    test('Update link: ' . $e->getMessage(), false);
}

// ── Test 7: Deactivate (soft disable) ─────────────────────────
try {
    db_query("UPDATE `{$prefix}external_links` SET active = 0 WHERE id = ?", [$testLinkId]);
    $inactive = db_fetch_one("SELECT active FROM `{$prefix}external_links` WHERE id = ?", [$testLinkId]);
    test('Deactivate link succeeds', (int) $inactive['active'] === 0);

    // Active-only query should exclude it
    $activeLinks = db_fetch_all(
        "SELECT id FROM `{$prefix}external_links` WHERE active = 1 AND id = ?",
        [$testLinkId]
    );
    test('Deactivated link excluded from active query', count($activeLinks) === 0);
} catch (Exception $e) {
    test('Deactivate link: ' . $e->getMessage(), false);
}

// ── Test 8: Category grouping ─────────────────────────────────
echo "\n-- Category Grouping --\n";

$testLink2Id = null;
try {
    // Re-activate test link and add a second one in same category
    db_query("UPDATE `{$prefix}external_links` SET active = 1, category = 'TestGroup' WHERE id = ?", [$testLinkId]);

    db_query(
        "INSERT INTO `{$prefix}external_links`
         (title, url, category, sort_order, active)
         VALUES (?, ?, ?, ?, ?)",
        ['Test Link 2', 'https://example.com/test2', 'TestGroup', 100, 1]
    );
    $testLink2Id = (int) db_insert_id();

    // Group by category
    $groups = db_fetch_all(
        "SELECT category, COUNT(*) AS link_count
         FROM `{$prefix}external_links`
         WHERE active = 1
         GROUP BY category
         ORDER BY category"
    );
    test('Category grouping query returns results', count($groups) > 0);

    // Find our test group
    $found = false;
    foreach ($groups as $g) {
        if ($g['category'] === 'TestGroup') {
            $found = true;
            test('TestGroup category has 2 links', (int) $g['link_count'] === 2);
            break;
        }
    }
    test('TestGroup category found in grouping', $found);
} catch (Exception $e) {
    test('Category grouping: ' . $e->getMessage(), false);
}

// ── Test 9: Delete a link ─────────────────────────────────────
echo "\n-- Delete --\n";

try {
    db_query("DELETE FROM `{$prefix}external_links` WHERE id = ?", [$testLinkId]);
    $deleted = db_fetch_one("SELECT id FROM `{$prefix}external_links` WHERE id = ?", [$testLinkId]);
    test('Delete link succeeds', $deleted === null);
} catch (Exception $e) {
    test('Delete link: ' . $e->getMessage(), false);
}

// ── Test 10: Indexes exist ────────────────────────────────────
echo "\n-- Indexes --\n";

try {
    $indexes = db_fetch_all("SHOW INDEX FROM `{$prefix}external_links`");
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    test('idx_category index exists', in_array('idx_category', $indexNames));
    test('idx_sort index exists', in_array('idx_sort', $indexNames));
} catch (Exception $e) {
    test('Index check: ' . $e->getMessage(), false);
}

// ── Cleanup ───────────────────────────────────────────────────
try {
    if ($testLink2Id) {
        db_query("DELETE FROM `{$prefix}external_links` WHERE id = ?", [$testLink2Id]);
    }
    // testLinkId already deleted in Test 9
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
