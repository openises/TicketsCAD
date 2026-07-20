<?php
/**
 * Check Member View — Detect legacy vs NewUI member columns and create view.
 *
 * Purpose:  Checks whether the member table uses legacy field names (field1/field2)
 *           or NewUI names (first_name/last_name). If legacy, creates a
 *           member_view with aliased column names.
 * Usage:    php sql/check_member_view.php
 * Prerequisites: config.php; member table must exist.
 * Safety:   Idempotent. Uses CREATE OR REPLACE VIEW; safe to run multiple times.
 * Output:   Reports column style detected and view creation result.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

// Check if member table has first_name or field1
$cols = $pdo->query("SHOW COLUMNS FROM member")->fetchAll(PDO::FETCH_COLUMN, 0);
if (in_array('first_name', $cols)) {
    echo "Member table has NewUI columns (first_name, last_name).\n";
} else {
    echo "Member table has legacy columns (field1, field2).\n";
    echo "Creating/updating view with aliased columns...\n";

    // Check if a view already exists
    try {
        $pdo->exec("CREATE OR REPLACE VIEW member_view AS
            SELECT id,
                   field1 AS last_name,
                   field2 AS first_name,
                   field3 AS member_type_id,
                   field4 AS callsign,
                   field5 AS photo,
                   field6 AS email,
                   field7 AS phone,
                   field8 AS available,
                   field12 AS lat,
                   field13 AS lng,
                   _by, _on
            FROM member");
        echo "Created member_view with aliased columns.\n";

        // Test
        $rows = $pdo->query("SELECT id, first_name, last_name, callsign FROM member_view LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) echo "  [{$r['id']}] {$r['first_name']} {$r['last_name']} ({$r['callsign']})\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
