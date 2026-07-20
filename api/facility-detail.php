<?php
/**
 * NewUI v4.0 API - Facility Detail
 *
 * GET /api/facility-detail.php?id=123
 *   Returns a single facility with all related data:
 *   - facility fields joined to fac_types, fac_status
 *   - assigned incidents (as origin or receiving facility)
 *   - transport statistics
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

ini_set('display_errors', '0');

$id     = (int) ($_GET['id'] ?? 0);
$prefix = $GLOBALS['db_prefix'] ?? '';

if ($id <= 0) {
    json_error('Invalid facility ID');
}

// IDOR check — non-admins must be in a group allocated to this facility.
// 404 (not 403) per Constitution rule #27 so existence is not disclosed.
if (!user_can_access_entity('facility', $id)) {
    json_error('Facility not found', 404);
}

// ── Main facility query ──
try {
    $facility = db_fetch_one(
        "SELECT
            `f`.*,
            `ft`.`name`       AS `type_name`,
            `ft`.`icon`       AS `type_icon`,
            `fs`.`status_val` AS `status_name`,
            `fs`.`bg_color`   AS `status_bg`,
            `fs`.`text_color` AS `status_text`
         FROM `{$prefix}facilities` `f`
         LEFT JOIN `{$prefix}fac_types` `ft` ON `f`.`type` = `ft`.`id`
         LEFT JOIN `{$prefix}fac_status` `fs` ON `f`.`status_id` = `fs`.`id`
         WHERE `f`.`id` = ?",
        [$id]
    );
} catch (Exception $e) {
    json_error('Database error: ' . $e->getMessage(), 500);
}

if (!$facility) {
    json_error('Facility not found', 404);
}

// Parse opening_hours
$hours_text = '';
$is_open = null;
$raw_hours = $facility['opening_hours'] ?? '';
if ($raw_hours !== '') {
    $decoded = @unserialize(@base64_decode($raw_hours));
    if (is_array($decoded)) {
        $dow = (int) date('w');
        $today = $decoded[$dow] ?? null;
        if ($today && ($today[0] ?? '') === 'on') {
            $open_t = $today[1] ?? '00:00';
            $close_t = $today[2] ?? '23:59';
            $now_t = date('H:i');
            $is_open = ($now_t >= $open_t && $now_t <= $close_t);
            $hours_text = $open_t . '-' . $close_t;
        } else {
            $is_open = false;
            $hours_text = 'Closed today';
        }
    }
}

$result_facility = [
    'id'            => (int) $facility['id'],
    'name'          => $facility['name'],
    'handle'        => $facility['handle'] ?? '',
    'callsign'      => $facility['callsign'] ?? '',
    'description'   => $facility['description'],
    'street'        => $facility['street'] ?? '',
    'city'          => $facility['city'] ?? '',
    'state'         => $facility['state'] ?? '',
    'lat'           => $facility['lat'] ? (float) $facility['lat'] : null,
    'lng'           => $facility['lng'] ? (float) $facility['lng'] : null,
    'type_id'       => (int) ($facility['type'] ?? 0),
    'type_name'     => $facility['type_name'] ?? '',
    'type_icon'     => $facility['type_icon'] ?? '',
    'status_id'     => (int) ($facility['status_id'] ?? 0),
    'status_name'   => $facility['status_name'] ?? '',
    'status_bg'     => $facility['status_bg'] ?? '#ffffff',
    'status_text'   => $facility['status_text'] ?? '#000000',
    'contact_name'  => $facility['contact_name'] ?? '',
    'contact_email' => $facility['contact_email'] ?? '',
    'contact_phone' => $facility['contact_phone'] ?? '',
    'capab'         => $facility['capab'] ?? '',
    'hide'          => (int) ($facility['hide'] ?? 0),
    'beds_a'        => (int) ($facility['beds_a'] ?? 0),
    'beds_o'        => (int) ($facility['beds_o'] ?? 0),
    'beds_info'     => $facility['beds_info'] ?? '',
    'bed_auto_mode' => (string) ($facility['bed_auto_mode'] ?? 'manual'),
    'status_about'  => $facility['status_about'] ?? '',
    'hours_today'   => $hours_text,
    'is_open'       => $is_open,
    'updated'       => $facility['updated'] ?? '',
    '_by'           => $facility['_by'] ?? '',
    '_on'           => $facility['_on'] ?? '',
];

// ── Assigned incidents (facility as origin or receiving) ──
$assigned_incidents = [];
try {
    $rows = db_fetch_all(
        "SELECT
            `t`.`id` AS `ticket_id`,
            `t`.`scope`,
            `t`.`status`,
            `t`.`severity`,
            `t`.`date` AS `created`,
            `t`.`updated`,
            `it`.`type` AS `type_name`,
            CASE
                WHEN `t`.`facility` = ? THEN 'origin'
                WHEN `t`.`rec_facility` = ? THEN 'receiving'
                ELSE 'unknown'
            END AS `role`
         FROM `{$prefix}ticket` `t`
         LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
         WHERE (`t`.`facility` = ? OR `t`.`rec_facility` = ?)
         ORDER BY `t`.`updated` DESC
         LIMIT 50",
        [$id, $id, $id, $id]
    );

    $status_labels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];
    foreach ($rows as $row) {
        $assigned_incidents[] = [
            'ticket_id'  => (int) $row['ticket_id'],
            'scope'      => $row['scope'],
            'type_name'  => $row['type_name'] ?? '',
            'status'     => (int) $row['status'],
            'status_text' => $status_labels[(int) $row['status']] ?? 'Unknown',
            'severity'   => (int) $row['severity'],
            'role'       => $row['role'],
            'created'    => $row['created'],
            'updated'    => $row['updated'],
        ];
    }
} catch (Exception $e) {
    // incidents query failure is non-fatal
}

// ── Transport statistics ──
$stats = ['total_transports' => 0, 'transports_this_month' => 0];
try {
    $stats['total_transports'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `rec_facility` = ?",
        [$id]
    );
    $stats['transports_this_month'] = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}ticket`
         WHERE `rec_facility` = ? AND `date` >= DATE_FORMAT(NOW(), '%Y-%m-01')",
        [$id]
    );
} catch (Exception $e) {
    // stats failure is non-fatal
}

// GH #75 — recent facility notes. The "Add Note" facility quick-action writes
// to facility_notes, but the detail page previously read only status_about
// (the single latest-status blurb), so every free-text note vanished from
// view. Surface the note history here so added notes are actually visible.
$facility_notes = [];
try {
    $facility_notes = db_fetch_all(
        "SELECT `note`, `detail`, `category`, `username`, `created_at`
           FROM `{$prefix}facility_notes`
          WHERE `facility_id` = ?
          ORDER BY `created_at` DESC, `id` DESC
          LIMIT 50",
        [$id]
    );
} catch (Throwable $e) {
    // facility_notes may not exist on a very old install — degrade to none.
    $facility_notes = [];
}

json_response([
    'facility'           => $result_facility,
    'assigned_incidents' => $assigned_incidents,
    'notes'              => $facility_notes,
    'stats'              => $stats,
]);
