<?php
/**
 * NewUI v4.0 API - Responder Status Update
 *
 * POST /api/responder-status.php
 *   Update a responder's status.
 *   JSON body: { responder_id, status_id, status_about, csrf_token }
 *   Updates un_status_id and status_updated on responder table.
 *   Logs the status change.
 *
 * 2026-06-28 — Refactored to delegate to inc/responder-write.php::
 * responder_set_status_internal(). Auth/CSRF/RBAC stay here; the helper
 * owns the SQL + open-assigns stamping (Phase 90-pre). Audit category
 * normalized to canonical ('asset','status_change','responder') so
 * webhooks fire from the same allowlist entry as the external API path
 * (Phase 94 reliability gap).
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

// RBAC enforcement (specs/rbac-enforcement-2026-06).
// Write-only endpoint; changing a unit's status requires action.change_unit_status.
if (!rbac_can('action.change_unit_status')) {
    json_error('Insufficient permissions: change unit status', 403);
}

$responder_id = (int) ($input['responder_id'] ?? 0);
$status_id    = (int) ($input['status_id'] ?? 0);
$status_about = trim($input['status_about'] ?? '');

// Phase 95 (2026-06-28) — optional extra_data payload for the
// configurable per-status extra-data feature. Shape:
//   { extra_data: { type: 'facility|mileage|location|note|numeric',
//                   value: <int|string|array> } }
// Validation + routing happens inside responder_set_status_internal.
$extra_data = $input['extra_data'] ?? null;
if ($extra_data !== null && !is_array($extra_data)) $extra_data = null;

if ($responder_id <= 0) {
    json_error('Invalid responder ID');
}
if ($status_id <= 0) {
    json_error('Invalid status ID');
}

try {
    $result = responder_set_status_internal(
        $responder_id,
        $status_id,
        (int) $current_user_id,
        $status_about,
        $extra_data
    );
} catch (Exception $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!empty($result['errors'])) {
    $errs = $result['errors'];
    if (in_array('responder_not_found', $errs, true)) {
        json_error('Responder not found', 404);
    }
    if (in_array('status_not_found', $errs, true)) {
        json_error('Status not found', 404);
    }
    // Phase 95: surface the required-extra-data error with the label
    // the admin configured so the UI can prompt the operator.
    // Issue #10 (a beta tester 2026-07-02): mobile.js was scanning
    // data.error for the string 'extra_data_required' to decide
    // whether to open the prompt, but json_error only echoed the
    // human-readable message ("Extra data required for this
    // status: destination"). The machine code wasn't there, so
    // the mobile check missed and the prompt never opened.
    // Instead of json_error, send a structured payload with an
    // explicit `code` field the mobile handler can key on
    // deterministically, plus keep `error` for the toast.
    // Phase 105 (a beta tester GH #16) — status-workflow enforcement. The
    // internal helper blocked the transition ('enforce' mode). Surface
    // the human-readable reason with a machine code the UIs can key on,
    // mirroring the extra_data_required pattern below.
    if (in_array('workflow_blocked', $errs, true)) {
        $reason = 'This status change is not allowed by the status workflow';
        foreach ($errs as $e) {
            if (strpos((string) $e, 'reason:') === 0) {
                $reason = substr((string) $e, 7);
                break;
            }
        }
        json_response([
            'error' => $reason,
            'code'  => 'workflow_blocked',
        ], 422);
    }
    if (in_array('extra_data_required', $errs, true)) {
        $label = '';
        foreach ($errs as $e) {
            if (strpos((string) $e, 'label:') === 0) {
                $label = substr((string) $e, 6);
                break;
            }
        }
        json_response([
            'error' => 'Extra data required for this status: ' . ($label ?: 'value missing'),
            'code'  => 'extra_data_required',
            'label' => $label,
        ], 422);
    }
    json_error('Status update failed: ' . implode(', ', $errs), 422);
}

audit_log('asset', 'status_change', 'responder', $responder_id,
    "Status changed on responder #{$responder_id} to '" . $result['status_name'] . "'",
    [
        'old_status_id'    => $result['old_status_id'] ?? null,
        'new_status_id'    => $result['new_status_id'] ?? $status_id,
        'incidents_logged' => $result['incidents_logged'] ?? 0,
        'timestamps_set'   => $result['timestamps_set'] ?? 0,
    ]
);

// Phase 105 — 'warn' mode: the change went through, but the transition
// isn't in the configured workflow. Audit it and pass the warning back
// so the UI can show a non-blocking notice.
if (!empty($result['workflow_warning'])) {
    audit_log('asset', 'status_workflow_warn', 'responder', $responder_id,
        "Status workflow warning on responder #{$responder_id}: " . $result['workflow_warning'],
        [
            'old_status_id' => $result['old_status_id'] ?? null,
            'new_status_id' => $result['new_status_id'] ?? $status_id,
            'reason'        => $result['workflow_warning'],
        ]
    );
}

$response = [
    'message'          => 'Status updated to ' . $result['status_name'],
    'status_id'        => $status_id,
    'status_name'      => $result['status_name'],
    'incidents_logged' => $result['incidents_logged'] ?? 0,
    'timestamps_set'   => $result['timestamps_set'] ?? 0,
];
if (!empty($result['workflow_warning'])) {
    $response['workflow_warning'] = $result['workflow_warning'];
}
json_response($response);
