<?php
/**
 * Phase 94 Stage 4a — External API: incidents.
 *
 * GET    /api/external/v1/incidents.php           list
 * GET    /api/external/v1/incidents.php?id=N      detail
 * POST   /api/external/v1/incidents.php           create
 *
 * (PATCH/DELETE land in the next slice — same endpoint with HTTP
 * method dispatch + path-suffix-aware routing once
 * api/external/v1/_dispatch.php is in place.)
 *
 * Authenticated by bearer token via _auth.php (Stage 2). Token's
 * scope LIMITS what it can hit; the owning user's RBAC GRANTS the
 * actual capability (Decision #1 + §2.4).
 *
 * Calls into inc/incident-write.php's incident_create_internal()
 * which is the canonical write path also used by api/incident-create.php
 * once the legacy endpoint is refactored to share the helper.
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

// 2026-06-28 security audit fix #5: $prefix was previously only
// declared inside the GET branch. PATCH and DELETE pre-check queries
// reference $prefix but it must be at file scope for installs with a
// non-empty db_prefix (training has it empty, masking the bug).
$prefix = $GLOBALS['db_prefix'] ?? '';

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — list or detail
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    ext_api_require_scope('incidents:read');
    if (!rbac_can('action.view_incident') && !rbac_can('action.view_incidents')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.view_incident']);
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Single detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            $row = db_fetch_one(
                "SELECT t.*, it.type AS in_type_name
                 FROM `{$prefix}ticket` t
                 LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
                 WHERE t.id = ?",
                [$id]
            );
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        // Phase 99j-8 (Billy beta 2026-06-29) — token's owning user
        // must be able to see this ticket via org scope. Same 404 as
        // a real not-found so the API can't be used to probe ids
        // across tenants.
        require_once __DIR__ . '/../../../inc/org-scope.php';
        if (!org_can_see_ticket($id)) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'ticket', $id, "External API GET incident #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);
        ext_api_response($row);
    }

    // List
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $status = isset($_GET['status']) ? (int) $_GET['status'] : null;
    $since  = isset($_GET['since']) ? trim((string) $_GET['since']) : null;

    $where = [];
    $params = [];
    if ($status !== null) { $where[] = 't.status = ?'; $params[] = $status; }
    if ($since)           { $where[] = 't.updated >= ?'; $params[] = $since; }

    // Phase 99j-8 — token's owning user determines visibility.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    [$orgFrag, $orgVars] = org_query_filter('t.org_id');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }

    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.in_types_id, t.scope, t.severity, t.status, t.contact, t.phone,
                    t.street, t.city, t.state, t.lat, t.lng, t.date, t.updated,
                    t.incident_number, it.type AS in_type_name
             FROM `{$prefix}ticket` t
             LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
             {$whereSql}
             ORDER BY t.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    audit_log('external_api', 'list', 'ticket', null,
        "External API list incidents (count=" . count($rows) . ")",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null, 'limit' => $limit, 'offset' => $offset]);
    ext_api_response(['incidents' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('incidents:write');
    if (!rbac_can('action.create_incident')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.create_incident']);
    }

    $raw = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/incident-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_create_internal($input, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    // GH #8 (2026-07-14): the incident|create|ticket audit — and its webhook +
    // Web Push fan-out — now fires INSIDE incident_create_internal(), so every
    // create path is consistent. Do NOT re-audit here (it would double-fire the
    // push). Record the external-API attribution as a NON-mapping audit so the
    // provenance is preserved without a second push.
    audit_log('data', 'api_incident_create', 'ticket', $result['id'],
        "External API created incident #{$result['id']}: " . substr((string) ($input['scope'] ?? ''), 0, 80),
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'in_types_id'      => (int) ($input['in_types_id'] ?? 0),
            'severity'         => (int) ($input['severity'] ?? 0),
            'patient_count'    => $result['patient_count'],
            'via_external_api' => true,
        ]
    );

    // Publish SSE event for real-time UI updates (mirrors api/incident-create.php)
    try {
        require_once __DIR__ . '/../../../inc/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('incident:new', [
                'ticket_id' => $result['id'],
                'scope'     => $input['scope'] ?? '',
                'severity'  => $input['severity'] ?? 0,
                'via'       => 'external_api',
            ], $result['id']);
        }
    } catch (Exception $e) { /* SSE non-fatal */ }

    ext_api_response([
        'id'              => $result['id'],
        'incident_number' => $result['incident_number'],
        'patient_count'   => $result['patient_count'],
    ], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — partial update
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('incidents:write');
    if (!rbac_can('action.edit_incident')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.edit_incident']);
    }

    $raw   = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    // Dispatcher injects id from /incidents/<id>; allow body override too.
    $ticketId = (int) ($input['id'] ?? $_GET['id'] ?? 0);
    if ($ticketId <= 0) ext_api_error('invalid_id', 400);

    // The fields to update may be sent at the top level OR nested under
    // `fields` (to match the internal endpoint's shape). Honor both.
    $fields = isset($input['fields']) && is_array($input['fields'])
        ? $input['fields']
        : array_diff_key($input, array_flip(['id', 'fields']));

    if (empty($fields)) {
        ext_api_error('validation_failed', 422, ['errors' => ['no fields to update']]);
    }

    // Pre-check the ticket exists so we return a clean 404 instead of
    // letting an UPDATE silently affect 0 rows.
    try {
        $exists = db_fetch_value(
            "SELECT id FROM `{$prefix}ticket` WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
            [$ticketId]
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!$exists) ext_api_error('not_found', 404);

    // Phase 99j-8 — token must be allowed to see/touch this ticket.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    if (!org_can_see_ticket($ticketId)) ext_api_error('not_found', 404);

    require_once __DIR__ . '/../../../inc/incident-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_update_fields_internal($ticketId, $fields, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('incident', 'update', 'ticket', $ticketId,
        "External API updated incident #{$ticketId} (" . implode(', ', $result['fields_changed']) . ")",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'fields_changed'   => $result['fields_changed'],
            'via_external_api' => true,
        ]
    );

    try {
        require_once __DIR__ . '/../../../inc/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('incident:update',
                ['ticket_id' => $ticketId, 'fields_changed' => $result['fields_changed'], 'via' => 'external_api'],
                $ticketId);
        }
    } catch (Exception $e) { /* SSE non-fatal */ }

    ext_api_response([
        'id'             => $ticketId,
        'fields_changed' => $result['fields_changed'],
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — soft-delete (wastebasket)
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('incidents:write');
    // Use the same RBAC code the internal soft-delete path uses; if
    // a more specific action.delete_incident exists, prefer that.
    if (!rbac_can('action.delete_incident') && !rbac_can('action.edit_incident')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.delete_incident']);
    }

    $ticketId = (int) ($_GET['id'] ?? 0);
    if ($ticketId <= 0) ext_api_error('invalid_id', 400);

    try {
        $exists = db_fetch_value(
            "SELECT id FROM `{$prefix}ticket` WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
            [$ticketId]
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!$exists) ext_api_error('not_found', 404);

    // Phase 99j-8 — token must be allowed to see/touch this ticket.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    if (!org_can_see_ticket($ticketId)) ext_api_error('not_found', 404);

    require_once __DIR__ . '/../../../inc/incident-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_soft_delete_internal($ticketId, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('db_error', 500, ['errors' => $result['errors']]);
    }

    audit_log('incident', 'delete', 'ticket', $ticketId,
        "External API soft-deleted incident #{$ticketId}",
        ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null, 'via_external_api' => true]);

    ext_api_response(['deleted' => $result['deleted']]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'PATCH', 'DELETE']]);
