<?php
/**
 * NewUI v4.0 — Event Zone Update (Phase 109 Slice A)
 *
 * The FAST path. Moves one assigned unit into a zone (or clears it),
 * stamps the check-in time, writes an ICS-214 activity note, and fans
 * an SSE event out to the incident's watchers.
 *
 * POST /api/event-zone-update.php   (JSON body)
 *   { ticket_id, assign_id, zone_id, csrf_token }
 *   zone_id = 0 or null clears the unit's zone.
 *
 * RBAC: action.update_zone. CSRF required.
 *
 * Design note (Eric decision #1, 2026-07-04): destination-only — no
 * en-route/arrived phase. Recording the destination IS the fact.
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
// zone_id may be 0/null to clear; keep it nullable.
$zoneRaw  = $input['zone_id'] ?? null;
$zoneId   = ($zoneRaw === null || $zoneRaw === '' ) ? 0 : (int) $zoneRaw;

if ($ticketId <= 0) json_error('ticket_id is required');
if ($assignId <= 0) json_error('assign_id is required');

/**
 * Resolve a responder's display name (handle preferred, then name).
 */
function _ezu_unit_name(?array $assignRow): string {
    if (!$assignRow) return 'Unit';
    $h = trim((string) ($assignRow['handle'] ?? ''));
    if ($h !== '') return $h;
    $n = trim((string) ($assignRow['name'] ?? ''));
    if ($n !== '') return $n;
    $rid = (int) ($assignRow['responder_id'] ?? 0);
    return $rid > 0 ? ('Unit #' . $rid) : 'Unit';
}

try {
    // ── Validate the assignment belongs to this ticket, pull the
    //    responder name in the same query. ──
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

    // ── Validate the zone belongs to this ticket (unless clearing). ──
    $zoneRow = null;
    if ($zoneId > 0) {
        $zoneRow = db_fetch_one(
            "SELECT `id`, `name`, `code`, `color`
             FROM `{$prefix}event_zones`
             WHERE `id` = ? AND `ticket_id` = ?",
            [$zoneId, $ticketId]
        );
        if (!$zoneRow) {
            json_error('Zone not found for this event', 404);
        }
    }

    $unitName = _ezu_unit_name($assign);
    $zoneName = $zoneRow ? (string) $zoneRow['name'] : null;
    $storeZoneId = $zoneId > 0 ? $zoneId : null;

    // ── Update the assigns row: zone + both timestamps. ──
    db_query(
        "UPDATE `{$prefix}assigns`
         SET `current_zone_id` = ?, `zone_updated_at` = NOW(), `last_checkin_at` = NOW()
         WHERE `id` = ?",
        [$storeZoneId, $assignId]
    );

    // ── ICS-214 activity note (wrapped so a note failure never fails
    //    the zone move — this is the operational contract). ──
    //
    // Use an ASCII arrow "->" (not the Unicode "→"): the legacy `action`
    // table is latin1/utf8mb3 on many installs and a 4-byte / non-latin1
    // character makes the INSERT fail with SQLSTATE 22007. ASCII keeps
    // the note landing everywhere so the after-action ICS-214 log is
    // never silently short a zone move.
    $noteText = $zoneName !== null
        ? "{$unitName} -> {$zoneName} (reported via net control)"
        : "{$unitName} zone cleared (reported via net control)";
    try {
        $res = incident_add_note_internal($ticketId, $noteText, (int) $current_user_id);
        if (!empty($res['errors'])) {
            error_log('[event-zone-update] note not written: ' . implode('; ', $res['errors']));
        }
    } catch (Throwable $e) {
        error_log('[event-zone-update] note write threw: ' . $e->getMessage());
    }

    // ── Audit log (best-effort). ──
    try {
        audit_log('incident', 'zone_update', 'assigns', $assignId,
            $noteText,
            ['ticket_id' => $ticketId, 'assign_id' => $assignId, 'zone_id' => $storeZoneId]);
    } catch (Throwable $e) { /* non-fatal */ }

    // ── SSE fan-out (optional — mirror api/incident-assign.php). ──
    try {
        require_once __DIR__ . '/../inc/sse.php';
        if (function_exists('sse_publish_for_incident')) {
            sse_publish_for_incident('responder:status',
                [
                    'ticket_id' => $ticketId,
                    'assign_id' => $assignId,
                    'unit'      => $unitName,
                    'zone_id'   => $storeZoneId,
                    'zone'      => $zoneName,
                    'action'    => 'zone_update',
                ],
                $ticketId);
        }
    } catch (Throwable $e) {
        error_log('[event-zone-update] sse publish failed: ' . $e->getMessage());
    }

    json_response([
        'ok'   => true,
        'zone' => $zoneRow ? [
            'id'    => (int) $zoneRow['id'],
            'name'  => (string) $zoneRow['name'],
            'code'  => (string) $zoneRow['code'],
            'color' => $zoneRow['color'] !== null ? (string) $zoneRow['color'] : null,
        ] : null,
        'assign_id' => $assignId,
        'unit'      => $unitName,
    ]);
} catch (Throwable $e) {
    json_error_safe('Zone update failed', $e, 'event-zone-update');
}
