<?php
/**
 * Retire beta_tester_applications (2026-08-23) — Eric's explicit decision:
 * this feature (a public beta-signup form + an admin review-workflow schema
 * that was never actually built — see tools/dead_control_phantom_baseline.txt's
 * beta_tester_applications entries) was never intended to ship as part of the
 * software at all. It shipped anyway: confirmed present in the public repo
 * (openises/TicketsCAD), and deployed on both live hosts
 * (your-server.example.com — 12 real applications — and your-server,
 * 0 rows). beta-tester.php, its .htaccess pretty-URL rule, and the original
 * CREATE migration (sql/run_beta_tester_applications.php) are all removed in
 * this same change. This script is the corresponding DROP for any install
 * that already ran that CREATE — idempotent, safe to re-run, and safe on a
 * fresh install that never had the table (a fresh install no longer even has
 * the CREATE script to run, since it's deleted alongside this one).
 *
 * Unlike a typical "confirmed-dead, always zero rows" removal (see
 * sql/run_gh96_drop_requests_table.php for that shape, which refuses on any
 * non-empty table), this table legitimately had 12 real rows on training.
 * Refusing on non-empty here would just be noise — the removal is already
 * authorized regardless of row count, after Eric reviewed a full export of
 * every column on every row. So instead of refusing, this script writes its
 * OWN timestamped SQL dump of the table's full contents to BACKUP_DIR before
 * dropping anything — a second safety net beyond the export already taken,
 * in case the exported spreadsheet is ever lost or found incomplete later.
 *
 * Also removes the orphaned `beta_application_notify_to` settings row —
 * beta-tester.php was its only reader, so once that file is gone the row is
 * genuinely dead (not just unused-for-now), and leaving it would surface as
 * a fresh finding in tools/dead_control_audit.php's dead-settings-key check.
 *
 * Usage: php sql/run_retire_beta_tester_applications.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/backup.php'; // for BACKUP_DIR

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $exists = db_fetch_value(
        "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'beta_tester_applications']
    );

    if (!$exists) {
        echo "[SKIP] {$prefix}beta_tester_applications does not exist — nothing to drop "
            . "(already dropped, or a fresh install that never created it)\n";
    } else {
        $tableName = $prefix . 'beta_tester_applications';
        $rows = db_fetch_all("SELECT * FROM `{$tableName}`");
        $rowCount = count($rows);

        if ($rowCount > 0) {
            if (!is_dir(BACKUP_DIR)) { @mkdir(BACKUP_DIR, 0770, true); }
            $backupPath = rtrim(BACKUP_DIR, '/\\') . '/beta_tester_applications_' . date('Ymd_His') . '.sql';
            $cols = array_keys($rows[0]);
            $colList = '`' . implode('`, `', $cols) . '`';
            $lines = [
                "-- beta_tester_applications retirement backup — " . date('c'),
                "-- {$rowCount} row(s), taken immediately before DROP TABLE by",
                "-- sql/run_retire_beta_tester_applications.php. The same data was",
                "-- already exported to a spreadsheet and reviewed by Eric before this",
                "-- script was authorized to run; this is a second safety net only.",
                "",
            ];
            foreach ($rows as $row) {
                $pdo = db();
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string) $v);
                }, $row);
                $lines[] = "INSERT INTO `{$tableName}` ({$colList}) VALUES (" . implode(', ', $vals) . ");";
            }
            file_put_contents($backupPath, implode("\n", $lines) . "\n");
            @chmod($backupPath, 0640);
            echo "[OK] backed up {$rowCount} row(s) to {$backupPath}\n";
        } else {
            echo "[OK] {$tableName} has 0 rows — no backup needed\n";
        }

        db_query("DROP TABLE `{$tableName}`");
        echo "[OK] {$tableName} dropped ({$rowCount} row(s) backed up first)\n";
    }

    $settingsRow = db_fetch_value(
        "SELECT 1 FROM `{$prefix}settings` WHERE `name` = 'beta_application_notify_to'"
    );
    if ($settingsRow) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'beta_application_notify_to'");
        echo "[OK] orphaned setting beta_application_notify_to removed\n";
    } else {
        echo "[SKIP] beta_application_notify_to setting not present — nothing to remove\n";
    }

    echo "\nDone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
