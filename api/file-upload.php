<?php
/**
 * NewUI v4.0 API - File Upload (legacy companion to upload.php)
 *
 * POST /api/file-upload.php  — Upload a file attachment   (CSRF + per-entity access)
 *   Required: file (multipart), entity_type (incident|responder|facility|major_incident),
 *             entity_id, csrf_token
 *   Optional: title, description
 *
 * GET /api/file-upload.php?entity_type=X&entity_id=X — List files for an entity
 * GET /api/file-upload.php?id=X&download=1           — Download a file (per-entity access)
 *
 * DELETE /api/file-upload.php?id=X                   — Delete a file (CSRF + per-entity access)
 *
 * SECURITY (F-003 hardening, 2026-05-04):
 *   - CSRF token verified on POST (form field) and DELETE (?csrf_token= or X-CSRF-Token).
 *   - MIME derived from finfo_file(); $_FILES['type'] is never trusted for storage.
 *   - File extension enforced via allowlist; canonical extension keyed off MIME.
 *   - Per-entity access enforced via user_can_access_entity().
 *   - Download responses set X-Content-Type-Options: nosniff and force a safe MIME.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/file-write.php';

$method = $_SERVER['REQUEST_METHOD'];
$uploadDir = NEWUI_ROOT . '/uploads';

// ── Allowlist: extension → expected MIME(s).
$ALLOWED_EXT_MIME = [
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'gif'  => ['image/gif'],
    'webp' => ['image/webp'],
    'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
    'pdf'  => ['application/pdf'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv'],
    'tsv'  => ['text/tab-separated-values', 'text/plain'],
    'txt'  => ['text/plain'],
    'log'  => ['text/plain'],
    'json' => ['application/json', 'text/plain'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'rtf'  => ['application/rtf', 'text/rtf'],
    'odt'  => ['application/vnd.oasis.opendocument.text'],
    'ods'  => ['application/vnd.oasis.opendocument.spreadsheet'],
    'mp3'  => ['audio/mpeg'],
    'wav'  => ['audio/wav', 'audio/x-wav'],
    'mp4'  => ['video/mp4'],
    'webm' => ['video/webm'],
];
$MIME_TO_EXT = [];
foreach ($ALLOWED_EXT_MIME as $ext => $mimes) {
    foreach ($mimes as $m) {
        if (!isset($MIME_TO_EXT[$m])) {
            $MIME_TO_EXT[$m] = $ext;
        }
    }
}

// Map this endpoint's entity_type values to the access helper's vocabulary.
// 'major_incident' has no allocates row today and falls back to "any auth user".
function _file_upload_access(string $entityType, int $entityId): bool {
    $alias = ($entityType === 'major_incident') ? 'general' : $entityType;
    return user_can_access_entity($alias, $entityId);
}

// Ensure upload directory exists, with .htaccess (defense-in-depth)
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}
$htaccess = $uploadDir . '/.htaccess';
if (!file_exists($htaccess)) {
    @file_put_contents($htaccess, "# Auto-generated\n"
        . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
        . "<FilesMatch \"\\.(php|phar|phtml|pht|phtm|inc|htaccess|html|htm|svg|xml|xsl|vbs|js)$\">\n"
        . "    Require all denied\n</FilesMatch>\nOptions -ExecCGI\n");
}

// ── Helper: read CSRF token from form field, header, or query (DELETE) ──
function _file_upload_csrf_ok(): bool {
    $tok = $_POST['csrf_token'] ?? '';
    if ($tok === '') {
        $tok = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    if ($tok === '') {
        $tok = $_GET['csrf_token'] ?? '';
    }
    if ($tok === '') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $tok = $body['csrf_token'] ?? '';
        }
    }
    return csrf_verify((string) $tok);
}

if ($method === 'GET') {
    // Download file
    if (!empty($_GET['id']) && !empty($_GET['download'])) {
        $id = intval($_GET['id']);
        try {
            $file = db_fetch_one("SELECT * FROM " . db_table('files') . " WHERE id = ?", [$id]);
        } catch (Exception $e) {
            json_error('File not found', 404);
        }
        if (!$file) json_error('File not found', 404);

        // Determine entity type/id from the row (column varies by attach point)
        $entityType = '';
        $entityId   = 0;
        if (!empty($file['ticket_id']))     { $entityType = 'incident';       $entityId = (int) $file['ticket_id']; }
        elseif (!empty($file['responder_id'])) { $entityType = 'responder';   $entityId = (int) $file['responder_id']; }
        elseif (!empty($file['facility_id']))  { $entityType = 'facility';    $entityId = (int) $file['facility_id']; }
        elseif (!empty($file['mi_id']))        { $entityType = 'major_incident'; $entityId = (int) $file['mi_id']; }

        if ($entityType !== '' && !_file_upload_access($entityType, $entityId)) {
            json_error('File not found', 404);
        }

        $path = $uploadDir . '/' . $file['filename'];
        if (!file_exists($path)) json_error('File missing from disk', 404);

        // Force a safe MIME — never echo the stored filetype blindly.
        $storedExt = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $safeMime = isset($ALLOWED_EXT_MIME[$storedExt])
            ? $ALLOWED_EXT_MIME[$storedExt][0]
            : 'application/octet-stream';

        header('Content-Type: ' . $safeMime);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        $disposition = (strpos($safeMime, 'image/') === 0 || $safeMime === 'application/pdf')
            ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition
            . '; filename="' . basename($file['orig_filename']) . '"');
        readfile($path);
        exit;
    }

    // List files for entity
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = intval($_GET['entity_id'] ?? 0);
    if (!$entityType || !$entityId) json_error('entity_type and entity_id required');

    if (!_file_upload_access($entityType, $entityId)) {
        json_response(['files' => []]);
    }

    // Map entity type to column
    $colMap = [
        'incident' => 'ticket_id',
        'responder' => 'responder_id',
        'facility' => 'facility_id',
        'major_incident' => 'mi_id'
    ];
    $col = $colMap[$entityType] ?? null;
    if (!$col) json_error('Invalid entity_type');

    try {
        $rows = db_fetch_all(
            "SELECT id, title, orig_filename, filetype, type, " . $col . " AS entity_id
             FROM " . db_table('files') . " WHERE `{$col}` = ? ORDER BY id DESC",
            [$entityId]
        );
    } catch (Exception $e) {
        $rows = [];
    }
    json_response(['files' => $rows]);
}

if ($method === 'POST') {
    if (!_file_upload_csrf_ok()) {
        json_error('Invalid CSRF token', 403);
    }

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    if (!rbac_can('action.upload_files')) {
        json_error('Insufficient permissions: upload files', 403);
    }

    // Parse request — the helper validates the upload struct itself, but
    // entity_type/entity_id come from form fields (not the upload).
    $entityType = $_POST['entity_type'] ?? '';
    $entityId   = (int) ($_POST['entity_id'] ?? 0);
    $title      = isset($_POST['title']) ? trim((string) $_POST['title']) : null;

    $colMap = [
        'incident'       => 'ticket_id',
        'responder'      => 'responder_id',
        'facility'       => 'facility_id',
        'major_incident' => 'mi_id'
    ];
    if (!isset($colMap[$entityType]) || !$entityId) {
        json_error('entity_type and entity_id required');
    }

    // Delegate the full attach flow (validation, content-sniff, store,
    // metadata insert) to the canonical write helper. _from='internal_ui'
    // distinguishes this from external-API uploads in the audit trail.
    $upload = $_FILES['file'] ?? [];
    $result = file_attach_to_internal(
        $entityType,
        $entityId,
        $upload,
        (int) $current_user_id,
        'internal_ui',
        $title
    );

    if (!empty($result['errors'])) {
        $msg = (string) $result['errors'][0];
        // Map helper error tokens back to the messages this endpoint has
        // returned historically — keeps the dispatcher UI's error toasts
        // looking identical.
        $statusMap = [
            'unknown_parent_type'              => 400,
            'invalid_parent_id'                => 400,
            'no_file_uploaded'                 => 400,
            'not_a_real_upload'                => 400,
            'upload_too_large'                 => 400,
            'upload_too_large_phpini'          => 400,
            'upload_too_large_form'            => 400,
            'upload_partial'                   => 400,
            'no_tmp_dir'                       => 400,
            'write_failed'                     => 400,
            'extension_blocked'                => 400,
            'file_type_not_allowed'            => 400,
            'extension_content_mismatch'       => 400,
            'extension_not_allowed'            => 400,
            'parent_not_found_or_forbidden'    => 403,
            'failed_to_store_file'             => 500,
            'db_insert_failed'                 => 500,
        ];
        $key = strstr($msg, ':', true) ?: $msg;
        $status = $statusMap[$key] ?? 400;
        json_error('Upload error: ' . $msg, $status);
    }

    $newId        = (int) $result['id'];
    $storedName   = (string) $result['filename'];
    $detectedMime = (string) $result['mime'];
    $origName     = (string) ($upload['name'] ?? '');

    // Canonical webhook-eligible event: 'data|create|file' → attachment.created
    audit_log('data', 'create', 'file', $newId,
        "Uploaded file '{$origName}' for {$entityType} #{$entityId}",
        [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'mime'        => $detectedMime,
            'source'      => 'internal_ui',
        ]);

    json_response(['success' => true, 'id' => $newId, 'filename' => $storedName, 'mime' => $detectedMime]);
}

if ($method === 'DELETE') {
    if (!_file_upload_csrf_ok()) {
        json_error('Invalid CSRF token', 403);
    }

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    if (!rbac_can('action.upload_files')) {
        json_error('Insufficient permissions: upload files', 403);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Missing id');

    $result = file_delete_internal($id, (int) $current_user_id);
    if (!empty($result['errors'])) {
        $first = (string) $result['errors'][0];
        $key = strstr($first, ':', true) ?: $first;
        if ($key === 'not_found' || $key === 'invalid_id') {
            json_error('File not found', 404);
        }
        json_error('Delete failed: ' . $first, 500);
    }

    // Canonical webhook-eligible event: 'data|delete|file' → attachment.deleted
    audit_log('data', 'delete', 'file', $id,
        "Deleted file '" . ($result['orig_filename'] ?? '') . "'",
        [
            'entity_type' => $result['parent_type'] ?? '',
            'entity_id'   => $result['parent_id'] ?? 0,
            'source'      => 'internal_ui',
        ]);

    json_response(['success' => true]);
}

json_error('Method not allowed', 405);
