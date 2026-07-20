<?php
/**
 * NewUI v4.0 API - Responder Save
 *
 * POST /api/responder-save.php
 *   Create or update a responder.
 *   JSON body with responder fields. If `id` is present, UPDATE; if not, INSERT.
 *   Validates required fields (name, description).
 *   Returns the saved responder data.
 *
 * 2026-06-28 — Refactored to delegate to inc/responder-write.php::
 * responder_upsert_internal(). Auth/CSRF/RBAC stay here; the helper
 * owns the SQL + tracking-provider mapping + geofence check (Phase
 * 90-pre). Audit category normalized to canonical ('asset','create|
 * update','responder') so webhooks fire from the same allowlist
 * entry as the external API path (Phase 94 reliability gap).
 */

// Issue #28 (a beta tester 2026-07-02): "Unexpected end of JSON input" on
// save of a unit that was created via clock-in. The response body was
// coming back empty, which meant either (a) a PHP fatal inside one of
// the includes (any warning that leaks into the response body would
// have been HTML, not empty) or (b) a fatal deep in responder_upsert
// that terminated PHP after headers but before any output. Two hardens:
//
//   1. Suppress display_errors BEFORE any require so a WARN in
//      auth/rbac/audit includes can't leak HTML into the response.
//   2. Register a shutdown handler that turns a fatal into a proper
//      JSON error response so the client always parses something.
//
// Nothing else in this file changed.
ini_set('display_errors', '0');
error_reporting(E_ALL);
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('[responder-save fatal] ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
        // Don't leak file paths / stack details to the client.
        echo json_encode(['error' => 'Server error while saving. Check logs.']);
    }
});

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/responder-write.php';

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

// RBAC enforcement (specs/rbac-enforcement-2026-06).
// This endpoint is write-only (POST); creating/updating responders requires
// action.manage_members.
if (!rbac_can('action.manage_members')) {
    json_error('Insufficient permissions: manage members', 403);
}

// Phase 99j-6b (Billy beta 2026-06-29) — org-scope gate on UPDATE.
// On CREATE (no id in payload) we'd default org_id to the user's
// home_org_id; the upsert helper handles that next.
require_once __DIR__ . '/../inc/org-scope.php';
$existingId = (int) ($input['id'] ?? 0);
if ($existingId > 0 && !org_can_see_row('responder', $existingId)) {
    json_error('Responder not found', 404);
}
// On create, stamp the new row with the creator's home org so it's
// visible to Org Admins from the start. Existing rows pass through.
if ($existingId === 0 && !isset($input['org_id'])) {
    try {
        $homeOrg = org_user_home_id((int) $current_user_id);
        if ($homeOrg > 0) $input['org_id'] = $homeOrg;
    } catch (Exception $e) { /* leave NULL */ }
}

try {
    $result = responder_upsert_internal($input, (int) $current_user_id);
} catch (Exception $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!empty($result['errors'])) {
    $errs = $result['errors'];
    if (in_array('not_found', $errs, true)) {
        json_error('Responder not found', 404);
    }
    if (in_array('name is required', $errs, true)) {
        json_error('Name is required');
    }
    if (in_array('description is required', $errs, true)) {
        json_error('Description is required');
    }
    json_error('Save failed: ' . implode(', ', $errs), 422);
}

$savedId  = (int) $result['id'];
$isNew    = !empty($result['is_new']);
$name     = trim((string) ($input['name'] ?? ''));
$callsign = trim((string) ($input['callsign'] ?? ''));

if ($isNew) {
    audit_log('asset', 'create', 'responder', $savedId, "Created responder '{$name}'", [
        'callsign' => $callsign ?: null,
    ]);
    json_response([
        'message' => 'Responder created successfully',
        'id'      => $savedId,
    ], 201);
}

audit_log('asset', 'update', 'responder', $savedId, "Updated responder '{$name}'", [
    'callsign' => $callsign ?: null,
]);

json_response([
    'message' => 'Responder updated successfully',
    'id'      => $savedId,
]);
