<?php
/**
 * ICS Forms Tests
 *
 * Verifies the ics_forms table exists with correct columns,
 * form CRUD operations work, form type validation, and incident linking.
 *
 * Usage: php tools/test_ics_forms.php
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

echo "=== ICS Forms Tests ===\n\n";

// ── Test 1: ics_forms table exists ────────────────────────────
try {
    $tables = db_fetch_all("SHOW TABLES LIKE '%ics_forms'");
    test('ics_forms table exists', count($tables) > 0);
} catch (Exception $e) {
    test('ics_forms table exists', false);
}

// ── Test 2: ics_forms has correct columns ─────────────────────
try {
    $cols = db_fetch_all("DESCRIBE `{$prefix}ics_forms`");
    $colNames = array_column($cols, 'Field');
    test('ics_forms has id column', in_array('id', $colNames));
    test('ics_forms has form_type column', in_array('form_type', $colNames));
    test('ics_forms has incident_id column', in_array('incident_id', $colNames));
    test('ics_forms has title column', in_array('title', $colNames));
    test('ics_forms has form_data_json column', in_array('form_data_json', $colNames));
    test('ics_forms has created_by column', in_array('created_by', $colNames));
    test('ics_forms has created_by_name column', in_array('created_by_name', $colNames));
    test('ics_forms has created_at column', in_array('created_at', $colNames));
    test('ics_forms has updated_at column', in_array('updated_at', $colNames));
    test('ics_forms has status column', in_array('status', $colNames));
} catch (Exception $e) {
    test('ics_forms column check', false);
}

// ── Test 3: Create a draft form ───────────────────────────────
$testFormId = null;
try {
    $formData = json_encode([
        'to' => 'Test Recipient',
        'from' => 'Test Sender',
        'subject' => 'Test ICS 213',
        'message' => 'This is a test message body for ICS 213.'
    ]);

    db_query(
        "INSERT INTO `{$prefix}ics_forms` (form_type, title, form_data_json, created_by, created_by_name, status)
         VALUES (?, ?, ?, ?, ?, ?)",
        ['213', 'Test ICS-213 Form', $formData, 1, 'admin', 'draft']
    );
    $testFormId = (int) db_insert_id();
    test('Create draft form succeeds', $testFormId > 0);

    // Verify it was inserted correctly
    $form = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE id = ?", [$testFormId]);
    test('Draft form has correct form_type', $form['form_type'] === '213');
    test('Draft form has correct status', $form['status'] === 'draft');
    test('Draft form has correct title', $form['title'] === 'Test ICS-213 Form');
    test('Draft form JSON data is valid', json_decode($form['form_data_json'], true) !== null);
} catch (Exception $e) {
    test('Create draft form', false);
}

// ── Test 4: Update form data ──────────────────────────────────
try {
    $updatedData = json_encode([
        'to' => 'Updated Recipient',
        'from' => 'Updated Sender',
        'subject' => 'Updated ICS 213',
        'message' => 'Updated message body.',
        'date_time' => '2026-04-03 12:00:00'
    ]);

    db_query(
        "UPDATE `{$prefix}ics_forms` SET form_data_json = ?, status = 'final' WHERE id = ?",
        [$updatedData, $testFormId]
    );
    $updated = db_fetch_one("SELECT * FROM `{$prefix}ics_forms` WHERE id = ?", [$testFormId]);
    test('Update form data succeeds', $updated !== null);
    test('Updated status is final', $updated['status'] === 'final');

    $decoded = json_decode($updated['form_data_json'], true);
    test('Updated JSON has new recipient', $decoded['to'] === 'Updated Recipient');
} catch (Exception $e) {
    test('Update form data', false);
}

// ── Test 5: Valid form types can be inserted ──────────────────
$validTypes = ['213', '214', '202', '205', '205a', '213rr'];
$typeTestIds = [];
foreach ($validTypes as $type) {
    try {
        db_query(
            "INSERT INTO `{$prefix}ics_forms` (form_type, title, form_data_json, created_by, created_by_name)
             VALUES (?, ?, '{}', 1, 'admin')",
            [$type, 'Type Test: ' . $type]
        );
        $id = (int) db_insert_id();
        $typeTestIds[] = $id;
        test("Form type '{$type}' accepted", $id > 0);
    } catch (Exception $e) {
        test("Form type '{$type}' accepted", false);
    }
}

// ── Test 6: Additional valid types (206, 214a, 221) ───────────
$extraTypes = ['206', '214a', '221'];
foreach ($extraTypes as $type) {
    try {
        db_query(
            "INSERT INTO `{$prefix}ics_forms` (form_type, title, form_data_json, created_by, created_by_name)
             VALUES (?, ?, '{}', 1, 'admin')",
            [$type, 'Type Test: ' . $type]
        );
        $id = (int) db_insert_id();
        $typeTestIds[] = $id;
        test("Form type '{$type}' accepted", $id > 0);
    } catch (Exception $e) {
        test("Form type '{$type}' accepted", false);
    }
}

// ── Test 7: Incident linking ──────────────────────────────────
$linkedFormId = null;
try {
    // Get any existing ticket ID for linking
    $ticket = db_fetch_one("SELECT id FROM `{$prefix}ticket` LIMIT 1");
    if ($ticket) {
        $ticketId = (int) $ticket['id'];
        db_query(
            "INSERT INTO `{$prefix}ics_forms` (form_type, incident_id, title, form_data_json, created_by, created_by_name)
             VALUES ('213', ?, 'Linked Form Test', '{}', 1, 'admin')",
            [$ticketId]
        );
        $linkedFormId = (int) db_insert_id();
        test('Form linked to incident succeeds', $linkedFormId > 0);

        // Verify the link
        $linked = db_fetch_one("SELECT incident_id FROM `{$prefix}ics_forms` WHERE id = ?", [$linkedFormId]);
        test('Linked form has correct incident_id', (int) $linked['incident_id'] === $ticketId);

        // Query forms by incident
        $byIncident = db_fetch_all(
            "SELECT id FROM `{$prefix}ics_forms` WHERE incident_id = ?",
            [$ticketId]
        );
        test('Query forms by incident_id returns results', count($byIncident) >= 1);
    } else {
        // No tickets in DB — create standalone form to test nullable incident_id
        db_query(
            "INSERT INTO `{$prefix}ics_forms` (form_type, incident_id, title, form_data_json, created_by, created_by_name)
             VALUES ('213', NULL, 'Standalone Form Test', '{}', 1, 'admin')",
            []
        );
        $linkedFormId = (int) db_insert_id();
        $standalone = db_fetch_one("SELECT incident_id FROM `{$prefix}ics_forms` WHERE id = ?", [$linkedFormId]);
        test('Standalone form has NULL incident_id', $standalone['incident_id'] === null);
        test('Standalone form created without ticket', $linkedFormId > 0);
        test('(skipped - no tickets for incident linking)', true);
    }
} catch (Exception $e) {
    test('Incident linking', false);
}

// ── Test 8: Indexes exist ─────────────────────────────────────
try {
    $indexes = db_fetch_all("SHOW INDEX FROM `{$prefix}ics_forms`");
    $indexNames = array_unique(array_column($indexes, 'Key_name'));
    test('idx_ics_form_type index exists', in_array('idx_ics_form_type', $indexNames));
    test('idx_ics_incident_id index exists', in_array('idx_ics_incident_id', $indexNames));
    test('idx_ics_created_at index exists', in_array('idx_ics_created_at', $indexNames));
    test('idx_ics_status index exists', in_array('idx_ics_status', $indexNames));
} catch (Exception $e) {
    test('Index check', false);
}

// ── Test 9: form_data_json is MEDIUMTEXT ──────────────────────
try {
    $col = db_fetch_one(
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'form_data_json'",
        [$prefix . 'ics_forms']
    );
    test('form_data_json is mediumtext', strtolower($col['DATA_TYPE']) === 'mediumtext');
} catch (Exception $e) {
    test('form_data_json column type', false);
}

// ── Cleanup test data ─────────────────────────────────────────
try {
    if ($testFormId) {
        db_query("DELETE FROM `{$prefix}ics_forms` WHERE id = ?", [$testFormId]);
    }
    if ($linkedFormId) {
        db_query("DELETE FROM `{$prefix}ics_forms` WHERE id = ?", [$linkedFormId]);
    }
    foreach ($typeTestIds as $tid) {
        db_query("DELETE FROM `{$prefix}ics_forms` WHERE id = ?", [$tid]);
    }
    echo "\n[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "\n[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
