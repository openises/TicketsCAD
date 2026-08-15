<?php
/**
 * GH #65 (Ron Jones, 2026-08-15) — retroactive repair for the reopen/
 * silent-reclose bug: a manual close never cleared
 * ticket.auto_close_scheduled_at, so a closed ticket could carry a
 * long-past scheduled-close timestamp with no way to trigger while
 * closed (the sweep requires status <> 1). Reopening put it back in the
 * sweep's path with an already-expired timer, and the very next sweep
 * silently re-closed it -- no audit entry, indistinguishable from
 * nothing having happened.
 *
 * inc/incident-write.php now clears the marker on every close going
 * forward (auto_close_clear_on_close()), but that only guards closes
 * that happen AFTER this fix is deployed. Any ticket that was already
 * closed with the marker armed BEFORE that -- Ron found 7 of 14 closed
 * incidents on his install carrying one -- is still a live trap: closed
 * carries no invariant that the marker is clear, and reopening any of
 * them races the same stale timer this fix exists to prevent.
 *
 * This is a one-time data repair, not a schema change: a closed ticket
 * should never carry an armed marker (that is exactly the invariant
 * auto_close_clear_on_close() now maintains going forward), so making
 * it true retroactively is safe and has no other effect -- the marker
 * is purely internal bookkeeping for the sweep, never displayed, and an
 * already-closed ticket cannot be swept regardless of the marker's
 * value.
 *
 * Purpose:  Clears auto_close_scheduled_at on every currently-closed
 *           ticket that still has one set.
 * Usage:    php sql/run_gh65_clear_stale_auto_close.php
 * Prerequisites: config.php with valid database credentials; ticket
 *                table (auto_close_scheduled_at is self-healed by
 *                inc/auto_close.php::auto_close_ensure_column() if the
 *                install has never used the feature -- absent means
 *                nothing to repair, not an error).
 * Safety:   Idempotent -- the WHERE clause only ever matches rows still
 *           in the bad state, so a second run always affects 0 rows.
 * Output:   [OK] <n> repaired, [SKIP] if the column doesn't exist yet
 *           (feature never used on this install), [WARN] on error.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $cols = db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$prefix}ticket'
           AND COLUMN_NAME = 'auto_close_scheduled_at'"
    );
    if (empty($cols)) {
        echo "[SKIP] ticket.auto_close_scheduled_at does not exist on this install (auto-close never used) — nothing to repair\n";
    } else {
        $before = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE `status` = 1 AND `auto_close_scheduled_at` IS NOT NULL"
        );
        if ($before === 0) {
            echo "[SKIP] no closed ticket currently carries an armed auto_close_scheduled_at marker\n";
        } else {
            db_query(
                "UPDATE `{$prefix}ticket` SET `auto_close_scheduled_at` = NULL
                 WHERE `status` = 1 AND `auto_close_scheduled_at` IS NOT NULL"
            );
            echo "[OK] cleared a stale auto_close_scheduled_at marker on {$before} closed ticket(s)\n";
        }
    }
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

echo "Done.\n";
