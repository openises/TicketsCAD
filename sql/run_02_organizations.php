<?php
/**
 * Run Organizations — Create organization and communication identifier tables.
 *
 * Purpose:  Executes organizations.sql and comm_identifiers.sql to create
 *           organizations, member_organizations, comm_identifier_types, and
 *           member_comm_identifiers tables. Seeds a default organization.
 * Usage:    php sql/run_organizations.php
 * Prerequisites: config.php; organizations.sql and comm_identifiers.sql in
 *                same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   OK/ERR per SQL statement; row counts for created tables.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

echo "=== Organizations & Comm Identifiers Setup ===\n\n";

function run_sql_file($path) {
    $sql = file_get_contents($path);
    // Remove SQL comments (lines starting with --)
    $sql = preg_replace('/^--.*$/m', '', $sql);

    // Split on semicolons that are NOT inside quotes
    // Simple approach: split on ;\n or ;\r\n at line boundary
    $statements = preg_split('/;\s*\n/', $sql);

    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt)) continue;

        try {
            db()->exec($stmt);
            $count++;

            if (stripos($stmt, 'CREATE TABLE') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS\s+(\S+)/i', $stmt, $m);
                echo "  [OK] Created table: " . ($m[1] ?? '?') . "\n";
            } elseif (stripos($stmt, 'INSERT') !== false) {
                preg_match("/INTO\s+(\S+)/i", $stmt, $m);
                echo "  [OK] Insert into: " . ($m[1] ?? '?') . "\n";
            }
        } catch (Exception $e) {
            echo "  [WARN] " . substr($e->getMessage(), 0, 200) . "\n";
        }
    }
    echo "  Executed $count statement(s)\n";
}

echo "Running organizations.sql...\n";
run_sql_file(__DIR__ . '/organizations.sql');

echo "\nRunning comm_identifiers.sql...\n";
run_sql_file(__DIR__ . '/comm_identifiers.sql');

// Verify
echo "\n=== Verification ===\n";
try {
    $orgs = db_fetch_all("SELECT id, name, short_name FROM organizations ORDER BY id");
    echo "Organizations: " . count($orgs) . " row(s)\n";
    foreach ($orgs as $o) {
        echo "  #{$o['id']} {$o['name']} ({$o['short_name']})\n";
    }
} catch (Exception $e) {
    echo "  [ERR] organizations: " . $e->getMessage() . "\n";
}

try {
    $mo = db_fetch_all("SELECT COUNT(*) AS cnt FROM member_organizations");
    echo "Member-org links: " . $mo[0]['cnt'] . "\n";
} catch (Exception $e) {
    echo "  [ERR] member_organizations: " . $e->getMessage() . "\n";
}

try {
    $modes = db_fetch_all("SELECT id, code, name FROM comm_modes ORDER BY sort_order");
    echo "Comm modes: " . count($modes) . "\n";
    foreach ($modes as $m) {
        echo "  #{$m['id']} {$m['code']} — {$m['name']}\n";
    }
} catch (Exception $e) {
    echo "  [ERR] comm_modes: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
