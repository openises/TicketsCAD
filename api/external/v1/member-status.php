<?php
/**
 * Phase 94 Stage 4i — External API: member (personnel) status change.
 *
 * PATCH /api/external/v1/member-status.php
 *   JSON body: {"member_id": N, "status_id": M}
 *
 * Mirrors api/responder-status.php's shape — a focused per-resource
 * status-change endpoint so an integrator (mobile app, IoT presence
 * beacon, etc.) doesn't have to round-trip the whole member record
 * just to flip availability.
 *
 * Scope: members:write
 * RBAC:  action.manage_members  (same gate the internal members.php
 *        POST save uses for status changes)
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';
require_once __DIR__ . '/../../../inc/member-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'PATCH') {
    ext_api_error('method_not_allowed', 405, ['allowed' => ['PATCH']]);
}

ext_api_require_scope('members:write');
if (!rbac_can('action.manage_members')) {
    ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_members']);
}

$raw = file_get_contents('php://input');
$input = $raw ? @json_decode($raw, true) : null;
if (!is_array($input)) ext_api_error('invalid_json_body', 400);

// Honor either JSON body or $_GET (dispatcher injects:
// /members/<id>/status → $_GET['member_id']=<id>).
$memberId = (int) ($input['member_id'] ?? $_GET['member_id'] ?? 0);
$statusId = (int) ($input['status_id'] ?? 0);
if ($memberId <= 0) ext_api_error('invalid_member_id', 400);
if ($statusId <= 0) ext_api_error('invalid_status_id', 400);

// ── Verify the member exists and isn't soft-deleted (IDOR-safe 404) ──
try {
    $member = db_fetch_one(
        "SELECT m.`id`, m.`first_name`, m.`last_name`, m.`member_status_id`
         FROM `{$prefix}member` m
         WHERE m.`id` = ?
           AND (m.`deleted_at` IS NULL OR m.`deleted_at` = '0000-00-00 00:00:00')",
        [$memberId]
    );
} catch (Exception $e) {
    ext_api_db_error('db_query', $e);
}
if (!$member) ext_api_error('not_found', 404);

// ── Verify the status row exists ──
$oldStatusId = (int) ($member['member_status_id'] ?? 0);
try {
    $status = db_fetch_one(
        "SELECT `id`, `status_val` FROM `{$prefix}member_status` WHERE `id` = ?",
        [$statusId]
    );
} catch (Exception $e) {
    // Pre-Phase-15 installs may not have member_status table; soft-fail
    // by treating the status as opaque (no label lookup) but still allow
    // the write.
    $status = ['id' => $statusId, 'status_val' => 'status #' . $statusId];
}
if (!$status) ext_api_error('invalid_status_id', 400, ['status_id' => $statusId]);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) ext_api_error('auth_user_missing', 500);

// ── Hand off to the shared write helper ──
try {
    $result = member_set_status_internal($memberId, $statusId, $userId);
} catch (Exception $e) {
    ext_api_internal_error('internal', $e);
}
if (!empty($result['errors'])) {
    ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
}

$displayName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: ('member #' . $memberId);

audit_log(
    'personnel',
    'status_change',
    'member',
    $memberId,
    "External API status change on '{$displayName}' to '" . $status['status_val'] . "'",
    [
        'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
        'request_id'       => $GLOBALS['__ext_api_request_id'] ?? null,
        'old_status_id'    => $oldStatusId,
        'new_status_id'    => $statusId,
        'new_status_label' => $status['status_val'],
        'via_external_api' => true,
    ]
);

ext_api_response([
    'member_id'   => $memberId,
    'status_id'   => $statusId,
    'status_name' => $status['status_val'],
]);
