<?php
/**
 * Run Scheduling — Create shift and event scheduling tables.
 *
 * Purpose:  Executes scheduling.sql to create shifts, shift_slots, events,
 *           event_slots, event_participants, and related tables for the
 *           scheduling module.
 * Usage:    php sql/run_scheduling.php
 * Prerequisites: config.php; scheduling.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   Table creation confirmations and statement count.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();
$sql = file_get_contents(__DIR__ . '/scheduling.sql');

// Strip SQL comments
$sql = preg_replace('/^--.*$/m', '', $sql);

// Split on semicolons and execute
$statements = array_filter(array_map('trim', explode(';', $sql)));
$count = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    try {
        $pdo->exec($stmt);
        $count++;
        if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $stmt, $m)) {
            echo "Created table: {$m[1]}\n";
        } elseif (preg_match('/INSERT INTO.*?`(\w+)`/i', $stmt, $m)) {
            echo "Inserted into: {$m[1]}\n";
        } else {
            echo "Executed statement #{$count}\n";
        }
    } catch (Exception $e) {
        echo "Statement #{$count} error: " . $e->getMessage() . "\n";
    }
}
echo "Done — {$count} statements executed.\n";
