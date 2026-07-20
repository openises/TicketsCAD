<?php
/**
 * Import FCC ULS license data (Amateur Radio or GMRS) into local database.
 *
 * Downloads are pipe-delimited files inside ZIP archives from the FCC:
 *   Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip
 *   GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip
 *
 * The ZIP contains several .dat files. We need:
 *   EN.dat — Entity (name, address, FRN)
 *   HD.dat — License header (callsign, grant/expiry, operator class)
 *
 * Usage:
 *   php tools/import-fcc.php amateur <path-to-extracted-dir>
 *   php tools/import-fcc.php gmrs <path-to-extracted-dir>
 *
 * This version uses MySQL temp tables to avoid PHP memory exhaustion.
 * Memory usage stays under 32MB regardless of dataset size.
 */

ini_set('memory_limit', '256M');  // modest limit — we stream, not buffer

require_once __DIR__ . '/../config.php';

// ── Parse arguments ─────────────────────────────────────────
$type = isset($argv[1]) ? strtolower($argv[1]) : '';
$path = isset($argv[2]) ? $argv[2] : '';

if (!in_array($type, ['amateur', 'gmrs']) || empty($path)) {
    echo "FCC ULS License Importer\n";
    echo "========================\n\n";
    echo "Usage: php tools/import-fcc.php <type> <path-to-extracted-dir>\n\n";
    echo "  type  = amateur | gmrs\n";
    echo "  path  = Directory containing EN.dat and HD.dat from FCC ZIP\n\n";
    echo "Download sources:\n";
    echo "  Amateur: https://data.fcc.gov/download/pub/uls/complete/l_amat.zip\n";
    echo "  GMRS:    https://data.fcc.gov/download/pub/uls/complete/l_gmrs.zip\n\n";
    echo "Steps:\n";
    echo "  1. Download the ZIP file\n";
    echo "  2. Extract to a directory (e.g., data/l_amat/)\n";
    echo "  3. Run this script pointing to that directory\n";
    exit(1);
}

// Resolve path
if (is_file($path) && pathinfo($path, PATHINFO_EXTENSION) === 'zip') {
    echo "NOTE: Please extract the ZIP file first, then point to the directory.\n";
    echo "      e.g.: unzip $path -d data/l_" . ($type === 'amateur' ? 'amat' : 'gmrs') . "/\n";
    exit(1);
}

if (!is_dir($path)) {
    echo "ERROR: Directory not found: $path\n";
    exit(1);
}

$enFile = rtrim($path, '/\\') . '/EN.dat';
$hdFile = rtrim($path, '/\\') . '/HD.dat';

if (!is_file($enFile)) {
    echo "ERROR: EN.dat not found in $path\n";
    echo "Expected: $enFile\n";
    exit(1);
}
if (!is_file($hdFile)) {
    echo "ERROR: HD.dat not found in $path\n";
    echo "Expected: $hdFile\n";
    exit(1);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $type === 'amateur' ? "{$prefix}fcc_amateur" : "{$prefix}fcc_gmrs";

// ── Create main table ──────────────────────────────────────
echo "FCC $type Import (streaming)\n";
echo "============================\n\n";

if ($type === 'amateur') {
    try {
        db_query("CREATE TABLE IF NOT EXISTS `$table` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `callsign`        VARCHAR(16)   NOT NULL,
            `oper_class`      VARCHAR(4)    DEFAULT NULL,
            `first_name`      VARCHAR(64)   DEFAULT NULL,
            `last_name`       VARCHAR(64)   DEFAULT NULL,
            `middle_initial`  VARCHAR(4)    DEFAULT NULL,
            `suffix`          VARCHAR(4)    DEFAULT NULL,
            `entity_name`     VARCHAR(200)  DEFAULT NULL,
            `entity_type`     CHAR(2)       DEFAULT NULL,
            `street`          VARCHAR(128)  DEFAULT NULL,
            `city`            VARCHAR(64)   DEFAULT NULL,
            `state`           VARCHAR(4)    DEFAULT NULL,
            `zip`             VARCHAR(16)   DEFAULT NULL,
            `frn`             VARCHAR(16)   DEFAULT NULL,
            `grant_date`      DATE          DEFAULT NULL,
            `expiry_date`     DATE          DEFAULT NULL,
            `last_action`     DATE          DEFAULT NULL,
            `lat`             DOUBLE        DEFAULT NULL,
            `lng`             DOUBLE        DEFAULT NULL,
            `grid_square`     VARCHAR(8)    DEFAULT NULL,
            `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_callsign` (`callsign`),
            KEY `idx_last_name_zip` (`last_name`, `zip`),
            KEY `idx_frn` (`frn`),
            KEY `idx_state` (`state`),
            KEY `idx_expiry` (`expiry_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[OK] $table table ready\n";
    } catch (Exception $e) {
        echo "[WARN] " . $e->getMessage() . "\n";
    }
} else {
    try {
        db_query("CREATE TABLE IF NOT EXISTS `$table` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `callsign`        VARCHAR(16)   DEFAULT NULL,
            `first_name`      VARCHAR(64)   DEFAULT NULL,
            `last_name`       VARCHAR(64)   DEFAULT NULL,
            `middle_initial`  VARCHAR(4)    DEFAULT NULL,
            `suffix`          VARCHAR(4)    DEFAULT NULL,
            `entity_name`     VARCHAR(200)  DEFAULT NULL,
            `entity_type`     CHAR(2)       DEFAULT NULL,
            `street`          VARCHAR(128)  DEFAULT NULL,
            `city`            VARCHAR(64)   DEFAULT NULL,
            `state`           VARCHAR(4)    DEFAULT NULL,
            `zip`             VARCHAR(16)   DEFAULT NULL,
            `frn`             VARCHAR(16)   DEFAULT NULL,
            `grant_date`      DATE          DEFAULT NULL,
            `expiry_date`     DATE          DEFAULT NULL,
            `last_action`     DATE          DEFAULT NULL,
            `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `idx_callsign` (`callsign`),
            KEY `idx_last_name_zip` (`last_name`, `zip`),
            KEY `idx_name_search` (`last_name`, `first_name`, `zip`),
            KEY `idx_frn` (`frn`),
            KEY `idx_state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "[OK] $table table ready\n";
    } catch (Exception $e) {
        echo "[WARN] " . $e->getMessage() . "\n";
    }
}

// ── Create staging tables ──────────────────────────────────
// These hold raw data from .dat files, then we JOIN-insert into the final table.
// Using regular tables (not TEMPORARY) so we can monitor progress.
$tmpHD = "{$prefix}_fcc_tmp_{$type}_hd";
$tmpEN = "{$prefix}_fcc_tmp_{$type}_en";

echo "\n[1/4] Creating staging tables...\n";

try {
    db_query("DROP TABLE IF EXISTS `$tmpHD`");
    db_query("DROP TABLE IF EXISTS `$tmpEN`");

    db_query("CREATE TABLE `$tmpHD` (
        `sys_id`       VARCHAR(20)  NOT NULL PRIMARY KEY,
        `callsign`     VARCHAR(16)  NOT NULL,
        `oper_class`   VARCHAR(4)   DEFAULT NULL,
        `grant_date`   DATE         DEFAULT NULL,
        `expiry_date`  DATE         DEFAULT NULL,
        `last_action`  DATE         DEFAULT NULL,
        KEY `idx_call` (`callsign`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_query("CREATE TABLE `$tmpEN` (
        `sys_id`           VARCHAR(20)  NOT NULL PRIMARY KEY,
        `entity_type`      CHAR(2)      DEFAULT NULL,
        `entity_name`      VARCHAR(200) DEFAULT NULL,
        `first_name`       VARCHAR(64)  DEFAULT NULL,
        `middle_initial`   VARCHAR(4)   DEFAULT NULL,
        `last_name`        VARCHAR(64)  DEFAULT NULL,
        `suffix`           VARCHAR(4)   DEFAULT NULL,
        `street`           VARCHAR(128) DEFAULT NULL,
        `city`             VARCHAR(64)  DEFAULT NULL,
        `state`            VARCHAR(4)   DEFAULT NULL,
        `zip`              VARCHAR(16)  DEFAULT NULL,
        `frn`              VARCHAR(16)  DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "[OK] Staging tables created\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

// ── Phase 1: Stream HD.dat into staging table ──────────────
// HD record fields:
//  0: Record Type (HD)
//  1: Unique System Identifier
//  4: Call Sign
//  5: License Status (A=Active)
//  7: Grant Date
//  8: Expired Date
// 43: Last Action Date
// 48: Operator Class (amateur only: T/G/E/A/N)

echo "\n[2/4] Streaming HD.dat into staging table...\n";

$handle = fopen($hdFile, 'r');
$lineCount = 0;
$activeCount = 0;
$batchRows = [];
$batchSize = 500;

while (($line = fgets($handle)) !== false) {
    $lineCount++;
    $fields = explode('|', rtrim($line, "\r\n"));

    if (count($fields) < 9) continue;

    $status = trim($fields[5]);
    if ($status !== 'A') continue;  // Only active licenses

    $sysId    = trim($fields[1]);
    $callsign = trim($fields[4]);
    if (empty($callsign) || empty($sysId)) continue;

    $grantDate  = !empty($fields[7]) ? parseDate($fields[7]) : null;
    $expiryDate = !empty($fields[8]) ? parseDate($fields[8]) : null;
    $lastAction = (isset($fields[43]) && !empty(trim($fields[43]))) ? parseDate($fields[43]) : null;
    $operClass  = isset($fields[48]) ? trim($fields[48]) : null;

    $batchRows[] = [$sysId, $callsign, $operClass, $grantDate, $expiryDate, $lastAction];
    $activeCount++;

    if (count($batchRows) >= $batchSize) {
        flushHDBatch($tmpHD, $batchRows);
        $batchRows = [];
    }

    if ($lineCount % 100000 === 0) {
        echo "  ... $lineCount lines read, $activeCount active\n";
    }
}
// Flush remaining
if (count($batchRows) > 0) {
    flushHDBatch($tmpHD, $batchRows);
    $batchRows = [];
}
fclose($handle);
echo "  HD.dat: $lineCount lines, $activeCount active licenses staged\n";

// ── Phase 2: Stream EN.dat into staging table ──────────────
// EN record fields:
//  0: Record Type (EN)
//  1: Unique System Identifier
//  4: Call Sign
//  5: Entity Type (I=Individual, C=Club)
//  7: Entity Name
//  8: First Name
//  9: MI
// 10: Last Name
// 11: Suffix
// 15: Street Address
// 16: City
// 17: State
// 18: Zip Code
// 22: FRN

echo "\n[3/4] Streaming EN.dat into staging table...\n";

$handle = fopen($enFile, 'r');
$lineCount = 0;
$loadedCount = 0;
$batchRows = [];

while (($line = fgets($handle)) !== false) {
    $lineCount++;
    $fields = explode('|', rtrim($line, "\r\n"));

    if (count($fields) < 19) continue;

    $sysId = trim($fields[1]);
    if (empty($sysId)) continue;

    // Load ALL EN records into staging — the JOIN will filter to active only.
    // This is faster than checking the HD staging table per row.
    $batchRows[] = [
        $sysId,
        trim($fields[5]),   // entity_type
        trim($fields[7]),   // entity_name
        trim($fields[8]),   // first_name
        trim($fields[9]),   // middle_initial
        trim($fields[10]),  // last_name
        trim($fields[11]),  // suffix
        trim($fields[15]),  // street
        trim($fields[16]),  // city
        trim($fields[17]),  // state
        trim($fields[18]),  // zip
        isset($fields[22]) ? trim($fields[22]) : null,  // frn
    ];
    $loadedCount++;

    if (count($batchRows) >= $batchSize) {
        flushENBatch($tmpEN, $batchRows);
        $batchRows = [];
    }

    if ($lineCount % 100000 === 0) {
        echo "  ... $lineCount lines read, $loadedCount loaded\n";
    }
}
if (count($batchRows) > 0) {
    flushENBatch($tmpEN, $batchRows);
    $batchRows = [];
}
fclose($handle);
echo "  EN.dat: $lineCount lines, $loadedCount entities staged\n";

// ── Phase 3: JOIN insert into final table ──────────────────
echo "\n[4/4] Merging staged data into $table...\n";

$beforeCount = 0;
try {
    $beforeCount = (int) db_fetch_value("SELECT COUNT(*) FROM `$table`");
} catch (Exception $e) {
    // Table may be empty or new
}

if ($type === 'amateur') {
    // Amateur: callsign is UNIQUE — use INSERT ... ON DUPLICATE KEY UPDATE
    $mergeSQL = "INSERT INTO `$table`
        (`callsign`, `oper_class`, `first_name`, `last_name`, `middle_initial`, `suffix`,
         `entity_name`, `entity_type`, `street`, `city`, `state`, `zip`, `frn`,
         `grant_date`, `expiry_date`, `last_action`)
        SELECT
            h.callsign, h.oper_class,
            e.first_name, e.last_name, e.middle_initial, e.suffix,
            e.entity_name, e.entity_type, e.street, e.city, e.state, e.zip, e.frn,
            h.grant_date, h.expiry_date, h.last_action
        FROM `$tmpHD` h
        INNER JOIN `$tmpEN` e ON e.sys_id = h.sys_id
        ON DUPLICATE KEY UPDATE
            `oper_class`     = VALUES(`oper_class`),
            `first_name`     = VALUES(`first_name`),
            `last_name`      = VALUES(`last_name`),
            `middle_initial` = VALUES(`middle_initial`),
            `suffix`         = VALUES(`suffix`),
            `entity_name`    = VALUES(`entity_name`),
            `entity_type`    = VALUES(`entity_type`),
            `street`         = VALUES(`street`),
            `city`           = VALUES(`city`),
            `state`          = VALUES(`state`),
            `zip`            = VALUES(`zip`),
            `frn`            = VALUES(`frn`),
            `grant_date`     = VALUES(`grant_date`),
            `expiry_date`    = VALUES(`expiry_date`),
            `last_action`    = VALUES(`last_action`)";
} else {
    // GMRS: no unique callsign, TRUNCATE and re-insert is cleanest
    echo "  Clearing existing GMRS records for clean import...\n";
    try {
        db_query("TRUNCATE TABLE `$table`");
    } catch (Exception $e) {
        // May not exist yet
    }

    $mergeSQL = "INSERT INTO `$table`
        (`callsign`, `first_name`, `last_name`, `middle_initial`, `suffix`,
         `entity_name`, `entity_type`, `street`, `city`, `state`, `zip`, `frn`,
         `grant_date`, `expiry_date`, `last_action`)
        SELECT
            h.callsign,
            e.first_name, e.last_name, e.middle_initial, e.suffix,
            e.entity_name, e.entity_type, e.street, e.city, e.state, e.zip, e.frn,
            h.grant_date, h.expiry_date, h.last_action
        FROM `$tmpHD` h
        INNER JOIN `$tmpEN` e ON e.sys_id = h.sys_id";
}

echo "  Running merge query (this may take a minute)...\n";
$startTime = microtime(true);

try {
    db_query($mergeSQL);
} catch (Exception $e) {
    echo "[FAIL] Merge failed: " . $e->getMessage() . "\n";
    // Clean up staging tables
    try { db_query("DROP TABLE IF EXISTS `$tmpHD`"); } catch (Exception $x) {}
    try { db_query("DROP TABLE IF EXISTS `$tmpEN`"); } catch (Exception $x) {}
    exit(1);
}

$elapsed = round(microtime(true) - $startTime, 1);

$afterCount = 0;
try {
    $afterCount = (int) db_fetch_value("SELECT COUNT(*) FROM `$table`");
} catch (Exception $e) {
    // ignore
}

$newRecords = $afterCount - $beforeCount;

echo "  Merge completed in {$elapsed}s\n";
echo "  Records before: " . number_format($beforeCount) . "\n";
echo "  Records after:  " . number_format($afterCount) . "\n";
echo "  Net change:     " . ($newRecords >= 0 ? '+' : '') . number_format($newRecords) . "\n";

// ── Phase 5: AM.dat → fcc_amateur.oper_class (PRE-RELEASE-FIXES #9) ──
//
// HD.dat field 48 is the radio-service class code (e.g. "HA"), NOT the
// licensee's amateur operator class. Operator class lives in AM.dat:
//   0: Record Type (AM)
//   1: Unique System Identifier
//   4: Call Sign
//   5: Operator Class (T=Technician, G=General, E=Extra, A=Advanced,
//      P=Tech Plus, N=Novice)
//
// We do this AFTER the main merge so we can update the canonical table
// in-place without juggling more staging.
if ($type === 'amateur') {
    $amFile = rtrim($path, '/\\') . '/AM.dat';
    if (file_exists($amFile)) {
        echo "\n[5/5] Parsing AM.dat for operator class...\n";
        $amHandle = fopen($amFile, 'r');
        $amCount = 0; $amApplied = 0;

        // Build a callsign → oper_class map so we can do a single bulk UPDATE.
        $opMap = [];
        while (($line = fgets($amHandle)) !== false) {
            $fields = explode('|', rtrim($line, "\r\n"));
            if (count($fields) < 6) continue;
            $cs = trim($fields[4]);
            $oc = trim($fields[5]);
            if ($cs !== '' && $oc !== '') {
                $opMap[$cs] = $oc;
                $amCount++;
            }
        }
        fclose($amHandle);
        echo "  AM.dat: " . number_format($amCount) . " operator-class records parsed\n";

        // Bulk update via a CASE expression in chunks of 5000.
        $chunks = array_chunk($opMap, 5000, true);
        foreach ($chunks as $chunk) {
            $cases = [];
            $params = [];
            $callsigns = [];
            foreach ($chunk as $cs => $oc) {
                $cases[] = "WHEN ? THEN ?";
                $params[] = $cs;
                $params[] = $oc;
                $callsigns[] = $cs;
            }
            $inPlaceholders = rtrim(str_repeat('?, ', count($callsigns)), ', ');
            $params = array_merge($params, $callsigns);

            $caseSQL = implode(' ', $cases);
            db_query(
                "UPDATE `$table` SET oper_class = CASE callsign $caseSQL ELSE oper_class END
                 WHERE callsign IN ($inPlaceholders)",
                $params
            );
            $amApplied += count($chunk);
        }
        echo "  oper_class applied to " . number_format($amApplied) . " amateur callsigns\n";
    } else {
        echo "\n[5/5] AM.dat not found at $amFile — oper_class will remain blank\n";
        echo "      (Re-extract the FCC ULS l_amat.zip with all .dat files for full data.)\n";
    }
}

// ── Cleanup staging tables ─────────────────────────────────
echo "\nCleaning up staging tables...\n";
try { db_query("DROP TABLE IF EXISTS `$tmpHD`"); } catch (Exception $e) {}
try { db_query("DROP TABLE IF EXISTS `$tmpEN`"); } catch (Exception $e) {}
echo "[OK] Staging tables dropped\n";

echo "\nDone! $table now has " . number_format($afterCount) . " records.\n";

// ═══════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════

/**
 * Batch-insert HD rows into staging table.
 * Each row: [sys_id, callsign, oper_class, grant_date, expiry_date, last_action]
 */
function flushHDBatch($table, $rows) {
    if (empty($rows)) return;

    $placeholders = [];
    $params = [];
    foreach ($rows as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?)';
        foreach ($row as $val) {
            $params[] = $val;
        }
    }

    $sql = "INSERT IGNORE INTO `$table` (`sys_id`, `callsign`, `oper_class`, `grant_date`, `expiry_date`, `last_action`)
            VALUES " . implode(', ', $placeholders);

    db_query($sql, $params);
}

/**
 * Batch-insert EN rows into staging table.
 * Each row: [sys_id, entity_type, entity_name, first_name, middle_initial, last_name, suffix, street, city, state, zip, frn]
 */
function flushENBatch($table, $rows) {
    if (empty($rows)) return;

    $placeholders = [];
    $params = [];
    foreach ($rows as $row) {
        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        foreach ($row as $val) {
            $params[] = $val;
        }
    }

    $sql = "INSERT IGNORE INTO `$table`
            (`sys_id`, `entity_type`, `entity_name`, `first_name`, `middle_initial`, `last_name`,
             `suffix`, `street`, `city`, `state`, `zip`, `frn`)
            VALUES " . implode(', ', $placeholders);

    db_query($sql, $params);
}

/**
 * Parse FCC date format MM/DD/YYYY → YYYY-MM-DD
 */
function parseDate($str) {
    $str = trim($str);
    if (empty($str)) return null;

    // FCC format: MM/DD/YYYY
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $str, $m)) {
        return $m[3] . '-' . $m[1] . '-' . $m[2];
    }

    // Try standard YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
        return $str;
    }

    return null;
}
