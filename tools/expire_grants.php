<?php
/**
 * Expire-grants cron job.
 *
 * Sweeps `user_roles` rows whose `expires_at` has passed, deletes them,
 * and writes an audit_log entry per row recording the auto-expiration.
 *
 * Security note: this is bookkeeping, NOT a security boundary. The
 * permission-check helper rbac_can() filters expired grants at query
 * time (`WHERE expires_at IS NULL OR expires_at > NOW()`), so an
 * expired row is already invisible to authorization checks the
 * instant the clock passes the expiry. The cron just keeps the
 * table tidy and produces matching `expire` audit entries to close
 * out the `grant`/`expire` audit pairs.
 *
 * Suggested schedule: nightly. Hourly is fine; sub-minute is overkill.
 *
 * Linux cron example (systemd-timer or /etc/cron.d):
 *   0 3 * * *  ejosterberg  /usr/bin/php /var/www/newui/tools/expire_grants.php
 *
 * Windows Task Scheduler example (XAMPP path):
 *   schtasks /create /tn "TicketsCAD expire grants" /tr ^
 *     "C:\xampp\8.2.4\php\php.exe C:\xampp\8.2.4\htdocs\newui\tools\expire_grants.php" ^
 *     /sc daily /st 03:00
 *
 * Exit codes:
 *   0  success (zero or more grants swept)
 *   1  unexpected error (database unreachable, etc.)
 *
 * Output (stdout) is suitable for cron-mailing — single summary line
 * when nothing was due, multi-line when grants were swept.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac_grant.php';

try {
    $start = microtime(true);
    $count = rbac_expire_due_grants();
    $ms    = (int) ((microtime(true) - $start) * 1000);

    $stamp = date('Y-m-d H:i:s');
    if ($count === 0) {
        echo "[$stamp] expire_grants: 0 due, {$ms}ms\n";
    } else {
        echo "[$stamp] expire_grants: $count grant(s) expired in {$ms}ms\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] expire_grants ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
