<?php
/**
 * NewUI v4.0 API - Responder Delete
 *
 * POST /api/responder-delete.php
 *   Delete a responder by ID.
 *   JSON body: { id, csrf_token }
 *
 * 2026-06-28 — Refactored to delegate to inc/responder-write.php::
 * responder_soft_delete_internal(). Auth/CSRF/admin-only check stay
 * here; the helper owns the active-assignment guard, soft-vs-hard
 * delete fallback, and allocates cleanup. Audit category normalized
 * to canonical ('asset','delete','responder') so webhooks fire from
 * the same allowlist entry as the external API path (Phase 94
 * reliability gap).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/responder-write.php';

ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

// Only admins can delete
if (!is_admin()) {
    json_error('Insufficient permissions. Only administrators can delete units.', 403);
}

$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    json_error('Invalid responder ID');
}

// Phase 99j-6b — org-scope gate. Even an admin must be allowed to
// see the responder by org-scope before deleting it.
require_once __DIR__ . '/../inc/org-scope.php';
if (!org_can_see_row('responder', $id)) {
    json_error('Responder not found', 404);
}

try {
    $result = responder_soft_delete_internal($id, (int) $current_user_id);
} catch (Exception $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!empty($result['errors'])) {
    $errs = $result['errors'];
    if (in_array('not_found', $errs, true)) {
        json_error('Responder not found', 404);
    }
    if (in_array('has_active_assignments', $errs, true)) {
        $n = $result['active_count'] ?? 1;
        json_error('Cannot delete: unit has ' . $n . ' active assignment(s). Clear them first.');
    }
    if (in_array('invalid_id', $errs, true)) {
        json_error('Invalid responder ID');
    }
    json_error('Delete failed: ' . implode(', ', $errs), 422);
}

$softDeleted = !empty($result['soft']);
$name        = $result['name'] ?? ('#' . $id);

audit_log('asset', 'delete', 'responder', $id,
    ($softDeleted ? 'Soft-deleted' : 'Deleted') . " responder '" . $name . "'",
    ['soft' => $softDeleted]
);

json_response(['message' => 'Unit deleted successfully']);
