<?php
/**
 * Phase 105 (a beta tester GH #16) — Status-workflow enforcement library.
 *
 * A single shared gate that answers: "is this unit allowed to move from
 * status A to status B right now?" Called from
 * responder_set_status_internal() (inc/responder-write.php) so every
 * status-change surface — the status modal, mobile, the /s command bar,
 * and the external API — passes through one check.
 *
 * Semantics (locked in specs/phase-105-conditional-statuses):
 *   - Enforcement mode lives in settings row `status_workflow_mode`
 *     ('off' | 'warn' | 'enforce'); a MISSING row means 'off'.
 *   - mode 'off'      → everything allowed (backwards compatible).
 *   - mode 'warn'     → the check reports blocked transitions but the
 *                       caller applies the change anyway and surfaces a
 *                       warning + audit row.
 *   - mode 'enforce'  → blocked transitions are rejected (HTTP 422).
 *   - An edge (from_status_id → to_status_id) in status_transitions
 *     allows the transition. from_status_id = 0 is the synthetic "ANY"
 *     source: the target status is reachable from any status.
 *   - Under warn/enforce an ABSENT edge means blocked — the designer
 *     warns admins about this semantic before they enable enforcement.
 *   - conditions_json (v1): {"requires_assignment":true} — unit must
 *     have >= 1 open incident assignment; {"requires_no_assignment":true}
 *     — unit must have none. Designed to grow more condition types.
 *
 * Fail-open discipline: any DB exception in here logs via error_log()
 * and returns "allowed" — a broken workflow table must NEVER lock
 * dispatch out of changing unit statuses.
 */

declare(strict_types=1);

/**
 * Read the enforcement mode from the settings table.
 * Cached in a static for the life of the request.
 *
 * @return string 'off' | 'warn' | 'enforce'
 */
function sw_get_mode(): string
{
    static $mode = null;
    if ($GLOBALS['_sw_mode_cache_reset'] ?? false) {
        $mode = null;
        $GLOBALS['_sw_mode_cache_reset'] = false;
    }
    if ($mode !== null) {
        return $mode;
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $v = db_fetch_value(
            "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
            ['status_workflow_mode']
        );
        $v = is_string($v) ? strtolower(trim($v)) : '';
        $mode = in_array($v, ['off', 'warn', 'enforce'], true) ? $v : 'off';
    } catch (Exception $e) {
        // Settings table missing / unreadable → treat as disabled.
        error_log('[status-workflow] sw_get_mode fail-open: ' . $e->getMessage());
        $mode = 'off';
    }
    return $mode;
}

/**
 * Test-support helper: drop the per-request mode cache so a test can
 * flip the settings row and re-read it within one PHP process.
 */
function sw_mode_cache_reset(): void
{
    $GLOBALS['_sw_mode_cache_reset'] = true;
}

/**
 * Check whether a responder may transition between two statuses.
 *
 * @param int $responderId  responder.id (used for condition evaluation)
 * @param int $fromStatusId current un_status.id (0 / unknown allowed)
 * @param int $toStatusId   requested un_status.id
 * @return array ['allowed' => bool, 'reason' => string, 'mode' => string]
 */
function sw_check_transition(int $responderId, int $fromStatusId, int $toStatusId): array
{
    $mode = sw_get_mode();
    if ($mode === 'off') {
        return ['allowed' => true, 'reason' => '', 'mode' => $mode];
    }

    // Re-applying the current status is a refresh, not a transition —
    // always allowed so operators can re-stamp status_updated.
    if ($fromStatusId === $toStatusId) {
        return ['allowed' => true, 'reason' => '', 'mode' => $mode];
    }

    $prefix = $GLOBALS['db_prefix'] ?? '';

    try {
        // Candidate edges: the specific (from → to) pair plus the
        // synthetic ANY source (0 → to). Specific edge is evaluated
        // first; if ANY edge exists it is a second chance.
        $edges = db_fetch_all(
            "SELECT `id`, `from_status_id`, `to_status_id`, `conditions_json`
             FROM `{$prefix}status_transitions`
             WHERE `to_status_id` = ?
               AND `from_status_id` IN (?, 0)
             ORDER BY `from_status_id` DESC",
            [$toStatusId, $fromStatusId]
        );

        if (empty($edges)) {
            return [
                'allowed' => false,
                'reason'  => 'No transition from ' . _sw_status_name($fromStatusId)
                           . ' to ' . _sw_status_name($toStatusId)
                           . ' is defined in the status workflow',
                'mode'    => $mode,
            ];
        }

        $failReason = '';
        foreach ($edges as $edge) {
            $result = _sw_edge_conditions_pass($edge, $responderId);
            if ($result['pass']) {
                return ['allowed' => true, 'reason' => '', 'mode' => $mode];
            }
            if ($failReason === '') {
                $failReason = $result['reason'];
            }
        }

        return [
            'allowed' => false,
            'reason'  => 'Transition from ' . _sw_status_name($fromStatusId)
                       . ' to ' . _sw_status_name($toStatusId) . ' ' . $failReason,
            'mode'    => $mode,
        ];
    } catch (Exception $e) {
        // Fail-open: a broken workflow table must never lock dispatch.
        error_log('[status-workflow] sw_check_transition fail-open: ' . $e->getMessage());
        return ['allowed' => true, 'reason' => '', 'mode' => $mode];
    }
}

/**
 * Evaluate one edge's conditions_json against the responder's live
 * assignment state. Unparseable / unknown condition payloads count as
 * "no conditions" (fail-open per edge).
 *
 * @return array ['pass' => bool, 'reason' => string]
 */
function _sw_edge_conditions_pass(array $edge, int $responderId): array
{
    $raw = trim((string) ($edge['conditions_json'] ?? ''));
    if ($raw === '') {
        return ['pass' => true, 'reason' => ''];
    }

    $cond = json_decode($raw, true);
    if (!is_array($cond)) {
        // Bogus JSON — treat the edge as unconditional rather than
        // blocking a legitimate change on corrupt config.
        error_log('[status-workflow] unparseable conditions_json on edge #'
            . (int) ($edge['id'] ?? 0) . ' — treating as unconditional');
        return ['pass' => true, 'reason' => ''];
    }

    if (!empty($cond['requires_assignment'])) {
        if (!_sw_has_open_assignment($responderId)) {
            return ['pass' => false, 'reason' => 'requires an active incident assignment'];
        }
    }
    if (!empty($cond['requires_no_assignment'])) {
        if (_sw_has_open_assignment($responderId)) {
            return ['pass' => false, 'reason' => 'requires NO active incident assignment'];
        }
    }

    return ['pass' => true, 'reason' => ''];
}

/**
 * Does the responder have at least one open incident assignment?
 * "Open" matches responder_set_status_internal()'s open-assigns query
 * exactly (inc/responder-write.php): clear IS NULL, '', or the MySQL
 * zero-datetime.
 */
function _sw_has_open_assignment(int $responderId): bool
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $count = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}assigns`
         WHERE `responder_id` = ?
           AND (`clear` IS NULL
                OR `clear` = ''
                OR `clear` = '0000-00-00 00:00:00')",
        [$responderId]
    );
    return $count > 0;
}

/**
 * Human name for a status id, for block-reason strings.
 * Id 0 / unknown renders as "(none)".
 */
function _sw_status_name(int $statusId): string
{
    if ($statusId <= 0) {
        return '(none)';
    }
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $name = db_fetch_value(
            "SELECT `status_val` FROM `{$prefix}un_status` WHERE `id` = ? LIMIT 1",
            [$statusId]
        );
        if (is_string($name) && $name !== '') {
            return $name;
        }
    } catch (Exception $e) { /* fall through */ }
    return 'status #' . $statusId;
}
