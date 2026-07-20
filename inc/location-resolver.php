<?php
/**
 * Location Resolver — Staleness-Aware Priority Resolution
 *
 * Core engine for determining a unit's current position from multiple
 * location providers. Each provider has:
 *   - A priority (lower number = higher priority)
 *   - A max_age_seconds threshold (data older than this is "stale")
 *
 * Resolution algorithm:
 *   1. Gather latest report from each bound provider for the unit
 *   2. Sort by: fresh (within max_age) first, then binding priority, then provider priority
 *   3. Return the best match (fresh + highest priority)
 *   4. If NO fresh data exists, return the most recent stale report (with is_fresh=0 flag)
 *
 * When a member is assigned to a unit (unit_personnel_assignments),
 * the unit's resolved location IS the member's location.
 *
 * Usage:
 *   $pos = location_resolve_unit($responderId);
 *   $pos = location_resolve_member($memberId);
 *   $all = location_resolve_all_units();
 */

/**
 * Resolve the current position for a single unit (responder).
 *
 * @param  int  $responderId
 * @return array|null  Position data with provider info, age_seconds, is_fresh
 */
function location_resolve_unit(int $responderId): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $row = db_fetch_one(
            "SELECT b.`responder_id`, b.`unit_identifier`,
                    b.`priority` AS `binding_priority`,
                    lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                    lr.`heading`, lr.`accuracy`, lr.`battery`,
                    lr.`reported_at`, lr.`received_at`,
                    lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                    lp.`icon`, lp.`color`, lp.`priority` AS `provider_priority`,
                    lp.`max_age_seconds`,
                    TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) AS `age_seconds`,
                    CASE
                        WHEN TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) <= lp.`max_age_seconds`
                        THEN 1 ELSE 0
                    END AS `is_fresh`
             FROM `{$prefix}unit_location_bindings` b
             JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
             JOIN `{$prefix}location_reports` lr
               ON lr.`unit_identifier` = b.`unit_identifier`
              AND lr.`provider_id` = b.`provider_id`
             WHERE b.`responder_id` = ?
               AND b.`active` = 1
               AND lp.`enabled` = 1
               AND lr.`received_at` = (
                   SELECT MAX(lr2.`received_at`)
                   FROM `{$prefix}location_reports` lr2
                   WHERE lr2.`unit_identifier` = b.`unit_identifier`
                     AND lr2.`provider_id` = b.`provider_id`
               )
             ORDER BY `is_fresh` DESC, b.`priority` ASC, lp.`priority` ASC
             LIMIT 1",
            [$responderId]
        );
    } catch (Exception $e) {
        return null;
    }

    return $row ?: null;
}

/**
 * Resolve the current position for a member.
 *
 * Strategy:
 *   1. If the member is actively assigned to a unit → use that unit's position
 *   2. If the member has their own location bindings → use those
 *   3. Fall back to member.lat/lng (home address geocode)
 *
 * @param  int  $memberId
 * @return array|null  Position data or null
 */
function location_resolve_member(int $memberId): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    // 1. Check if member is assigned to an active unit
    try {
        $assignment = db_fetch_one(
            "SELECT upa.`responder_id`, r.`name` AS `unit_name`
             FROM `{$prefix}unit_personnel_assignments` upa
             LEFT JOIN `{$prefix}responder` r ON upa.`responder_id` = r.`id`
             WHERE upa.`member_id` = ? AND upa.`status` = 'active'
             ORDER BY upa.`assigned_at` DESC
             LIMIT 1",
            [$memberId]
        );
    } catch (Exception $e) {
        $assignment = null;
    }

    if ($assignment && !empty($assignment['responder_id'])) {
        $pos = location_resolve_unit((int) $assignment['responder_id']);
        if ($pos) {
            $pos['source'] = 'unit_assignment';
            $pos['unit_name'] = $assignment['unit_name'] ?? '';
            return $pos;
        }
    }

    // 2. Check member's own location bindings (member.responder_id legacy link)
    try {
        $member = db_fetch_one(
            "SELECT `responder_id`, `lat`, `lng`, `callsign`
             FROM `{$prefix}member` WHERE `id` = ?",
            [$memberId]
        );
    } catch (Exception $e) {
        return null;
    }

    if ($member && !empty($member['responder_id'])) {
        $pos = location_resolve_unit((int) $member['responder_id']);
        if ($pos) {
            $pos['source'] = 'responder_link';
            return $pos;
        }
    }

    // 3. Fall back to member static lat/lng
    if ($member && !empty($member['lat']) && !empty($member['lng'])) {
        return [
            'lat'           => (float) $member['lat'],
            'lng'           => (float) $member['lng'],
            'provider_code' => 'static',
            'provider_name' => 'Home Address',
            'icon'          => 'bi-house',
            'color'         => '#666666',
            'age_seconds'   => null,
            'is_fresh'      => 0,
            'source'        => 'member_static',
        ];
    }

    return null;
}

/**
 * Resolve positions for all bound units (map display).
 * Returns one row per unit, using the best available provider.
 *
 * @return array  Array of position records
 */
function location_resolve_all_units(): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        $rows = db_fetch_all(
            "SELECT b.`responder_id`, b.`unit_identifier`,
                    b.`priority` AS `binding_priority`,
                    lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                    lr.`heading`, lr.`accuracy`, lr.`battery`,
                    lr.`reported_at`, lr.`received_at`,
                    lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                    lp.`icon`, lp.`color`, lp.`priority` AS `provider_priority`,
                    lp.`max_age_seconds`,
                    TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) AS `age_seconds`,
                    CASE
                        WHEN TIMESTAMPDIFF(SECOND, lr.`received_at`, NOW()) <= lp.`max_age_seconds`
                        THEN 1 ELSE 0
                    END AS `is_fresh`,
                    r.`name` AS `unit_name`, r.`handle` AS `unit_handle`,
                    r.`callsign` AS `unit_callsign`
             FROM `{$prefix}unit_location_bindings` b
             JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
             JOIN `{$prefix}location_reports` lr
               ON lr.`unit_identifier` = b.`unit_identifier`
              AND lr.`provider_id` = b.`provider_id`
             LEFT JOIN `{$prefix}responder` r ON b.`responder_id` = r.`id`
             WHERE b.`active` = 1
               AND lp.`enabled` = 1
               AND lr.`received_at` = (
                   SELECT MAX(lr2.`received_at`)
                   FROM `{$prefix}location_reports` lr2
                   WHERE lr2.`unit_identifier` = b.`unit_identifier`
                     AND lr2.`provider_id` = b.`provider_id`
               )
             ORDER BY `is_fresh` DESC, b.`priority` ASC, lp.`priority` ASC"
        );
    } catch (Exception $e) {
        return [];
    }

    // De-duplicate: keep only the best entry per responder_id
    $seen = [];
    $units = [];
    foreach ($rows as $row) {
        $rid = (int) $row['responder_id'];
        if (!isset($seen[$rid])) {
            $seen[$rid] = true;
            // Enrich with assigned personnel
            try {
                $personnel = db_fetch_all(
                    "SELECT upa.`member_id`, upa.`role`,
                            CONCAT(m.`first_name`, ' ', m.`last_name`) AS `name`
                     FROM `{$prefix}unit_personnel_assignments` upa
                     LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
                     WHERE upa.`responder_id` = ? AND upa.`status` = 'active'",
                    [$rid]
                );
                $row['personnel'] = $personnel;
            } catch (Exception $e) {
                $row['personnel'] = [];
            }
            $units[] = $row;
        }
    }

    return $units;
}

/**
 * Get the active personnel assigned to a unit.
 *
 * @param  int  $responderId
 * @return array  Array of assignment records with member info
 */
function location_get_unit_personnel(int $responderId): array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        return db_fetch_all(
            "SELECT upa.`id`, upa.`member_id`, upa.`role`, upa.`status`,
                    upa.`assigned_at`, upa.`notes`,
                    CONCAT(m.`first_name`, ' ', m.`last_name`) AS `member_name`,
                    m.`callsign`, m.`phone_cell`
             FROM `{$prefix}unit_personnel_assignments` upa
             LEFT JOIN `{$prefix}member` m ON upa.`member_id` = m.`id`
             WHERE upa.`responder_id` = ? AND upa.`status` != 'released'
             ORDER BY upa.`assigned_at` ASC",
            [$responderId]
        );
    } catch (Exception $e) {
        return [];
    }
}
