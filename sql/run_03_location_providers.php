<?php
/**
 * Run Location Providers — Create location tracking schema and seed providers.
 *
 * Purpose:  Creates location_providers, location_reports, and
 *           unit_location_bindings tables. Seeds 7 default providers
 *           (APRS, Meshtastic, OwnTracks, DMR, Zello, Internal GPS, Manual)
 *           with only Internal GPS enabled by default.
 * Usage:    php sql/run_location_providers.php
 * Prerequisites: config.php; location_providers.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   OK/ERR per SQL statement; row counts for created tables.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// Remove SQL comments and split on semicolons
$sql = file_get_contents(__DIR__ . '/location_providers.sql');
// Remove single-line comments
$sql = preg_replace('/^--.*$/m', '', $sql);
// Split on END-OF-LINE semicolons only. The old explode(';') split inside
// a JSON string value ("...on_location events; unit_identifier = ...") and
// silently truncated the provider INSERT — every fresh install since
// 2026-06-26 seeded ZERO default providers (diagnosed 2026-07-07).
$statements = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)));

$count = 0;
$errors = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    $count++;
    try {
        $pdo->exec($stmt);
        echo "OK [{$count}]: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "...\n";
    } catch (PDOException $e) {
        echo "ERR [{$count}]: " . $e->getMessage() . "\n";
        echo "  SQL: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 120) . "\n";
        $errors++;
    }
}

echo "\nDone ({$count} statements). Checking tables...\n";

// ── Repairs for installs created while the splitter bug was live ──
// (This file's hash changed, so run_migrations.php re-runs it everywhere.)

// unit_location_bindings.source — in the CREATE for fresh installs; ALTER
// for tables that predate it. proxy/ZelloProxyApp.php INSERTs this column.
try {
    $hasSource = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'unit_location_bindings' AND COLUMN_NAME = 'source'"
    );
    if (!$hasSource) {
        $pdo->exec("ALTER TABLE `unit_location_bindings`
                    ADD COLUMN `source` ENUM('manual','personnel') NOT NULL DEFAULT 'manual'");
        echo "repair: added unit_location_bindings.source\n";
    }
    $hasAsgn = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'unit_location_bindings' AND COLUMN_NAME = 'assignment_id'"
    );
    if (!$hasAsgn) {
        $pdo->exec("ALTER TABLE `unit_location_bindings` ADD COLUMN `assignment_id` INT NULL");
        echo "repair: added unit_location_bindings.assignment_id\n";
    }
} catch (PDOException $e) {
    echo "ERR: source-column repair failed: " . $e->getMessage() . "\n";
    $errors++;
}

// Per-provider staleness defaults for rows seeded AFTER run_unit_assignments
// added max_age_seconds (its one-time backfill can't reach rows that didn't
// exist yet). Only touches rows still on the column default (300).
try {
    $hasMaxAge = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'location_providers' AND COLUMN_NAME = 'max_age_seconds'"
    );
    if ($hasMaxAge) {
        $staleDefaults = ['aprs' => 600, 'meshtastic' => 300, 'owntracks' => 120,
                          'opengts' => 600, 'dmr' => 900, 'internal' => 60, 'google_lat' => 3600];
        foreach ($staleDefaults as $code => $age) {
            $pdo->prepare("UPDATE `location_providers` SET `max_age_seconds` = ?
                           WHERE `code` = ? AND `max_age_seconds` = 300")
                ->execute([$age, $code]);
        }
        echo "repair: per-provider staleness defaults applied\n";
    }
} catch (PDOException $e) { echo "note: staleness backfill skipped: " . $e->getMessage() . "\n"; }

// Verify tables
$tables = ['location_providers', 'location_reports', 'unit_location_bindings'];
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "{$t}: {$c} rows\n";
    } catch (PDOException $e) {
        echo "{$t}: MISSING - " . $e->getMessage() . "\n";
    }
}

// List seeded providers
echo "\nSeeded providers:\n";
try {
    $rows = $pdo->query("SELECT `code`, `name`, `enabled`, `priority`, `icon`, `color` FROM `location_providers` ORDER BY `priority`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $status = $row['enabled'] ? 'ENABLED' : 'disabled';
        echo "  [{$row['priority']}] {$row['code']} — {$row['name']} ({$status}) {$row['icon']} {$row['color']}\n";
    }
} catch (PDOException $e) {
    echo "  Could not list providers: " . $e->getMessage() . "\n";
    $errors++;
}

// Non-zero exit on any statement failure so run_migrations.php's
// exit-code detection sees it (this script previously always exited 0 —
// the truncated-INSERT failure above was recorded as applied).
exit($errors > 0 ? 1 : 0);
