<?php
/**
 * GH #78 regression — unit + facility notes merge into the recent-events feed.
 *
 * api/log.php merges responder_notes + facility_notes into its entries so the
 * Situation Events panel and the dashboard Recent Events widget show them.
 * This test exercises the exact merge SELECTs: names resolve via the joins,
 * soft-deleted unit notes are excluded, and facility notes (no deleted_at)
 * come through. Self-heals the note tables (they self-create on first write in
 * production) so the test runs on a fresh DB too.
 *
 * Usage: php tests/test_log_notes_merge.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function test($name, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "[PASS] $name\n"; }
    else       { $fail++; echo "[FAIL] $name\n"; }
}

// Ensure the note tables exist (mirror the app's self-heal DDL).
db_query("CREATE TABLE IF NOT EXISTS `{$prefix}responder_notes` (
    id INT AUTO_INCREMENT PRIMARY KEY, responder_id INT NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'general', note TEXT NOT NULL,
    by_user INT NOT NULL DEFAULT 0, by_username VARCHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL, deleted_by INT NULL) ENGINE=InnoDB");
db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_notes` (
    id INT AUTO_INCREMENT PRIMARY KEY, facility_id INT NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'general', note VARCHAR(1000) NOT NULL,
    detail VARCHAR(255) NULL, user_id INT NOT NULL DEFAULT 0,
    username VARCHAR(64) NOT NULL DEFAULT '', source_ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$now = date('Y-m-d H:i:s');
$days = 7;
$respId = 0; $facId = 0;
$tag = 'gh78-' . substr(md5($now . rand()), 0, 8);

try {
    db_query("INSERT INTO `{$prefix}responder` (name, handle, description)
              VALUES ('GH78 Unit', 'GH78TST', 'gh78 test')");
    $respId = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}facilities` (name, description)
              VALUES ('GH78 Clinic', 'gh78 test')");
    $facId = (int) db_insert_id();

    // Two unit notes — one live, one soft-deleted (must be excluded).
    db_query("INSERT INTO `{$prefix}responder_notes`
              (responder_id, note, by_username, created_at)
              VALUES (?, ?, 'tester', ?)", [$respId, "$tag live unit note", $now]);
    db_query("INSERT INTO `{$prefix}responder_notes`
              (responder_id, note, by_username, created_at, deleted_at)
              VALUES (?, ?, 'tester', ?, ?)", [$respId, "$tag deleted unit note", $now, $now]);
    // One facility note.
    db_query("INSERT INTO `{$prefix}facility_notes`
              (facility_id, note, username, created_at)
              VALUES (?, ?, 'tester', ?)", [$facId, "$tag facility note", $now]);

    // ── Unit-notes merge query (verbatim shape from api/log.php) ──
    $unitNotes = db_fetch_all(
        "SELECT n.note, n.by_username, n.created_at, n.responder_id, r.handle, r.name
           FROM `{$prefix}responder_notes` n
           LEFT JOIN `{$prefix}responder` r ON n.responder_id = r.id
          WHERE n.deleted_at IS NULL
            AND n.created_at >= CURRENT_DATE - INTERVAL ? DAY
            AND n.responder_id = ?
          ORDER BY n.created_at DESC", [$days, $respId]);
    test('unit note merge returns the live note', count($unitNotes) === 1);
    test('unit note excludes the soft-deleted one',
        count($unitNotes) === 1 && strpos($unitNotes[0]['note'], 'deleted') === false);
    test('unit note resolves the unit handle for the label',
        !empty($unitNotes) && $unitNotes[0]['handle'] === 'GH78TST');

    // ── Facility-notes merge query ──
    $facNotes = db_fetch_all(
        "SELECT n.note, n.username, n.created_at, n.facility_id, f.name AS facility_name
           FROM `{$prefix}facility_notes` n
           LEFT JOIN `{$prefix}facilities` f ON n.facility_id = f.id
          WHERE n.created_at >= CURRENT_DATE - INTERVAL ? DAY
            AND n.facility_id = ?
          ORDER BY n.created_at DESC", [$days, $facId]);
    test('facility note merge returns the note', count($facNotes) === 1);
    test('facility note resolves the facility name for the label',
        !empty($facNotes) && $facNotes[0]['facility_name'] === 'GH78 Clinic');
} finally {
    if ($respId) { db_query("DELETE FROM `{$prefix}responder_notes` WHERE responder_id = ?", [$respId]); }
    if ($facId)  { db_query("DELETE FROM `{$prefix}facility_notes` WHERE facility_id = ?", [$facId]); }
    if ($respId) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$respId]); }
    if ($facId)  { db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$facId]); }
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
