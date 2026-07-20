<?php
/**
 * Phase 29B (2026-06-12) — par_last_overdue_broadcast_at
 *
 * Eric: "don't we already have a pattern for this, relating to
 * messaging? Wouldn't we want this to appear there as a message,
 * possibly with a reminder until the messages are read?"
 *
 * Right. PAR-overdue alerts now flow through the existing internal-
 * messaging broadcast system (urgent + is_broadcast) which already
 * has a notification-tray + bell badge + audio + SSE push. Much
 * better than reinventing it as a custom banner.
 *
 * Dedup needs a column to track when we last broadcast about a given
 * incident going overdue, so the same incident doesn't generate one
 * urgent message per 10-second poll. We only re-broadcast after one
 * full cadence interval has passed without a fresh PAR cycle starting.
 *
 * Idempotent. Safe to re-run.
 */
require_once __DIR__ . '/../config.php';

echo "Phase 29B — par_last_overdue_broadcast_at\n";
echo "=========================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = 'par_last_overdue_broadcast_at'",
        [$prefix . 'ticket']
    );
    if (!$col) {
        db_query(
            "ALTER TABLE `{$prefix}ticket`
             ADD COLUMN `par_last_overdue_broadcast_at` DATETIME NULL DEFAULT NULL
             COMMENT 'Phase 29B: when an urgent broadcast was last sent about this incident going PAR-overdue. NULL = never broadcast for current overdue state.'"
        );
        echo "[OK] Added ticket.par_last_overdue_broadcast_at\n";
    } else {
        echo "[OK] ticket.par_last_overdue_broadcast_at already exists\n";
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nDone.\n";
