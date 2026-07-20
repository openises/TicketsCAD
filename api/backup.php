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
        // Cleanup on error
        @unlink($sqlFile);
        @unlink($zipFile);
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

    $destDir = trim($input['path'] ?? BACKUP_DIR);

    // Validate path
    if (empty($destDir)) {
        json_error('Backup path is required');
    }

    // Create directory if needed
    if (!is_dir($destDir)) {
        if (!@mkdir($destDir, 0755, true)) {
            json_error('Cannot create backup directory: ' . $destDir);
        }
    }

    if (!is_writable($destDir)) {
        json_error('Backup directory is not writable: ' . $destDir);
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
    $dir = $_GET['path'] ?? BACKUP_DIR;

    if (empty($filename) || !preg_match('/^ticketscad-backup-[\d_-]+\.(zip|sql\.gz)$/', $filename)) {
        json_error('Invalid filename', 400);
    }

    $filePath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
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
    $dir = $_GET['path'] ?? BACKUP_DIR;
    $history = backup_get_history($dir);
    json_response(['backups' => $history, 'directory' => $dir]);
}

json_error('Unknown action', 400);
