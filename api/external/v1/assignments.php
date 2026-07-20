<?php
/**
 * Phase 94 Stage 4c — External API: responder-to-incident assignments.
 *
 * POST   /api/external/v1/assignments.php
 *   Body: { "ticket_id": N, "responder_id": N, "role": "optional" }
 *   Returns 201 { id: <assignId> }
 *
 * PATCH  /api/external/v1/assignments.php
 *   Body: { "assign_id": N, "new_status_id": N }
 *     OR  { "assign_id": N, "new_status": "responding|on_scene|clear" }
 *   Returns 200 { status: <applied> }
 *
 * DELETE /api/external/v1/assignments.php?assign_id=N
 *   (Body also accepted: { "assign_id": N } for client convenience.)
 *   Returns 200 { unassigned: true }
 *
 * Authenticated by bearer token via _auth.php. All three actions share
 * the same scope (incidents:write) and RBAC (action.assign_unit). The
 * audit_log() calls auto-fire the matching webhook events via the
 * Stage 5 hook in inc/audit.php (mappings already present in
 * inc/webhooks.php: 'assign.created', 'assign.removed';
 * responder.status_changed for the propagation update).
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';
require_once __DIR__ . '/../../../inc/assignment-write.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Scope + RBAC are uniform across all three verbs
ext_api_require_scope('incidents:write');
if (!rbac_can('action.assign_unit')) {
    ext_api_error('forbidden_rbac', 403, ['required' => 'action.assign_unit']);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    ext_api_error('auth_user_missing', 500);
}

/** Decode JSON body; fall through to empty array on DELETE if no body. */
function _ext_decode_body(bool $required): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        if ($required) ext_api_error('invalid_json_body', 400);
        return [];
    }
    $decoded = @json_decode($raw, true);
    if (!is_array($decoded)) {
        if ($required) ext_api_error('invalid_json_body', 400);
        return [];
    }
    return $decoded;
}

// ═══════════════════════════════════════════════════════════════
//  POST — assign
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    $input = _ext_decode_body(true);

    // Honor either JSON body or $_GET (dispatcher injects ticket_id from
    // /incidents/<id>/assignments clean URLs).
    $ticketId    = (int) ($input['ticket_id']    ?? $_GET['ticket_id']    ?? 0);
    $responderId = (int) ($input['responder_id'] ?? 0);
    $role        = trim((string) ($input['role']  ?? ''));

    if ($ticketId <= 0)    ext_api_error('invalid_ticket_id', 400);
    if ($responderId <= 0) ext_api_error('invalid_responder_id', 400);

    try {
        $result = assign_create_internal($ticketId, $responderId, $role, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    $assignId = (int) $result['id'];

    // Fire audit → 'assign.created' webhook via the Stage 5 hook.
    // target_type='assigns' matches the existing
    // _audit_to_webhook_event mapping in inc/webhooks.php.
    try {
        audit_log(
            'incident', 'assign', 'assigns', $assignId,
            "External API assigned responder #{$responderId} to incident #{$ticketId}",
            [
                'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
                'ticket_id'        => $ticketId,
                'responder_id'     => $responderId,
                'role'             => $role,
                'via_external_api' => true,
            ]
        );
    } catch (Exception $e) { /* audit failure non-fatal */ }

    // Best-effort SSE for live UI refresh
    try {
        require_once __DIR__ . '/../../../inc/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('responder:assign', [
                'ticket_id'    => $ticketId,
                'responder_id' => $responderId,
                'assign_id'    => $assignId,
                'via'          => 'external_api',
            ], $ticketId);
        }
    } catch (Exception $e) { /* SSE non-fatal */ }

    ext_api_response([
        'id'           => $assignId,
        'ticket_id'    => $ticketId,
        'responder_id' => $responderId,
    ], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — update status (responding | on_scene | clear OR picked-id)
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    $input = _ext_decode_body(true);

    // PATCH — dispatcher injects assign_id from /assignments/<aid> too
    $assignId = (int) ($input['assign_id'] ?? $_GET['assign_id'] ?? 0);
    if ($assignId <= 0) ext_api_error('invalid_assign_id', 400);

    // Either new_status_id (int) OR new_status (string)
    $statusInput = null;
    if (isset($input['new_status_id']) && (int) $input['new_status_id'] > 0) {
        $statusInput = (int) $input['new_status_id'];
    } elseif (isset($input['new_status'])) {
        $statusInput = (string) $input['new_status'];
    } else {
        ext_api_error('missing_status', 400, ['hint' => 'Send new_status or new_status_id']);
    }

    try {
        $result = assign_update_status_internal($assignId, $statusInput, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    if (!empty($result['errors'])) {
        $msg = $result['errors'][0];
        // Differentiate "already cleared" / "not found" for cleaner client UX
        if (stripos($msg, 'not found') !== false) {
            ext_api_error('not_found', 404, ['resource' => 'assigns', 'id' => $assignId]);
        }
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    $appliedStatus = (string) $result['status'];

    // Fire audit. There's no dedicated 'assign.status_changed' event in
    // the Stage-5 map yet — Stage 4c.3 lists it as a follow-up. We use
    // 'incident|update|responder' which maps to
    // 'responder.status_changed' (the legacy setResponderStatus path).
    // Subscribers watching responder.status_changed will see this.
    try {
        audit_log(
            'incident', 'update', 'responder', $assignId,
            "External API updated assign #{$assignId} status → " . ($appliedStatus !== '' ? $appliedStatus : '(picked)'),
            [
                'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
                'assign_id'        => $assignId,
                'new_status'       => $appliedStatus,
                'via_external_api' => true,
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    ext_api_response([
        'assign_id' => $assignId,
        'status'    => $appliedStatus,
    ], 200);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — unassign
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    // Accept assign_id via query string OR JSON body (some clients
    // can't send a DELETE body)
    $assignId = isset($_GET['assign_id']) ? (int) $_GET['assign_id'] : 0;
    if ($assignId <= 0) {
        $input = _ext_decode_body(false);
        $assignId = (int) ($input['assign_id'] ?? 0);
    }
    if ($assignId <= 0) ext_api_error('invalid_assign_id', 400);

    try {
        $result = assign_unassign_internal($assignId, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    if (!empty($result['errors'])) {
        $msg = $result['errors'][0];
        if (stripos($msg, 'not found') !== false) {
            ext_api_error('not_found', 404, ['resource' => 'assigns', 'id' => $assignId]);
        }
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    // Fire audit → 'assign.removed' webhook via Stage 5 hook
    try {
        audit_log(
            'incident', 'unassign', 'assigns', $assignId,
            "External API unassigned assign #{$assignId}",
            [
                'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
                'assign_id'        => $assignId,
                'ticket_id'        => (int) ($result['ticket_id'] ?? 0),
                'responder_id'     => (int) ($result['responder_id'] ?? 0),
                'via_external_api' => true,
            ]
        );
    } catch (Exception $e) { /* non-fatal */ }

    ext_api_response([
        'assign_id'  => $assignId,
        'unassigned' => true,
    ], 200);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['POST', 'PATCH', 'DELETE']]);
