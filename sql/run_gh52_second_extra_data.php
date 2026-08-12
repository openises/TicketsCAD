<?php
/**
 * GH#52 (Eric, 2026-08-12, option 2 of 3 proposed) — a second, fixed
 * extra-data slot on unit statuses.
 *
 * un_status could only ever collect ONE datum per status change
 * (extra_data_type/required/label/target -- singular columns). Real need
 * (@rjonesbsink's trace on GH#52): a status like "Transporting" wants
 * BOTH a destination facility AND a starting mileage reading collected at
 * once. Rather than a fully general N-field system (a new child table,
 * more schema/UI/migration risk than proven need justifies), this adds a
 * SECOND fixed slot -- same four columns, suffixed _2. Two is what has
 * actually been asked for.
 *
 * All four new columns default to the same "off" state as the originals
 * (extra_data_type_2='none', extra_data_required_2=0, label_2=NULL,
 * target_2='action_log') -- every existing status row keeps collecting
 * exactly what it collects today; slot 2 is purely additive.
 *
 * Idempotent -- checks information_schema before altering.
 *
 * No `AFTER <col>` positioning: this migration's filename (run_gh52_...)
 * sorts alphabetically BEFORE run_phase95_status_extra_data.php, the
 * migration that creates extra_data_target -- on a fresh install
 * run_migrations.php's ksort() runs this one first, so `AFTER
 * extra_data_target` failed with "Unknown column" (caught on CI's genuine
 * fresh-install run, not reproducible against a long-lived dev DB where
 * phase95 had already applied months ago). Column order in a wide table
 * is cosmetic only -- nothing queries these by position -- so the columns
 * just append at the end instead of chasing migration ordering.
 *
 * Usage:
 *   php sql/run_gh52_second_extra_data.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$pdo    = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#52 — second extra-data slot on un_status\n";
echo "==============================================\n\n";

function gh52_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$table = "{$prefix}un_status";

try {
    if (gh52_column_exists($pdo, $table, 'extra_data_type_2')) {
        echo "  [skip] extra_data_type_2 already exists (assuming all four columns do)\n";
    } else {
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD COLUMN `extra_data_type_2` ENUM('none','facility','mileage','location','note','numeric')
                 NOT NULL DEFAULT 'none',
             ADD COLUMN `extra_data_required_2` TINYINT(1) NOT NULL DEFAULT 0,
             ADD COLUMN `extra_data_label_2` VARCHAR(64) NULL,
             ADD COLUMN `extra_data_target_2` ENUM('incident','unit','action_log','assignment')
                 NOT NULL DEFAULT 'action_log'"
        );
        echo "  [+] extra_data_type_2 / required_2 / label_2 / target_2 added\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nDone.\n";
