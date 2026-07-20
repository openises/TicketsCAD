<?php
/**
 * Phase 94 Stage 4h — File attachment write helpers.
 *
 * Extracted from api/file-upload.php so both the internal CSRF-checked
 * endpoint and the new external token-auth endpoint share the same write
 * path. Caller is responsible for CSRF/bearer auth + RBAC; this file
 * just validates the upload and persists it.
 *
 * The legacy api/file-upload.php still owns delete + download today —
 * this helper covers the attach (POST) path for v1 of the external API.
 *
 * Schema note (verified 2026-06-28 against training):
 *   files.* has columns ticket_id, responder_id, facility_id, mi_id —
 *   NO member_id column. So $parentType is limited to the set the
 *   schema can hold. Member attachments are out of scope for v1 even
 *   though the spec §3 sketch listed members alongside incidents and
 *   facilities — that gap belongs to a future schema migration.
 */

declare(strict_types=1);

if (!defined('NEWUI_ROOT')) {
    define('NEWUI_ROOT', dirname(__DIR__));
}

/**
 * MIME / extension allowlist — mirrors api/file-upload.php exactly so
 * both endpoints stay in lockstep. If the legacy list grows, mirror
 * the change here.
 *
 * @return array<string, array<int, string>> ext => list of acceptable MIME types
 */
function file_write_allowed_ext_mime(): array {
    return [
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
}

/**
 * Reverse map: MIME → canonical extension. Used to rename incoming
 * uploads so the stored filename's extension always matches the
 * content-sniffed type — never trust the user-supplied extension.
 *
 * @return array<string, string>
 */
function file_write_mime_to_ext(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (file_write_allowed_ext_mime() as $ext => $mimes) {
        foreach ($mimes as $m) {
            if (!isset($cache[$m])) $cache[$m] = $ext;
        }
    }
    return $cache;
}

/**
 * Per-parent-type → files-table-column mapping. Mirrors
 * api/file-upload.php's $colMap. Only parent types whose foreign-key
 * column actually exists on the legacy files table are accepted.
 *
 * @return array<string, string>
 */
function file_write_parent_column_map(): array {
    return [
        'incident'       => 'ticket_id',
        'responder'      => 'responder_id',
        'facility'       => 'facility_id',
        'major_incident' => 'mi_id',
    ];
}

/**
 * Read the configured upload size limit. Default 10 MB if the setting
 * row is missing. Hard cap of 100 MB regardless of setting — keeps a
 * mis-configured row from opening the door to a DoS via 4 GB uploads
 * pushed past Apache's defaults.
 */
function file_write_max_bytes(): int {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['external_api_max_upload_bytes']
        );
    } catch (Exception $e) {
        $v = null;
    }
    $configured = $v !== null ? (int) $v : 10485760; // 10 MB default
    if ($configured <= 0) $configured = 10485760;
    return min($configured, 100 * 1024 * 1024);
}

/**
 * Verify the caller can access the parent entity. Mirrors
 * api/file-upload.php's _file_upload_access(). Uses the shared
 * inc/access.php helper so incident/responder/facility honour the
 * allocates-group filter; major_incident falls back to the
 * authenticated-org-wide "general" check.
 */
function file_write_check_access(string $parentType, int $parentId): bool {
    require_once __DIR__ . '/access.php';
    $alias = ($parentType === 'major_incident') ? 'general' : $parentType;
    return user_can_access_entity($alias, $parentId);
}

/**
 * Attach an uploaded file to a parent entity. Persists to the uploads
 * directory + inserts the metadata row in the files table.
 *
 * Caller responsibilities (NOT handled here):
 *   - CSRF or bearer auth
 *   - RBAC check (action.upload_files)
 *   - $_SESSION['user_id'] populated for created-by attribution
 *
 * This helper DOES enforce:
 *   - Parent-type whitelist (only known columns on the files table)
 *   - Parent entity exists + caller can access it (IDOR check)
 *   - Upload error code (PHP UPLOAD_ERR_OK)
 *   - is_uploaded_file() — rejects forged $_FILES entries
 *   - Size cap from external_api_max_upload_bytes setting
 *   - MIME content-sniff via finfo + allowlist match
 *   - User-supplied extension cross-checked against detected MIME
 *
 * @param string $parentType One of: incident, responder, facility, major_incident
 * @param int    $parentId   Parent entity id (must already exist)
 * @param array  $upload     $_FILES['file']-shaped: name, tmp_name, size, type, error
 * @param int    $userId     Acting user id (for audit attribution upstream)
 * @param string $source     Source channel for files._from column. Defaults
 *                           to 'external_api' (the helper's first caller).
 *                           Internal CSRF-checked uploads pass 'internal_ui'
 *                           so the audit trail distinguishes the two paths.
 * @param string|null $title Optional override for files.title. Defaults to
 *                           the original upload filename when omitted.
 * @return array {
 *   'id'      => int    Newly inserted files.id on success (0 on error)
 *   'url'     => string Server-relative URL to download the file
 *   'filename'=> string Stored filename (server-side, opaque to client)
 *   'mime'    => string Detected MIME type
 *   'errors'  => string[] Validation/storage errors (empty array on success)
 * }
 */
function file_attach_to_internal(string $parentType, int $parentId, array $upload, int $userId, string $source = 'external_api', ?string $title = null): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $errors = [];

    // ── 1. Parent-type whitelist ──
    $colMap = file_write_parent_column_map();
    if (!isset($colMap[$parentType])) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['unknown_parent_type: ' . $parentType]];
    }
    $parentCol = $colMap[$parentType];

    // ── 2. Parent id sanity ──
    if ($parentId <= 0) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['invalid_parent_id']];
    }

    // ── 3. Upload struct sanity ──
    if (empty($upload['tmp_name']) || empty($upload['name'])) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['no_file_uploaded']];
    }
    if (!isset($upload['error']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
        $codeMap = [
            UPLOAD_ERR_INI_SIZE   => 'upload_too_large_phpini',
            UPLOAD_ERR_FORM_SIZE  => 'upload_too_large_form',
            UPLOAD_ERR_PARTIAL    => 'upload_partial',
            UPLOAD_ERR_NO_FILE    => 'no_file_uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'no_tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'write_failed',
            UPLOAD_ERR_EXTENSION  => 'extension_blocked',
        ];
        $code = $codeMap[(int) $upload['error']] ?? ('upload_error_' . (int) $upload['error']);
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => [$code]];
    }
    if (!is_uploaded_file($upload['tmp_name'])) {
        // Critical IDOR/forgery check — rejects callers who fabricate a
        // $_FILES entry pointing at an arbitrary server-side path.
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['not_a_real_upload']];
    }

    // ── 4. Size limit (settings-driven, hard cap 100 MB) ──
    $maxBytes = file_write_max_bytes();
    if ((int) $upload['size'] > $maxBytes) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['upload_too_large', 'max_bytes=' . $maxBytes]];
    }

    // ── 5. Parent existence + access check ──
    if (!file_write_check_access($parentType, $parentId)) {
        // Same IDOR-safe response as the read path: do NOT distinguish
        // "doesn't exist" from "you can't see it" (constitution rule 27).
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => '',
                'errors' => ['parent_not_found_or_forbidden']];
    }

    // ── 6. MIME content-sniff (never trust $upload['type']) ──
    $detectedMime = null;
    if (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = @finfo_file($finfo, $upload['tmp_name']);
            @finfo_close($finfo);
        }
    }
    if (!$detectedMime) {
        $detectedMime = @mime_content_type($upload['tmp_name']) ?: '';
    }
    $detectedMime = strtolower(trim((string) $detectedMime));
    $mimeToExt = file_write_mime_to_ext();
    if ($detectedMime === '' || !isset($mimeToExt[$detectedMime])) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => $detectedMime,
                'errors' => ['file_type_not_allowed: ' . ($detectedMime ?: 'unknown')]];
    }
    $canonicalExt = $mimeToExt[$detectedMime];

    // ── 7. Cross-check user-supplied extension ──
    $allowedExtMime = file_write_allowed_ext_mime();
    $userExt = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
    if ($userExt !== '' && isset($allowedExtMime[$userExt])) {
        if (!in_array($detectedMime, $allowedExtMime[$userExt], true)) {
            return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => $detectedMime,
                    'errors' => ["extension_content_mismatch: .{$userExt} vs {$detectedMime}"]];
        }
    } elseif ($userExt !== '' && !isset($allowedExtMime[$userExt])) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => $detectedMime,
                'errors' => ["extension_not_allowed: .{$userExt}"]];
    }

    // ── 8. Persist to disk ──
    $uploadDir = NEWUI_ROOT . '/uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    // Defense-in-depth: drop a no-exec .htaccess if missing (matches
    // api/file-upload.php).
    $htaccess = $uploadDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "# Auto-generated by file-write.php\n"
            . "<IfModule mod_php.c>\n    php_flag engine off\n</IfModule>\n"
            . "<FilesMatch \"\\.(php|phar|phtml|pht|phtm|inc|htaccess|html|htm|svg|xml|xsl|vbs|js)$\">\n"
            . "    Require all denied\n</FilesMatch>\nOptions -ExecCGI\n");
    }
    $storedName = 'file_' . bin2hex(random_bytes(8)) . '.' . $canonicalExt;
    $destPath = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($upload['tmp_name'], $destPath)) {
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => $detectedMime,
                'errors' => ['failed_to_store_file']];
    }

    // ── 9. Insert metadata row ──
    if ($title === null || $title === '') {
        $title = trim((string) $upload['name']);
    }
    if ($title === '') $title = $storedName;
    if (strlen($title) > 128) $title = substr($title, 0, 128);
    // Legacy schema note: files.type is int(2) NULL DEFAULT 0 — it was
    // intended as a legacy enum class that's never been used. The legacy
    // api/file-upload.php INSERTs the extension string ('csv') into it,
    // which only succeeds because the legacy install runs MySQL in non-
    // strict mode. On a strict-mode install (training), that fails with
    // 1366 "Incorrect integer value". Pass NULL instead — the canonical
    // extension lives in `filetype` (varchar) anyway.
    // _from values: 'internal_ui' (CSRF-checked UI uploads),
    // 'external_api' (bearer-token uploads). Capped at 16 chars to fit
    // the legacy varchar(16) column.
    $source = substr($source, 0, 16);
    try {
        db_query(
            "INSERT INTO `{$prefix}files`
                (`title`, `filename`, `orig_filename`, `{$parentCol}`, `type`, `filetype`, `_by`, `_on`, `_from`)
             VALUES (?, ?, ?, ?, NULL, ?, ?, NOW(), ?)",
            [
                $title,
                $storedName,
                $upload['name'],
                $parentId,
                $detectedMime,
                $userId,
                $source,
            ]
        );
        $newId = (int) db_insert_id();
    } catch (Exception $e) {
        // Roll back the on-disk write if the metadata insert failed,
        // otherwise we'd accumulate orphan blobs.
        @unlink($destPath);
        return ['id' => 0, 'url' => '', 'filename' => '', 'mime' => $detectedMime,
                'errors' => ['db_insert_failed: ' . $e->getMessage()]];
    }

    return [
        'id'       => $newId,
        'url'      => '/api/file-upload.php?id=' . $newId . '&download=1',
        'filename' => $storedName,
        'mime'     => $detectedMime,
        'errors'   => [],
    ];
}

/**
 * Delete a file attachment: remove the blob from disk + drop the
 * metadata row. Mirrors the DELETE branch of api/file-upload.php with
 * the same per-entity access check (incident/responder/facility/major_
 * incident → user_can_access_entity).
 *
 * Caller responsibilities (NOT handled here):
 *   - CSRF or bearer auth
 *   - RBAC check (action.upload_files or equivalent)
 *
 * This helper DOES enforce:
 *   - File row exists (returns errors=['not_found'] if not)
 *   - Per-entity access check via file_write_check_access() — same
 *     IDOR-safe response shape as the read path
 *
 * On success returns:
 *   ['deleted' => true, 'orig_filename' => string, 'parent_type' => string,
 *    'parent_id' => int, 'errors' => []]
 * — the caller uses orig_filename + parent for the audit message.
 *
 * Phase 94 Stage 4j extraction (2026-06-28).
 */
function file_delete_internal(int $fileId, int $userId): array {
    if ($fileId <= 0) {
        return ['deleted' => false, 'errors' => ['invalid_id']];
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $file = db_fetch_one(
            "SELECT * FROM `{$prefix}files` WHERE id = ?",
            [$fileId]
        );
    } catch (Exception $e) {
        return ['deleted' => false, 'errors' => ['db_query_failed: ' . $e->getMessage()]];
    }
    if (!$file) {
        return ['deleted' => false, 'errors' => ['not_found']];
    }

    // Resolve the parent entity from whichever foreign-key column is
    // populated (mirrors api/file-upload.php's GET-download branch).
    $parentType = '';
    $parentId   = 0;
    if (!empty($file['ticket_id']))         { $parentType = 'incident';       $parentId = (int) $file['ticket_id']; }
    elseif (!empty($file['responder_id']))  { $parentType = 'responder';      $parentId = (int) $file['responder_id']; }
    elseif (!empty($file['facility_id']))   { $parentType = 'facility';       $parentId = (int) $file['facility_id']; }
    elseif (!empty($file['mi_id']))         { $parentType = 'major_incident'; $parentId = (int) $file['mi_id']; }

    if ($parentType !== '' && !file_write_check_access($parentType, $parentId)) {
        // IDOR-safe: return same 'not_found' shape as missing rows
        return ['deleted' => false, 'errors' => ['not_found']];
    }

    // Best-effort unlink — never block the DB delete if the blob is
    // already gone (e.g. an admin pruned the uploads dir manually).
    $uploadDir = NEWUI_ROOT . '/uploads';
    $path = $uploadDir . '/' . $file['filename'];
    if (file_exists($path)) {
        @unlink($path);
    }

    try {
        db_query("DELETE FROM `{$prefix}files` WHERE id = ?", [$fileId]);
    } catch (Exception $e) {
        return ['deleted' => false, 'errors' => ['db_delete_failed: ' . $e->getMessage()]];
    }

    return [
        'deleted'       => true,
        'orig_filename' => (string) ($file['orig_filename'] ?? ''),
        'parent_type'   => $parentType,
        'parent_id'     => $parentId,
        'errors'        => [],
    ];
}
