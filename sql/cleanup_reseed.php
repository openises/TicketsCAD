<?php
/**
 * Cleanup & Reseed — Delete demo data so seed scripts can be re-run cleanly.
 *
 * Purpose:  Clears rows from vehicles, equipment, equipment_log,
 *           training_records, and member_certifications tables to prepare
 *           for a fresh seed_demo_data.php run.
 * Usage:    php sql/cleanup_reseed.php
 * Prerequisites: config.php; target tables must exist.
 * Safety:   DESTRUCTIVE — deletes data. Safe to run multiple times but
 *           will remove existing records each time.
 * Output:   Confirmation of each table cleared.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

$pdo->exec('DELETE FROM newui_vehicles WHERE id > 0');
echo "Cleared vehicles.\n";
$pdo->exec('DELETE FROM newui_equipment_log WHERE id > 0');
$pdo->exec('DELETE FROM newui_equipment WHERE id > 0');
echo "Cleared equipment + log.\n";

try {
    $pdo->exec('DELETE FROM training_records WHERE id > 0');
    echo "Cleared training_records.\n";
} catch (Exception $e) {
    echo "training_records: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec('DELETE FROM member_certifications WHERE id > 0');
    echo "Cleared member_certifications.\n";
} catch (Exception $e) {
    echo "member_certifications: " . $e->getMessage() . "\n";
}

echo "Done — ready for re-seed.\n";
