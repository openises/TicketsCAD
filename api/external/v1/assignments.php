<?php
/**
 * Phase 94 Stage 4c — External API: responder-to-incident assignments.
 *
 * POST   /api/external/v1/assignments.php
 *   Body: { "ticket_id": N, "responder_id": N, "role": "optional",
 *           "force": false, "dispatch_now": false }
 *   Returns 201 { id: <assignId> }
 *   GH#141: when the install's "Units assigned to Scheduled incidents" setting
 *   is `reserve` and the incident is Scheduled with its booked time still ahead,
 *   the unit is RESERVED, not dispatched, and the answer is instead
 *   201 { reserved: true, reservation_id, ticket_id, responder_id, promotes_at }
 *   (no `id`: there is no assignment yet, and no assign.created webhook fires
 *   until the unit is really dispatched). "dispatch_now": true bypasses the
 *   reservation and dispatches at once.
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
require_once __DIR__ . '/../../../inc/assign-effects.php';      // GH#141: assign_emit_created_effects()
require_once __DIR__ . '/../../../inc/org-scope.php';

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

/**
 * GH#141 (Phase 155) -- the organization write gate this endpoint never had.
 *
 * Every other external write path that names an incident (PATCH/DELETE
 * /incidents/<id>) checks org_can_mutate_ticket() before touching it; this one
 * passed the id straight to the writer, so a token bound to a user of one
 * organization could assign units to -- and, with reservations, now reserve
 * units on -- another organization's incident. Same answer shape as
 * incidents.php: 403 `forbidden` when the caller can see the incident but its
 * tier does not permit writing, 404 `not_found` when it cannot see it at all
 * (never confirming that it exists). A missing incident is left to the writer's
 * own "Ticket not found" so existing behaviour for that case is unchanged.
 */
function _ext_assign_org_gate(int $ticketId): void {
    if ($ticketId <= 0) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $exists = db_fetch_value("SELECT 1 FROM `{$prefix}ticket` WHERE `id` = ?", [$ticketId]);
    if (!$exists) return;
    if (!org_can_mutate_ticket($ticketId)) {
        if (org_can_see_ticket($ticketId)) ext_api_error('forbidden', 403);
        ext_api_error('not_found', 404);
    }
}

/** The incident an assignment belongs to (0 if unknown), for the PATCH/DELETE org gate. */
function _ext_assign_ticket_of(int $assignId): int {
    if ($assignId <= 0) return 0;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return (int) db_fetch_value("SELECT `ticket_id` FROM `{$prefix}assigns` WHERE `id` = ?", [$assignId]);
    } catch (Exception $e) {
        return 0;
    }
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

    // GH#82/GH#83 — same dispatch-level / Multi-Assign gate as the
    // internal endpoint. A headless caller can't answer a confirm dialog,
    // so a WARN-level result is reported back as a 409 (not silently
    // allowed, and not silently dropped) — the caller must explicitly
    // resend with `"force": true` once it has decided to proceed, exactly
    // like api/incident-assign.php's interactive confirm-and-resubmit.
    // A hard BLOCK (Dispatch level: Unavailable) is never bypassable by
    // force, from either endpoint.
    $force = !empty($input['force']);

    _ext_assign_org_gate($ticketId);

    try {
        $result = assign_create_internal($ticketId, $responderId, $role, $userId, $force,
            !empty($input['dispatch_now']) ? ['dispatch_now' => true] : []);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    if (!empty($result['needs_confirmation'])) {
        ext_api_error('dispatch_confirmation_required', 409, ['message' => $result['message']]);
    }

    if (!empty($result['errors'])) {
        $status = !empty($result['blocked']) ? 409 : 422;
        $code   = !empty($result['blocked']) ? 'dispatch_blocked' : 'validation_failed';
        ext_api_error($code, $status, ['errors' => $result['errors']]);
    }

    // GH#141 -- RESERVED, not dispatched: no assignment exists (id would be 0), so
    // there is no assign.created audit row to write and nothing to put in `id`.
    if (!empty($result['reserved'])) {
        ext_api_response([
            'reserved'       => true,
            'reservation_id' => (int) $result['reservation_id'],
            'ticket_id'      => $ticketId,
            'responder_id'   => $responderId,
            'promotes_at'    => $result['promotes_at'] ?? null,
            'conflicts'      => $result['conflicts'] ?? [],
        ], 201);
    }

    $assignId = (int) $result['id'];

    // Audit row -> 'assign.created' webhook (target_type='assigns' matches the
    // _audit_to_webhook_event mapping in inc/webhooks.php) and the SSE refresh:
    // GH#141 moved both into assign_emit_created_effects(), shared with the
    // promotion of a reservation. This endpoint keeps its historical audit
    // summary/details and SSE payload shape, and -- as before -- does not push
    // OwnTracks config or fire notification rules.
    require_once __DIR__ . '/../../../inc/sse.php';
    assign_emit_created_effects($ticketId, $responderId, $assignId, '', [
        'via'       => 'external_api',
        'token_id'  => $GLOBALS['__ext_api_token_id'] ?? null,
        'role'      => $role,
        'owntracks' => false,
        'notify'    => false,
    ]);

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

    _ext_assign_org_gate(_ext_assign_ticket_of($assignId));

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

    _ext_assign_org_gate(_ext_assign_ticket_of($assignId));

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
