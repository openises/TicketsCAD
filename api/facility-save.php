<?php
/**
 * NewUI v4.0 API - Facility Save
 *
 * POST /api/facility-save.php
 *   Create or update a facility.
 *   Expects JSON body with facility fields.
 *   If `id` is present and > 0, updates existing; otherwise inserts new.
 *   Returns the saved facility data on success.
 *
 * DELETE /api/facility-save.php?id=123
 *   Soft-delete (set hide=1) a facility.
 *
 * 2026-06-28 — Refactored to delegate to inc/facility-write.php::
 * facility_upsert_internal() + facility_soft_delete_internal(). Auth/
 * CSRF/RBAC/IDOR checks stay here; the helpers own the SQL. Audit
 * category normalized to canonical ('asset','create|update|delete',
 * 'facility') so webhooks fire from the same allowlist entry as the
 * external API path (Phase 94 reliability gap).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/facility-write.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── DELETE: soft-delete a facility ──
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // CSRF on destructive action — accept either ?csrf_token= or X-CSRF-Token
    $csrfTok = $_GET['csrf_token']
        ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify((string) $csrfTok)) {
        json_error('Invalid CSRF token', 403);
    }

    // RBAC enforcement (specs/rbac-enforcement-2026-06).
    if (!rbac_can('action.manage_facilities')) {
        json_error('Insufficient permissions: manage facilities', 403);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Invalid facility ID');
    }

    // IDOR check before any DB read or write — hides existence from
    // callers who shouldn't see this facility.
    if (!user_can_access_entity('facility', $id)) {
        json_error('Facility not found', 404);
    }
    // Phase 99j-6b — org-scope gate.
    require_once __DIR__ . '/../inc/org-scope.php';
    if (!org_can_see_row('facilities', $id)) {
        json_error('Facility not found', 404);
    }

    try {
        $result = facility_soft_delete_internal($id, (int) $current_user_id);
    } catch (Exception $e) {
        json_error('Failed to delete facility: ' . $e->getMessage(), 500);
    }

    if (!empty($result['errors'])) {
        $errs = $result['errors'];
        if (in_array('not_found', $errs, true)) {
            json_error('Facility not found', 404);
        }
        if (in_array('invalid_id', $errs, true)) {
            json_error('Invalid facility ID');
        }
        json_error('Delete failed: ' . implode(', ', $errs), 422);
    }

    audit_log('asset', 'delete', 'facility', $id,
        "Soft-deleted facility '" . ($result['name'] ?? '#' . $id) . "'");
    json_response(['success' => true, 'message' => 'Facility deleted']);
}

// ── POST: create or update ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST or DELETE required', 405);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

// RBAC enforcement (specs/rbac-enforcement-2026-06).
if (!rbac_can('action.manage_facilities')) {
    json_error('Insufficient permissions: manage facilities', 403);
}

$id = (int) ($input['id'] ?? 0);

// IDOR check on UPDATE — users editing an existing facility must
// already have access to it. New-facility creation is gated by RBAC
// at the screen level.
if ($id > 0 && !user_can_access_entity('facility', $id)) {
    json_error('Facility not found', 404);
}

// Phase 99j-6b — org-scope gate on UPDATE; default org_id on CREATE.
require_once __DIR__ . '/../inc/org-scope.php';
if ($id > 0 && !org_can_see_row('facilities', $id)) {
    json_error('Facility not found', 404);
}
if ($id === 0 && !isset($input['org_id'])) {
    try {
        $homeOrg = org_user_home_id((int) $current_user_id);
        if ($homeOrg > 0) $input['org_id'] = $homeOrg;
    } catch (Exception $e) { /* leave NULL */ }
}

try {
    $result = facility_upsert_internal($input, (int) $current_user_id);
} catch (Exception $e) {
    json_error('Failed to save facility: ' . $e->getMessage(), 500);
}

if (!empty($result['errors'])) {
    $errs = $result['errors'];
    if (in_array('not_found', $errs, true)) {
        json_error('Facility not found', 404);
    }
    // Field-validation errors (name/description required) — keep the
    // 422 + errors[] shape the dispatcher UI expects.
    $userFacing = [];
    foreach ($errs as $e) {
        if ($e === 'name is required')        $userFacing[] = 'Name is required';
        elseif ($e === 'description is required') $userFacing[] = 'Description is required';
        else                                  $userFacing[] = $e;
    }
    json_response(['errors' => $userFacing], 422);
}

$savedId = (int) $result['id'];
$name    = trim((string) ($input['name'] ?? ''));

if (!empty($result['is_new'])) {
    audit_log('asset', 'create', 'facility', $savedId, "Created facility '{$name}'");
    json_response(['success' => true, 'id' => $savedId, 'message' => 'Facility created'], 201);
}

audit_log('asset', 'update', 'facility', $savedId, "Updated facility '{$name}'");
json_response(['success' => true, 'id' => $savedId, 'message' => 'Facility updated']);
