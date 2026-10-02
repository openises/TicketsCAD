<?php
/**
 * Phase 152 (Communications Console v2) — the thin position layer
 * (spec.md's "In scope" item, plan.md section 3).
 *
 * Three tables, deliberately thin (spec.md's own framing: naming, a
 * working channel set, per-position seen-state, presence visibility --
 * NOT the full supervisor/mute/takeover machinery this phase explicitly
 * defers):
 *
 * - console_positions: admin-authored named seats, a lightweight
 *   console_views sibling (inc/console-views.php), not a merge into it --
 *   a position is "which seat", a view is "which layout", and conflating
 *   them would make every future position feature also a layout-designer
 *   feature whether it needs to be or not.
 * - console_position_sessions: one row per "a session occupies a
 *   position". Logging into a NEW position closes any of the SAME user's
 *   other open rows first (inc/console-positions.php::console_position_
 *   log_in()) -- but logging into a position someone else already
 *   occupies is allowed and never evicts them (spec.md: "no mute-another-
 *   position, no forced takeover"). last_heartbeat_at is bumped by the
 *   same periodic call the console page already needs for its own
 *   liveness, piggybacking on one mechanism rather than inventing a
 *   second (plan.md section 3) -- there is no background job that closes
 *   a stale row; staleness is reported to the presence list, not acted on.
 * - comm_channel_reads: a per-(position, channel) seen-cursor. This
 *   migration's only writer is the "clear at handoff" action (a single
 *   button resetting every one of a position's default channels to "seen
 *   as of now") -- automatic advance-as-you-go tracking and the Recall
 *   tab's own "unseen since I logged in" filter are explicitly a LATER
 *   task (see assets/js/console-recall.js's own docblock), so an empty
 *   table on a fresh install, or a row that only ever moves on an
 *   explicit clear, is the correct, complete v1 shape -- not a partial
 *   build waiting on wiring that hasn't shipped yet.
 *
 * No foreign-key constraints, matching this project's schema-resilience
 * convention elsewhere (comm_routes, comm_channels) -- inc/console-
 * positions.php cleans up dependent rows itself on delete.
 *
 * Idempotent -- safe to run repeatedly; picked up by run_migrations.php.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- the thin position layer\n";
echo "=====================================\n\n";

$ok = true;

$tables = [];

$tables['console_positions'] = "CREATE TABLE IF NOT EXISTS `{$prefix}console_positions` (
    `id`                        INT AUTO_INCREMENT PRIMARY KEY,
    `label`                     VARCHAR(64) NOT NULL,
    `org_id`                    INT DEFAULT NULL COMMENT 'NULL = install-wide,
                                 matching console_views'' own org-nullable
                                 convention -- not enforced/filtered by this
                                 thin slice, reserved for a later org-scoped
                                 pass if ever needed',
    `default_channel_ids_json`  TEXT NOT NULL COMMENT 'JSON array of
                                 comm_channels.id -- this position''s working
                                 channel set (spec.md user story #6)',
    `sort_order`                INT NOT NULL DEFAULT 0,
    `created_by`                INT DEFAULT NULL,
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- named console
    seats (positions). Admin-authored, action.manage_positions.'";

$tables['console_position_sessions'] = "CREATE TABLE IF NOT EXISTS `{$prefix}console_position_sessions` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `position_id`           INT NOT NULL,
    `console_session_id`    INT DEFAULT NULL COMMENT 'FK-by-convention only
                             (no constraint, matching this project''s schema-
                             resilience rule) into console_sessions.id -- the
                             browser tab that logged into this position, so
                             the heartbeat call can piggyback on the same
                             console-session liveness signal instead of
                             inventing a second one (plan.md section 3)',
    `user_id`               INT NOT NULL,
    `username`              VARCHAR(64) NOT NULL COMMENT 'denormalized,
                             matching this project''s audit-log convention',
    `logged_in_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_heartbeat_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `logged_out_at`         DATETIME DEFAULT NULL COMMENT 'NULL = still
                             occupying this position. No background job ever
                             sets this on staleness alone -- spec.md''s
                             explicit deferral of forced takeover means a
                             stale row is REPORTED to the presence list, not
                             auto-closed.',
    KEY `idx_position_open` (`position_id`, `logged_out_at`),
    KEY `idx_user_open` (`user_id`, `logged_out_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- who is
    currently logged into which console position (presence list source).'";

$tables['comm_channel_reads'] = "CREATE TABLE IF NOT EXISTS `{$prefix}comm_channel_reads` (
    `id`                    INT AUTO_INCREMENT PRIMARY KEY,
    `position_id`           INT NOT NULL,
    `channel_id`            INT NOT NULL,
    `last_seen_event_ref`   VARCHAR(64) DEFAULT NULL COMMENT 'an opaque
                             cursor value (currently: the clear-at-handoff
                             timestamp) -- not a foreign key into any one
                             adapter''s own history table, since the shape
                             of \"the last thing seen\" differs per adapter',
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_position_channel` (`position_id`, `channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Phase 152 -- per-position,
    per-channel seen-cursor. Written today only by the \"clear at handoff\"
    action; automatic advance-as-you-go and the Recall tab''s unseen-only
    filter are a separate, not-yet-built task.'";

foreach ($tables as $name => $sql) {
    try {
        db_query($sql);
        echo "[OK] {$name} table ready\n";
    } catch (Exception $e) {
        fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . "\n");
        $ok = false;
    }
}

// Verify (Phase 128 A9b lesson: a migration that swallows its own failure
// and exits 0 is a migration that never ran).
foreach (array_keys($tables) as $name) {
    $exists = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . $name]
    );
    if ((int) $exists !== 1) {
        fwrite(STDERR, "[FAIL] verification: {$name} not found after migration\n");
        $ok = false;
    }
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
