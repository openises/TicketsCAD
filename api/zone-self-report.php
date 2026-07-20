<?php
/**
 * NewUI v4.0 — Zone Self-Report (Phase 115, GH #64)
 *
 * Lets a roaming volunteer report THEIR OWN unit's zone in one tap from the
 * Zone Coverage board — no radio round-trip. It writes through the exact same
 * Phase 109 path as the dispatcher's /z command (zone + check-in timestamp +
 * ICS-214 note + audit + SSE), so a self-report is indistinguishable downstream
 * from a net-control entry.
 *
 * POST /api/zone-self-report.php   (JSON body)
 *   { ticket_id, zone_id, csrf_token }
 *   zone_id = 0 / null clears the caller's zone.
 *
 * SECURITY: the target assignment is resolved SERVER-SIDE from the session
 * user — the client never sends an assign_id or responder_id. A volunteer can
 * therefore only ever move their OWN unit; there is no id to tamper with (no
 * IDOR surface). RBAC: action.set_own_zone. CSRF required.
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
if (!rbac_can('action.set_own_zone')) {
    json_error('Insufficient permissions: report own zone', 403);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_error('Invalid JSON body');
}
if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
    json_error('Invalid CSRF token', 403);
}

$ticketId = (int) ($input['ticket_id'] ?? 0);
$zoneRaw  = $input['zone_id'] ?? null;
$zoneId   = ($zoneRaw === null || $zoneRaw === '') ? 0 : (int) $zoneRaw;
if ($ticketId <= 0) json_error('ticket_id is required');

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($currentUserId <= 0) json_error('Not signed in', 401);

try {
    // ── Resolve the caller's OWN active assignment on this event. Path 1:
    //    personnel (member.user_id → unit_personnel_assignments → responder).
    //    Path 2: responder linked directly to the user. First active wins. ──
    $assign = db_fetch_one(
        "SELECT a.`id`, a.`responder_id`, r.`name` AS name, r.`handle` AS handle
         FROM `{$prefix}assigns` a
         JOIN `{$prefix}unit_personnel_assignments` upa
              ON upa.responder_id = a.`responder_id` AND upa.status IN ('active','standby')
         JOIN `{$prefix}member` m ON m.id = upa.member_id
         LEFT JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
         WHERE a.`ticket_id` = ? AND m.`user_id` = ?
           AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
         ORDER BY a.`id` ASC LIMIT 1",
        [$ticketId, $currentUserId]
    );
    if (!$assign) {
        $assign = db_fetch_one(
            "SELECT a.`id`, a.`responder_id`, r.`name` AS name, r.`handle` AS handle
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
             WHERE a.`ticket_id` = ? AND r.`user_id` = ?
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
             ORDER BY a.`id` ASC LIMIT 1",
            [$ticketId, $currentUserId]
        );
    }
    if (!$assign) {
        json_error('You are not assigned to a unit on this event, so there is no unit to move. '
            . 'Ask net control to add you to a unit first.', 409);
    }
    $assignId = (int) $assign['id'];

    // ── Validate the zone belongs to this event (unless clearing). ──
    $zoneRow = null;
    if ($zoneId > 0) {
        $zoneRow = db_fetch_one(
            "SELECT `id`, `name`, `code`, `color`
             FROM `{$prefix}event_zones`
             WHERE `id` = ? AND `ticket_id` = ?",
            [$zoneId, $ticketId]
        );
        if (!$zoneRow) json_error('Zone not found for this event', 404);
    }

    $handle = trim((string) ($assign['handle'] ?? ''));
    $name   = trim((string) ($assign['name'] ?? ''));
    $unitName = 'Unit #' . (int) $assign['responder_id'];
    if ($handle !== '') {
        $unitName = $handle;
    } elseif ($name !== '') {
        $unitName = $name;
    }
    $zoneName = $zoneRow ? (string) $zoneRow['name'] : null;
    $storeZoneId = $zoneId > 0 ? $zoneId : null;

    // ── Update the assigns row: zone + both timestamps (same as /z). ──
    db_query(
        "UPDATE `{$prefix}assigns`
         SET `current_zone_id` = ?, `zone_updated_at` = NOW(), `last_checkin_at` = NOW()
         WHERE `id` = ?",
        [$storeZoneId, $assignId]
    );

    // ── ICS-214 activity note (ASCII arrow — latin1 legacy `action` table).
    //    Marked "(self-reported)" so the after-action log shows the source. ──
    $noteText = $zoneName !== null
        ? "{$unitName} -> {$zoneName} (self-reported)"
        : "{$unitName} zone cleared (self-reported)";
    try {
        $res = incident_add_note_internal($ticketId, $noteText, $currentUserId);
        if (!empty($res['errors'])) {
            error_log('[zone-self-report] note not written: ' . implode('; ', $res['errors']));
        }
    } catch (Throwable $e) {
        error_log('[zone-self-report] note write threw: ' . $e->getMessage());
    }

    // ── Audit (best-effort). ──
    try {
        audit_log('incident', 'zone_update', 'assigns', $assignId,
            $noteText,
            ['ticket_id' => $ticketId, 'assign_id' => $assignId, 'zone_id' => $storeZoneId,
             'self_reported' => 1]);
    } catch (Throwable $e) { /* non-fatal */ }

    // ── SSE fan-out — same event shape as event-zone-update.php so the
    //    coverage board + net control refresh identically. ──
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
        error_log('[zone-self-report] sse publish failed: ' . $e->getMessage());
    }

    json_response([
        'ok'        => true,
        'assign_id' => $assignId,
        'unit'      => $unitName,
        'zone'      => $zoneRow ? [
            'id'    => (int) $zoneRow['id'],
            'name'  => (string) $zoneRow['name'],
            'code'  => (string) $zoneRow['code'],
            'color' => $zoneRow['color'] !== null ? (string) $zoneRow['color'] : null,
        ] : null,
    ]);
} catch (Throwable $e) {
    json_error_safe('Zone self-report failed', $e, 'zone-self-report');
}
