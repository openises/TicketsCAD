<?php
/**
 * Phase 152 (Communications Console v2) — the thin position layer.
 *
 * Business logic behind api/console-positions.php, split out so it can be
 * driven directly by tests (this project's established pattern for
 * admin-CRUD-UI endpoints, e.g. inc/console-views.php / inc/matrix-routes.php).
 *
 * Validation failures throw Exception with a human-readable message,
 * matching inc/matrix-routes.php's convention -- the API layer catches and
 * returns json_error($e->getMessage(), 400).
 *
 * Heartbeat interval / staleness: the console page is expected to call
 * console_position_heartbeat() roughly every HEARTBEAT_INTERVAL_SECONDS
 * while a position is occupied; a presence row is flagged stale once its
 * last_heartbeat_at is older than 2x that interval (plan.md section 3).
 * Nothing here auto-closes a stale row -- staleness is reported, not acted
 * on (spec.md's explicit deferral of forced takeover / supervisor tooling).
 */

const CONSOLE_POSITION_HEARTBEAT_INTERVAL_SECONDS = 60;
const CONSOLE_POSITION_STALE_AFTER_SECONDS = 2 * CONSOLE_POSITION_HEARTBEAT_INTERVAL_SECONDS;

function _console_positions_prefix() {
    return $GLOBALS['db_prefix'] ?? '';
}

/**
 * Decode + validate a caller-supplied channel-id list against the real
 * comm_channels table. Throws on ANY id that doesn't resolve to a real
 * channel -- an admin authoring a position's working set should be told
 * immediately, not have the bad id silently dropped (mirrors inc/matrix-
 * routes.php's throw-based validation rather than this project's
 * read-path graceful-degradation convention, which is for RUNTIME
 * resilience against schema drift, not admin input mistakes).
 */
function console_position_validate_channel_ids($ids): array {
    if (!is_array($ids)) {
        throw new Exception('default_channel_ids must be an array');
    }
    $clean = [];
    foreach ($ids as $v) {
        $id = (int) $v;
        if ($id <= 0) {
            throw new Exception('Invalid channel id in default_channel_ids');
        }
        $clean[] = $id;
    }
    $clean = array_values(array_unique($clean));
    if (empty($clean)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $prefix = _console_positions_prefix();
    $rows = db_fetch_all(
        "SELECT id FROM `{$prefix}comm_channels` WHERE id IN ($placeholders)",
        $clean
    );
    $found = array_map(function ($r) { return (int) $r['id']; }, $rows);
    $missing = array_diff($clean, $found);
    if (!empty($missing)) {
        throw new Exception('Unknown channel id(s): ' . implode(', ', $missing));
    }
    return $clean;
}

function console_position_get_row($id) {
    $prefix = _console_positions_prefix();
    $row = db_fetch_one("SELECT * FROM `{$prefix}console_positions` WHERE id = ?", [(int) $id]);
    if (!$row) {
        return null;
    }
    $row['default_channel_ids'] = json_decode((string) $row['default_channel_ids_json'], true) ?: [];
    return $row;
}

function console_positions_list(): array {
    $prefix = _console_positions_prefix();
    $rows = db_fetch_all("SELECT * FROM `{$prefix}console_positions` ORDER BY sort_order ASC, id ASC");
    foreach ($rows as &$r) {
        $r['default_channel_ids'] = json_decode((string) $r['default_channel_ids_json'], true) ?: [];
        unset($r['default_channel_ids_json']);
    }
    return $rows;
}

function console_position_create(array $args, ?int $createdBy = null): int {
    $label = trim((string) ($args['label'] ?? ''));
    if ($label === '' || mb_strlen($label) > 64) {
        throw new Exception('Position label is required (max 64 characters)');
    }
    $channelIds = console_position_validate_channel_ids($args['default_channel_ids'] ?? []);
    $sortOrder = (int) ($args['sort_order'] ?? 0);
    $prefix = _console_positions_prefix();
    db_query(
        "INSERT INTO `{$prefix}console_positions` (label, default_channel_ids_json, sort_order, created_by)
         VALUES (?, ?, ?, ?)",
        [$label, json_encode($channelIds), $sortOrder, $createdBy]
    );
    return (int) db_insert_id();
}

function console_position_update($id, array $fields): void {
    $row = console_position_get_row($id);
    if (!$row) {
        throw new Exception('Position not found');
    }
    $label = array_key_exists('label', $fields) ? trim((string) $fields['label']) : $row['label'];
    if ($label === '' || mb_strlen($label) > 64) {
        throw new Exception('Position label is required (max 64 characters)');
    }
    $channelIds = array_key_exists('default_channel_ids', $fields)
        ? console_position_validate_channel_ids($fields['default_channel_ids'])
        : $row['default_channel_ids'];
    $sortOrder = array_key_exists('sort_order', $fields) ? (int) $fields['sort_order'] : (int) $row['sort_order'];
    $prefix = _console_positions_prefix();
    db_query(
        "UPDATE `{$prefix}console_positions` SET label = ?, default_channel_ids_json = ?, sort_order = ? WHERE id = ?",
        [$label, json_encode($channelIds), $sortOrder, (int) $id]
    );
}

/**
 * Deletes a position and cleans up its dependent rows -- no FK constraint
 * exists (schema-resilience convention), so this function is the one
 * place that keeps console_position_sessions / comm_channel_reads from
 * accumulating orphans.
 */
function console_position_delete($id): void {
    $row = console_position_get_row($id);
    if (!$row) {
        throw new Exception('Position not found');
    }
    $prefix = _console_positions_prefix();
    db_query("UPDATE `{$prefix}console_position_sessions` SET logged_out_at = NOW() WHERE position_id = ? AND logged_out_at IS NULL", [(int) $id]);
    db_query("DELETE FROM `{$prefix}comm_channel_reads` WHERE position_id = ?", [(int) $id]);
    db_query("DELETE FROM `{$prefix}console_positions` WHERE id = ?", [(int) $id]);
}

/**
 * Any screen.console holder may log into any position -- no forced
 * takeover (spec.md), so logging into an already-occupied position is
 * allowed and never evicts the existing occupant. Logging into a NEW
 * position DOES close the SAME user's own other open session(s) first --
 * one operator occupies one seat at a time, matching the physical reality
 * a "position" models.
 */
function console_position_log_in(int $positionId, int $userId, string $username, ?int $consoleSessionId = null): int {
    if (!console_position_get_row($positionId)) {
        throw new Exception('Position not found');
    }
    $prefix = _console_positions_prefix();
    db_query(
        "UPDATE `{$prefix}console_position_sessions` SET logged_out_at = NOW() WHERE user_id = ? AND logged_out_at IS NULL",
        [$userId]
    );
    db_query(
        "INSERT INTO `{$prefix}console_position_sessions` (position_id, console_session_id, user_id, username)
         VALUES (?, ?, ?, ?)",
        [$positionId, $consoleSessionId, $userId, $username]
    );
    return (int) db_insert_id();
}

function console_position_log_out_all(int $userId): void {
    $prefix = _console_positions_prefix();
    db_query(
        "UPDATE `{$prefix}console_position_sessions` SET logged_out_at = NOW() WHERE user_id = ? AND logged_out_at IS NULL",
        [$userId]
    );
}

/** The caller's own currently-open position_sessions row, or null. */
function console_position_current_for_user(int $userId) {
    $prefix = _console_positions_prefix();
    return db_fetch_one(
        "SELECT * FROM `{$prefix}console_position_sessions` WHERE user_id = ? AND logged_out_at IS NULL ORDER BY logged_in_at DESC LIMIT 1",
        [$userId]
    );
}

/** No-op (does not throw) if the caller has no open position -- a heartbeat with nothing to bump is not an error. */
function console_position_heartbeat(int $userId): void {
    $prefix = _console_positions_prefix();
    db_query(
        "UPDATE `{$prefix}console_position_sessions` SET last_heartbeat_at = NOW() WHERE user_id = ? AND logged_out_at IS NULL",
        [$userId]
    );
}

/**
 * Every currently-occupied position (one row per open console_position_
 * sessions row -- co-occupancy of the same position by two users is
 * allowed and shows as two rows, per spec.md's no-forced-takeover rule).
 * `stale` is computed here (age > 2x heartbeat interval), never persisted.
 */
function console_positions_presence(): array {
    $prefix = _console_positions_prefix();
    $rows = db_fetch_all(
        "SELECT s.id, s.position_id, p.label AS position_label, s.user_id, s.username,
                s.logged_in_at, s.last_heartbeat_at,
                TIMESTAMPDIFF(SECOND, s.last_heartbeat_at, NOW()) AS heartbeat_age_seconds
           FROM `{$prefix}console_position_sessions` s
           JOIN `{$prefix}console_positions` p ON p.id = s.position_id
          WHERE s.logged_out_at IS NULL
          ORDER BY p.sort_order ASC, p.id ASC, s.logged_in_at ASC"
    );
    foreach ($rows as &$r) {
        $r['stale'] = ((int) $r['heartbeat_age_seconds']) > CONSOLE_POSITION_STALE_AFTER_SECONDS;
    }
    return $rows;
}

/**
 * "Clear at handoff" -- the one write path onto comm_channel_reads this
 * thin slice ships (automatic advance-as-you-go tracking is a separate,
 * not-yet-built task). Resets every one of the position's default
 * channels to "seen as of now" in a single action, so the next occupant
 * doesn't inherit a false "you missed this" flag for traffic the outgoing
 * operator already handled.
 *
 * $requestingUserId + $canManagePositions: the caller must either
 * currently occupy this position, or hold action.manage_positions
 * (admin override) -- enforced here (not only at the API layer) so a test
 * driving this function directly still proves the boundary.
 */
function console_channel_reads_clear_handoff(int $positionId, int $requestingUserId, bool $canManagePositions): void {
    $row = console_position_get_row($positionId);
    if (!$row) {
        throw new Exception('Position not found');
    }
    if (!$canManagePositions) {
        $current = console_position_current_for_user($requestingUserId);
        if (!$current || (int) $current['position_id'] !== $positionId) {
            throw new Exception('You must be logged into this position to clear it at handoff');
        }
    }
    $prefix = _console_positions_prefix();
    $now = date('Y-m-d H:i:s');
    foreach ($row['default_channel_ids'] as $channelId) {
        db_query(
            "INSERT INTO `{$prefix}comm_channel_reads` (position_id, channel_id, last_seen_event_ref)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seen_event_ref = VALUES(last_seen_event_ref)",
            [$positionId, (int) $channelId, $now]
        );
    }
}

function console_channel_reads_for_position(int $positionId): array {
    $prefix = _console_positions_prefix();
    return db_fetch_all(
        "SELECT channel_id, last_seen_event_ref, updated_at FROM `{$prefix}comm_channel_reads` WHERE position_id = ?",
        [$positionId]
    );
}
