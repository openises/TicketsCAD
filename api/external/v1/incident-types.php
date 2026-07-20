<?php
/**
 * Phase 94 Stage 4i — External API: incident types (in_types).
 *
 * GET    /api/external/v1/incident-types.php          list
 * GET    /api/external/v1/incident-types.php?id=N     detail
 * POST   /api/external/v1/incident-types.php          create
 * PATCH  /api/external/v1/incident-types.php?id=N     update
 * DELETE /api/external/v1/incident-types.php?id=N     hard delete
 *
 * Scopes:
 *   incident_types:read  — GET
 *   incident_types:write — POST / PATCH / DELETE
 *
 * RBAC: action.manage_config — mirrors api/config-admin.php's gate.
 *
 * Audit + webhook fan-out: target_type='incident_type'. Stage 5's
 * audit-driven dispatch already maps config|<act>|in_type — the
 * webhook event mapping in inc/webhooks.php currently uses 'in_type'
 * as the target, while config-admin.php and this endpoint use the
 * more readable 'incident_type'. The final report flags the mismatch
 * for the parallel agent owning webhooks.php to reconcile.
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════════════════
//  GET — list (no ?id=) or detail (?id=N)
// ═══════════════════════════════════════════════════════════════
if ($method === 'GET') {
    ext_api_require_scope('incident_types:read');
    // RBAC: a token whose bound user lacks manage_config can still GET
    // — viewing incident types is implicitly granted by anyone who can
    // file an incident. Gate writes only.

    // Detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            // Try the modern column set first; fall back if match_pattern
            // missing on pre-Phase-32 installs (same pattern as
            // api/config-admin.php).
            try {
                $row = db_fetch_one(
                    "SELECT `id`, `type`, `description`, `protocol`, `set_severity`,
                            `group`, `radius`, `color`, `sort`, `match_pattern`
                     FROM `{$prefix}in_types`
                     WHERE `id` = ?",
                    [$id]
                );
            } catch (Exception $eCol) {
                $row = db_fetch_one(
                    "SELECT `id`, `type`, `description`, `protocol`, `set_severity`,
                            `group`, `radius`, `color`, `sort`
                     FROM `{$prefix}in_types`
                     WHERE `id` = ?",
                    [$id]
                );
            }
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'incident_type', $id,
            "External API GET incident type #{$id}",
            ['token_id'   => $GLOBALS['__ext_api_token_id'] ?? null,
             'request_id' => $GLOBALS['__ext_api_request_id'] ?? null]);
        ext_api_response($row);
    }

    // List
    $limit  = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    try {
        try {
            $rows = db_fetch_all(
                "SELECT `id`, `type`, `description`, `protocol`, `set_severity`,
                        `group`, `radius`, `color`, `sort`, `match_pattern`
                 FROM `{$prefix}in_types`
                 ORDER BY `sort`, `type`
                 LIMIT {$limit} OFFSET {$offset}"
            );
        } catch (Exception $eCol) {
            $rows = db_fetch_all(
                "SELECT `id`, `type`, `description`, `protocol`, `set_severity`,
                        `group`, `radius`, `color`, `sort`
                 FROM `{$prefix}in_types`
                 ORDER BY `sort`, `type`
                 LIMIT {$limit} OFFSET {$offset}"
            );
        }
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }

    audit_log('external_api', 'list', 'incident_type', null,
        "External API list incident types (count=" . count($rows) . ")",
        ['token_id'   => $GLOBALS['__ext_api_token_id'] ?? null,
         'request_id' => $GLOBALS['__ext_api_request_id'] ?? null,
         'limit'      => $limit,
         'offset'     => $offset]);

    ext_api_response(['incident_types' => $rows, 'limit' => $limit, 'offset' => $offset]);
}

// ═══════════════════════════════════════════════════════════════
//  POST — create
// ═══════════════════════════════════════════════════════════════
if ($method === 'POST') {
    ext_api_require_scope('incident_types:write');
    if (!rbac_can('action.manage_config')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_config']);
    }

    $raw = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    require_once __DIR__ . '/../../../inc/incident-type-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_type_upsert_internal($input, $userId, null);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('config', 'create', 'incident_type', $result['id'],
        "External API created incident type '" . substr((string) ($input['type'] ?? ''), 0, 80) . "'",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'request_id'       => $GLOBALS['__ext_api_request_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $result['id'], 'created' => true], 201);
}

// ═══════════════════════════════════════════════════════════════
//  PATCH — update by ?id=N
// ═══════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    ext_api_require_scope('incident_types:write');
    if (!rbac_can('action.manage_config')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_config']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    // Verify the row exists first so we can return a clean 404 rather
    // than letting UPDATE silently affect 0 rows.
    try {
        $existing = db_fetch_one(
            "SELECT `id`, `type`, `description`, `protocol`, `set_severity`,
                    `group`, `radius`, `color`, `sort`
             FROM `{$prefix}in_types`
             WHERE `id` = ?",
            [$id]
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!$existing) ext_api_error('not_found', 404);

    $raw = file_get_contents('php://input');
    $input = $raw ? @json_decode($raw, true) : null;
    if (!is_array($input)) ext_api_error('invalid_json_body', 400);

    // PATCH semantics: merge incoming over existing so omitted fields
    // are preserved. The upsert helper takes a full record so we have
    // to do the merge here.
    $merged = array_merge([
        'type'          => $existing['type'],
        'description'   => $existing['description'],
        'protocol'      => $existing['protocol'],
        'set_severity'  => $existing['set_severity'],
        'group'         => $existing['group'],
        'radius'        => $existing['radius'],
        'color'         => $existing['color'],
        'sort'          => $existing['sort'],
        // match_pattern intentionally omitted from the existing fetch
        // for pre-Phase-32 compat — if the caller doesn't supply it,
        // the upsert helper's empty-string default applies.
    ], $input);

    require_once __DIR__ . '/../../../inc/incident-type-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_type_upsert_internal($merged, $userId, $id);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('config', 'update', 'incident_type', $id,
        "External API updated incident type #{$id}",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'request_id'       => $GLOBALS['__ext_api_request_id'] ?? null,
            'changed_fields'   => array_keys($input),
            'via_external_api' => true,
        ]
    );

    ext_api_response(['id' => $id, 'updated' => true]);
}

// ═══════════════════════════════════════════════════════════════
//  DELETE — hard delete by ?id=N
// ═══════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    ext_api_require_scope('incident_types:write');
    if (!rbac_can('action.manage_config')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'action.manage_config']);
    }

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) ext_api_error('invalid_id', 400);

    // Pre-flight: confirm the row exists so we can 404 cleanly.
    try {
        $existing = db_fetch_one(
            "SELECT `id`, `type` FROM `{$prefix}in_types` WHERE `id` = ?",
            [$id]
        );
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!$existing) ext_api_error('not_found', 404);

    require_once __DIR__ . '/../../../inc/incident-type-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    try {
        $result = incident_type_delete_internal($id, $userId);
    } catch (Exception $e) {
        ext_api_db_error('db_query', $e);
    }
    if (!empty($result['errors'])) {
        ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
    }

    audit_log('config', 'delete', 'incident_type', $id,
        "External API deleted incident type '" . $existing['type'] . "' (#{$id})",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'request_id'       => $GLOBALS['__ext_api_request_id'] ?? null,
            'via_external_api' => true,
        ]
    );

    ext_api_response(['deleted' => true, 'id' => $id]);
}

ext_api_error('method_not_allowed', 405, ['allowed' => ['GET', 'POST', 'PATCH', 'DELETE']]);
