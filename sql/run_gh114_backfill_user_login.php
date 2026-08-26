<?php
/**
 * GH#114 (rjonesbsink, 2026-08-25) — user.login backfill from audit history.
 *
 * user.login ("Last login", Settings -> User Accounts) was read and
 * displayed everywhere but never written by any code path -- every
 * account showed "never" regardless of real login history. Fixed at the
 * source in login.php's complete_login(), which now writes it on every
 * successful login going forward. That alone leaves every EXISTING
 * account showing "never" until its next login, even ones that have
 * signed in for months.
 *
 * newui_audit_log already has that history: every successful login calls
 * audit_login() with category='auth', activity='login'. This script
 * backfills user.login from the most recent such row per user, subject to
 * whatever audit retention has already pruned (a genuinely never-logged-in
 * account, or one whose entire login history has aged out of retention,
 * correctly stays NULL -- "never" is still the honest answer there).
 *
 * Idempotent and safe to re-run: only ever moves a user's login value
 * FORWARD to a more recent audit timestamp (or fills it from NULL), never
 * backward, so re-running after more logins have accrued just catches
 * those up too. Never touches a user whose audit history is empty.
 *
 * Usage: php sql/run_gh114_backfill_user_login.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $auditTable = $prefix . 'newui_audit_log';
    $hasAuditTable = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$auditTable]
    );
    if (!$hasAuditTable) {
        echo "[SKIP] {$auditTable} not present — nothing to backfill from\n";
        exit(0);
    }

    $updated = db_query(
        "UPDATE `{$prefix}user` u
           JOIN (
               SELECT user_id, MAX(event_time) AS last_login
                 FROM `{$auditTable}`
                WHERE category = 'auth' AND activity = 'login' AND user_id IS NOT NULL
                GROUP BY user_id
           ) latest ON latest.user_id = u.id
            SET u.login = latest.last_login
          WHERE u.login IS NULL OR u.login < latest.last_login"
    )->rowCount();

    echo "[OK] backfilled user.login for {$updated} account(s) from existing audit login history\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
