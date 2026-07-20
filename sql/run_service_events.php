<?php
/**
 * Run Service Events — Create service health monitoring tables.
 *
 * Purpose:  Executes service_events.sql to create newui_service_state and
 *           newui_service_events tables for tracking API and service health.
 * Usage:    php sql/run_service_events.php
 * Prerequisites: config.php; service_events.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   Table creation confirmations and statement count.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();
$sql = file_get_contents(__DIR__ . '/service_events.sql');

// Strip SQL comments
$sql = preg_replace('/^--.*$/m', '', $sql);

// Split on semicolons and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql)));
$count = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $pdo->exec($stmt);
        $count++;
        // Extract table name for display
        if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $stmt, $m)) {
            echo "Created table: {$m[1]}\n";
        } else {
            echo "Executed statement #{$count}\n";
        }
    } catch (Exception $e) {
        echo "Statement #{$count} error: " . $e->getMessage() . "\n";
    }
}
echo "Done — {$count} statements executed.\n";
