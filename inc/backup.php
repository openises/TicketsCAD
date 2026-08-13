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

// ── Where backup archives live ───────────────────────────────────────────────
//
// Until v4.2.3 this was NEWUI_ROOT . '/backups' — INSIDE the served tree. The
// documented install points the web root at the application root, so on
// 2026-07-30 an unauthenticated `GET /backups/<archive>.zip` returned a 110 MB
// database dump from a live, internet-facing install, and `GET /backups/` gave
// a browsable index of every archive. A backup is the single most concentrated
// copy of everything in the system; it must not be one URL away.
//
// The fence (.htaccess / nginx / IIS rules) ships too, but no single fence
// covers every install — nginx ignores .htaccess entirely and Apache ignores it
// under AllowOverride None. So the files moved rather than relying on it.
//
// ── …and then v4.2.3's own fix re-exposed them on Windows (2026-08-02) ──────
//
// v4.2.3 wrote `dirname(NEWUI_ROOT) . '/backups'` unconditionally. That is
// "above the web root" only on a POSIX-shaped layout (/var/www/newui →
// /var/www). @rjonesbsink reported the Windows case: a stock IIS install lives
// at C:\inetpub\wwwroot\<app>, so dirname() gives **C:\inetpub\wwwroot** — the
// physical path of Default Web Site, bound to *:80. The "safe" destination was
// the document root of a DIFFERENT site, on a different port, with none of this
// application's deny rules anywhere near it, and the health check reported OK
// because it only ever probes THIS install's own base URL. A complete database
// archive was one `http://<host>/backups/<file>.zip` away — more exposed than
// before the fix, and now with a green tick beside it.
//
// C:\inetpub\wwwroot is not an exotic layout; it is where the IIS default site
// lives on every Windows machine. The same shape bites XAMPP
// (C:\xampp\htdocs\newui → C:\xampp\htdocs, the DocumentRoot).
//
// So the default is platform-aware, and — deliberately — UNCONDITIONAL per
// platform rather than "sibling unless it looks unsafe". BACKUP_DIR is a
// define(); a constant whose value depends on mutable filesystem state (does
// C:\inetpub\wwwroot\web.config exist today?) would silently relocate an
// install's archives when something unrelated changed. One rule per platform,
// and an operator who wants a different directory sets `backup_dir` in
// Settings → Backup / Maintenance, which overrides all of this.
//
//   POSIX    dirname(NEWUI_ROOT)/backups        (unchanged — correct there)
//   Windows  %ProgramData%\TicketsCAD\backups   (never a site root on any
//                                                stock configuration, and not
//                                                specific to IIS, so it is also
//                                                right for XAMPP and nginx)
//
// C:\inetpub\backups — @rjonesbsink's own suggestion — is equally safe on an
// IIS box and is a perfectly good value for `backup_dir`; it is not the default
// only because it is meaningless on a machine that has no IIS.
//
// TWO historical locations are kept so nothing an install already wrote is
// orphaned. Both stay listed and downloadable in Settings → Backup / Maintenance, and pruning
// never touches either. Nothing is moved automatically — that is the operator's
// decision, and the Status page tells them it needs making:
//
//   BACKUP_DIR_LEGACY          pre-4.2.3, inside the application tree
//   BACKUP_DIR_LEGACY_SIBLING  the 4.2.3 default (identical to BACKUP_DIR on
//                              POSIX; on Windows this is the wwwroot directory
//                              above, and archives found there are reported
//                              CRITICAL with the reason spelled out)
//
// See docs/WEB-SERVER-HARDENING.md and
// docs/security/advisory-2026-07-30-exposed-directories.md.
//
// ── …and then the same assumption turned up a THIRD time (2026-08-03) ───────
//
// FE_KEYS_DIR — the RSA private key and the 2FA key — was `NEWUI_ROOT .
// '/../keys'`, chosen for the same reason and wrong on Windows in the same way
// (GHSA-3jmh-c6f6-64jc). So the three helpers that answer "is this directory
// published, and can we fence it?" now live in inc/served-dir.php and are
// shared. The backup_* names below are kept as the callers' vocabulary; they
// delegate, so there is one implementation to be right.

require_once __DIR__ . '/served-dir.php';

/**
 * The default backup directory for an application root, per platform.
 *
 * Exposed as a function, and taking the platform explicitly, for two reasons:
 * tests can assert BOTH platforms' answers from one machine, and the Docker
 * checks (a Linux image, whatever the developer's laptop is) can ask for the
 * POSIX answer rather than hard-coding a literal that would drift.
 *
 * @param string    $appRoot  The application root (NEWUI_ROOT).
 * @param bool|null $windows  NULL = detect from this machine.
 */
function backup_default_dir_for(string $appRoot, ?bool $windows = null): string
{
    if ($windows === null) {
        $windows = (DIRECTORY_SEPARATOR === '\\');
    }
    if (!$windows) {
        return dirname($appRoot) . '/backups';
    }

    // %ProgramData% is set by Windows itself on every install and is inherited
    // by IIS worker processes and by the CLI alike, so the web UI and
    // tools/backup_run.php resolve the same directory. Its fallbacks (for a
    // scrubbed environment) are in served_dir_program_data(), which is also
    // what the encryption keys use, so the two cannot drift apart.
    return served_dir_program_data() . '\\TicketsCAD\\backups';
}

define('BACKUP_DIR', backup_default_dir_for(NEWUI_ROOT));
define('BACKUP_DIR_LEGACY', NEWUI_ROOT . '/backups');
define('BACKUP_DIR_LEGACY_SIBLING', dirname(NEWUI_ROOT) . '/backups');

/**
 * Is a path inside the served application tree (and therefore potentially
 * reachable over HTTP)?
 *
 * The implementation is served_dir_is_in_app_tree() in inc/served-dir.php —
 * shared with the encryption-key directory, which had the identical defect
 * (GHSA-3jmh-c6f6-64jc). This name is kept because it is the one api/backup.php
 * and the tests speak.
 */
function backup_dir_is_web_served(string $dir): bool
{
    return served_dir_is_in_app_tree($dir);
}

/**
 * Could this backup directory be published over HTTP by some web site on this
 * machine? Graded verdict, never a bare boolean — see served_dir_exposure() in
 * inc/served-dir.php for what it can and cannot know, which is the whole point
 * of it.
 */
function backup_dir_exposure(string $dir): array
{
    return served_dir_exposure($dir);
}

/**
 * If a backup directory is — or may be — published by a web server, drop deny
 * rules beside the archives so at least Apache and IIS refuse to serve them.
 *
 * Widened 2026-08-02: the trigger used to be "inside OUR tree", which is
 * precisely the case that missed C:\inetpub\wwwroot\backups. It now fires on
 * any directory served_dir_exposure() calls served OR suspect, which is the
 * whole point — the archives that most need a fence are the ones sitting in
 * somebody else's document root.
 *
 * Unlike the keys directory, this does NOT fence unconditionally: a deny file
 * in a backup folder no server can see is one more file an operator has to
 * reason about, and the Status page reports the exposure either way.
 */
function backup_harden_dir(string $dir): void
{
    served_dir_harden($dir, 'Database backups', false);
}

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
    fwrite($fh, "-- TicketsCAD Version: " . newui_version() . "\n");
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

        // Get column metadata for BLOB detection. GENERATED columns (VIRTUAL or
        // STORED -- e.g. user_roles.scope_key, teams.name, member.phone) are
        // excluded: MySQL computes them and refuses an explicit INSERT value,
        // and an INVISIBLE generated column additionally vanishes from a bare
        // `SELECT *` while still being named by SHOW COLUMNS -- a column-count
        // mismatch that made every dump containing one entirely unrestorable
        // (SQLSTATE 21S01 "Column count doesn't match value count", or 3105 for
        // a visible generated column). The SELECT below now names columns
        // explicitly -- the same list used for the INSERT's column list -- so
        // there is no reliance on `SELECT *`'s implicit visibility behaviour,
        // which also differs between MySQL and MariaDB. Reported by
        // @rjonesbsink, GitHub #53, with the exact reproduction and table list.
        $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = [];
        $blobCols = [];
        foreach ($columns as $col) {
            if (stripos($col['Extra'] ?? '', 'GENERATED') !== false) {
                continue;
            }
            $idx = count($colNames);
            $colNames[] = $col['Field'];
            $type = strtolower($col['Type']);
            if (strpos($type, 'blob') !== false || strpos($type, 'binary') !== false) {
                $blobCols[$idx] = true;
            }
        }
        if (empty($colNames)) {
            fwrite($fh, "-- (no storable columns -- every column is generated)\n\n");
            continue;
        }

        $colList = '`' . implode('`, `', $colNames) . '`';
        fwrite($fh, "-- Dumping data for `{$table}` ({$count} rows)\n\n");

        // Disable keys for MyISAM performance
        fwrite($fh, "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n");

        // Stream rows with unbuffered query.
        // SQL injection: $table validated as [A-Za-z0-9_$]+ above (Sonar S2077);
        // $colList is built only from names SHOW COLUMNS returned for this table.
        $dataStmt = $unbuffered->query("SELECT {$colList} FROM `{$table}`"); // NOSONAR

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
            'version'     => newui_version(),
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
    $readme .= "Version: " . newui_version() . "\n\n";
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

    // Match BOTH naming schemes. Manual backups (api/backup.php) are written as
    // 'ticketscad-backup-<date>'; the scheduler (backup_run_now) writes
    // 'ticketscad-<stamp>'. Globbing only the former meant every automatic
    // backup was invisible in Settings → Backup / Maintenance → Backup History — the operator could
    // not see the copies the product was making on their behalf, which is half
    // of "do I actually have a backup?".
    $files = glob(rtrim($dir, '/\\') . '/ticketscad-*.{zip,gz}', GLOB_BRACE) ?: [];
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
