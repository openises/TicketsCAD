<?php
/**
 * NewUI v4.0 API — Database Backup
 *
 * GET  ?action=download  — Generate and stream backup .zip to browser
 * GET  ?action=history   — List previous filesystem backups
 * POST action=filesystem — Save backup to server filesystem
 *
 * Super admin only (level 0).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/backup.php';
require_once __DIR__ . '/../inc/backup_schedule.php';
require_once __DIR__ . '/../inc/audit.php';

ini_set('display_errors', '0');

// Super admin only
if (!is_admin()) {
    json_error('Super admin access required', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// For POST requests, read action from JSON body
$postInput = null;
if ($method === 'POST') {
    $postInput = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($action)) {
        $action = $postInput['action'] ?? '';
    }
}

// ═══════════════════════════════════════════════════════════════
//  Download backup to browser
// ═══════════════════════════════════════════════════════════════
if ($action === 'download' && $method === 'GET') {
    // CSRF via query param (this is a browser navigation, not XHR)
    $token = $_GET['csrf_token'] ?? '';
    if (!csrf_verify($token)) {
        json_error('Invalid CSRF token', 403);
    }

    // Allow long execution
    set_time_limit(600);
    ignore_user_abort(true);

    // Check disk space (need ~2x database size for temp files)
    $tmpDir = sys_get_temp_dir();
    $freeSpace = @disk_free_space($tmpDir);
    if ($freeSpace !== false && $freeSpace < 500 * 1024 * 1024) { // 500 MB minimum
        json_error('Insufficient disk space for backup. Need at least 500 MB free in temp directory.', 507);
    }

    $timestamp = date('Y-m-d_His');
    $ext = backup_extension();
    $sqlFile = $tmpDir . '/ticketscad-backup-' . $timestamp . '.sql';
    $outFile = $tmpDir . '/ticketscad-backup-' . $timestamp . $ext;

    // Cleanup on exit
    register_shutdown_function(function () use ($sqlFile, $outFile) {
        @unlink($sqlFile);
        @unlink($outFile);
    });

    try {
        // Generate SQL dump
        backup_dump_sql($sqlFile);

        // Generate config JSON
        $configJson = backup_export_config();

        // Create archive (zip or gzip fallback)
        backup_create_zip($sqlFile, $configJson, $outFile);

        // The actual output file may have a different extension if fallback was used
        if (!file_exists($outFile)) {
            // Gzip fallback changes .zip to .sql.gz
            $outFile = preg_replace('/\.zip$/', '.sql.gz', $outFile);
            $ext = '.sql.gz';
        }

        // Audit log
        $outSize = filesize($outFile);
        audit_log('system', 'export', 'backup', null,
            'Full database backup downloaded (' . backup_format_size($outSize) . ')',
            ['size' => $outSize, 'filename' => basename($outFile)],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3);

        // Stream to browser
        $contentType = ($ext === '.zip') ? 'application/zip' : 'application/gzip';
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="ticketscad-backup-' . $timestamp . $ext . '"');
        header('Content-Length: ' . $outSize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        readfile($outFile);
        exit;

    } catch (Exception $e) {
        // Cleanup on error. ($zipFile was an undefined variable here — the
        // partially-written archive was left behind in the temp directory on
        // every failed download, which is its own slow disk leak.)
        @unlink($sqlFile);
        @unlink($outFile);
        json_error('Backup failed: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  Save backup to filesystem
// ═══════════════════════════════════════════════════════════════
if ($action === 'filesystem' && $method === 'POST') {
    $input = $postInput ?: [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }

    set_time_limit(600);

    // backup_dir(), not the raw BACKUP_DIR constant: it honours the operator's
    // `backup_dir` setting and the compatibility fallback, so a manual "save to
    // server" lands in the same place as the scheduled runs. GH#56: fall back
    // for an explicitly empty/whitespace path too, not just a missing key --
    // the settings.php field now pre-fills with backup_dir() itself, but a
    // cleared box should mean "use the configured directory", not an error.
    $destDir = trim((string) ($input['path'] ?? ''));
    if ($destDir === '') {
        $destDir = backup_dir();
    }

    // Create directory if needed
    if (!is_dir($destDir)) {
        if (!@mkdir($destDir, 0755, true)) {
            json_error('Cannot create backup directory: ' . $destDir
                . ' — create it yourself, or set a different one in Settings → Backup / Maintenance.');
        }
    }
    // If it ended up inside the web root anyway, put deny rules beside it.
    backup_harden_dir($destDir);

    if (!is_writable($destDir)) {
        json_error('Backup directory is not writable: ' . $destDir);
    }

    // Same disk guard as the scheduler. Writing to the server's own filesystem
    // is exactly the path that can fill the disk, so it may not skip the check
    // the automatic path honours. 507 Insufficient Storage is the honest code.
    $guard = backup_guard($destDir);
    if (!$guard['ok']) {
        json_error('Backup refused — ' . $guard['reason'], 507);
    }

    $timestamp = date('Y-m-d_His');
    $ext = backup_extension();
    $sqlFile = sys_get_temp_dir() . '/ticketscad-backup-' . $timestamp . '.sql';
    $outFile = $destDir . '/ticketscad-backup-' . $timestamp . $ext;

    try {
        backup_dump_sql($sqlFile);
        $configJson = backup_export_config();
        backup_create_zip($sqlFile, $configJson, $outFile);

        @unlink($sqlFile);

        // Handle gzip fallback filename change
        if (!file_exists($outFile)) {
            $outFile = preg_replace('/\.zip$/', '.sql.gz', $outFile);
        }

        $outSize = filesize($outFile);
        audit_log('system', 'export', 'backup', null,
            'Full database backup saved to filesystem (' . backup_format_size($outSize) . ')',
            ['size' => $outSize, 'path' => $outFile],
            defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3);

        json_response([
            'success'  => true,
            'filename' => basename($outFile),
            'path'     => $outFile,
            'size'     => backup_format_size($outSize),
        ]);

    } catch (Exception $e) {
        @unlink($sqlFile);
        json_error('Backup failed: ' . $e->getMessage(), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  Download a previously saved backup file
// ═══════════════════════════════════════════════════════════════
if ($action === 'download_file' && $method === 'GET') {
    $filename = basename($_GET['file'] ?? ''); // basename prevents path traversal
    $dir = $_GET['path'] ?? backup_dir();

    // Accepts both naming schemes: 'ticketscad-backup-<date>' (manual) and
    // 'ticketscad-<stamp>' (scheduler). Scheduled archives previously could not
    // be downloaded at all because the pattern demanded the '-backup-' segment.
    // The character class stays deliberately narrow — digits, dashes and
    // underscores only, so no dots can be smuggled in to build a traversal.
    if (empty($filename) || !preg_match('/^ticketscad-(backup-)?[\d_-]+\.(zip|sql\.gz|gz)$/', $filename)) {
        json_error('Invalid filename', 400);
    }

    // v4.2.3 moved the default backup directory above the web root. An install
    // that has been running for a while still has archives in the old in-webroot
    // location, and those must keep downloading — the move must not make anyone's
    // existing restore points unreachable. Only searched when the caller did NOT
    // name a directory; an explicit ?path= is still honoured exactly as given.
    if (!isset($_GET['path']) && function_exists('backup_dirs_all')) {
        foreach (backup_dirs_all() as $cand) {
            if (is_file(rtrim($cand, '/\\') . DIRECTORY_SEPARATOR . $filename)) {
                $dir = $cand;
                break;
            }
        }
    }

    $filePath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    // Defence in depth: prove the resolved file really sits in the directory we
    // were asked to read from before streaming it.
    $realFile = realpath($filePath);
    $realDir  = realpath(rtrim($dir, '/\\'));
    if ($realFile === false || $realDir === false || strpos($realFile, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        json_error('File not found', 404);
    }

    if (!file_exists($filePath) || !is_readable($filePath)) {
        json_error('File not found', 404);
    }

    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $contentType = ($ext === 'zip') ? 'application/zip' : 'application/gzip';

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    readfile($filePath);
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  List backup history
// ═══════════════════════════════════════════════════════════════
if ($action === 'history' && $method === 'GET') {
    // Default to the CONFIGURED directory, not the compiled-in one. An operator
    // who moved backups elsewhere was previously shown an empty history.
    $dir = $_GET['path'] ?? backup_dir();

    if (isset($_GET['path'])) {
        $history = backup_get_history($dir);
    } else {
        // List EVERY directory this install may have written to, so the v4.2.3
        // move of the default out of the web root does not make an operator's
        // existing restore points disappear from Settings → Backup / Maintenance. Retention
        // still prunes only the active directory.
        $history = [];
        $seen    = [];
        foreach (backup_dirs_all() as $cand) {
            foreach (backup_get_history($cand) as $row) {
                $key = $row['path'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $row['web_served'] = backup_dir_is_web_served($cand);
                $history[] = $row;
            }
        }
        usort($history, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    }

    json_response(['backups' => $history, 'directory' => $dir]);
}

// ═══════════════════════════════════════════════════════════════
//  Automatic-backup status — schedule, storage and free space
// ═══════════════════════════════════════════════════════════════
if ($action === 'status' && $method === 'GET') {
    try {
        json_response(['status' => backup_status()]);
    } catch (Throwable $e) {
        error_log('[backup] status failed: ' . $e->getMessage());
        json_error('Could not read backup status', 500);
    }
}

// ═══════════════════════════════════════════════════════════════
//  Run a scheduled-style backup now (honours the disk guard)
// ═══════════════════════════════════════════════════════════════
if ($action === 'run_now' && $method === 'POST') {
    $input = $postInput ?: [];
    if (!csrf_verify($input['csrf_token'] ?? '')) {
        json_error('Invalid CSRF token', 403);
    }

    set_time_limit(1800);
    ignore_user_abort(true);

    try {
        // NOTE: the guard is deliberately NOT bypassed for a manual run. The
        // whole point is that the disk cannot be filled by backups; a button
        // that ignores the reserve would be a hole straight through it. The
        // response tells the operator exactly which limit stopped them and how
        // to change it, which is more useful than an override.
        $r = backup_run_now();
    } catch (Throwable $e) {
        error_log('[backup] manual run failed: ' . $e->getMessage());
        json_error('Backup failed: ' . $e->getMessage(), 500);
    }

    audit_log('system', 'export', 'backup', null,
        $r['ok'] ? 'Manual backup completed: ' . $r['detail']
                 : 'Manual backup did not complete: ' . $r['detail'],
        ['ok' => $r['ok'], 'skipped' => !empty($r['skipped'])],
        defined('AUDIT_MEDIUM') ? AUDIT_MEDIUM : 3);

    json_response([
        'success' => (bool) $r['ok'],
        'skipped' => !empty($r['skipped']),
        'detail'  => $r['detail'],
        'file'    => !empty($r['path']) ? basename((string) $r['path']) : null,
        'status'  => backup_status(),
    ]);
}

json_error('Unknown action', 400);
