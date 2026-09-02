<?php
/**
 * Phase 86 (2026-09-02) — live resource rollup for a major incident.
 *
 * Deliberately NOT a cached/stored column (the original spec proposed a
 * `resource_summary JSON` column) — Priya's review point: this codebase has
 * a documented history of caches nobody ever recomputes (CLAUDE.md's
 * several GEOCODE_CACHE_DIR/TILE_CACHE_DIR-class entries). At the scale a
 * major event's linked-incident set actually reaches (a handful of open
 * tickets, a handful of assignments each), a live COUNT is cheap enough
 * that there is no cache-invalidation story to get wrong.
 */

/**
 * Units currently assigned across every incident linked to a major
 * incident, and how many of those units are still active (not cleared).
 * "Available" in the original spec's sense (units NOT on this event) is
 * intentionally not computed here — that's a fleet-wide question this
 * function has no reason to answer; the UI shows assigned/active only.
 *
 * @return array{units_assigned:int, units_active:int}
 */
function major_event_resource_rollup(int $majorId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $row = db_fetch_one(
            "SELECT
                COUNT(DISTINCT a.`responder_id`) AS units_assigned,
                COUNT(DISTINCT CASE
                    WHEN a.`clear` IS NULL OR a.`clear` = '0000-00-00 00:00:00'
                    THEN a.`responder_id`
                END) AS units_active
             FROM `{$prefix}newui_major_incident_links` l
             JOIN `{$prefix}assigns` a ON a.`ticket_id` = l.`ticket_id`
            WHERE l.`major_id` = ?",
            [$majorId]
        );
    } catch (Throwable $e) {
        return ['units_assigned' => 0, 'units_active' => 0];
    }

    return [
        'units_assigned' => (int) ($row['units_assigned'] ?? 0),
        'units_active'   => (int) ($row['units_active'] ?? 0),
    ];
}
