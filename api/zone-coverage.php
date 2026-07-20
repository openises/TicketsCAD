<?php
/**
 * NewUI v4.0 — Zone Coverage payload (Phase 115, GH #64)
 *
 * The VOLUNTEER-facing companion to the dispatcher Net Control board. Answers
 * one question at a glance: how many units are in each zone right now — so a
 * roaming volunteer can decide to meet up or spread out (Eric, #64).
 *
 * GET /api/zone-coverage.php[?ticket_id=N]
 *   → {
 *       event: { id, scope, incident_number } | null,
 *       events: [ {id, label, zone_count} ],        // picker, only if >1 has zones
 *       zones: [ {id, name, code, color, unit_count, units:[{assign_id,callsign,name,lead}]} ],
 *       unassigned: { unit_count, units:[...] },     // active units with no zone yet
 *       me: { assign_id:int|null, current_zone_id:int|null },  // the caller's own unit
 *       caps: { can_set_own_zone:bool }
 *     }
 *
 * If ticket_id is omitted, auto-picks the active event that has zones (open
 * ticket with the most zones, most recent) so a volunteer just opens the page.
 *
 * RBAC: screen.zone_coverage (granted to ALL roles incl. Field Unit).
 * Read-only. Defensive: every optional query degrades, never crashes
 * (mirrors api/net-control.php).
 */
ini_set('display_errors', '0');

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

if (!rbac_can('screen.zone_coverage')) {
    json_error('Insufficient permissions: zone coverage', 403);
}

function _zcov_fetch_all(string $sql, array $params = []): array {
    try { return db_fetch_all($sql, $params); }
    catch (Exception $e) {
        error_log('[zone-coverage] ' . $e->getMessage() . ' :: ' . substr($sql, 0, 180));
        return [];
    }
}
function _zcov_fetch_one(string $sql, array $params = []) {
    try { return db_fetch_one($sql, $params); }
    catch (Exception $e) {
        error_log('[zone-coverage] ' . $e->getMessage() . ' :: ' . substr($sql, 0, 180));
        return null;
    }
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

// ── Which events have zones? (for the auto-pick + optional picker) ──
$eventsWithZones = _zcov_fetch_all(
    "SELECT t.`id`,
            t.`scope`,
            t.`incident_number`,
            t.`status`,
            COUNT(z.`id`) AS zone_count
     FROM `{$prefix}event_zones` z
     JOIN `{$prefix}ticket` t ON t.`id` = z.`ticket_id`
     WHERE (z.`hide` = 0 OR z.`hide` IS NULL)
     GROUP BY t.`id`, t.`scope`, t.`incident_number`, t.`status`
     ORDER BY (t.`status` = 2) DESC, t.`id` DESC"
);

$events = [];
foreach ($eventsWithZones as $ev) {
    $label = trim((string) ($ev['scope'] ?? ''));
    if ($label === '') $label = 'Incident #' . (int) $ev['id'];
    $num = trim((string) ($ev['incident_number'] ?? ''));
    if ($num !== '') $label = $num . ' — ' . $label;
    $events[] = [
        'id'         => (int) $ev['id'],
        'label'      => $label,
        'zone_count' => (int) $ev['zone_count'],
    ];
}

// ── Resolve the target event ──
$ticketId = (int) ($_GET['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    // Auto-pick: the ORDER BY above already put active-with-most-zones first.
    $ticketId = !empty($eventsWithZones) ? (int) $eventsWithZones[0]['id'] : 0;
}

if ($ticketId <= 0) {
    // No event has zones yet — return an empty-but-valid shape for the page.
    json_response([
        'event'      => null,
        'events'     => $events,
        'zones'      => [],
        'unassigned' => ['unit_count' => 0, 'units' => []],
        'me'         => ['assign_id' => null, 'current_zone_id' => null],
        'caps'       => ['can_set_own_zone' => rbac_can('action.set_own_zone') || is_admin()],
    ]);
}

$event = _zcov_fetch_one(
    "SELECT `id`, `scope`, `incident_number` FROM `{$prefix}ticket` WHERE `id` = ?",
    [$ticketId]
);
if (!$event) {
    json_error('Event / incident not found', 404);
}

// ── Zones for this event ──
$zoneRows = _zcov_fetch_all(
    "SELECT `id`, `name`, `code`, `color`, `sort_order`
     FROM `{$prefix}event_zones`
     WHERE `ticket_id` = ? AND (`hide` = 0 OR `hide` IS NULL)
     ORDER BY `sort_order` ASC, `id` ASC",
    [$ticketId]
);
$zones    = [];
$zoneById = [];
foreach ($zoneRows as $z) {
    $zid = (int) $z['id'];
    $zones[$zid] = [
        'id'         => $zid,
        'name'       => (string) $z['name'],
        'code'       => (string) $z['code'],
        'color'      => $z['color'] !== null ? (string) $z['color'] : null,
        'sort_order' => (int) $z['sort_order'],
        'unit_count' => 0,
        'units'      => [],
    ];
    $zoneById[$zid] = true;
}

// ── Active units on this event, with their current zone + lead name. ──
//    Defensive: the zone column may be absent pre-Phase-109 — fall back.
$unitRows = _zcov_fetch_all(
    "SELECT a.`id` AS assign_id, a.`responder_id`, a.`current_zone_id`,
            r.`name` AS name, r.`handle` AS callsign, r.`user_id` AS responder_user_id
     FROM `{$prefix}assigns` a
     LEFT JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
     WHERE a.`ticket_id` = ?
       AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
     ORDER BY r.`handle` ASC, r.`name` ASC",
    [$ticketId]
);
if (empty($unitRows)) {
    $unitRows = _zcov_fetch_all(
        "SELECT a.`id` AS assign_id, a.`responder_id`,
                r.`name` AS name, r.`handle` AS callsign, r.`user_id` AS responder_user_id
         FROM `{$prefix}assigns` a
         LEFT JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
         WHERE a.`ticket_id` = ?
           AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
         ORDER BY r.`handle` ASC, r.`name` ASC",
        [$ticketId]
    );
}

// ── Lead name per unit (top-sorted active roster member), batched. ──
$responderIds = [];
foreach ($unitRows as $u) { $rid = (int) $u['responder_id']; if ($rid > 0) $responderIds[$rid] = true; }
$responderIds = array_keys($responderIds);
$leadByRid = [];
if (!empty($responderIds)) {
    $ph = implode(',', array_fill(0, count($responderIds), '?'));
    $rosterRows = _zcov_fetch_all(
        "SELECT upa.responder_id,
                TRIM(CONCAT(COALESCE(m.first_name,''), ' ', COALESCE(m.last_name,''))) AS name,
                upa.role AS role
         FROM `{$prefix}unit_personnel_assignments` upa
         JOIN `{$prefix}member` m ON m.id = upa.member_id
         WHERE upa.responder_id IN ({$ph}) AND upa.status = 'active'
         ORDER BY (LOWER(upa.role) IN ('team lead','commander','lead')) DESC,
                  upa.assigned_at DESC, upa.id ASC",
        $responderIds
    );
    foreach ($rosterRows as $rr) {
        $rid = (int) $rr['responder_id'];
        if (isset($leadByRid[$rid])) continue;   // first (top-sorted) only
        $nm = trim((string) ($rr['name'] ?? ''));
        if ($nm !== '') $leadByRid[$rid] = $nm;
    }
}

// ── Resolve the caller's OWN unit on this event (for the self-report strip).
//    Path 1: personnel — the member linked to the login user is an active
//    person on a unit assigned here. Path 2: a responder linked directly to
//    the user. First active assign wins. ──
$meAssignId = null;
$meZoneId   = null;
if ($currentUserId > 0) {
    $meRow = _zcov_fetch_one(
        "SELECT a.`id` AS assign_id, a.`current_zone_id`
         FROM `{$prefix}assigns` a
         JOIN `{$prefix}unit_personnel_assignments` upa
              ON upa.responder_id = a.`responder_id` AND upa.status IN ('active','standby')
         JOIN `{$prefix}member` m ON m.id = upa.member_id
         WHERE a.`ticket_id` = ? AND m.`user_id` = ?
           AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
         ORDER BY a.`id` ASC LIMIT 1",
        [$ticketId, $currentUserId]
    );
    if (!$meRow) {
        $meRow = _zcov_fetch_one(
            "SELECT a.`id` AS assign_id, a.`current_zone_id`
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
             WHERE a.`ticket_id` = ? AND r.`user_id` = ?
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`, '%y') = '00')
             ORDER BY a.`id` ASC LIMIT 1",
            [$ticketId, $currentUserId]
        );
    }
    if ($meRow) {
        $meAssignId = (int) $meRow['assign_id'];
        $meZoneId   = isset($meRow['current_zone_id']) && (int) $meRow['current_zone_id'] > 0
                        ? (int) $meRow['current_zone_id'] : null;
    }
}

// ── Bucket units into zones (+ unassigned). ──
$unassigned = ['unit_count' => 0, 'units' => []];
foreach ($unitRows as $u) {
    $rid = (int) $u['responder_id'];
    $zid = isset($u['current_zone_id']) ? (int) $u['current_zone_id'] : 0;
    $unit = [
        'assign_id' => (int) $u['assign_id'],
        'callsign'  => trim((string) ($u['callsign'] ?? '')),
        'name'      => trim((string) ($u['name'] ?? '')) !== ''
                         ? trim((string) $u['name']) : ('Unit #' . $rid),
        'lead'      => $leadByRid[$rid] ?? null,
    ];
    if ($zid > 0 && isset($zones[$zid])) {
        $zones[$zid]['units'][]     = $unit;
        $zones[$zid]['unit_count'] += 1;
    } else {
        $unassigned['units'][]     = $unit;
        $unassigned['unit_count'] += 1;
    }
}

json_response([
    'event' => [
        'id'              => (int) $event['id'],
        'scope'           => (string) ($event['scope'] ?? ''),
        'incident_number' => (string) ($event['incident_number'] ?? ''),
    ],
    'events'     => count($events) > 1 ? $events : [],
    'zones'      => array_values($zones),
    'unassigned' => $unassigned,
    'me'         => ['assign_id' => $meAssignId, 'current_zone_id' => $meZoneId],
    'caps'       => ['can_set_own_zone' => rbac_can('action.set_own_zone') || is_admin()],
]);
