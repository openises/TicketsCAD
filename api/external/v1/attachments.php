<?php
/**
 * Phase 94 Stage 4h — External API: attachments (file uploads).
 *
 * POST /api/external/v1/attachments.php  (multipart/form-data)
 *   Form fields:
 *     parent_type — incident | facility | responder | major_incident
 *     parent_id   — integer parent entity id
 *     file        — the actual upload (one file per request)
 *
 *   Schema-imposed scope note: the legacy `files` table only carries
 *   ticket_id / responder_id / facility_id / mi_id FK columns — there
 *   is no member_id. Member attachments are therefore out of scope for
 *   v1 even though spec §3 listed members. A follow-up schema slice
 *   would need to add a member_id column before that surfaces here.
 *
 * Authentication via bearer token (_auth.php). Scope:
 *     attachments:write          (always sufficient)  OR
 *     <parent_type>:write        (parent-resource scope — convenience)
 *
 * RBAC: action.upload_files — same gate the internal endpoint uses.
 *
 * Multipart body parsing relies on PHP's normal $_POST + $_FILES
 * population; no json_decode here. JSON bodies on this endpoint
 * return invalid_form_body.
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';
require_once __DIR__ . '/../../../inc/file-write.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    ext_api_error('method_not_allowed', 405, ['allowed' => ['POST']]);
}

// ── Body shape check — must be multipart ──────────────────────
// $_POST being empty AND no $_FILES means the body was either JSON
// (wrong content-type) or the multipart parsing failed (e.g. body
// exceeded post_max_size — Apache silently drops both $_POST and
// $_FILES in that case, leaving the request looking blank).
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (strpos($contentType, 'multipart/form-data') !== 0) {
    ext_api_error('invalid_form_body', 400, [
        'expected' => 'multipart/form-data',
        'got'      => $contentType !== '' ? $contentType : '(none)',
    ]);
}

// Honor multipart $_POST OR query string $_GET (dispatcher injects:
// /incidents/<id>/attachments → $_GET['parent_type']='incident',
// $_GET['parent_id']=<id>). Direct-file POST still works.
$parentType = trim((string) ($_POST['parent_type'] ?? $_GET['parent_type'] ?? ''));
$parentId   = (int) ($_POST['parent_id'] ?? $_GET['parent_id'] ?? 0);

// ── Scope check — attachments:write OR <parent_type>:write ──
// Walk the token's scope list manually so a per-parent scope (e.g.
// incidents:write) satisfies the requirement without forcing a
// dedicated attachments:write grant. Falls back to ext_api_require_scope
// for the canonical error envelope if neither is present.
$tokenScopes = (array) ($GLOBALS['__ext_api_token']['scopes'] ?? []);
$parentScopeMap = [
    'incident'       => 'incidents:write',
    'facility'       => 'facilities:write',
    'responder'      => 'responders:write',
    'major_incident' => 'incidents:write', // major_incident piggy-backs incidents
];
$parentScope = $parentScopeMap[$parentType] ?? null;

$hasScope = false;
foreach ($tokenScopes as $s) {
    if ($s === '*' || $s === 'attachments:write' || $s === 'attachments'
        || $s === 'attachments:*') { $hasScope = true; break; }
    if ($parentScope !== null && ($s === $parentScope || $s === explode(':', $parentScope)[0]
        || $s === explode(':', $parentScope)[0] . ':*')) { $hasScope = true; break; }
}
if (!$hasScope) {
    // Defer to the standard helper so error shape matches every other
    // endpoint (and so token_scopes are surfaced in the response).
    ext_api_require_scope('attachments:write');
}

// ── RBAC check — same as the internal file-upload endpoint ──
if (!rbac_can('action.upload_files')) {
    ext_api_error('forbidden_rbac', 403, ['required' => 'action.upload_files']);
}

// ── Parent type whitelist (mirrors file-write.php's map) ──
if (!isset($parentScopeMap[$parentType])) {
    ext_api_error('invalid_parent_type', 400, [
        'allowed' => array_keys($parentScopeMap),
        'got'     => $parentType,
    ]);
}
if ($parentId <= 0) {
    ext_api_error('invalid_parent_id', 400);
}

// ── File must be present ──
if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    // PHP also empties $_FILES if the upload exceeded post_max_size —
    // call that out explicitly so operators don't chase a phantom bug
    // when they're really hitting Apache's body cap.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = _attachments_parse_size_setting(ini_get('post_max_size') ?: '8M');
    if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
        ext_api_error('upload_too_large_post_max', 413, [
            'message'        => 'Request body exceeded PHP post_max_size — Apache dropped the upload before reaching this handler.',
            'content_length' => $contentLength,
            'post_max_size'  => $postMax,
        ]);
    }
    ext_api_error('no_file_uploaded', 400, ['expected_field' => 'file']);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) ext_api_error('auth_user_missing', 500);

// ── Hand off to the shared write helper ──
try {
    $result = file_attach_to_internal($parentType, $parentId, $_FILES['file'], $userId);
} catch (Exception $e) {
    ext_api_internal_error('internal', $e);
}

if (!empty($result['errors'])) {
    // Distinguish IDOR-safe "parent not found / forbidden" from
    // garden-variety validation errors so integrators can act on the
    // right kind of failure. Constitution rule #27 keeps the response
    // body free of the "which one was it" distinction.
    $firstErr = (string) $result['errors'][0];
    if (strpos($firstErr, 'parent_not_found_or_forbidden') !== false) {
        ext_api_error('not_found', 404);
    }
    if (strpos($firstErr, 'upload_too_large') !== false) {
        ext_api_error('upload_too_large', 413, [
            'errors'    => $result['errors'],
            'max_bytes' => file_write_max_bytes(),
        ]);
    }
    if (strpos($firstErr, 'file_type_not_allowed') !== false
        || strpos($firstErr, 'extension_') !== false) {
        ext_api_error('unsupported_media_type', 415, [
            'errors' => $result['errors'],
            'mime'   => $result['mime'] ?? null,
        ]);
    }
    ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
}

// ── Audit. Stage 5's audit-driven fan-out picks up data|create|file
// and fires attachment.created automatically; we do NOT call
// webhook_fire here.
audit_log(
    'data',
    'create',
    'file',
    (int) $result['id'],
    "External API attached file to {$parentType}#{$parentId}: " . substr((string) ($_FILES['file']['name'] ?? ''), 0, 80),
    [
        'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
        'request_id'       => $GLOBALS['__ext_api_request_id'] ?? null,
        'parent_type'      => $parentType,
        'parent_id'        => $parentId,
        'orig_filename'    => (string) ($_FILES['file']['name'] ?? ''),
        'stored_filename'  => $result['filename'],
        'mime'             => $result['mime'],
        'size_bytes'       => (int) ($_FILES['file']['size'] ?? 0),
        'via_external_api' => true,
    ]
);

ext_api_response([
    'id'          => $result['id'],
    'url'         => $result['url'],
    'filename'    => $result['filename'],
    'mime'        => $result['mime'],
    'parent_type' => $parentType,
    'parent_id'   => $parentId,
], 201);

// ── Local helper — parse PHP's "8M" / "100K" / "1G" size syntax ──
// Standalone so the upload_too_large_post_max diagnostic above can
// run before any other includes are pulled in. Pure function.
function _attachments_parse_size_setting(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $unit = strtolower(substr($val, -1));
    $num  = (int) $val;
    switch ($unit) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
    }
    return $num;
}
