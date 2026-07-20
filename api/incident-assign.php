<?php
/**
 * NewUI v4.0 API - Incident Assignment Management
 *
 * POST /api/incident-assign.php
 *
 * Manages responder assignments on existing incidents.
 * Three actions via JSON body 'action' field:
 *
 *   assign        { ticket_id, responder_id }
 *   update_status { ticket_id, assign_id, new_status }  (responding|on_scene|clear)
 *   unassign      { ticket_id, assign_id }
 *
 * 2026-06-28 — Phase 94 Stage 4j refactor: delegates SQL/business logic
 * to inc/assignment-write.php helpers (assign_create_internal,
 * assign_update_status_internal, assign_unassign_internal). Endpoint
 * owns auth/CSRF/RBAC, ticket existence pre-check, display-name
 * lookups for response messages, audit category normalization, SSE
 * fan-out, notification triggers, and OwnTracks config push.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/assignment-write.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if (!rbac_can('action.assign_unit')) {
    json_error('Insufficient permissions: assign units', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$prefix    = $GLOBALS['db_prefix'] ?? '';
$action    = trim($input['action'] ?? '');
$ticket_id = (int) ($input['ticket_id'] ?? 0);

if ($ticket_id <= 0) {
    json_error('Invalid ticket ID');
}

// Verify ticket exists. The helpers each re-verify, but a friendly
// 404 here keeps the dispatcher UI's error toast accurate.
try {
    $ticket = db_fetch_one(
        "SELECT `id`, `status`, `scope` FROM `{$prefix}ticket` WHERE `id` = ?",
        [$ticket_id]
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!$ticket) {
    ini_set('display_errors', $prevDisplay);
    json_error('Ticket not found', 404);
}

// Phase 99j-4 (Billy beta 2026-06-29) — org-scope gate. Stops an Org
// Admin from assigning units to a ticket that belongs to another
// tenant. Same 404-not-403 to avoid confirming existence.
require_once __DIR__ . '/../inc/org-scope.php';
if (!org_can_see_ticket($ticket_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Ticket not found', 404);
}

// ── Helper: look up the display name (handle or name) for a responder.
// Used for response messages + SSE payloads + audit_log descriptions.
function _ia_responder_name(int $responderId): string {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $r = db_fetch_one(
            "SELECT `name`, `handle` FROM `{$prefix}responder` WHERE `id` = ?",
            [$responderId]
        );
    } catch (Exception $e) { $r = null; }
    if (!$r) return "responder #{$responderId}";
    return $r['handle'] ?: $r['name'] ?: "responder #{$responderId}";
}

// ══════════════════════════════════════════════════════════════
// ACTION: assign
// ══════════════════════════════════════════════════════════════
if ($action === 'assign') {
    $responder_id = (int) ($input['responder_id'] ?? 0);
    if ($responder_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid responder ID');
    }

    // Pre-fetch responder display name so we can emit it in the
    // response/SSE/audit even before the helper succeeds.
    $respName = _ia_responder_name($responder_id);
    $role     = trim((string) ($input['role'] ?? ''));

    $result = assign_create_internal($ticket_id, $responder_id, $role, (int) $current_user_id);
    if (!empty($result['errors'])) {
        $first = (string) $result['errors'][0];
        // Map known helper errors to the legacy endpoint's status codes
        if (strpos($first, 'already assigned') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error($first);
        }
        if (strpos($first, 'not found') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error($first, 404);
        }
        ini_set('display_errors', $prevDisplay);
        json_error($first, 500);
    }

    $assign_id = (int) $result['id'];

    // Canonical webhook-eligible event: 'incident|assign|assigns' →
    // assign.created (matches inc/webhooks.php canonical entry —
    // replaces the legacy 'incident|assign|responder' alias).
    audit_log('incident', 'assign', 'assigns', $assign_id,
        "Assigned '{$respName}' to incident #{$ticket_id}",
        [
            'ticket_id'    => $ticket_id,
            'responder_id' => $responder_id,
            'assign_id'    => $assign_id,
        ]);

    require_once __DIR__ . '/../inc/sse.php';
    sse_publish_for_incident('responder:assign',
        ['ticket_id' => $ticket_id, 'responder' => $respName, 'action' => 'assign'],
        $ticket_id);

    // Phase 52b — push tightened OwnTracks config (5min stationary,
    // 30s moving) to everyone assigned to this unit. Best-effort; if
    // the helper file or table is missing we just skip. The
    // OT_CONFIG_LIBRARY_ONLY guard prevents the included file from
    // re-dispatching against this request's $_GET/$_POST.
    try {
        if (!defined('OT_CONFIG_LIBRARY_ONLY')) define('OT_CONFIG_LIBRARY_ONLY', 1);
        require_once __DIR__ . '/owntracks-config.php';
        if (function_exists('_ot_recompute_for_responder')) {
            _ot_recompute_for_responder($responder_id, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        }
    } catch (Throwable $e) { /* swallow — never block an assignment for a config push */ }

    // ── Fire notification rules (best-effort) ──
    try {
        require_once __DIR__ . '/../inc/notification-engine.php';
        notification_check('unit_assign', [
            'ticket_id'      => $ticket_id,
            'scope'          => $ticket['scope'] ?? '',
            'responder_id'   => $responder_id,
            'responder_name' => $respName,
        ]);
    } catch (Exception $e) {
        error_log('Notification engine error on unit assign: ' . $e->getMessage());
    }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'   => true,
        'assign_id' => $assign_id,
        'message'   => $respName . ' assigned to incident #' . $ticket_id,
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: update_status
// ══════════════════════════════════════════════════════════════
elseif ($action === 'update_status') {
    $assign_id = (int) ($input['assign_id'] ?? 0);
    if ($assign_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid assignment ID');
    }

    // Helper accepts either a named string (responding|on_scene|clear)
    // or an integer un_status id. The legacy endpoint received either
    // via new_status (string) or new_status_id (int) — pick the first
    // populated one (preserves legacy precedence: new_status_id only
    // applies when new_status is empty).
    $newStatusStr = trim((string) ($input['new_status'] ?? ''));
    $newStatusId  = (int) ($input['new_status_id'] ?? 0);
    $statusInput  = $newStatusStr !== ''
        ? $newStatusStr
        : ($newStatusId > 0 ? $newStatusId : '');

    // Phase 104f (a beta tester GH #10) — hand the full input array to the
    // internal so it can read extra_data (validated against the
    // picked-status's extra_data_required flag). Ambient globals are
    // ugly but the alternative is changing the helper's signature,
    // which cascades through every legacy call site.
    $GLOBALS['_assign_update_status_input'] = is_array($input) ? $input : [];
    $result = assign_update_status_internal($assign_id, $statusInput, (int) $current_user_id);
    unset($GLOBALS['_assign_update_status_input']);
    if (!empty($result['errors'])) {
        $first = (string) $result['errors'][0];
        // Phase 104f — extra-data-required error surfaces to the JS
        // client so it can open the inline extra-data prompt (mobile
        // + situation reuse the same modal). Split on ':' so callers
        // get both the label and the type.
        if ($first === 'extra_data_required') {
            $label = '';
            foreach ($result['errors'] as $err) {
                if (strpos((string) $err, 'label:') === 0) {
                    $label = substr((string) $err, 6);
                    break;
                }
            }
            ini_set('display_errors', $prevDisplay);
            json_response([
                'error' => 'extra_data_required',
                'label' => $label,
                'hint'  => 'Status requires additional data — reopen the status modal to supply it.',
            ], 422);
        }
        if (strpos($first, 'not found') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error($first, 404);
        }
        if (strpos($first, 'already cleared') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error($first);
        }
        if (strpos($first, 'Invalid') !== false) {
            ini_set('display_errors', $prevDisplay);
            json_error($first);
        }
        ini_set('display_errors', $prevDisplay);
        json_error($first, 500);
    }

    // Look up assignment + responder name for the response message
    try {
        $assign = db_fetch_one(
            "SELECT `a`.`responder_id`, `r`.`name` AS `responder_name`, `r`.`handle` AS `responder_handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`id` = ?",
            [$assign_id]
        );
        $respName = $assign ? ($assign['responder_handle'] ?: $assign['responder_name']) : "assign #{$assign_id}";
    } catch (Exception $e) { $respName = "assign #{$assign_id}"; }

    $appliedStatus = (string) ($result['status'] ?? '');

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => $respName . ' status updated to ' . str_replace('_', ' ', $appliedStatus),
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: unassign
// ══════════════════════════════════════════════════════════════
elseif ($action === 'unassign') {
    $assign_id = (int) ($input['assign_id'] ?? 0);
    if ($assign_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid assignment ID');
    }

    // Pre-fetch responder name for response/SSE/audit (helper returns
    // responder_id but not name).
    try {
        $assign = db_fetch_one(
            "SELECT `a`.`responder_id`, `r`.`name` AS `responder_name`, `r`.`handle` AS `responder_handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?",
            [$assign_id, $ticket_id]
        );
    } catch (Exception $e) { $assign = null; }

    if (!$assign) {
        // Preserve the legacy "Assignment not found" 404 when the
        // assigns row doesn't belong to this ticket (the helper alone
        // would happily clear an assigns row on any ticket).
        ini_set('display_errors', $prevDisplay);
        json_error('Assignment not found', 404);
    }

    $respName     = $assign['responder_handle'] ?: $assign['responder_name'];
    $responder_id = (int) $assign['responder_id'];

    $result = assign_unassign_internal($assign_id, (int) $current_user_id);
    if (!empty($result['errors'])) {
        $first = (string) $result['errors'][0];
        ini_set('display_errors', $prevDisplay);
        json_error($first, strpos($first, 'not found') !== false ? 404 : 500);
    }

    // Canonical webhook-eligible event: 'incident|unassign|assigns' →
    // assign.removed (matches inc/webhooks.php canonical entry —
    // replaces the legacy 'incident|unassign|responder' alias).
    audit_log('incident', 'unassign', 'assigns', $assign_id,
        "Unassigned '{$respName}' from incident #{$ticket_id}",
        [
            'ticket_id'    => $ticket_id,
            'responder_id' => $responder_id,
            'assign_id'    => $assign_id,
        ]);

    // Phase 52b — push baseline OwnTracks config back to members on
    // this unit (if they're not still on another active incident).
    // _ot_member_has_active_incident, called from inside _ot_build_
    // layered_config, handles the "still on another assignment" case.
    try {
        if (!defined('OT_CONFIG_LIBRARY_ONLY')) define('OT_CONFIG_LIBRARY_ONLY', 1);
        require_once __DIR__ . '/owntracks-config.php';
        if (function_exists('_ot_recompute_for_responder')) {
            _ot_recompute_for_responder($responder_id, (int) ($_SESSION['user_id'] ?? 0) ?: null);
        }
    } catch (Throwable $e) { /* swallow */ }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => $respName . ' removed from incident #' . $ticket_id,
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: set_rec_facility  (Phase 116 — per-unit receiving facility)
// { ticket_id, assign_id, facility_id }  (facility_id 0 clears it)
// Sets the destination hospital for ONE unit's assignment. A mass-casualty
// incident sends different units to different facilities; this is the
// always-editable per-unit selector on the incident's assigned-unit rows.
// ══════════════════════════════════════════════════════════════
elseif ($action === 'set_rec_facility') {
    $assign_id   = (int) ($input['assign_id'] ?? 0);
    $facility_id = (int) ($input['facility_id'] ?? 0);
    if ($assign_id <= 0) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid assignment ID');
    }

    // The assign must belong to THIS ticket (same 404 guard as unassign).
    try {
        $assign = db_fetch_one(
            "SELECT `a`.`responder_id`, `r`.`name` AS `responder_name`, `r`.`handle` AS `responder_handle`
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
             WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?",
            [$assign_id, $ticket_id]
        );
    } catch (Exception $e) { $assign = null; }
    if (!$assign) {
        ini_set('display_errors', $prevDisplay);
        json_error('Assignment not found', 404);
    }

    // Validate the facility (when setting, not clearing).
    $facName = '';
    if ($facility_id > 0) {
        try {
            $fac = db_fetch_one(
                "SELECT `id`, `name` FROM `{$prefix}facilities` WHERE `id` = ?",
                [$facility_id]
            );
        } catch (Exception $e) { $fac = null; }
        if (!$fac) {
            ini_set('display_errors', $prevDisplay);
            json_error('Facility not found', 404);
        }
        $facName = (string) $fac['name'];
    }

    $respName = $assign['responder_handle'] ?: $assign['responder_name'] ?: "assign #{$assign_id}";

    assign_set_rec_facility($assign_id, $facility_id, (int) $current_user_id);

    audit_log('incident', 'update', 'assigns', $assign_id,
        $facility_id > 0
            ? "Set '{$respName}' receiving facility to '{$facName}' on incident #{$ticket_id}"
            : "Cleared '{$respName}' receiving facility on incident #{$ticket_id}",
        [
            'ticket_id'   => $ticket_id,
            'assign_id'   => $assign_id,
            'facility_id' => $facility_id,
        ]);

    try {
        require_once __DIR__ . '/../inc/sse.php';
        sse_publish_for_incident('responder:rec_facility',
            ['ticket_id' => $ticket_id, 'assign_id' => $assign_id,
             'facility_id' => $facility_id, 'facility_name' => $facName,
             'responder' => $respName],
            $ticket_id);
    } catch (Throwable $e) { /* non-fatal */ }

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success'       => true,
        'assign_id'     => $assign_id,
        'facility_id'   => $facility_id,
        'facility_name' => $facName,
        'message'       => $facility_id > 0
            ? $respName . ' → ' . $facName
            : $respName . ' destination cleared',
    ]);
}

// ── Unknown action ──
else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action: ' . $action . '. Valid actions: assign, update_status, unassign, set_rec_facility');
}
