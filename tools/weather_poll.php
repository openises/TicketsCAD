<?php
/**
 * NewUI v4.0 — Weather-alert poller (Phase 112, Phase 1).
 *
 * CLI entry point for cron / systemd timer. One invocation = one poll cycle:
 * fetch active NWS alerts for each configured coverage area, de-dup, match
 * rules, and notify the tray. Fully inert unless weather_alerts_enabled = '1'.
 *
 * Usage:
 *   php tools/weather_poll.php            # normal run
 *   php tools/weather_poll.php --dry-run  # evaluate live NWS, write/emit nothing
 *   php tools/weather_poll.php --verbose  # print the full summary
 *
 * Cron example (every minute; the engine self-throttles by nothing here — cron
 * cadence IS the poll cadence, so match it to weather_poll_seconds):
 *   * * * * * php /var/www/newui/tools/weather_poll.php >/dev/null 2>&1
 *
 * systemd timer is preferred on training/Bloomington — see
 * docs/WEATHER-ALERTS-GUIDE.md for the unit files.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/weather_alerts.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$dryRun  = in_array('--dry-run', $argv ?? [], true);
$verbose = in_array('--verbose', $argv ?? [], true);

if (!weather_enabled()) {
    if ($verbose) echo "Weather alerts disabled (weather_alerts_enabled != 1). Nothing to do.\n";
    exit(0);
}

$warning = weather_config_warning();
if ($warning !== '') {
    fwrite(STDERR, "WARN: {$warning}\n");
    exit(1);
}

$summary = weather_poll_run($dryRun, null);

// Record the last successful run so the page-load catch-up path can throttle.
if (!$dryRun) {
    try {
        $prefix = $GLOBALS['db_prefix'] ?? '';
        db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_last_poll_at', ?)
                  ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [(string) time()]);
    } catch (Throwable $e) { /* non-fatal */ }
}

if ($verbose || !empty($summary['errors'])) {
    echo "Weather poll: " . ($dryRun ? '[dry-run] ' : '')
       . "areas={$summary['areas']} fetched={$summary['fetched']} "
       . "matched={$summary['matched']} notified={$summary['notified']}\n";
    foreach ($summary['errors'] as $e) echo "  ERROR: {$e}\n";
    if ($summary['warning'] !== '') echo "  WARNING: {$summary['warning']}\n";
}

exit(empty($summary['errors']) ? 0 : 1);
