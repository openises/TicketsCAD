<?php
/**
 * NewUI v4.0 API - Responders
 *
 * GET /api/responders.php
 *
 * Returns all responders with their type, status, assignment info, and GPS data.
 */

require_once __DIR__ . '/auth.php';

// Phase 66 (2026-06-14): consult the unit_location_bindings resolver
// for live positions. Without this, OwnTracks data never reaches the
// Responders widget — only the legacy APRS-tracks table was read, so
// clicking a unit fed by OwnTracks fired the "No location available"
// toast even though the data was sitting in location_reports.
require_once __DIR__ . '/../inc/location-resolver.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Unit types lookup
$unit_types = [];
$rows = db_fetch_all("SELECT * FROM `{$prefix}unit_types` ORDER BY `id`");
foreach ($rows as $r) {
    $unit_types[(int) $r['id']] = $r;
}

// Unit status lookup
$statuses = [];
$rows = db_fetch_all("SELECT * FROM `{$prefix}un_status` ORDER BY `id`");
foreach ($rows as $r) {
    $statuses[(int) $r['id']] = $r;
}

// Active assignments: responder_id => list of {assign_id, ticket_id, scope, ...}.
// 2026-06-11 — added lat/lng so unit markers can fall back to the
// incident location when the unit itself has no location source.
// Phase 99n-v2 (2026-06-29) — added assign_id (so the dashboard
// Status hotkey can POST per-assignment status changes via
// api/incident-assign.php?action=update_status) and dispatched/
// responding step timestamps (so the modal can render the unit's
// current step in the dispatched->responding->on_scene->clear
// progression). Order by `dispatched DESC` so most-recently-active
// assignment is first — matches Eric's beta request "sort by most
// recently updated incident assignment".
// Phase 115 (#64) — expose each unit's current EVENT zone so units.php can
// filter the queue by zone. Guarded: the zone columns arrive with Phase 109; on
// an older assigns table we omit them and the zone filter simply doesn't show.
$hasZoneCols = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'current_zone_id'",
    [$prefix . 'assigns']);
$zoneSelect = $hasZoneCols
    ? ", `a`.`current_zone_id`, `z`.`name` AS `zone_name`, `z`.`code` AS `zone_code`, `z`.`color` AS `zone_color`"
    : "";
$zoneJoin = $hasZoneCols
    ? "LEFT JOIN `{$prefix}event_zones` `z` ON `z`.`id` = `a`.`current_zone_id`"
    : "";

$assigns = [];
$zoneByResponder = [];   // rid => {id,name,code,color} of the unit's current zone
$rows = db_fetch_all(
    "SELECT `a`.`id` AS `assign_id`, `a`.`responder_id`, `t`.`scope` AS `ticket_scope`,
            `a`.`ticket_id`, `t`.`lat` AS `ticket_lat`, `t`.`lng` AS `ticket_lng`,
            `a`.`dispatched`, `a`.`responding`, `a`.`on_scene`{$zoneSelect}
     FROM `{$prefix}assigns` `a`
     LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
     {$zoneJoin}
     WHERE (`t`.`status` = 2 OR `t`.`status` = 3)
       AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
     ORDER BY COALESCE(`a`.`on_scene`, `a`.`responding`, `a`.`dispatched`) DESC, `a`.`id` DESC"
);
foreach ($rows as $r) {
    $rid = (int) $r['responder_id'];
    if (!isset($assigns[$rid])) {
        $assigns[$rid] = [];
    }
    $zoneId = $hasZoneCols && isset($r['current_zone_id']) ? (int) $r['current_zone_id'] : 0;
    $assigns[$rid][] = [
        'assign_id'    => (int) $r['assign_id'],
        'ticket_id'    => (int) $r['ticket_id'],
        'ticket_scope' => $r['ticket_scope'],
        'ticket_lat'   => (float) ($r['ticket_lat'] ?? 0),
        'ticket_lng'   => (float) ($r['ticket_lng'] ?? 0),
        'dispatched'   => $r['dispatched'],
        'responding'   => $r['responding'],
        'on_scene'     => $r['on_scene'],
        'zone_id'      => $zoneId > 0 ? $zoneId : null,
        'zone_name'    => $zoneId > 0 ? (string) ($r['zone_name'] ?? '') : null,
    ];
    // First (top-sorted) assignment carrying a zone wins as the unit's current
    // zone — matches the single-active-event reality of the net-control op.
    if ($zoneId > 0 && !isset($zoneByResponder[$rid])) {
        $zoneByResponder[$rid] = [
            'id'    => $zoneId,
            'name'  => (string) ($r['zone_name'] ?? ''),
            'code'  => (string) ($r['zone_code'] ?? ''),
            'color' => $r['zone_color'] !== null ? (string) $r['zone_color'] : null,
        ];
    }
}

// User group filtering — admins (level 0,1) see all; others filtered by allocates
$user_groups = $_SESSION['user_groups'] ?? [];
$is_admin = is_admin();
$group_filter = '';
$params = [];
// Soft-delete filter: exclude deleted responders (graceful if column doesn't exist)
$softDeleteFilter = '';
try {
    $colCheck = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [$prefix . 'responder']
    );
    if ($colCheck) {
        $softDeleteFilter = "`r`.`deleted_at` IS NULL";
    }
} catch (Exception $e) {}

// RBAC-aware bypass: if the user holds the screen.responders or the
// canonical responder.view permission, they have explicit grant to
// view responders via RBAC and the legacy allocates filter is skipped.
// See api/incidents.php for the same pattern (deployed 2026-05-26 after
// shoreas demo-user couldn't see any responders despite Operator role).
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/par.php';  // Phase 17 (2026-06-11) — par_due_at()
$rbacResponderView = (function_exists('rbac_can')
    && (rbac_can('screen.responders') || rbac_can('responder.view') || rbac_can('widget.responders')));

if ($is_admin || $rbacResponderView) {
    $group_filter = $softDeleteFilter ? "WHERE " . $softDeleteFilter : "";
} elseif (!empty($user_groups)) {
    $placeholders = implode(',', array_fill(0, count($user_groups), '?'));
    $group_filter = "WHERE `a`.`group` IN ({$placeholders}) AND `a`.`type` = 2";
    if ($softDeleteFilter) $group_filter .= " AND " . $softDeleteFilter;
    $params = $user_groups;
} else {
    // Non-admin, no RBAC view perm, no legacy groups — show nothing.
    $group_filter = "WHERE 1=0";
}

// Phase 54 / 58 (2026-06-14) — personal units filter.
//   Default       : show only CLOCKED-IN personal units (status name
//                   isn't on the off-list). Hidden personal units
//                   would clutter the board with people who aren't
//                   actually working right now.
//   ?include_personal=all : show every personal unit including the
//                   clocked-out ones (admin "where is everyone" view).
//   ?include_personal=none: hide every personal unit (legacy behavior
//                   for views that should never show 1-person resources,
//                   e.g. equipment-only reports).
// personal_for_member_id is added on demand by inc/personnel-units.php;
// check column existence before referencing it so older installs don't 500.
$includePersonal = $_GET['include_personal'] ?? '';   // '', '1', 'all', 'none'
if ($includePersonal === '1' || $includePersonal === 'all') $includePersonal = 'all';
elseif ($includePersonal === 'none') $includePersonal = 'none';
else $includePersonal = 'active';   // default

$personalColExists = false;
try {
    $col = db_fetch_one(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'personal_for_member_id'",
        [$prefix . 'responder']
    );
    $personalColExists = !empty($col);
} catch (Exception $e) { /* assume not */ }

if ($personalColExists && $includePersonal === 'none') {
    // Hide every personal unit regardless of status.
    $personalFilter = '`r`.`personal_for_member_id` IS NULL';
} elseif ($personalColExists && $includePersonal === 'active') {
    // Show only personal units that are clocked-in (un_status_id resolves
    // to something NOT in the off list). Personal units that are clocked-out
    // are hidden; non-personal units are unaffected. The NOT EXISTS subquery
    // returns true (= keep the row) for non-personal units (no personal_for_
    // member_id) AND for personal units whose current status is NOT a known
    // off label.
    $offWords = ['inactive', 'released', 'off-duty', 'off duty',
                 'unavailable', 'out of service', 'out-of-service'];
    $offLikes = [];
    foreach ($offWords as $w) { $offLikes[] = "LOWER(`ms`.`status_val`) LIKE '%" . $w . "%'"; }
    $offWhere = implode(' OR ', $offLikes);
    // QA #13 — responder.un_status_id references un_status, NOT member_status
    // (a separate table, empty on fresh installs). Joining member_status meant
    // the off-word match never fired, so clocked-out personal units were never
    // hidden from the board. un_status also has the status_val column used below.
    $personalFilter = '(`r`.`personal_for_member_id` IS NULL
                       OR NOT EXISTS (
                          SELECT 1 FROM `' . $prefix . 'un_status` `ms`
                           WHERE `ms`.`id` = `r`.`un_status_id`
                             AND (' . $offWhere . ')))';
} else {
    $personalFilter = null;  // ?include_personal=all → no filter
}

if ($personalFilter) {
    if (strpos($group_filter, 'WHERE') === 0) {
        $group_filter .= ' AND ' . $personalFilter;
    } else {
        $group_filter = 'WHERE ' . $personalFilter;
    }
}

// Phase 99j-6 (Billy beta 2026-06-29) — org-scope filter.
// responder.org_id is added on the fly for older installs that
// were never multi-tenant aware. Super Admin → no filter; Org
// Admin → own + descendant orgs; ordinary → home org.
require_once __DIR__ . '/../inc/org-scope.php';
ensure_org_id_column('responder');
[$orgFrag, $orgVars] = org_query_filter('r.org_id');
if ($orgFrag !== '') {
    if (strpos($group_filter, 'WHERE') === 0) {
        $group_filter .= $orgFrag;
    } else {
        $group_filter = 'WHERE 1=1' . $orgFrag;
    }
    $params = array_merge($params, $orgVars);
}

// Main responder query
// GH #66 + GH #68r2 — per-status hide-from-boards flag and explicit
// units-filter bucket. Columns arrive via sql/run_gh66_hide_from_board.php
// and sql/run_gh68_units_filter_class.php; emit safe defaults on
// pre-migration schemas so the listing query never breaks.
$hideBoardSelect = "0 AS `hide_from_board`, '' AS `units_filter`,";
try {
    $usCols = [];
    foreach (db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME IN ('hide_from_board', 'units_filter')",
        [$prefix . 'un_status']) as $c) {
        $usCols[$c['COLUMN_NAME']] = true;
    }
    $hideBoardSelect = (isset($usCols['hide_from_board'])
            ? "COALESCE(`us`.`hide_from_board`, 0) AS `hide_from_board`,"
            : "0 AS `hide_from_board`,")
        . (isset($usCols['units_filter'])
            ? " COALESCE(`us`.`units_filter`, '') AS `units_filter`,"
            : " '' AS `units_filter`,");
} catch (Exception $e) { /* keep the literal defaults */ }

$sql = "SELECT
    `r`.`id` AS `id`,
    `r`.`name`,
    `r`.`handle`,
    `r`.`callsign`,
    `r`.`description`,
    `r`.`street`,
    `r`.`city`,
    `r`.`lat`,
    `r`.`lng`,
    `r`.`type` AS `type_id`,
    `r`.`un_status_id` AS `status_id`,
    `ut`.`icon` AS `icon`,
    `r`.`icon_str`,
    `r`.`contact_via`,
    `r`.`smsg_id`,
    `r`.`ring_fence`,
    `r`.`excl_zone`,
    `r`.`status_about`,
    `r`.`updated`,
    `r`.`status_updated`,
    `ut`.`name` AS `type_name`,
    `us`.`status_val` AS `status_name`,
    `us`.`description` AS `status_description`,
    `us`.`hide` AS `status_hide`,
    `us`.`group` AS `status_group`,
    -- Phase 104j (a beta tester GH #9) — carry the mapped un_status's
    -- bg_color / text_color through so widgets can paint each
    -- responder row in its configured status colour without a
    -- second round-trip. Mobile.php already does this; the
    -- dashboard responders widget was missing it.
    `us`.`bg_color`   AS `status_bg_color`,
    `us`.`text_color` AS `status_text_color`,
    {$hideBoardSelect}
    (SELECT COUNT(*) FROM `{$prefix}assigns`
     WHERE `responder_id` = `r`.`id`
       AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
     AS `active_assignments`
FROM `{$prefix}responder` `r`
LEFT JOIN `{$prefix}allocates` `a` ON `r`.`id` = `a`.`resource_id`
LEFT JOIN `{$prefix}unit_types` `ut` ON `r`.`type` = `ut`.`id`
LEFT JOIN `{$prefix}un_status` `us` ON `r`.`un_status_id` = `us`.`id`
{$group_filter}
GROUP BY `r`.`id`
ORDER BY `active_assignments` DESC, `r`.`handle` ASC, `r`.`name` ASC";

$rows = db_fetch_all($sql, $params);

// Eric 2026-07-03 — enrich each responder row with the principal
// person assigned (Team Lead if present, else the first active
// member) so the situation Units tab can show "who to call for
// Team Delta." Best-effort second pass; a schema without
// unit_personnel_assignments / personnel silently skips.
$responderIds = array_column($rows, 'id');
if (!empty($responderIds)) {
    try {
        $rphs = implode(',', array_fill(0, count($responderIds), '?'));
        // ORDER: role='Team Lead' wins first; among ties, most-
        // recently assigned; among further ties, lowest personnel id
        // for determinism. LIMIT 1 per unit via the correlated
        // subquery pattern.
        // Schema audit 2026-07-07: there is no `personnel` table — the
        // people table is `member` (first_name/last_name/callsign). The
        // old JOIN threw, the catch swallowed it, and units never showed
        // their assigned person on any install.
        $primaries = db_fetch_all(
            "SELECT upa.responder_id,
                    p.id AS person_id,
                    NULLIF(TRIM(CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, ''))), '')
                        AS person_name,
                    p.callsign AS person_handle, upa.role AS person_role
             FROM `{$prefix}unit_personnel_assignments` upa
             JOIN `{$prefix}member` p ON p.id = upa.member_id
             WHERE upa.responder_id IN ({$rphs})
               AND upa.status = 'active'
               AND upa.id = (
                   SELECT upa2.id
                   FROM `{$prefix}unit_personnel_assignments` upa2
                   WHERE upa2.responder_id = upa.responder_id
                     AND upa2.status = 'active'
                   ORDER BY (upa2.role = 'Team Lead') DESC,
                            upa2.assigned_at DESC,
                            upa2.id ASC
                   LIMIT 1
               )",
            $responderIds
        );
        $primaryByRid = [];
        foreach ($primaries as $p) {
            $primaryByRid[(int) $p['responder_id']] = [
                'name'   => $p['person_name'],
                'handle' => $p['person_handle'],
                'role'   => $p['person_role'],
            ];
        }
        foreach ($rows as &$row) {
            $rid = (int) $row['id'];
            $row['primary_person'] = $primaryByRid[$rid] ?? null;
        }
        unset($row);
    } catch (Exception $e) {
        // unit_personnel_assignments / personnel absent on legacy
        // installs — skip silently. Every downstream caller reads
        // primary_person defensively (?? null).
        error_log('[responders.primary] ' . $e->getMessage());
    }
}

// 2026-06-28 — was N+1 (one query per responder with a callsign).
// Now batched: single MAX-per-source query gets the latest packet_date
// per callsign, then a single JOIN fetches the latest row for each.
$track_data = [];
$callsigns = array_filter(array_column($rows, 'callsign'));
if (!empty($callsigns)) {
    $placeholders = implode(',', array_fill(0, count($callsigns), '?'));
    try {
        // Single batched query: latest track row per source.
        // Uses a self-join on (source, MAX(packet_date)) which is
        // the standard "greatest-n-per-group" pattern.
        $tracks = db_fetch_all(
            "SELECT t.`source`, t.`latitude`, t.`longitude`, t.`speed`, t.`course`, t.`packet_date`
               FROM `{$prefix}tracks` t
              INNER JOIN (
                    SELECT `source`, MAX(`packet_date`) AS max_pd
                      FROM `{$prefix}tracks`
                     WHERE `source` IN ({$placeholders})
                     GROUP BY `source`
              ) m ON m.`source` = t.`source` AND m.`max_pd` = t.`packet_date`",
            array_values($callsigns)
        );
        // Map source-callsign → row, then back to responder_id.
        $bySource = [];
        foreach ($tracks as $tr) $bySource[$tr['source']] = $tr;
        foreach ($rows as $r) {
            $cs = $r['callsign'] ?: '';
            if ($cs !== '' && isset($bySource[$cs])) {
                $track_data[(int) $r['id']] = $bySource[$cs];
            }
        }
    } catch (Exception $e) { /* non-fatal */ }
}

// 2026-06-28 — same de-N+1 treatment for par_unit_acks. Was: one
// MAX(acked_at) query per responder. Now: one GROUP BY query.
$acks_by_responder = [];
$resp_ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
if (!empty($resp_ids)) {
    $rPlaceholders = implode(',', array_fill(0, count($resp_ids), '?'));
    try {
        $ackRows = db_fetch_all(
            "SELECT `responder_id`, MAX(`acked_at`) AS last_ack
               FROM `{$prefix}par_unit_acks`
              WHERE `responder_id` IN ({$rPlaceholders})
                AND `state` = 'acked'
              GROUP BY `responder_id`",
            $resp_ids
        );
        foreach ($ackRows as $ar) {
            $acks_by_responder[(int) $ar['responder_id']] = $ar['last_ack'];
        }
    } catch (Exception $e) { /* non-fatal */ }
}

$responders = [];
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $type_id = (int) $row['type_id'];
    $ut = $unit_types[$type_id] ?? null;
    $track = $track_data[$id] ?? null;

    // Phase 17 (2026-06-11) — PAR-derived timer fields for the
    // units page columns Eric asked for.
    //   par_last_checkin_at: when this unit last had any activity
    //                        recorded (status update / location track
    //                        / acked PAR cycle). Used by the "time
    //                        since last activity" column.
    //   par_next_due_at:     Unix-timestamp when the next PAR cycle
    //                        is due for this unit's earliest active
    //                        incident assignment. Null when not
    //                        assigned OR PAR disabled OR cadence=0.
    $lastTs = null;
    if (!empty($row['status_updated'])) $lastTs = strtotime($row['status_updated']);
    if (!empty($row['updated'])) {
        $u = strtotime($row['updated']);
        if ($u && (!$lastTs || $u > $lastTs)) $lastTs = $u;
    }
    if ($track && !empty($track['packet_date'])) {
        $t = strtotime($track['packet_date']);
        if ($t && (!$lastTs || $t > $lastTs)) $lastTs = $t;
    }
    // 2026-06-28 — read from the pre-batched ack lookup (was N+1).
    $aack = $acks_by_responder[$id] ?? null;
    if ($aack) {
        $a = strtotime($aack);
        if ($a && (!$lastTs || $a > $lastTs)) $lastTs = $a;
    }

    // Compute next PAR due across this unit's currently-assigned active
    // tickets. Use the existing par_due_at helper — but only if PAR
    // file is available + enabled.
    $nextDue = null;
    if (!empty($assigns[$id]) && function_exists('par_due_at') && function_exists('par_enabled') && par_enabled()) {
        foreach ($assigns[$id] as $assigned) {
            // 2026-06-11 fix — assigned is the {ticket_id, scope, ...}
            // array, not a bare ticket id.
            $d = par_due_at((int) ($assigned['ticket_id'] ?? 0));
            if ($d !== null && (!$nextDue || $d < $nextDue)) $nextDue = $d;
        }
    }

    // Phase 66 — Effective location, priority order:
    //   (1) unit_location_bindings resolver (covers OwnTracks, Meshtastic,
    //       OpenGTS, any binding-backed provider — including stale fixes,
    //       because a known-old position still beats "none")
    //   (2) legacy APRS tracks table (`tracks` keyed by callsign)
    //   (3) responder's own stored lat/lng (Location form on unit-edit)
    //   (4) most recent active assignment's incident location
    $resolved = null;
    try {
        $resolved = location_resolve_unit($id);
    } catch (Exception $e) {
        $resolved = null;
    }

    $effLat = 0.0;
    $effLng = 0.0;
    $locationSource = 'none';
    $effSpeed   = null;
    $effCourse  = null;
    $effLastAt  = null;
    $effIsFresh = null;

    if ($resolved && $resolved['lat'] !== null && $resolved['lng'] !== null) {
        $effLat        = (float) $resolved['lat'];
        $effLng        = (float) $resolved['lng'];
        $locationSource = $resolved['provider_code'] ?? 'binding';
        $effSpeed      = isset($resolved['speed'])  ? (float) $resolved['speed']  : null;
        $effCourse     = isset($resolved['heading']) ? (float) $resolved['heading'] : null;
        $effLastAt     = $resolved['received_at'] ?? null;
        $effIsFresh    = (int) ($resolved['is_fresh'] ?? 0);
    } elseif ($track) {
        $effLat        = (float) $track['latitude'];
        $effLng        = (float) $track['longitude'];
        $locationSource = 'track';
        $effSpeed      = (float) $track['speed'];
        $effCourse     = (float) $track['course'];
        $effLastAt     = $track['packet_date'];
    } elseif ($row['lat'] && $row['lng']) {
        $effLat        = (float) $row['lat'];
        $effLng        = (float) $row['lng'];
        $locationSource = 'unit';
    }

    if ((!$effLat || !$effLng) && !empty($assigns[$id])) {
        // assigns[id] is already ORDER BY a.id DESC — first row is most recent.
        foreach ($assigns[$id] as $assigned) {
            $tlat = (float) ($assigned['ticket_lat'] ?? 0);
            $tlng = (float) ($assigned['ticket_lng'] ?? 0);
            if ($tlat && $tlng) {
                $effLat = $tlat;
                $effLng = $tlng;
                $locationSource = 'incident';
                break;
            }
        }
    }

    $responders[] = [
        'id'                 => $id,
        'name'               => $row['name'],
        'handle'             => $row['handle'],
        'callsign'           => $row['callsign'],
        'description'        => $row['description'],
        // Eric 2026-07-07 (EOC Units tab): the situation board needs the
        // unit's base location and its primary person. primary_person was
        // enriched onto the intermediate rows but never copied into this
        // output mapping — the browser never saw it.
        'street'             => $row['street'] ?? '',
        'city'               => $row['city'] ?? '',
        'primary_person'     => $row['primary_person'] ?? null,
        'lat'                => $effLat,
        'lng'                => $effLng,
        'location_source'    => $locationSource,
        'location_is_fresh'  => $effIsFresh,
        'speed'              => $effSpeed,
        'course'             => $effCourse,
        'last_track'         => $effLastAt,
        'type_id'            => $type_id,
        'type_name'          => $row['type_name'],
        'icon'               => $row['icon'],
        'icon_str'           => $row['icon_str'],
        'status_id'          => (int) $row['status_id'],
        'status_name'        => $row['status_name'],
        'status_description' => $row['status_description'],
        'status_group'       => $row['status_group'],
        'status_about'       => $row['status_about'],
        // Phase 104j (a beta tester GH #9) — per-responder status colour so
        // the situation-page responders widget can paint each row in
        // the configured colour (mobile already does).
        'status_bg_color'    => $row['status_bg_color']   ?? '',
        'status_text_color'  => $row['status_text_color'] ?? '',
        // GH #66 — listing surfaces (situation Units tab, dashboard units
        // widget) filter on this; dispatch pickers ignore it.
        'hide_from_board'    => (int) ($row['hide_from_board'] ?? 0),
        // GH #68r2 — explicit filter bucket set by the admin; '' means
        // unclassified and the units-page falls back to name matching.
        'units_filter'       => (string) ($row['units_filter'] ?? ''),
        'contact_via'        => $row['contact_via'],
        'smsg_id'            => $row['smsg_id'],
        'ring_fence'         => (float) ($row['ring_fence'] ?? 0),
        'excl_zone'          => (float) ($row['excl_zone'] ?? 0),
        'active_assignments' => (int) $row['active_assignments'],
        'assigned_tickets'   => $assigns[$id] ?? [],
        // Phase 115 (#64) — the unit's current event zone (from its active
        // assignment), so the Units queue can filter by zone. null = no zone.
        'current_zone'       => $zoneByResponder[$id] ?? null,
        'updated'            => $row['updated'],
        'status_updated'     => $row['status_updated'],
        'par_last_checkin_at' => $lastTs,
        'par_next_due_at'     => $nextDue,
    ];
}

// Phase 99n-v2 (Eric beta 2026-06-29): expose un_status options for
// the dashboard Status hotkey modal so it can render a picker without
// an extra round-trip. Strip the hidden ones at the API boundary.
// Phase 99n-v3 (Eric beta 2026-06-29): also include Phase 95 extra_data_*
// columns so the dashboard knows when to prompt for additional info
// (Transporting -> facility, Out of Service -> note, etc.).
$statusOptions = [];
foreach ($statuses as $sid => $s) {
    $hide = $s['hide'] ?? 'n';
    if ($hide === 'y') continue;
    $statusOptions[] = [
        'id'                  => (int) $s['id'],
        'status_val'          => $s['status_val'],
        'bg_color'            => $s['bg_color']  ?? '',
        'text_color'          => $s['text_color'] ?? '',
        'group'               => $s['group']     ?? '',
        'sort'                => (int) ($s['sort'] ?? 0),
        // Phase 104a (a beta tester GH #19) — surface incident_action so the
        // dashboard Clear button can find the un_status mapped to
        // 'clear' without name-matching heuristics. Empty string if
        // not seeded (see sql/run_phase25_un_status_incident_action.php).
        'incident_action'     => (string) ($s['incident_action']    ?? ''),
        'extra_data_type'     => $s['extra_data_type']     ?? 'none',
        'extra_data_required' => (int) ($s['extra_data_required'] ?? 0),
        'extra_data_label'    => $s['extra_data_label']    ?? null,
        'extra_data_target'   => $s['extra_data_target']   ?? 'action_log',
    ];
}
usort($statusOptions, function ($a, $b) {
    if ($a['sort'] === $b['sort']) return $a['id'] - $b['id'];
    return $a['sort'] - $b['sort'];
});

json_response([
    'responders' => $responders,
    'count'      => count($responders),
    'statuses'   => $statusOptions,
]);
