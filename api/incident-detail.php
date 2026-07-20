<?php
/**
 * NewUI v4.0 API - Incident Detail
 *
 * GET /api/incident-detail.php?id=123
 *   Returns a single incident with all related data:
 *   - ticket fields joined to in_types, facilities, user
 *   - active and cleared assignments joined to responder + un_status
 *   - action log entries joined to user
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$id     = (int) ($_GET['id'] ?? 0);
$prefix = $GLOBALS['db_prefix'] ?? '';

if ($id <= 0) {
    json_error('Invalid incident ID');
}

// IDOR check (F-004) — non-admins must be in a group allocated to this incident.
// 404 (not 403) per Constitution rule #27 — don't leak existence.
if (!user_can_access_entity('incident', $id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

// Phase 99j-4 (Billy beta 2026-06-29) — org-scope gate. Stops an Org
// Admin from URL-hopping to a ticket that belongs to a different
// tenant. Same 404-not-403 convention.
require_once __DIR__ . '/../inc/org-scope.php';
if (!org_can_see_ticket($id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

// Severity color map
$sev_colors = [
    0 => get_variable('sev_0_color') ?: '#00ff00',
    1 => get_variable('sev_1_color') ?: '#ffff00',
    2 => get_variable('sev_2_color') ?: '#ff0000',
];

$status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

// ── Main incident query ──
try {
    $incident = db_fetch_one(
        "SELECT
            `t`.*,
            `it`.`type`        AS `type_name`,
            `it`.`description` AS `type_description`,
            `it`.`protocol`,
            `it`.`group`       AS `type_group`,
            `it`.`set_severity`,
            `f`.`name`         AS `facility_name`,
            `f`.`street`       AS `facility_street`,
            `f`.`city`         AS `facility_city`,
            `f`.`lat`          AS `facility_lat`,
            `f`.`lng`          AS `facility_lng`,
            `rf`.`name`        AS `rec_facility_name`,
            `rf`.`street`      AS `rec_facility_street`,
            `rf`.`city`        AS `rec_facility_city`,
            `rf`.`lat`         AS `rec_facility_lat`,
            `rf`.`lng`         AS `rec_facility_lng`,
            `u`.`user`         AS `created_by_name`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         LEFT JOIN `{$prefix}facilities` `f` ON `t`.`facility` = `f`.`id`
         LEFT JOIN `{$prefix}facilities` `rf` ON `t`.`rec_facility` = `rf`.`id`
         LEFT JOIN `{$prefix}user` `u` ON `t`.`_by` = `u`.`id`
         WHERE `t`.`id` = ?",
        [$id]
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!$incident) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

$sev = (int) $incident['severity'];

$result_incident = [
    'id'                  => (int) $incident['id'],
    // Phase 99m (Eric beta 2026-06-29): admin-configured incident_number
    // (e.g. "26-0062") from the Incident Numbering settings panel.
    // Stored by inc/incident-write.php via incnum_allocate(). Read via
    // t.* so it was already in $incident — was just absent from the
    // projection. NULL on legacy tickets that pre-date the feature.
    'incident_number'     => $incident['incident_number'] ?? null,
    'in_types_id'         => (int) $incident['in_types_id'],
    'scope'               => $incident['scope'],
    'description'         => $incident['description'],
    'street'              => $incident['street'],
    'city'                => $incident['city'],
    'state'               => $incident['state'],
    'phone'               => $incident['phone'],
    'contact'             => $incident['contact'],
    'address_about'       => $incident['address_about'],
    'to_address'          => $incident['to_address'],
    'nine_one_one'        => $incident['nine_one_one'],
    'affected'            => $incident['affected'],
    'comments'            => $incident['comments'],
    'lat'                 => $incident['lat'] ? (float) $incident['lat'] : null,
    'lng'                 => $incident['lng'] ? (float) $incident['lng'] : null,
    'severity'            => $sev,
    'severity_color'      => $sev_colors[$sev] ?? '#ffffff',
    'status'              => (int) $incident['status'],
    'status_text'         => $status_labels[(int) $incident['status']] ?? 'Unknown',
    'created'             => $incident['date'],
    'updated'             => $incident['updated'],
    'problemstart'        => $incident['problemstart'],
    'problemend'          => $incident['problemend'],
    'booked_date'         => $incident['booked_date'],
    'created_by'          => (int) ($incident['_by'] ?? 0),
    'created_by_name'     => $incident['created_by_name'] ?? '',
    'type_name'           => $incident['type_name'] ?? '',
    'type_description'    => $incident['type_description'] ?? '',
    'protocol'            => $incident['protocol'] ?? '',
    'type_group'          => $incident['type_group'] ?? '',
    // 2026-06-26 — Expose raw FK ids so the incident-detail edit form
    // can pre-select the current facility / receiving facility in its
    // dropdowns. Display fields below (facility_name, ...) stay for
    // the read-only renders.
    'facility'            => (int) ($incident['facility'] ?? 0),
    'rec_facility'        => (int) ($incident['rec_facility'] ?? 0),
    'facility_name'       => $incident['facility_name'],
    'facility_street'     => $incident['facility_street'],
    'facility_city'       => $incident['facility_city'],
    'facility_lat'        => $incident['facility_lat'] ? (float) $incident['facility_lat'] : null,
    'facility_lng'        => $incident['facility_lng'] ? (float) $incident['facility_lng'] : null,
    'rec_facility_name'   => $incident['rec_facility_name'],
    'rec_facility_street' => $incident['rec_facility_street'],
    'rec_facility_city'   => $incident['rec_facility_city'],
    'rec_facility_lat'    => $incident['rec_facility_lat'] ? (float) $incident['rec_facility_lat'] : null,
    'rec_facility_lng'    => $incident['rec_facility_lng'] ? (float) $incident['rec_facility_lng'] : null,
];

// ── Assignments ──
$assignments = [];
try {
    $rows = db_fetch_all(
        "SELECT
            `a`.`id`,
            `a`.`responder_id`,
            `a`.`status_id`,
            `a`.`dispatched`,
            `a`.`responding`,
            `a`.`on_scene`,
            `a`.`clear`,
            `a`.`u2fenr`,
            `a`.`u2farr`,
            `a`.`comments`,
            `a`.`start_miles`,
            `a`.`on_scene_miles`,
            `a`.`end_miles`,
            `a`.`miles`,
            `a`.`rec_facility_id`,
            `r`.`name`       AS `responder_name`,
            `r`.`handle`     AS `responder_handle`,
            `r`.`un_status_id` AS `responder_un_status_id`,
            `us`.`status_val` AS `status_name`,
            `us`.`bg_color`,
            `us`.`text_color`
         ,`r`.`lat`        AS `responder_lat`,
            `r`.`lng`        AS `responder_lng`,
            `r`.`updated`    AS `responder_updated`,
            `t`.`lat`        AS `ticket_lat`,
            `t`.`lng`        AS `ticket_lng`,
            `rfa`.`name`     AS `rec_facility_name`
         FROM `{$prefix}assigns` `a`
         LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
         LEFT JOIN `{$prefix}un_status` `us` ON `r`.`un_status_id` = `us`.`id`
         LEFT JOIN `{$prefix}ticket`    `t` ON `a`.`ticket_id`    = `t`.`id`
         LEFT JOIN `{$prefix}facilities` `rfa` ON `a`.`rec_facility_id` = `rfa`.`id`
         WHERE `a`.`ticket_id` = ?
           AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
         ORDER BY `a`.`dispatched` DESC",
        [$id]
    );

    foreach ($rows as $row) {
        $isCleared = !empty($row['clear']) && substr($row['clear'], 0, 4) !== '0000';
        // 2026-06-28 (Eric beta request): compute haversine distance
        // from the incident's lat/lng to the responder's last-known
        // lat/lng (in km). Also stamp the responder.updated timestamp
        // so the UI can flag "stale" units (no recent location data).
        // Units with no lat/lng on either side get distance_km = null
        // — JS sorts those to the bottom + flags them.
        $distanceKm  = null;
        $rLat = isset($row['responder_lat']) ? (float) $row['responder_lat'] : 0.0;
        $rLng = isset($row['responder_lng']) ? (float) $row['responder_lng'] : 0.0;
        $tLat = isset($row['ticket_lat'])    ? (float) $row['ticket_lat']    : 0.0;
        $tLng = isset($row['ticket_lng'])    ? (float) $row['ticket_lng']    : 0.0;
        $haveResp   = ($rLat !== 0.0 || $rLng !== 0.0);
        $haveTicket = ($tLat !== 0.0 || $tLng !== 0.0);
        if ($haveResp && $haveTicket) {
            $distanceKm = _incident_detail_haversine_km($rLat, $rLng, $tLat, $tLng);
        }
        $assignments[] = [
            'id'              => (int) $row['id'],
            'responder_id'    => (int) $row['responder_id'],
            'responder_name'  => $row['responder_name'] ?? '',
            'responder_handle' => $row['responder_handle'] ?? '',
            'status_id'       => (int) $row['status_id'],
            'responder_un_status_id' => (int) ($row['responder_un_status_id'] ?? 0),
            'status_name'     => $row['status_name'] ?? 'Unknown',
            'bg_color'        => $row['bg_color'] ?? '',
            'text_color'      => $row['text_color'] ?? '',
            'dispatched'      => $row['dispatched'],
            'responding'      => $row['responding'],
            'on_scene'        => $row['on_scene'],
            'clear'           => $isCleared ? $row['clear'] : null,
            'cleared'         => $isCleared,
            'u2fenr'          => $row['u2fenr'],
            'u2farr'          => $row['u2farr'],
            'comments'        => $row['comments'] ?? '',
            // Phase 95-plus (2026-06-28) — distance + freshness for the
            // sort + "stale" UI badge. distance_km null = no location
            // data for either responder or ticket. responder_updated
            // is the responder.updated column (last write to the row,
            // which any status / location update touches).
            'distance_km'     => $distanceKm,
            'responder_updated' => $row['responder_updated'] ?? null,
            // Phase 116 — per-unit receiving facility (the destination hospital
            // for THIS unit's transport). 0 / '' when unset. Drives the per-row
            // Destination selector on the incident's assigned-unit list.
            'rec_facility_id'   => (int) ($row['rec_facility_id'] ?? 0),
            'rec_facility_name' => $row['rec_facility_name'] ?? '',
            // Phase 116b (GH #85) — the unit's crew (personnel assigned to this
            // unit). Filled in one batched query below; defaults keep the key
            // present for older installs without unit_personnel_assignments.
            'crew'              => [],
            'crew_count'        => 0,
        ];
    }

    // Phase 116b (GH #85) — attach each ACTIVE unit's crew (the personnel
    // assigned to that unit via unit_personnel_assignments). Dispatching a unit
    // puts its crew on the incident for accountability; the notification layer
    // already targets them (inc/router_recipients.php) — this surfaces them on
    // the incident screen + feeds the PAR head-count. Read-only, one batched
    // query for all units. Guarded so an install without the table degrades to
    // empty crew rather than erroring.
    try {
        $activeRids = [];
        foreach ($assignments as $a) {
            if (empty($a['cleared'])) $activeRids[(int) $a['responder_id']] = true;
        }
        if (!empty($activeRids)) {
            $ids = array_keys($activeRids);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $crewRows = db_fetch_all(
                "SELECT upa.responder_id, m.id AS member_id,
                        m.first_name, m.last_name, m.callsign, upa.role
                   FROM `{$prefix}unit_personnel_assignments` upa
                   JOIN `{$prefix}member` m ON m.id = upa.member_id
                  WHERE upa.responder_id IN ($ph)
                    AND upa.status IN ('active','standby')
                    AND (upa.released_at IS NULL OR DATE_FORMAT(upa.released_at,'%y') = '00')
                  ORDER BY FIELD(upa.role,'commander','operator','driver','medic','observer','trainee','support'),
                           m.last_name, m.first_name",
                $ids
            );
            $crewByRid = [];
            foreach ($crewRows as $cr) {
                $rid = (int) $cr['responder_id'];
                $nm  = trim(((string) ($cr['first_name'] ?? '')) . ' ' . ((string) ($cr['last_name'] ?? '')));
                if ($nm === '') $nm = (string) ($cr['callsign'] ?? '') ?: ('Member #' . (int) $cr['member_id']);
                $crewByRid[$rid][] = [
                    'member_id' => (int) $cr['member_id'],
                    'name'      => $nm,
                    'callsign'  => (string) ($cr['callsign'] ?? ''),
                    'role'      => (string) ($cr['role'] ?? ''),
                ];
            }
            foreach ($assignments as &$a) {
                $rid = (int) $a['responder_id'];
                if (isset($crewByRid[$rid])) {
                    $a['crew']       = $crewByRid[$rid];
                    $a['crew_count'] = count($crewByRid[$rid]);
                }
            }
            unset($a);
        }
    } catch (Exception $e) {
        // Older install without unit_personnel_assignments — leave crew empty.
    }
} catch (Exception $e) {
    // assignments query failure is non-fatal
}

/**
 * Phase 95-plus helper — haversine distance in kilometers between
 * two (lat, lng) pairs. Earth's mean radius = 6371 km.
 * 2026-06-28 — added for the per-incident distance-sort feature.
 */
function _incident_detail_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return round(2 * $R * asin(sqrt($a)), 2);
}

// ── Action log ──
$actions = [];
try {
    $rows = db_fetch_all(
        "SELECT
            `ac`.`id`,
            `ac`.`date`,
            `ac`.`description`,
            `ac`.`action_type`,
            `ac`.`responder` AS `responder_ids`,
            `u`.`user`       AS `user_name`
         FROM `{$prefix}action` `ac`
         LEFT JOIN `{$prefix}user` `u` ON `ac`.`user` = `u`.`id`
         WHERE `ac`.`ticket_id` = ?
         ORDER BY `ac`.`date` DESC",
        [$id]
    );

    foreach ($rows as $row) {
        $actions[] = [
            'id'           => (int) $row['id'],
            'date'         => $row['date'],
            'description'  => $row['description'],
            'action_type'  => (int) ($row['action_type'] ?? 0),
            'user_name'    => $row['user_name'] ?? '',
        ];
    }
} catch (Exception $e) {
    // action log failure is non-fatal
}

ini_set('display_errors', $prevDisplay);

json_response([
    'incident'    => $result_incident,
    'assignments' => $assignments,
    'actions'     => $actions,
]);
