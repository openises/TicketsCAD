<?php
/**
 * Run the soft_delete_mileage.sql migration via PHP.
 * Usage: php tools/run_migration_soft_delete.php
 */

require_once __DIR__ . '/../config.php';

echo "Running soft_delete_mileage migration...\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$errors = 0;

// Helper: run a guarded ALTER TABLE ADD COLUMN
function addColumnIfMissing($table, $column, $definition) {
    global $errors;
    try {
        $col = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        if (!$col) {
            db_query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            echo "  + Added {$table}.{$column}\n";
        } else {
            echo "  . {$table}.{$column} already exists\n";
        }
    } catch (Exception $e) {
        echo "  ! Error on {$table}.{$column}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

// Helper: create index if missing
function addIndexIfMissing($table, $indexName, $columns) {
    global $errors;
    try {
        $idx = db_fetch_one(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );
        if (!$idx) {
            db_query("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");
            echo "  + Created index {$indexName} on {$table}\n";
        } else {
            echo "  . Index {$indexName} already exists\n";
        }
    } catch (Exception $e) {
        echo "  ! Error creating index {$indexName}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "-- Soft-delete columns --\n";

$tables = ['member', 'responder', 'ticket', 'facilities'];
foreach ($tables as $t) {
    $tbl = $prefix . $t;
    // Check table exists first
    try {
        db_fetch_one("SELECT 1 FROM `{$tbl}` LIMIT 1");
    } catch (Exception $e) {
        echo "  . Table {$tbl} does not exist, skipping\n";
        continue;
    }
    addColumnIfMissing($tbl, 'deleted_at', 'DATETIME DEFAULT NULL');
    addColumnIfMissing($tbl, 'deleted_by', 'INT DEFAULT NULL');
    addIndexIfMissing($tbl, "idx_{$t}_deleted", '`deleted_at`');
}

echo "\n-- Mileage log table --\n";
try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'mileage_log']
    );
    if (!$tbl) {
        db_query("CREATE TABLE IF NOT EXISTS `{$prefix}mileage_log` (
            `id`            INT AUTO_INCREMENT PRIMARY KEY,
            `responder_id`  INT NOT NULL,
            `user_id`       INT NOT NULL,
            `ticket_id`     INT DEFAULT NULL,
            `start_odo`     DECIMAL(10,1) DEFAULT NULL,
            `end_odo`       DECIMAL(10,1) DEFAULT NULL,
            `miles`         DECIMAL(10,1) DEFAULT NULL,
            `started_at`    DATETIME NOT NULL,
            `ended_at`      DATETIME DEFAULT NULL,
            `notes`         VARCHAR(255) DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mileage_responder (`responder_id`),
            INDEX idx_mileage_user (`user_id`),
            INDEX idx_mileage_ticket (`ticket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "  + Created mileage_log table\n";
    } else {
        echo "  . mileage_log table already exists\n";
    }
} catch (Exception $e) {
    echo "  ! Error: " . $e->getMessage() . "\n";
    $errors++;
}

echo "\n";
if ($errors > 0) {
    echo "Completed with {$errors} error(s).\n";
    exit(1);
} else {
    echo "Migration complete. No errors.\n";
    exit(0);
}
