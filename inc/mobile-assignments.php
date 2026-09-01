<?php
/**
 * GH#82 (2026-08-18) — the mobile field-unit "current assignment(s)" query,
 * extracted out of api/mobile-data.php so it's directly testable via CLI
 * (per this project's own convention: reusable logic belongs in an inc/
 * include, not buried in an api/*.php endpoint — see CLAUDE.md's Phase 117
 * pitfall entry).
 *
 * Before this fix, api/mobile-data.php selected the single NEWEST active
 * assignment (`ORDER BY a.id DESC LIMIT 1`). When a unit was given a
 * second concurrent call (the GH#82 bug), the crew's original, still-active
 * incident vanished from the mobile screen entirely — not just
 * deprioritized, gone. mobile_active_assignments() now returns EVERY
 * active (uncleared, ticket status=2) assignment for the given responder
 * ids, oldest first, so the unit's original call stays primary and any
 * additional one is still visible rather than silently discarded.
 */

/**
 * All currently-active assignments for the given responder ids, oldest
 * first (the responder's ORIGINAL live call sorts first, matching physical
 * reality — a unit added to a second call while already on one is still,
 * first and foremost, on the first).
 *
 * @param string $prefix        Table prefix
 * @param int[]  $responderIds  Responder ids to check (the crew member's
 *                               own unit + any units they crew)
 * @return array List of assignment rows (possibly empty)
 */
function mobile_active_assignments(string $prefix, array $responderIds): array {
    if (empty($responderIds)) return [];
    $ph = implode(',', array_fill(0, count($responderIds), '?'));
    try {
        return db_fetch_all(
            "SELECT a.`id` AS assign_id, a.`ticket_id`, a.`status_id` AS `assign_status_id`,
                    t.`street` AS `address`, t.`city`, t.`state`,
                    t.`scope` AS `nature`, t.`description`,
                    t.`lat`, t.`lng` AS `lon`,
                    t.`status` AS ticket_status, t.`severity`,
                    t.`contact`, t.`phone`,
                    t.`incident_number`,
                    it.`type` AS incident_type,
                    it.`color` AS type_color,
                    r.`name` AS assigned_unit_name, r.`handle` AS assigned_unit_handle
             FROM `{$prefix}assigns` a
             JOIN `{$prefix}ticket` t ON t.`id` = a.`ticket_id`
             LEFT JOIN `{$prefix}in_types` it ON it.`id` = t.`in_types_id`
             LEFT JOIN `{$prefix}responder` r ON r.`id` = a.`responder_id`
             WHERE a.`responder_id` IN ($ph)
               AND (a.`clear` IS NULL OR DATE_FORMAT(a.`clear`,'%y') = '00')
               AND t.`status` = 2
               AND (t.`deleted_at` IS NULL)
             ORDER BY a.`id` ASC",
            $responderIds
        );
    } catch (Exception $e) {
        error_log('[mobile-assignments] mobile_active_assignments: ' . $e->getMessage());
        return [];
    }
}

/**
 * GH#113 (rjonesbsink) — moved here from api/mobile-data.php (unchanged
 * behavior) so inc/par.php's ack-ownership gate can call the SAME
 * resolver mobile-data.php itself uses, instead of restating the query a
 * third time. Extracting into inc/ rather than requiring the whole
 * api/mobile-data.php endpoint file follows this project's own
 * established convention (see mobile_active_assignments() above,
 * GH#82) — an api/*.php file can carry top-level request-dispatch code
 * that must not run as a side effect of merely wanting one of its
 * helper functions.
 *
 * The units (responder ids) this user actively crews via
 * unit_personnel_assignments — the Phase 116c / GH#85 query. Factored
 * out originally (GH#77) so every mobile action that needs "which unit
 * is this crew member operating from" calls the exact same query
 * instead of each re-deriving (and drifting from) its own copy; that
 * drift is exactly what GH#77 was, and re-deriving the query a third
 * time for PAR would have been the identical mistake one file over.
 */
function mobile_crew_unit_ids($prefix, $userId) {
    $ids = [];
    try {
        $crewRows = db_fetch_all(
            "SELECT DISTINCT upa.`responder_id`
               FROM `{$prefix}unit_personnel_assignments` upa
               JOIN `{$prefix}member` m ON m.`id` = upa.`member_id`
              WHERE m.`user_id` = ?
                AND upa.`status` IN ('active','standby')
                AND (upa.`released_at` IS NULL OR DATE_FORMAT(upa.`released_at`,'%y') = '00')",
            [$userId]
        );
        foreach ($crewRows as $cr) {
            $rid = (int) $cr['responder_id'];
            if ($rid > 0) $ids[] = $rid;
        }
    } catch (Exception $e) {
        // older install without unit_personnel_assignments, or a schema
        // mismatch — either way, "this user crews nothing" is the safe
        // answer, not a fatal.
        error_log('[mobile-assignments] mobile_crew_unit_ids: ' . $e->getMessage());
    }
    return $ids;
}
