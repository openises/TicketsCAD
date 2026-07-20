<?php
/**
 * Phase 94 Stage 4b — External API: incident action notes.
 *
 * POST /api/external/v1/incident-actions.php
 *
 *   Body: { "ticket_id": N, "note": "free text" }
 *   Returns 201 { id: <newActionId> }
 *
 * Authenticated by bearer token via _auth.php (Stage 2). Token's scope
 * LIMITS what it can hit; the owning user's RBAC GRANTS the actual
 * capability.
 *
 * Calls inc/incident-write.php's incident_add_note_internal() which is
 * the canonical write path. The audit_log() call at the bottom auto-
 * fires the `incident.note_added` webhook (already mapped in
 * inc/webhooks.php's _audit_to_webhook_event allowlist).
 */

declare(strict_types=1);

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../../inc/rbac.php';
require_once __DIR__ . '/../../../inc/audit.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    ext_api_error('method_not_allowed', 405, ['allowed' => ['POST']]);
}

ext_api_require_scope('incidents:write');
if (!rbac_can('action.edit_incident')) {
    ext_api_error('forbidden_rbac', 403, ['required' => 'action.edit_incident']);
}

$raw   = file_get_contents('php://input');
$input = $raw ? @json_decode($raw, true) : null;
if (!is_array($input)) {
    ext_api_error('invalid_json_body', 400);
}

// Honor either JSON body or $_GET (the dispatcher injects path params:
// /incidents/<id>/actions → $_GET['ticket_id']=<id>). JSON body takes
// precedence so a direct-file POST can still override.
$ticketId = (int) ($input['ticket_id'] ?? $_GET['ticket_id'] ?? 0);
$note     = (string) ($input['note'] ?? '');

if ($ticketId <= 0) {
    ext_api_error('invalid_ticket_id', 400);
}

// Verify ticket exists — cheap pre-check so we can return 404 cleanly
// instead of a DB-error from a downstream FK or stale-id INSERT.
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    $ticket = db_fetch_one(
        "SELECT `id` FROM `{$prefix}ticket` WHERE `id` = ?",
        [$ticketId]
    );
} catch (Exception $e) {
    ext_api_db_error('db_query', $e);
}
if (!$ticket) {
    ext_api_error('not_found', 404, ['resource' => 'ticket', 'id' => $ticketId]);
}

require_once __DIR__ . '/../../../inc/incident-write.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    ext_api_error('auth_user_missing', 500);
}

try {
    $result = incident_add_note_internal($ticketId, $note, $userId);
} catch (Exception $e) {
    ext_api_db_error('db_query', $e);
}

if (!empty($result['errors'])) {
    ext_api_error('validation_failed', 422, ['errors' => $result['errors']]);
}

$newId = (int) $result['id'];

// Audit + webhook fan-out (Stage 5 hooks audit_log to webhook_fire).
// Category/activity/target tuple matches the existing
// _audit_to_webhook_event mapping → 'incident.note_added'.
try {
    audit_log(
        'incident', 'note_add', 'action', $newId,
        "External API added note to incident #{$ticketId}",
        [
            'token_id'         => $GLOBALS['__ext_api_token_id'] ?? null,
            'ticket_id'        => $ticketId,
            'note_preview'     => substr($note, 0, 120),
            'via_external_api' => true,
        ]
    );
} catch (Exception $e) { /* audit failure must not block the response */ }

// Best-effort SSE so the UI updates in real time, mirroring the
// internal api/incident-update.php#add_note path.
try {
    require_once __DIR__ . '/../../../inc/sse.php';
    if (function_exists('sse_publish_for_incident')) {
        sse_publish_for_incident('incident:note', [
            'ticket_id' => $ticketId,
            'action_id' => $newId,
            'via'       => 'external_api',
        ], $ticketId);
    }
} catch (Exception $e) { /* SSE non-fatal */ }

ext_api_response([
    'id'        => $newId,
    'ticket_id' => $ticketId,
], 201);
