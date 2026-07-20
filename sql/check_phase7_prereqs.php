<?php
/**
 * Check Phase 7 Prerequisites — Verify personnel/NIMS tables exist.
 *
 * Purpose:  Checks whether the tables required for Phase 7 (teams, certs,
 *           ICS positions, training records) exist and reports their row counts.
 * Usage:    php sql/check_phase7_prereqs.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Table name and row count (or "NOT FOUND") for each prerequisite.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

$tables = ['teams', 'certifications', 'member_certifications', 'ics_positions', 'member_ics_qualifications', 'team_members', 'training_records'];
foreach ($tables as $t) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "$t: $count rows\n";
    } catch (Exception $e) {
        echo "$t: NOT FOUND\n";
    }
}
