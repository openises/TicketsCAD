<?php
/**
 * NewUI v4.0 API - Create Incident
 *
 * POST /api/incident-create.php
 *   Creates a new ticket/incident in the database.
 *   Expects JSON body with incident fields.
 *   Returns the new ticket ID on success.
 *
 * 2026-06-28 — Phase 94 Stage 4j refactor: delegates the SQL/business
 * logic to inc/incident-write.php :: incident_create_internal(). This
 * endpoint now owns only auth/CSRF/RBAC, the description-required
 * validation (stricter than the helper — preserves the original
 * internal contract), audit/SSE/notification fan-out, and JSON response
 * shaping. The canonical write path is shared with
 * api/external/v1/incidents.php.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/incident-number.php';
require_once __DIR__ . '/../inc/incident-write.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

if (!rbac_can('action.create_incident')) {
    json_error('Insufficient permissions: create incident', 403);
}

// Parse JSON body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// CSRF check
if (empty($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Validate required fields ──
// The helper requires in_types_id and scope. The internal endpoint
// additionally requires description (a stricter contract preserved
// from the pre-refactor flow). Validate description here; let the
// helper validate the rest.
$errors = [];
$description = trim($input['description'] ?? '');
if ($description === '') {
    $errors[] = 'Description is required';
}
if (!empty($errors)) {
    json_response(['errors' => $errors], 422);
}

// ── Pre-fetch incident type row for response (protocol) + auto-severity ──
// (Same SELECT shape as the pre-refactor flow — preserves the notification
// context value for $type_row['name'] which was never populated by the
// query before this refactor. Don't add the `type` column here without a
// matching update to the notification-engine rules tests.)
$in_types_id = (int) ($input['in_types_id'] ?? 0);
$type_row = null;
if ($in_types_id > 0) {
    try {
        $type_row = db_fetch_one(
            "SELECT `set_severity`, `protocol` FROM `{$prefix}in_types` WHERE `id` = ?",
            [$in_types_id]
        );
    } catch (Exception $e) {
        $type_row = null;
    }
}

// ── Delegate the write to the canonical helper ──
try {
    $result = incident_create_internal($input, (int) $current_user_id);
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error creating incident: ' . $e->getMessage(), 500);
}

if (!empty($result['errors'])) {
    ini_set('display_errors', $prevDisplay);
    json_response(['errors' => $result['errors']], 422);
}

$ticket_id = (int) $result['id'];
if (!$ticket_id) {
    ini_set('display_errors', $prevDisplay);
    json_error('Failed to create incident', 500);
}

// QA #11 — link the new incident to a parent Major Incident when one was
// chosen on the New Incident form. incident_create_internal() never consumed
// the `major_incident` field, so the create-time selection was silently
// dropped (the only working link path was a separate api/major-incidents.php
// action=link call). Only link if the user actually holds action.link_major;
// a failure here must never break the incident creation.
$majorId = (int) ($input['major_incident'] ?? 0);
if ($majorId > 0 && function_exists('rbac_can') && rbac_can('action.link_major')) {
    $mlPrefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query(
            "INSERT INTO `{$mlPrefix}newui_major_incident_links`
                (`major_id`, `ticket_id`, `linked_by`, `linked_at`)
             VALUES (?, ?, ?, NOW())",
            [$majorId, $ticket_id, (int) $current_user_id]
        );
    } catch (Throwable $e) {
        error_log('[incident-create] major-incident link failed: ' . $e->getMessage());
    }
}

// ── Capture values used by audit/SSE/notifications ──
$scope         = trim((string) ($input['scope'] ?? ''));
$signal        = trim((string) ($input['signal'] ?? ''));
$severity      = max(0, min(2, (int) ($input['severity'] ?? 0)));
if ($type_row && (int) $type_row['set_severity'] > 0) {
    $severity = (int) $type_row['set_severity']; // mirror auto-set inside helper
}
$status        = (int) ($input['status'] ?? 2);
if (!in_array($status, [1, 2, 3], true)) $status = 2;
$street        = trim((string) ($input['street'] ?? ''));
$city          = trim((string) ($input['city'] ?? ''));
$assign_ids    = $input['assign_responders'] ?? [];
$patientCount  = (int) ($result['patient_count'] ?? 0);

// GH #8 (2026-07-14): the incident|create|ticket audit — which drives the
// webhook + Web Push fan-out — now lives INSIDE incident_create_internal() so
// every create path fires it consistently. Do NOT re-audit it here or the push
// double-fires. (SSE + notification rules below are still this endpoint's job.)

ini_set('display_errors', $prevDisplay);

// ── Publish SSE event (group-scoped per F-007) ──
require_once __DIR__ . '/../inc/sse.php';
sse_publish_for_incident('incident:new', [
    'ticket_id' => $ticket_id,
    'scope'     => $input['scope'] ?? '',
    'address'   => $input['address'] ?? '',
    'severity'  => $input['severity'] ?? 0
], $ticket_id);

// ── Fire notification rules (best-effort) ──
try {
    require_once __DIR__ . '/../inc/notification-engine.php';
    $notifContext = [
        'ticket_id'    => $ticket_id,
        'scope'        => $scope,
        'severity'     => $severity,
        'in_types_id'  => $in_types_id,
        'incident_type' => $type_row['name'] ?? '',
        'street'       => $street,
        'city'         => $city,
    ];
    notification_check('incident_create', $notifContext);

    // Also fire severity_high event for high-severity incidents
    if ($severity >= 2) {
        notification_check('severity_high', $notifContext);
    }
} catch (Exception $e) {
    // Notification failure must never block incident creation
    error_log('Notification engine error on incident create: ' . $e->getMessage());
}

// ── Return success ──
// Phase 99p (Eric beta 2026-06-29) — toast uses the case number,
// not the internal id. New incidents always get a case number
// allocated via incnum_allocate(); the fallback only fires for the
// degenerate case where allocation returned empty.
$displayNum = ($result['incident_number'] ?? '') !== '' ? $result['incident_number'] : ('#' . $ticket_id);
json_response([
    'success'         => true,
    'ticket_id'       => $ticket_id,
    'incident_number' => $result['incident_number'] ?? null,
    'message'         => "Incident {$displayNum} created successfully",
    'protocol'        => $type_row['protocol'] ?? null,
]);
