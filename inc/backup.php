<?php
/**
 * NewUI v4.0 - Database Backup Functions
 *
 * Generates full SQL dumps using PDO (no mysqldump dependency).
 * Streams output to disk to avoid memory exhaustion on large databases.
 *
 * Usage:
 *   require_once __DIR__ . '/backup.php';
 *   $sqlFile = backup_dump_sql(sys_get_temp_dir() . '/backup.sql');
 *   $configJson = backup_export_config();
 *   $zipFile = backup_create_zip($sqlFile, $configJson, '/path/to/output.zip');
 */

// Default backup directory on filesystem
define('BACKUP_DIR', NEWUI_ROOT . '/backups');

/**
 * Generate a full SQL dump of the database to a file.
 * Uses unbuffered queries to stream rows without exhausting memory.
 *
 * @param  string $outputPath  Path to write the .sql file
 * @return bool   TRUE on success
 * @throws RuntimeException on failure
 */
function backup_dump_sql(string $outputPath): bool
{
    global $db_host, $db_user, $db_pass, $db_name;

    $fh = @fopen($outputPath, 'w');
    if (!$fh) {
        throw new RuntimeException('Cannot open output file: ' . $outputPath);
    }

    // Create a separate unbuffered PDO connection for streaming
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $unbuffered = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
    ]);

    // Use the regular connection for metadata queries
    $pdo = db();

    // Header
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    $now = date('Y-m-d H:i:s');
    fwrite($fh, "-- TicketsCAD NewUI Database Backup\n");
    fwrite($fh, "-- Generated: {$now}\n");
    fwrite($fh, "-- Server: {$db_host}\n");
    fwrite($fh, "-- Database: {$db_name}\n");
    fwrite($fh, "-- MySQL Version: {$version}\n");
    fwrite($fh, "-- PHP Version: " . PHP_VERSION . "\n");
    fwrite($fh, "-- TicketsCAD Version: " . (defined('NEWUI_VERSION') ? NEWUI_VERSION : 'unknown') . "\n");
    fwrite($fh, "--\n\n");

    fwrite($fh, "SET NAMES utf8mb4;\n");
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
    fwrite($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($fh, "SET AUTOCOMMIT = 0;\n");
    fwrite($fh, "SET time_zone = '+00:00';\n\n");

    // Get list of base tables (skip views)
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as $tableRow) {
        // Phase 41: Sonar S2077 — even though $table comes from SHOW
        // TABLES (no user input), restrict to MySQL-legal identifier
        // characters so a future leak (e.g. a manually-named view) can't
        // smuggle SQL through string interpolation.
        $table = (string) $tableRow[0];
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
            fwrite($fh, "-- (skipped table with unusual name: " . addslashes($table) . ")\n\n");
            continue;
        }

        fwrite($fh, "-- --------------------------------------------------------\n");
        fwrite($fh, "-- Table: `{$table}`\n");
        fwrite($fh, "-- --------------------------------------------------------\n\n");

        // Schema
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
        fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fh, $createStmt[1] . ";\n\n");

        // Row count
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($count === 0) {
            fwrite($fh, "-- (empty table)\n\n");
            continue;
        }

        // Get column metadata for BLOB detection
        $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = [];
        $blobCols = [];
        foreach ($columns as $idx => $col) {
            $colNames[] = $col['Field'];
            $type = strtolower($col['Type']);
            if (strpos($type, 'blob') !== false || strpos($type, 'binary') !== false) {
                $blobCols[$idx] = true;
            }
        }

        $colList = '`' . implode('`, `', $colNames) . '`';
        fwrite($fh, "-- Dumping data for `{$table}` ({$count} rows)\n\n");

        // Disable keys for MyISAM performance
        fwrite($fh, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");

        // Stream rows with unbuffered query.
        // SQL injection: $table validated as [A-Za-z0-9_$]+ above (Sonar S2077).
        $dataStmt = $unbuffered->query("SELECT * FROM `{$table}`"); // NOSONAR

        $batchSize = 0;
        $maxBatch = 1048576; // 1 MB per INSERT statement
        $values = [];
        $rowNum = 0;

        while ($row = $dataStmt->fetch(PDO::FETCH_NUM)) {
            $rowNum++;
            $escaped = [];
            foreach ($row as $idx => $val) {
                if ($val === null) {
                    $escaped[] = 'NULL';
                } elseif (isset($blobCols[$idx])) {
                    $escaped[] = strlen($val) > 0 ? '0x' . bin2hex($val) : "''";
                } elseif (is_numeric($val) && !isset($blobCols[$idx]) && strpos($val, '0') !== 0 && strpos($val, '+') === false) {
                    // Numeric value (but not zero-padded strings like zip codes)
                    $escaped[] = $val;
                } else {
                    $escaped[] = $pdo->quote($val);
                }
            }
            $rowStr = '(' . implode(',', $escaped) . ')';
            $rowLen = strlen($rowStr);

            if (empty($values)) {
                // Start new INSERT
                $values[] = $rowStr;
                $batchSize = $rowLen;
            } elseif ($batchSize + $rowLen + 2 > $maxBatch) {
                // Flush current batch
                fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $values) . ";\n");
                $values = [$rowStr];
                $batchSize = $rowLen;
            } else {
                $values[] = $rowStr;
                $batchSize += $rowLen + 2;
            }
        }

        // Flush remaining
        if (!empty($values)) {
            fwrite($fh, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $values) . ";\n");
        }

        fwrite($fh, "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n\n");

        // Free the unbuffered result set
        $dataStmt->closeCursor();
        unset($dataStmt);
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fwrite($fh, "COMMIT;\n");
    fwrite($fh, "\n-- Backup complete\n");

    fclose($fh);
    $unbuffered = null;

    return true;
}

/**
 * Export system configuration as JSON.
 * Includes settings, incident types, statuses, and other structural data.
 * Sensitive values (passwords, API keys, secrets) are masked.
 *
 * @return string  JSON string
 */
function backup_export_config(): string
{
    $pdo = db();
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $config = [
        '_meta' => [
            'generated'   => date('Y-m-d H:i:s'),
            'version'     => defined('NEWUI_VERSION') ? NEWUI_VERSION : 'unknown',
            'php_version' => PHP_VERSION,
            'database'    => $GLOBALS['db_name'] ?? '',
        ],
    ];

    // Settings table
    try {
        $rows = $pdo->query("SELECT * FROM `{$prefix}settings`")->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $row) {
            $key = $row['name'] ?? $row['key'] ?? '';
            $value = $row['value'] ?? '';
            // Mask sensitive values
            if (preg_match('/password|secret|key|token|api_key|apikey|webhook.*url/i', $key)) {
                $value = '********';
            }
            $settings[$key] = $value;
        }
        $config['settings'] = $settings;
    } catch (Exception $e) {
        $config['settings'] = ['_error' => $e->getMessage()];
    }

    // Structural config tables (safe to export)
    $configTables = [
        'in_types'      => 'incident_types',
        'un_status'     => 'unit_statuses',
        'unit_types'    => 'unit_types',
        'facility_types'=> 'facility_types',
        'severity'      => 'severity_levels',
        'member_types'  => 'member_types',
        'member_status' => 'member_statuses',
        'comm_modes'    => 'comm_modes',
        'teams'         => 'teams',
        'organizations' => 'organizations',
    ];

    foreach ($configTables as $table => $label) {
        try {
            $rows = $pdo->query("SELECT * FROM `{$prefix}{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            $config[$label] = $rows;
        } catch (Exception $e) {
            // Table may not exist — skip silently
        }
    }

    // RBAC roles and permissions. Schema audit 2026-07-07: the tables are
    // `roles` / `permissions` (not rbac_*) — the old names threw and the
    // catch meant every backup silently shipped WITHOUT its RBAC config.
    // Include the mapping tables too; roles without their grants are
    // useless on restore.
    try {
        $config['rbac_roles']            = $pdo->query("SELECT * FROM `{$prefix}roles`")->fetchAll(PDO::FETCH_ASSOC);
        $config['rbac_permissions']      = $pdo->query("SELECT * FROM `{$prefix}permissions`")->fetchAll(PDO::FETCH_ASSOC);
        $config['rbac_role_permissions'] = $pdo->query("SELECT * FROM `{$prefix}role_permissions`")->fetchAll(PDO::FETCH_ASSOC);
        $config['rbac_user_roles']       = $pdo->query("SELECT * FROM `{$prefix}user_roles`")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[backup] RBAC export failed: ' . $e->getMessage());
    }

    return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Create a .zip file containing the SQL dump, config JSON, and a README.
 *
 * @param  string $sqlPath     Path to the SQL dump file on disk
 * @param  string $configJson  JSON config string
 * @param  string $zipPath     Output path for the .zip file
 * @return bool   TRUE on success
 */
function backup_create_zip(string $sqlPath, string $configJson, string $zipPath): bool
{
    // If ZipArchive is available, use it for a proper .zip
    if (!class_exists('ZipArchive')) {
        // Fallback: create a gzip-compressed SQL file with config appended as comment
        return backup_create_gzip_fallback($sqlPath, $configJson, $zipPath);
    }

    $zip = new ZipArchive();
    $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($result !== true) {
        throw new RuntimeException('Cannot create zip file: error code ' . $result);
    }

    // Add SQL dump from disk (not memory)
    $zip->addFile($sqlPath, 'backup.sql');

    // Add config JSON
    $zip->addFromString('config.json', $configJson);

    // Add README with restore instructions
    $readme = "TicketsCAD NewUI — Backup Archive\n";
    $readme .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $readme .= "Version: " . (defined('NEWUI_VERSION') ? NEWUI_VERSION : 'unknown') . "\n\n";
    $readme .= "Contents:\n";
    $readme .= "  backup.sql   — Full database dump (all tables)\n";
    $readme .= "  config.json  — System configuration snapshot\n";
    $readme .= "  README.txt   — This file\n\n";
    $readme .= "RESTORE INSTRUCTIONS\n";
    $readme .= "====================\n\n";
    $readme .= "1. Database Restore:\n";
    $readme .= "   mysql -u USERNAME -p DATABASE_NAME < backup.sql\n\n";
    $readme .= "   Or on Windows XAMPP:\n";
    $readme .= "   C:\\xampp\\8.2.4\\mysql\\bin\\mysql.exe -u newui -p newui < backup.sql\n\n";
    $readme .= "2. Config Review:\n";
    $readme .= "   Open config.json to review system settings.\n";
    $readme .= "   Sensitive values (passwords, API keys) are masked with ********\n";
    $readme .= "   and must be re-entered manually after restore.\n\n";
    $readme .= "3. Encryption Keys:\n";
    $readme .= "   This backup does NOT include encryption key files (../keys/).\n";
    $readme .= "   You must back up these separately:\n";
    $readme .= "     ../keys/tfa.key       — 2FA encryption key\n";
    $readme .= "     ../keys/private.pem   — RSA field encryption key\n";
    $readme .= "     ../keys/public.pem    — RSA public key\n\n";
    $readme .= "IMPORTANT: Store this backup securely. It contains your complete\n";
    $readme .= "database including user credentials and operational data.\n";

    $zip->addFromString('README.txt', $readme);

    $zip->close();
    return true;
}

/**
 * List previous backup files in a directory.
 *
 * @param  string $dir  Directory path
 * @return array  Array of [filename, size, size_formatted, date]
 */
function backup_get_history(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $files = array_merge(
        glob($dir . '/ticketscad-backup-*.zip') ?: [],
        glob($dir . '/ticketscad-backup-*.sql.gz') ?: []
    );
    if (empty($files)) {
        return [];
    }

    $history = [];
    foreach ($files as $file) {
        $size = filesize($file);
        $history[] = [
            'filename' => basename($file),
            'path'     => $file,
            'size'     => $size,
            'size_formatted' => backup_format_size($size),
            'date'     => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    // Sort by date descending
    usort($history, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    return $history;
}

/**
 * Fallback: create a gzip-compressed backup when ZipArchive is not available.
 * Produces a .gz file containing the SQL dump with config JSON appended as SQL comments.
 * The output filename is changed from .zip to .sql.gz.
 */
function backup_create_gzip_fallback(string $sqlPath, string $configJson, string $gzPath): bool
{
    // Change extension from .zip to .sql.gz
    $gzPath = preg_replace('/\.zip$/', '.sql.gz', $gzPath);

    $gz = gzopen($gzPath, 'wb9'); // Level 9 compression
    if (!$gz) {
        throw new RuntimeException('Cannot open gzip file for writing');
    }

    // Write the SQL dump
    $fh = fopen($sqlPath, 'r');
    if (!$fh) {
        gzclose($gz);
        throw new RuntimeException('Cannot read SQL dump file');
    }

    while (!feof($fh)) {
        $chunk = fread($fh, 65536);
        if ($chunk !== false) {
            gzwrite($gz, $chunk);
        }
    }
    fclose($fh);

    // Append config JSON as SQL comments
    gzwrite($gz, "\n\n-- ══════════════════════════════════════════\n");
    gzwrite($gz, "-- CONFIGURATION SNAPSHOT (JSON)\n");
    gzwrite($gz, "-- ══════════════════════════════════════════\n");
    $configLines = explode("\n", $configJson);
    foreach ($configLines as $line) {
        gzwrite($gz, "-- CONFIG: " . $line . "\n");
    }

    gzclose($gz);
    return true;
}

/**
 * Check if ZipArchive is available.
 */
function backup_has_zip(): bool
{
    return class_exists('ZipArchive');
}

/**
 * Get the appropriate backup file extension.
 */
function backup_extension(): string
{
    return class_exists('ZipArchive') ? '.zip' : '.sql.gz';
}

/**
 * Format bytes into human-readable size.
 */
function backup_format_size(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 1) . ' GB';
}
