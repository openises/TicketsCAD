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
    // GH -- reported by an external-API integrator (2026-08-15): neither
    // action.view_incident nor action.view_incidents has ever had a row in
    // the permissions table (tools/rbac_permission_audit.php confirmed both
    // dead app-wide), so this gate was reachable by Super Admin tokens
    // only -- no role configuration could grant a Field Unit / read-only
    // token access to the read-only incident list. incidents.view is the
    // real, already-seeded code the rest of the app uses for exactly this
    // (see screen.incidents/screen.incident_detail's sibling entries and
    // the internal api/incidents.php gate, which had the same dead-code
    // disease on its OWN singular `incident.view` half -- fixed alongside
    // this one, see that file).
    if (!rbac_can('incidents.view')) {
        ext_api_error('forbidden_rbac', 403, ['required' => 'incidents.view']);
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    // Single detail
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) ext_api_error('invalid_id', 400);
        try {
            // Public issue #25 — soft-deleted incidents were served in
            // full from both read paths. `deleted_at` appeared in this
            // file only in the PATCH/DELETE existence guards below, so
            // the file applied soft deletion when deciding whether a
            // write may proceed and not when deciding what to return.
            // Deleted rows now 404, which is the same answer the
            // org-scope check below already gives for a row the token
            // may not see, and matches what the UI shows.
            // Phase 151 (GH#138) — primary_responder_name joined for
            // convenience; primary_responder_id/primary_set_at/
            // primary_set_by already flow through t.* with no code change.
            // Schema-resilience: t.primary_responder_id doesn't exist until
            // sql/run_phase151_primary_unit.php has run, so a hard reference
            // to it in the JOIN (unlike t.* itself, which degrades silently)
            // would break this entire endpoint on a not-yet-migrated
            // install. Falls back to the pre-Phase-151 query on failure.
            try {
                $row = db_fetch_one(
                    "SELECT t.*, it.type AS in_type_name,
                            COALESCE(pr.handle, pr.name) AS primary_responder_name
                     FROM `{$prefix}ticket` t
                     LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
                     LEFT JOIN `{$prefix}responder` pr ON pr.id = t.primary_responder_id
                     WHERE t.id = ?
                       AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')",
                    [$id]
                );
            } catch (Exception $e) {
                $row = db_fetch_one(
                    "SELECT t.*, it.type AS in_type_name
                     FROM `{$prefix}ticket` t
                     LEFT JOIN `{$prefix}in_types` it ON it.id = t.in_types_id
                     WHERE t.id = ?
                       AND (t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')",
                    [$id]
                );
            }
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!$row) ext_api_error('not_found', 404);
        // Phase 99j-8 (Billy beta 2026-06-29) — token's owning user
        // must be able to see this ticket via org scope. Same 404 as
        // a real not-found so the API can't be used to probe ids
        // across tenants.
        //
        // Phase 141 (2026-08-17) — org_can_see_ticket() was extended in
        // place to also allow an active cross-org share; no code change
        // needed here to inherit that. What DOES need code: t.* returns
        // every raw ticket column (contact/phone/description/etc included)
        // — this is exactly the read path a view-tier share must be
        // redacted on, same as the internal incident-detail.php endpoint.
        require_once __DIR__ . '/../../../inc/org-scope.php';
        require_once __DIR__ . '/../../../inc/org-sharing.php';
        if (!org_can_see_ticket($id)) ext_api_error('not_found', 404);
        audit_log('external_api', 'read', 'ticket', $id, "External API GET incident #{$id}",
            ['token_id' => $GLOBALS['__ext_api_token_id'] ?? null]);

        $shareCtx = org_share_context_for_ticket($id);
        if ($shareCtx !== null) {
            $tier = $shareCtx['access_tier'];
            // Phase 143 (2026-08-17) -- redaction reads the REDACTION tier;
            // see inc/org-sharing.php's org_share_context_for_ticket()
            // docblock for why this can differ from access_tier.
            $redactionTier = $shareCtx['redaction_tier'] ?? $tier;
            $row = org_share_redact_ticket_fields($row, $redactionTier);
            $owningOrgId = (int) ($row['org_id'] ?? 0);
            $owningOrgName = null;
            if ($owningOrgId > 0) {
                try {
                    $owningOrgName = db_fetch_value(
                        "SELECT `name` FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1",
                        [$owningOrgId]
                    );
                } catch (Throwable $e) { /* organizations table missing */ }
            }
            $row['shared_from_org_id']   = $owningOrgId ?: null;
            $row['shared_from_org_name'] = $owningOrgName;

            $sharedWithOrgId = (int) $shareCtx['shared_with_org_id'];
            audit_log(
                'incident', 'view_shared', 'ticket', $id,
                "Incident #{$id} opened via cross-org share (org #{$sharedWithOrgId}, {$tier} access / {$redactionTier} redaction tier)",
                ['shared_with_org_id' => $sharedWithOrgId, 'access_tier' => $tier, 'redaction_tier' => $redactionTier],
                defined('AUDIT_INFO') ? AUDIT_INFO : 1
            );
        }

        ext_api_response($row);
    }

    // List
    $limit  = max(1, min(200, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $status = isset($_GET['status']) ? (int) $_GET['status'] : null;
    $since  = isset($_GET['since']) ? trim((string) $_GET['since']) : null;

    // Public issue #25 — same soft-delete term as the detail path above
    // and as every sibling endpoint in this directory (members.php,
    // member-status.php, responders.php, facilities.php). Seeded as the
    // first element so it survives any later rearrangement of the
    // optional filters. There is deliberately no ?include_deleted
    // opt-in — see the note on api/wastebasket.php, which is the
    // permission-gated surface for reading deleted records.
    $where = ["(t.deleted_at IS NULL OR t.deleted_at = '0000-00-00 00:00:00')"];
    $params = [];
    if ($status !== null) { $where[] = 't.status = ?'; $params[] = $status; }
    if ($since)           { $where[] = 't.updated >= ?'; $params[] = $since; }

    // Phase 99j-8 — token's owning user determines visibility.
    // Phase 141 (2026-08-17) — org_ticket_query_filter() is the
    // ticket-specific sibling: widens visibility to cross-org-shared
    // tickets, no-op on a database with no routing rules.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    require_once __DIR__ . '/../../../inc/org-sharing.php';
    [$orgFrag, $orgVars] = org_ticket_query_filter(null, 't');
    if ($orgFrag !== '') {
        $where[] = '(' . preg_replace('/^\s*AND\s+/', '', $orgFrag) . ')';
        $params = array_merge($params, $orgVars);
    }

    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    try {
        $rows = db_fetch_all(
            "SELECT t.id, t.org_id, t.in_types_id, t.scope, t.severity, t.status, t.contact, t.phone,
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

    // Redact/annotate any share-derived row (contact/phone stripped for
    // view tier per plan.md's allowlist; no-op otherwise).
    $rows = org_sharing_apply_list_redaction($rows);

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

    // Phase 151 (GH#138) — primary_responder_id is a DEDICATED action, not
    // part of the generic field-update whitelist (plan.md §8, matching the
    // internal set_rec_facility precedent) -- pulled out of $fields here so
    // it routes through incident_set_primary_internal() (same validation,
    // audit, webhook, and off-mode no-op as the UI path) rather than
    // silently riding along inside an unrelated PATCH. Composable with
    // other fields in the same request: extracted first, remaining fields
    // (if any) still go through the normal path below.
    $primaryFieldPresent = array_key_exists('primary_responder_id', $fields);
    $primaryRequestedId  = $primaryFieldPresent ? $fields['primary_responder_id'] : null;
    unset($fields['primary_responder_id']);

    if (empty($fields) && !$primaryFieldPresent) {
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
    // Phase 141 (2026-08-17) — org_can_mutate_ticket() is the tier-aware
    // WRITE gate: same-org unchanged, a cross-org share only permits this
    // PATCH at 'assist' tier. 'forbidden' (403), not 'not_found' (404),
    // when the caller already has confirmed read visibility — same
    // reasoning as the internal incident-update.php endpoint.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    if (!org_can_mutate_ticket($ticketId)) {
        if (org_can_see_ticket($ticketId)) ext_api_error('forbidden', 403);
        ext_api_error('not_found', 404);
    }

    require_once __DIR__ . '/../../../inc/incident-write.php';
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) ext_api_error('auth_user_missing', 500);

    $fieldsChanged = [];

    if (!empty($fields)) {
        try {
            $result = incident_update_fields_internal($ticketId, $fields, $userId);
        } catch (Exception $e) {
            ext_api_db_error('db_query', $e);
        }
        if (!empty($result['errors'])) {
            ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
        }
        $fieldsChanged = $result['fields_changed'];

        audit_log('incident', 'update', 'ticket', $ticketId,
            "External API updated incident #{$ticketId} (" . implode(', ', $fieldsChanged) . ")",
            [
                'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
                'fields_changed'   => $fieldsChanged,
                'via_external_api' => true,
            ]
        );

        try {
            require_once __DIR__ . '/../../../inc/sse.php';
            if (function_exists('sse_publish_for_incident')) {
                sse_publish_for_incident('incident:update',
                    ['ticket_id' => $ticketId, 'fields_changed' => $fieldsChanged, 'via' => 'external_api'],
                    $ticketId);
            }
        } catch (Exception $e) { /* SSE non-fatal */ }
    }

    $primaryResult = null;
    if ($primaryFieldPresent) {
        if (!rbac_can('action.set_primary_unit')) {
            ext_api_error('forbidden_rbac', 403, ['required' => 'action.set_primary_unit']);
        }
        $primaryRespId = (int) $primaryRequestedId;
        $primaryResult = incident_set_primary_internal($ticketId, $primaryRespId > 0 ? $primaryRespId : null,
            $userId, 'manual', true);
        if (($primaryResult['noop_reason'] ?? '') === 'mode_off') {
            ext_api_error('primary_unit_disabled', 409,
                ['errors' => ['Primary unit tracking is not enabled on this install']]);
        }
        if (!empty($primaryResult['errors'])) {
            ext_api_error('validation_failed', 422, ['errors' => $primaryResult['errors']]);
        }
        $fieldsChanged[] = 'primary_responder_id';

        // audit_log() for the primary-unit change happens INSIDE
        // incident_set_primary_internal() (matching incident_set_
        // disposition_internal()'s own convention of auditing itself
        // rather than leaving it to the caller) — the trailing `true`
        // argument above is what makes that internal audit_log call's
        // detail payload carry via_external_api=true, which is what a
        // real webhook subscriber actually receives.
        try {
            require_once __DIR__ . '/../../../inc/sse.php';
            if (function_exists('sse_publish_for_incident')) {
                sse_publish_for_incident('incident:primary_changed',
                    ['ticket_id' => $ticketId, 'primary_responder_id' => $primaryResult['primary_responder_id'],
                     'via' => 'external_api'],
                    $ticketId);
            }
        } catch (Exception $e) { /* SSE non-fatal */ }
    }

    ext_api_response([
        'id'             => $ticketId,
        'fields_changed' => $fieldsChanged,
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
    //
    // Phase 141 (2026-08-17) — DELIBERATELY uses org_ticket_is_owned_by_
    // caller(), NOT org_can_mutate_ticket(). Soft-deleting a ticket removes
    // it from the OWNING org's own visibility — spec.md reserves that for a
    // 'full' tier that does not exist in Phase 1, so NO share, at ANY tier,
    // may satisfy this gate. This is the one call site in the whole
    // cross-org-sharing sweep where the more-permissive helper is the
    // wrong answer — see plan.md's open-question-2 and
    // org_ticket_is_owned_by_caller()'s own docblock in inc/org-scope.php.
    require_once __DIR__ . '/../../../inc/org-scope.php';
    if (!org_ticket_is_owned_by_caller($ticketId)) ext_api_error('not_found', 404);

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
