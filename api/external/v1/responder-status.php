<?php
/**
 * Phase 94 Stage 4e — External API: responder status update.
 *
 * PATCH /api/external/v1/responder-status.php
 *   Body: {"responder_id": N, "status_id": M, "status_about": "optional"}
 *
 * Mirrors api/responder-status.php exactly via inc/responder-write.php's
 * responder_set_status_internal() — including the Phase 90-pre fix that
 * stamps the open-assignments timestamps (responding/on_scene/clear)
 * based on the new status's incident_action.
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'PATCH') {
    ext_api_error('method_not_allowed', 405, ['allowed' => ['PATCH']]);
}

ext_api_require_scope('responders:write');
if (!rbac_can('action.change_unit_status')) {
    ext_api_error('forbidden_rbac', 403, ['required' => 'action.change_unit_status']);
}

$raw   = file_get_contents('php://input');
$input = $raw ? @json_decode($raw, true) : null;
if (!is_array($input)) ext_api_error('invalid_json_body', 400);

// Honor either JSON body or $_GET (dispatcher injects:
// /responders/<id>/status → $_GET['responder_id']=<id>).
$responderId = (int) ($input['responder_id'] ?? $_GET['responder_id'] ?? 0);
$statusId    = (int) ($input['status_id'] ?? 0);
$statusAbout = trim((string) ($input['status_about'] ?? ''));

if ($responderId <= 0) ext_api_error('invalid_responder_id', 400);
if ($statusId <= 0)    ext_api_error('invalid_status_id', 400);

require_once __DIR__ . '/../../../inc/responder-write.php';
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) ext_api_error('auth_user_missing', 500);

try {
    $result = responder_set_status_internal($responderId, $statusId, $userId, $statusAbout);
} catch (Exception $e) {
    ext_api_db_error('db_query', $e);
}

if (!empty($result['errors'])) {
    $errs = $result['errors'];
    if (in_array('responder_not_found', $errs, true)) ext_api_error('responder_not_found', 404);
    if (in_array('status_not_found', $errs, true))    ext_api_error('status_not_found', 404);
    // Phase 105 (a beta tester GH #16) — status-workflow 'enforce' mode blocked
    // the transition. Same machine code as the internal endpoint.
    if (in_array('workflow_blocked', $errs, true)) {
        $reason = 'This status change is not allowed by the status workflow';
        foreach ($errs as $e) {
            if (strpos((string) $e, 'reason:') === 0) {
                $reason = substr((string) $e, 7);
                break;
            }
        }
        ext_api_error('workflow_blocked', 422, ['reason' => $reason]);
    }
    ext_api_error('validation_failed', 422, ['errors' => $errs]);
}

audit_log('asset', 'status_change', 'responder', $responderId,
    "External API status change on responder #{$responderId} → '{$result['status_name']}'",
    [
        'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
        'old_status_id'    => $result['old_status_id'] ?? null,
        'new_status_id'    => $result['new_status_id'] ?? $statusId,
        'incidents_logged' => $result['incidents_logged'] ?? 0,
        'timestamps_set'   => $result['timestamps_set'] ?? 0,
        'via_external_api' => true,
    ]
);

$response = [
    'updated'          => true,
    'responder_id'     => $responderId,
    'status_id'        => $statusId,
    'status_name'      => $result['status_name'],
    'incidents_logged' => $result['incidents_logged'] ?? 0,
    'timestamps_set'   => $result['timestamps_set'] ?? 0,
];
// Phase 105 — 'warn' mode: change applied, but flag the workflow miss.
if (!empty($result['workflow_warning'])) {
    $response['workflow_warning'] = $result['workflow_warning'];
}
ext_api_response($response);
