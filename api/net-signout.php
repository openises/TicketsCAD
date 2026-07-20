<?php
/**
 * NewUI v4.0 — Net Control sign-out / sign-in (Phase 109 Slice B)
 *
 * Signs a unit OUT of an event (drops it into the board's "signed out" tray)
 * or signs it back IN. Sign-out is independent of the unit's global status and
 * its zone — the zone is preserved so signing back in restores the unit to
 * where it was.
 *
 * POST /api/net-signout.php   (JSON body)
 *   { ticket_id, assign_id, action: "signout" | "signin", csrf_token }
 *
 * RBAC: action.update_zone (net control). CSRF required. Writes an ICS-214
 * activity note + audit row; SSE fan-out to the event's watchers.
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/incident-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST required', 405);
}
if (!rbac_can('action.update_zone')) {
    json_error('Insufficient permissions: update zone', 403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_error('Invalid JSON body');
}
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$ticketId = (int) ($input['ticket_id'] ?? 0);
$assignId = (int) ($input['assign_id'] ?? 0);
$act      = (string) ($input['action'] ?? '');
if ($ticketId <= 0) json_error('ticket_id is required');
if ($assignId <= 0) json_error('assign_id is required');
if ($act !== 'signout' && $act !== 'signin') json_error("action must be 'signout' or 'signin'");

function _ns_unit_name(?array $a): string {
    if (!$a) return 'Unit';
    $h = trim((string) ($a['handle'] ?? ''));
    if ($h !== '') return $h;
    $n = trim((string) ($a['name'] ?? ''));
    if ($n !== '') return $n;
    $rid = (int) ($a['responder_id'] ?? 0);
    return $rid > 0 ? ('Unit #' . $rid) : 'Unit';
}

try {
    $assign = db_fetch_one(
        "SELECT `a`.`id`, `a`.`ticket_id`, `a`.`responder_id`,
                `r`.`name` AS `name`, `r`.`handle` AS `handle`
         FROM `{$prefix}assigns` `a`
         LEFT JOIN `{$prefix}responder` `r` ON `r`.`id` = `a`.`responder_id`
         WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?",
        [$assignId, $ticketId]
    );
    if (!$assign) {
        json_error('Assignment not found for this event', 404);
    }

    $unitName = _ns_unit_name($assign);

    if ($act === 'signout') {
        db_query("UPDATE `{$prefix}assigns` SET `signed_out_at` = NOW() WHERE `id` = ?", [$assignId]);
        $noteText = "{$unitName} signed out (net control)";
    } else {
        // Sign back in: clear the flag and stamp a fresh check-in so the unit
        // returns to the board without immediately reading as overdue.
        db_query("UPDATE `{$prefix}assigns` SET `signed_out_at` = NULL, `last_checkin_at` = NOW() WHERE `id` = ?", [$assignId]);
        $noteText = "{$unitName} signed back in (net control)";
    }

    // ICS-214 note (best-effort; never fails the sign-out). ASCII only.
    try {
        $res = incident_add_note_internal($ticketId, $noteText, (int) $current_user_id);
        if (!empty($res['errors'])) {
            error_log('[net-signout] note not written: ' . implode('; ', $res['errors']));
        }
    } catch (Throwable $e) {
        error_log('[net-signout] note write threw: ' . $e->getMessage());
    }

    try {
        audit_log('incident', $act === 'signout' ? 'unit_signout' : 'unit_signin', 'assigns', $assignId,
            $noteText, ['ticket_id' => $ticketId, 'assign_id' => $assignId]);
    } catch (Throwable $e) { /* non-fatal */ }

    try {
        require_once __DIR__ . '/../inc/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('responder:status',
                ['ticket_id' => $ticketId, 'assign_id' => $assignId, 'unit' => $unitName, 'action' => $act],
                $ticketId);
        }
    } catch (Throwable $e) {
        error_log('[net-signout] sse publish failed: ' . $e->getMessage());
    }

    json_response(['ok' => true, 'assign_id' => $assignId, 'unit' => $unitName, 'action' => $act]);
} catch (Throwable $e) {
    json_error_safe('Sign-out failed', $e, 'net-signout');
}
