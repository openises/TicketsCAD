<?php
/**
 * NewUI v4.0 API - Incident Update
 *
 * POST /api/incident-update.php
 *
 * Manages incident status changes and activity notes.
 * Three actions via JSON body 'action' field:
 *
 *   add_note       { ticket_id, note }
 *   update_status  { ticket_id, new_status }  (1=Closed, 2=Open, 3=Scheduled)
 *   update_fields  { ticket_id, fields: { severity, description, ... } }
 *
 * 2026-06-28 — Phase 94 Stage 4j refactor: delegates SQL/business logic
 * to inc/incident-write.php helpers (incident_add_note_internal,
 * incident_update_status_internal, incident_update_fields_internal).
 * Endpoint owns auth/CSRF/RBAC, transition-specific audit category
 * splitting (close vs reopen vs update), SSE event-type mapping,
 * notification fan-out, and JSON response shaping.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/incident-write.php';
require_once __DIR__ . '/../inc/incident-number.php';   // Phase 99p — incnum_display()

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_error('Invalid JSON body');
}

// RBAC permission checks based on action
$action = $input['action'] ?? '';
if ($action === 'add_note' && !rbac_can('action.add_note')) {
    json_error('Insufficient permissions: add notes', 403);
}
if ($action === 'update_status' && !rbac_can('action.close_incident')) {
    json_error('Insufficient permissions: update status', 403);
}
if ($action === 'update_fields' && !rbac_can('action.edit_incident')) {
    json_error('Insufficient permissions: edit incident', 403);
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

// Verify ticket exists and get current state. The helpers will accept
// any int and silently no-op on a missing row, but the dispatcher UI
// expects a friendly 404 here.
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

// Phase 99j-4 (Billy beta 2026-06-29) — org-scope gate. Blocks an Org
// Admin from mutating a ticket that belongs to a different tenant
// even if they construct the POST by hand. Same 404-not-403 to avoid
// confirming the ticket exists.
require_once __DIR__ . '/../inc/org-scope.php';
if (!org_can_see_ticket($ticket_id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Ticket not found', 404);
}

$status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

// ══════════════════════════════════════════════════════════════
// ACTION: add_note
// ══════════════════════════════════════════════════════════════
if ($action === 'add_note') {
    $note = trim($input['note'] ?? '');

    $result = incident_add_note_internal($ticket_id, $note, (int) $current_user_id);
    if (!empty($result['errors'])) {
        ini_set('display_errors', $prevDisplay);
        json_error($result['errors'][0]);
    }

    // Canonical webhook-eligible event: 'incident|note_add|action' →
    // incident.note_added (matches the canonical event-map entry in
    // inc/webhooks.php).
    audit_log('incident', 'note_add', 'action', (int) $result['id'],
        "Added note to incident #{$ticket_id}",
        ['ticket_id' => $ticket_id]);

    require_once __DIR__ . '/../inc/sse.php';
    // Phase 99p — include the case number in the SSE payload so the
    // notification tray renders "Note on incident 26-0062" not "#217".
    sse_publish_for_incident('incident:note',
        ['ticket_id' => $ticket_id, 'incident_number' => incnum_display((int) $ticket_id)],
        $ticket_id);

    ini_set('display_errors', $prevDisplay);
    json_response([
        'success' => true,
        'message' => 'Note added to incident ' . incnum_display((int) $ticket_id),
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: update_status
// ══════════════════════════════════════════════════════════════
elseif ($action === 'update_status') {
    $new_status = (int) ($input['new_status'] ?? 0);

    if (!isset($status_labels[$new_status])) {
        ini_set('display_errors', $prevDisplay);
        json_error('Invalid status. Must be 1 (Closed), 2 (Open), or 3 (Scheduled)');
    }

    $current_status = (int) $ticket['status'];
    if ($current_status === $new_status) {
        // Eric 2026-07-07 — closing an already-closed incident used to be a
        // hard error, which left no UI path to fix a stranded unit: closes
        // that predate the 2026-06-28 auto-clear cascade (e.g. ticket 131 /
        // unit M1 on training) kept their assigns rows open forever. Re-close
        // now runs the cascade as an idempotent heal — in conservative mode,
        // so a status a dispatcher deliberately set since the close survives.
        if ($new_status === 1) {
            $heal = incident_clear_stragglers($ticket_id, (int) $current_user_id, [
                'conservative' => true,
                'action_note'  => 'Lingering assignments cleared (re-close heal)',
            ]);
            if ((int) $heal['cleared_assigns'] > 0) {
                audit_log('incident', 'close', 'ticket', $ticket_id,
                    "Re-close heal on incident #{$ticket_id}: cleared lingering assignments",
                    [
                        'cleared_assigns'  => (int) $heal['cleared_assigns'],
                        'reset_responders' => (int) $heal['reset_responders'],
                    ]);
                require_once __DIR__ . '/../inc/sse.php';
                sse_publish_for_incident('incident:close',
                    ['ticket_id' => $ticket_id, 'incident_number' => incnum_display((int) $ticket_id),
                     'new_status' => 1, 'status_label' => 'Closed'],
                    $ticket_id);
                ini_set('display_errors', $prevDisplay);
                json_response([
                    'success'    => true,
                    'message'    => 'Incident ' . incnum_display((int) $ticket_id)
                        . ' was already Closed — cleared ' . (int) $heal['cleared_assigns']
                        . ' lingering unit assignment(s)',
                    'new_status' => 1,
                ]);
            }
        }
        ini_set('display_errors', $prevDisplay);
        json_error('Incident is already ' . $status_labels[$new_status]);
    }

    $old_label = $status_labels[$current_status] ?? 'Unknown';
    $new_label = $status_labels[$new_status];

    // Pre-validate booked_date here so we return a friendly error before
    // calling the helper (which would also reject it but with a less
    // dispatcher-friendly message).
    $booked = '';
    if ($new_status === 3) {
        $booked = trim((string) ($input['booked_date'] ?? ''));
        if ($booked === '') {
            ini_set('display_errors', $prevDisplay);
            json_error('Booked date is required for scheduled incidents');
        }
    }

    $result = incident_update_status_internal(
        $ticket_id,
        $new_status,
        (int) $current_user_id,
        ['booked_date' => $booked]
    );
    if (!empty($result['errors'])) {
        ini_set('display_errors', $prevDisplay);
        json_error('Failed to update status: ' . $result['errors'][0], 500);
    }

    // Per-incident action-log entry — action_type 10 = "status change"
    // (helper handles action_type=23 for the auto-clear-on-close row).
    try {
        $now = date('Y-m-d H:i:s');
        db_query(
            "INSERT INTO `{$prefix}action` (`ticket_id`, `date`, `description`, `user`, `action_type`, `updated`)
             VALUES (?, ?, ?, ?, 10, ?)",
            [$ticket_id, $now, 'Status changed: ' . $old_label . ' → ' . $new_label, $current_user_id, $now]
        );
    } catch (Exception $e) { /* non-fatal */ }

    // Canonical webhook-eligible events:
    //   close (status=1)  → 'incident|close|ticket'  → incident.closed
    //   reopen (status=2) → 'incident|reopen|ticket' → incident.reopened
    //   schedule (3)      → 'incident|update|ticket' → incident.updated
    $auditActivity = ($new_status === 1) ? 'close' : (($new_status === 2 && $current_status === 1) ? 'reopen' : 'update');
    audit_log('incident', $auditActivity, 'ticket', $ticket_id,
        "Status changed on incident #{$ticket_id}: {$old_label} → {$new_label}",
        [
            'old_status'       => $current_status,
            'new_status'       => $new_status,
            'cleared_assigns'  => (int) $result['cleared_assigns'],
            'reset_responders' => (int) $result['reset_responders'],
        ]);

    require_once __DIR__ . '/../inc/sse.php';
    // Phase 99p — case number stamp on every SSE so all listeners
    // can render the friendly identifier.
    $incNum = incnum_display((int) $ticket_id);
    $sseType = ($new_status === 1) ? 'incident:close' : 'incident:update';
    sse_publish_for_incident($sseType,
        ['ticket_id' => $ticket_id, 'incident_number' => $incNum, 'new_status' => $new_status, 'status_label' => $new_label],
        $ticket_id);

    // ── Fire notification rules (best-effort) ──
    try {
        require_once __DIR__ . '/../inc/notification-engine.php';
        $notifEvent = ($new_status === 1) ? 'incident_close' : 'incident_status';
        notification_check($notifEvent, [
            'ticket_id'        => $ticket_id,
            'scope'            => $ticket['scope'] ?? '',
            'severity'         => 0,
            'old_status'       => $current_status,
            'new_status'       => $new_status,
            'old_status_label' => $old_label,
            'new_status_label' => $new_label,
        ]);
    } catch (Exception $e) {
        error_log('Notification engine error on status change: ' . $e->getMessage());
    }

    ini_set('display_errors', $prevDisplay);
    // Phase 99p — toast uses the case number, not the internal id.
    $displayNum = incnum_display((int) $ticket_id);
    json_response([
        'success'    => true,
        'message'    => 'Incident ' . $displayNum . ' status changed to ' . $new_label,
        'new_status' => $new_status,
    ]);
}

// ══════════════════════════════════════════════════════════════
// ACTION: update_fields
// ══════════════════════════════════════════════════════════════
elseif ($action === 'update_fields') {
    $fields = $input['fields'] ?? [];
    if (!is_array($fields) || empty($fields)) {
        ini_set('display_errors', $prevDisplay);
        json_error('No fields to update');
    }

    $result = incident_update_fields_internal($ticket_id, $fields, (int) $current_user_id);
    if (!empty($result['errors'])) {
        ini_set('display_errors', $prevDisplay);
        // 'no whitelisted fields in request' / 'no fields to update' →
        // user error; everything else → 500
        $first = (string) $result['errors'][0];
        $isUserErr = (strpos($first, 'no whitelisted') !== false || strpos($first, 'no fields') !== false);
        json_error($isUserErr ? 'No valid fields to update' : ('Failed to update fields: ' . $first),
            $isUserErr ? 400 : 500);
    }

    $changed = $result['fields_changed'] ?? [];

    audit_log('incident', 'update', 'ticket', $ticket_id,
        "Updated fields on incident #{$ticket_id}: " . implode(', ', $changed),
        ['fields_changed' => $changed]);

    require_once __DIR__ . '/../inc/sse.php';
    // Phase 99p — case number in SSE payload.
    // API contract audit 2026-07-07: audio-alerts.js plays the high-
    // severity tone on `severity_changed`, which nothing ever published
    // — the tone could never fire. Include the flag + new severity.
    $ssePayload = [
        'ticket_id'       => $ticket_id,
        'incident_number' => incnum_display((int) $ticket_id),
        'fields_changed'  => $changed,
    ];
    if (in_array('severity', $changed, true)) {
        $ssePayload['severity_changed'] = true;
        try {
            $ssePayload['severity'] = (int) db_fetch_value(
                "SELECT `severity` FROM `{$prefix}ticket` WHERE `id` = ?",
                [$ticket_id]
            );
        } catch (Exception $e) { /* tone falls back to no-op */ }
    }
    sse_publish_for_incident('incident:update', $ssePayload, $ticket_id);

    ini_set('display_errors', $prevDisplay);
    // Phase 99p — toast uses the case number.
    $displayNum = incnum_display((int) $ticket_id);
    json_response([
        'success' => true,
        'message' => 'Incident ' . $displayNum . ' updated (' . implode(', ', $changed) . ')',
    ]);
}

// ── Unknown action ──
else {
    ini_set('display_errors', $prevDisplay);
    json_error('Unknown action: ' . $action . '. Valid actions: add_note, update_status, update_fields');
}
