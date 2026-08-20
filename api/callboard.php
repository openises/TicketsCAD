<?php
/**
 * NewUI v4.0 API - Call Board
 *
 * GET /api/callboard.php
 *   Returns all open/active incidents with assigned unit names
 *   for the dispatch call board display.
 *
 * Response includes:
 *   - incidents: array of active incidents with unit names
 *   - types: distinct incident type names (for filter dropdown)
 *   - count: total active incidents
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/severity.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    // Fetch open/scheduled incidents plus recently closed (last 30 min)
    $recent_mins = (int) (get_variable('recent_close_mins') ?: 30);

    // Phase 99p — include admin-configured case number so the JS
    // can render it instead of the internal id.
    $sql = "SELECT
        `t`.`id`,
        `t`.`incident_number`,
        `t`.`org_id`,
        `t`.`scope`,
        `t`.`street`,
        `t`.`city`,
        `t`.`state`,
        `t`.`lat`,
        `t`.`lng`,
        `t`.`severity`,
        `t`.`status`,
        `t`.`description`,
        `t`.`date` AS `created`,
        `t`.`updated`,
        `t`.`problemstart`,
        `t`.`problemend`,
        `it`.`type` AS `incident_type`,
        `it`.`id` AS `type_id`,
        `it`.`color` AS `type_color`,
        (SELECT COUNT(*) FROM `{$prefix}assigns`
         WHERE `ticket_id` = `t`.`id` AND (`clear` IS NULL OR DATE_FORMAT(`clear`,'%y') = '00'))
         AS `units_assigned`
    FROM `{$prefix}ticket` `t`
    LEFT JOIN `{$prefix}in_types` `it` ON `t`.`in_types_id` = `it`.`id`
    WHERE (
        `t`.`status` = 2
        OR `t`.`status` = 3
        OR (`t`.`status` = 1 AND `t`.`problemend` >= DATE_SUB(NOW(), INTERVAL ? MINUTE))
    )
    AND (`t`.`deleted_at` IS NULL OR `t`.`deleted_at` = '0000-00-00 00:00:00')";
    // Soft-delete sweep (issue #25 follow-up) — same class of bug as the
    // dispatch board (api/incidents.php func=0, fixed in 1502157): an
    // incident deleted while OPEN stays status=2 and would match the
    // first clause above forever.

    // Phase 99j-4 — org-scope filter. See specs/phase-99j-org-scoping.
    // Phase 141 (2026-08-17) — org_ticket_query_filter() is the
    // ticket-specific sibling: widens visibility to cross-org-shared
    // tickets, no-op on a database with no routing rules.
    require_once __DIR__ . '/../inc/org-scope.php';
    require_once __DIR__ . '/../inc/org-sharing.php';
    [$orgFrag, $orgVars] = org_ticket_query_filter(null, 't');
    $sql .= $orgFrag;
    $sql .= " ORDER BY `t`.`severity` DESC, `t`.`updated` DESC";

    $rows = db_fetch_all($sql, array_merge([$recent_mins], $orgVars));

    // Gather assigned unit names per ticket
    $ticket_ids = [];
    foreach ($rows as $row) {
        $ticket_ids[] = (int) $row['id'];
    }

    $unit_map = [];
    if (!empty($ticket_ids)) {
        $placeholders = implode(',', array_fill(0, count($ticket_ids), '?'));
        $unit_sql = "SELECT
            `a`.`ticket_id`,
            `r`.`name`
        FROM `{$prefix}assigns` `a`
        LEFT JOIN `{$prefix}responder` `r` ON `a`.`responder_id` = `r`.`id`
        WHERE `a`.`ticket_id` IN ({$placeholders})
          AND (`a`.`clear` IS NULL OR DATE_FORMAT(`a`.`clear`,'%y') = '00')
        ORDER BY `r`.`name`";

        try {
            $unit_rows = db_fetch_all($unit_sql, $ticket_ids);
            foreach ($unit_rows as $ur) {
                $tid = (int) $ur['ticket_id'];
                if (!isset($unit_map[$tid])) {
                    $unit_map[$tid] = [];
                }
                if ($ur['name']) {
                    $unit_map[$tid][] = $ur['name'];
                }
            }
        } catch (Exception $e) {
            // Graceful degradation — unit names not available
        }
    }

    // Build response
    $incidents = [];
    $type_set = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $type_name = $row['incident_type'] ?: '';

        if ($type_name && !isset($type_set[$type_name])) {
            $type_set[$type_name] = true;
        }

        $names = isset($unit_map[$id]) ? $unit_map[$id] : [];

        $incidents[] = [
            'id'              => $id,
            'incident_number' => $row['incident_number'] ?? null,  // Phase 99p
            'org_id'          => isset($row['org_id']) ? (int) $row['org_id'] : null,
            'scope'           => $row['scope'],
            'street'          => $row['street'],
            'city'            => $row['city'],
            'state'           => $row['state'],
            'lat'             => (float) $row['lat'],
            'lng'             => (float) $row['lng'],
            'severity'        => (int) $row['severity'],
            // GH#87/GH#88 (2026-08-19) — sourced from the configurable
            // severity_levels table instead of assets/js/callboard.js's
            // own hardcoded switch statement (which used YET ANOTHER
            // spelling of the same 3 integers — "Normal/Medium/High").
            'severity_label'  => severity_label((int) $row['severity']),
            'severity_color'  => severity_color((int) $row['severity']),
            'status'          => (int) $row['status'],
            'description'     => $row['description'],
            'incident_type'   => $type_name,
            'type_id'         => (int) ($row['type_id'] ?? 0),
            'type_color'      => $row['type_color'] ?: null,
            'created'         => toIso($row['created']),
            'updated'         => toIso($row['updated']),
            'problemstart'    => toIso($row['problemstart']),
            'problemend'      => toIso($row['problemend']),
            'units_assigned'  => (int) $row['units_assigned'],
            'unit_names'      => implode(', ', $names),
        ];
    }

    // Sort type names for filter dropdown
    $types = array_keys($type_set);
    sort($types);

    // Phase 141 (2026-08-17) — redact/annotate any share-derived row
    // (description stripped for view tier; unit_names/units_assigned kept
    // -- "which units, and their current status" is explicitly allowed).
    $incidents = org_sharing_apply_list_redaction($incidents);

    json_response([
        'ok'        => true,
        'incidents' => $incidents,
        'count'     => count($incidents),
        'types'     => $types,
    ]);

} catch (Exception $e) {
    json_error('Failed to load call board data: ' . $e->getMessage(), 500);
}
