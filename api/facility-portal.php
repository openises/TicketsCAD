<?php
/**
 * NewUI v4.0 API — Facility Self-Service Portal (Phase 145, GH#90)
 *
 * The ONE bespoke endpoint backing facility-portal.php. Deliberately a
 * brand-new, narrow surface rather than a reuse of api/incident-list.php
 * or api/facility-capacity.php with a filter bolted on — see
 * inc/facility-scope.php's docblock for why. Every query in this file is
 * scoped server-side to `facility_session_facility_id()`; nothing here
 * ever accepts a client-supplied facility id for reads or writes.
 *
 * GET  ?action=incidents (default) — incidents at/inbound to this
 *      session's own facility, status OPEN or SCHEDULED only (matches
 *      v3's facboard_incidents.php filter).
 * GET  ?action=status               — this facility's own status/
 *      capacity snapshot + the fac_status / capacity_categories lookups
 *      the self-report form needs.
 * POST {action:'set_status', status_id, status_about}
 * POST {action:'set_capacity', category_id, total, available, notes}
 * POST {action:'release_bed', count?, note?} — GH#102: the facility's own
 *      inverse of inc/bed_auto.php's automatic decrement. See
 *      inc/facility-bed-release.php for the full design rationale
 *      (targets beds_a/beds_o — the SAME columns automation touches —
 *      not the separate facility_capacity table; coarse "release N",
 *      floor-at-occupied-count as the safety ceiling, not a per-delivery
 *      undo). Always scoped to $facilityId = facility_session_facility_id()
 *      like every other write in this file — never a client-supplied id.
 *
 * RBAC: screen.facility_portal for GET, action.facility_self_report for
 * POST — both held ONLY by role 7 (Facility) by default (sql/rbac.sql).
 * is_admin() also passes (matches the `_fac_can()` convention in
 * api/facility-action.php) for support/preview purposes; a Super Admin
 * session has facility_session_facility_id() === 0, so it sees an empty,
 * harmless result rather than another facility's data.
 */

ini_set('display_errors', '0');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/audit.php';
require_once __DIR__ . '/../inc/facility-scope.php';
require_once __DIR__ . '/../inc/severity.php';
require_once __DIR__ . '/../inc/facility-bed-release.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function _fp_can(string $perm): bool
{
    return (function_exists('is_admin') && is_admin())
        || (function_exists('rbac_can') && rbac_can($perm));
}

// ── Self-healing tables (same shape as api/facility-capacity.php — kept
//    independent rather than requiring that file, per this endpoint's
//    "brand new, narrow, self-contained" design). ─────────────────────
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}capacity_categories` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `name`        VARCHAR(64) NOT NULL,
        `icon`        VARCHAR(64) DEFAULT 'bi-hospital',
        `unit_label`  VARCHAR(32) DEFAULT 'beds',
        `sort_order`  INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uk_cap_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}facility_capacity` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `facility_id`  INT NOT NULL,
        `category_id`  INT NOT NULL,
        `total`        INT NOT NULL DEFAULT 0,
        `available`    INT NOT NULL DEFAULT 0,
        `notes`        VARCHAR(255) DEFAULT '',
        `updated_by`   INT NOT NULL DEFAULT 0,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_fac_cat` (`facility_id`, `category_id`),
        KEY `idx_facility` (`facility_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* best-effort — matches facility-capacity.php */ }

if ($method === 'GET') {
    if (!_fp_can('screen.facility_portal')) {
        json_error('Facility portal access required', 403);
    }

    $facilityId = facility_session_facility_id();
    $action = (string) ($_GET['action'] ?? 'incidents');

    if ($facilityId <= 0) {
        // Not a facility-confined session (e.g. an admin previewing with
        // no facility_id set) — nothing to show, not an error.
        if ($action === 'status') {
            json_response(['facility' => null, 'statuses' => [], 'capacity' => [], 'categories' => []]);
        }
        json_response(['facility' => null, 'incidents' => []]);
    }

    $facility = null;
    try {
        $facility = db_fetch_one(
            "SELECT `id`, `name`, `type`, `status_id`, `status_about`,
                    `beds_a`, `beds_o`, `beds_info`, `lat`, `lng`
             FROM `{$prefix}facilities` WHERE `id` = ? LIMIT 1",
            [$facilityId]
        );
    } catch (Throwable $e) {
        json_error_safe('Database error', $e, 'facility-portal-lookup', 500);
    }
    if (!$facility) {
        json_error('Linked facility not found', 404);
    }

    if ($action === 'status') {
        $statuses = [];
        try {
            foreach (db_fetch_all(
                "SELECT `id`, `status_val`, `bg_color`, `text_color`
                 FROM `{$prefix}fac_status` ORDER BY `sort`, `id`"
            ) as $s) {
                $statuses[] = [
                    'id'         => (int) $s['id'],
                    'name'       => $s['status_val'] ?? '',
                    'bg_color'   => $s['bg_color'] ?? null,
                    'text_color' => $s['text_color'] ?? null,
                ];
            }
        } catch (Throwable $e) { /* fac_status missing on very old installs */ }

        $capacity = [];
        try {
            $capacity = db_fetch_all(
                "SELECT fc.category_id, fc.total, fc.available, fc.notes, fc.updated_at,
                        cc.name AS category_name, cc.icon, cc.unit_label
                 FROM `{$prefix}facility_capacity` fc
                 JOIN `{$prefix}capacity_categories` cc ON fc.category_id = cc.id
                 WHERE fc.facility_id = ?
                 ORDER BY cc.sort_order",
                [$facilityId]
            );
        } catch (Throwable $e) { /* no rows yet */ }

        $categories = [];
        try {
            $categories = db_fetch_all(
                "SELECT `id`, `name`, `icon`, `unit_label`, `sort_order`
                 FROM `{$prefix}capacity_categories` ORDER BY sort_order, name"
            );
        } catch (Throwable $e) { /* no categories yet */ }

        json_response([
            'facility' => [
                'id'           => (int) $facility['id'],
                'name'         => $facility['name'] ?? '',
                'status_id'    => (int) $facility['status_id'],
                'status_about' => $facility['status_about'] ?? '',
                'beds_a'       => $facility['beds_a'] ?? '',
                'beds_o'       => $facility['beds_o'] ?? '',
                'beds_info'    => $facility['beds_info'] ?? '',
            ],
            'statuses'   => $statuses,
            'capacity'   => $capacity,
            'categories' => $categories,
        ]);
    }

    // ── default action: incidents ──
    [$visFrag, $visParams] = facility_ticket_visibility_sql('t');
    $rows = [];
    try {
        $rows = db_fetch_all(
            "SELECT `t`.`id`, `t`.`incident_number`, `t`.`scope`, `t`.`street`, `t`.`city`, `t`.`state`,
                    `t`.`severity`, `t`.`status`, `t`.`date`, `t`.`updated`,
                    `t`.`facility`, `t`.`rec_facility`,
                    `it`.`type` AS `type_name`, `it`.`group` AS `type_group`,
                    (SELECT COUNT(*) FROM `{$prefix}patient` `p` WHERE `p`.`ticket_id` = `t`.`id`) AS `patient_count`
             FROM `{$prefix}ticket` `t`
             LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
             WHERE `t`.`status` IN (2, 3)
               AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')
               {$visFrag}
             ORDER BY `t`.`date` DESC
             LIMIT 200",
            $visParams
        );
    } catch (Throwable $e) {
        json_error_safe('Database error', $e, 'facility-portal-incidents', 500);
    }

    $sevColors = severity_color_map();
    $statusLabels = [1 => 'Closed', 2 => 'Open', 3 => 'Scheduled'];

    $incidents = [];
    foreach ($rows as $row) {
        $ticketId = (int) $row['id'];
        $sev = (int) $row['severity'];

        // Leg: which relationship does THIS facility have to the call?
        $legs = [];
        if ((int) $row['facility'] === $facilityId) $legs[] = 'origin';
        if ((int) $row['rec_facility'] === $facilityId) $legs[] = 'receiving';
        try {
            $hasUnitLeg = (bool) db_fetch_value(
                "SELECT 1 FROM `{$prefix}assigns` WHERE `ticket_id` = ? AND `rec_facility_id` = ? LIMIT 1",
                [$ticketId, $facilityId]
            );
            if ($hasUnitLeg && !in_array('receiving', $legs, true)) $legs[] = 'receiving';
        } catch (Throwable $e) { /* assigns.rec_facility_id missing (pre-Phase-116) */ }
        if (empty($legs)) $legs[] = 'inbound';

        // Unit summary — identity + status timeline only. Deliberately
        // drops crew roster / individual responder comments, mirroring
        // the org-sharing view-tier redaction precedent in
        // inc/org-sharing.php (org_share_redact_assignment_fields()) —
        // "never leak a roster" applies to an external facility account
        // even more than to a partner org.
        //
        // GH#99 (2026-08-20): a multi-unit incident can have units
        // transporting to DIFFERENT facilities — the units list must be
        // filtered to whichever units are actually relevant to THIS
        // viewing facility, not every unit on the ticket. See
        // facility_portal_visible_units()'s own docblock for the
        // origin-vs-receiving distinction; it resolves each unit's
        // effective destination with the same COALESCE inc/bed_auto.php
        // already uses, so a diverted unit (and its facility-leg
        // en_route_at/arrived_at timestamps, which would otherwise read
        // as "coming to you") never reaches a facility it isn't going to.
        $units = facility_portal_visible_units($ticketId, $facilityId, (int) $row['facility'], (int) $row['rec_facility']);

        $incidents[] = [
            'id'              => $ticketId,
            'incident_number' => $row['incident_number'] ?? null,
            'type_name'       => $row['type_name'] ?? '',
            'type_group'      => $row['type_group'] ?? '',
            'severity'        => $sev,
            'severity_color'  => $sevColors[$sev] ?? '#ffffff',
            'severity_label'  => severity_label($sev),
            'status'          => (int) $row['status'],
            'status_label'    => $statusLabels[(int) $row['status']] ?? '',
            'scope'           => $row['scope'] ?? '',
            'street'          => $row['street'] ?? '',
            'city'            => $row['city'] ?? '',
            'state'           => $row['state'] ?? '',
            'date'            => $row['date'] ?? null,
            'updated'         => $row['updated'] ?? null,
            'legs'            => $legs,
            'patient_count'   => (int) $row['patient_count'],
            // GH#99 follow-up: destination_elsewhere/destination_name let the
            // frontend qualify a unit's timing string ("transported to
            // [name]") instead of a bare "arrived HH:MM" that misreads as
            // "arrived here" when the unit's actual destination is a
            // different facility than the one viewing this board. Compares
            // against $facilityId (not the ticket-level rec_facility) since
            // the unit's OWN effective destination is what matters -- a
            // multi-unit incident can have units going to different places.
            'units'           => array_map(function ($u) use ($facilityId) {
                $destId = (int) ($u['effective_dest_id'] ?? 0);
                $elsewhere = ($destId !== $facilityId);
                return [
                    'responder_name'         => $u['responder_name'] ?? '',
                    'handle'                 => $u['handle'] ?? '',
                    'status_val'             => $u['status_val'] ?? '',
                    'bg_color'               => $u['bg_color'] ?? null,
                    'text_color'             => $u['text_color'] ?? null,
                    'en_route_at'            => (!empty($u['u2fenr']) && substr((string) $u['u2fenr'], 0, 4) !== '0000') ? $u['u2fenr'] : null,
                    'arrived_at'             => (!empty($u['u2farr']) && substr((string) $u['u2farr'], 0, 4) !== '0000') ? $u['u2farr'] : null,
                    'destination_elsewhere'  => $elsewhere,
                    'destination_name'       => $elsewhere ? ($u['dest_facility_name'] ?? null) : null,
                ];
            }, $units),
        ];
    }

    json_response([
        'facility'  => ['id' => (int) $facility['id'], 'name' => $facility['name'] ?? ''],
        'incidents' => $incidents,
    ]);
}

if ($method === 'POST') {
    if (!_fp_can('action.facility_self_report')) {
        json_error('Facility self-report access required', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (empty($input['csrf_token']) || !csrf_verify((string) $input['csrf_token'])) {
        json_error('Invalid CSRF token', 403);
    }

    $facilityId = facility_session_facility_id();
    if ($facilityId <= 0) {
        // No facility linked to this session — nothing to write, ever.
        // (Blocks an is_admin() preview session from writing to facility
        // 0 / an arbitrary facility; a facility ACCOUNT always has
        // facility_id > 0 by construction — see login.php.)
        json_error('No facility linked to this account', 403);
    }

    $action = (string) ($input['action'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($action === 'set_status') {
        $statusId = (int) ($input['status_id'] ?? 0);
        $statusAbout = trim((string) ($input['status_about'] ?? ''));
        if ($statusId <= 0) json_error('status_id required');

        try {
            $label = db_fetch_value(
                "SELECT `status_val` FROM `{$prefix}fac_status` WHERE `id` = ? LIMIT 1",
                [$statusId]
            );
        } catch (Throwable $e) {
            $label = null;
        }
        if ($label === null) json_error('Unknown status_id', 400);

        try {
            db_query(
                "UPDATE `{$prefix}facilities` SET `status_id` = ?, `status_about` = ?, `updated` = NOW() WHERE `id` = ?",
                [$statusId, $statusAbout, $facilityId]
            );
        } catch (Throwable $e) {
            json_error_safe('Database error', $e, 'facility-portal-set-status', 500);
        }

        audit_log(
            'facility', 'status_change', 'facility', $facilityId,
            "Facility self-reported status → {$label}",
            ['facility_id' => $facilityId, 'status_id' => $statusId, 'status' => $label,
             'note' => $statusAbout, 'source' => 'facility_portal_self_report']
        );

        json_response(['success' => true]);
    }

    if ($action === 'set_capacity') {
        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId <= 0) json_error('category_id required');

        try {
            $catExists = (bool) db_fetch_value(
                "SELECT 1 FROM `{$prefix}capacity_categories` WHERE `id` = ? LIMIT 1",
                [$categoryId]
            );
        } catch (Throwable $e) {
            $catExists = false;
        }
        if (!$catExists) json_error('Unknown category_id', 400);

        $total = max(0, (int) ($input['total'] ?? 0));
        $available = min($total, max(0, (int) ($input['available'] ?? 0)));
        $notes = trim((string) ($input['notes'] ?? ''));

        try {
            db_query(
                "INSERT INTO `{$prefix}facility_capacity` (facility_id, category_id, total, available, notes, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE total = VALUES(total), available = VALUES(available),
                                         notes = VALUES(notes), updated_by = VALUES(updated_by)",
                [$facilityId, $categoryId, $total, $available, $notes, $userId]
            );
        } catch (Throwable $e) {
            json_error_safe('Database error', $e, 'facility-portal-set-capacity', 500);
        }

        audit_log(
            'facility', 'update', 'capacity', $facilityId,
            "Facility self-reported capacity: cat={$categoryId} total={$total} available={$available}",
            ['facility_id' => $facilityId, 'category_id' => $categoryId, 'total' => $total,
             'available' => $available, 'source' => 'facility_portal_self_report']
        );

        json_response(['success' => true]);
    }

    if ($action === 'release_bed') {
        $count = max(1, (int) ($input['count'] ?? 1));
        $note  = trim((string) ($input['note'] ?? ''));

        // Denormalized display name for facility_bed_release_log, resolved
        // from the DB (same COALESCE(name_f/name_l, login) convention
        // api/reports.php's mileage_report driver-name lookup already
        // uses) rather than trusting a session value — the acting user id
        // itself ($userId, above) is what actually matters for
        // attribution and is never derived from anything client-supplied.
        $userName = '';
        try {
            $userName = (string) (db_fetch_value(
                "SELECT COALESCE(NULLIF(TRIM(CONCAT(`name_f`, ' ', `name_l`)), ''), `user`)
                 FROM `{$prefix}user` WHERE `id` = ? LIMIT 1",
                [$userId]
            ) ?? '');
        } catch (Throwable $e) { $userName = ''; }

        $release = facility_bed_release_apply($facilityId, $count, $note, $userId, $userName);
        if (!$release['success']) {
            json_error($release['error'] ?? 'Unable to release a bed', 400);
        }

        json_response([
            'success'  => true,
            'released' => $release['released'],
            'beds_a'   => $release['beds_a'],
            'beds_o'   => $release['beds_o'],
        ]);
    }

    json_error('Unknown action', 400);
}

json_error('Method not allowed', 405);
