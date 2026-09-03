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
require_once __DIR__ . '/../inc/severity.php';

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
//
// Phase 141 (2026-08-17) — org_can_see_ticket() itself was extended in
// place to also allow an active cross-org incident_shares grant; this
// call site needs NO code change to inherit that (plan.md tasks.md 5.7).
require_once __DIR__ . '/../inc/org-scope.php';
require_once __DIR__ . '/../inc/org-sharing.php';
if (!org_can_see_ticket($id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Incident not found', 404);
}

// Phase 141 — resolves to the caller's winning incident_shares row iff
// their visibility into this ticket came from a share rather than
// same-org access; null for Super Admin / same-org / no share.
$shareCtx = org_share_context_for_ticket($id);

// GH#87/GH#88 (2026-08-19) — was a hardcoded 3-entry color map read
// straight from settings; now sourced from the configurable
// severity_levels table (inc/severity.php) so a 4th+ level (or a
// relabeled/recolored one) resolves correctly here too.
$sev_colors = severity_color_map();

$status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

// ── Main incident query ──
// Soft-delete sweep (issue #25 follow-up) — this is the desktop UI's own
// incident detail view; a soft-deleted incident was still served in full
// here (street, contact, narrative — the exact class of leak the two
// endpoints fixed in 1502157 addressed elsewhere), and a dispatcher could
// still act on it. Deleted rows now 404, same as the External API detail
// path.
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
         WHERE `t`.`id` = ?
           AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')",
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

// Phase 132 Step 4 (2026-08-04, GH #16) — current disposition_id + its
// resolved label, read defensively and SEPARATELY from the main query
// above: a pre-migration install (Step 1 not yet run) has neither
// ticket.disposition_id nor the ticket_disposition table, and this must
// degrade to null/false rather than take down the whole incident view
// (CLAUDE.md schema-resilience pattern — the assignments/actions blocks
// below follow the same guarded-block convention). The OFFERED-list for
// the dropdowns is a separate endpoint (api/dispositions-picker.php,
// mirroring api/un-statuses.php) — this is only the incident's OWN
// current value, for immediate display without a second round trip.
$dispositionId       = null;
$dispositionLabel    = null;
$dispositionRetired  = false;
try {
    $dRow = db_fetch_one(
        "SELECT `t`.`disposition_id`, `td`.`status_val`, `td`.`active`
           FROM `{$prefix}ticket` `t`
           LEFT JOIN `{$prefix}ticket_disposition` `td` ON `t`.`disposition_id` = `td`.`id`
          WHERE `t`.`id` = ?",
        [$id]
    );
    if ($dRow && $dRow['disposition_id'] !== null && (int) $dRow['disposition_id'] > 0) {
        $dispositionId      = (int) $dRow['disposition_id'];
        $dispositionLabel   = $dRow['status_val'] ?? null;
        $dispositionRetired = isset($dRow['active']) && (int) $dRow['active'] !== 1;
    }
} catch (Exception $e) {
    // Pre-migration install (no disposition_id column / no
    // ticket_disposition table) — leave the defaults above.
}

// Phase 151 (GH#138) — current primary/responsible unit, read defensively
// and separately from the main query, same guarded-block convention as
// disposition_id above (a pre-migration install has none of these columns).
$primaryResponderId   = null;
$primaryResponderName = null;
$primarySetAt         = null;
$primarySetByName     = null;
try {
    $pRow = db_fetch_one(
        "SELECT `t`.`primary_responder_id`, `t`.`primary_set_at`,
                COALESCE(`r`.`handle`, `r`.`name`) AS `primary_responder_name`,
                `su`.`user` AS `primary_set_by_name`
           FROM `{$prefix}ticket` `t`
           LEFT JOIN `{$prefix}responder` `r` ON `t`.`primary_responder_id` = `r`.`id`
           LEFT JOIN `{$prefix}user` `su` ON `t`.`primary_set_by` = `su`.`id`
          WHERE `t`.`id` = ?",
        [$id]
    );
    if ($pRow && $pRow['primary_responder_id'] !== null && (int) $pRow['primary_responder_id'] > 0) {
        $primaryResponderId   = (int) $pRow['primary_responder_id'];
        $primaryResponderName = $pRow['primary_responder_name'] ?? null;
        $primarySetAt         = $pRow['primary_set_at'] ?? null;
        // dead_control_audit.php check (d), 2026-09-03: ticket.primary_set_by
        // had a write path (incident_set_primary_internal()) but nothing read
        // it anywhere — surfaced here rather than baselined, since "who set
        // this and when" is a genuinely useful thing to show, not a column
        // that only ever needs to exist for accountability.
        $primarySetByName     = $pRow['primary_set_by_name'] ?? null;
    }
} catch (Exception $e) {
    // Pre-migration install (no primary_responder_id column) — leave defaults.
}

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
    'severity_label'      => severity_label($sev), // GH#87/GH#88 — replaces the client's own hardcoded label array
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
    // Phase 132 Step 4 (GH #16) — the incident's own current disposition.
    // null when unset OR on a pre-migration install (guarded lookup
    // above). disposition_retired is true only when the stored value's
    // `active` = 0 — the label still resolves so the UI can show it,
    // badge it as retired, and keep it selected.
    'disposition_id'      => $dispositionId,
    'disposition_label'   => $dispositionLabel,
    'disposition_retired' => $dispositionRetired,
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
    'primary_responder_id'   => $primaryResponderId,
    'primary_responder_name' => $primaryResponderName,
    'primary_set_at'         => $primarySetAt,
    'primary_set_by_name'    => $primarySetByName,
    'primary_unit_mode'      => get_variable('primary_unit_mode') ?: 'off',
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
            // dead_control_audit.php check (c), 2026-08-20: `assigns`.
            // start_miles/on_scene_miles/end_miles/miles are SELECTed
            // above (line ~218-221) alongside `comments` but silently
            // dropped here — the frontend has NOTHING to render because
            // this endpoint never sends them. Legacy tickets/board.php
            // (dispatch form) and tickets/rm/ajax/update_mileage.php
            // (mobile responder screen) both wrote real per-unit
            // odometer readings into these exact columns; no NewUI writer
            // exists for any of them (confirmed: inc/assignment-write.php's
            // assign_create_internal() only sets as_of/status_id/ticket_id/
            // responder_id/user_id/dispatched). `comments`, by contrast,
            // DOES have real frontend rendering already
            // (assets/js/incident-detail.js ~line 698 shows it as an
            // italic quoted line) with no writer either — the UI half of
            // a feature exists, the write half doesn't. This is a genuine
            // capability gap inherited from the rewrite (per-unit dispatch
            // comment + start/on-scene/end mileage tracking), not a tool
            // false positive — see tools/dead_control_phantom_baseline.txt's
            // entry for the full writeup and mileage_log's own note on why
            // it doesn't already cover this (mileage_log is a single
            // manually-typed total, not per-leg odometer capture).
            // Flagged for Eric to prioritize/spec; not built here.
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

// ── Phase 141 (2026-08-17) — cross-org share redaction + audit ──
//
// Only runs when $shareCtx is non-null, i.e. the caller's visibility into
// THIS ticket came from a cross-org share rather than same-org access
// (Super Admin and same-org callers are always null here and this whole
// block is a no-op for them — see tests/test_org_sharing_noop.php).
if ($shareCtx !== null) {
    $tier = $shareCtx['access_tier'];
    // Phase 143 (2026-08-17) -- redaction reads the REDACTION tier, which
    // can genuinely differ from access_tier for a relationship-sourced
    // context (plan.md's "Two independent axes"). Equal to $tier for an
    // incident_shares-sourced context (unchanged behavior); the ?? falls
    // back to $tier defensively if an older-shaped $shareCtx ever reaches
    // here without the key.
    $redactionTier = $shareCtx['redaction_tier'] ?? $tier;

    // Ticket-level fields: view tier gets plan.md's allowlist only
    // (contact/phone/nine_one_one/description/comments/affected/
    // to_address never leave this function for view tier); assist tier
    // is unchanged — same field set a same-org dispatcher gets.
    $result_incident = org_share_redact_ticket_fields($result_incident, $redactionTier);

    // Per-assignment fields: view tier drops crew/comments/distance_km/
    // responder_updated/u2fenr/u2farr (the "never leak a roster" boundary
    // — crew names INDIVIDUAL personnel, distance_km/responder_updated are
    // derived from the responding org's own responder.lat/lng/updated).
    // Unit identity + status timeline survive — "assigned-unit status" is
    // explicitly dispatch-relevant per plan.md.
    $assignments = array_map(
        fn($a) => org_share_redact_assignment_fields($a, $redactionTier),
        $assignments
    );

    // Action log (notes/narrative) is EXCLUDED ENTIRELY at view tier — not
    // field-filtered, because there is no field of an action-log entry
    // that plan.md's allowlist permits ("never... free-text activity-log
    // narrative beyond what dispatch needs"). Assist tier is unchanged.
    if ($redactionTier !== 'assist') {
        $actions = [];
    }

    // "Shared from [Org]" indicator — same annotation shape
    // org_sharing_apply_list_redaction() adds to list rows.
    $owningOrgId = (int) ($incident['org_id'] ?? 0);
    $owningOrgName = null;
    if ($owningOrgId > 0) {
        try {
            $owningOrgName = db_fetch_value(
                "SELECT `name` FROM `{$prefix}organizations` WHERE `id` = ? LIMIT 1",
                [$owningOrgId]
            );
        } catch (Throwable $e) { /* organizations table missing — name stays null */ }
    }
    $result_incident['shared_from_org_id']   = $owningOrgId ?: null;
    $result_incident['shared_from_org_name'] = $owningOrgName;

    // Audit — fired ONLY here (not from any list-shaped endpoint, to avoid
    // flooding the log with one row per dashboard poll interval), and
    // ONLY when the read was genuinely share-mediated.
    if (!function_exists('audit_log') && is_file(__DIR__ . '/../inc/audit.php')) {
        require_once __DIR__ . '/../inc/audit.php';
    }
    if (function_exists('audit_log')) {
        $sharedWithOrgId = (int) $shareCtx['shared_with_org_id'];
        audit_log(
            'incident', 'view_shared', 'ticket', $id,
            "Incident #{$id} opened via cross-org share (org #{$sharedWithOrgId}, {$tier} access / {$redactionTier} redaction tier)",
            ['shared_with_org_id' => $sharedWithOrgId, 'access_tier' => $tier, 'redaction_tier' => $redactionTier],
            defined('AUDIT_INFO') ? AUDIT_INFO : 1
        );
    }
}

// Phase 142 (2026-08-17) — "can I manage sharing OUT of this ticket?"
// Deliberately UNCONDITIONAL (not inside the `if ($shareCtx !== null)`
// block above) — the owning org's own dispatcher, who this field exists
// for, is by definition NOT viewing via a share ($shareCtx is null for
// same-org/Super-Admin callers), so gating this on $shareCtx would make
// it permanently false for the exact audience who needs it true. Display
// only, same caveat every existing display gate in this codebase carries
// — every write is re-checked server-side by api/incident-share.php via
// the same two checks (RBAC + org_ticket_is_owned_by_caller()).
$result_incident['can_manage_sharing'] =
    (function_exists('rbac_can')
        && (rbac_can('action.share_incident') || rbac_can('action.revoke_incident_share')))
    && org_ticket_is_owned_by_caller($id);

// Phase 151 (GH#138) — candidates for the "Primary: [change]" picker: any
// responder with EITHER an assigns row on this ticket, active or cleared
// (a unit that already cleared can still be retroactively marked primary —
// spec.md), so this deliberately does NOT reuse $assignments (which is
// already filtered to active-only above). A separate, guarded query rather
// than widening the assignments query itself, matching the disposition/
// primary-unit blocks' own degrade-gracefully-on-missing-schema pattern.
$primaryCandidates = [];
try {
    $primaryCandidates = db_fetch_all(
        "SELECT DISTINCT `r`.`id`, COALESCE(`r`.`handle`, `r`.`name`) AS `label`
           FROM `{$prefix}assigns` `a`
           JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
          WHERE `a`.`ticket_id` = ?
          ORDER BY `label`",
        [$id]
    );
    foreach ($primaryCandidates as &$pc) {
        $pc['id'] = (int) $pc['id'];
    }
    unset($pc);
} catch (Exception $e) {
    $primaryCandidates = [];
}

ini_set('display_errors', $prevDisplay);

json_response([
    'incident'           => $result_incident,
    'assignments'        => $assignments,
    'actions'            => $actions,
    'primary_candidates' => $primaryCandidates,
]);
