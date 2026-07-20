<?php
/**
 * NewUI v4.0 API - Responder Detail
 *
 * GET /api/responder-detail.php?id=123
 *   Returns a single responder with all related data:
 *   - responder fields joined to unit_types, un_status, facilities
 *   - active and recent assignments joined to ticket + in_types
 *   - summary stats (total calls, avg response time, calls this month)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$id     = (int) ($_GET['id'] ?? 0);
$prefix = $GLOBALS['db_prefix'] ?? '';

if ($id <= 0) {
    json_error('Invalid responder ID');
}

// IDOR check (F-005) — non-admins must be in a group allocated to this responder.
if (!user_can_access_entity('responder', $id)) {
    ini_set('display_errors', $prevDisplay);
    json_error('Responder not found', 404);
}

// ── Main responder query ──
try {
    $responder = db_fetch_one(
        "SELECT
            `r`.*,
            `ut`.`name`        AS `type_name`,
            `us`.`status_val`  AS `status_name`,
            `us`.`description` AS `status_description`,
            `us`.`bg_color`    AS `status_bg_color`,
            `us`.`text_color`  AS `status_text_color`,
            `us`.`dispatch`    AS `status_dispatch`,
            `us`.`group`       AS `status_group`,
            `f`.`name`         AS `facility_name`
         FROM `{$prefix}responder` `r`
         LEFT JOIN `{$prefix}unit_types` `ut` ON `r`.`type` = `ut`.`id`
         LEFT JOIN `{$prefix}un_status` `us` ON `r`.`un_status_id` = `us`.`id`
         LEFT JOIN `{$prefix}facilities` `f` ON `r`.`at_facility` = `f`.`id`
         WHERE `r`.`id` = ?",
        [$id]
    );
} catch (Exception $e) {
    ini_set('display_errors', $prevDisplay);
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!$responder) {
    ini_set('display_errors', $prevDisplay);
    json_error('Responder not found', 404);
}

// Determine active tracking provider
$tracking_map = [
    'aprs'              => 'aprs',
    'instam'            => 'instam',
    'ogts'              => 'ogts',
    't_tracker'         => 't_tracker',
    'mob_tracker'       => 'mob_tracker',
    'xastir_tracker'    => 'xastir',
    'traccar'           => 'traccar',
    'javaprssrvr'       => 'javaprssrvr',
    'locatea'           => 'locatea',
    'gtrack'            => 'gtrack',
    'glat'              => 'glat',
    'followmee_tracker' => 'followmee',
];
$active_tracker = '';
foreach ($tracking_map as $col => $provider) {
    if (!empty($responder[$col]) && (int) $responder[$col] === 1) {
        $active_tracker = $provider;
        break;
    }
}

$result_responder = [
    'id'                => (int) $responder['id'],
    'name'              => $responder['name'] ?? '',
    'handle'            => $responder['handle'] ?? '',
    'callsign'          => $responder['callsign'] ?? '',
    'description'       => $responder['description'] ?? '',
    'street'            => $responder['street'] ?? '',
    'city'              => $responder['city'] ?? '',
    'state'             => $responder['state'] ?? '',
    'lat'               => $responder['lat'] ? (float) $responder['lat'] : null,
    'lng'               => $responder['lng'] ? (float) $responder['lng'] : null,
    'type_id'           => (int) ($responder['type'] ?? 0),
    'type_name'         => $responder['type_name'] ?? '',
    'icon_str'          => $responder['icon_str'] ?? '',
    // Status
    'status_id'         => (int) ($responder['un_status_id'] ?? 0),
    'status_name'       => $responder['status_name'] ?? '',
    'status_description' => $responder['status_description'] ?? '',
    'status_bg_color'   => $responder['status_bg_color'] ?? '',
    'status_text_color' => $responder['status_text_color'] ?? '',
    'status_group'      => $responder['status_group'] ?? '',
    'status_dispatch'   => isset($responder['status_dispatch']) ? (int) $responder['status_dispatch'] : 0,
    'status_about'      => $responder['status_about'] ?? '',
    'status_updated'    => $responder['status_updated'] ?? '',
    // Contact & Messaging
    'phone'             => $responder['phone'] ?? '',
    'cellphone'         => $responder['cellphone'] ?? '',
    'contact_name'      => $responder['contact_name'] ?? '',
    'contact_via'       => $responder['contact_via'] ?? '',
    'smsg_id'           => $responder['smsg_id'] ?? '',
    'pager_p'           => $responder['pager_p'] ?? '',
    'pager_s'           => $responder['pager_s'] ?? '',
    'send_no'           => $responder['send_no'] ?? '',
    // Configuration
    'mobile'            => (int) ($responder['mobile'] ?? 0),
    'multi'             => (int) ($responder['multi'] ?? 0),
    'direcs'            => (int) ($responder['direcs'] ?? 0),
    'capab'             => $responder['capab'] ?? '',
    'other'             => $responder['other'] ?? '',
    'at_facility'       => (int) ($responder['at_facility'] ?? 0),
    'facility_name'     => $responder['facility_name'] ?? '',
    // Tracking
    'tracking_provider' => $active_tracker,
    // Boundaries
    'ring_fence'        => (int) ($responder['ring_fence'] ?? 0),
    'excl_zone'         => (int) ($responder['excl_zone'] ?? 0),
    // Timestamps
    'updated'           => $responder['updated'] ?? '',
];

// ── Active assignments (uncleared) ──
$active_assignments = [];
try {
    $rows = db_fetch_all(
        "SELECT
            `a`.`id`          AS `assign_id`,
            `a`.`ticket_id`,
            `t`.`scope`,
            `t`.`status`      AS `ticket_status`,
            `it`.`type`       AS `type_name`,
            `a`.`dispatched`,
            `a`.`responding`,
            `a`.`on_scene`,
            `a`.`status_id`
         FROM `{$prefix}assigns` `a`
         LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         WHERE `a`.`responder_id` = ?
           AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
         ORDER BY `a`.`dispatched` DESC",
        [$id]
    );

    foreach ($rows as $row) {
        $currentState = 'Dispatched';
        if ($row['on_scene']) {
            $currentState = 'On Scene';
        } elseif ($row['responding']) {
            $currentState = 'Responding';
        }

        $active_assignments[] = [
            'assign_id'  => (int) $row['assign_id'],
            'ticket_id'  => (int) $row['ticket_id'],
            'scope'      => $row['scope'] ?? '',
            'type_name'  => $row['type_name'] ?? '',
            'dispatched' => $row['dispatched'],
            'status'     => $currentState,
        ];
    }
} catch (Exception $e) {
    // non-fatal
}

// ── Recent assignments (cleared, last 50) ──
$recent_assignments = [];
try {
    $rows = db_fetch_all(
        "SELECT
            `a`.`id`          AS `assign_id`,
            `a`.`ticket_id`,
            `t`.`scope`,
            `it`.`type`       AS `type_name`,
            `a`.`dispatched`,
            `a`.`responding`,
            `a`.`on_scene`,
            `a`.`clear`,
            `a`.`u2fenr`,
            `a`.`u2farr`
         FROM `{$prefix}assigns` `a`
         LEFT JOIN `{$prefix}ticket` `t` ON `a`.`ticket_id` = `t`.`id`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         WHERE `a`.`responder_id` = ?
           AND `a`.`clear` IS NOT NULL
           AND DATE_FORMAT(`a`.`clear`,'%y') != '00'
         ORDER BY `a`.`dispatched` DESC
         LIMIT 50",
        [$id]
    );

    foreach ($rows as $row) {
        // Calculate response time in minutes
        $response_time = null;
        if ($row['dispatched'] && $row['on_scene']) {
            $dDisp = strtotime($row['dispatched']);
            $dScene = strtotime($row['on_scene']);
            if ($dDisp && $dScene && $dScene > $dDisp) {
                $response_time = round(($dScene - $dDisp) / 60, 1);
            }
        }

        $recent_assignments[] = [
            'assign_id'     => (int) $row['assign_id'],
            'ticket_id'     => (int) $row['ticket_id'],
            'scope'         => $row['scope'] ?? '',
            'type_name'     => $row['type_name'] ?? '',
            'dispatched'    => $row['dispatched'],
            'responding'    => $row['responding'],
            'on_scene'      => $row['on_scene'],
            'clear'         => $row['clear'],
            'response_time' => $response_time,
        ];
    }
} catch (Exception $e) {
    // non-fatal
}

// ── Stats ──
$stats = [
    'total_calls'       => 0,
    'avg_response_time' => null,
    'calls_this_month'  => 0,
];

try {
    $stats['total_calls'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}assigns` WHERE `responder_id` = ?",
        [$id]
    );
} catch (Exception $e) {}

try {
    $avg = db_fetch_value(
        "SELECT AVG(TIMESTAMPDIFF(MINUTE, `dispatched`, `on_scene`))
         FROM `{$prefix}assigns`
         WHERE `responder_id` = ?
           AND `on_scene` IS NOT NULL
           AND `dispatched` IS NOT NULL
           AND `on_scene` > `dispatched`",
        [$id]
    );
    $stats['avg_response_time'] = $avg !== null ? round((float) $avg, 1) : null;
} catch (Exception $e) {}

try {
    $stats['calls_this_month'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}assigns`
         WHERE `responder_id` = ?
           AND `dispatched` >= DATE_FORMAT(NOW(), '%Y-%m-01')",
        [$id]
    );
} catch (Exception $e) {}

// ── Comm Identifiers (via member link) ──
$comm_identifiers = [];
$member_id = null;
try {
    // Find the member record linked to this responder
    $member = db_fetch_one(
        "SELECT `id` FROM " . db_table('members') . " WHERE `responder_id` = ?",
        [$id]
    );
    if ($member) {
        $member_id = (int) $member['id'];
        $commRows = db_fetch_all(
            "SELECT `mci`.`id`, `mci`.`identifier_value`, `mci`.`is_primary`,
                    `mci`.`notes`, `cm`.`code` AS `mode_code`, `cm`.`name` AS `mode_name`,
                    `cm`.`icon` AS `mode_icon`
             FROM " . db_table('member_comm_identifiers') . " `mci`
             JOIN " . db_table('comm_modes') . " `cm` ON `mci`.`comm_mode_id` = `cm`.`id`
             WHERE `mci`.`member_id` = ?
             ORDER BY `cm`.`sort_order`, `mci`.`is_primary` DESC",
            [$member_id]
        );
        foreach ($commRows as $cr) {
            $comm_identifiers[] = [
                'id'         => (int) $cr['id'],
                'mode_code'  => $cr['mode_code'],
                'mode_name'  => $cr['mode_name'],
                'mode_icon'  => $cr['mode_icon'] ?? '',
                'value'      => $cr['identifier_value'],
                'is_primary' => (int) $cr['is_primary'],
                'notes'      => $cr['notes'] ?? '',
            ];
        }
    }
} catch (Exception $e) {
    // non-fatal — members or comm tables may not exist
}

// ── Personnel assigned to this unit ──
$unit_personnel = [];
try {
    $unit_personnel = db_fetch_all(
        "SELECT upa.`id` AS `assignment_id`, upa.`member_id`, upa.`role`,
                upa.`status`, upa.`assigned_at`, upa.`notes`,
                CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                m.`callsign` AS `member_callsign`,
                m.`phone_cell` AS `member_phone`
         FROM `{$prefix}unit_personnel_assignments` upa
         LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
         WHERE upa.`responder_id` = ? AND upa.`status` != 'released'
         ORDER BY upa.`assigned_at` ASC",
        [$id]
    );
} catch (Exception $e) {
    // Table may not exist yet
}

// ── Resolved location (staleness-aware priority) ──
$resolved_location = null;
try {
    require_once __DIR__ . '/../inc/location-resolver.php';
    $resolved_location = location_resolve_unit($id);
} catch (Exception $e) {
    // Non-fatal — location tables may not exist yet
}

// ── Location bindings for this unit ──
$location_bindings = [];
try {
    $location_bindings = db_fetch_all(
        "SELECT b.`id`, b.`provider_id`, b.`unit_identifier`,
                b.`priority`, b.`active`, b.`source`, b.`assignment_id`,
                lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                lp.`icon` AS `provider_icon`, lp.`color` AS `provider_color`,
                lp.`max_age_seconds`
         FROM `{$prefix}unit_location_bindings` b
         LEFT JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
         WHERE b.`responder_id` = ?
         ORDER BY b.`priority` ASC",
        [$id]
    );
} catch (Exception $e) {
    // Non-fatal
}

ini_set('display_errors', $prevDisplay);

// GH #75 — recent unit notes. Notes added from the dashboard quick-action and
// the unit-detail title bar now persist to responder_notes (api/responder-
// note.php); surface them here so they're visible on the detail page, not
// only via the separate History tab.
$notes = [];
try {
    $notes = db_fetch_all(
        "SELECT `note`, `category`, `by_username`, `created_at`
           FROM `{$prefix}responder_notes`
          WHERE `responder_id` = ? AND `deleted_at` IS NULL
          ORDER BY `created_at` DESC, `id` DESC
          LIMIT 50",
        [$id]
    );
} catch (Throwable $e) {
    $notes = [];
}

json_response([
    'responder'          => $result_responder,
    'active_assignments' => $active_assignments,
    'recent_assignments' => $recent_assignments,
    'stats'              => $stats,
    'member_id'          => $member_id,
    'comm_identifiers'   => $comm_identifiers,
    'unit_personnel'     => $unit_personnel,
    'resolved_location'  => $resolved_location,
    'location_bindings'  => $location_bindings,
    'notes'              => $notes,
]);
