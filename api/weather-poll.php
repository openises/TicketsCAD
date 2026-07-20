<?php
/**
 * NewUI v4.0 API — Weather-alert page-load catch-up poller (Phase 112).
 *
 * The shared-hosting fallback for installs without cron/systemd (Phase 106
 * Option B pattern). Any authenticated dispatcher's browser can ping this on a
 * heartbeat; it SELF-THROTTLES to weather_poll_seconds using a last-run stamp,
 * so hammering it is cheap (returns {throttled:true} without touching NWS).
 *
 * Inert unless weather_alerts_enabled = '1'. Time-boxed: one poll cycle, never
 * blocks a page beyond the NWS fetch timeout. On cron/systemd installs this
 * endpoint simply reports "throttled" because the CLI poller keeps the stamp
 * fresh — so wiring the heartbeat in is harmless everywhere.
 */

require_once __DIR__ . '/auth.php';                 // logged-in only
require_once __DIR__ . '/../inc/weather_alerts.php';

ini_set('display_errors', '0');
$prefix = $GLOBALS['db_prefix'] ?? '';

if (!weather_enabled()) {
    json_response(['ran' => false, 'reason' => 'disabled']);
}

$warning = weather_config_warning();
if ($warning !== '') {
    json_response(['ran' => false, 'reason' => 'config', 'warning' => $warning]);
}

// Throttle: only poll if at least weather_poll_seconds have elapsed.
$interval = max(30, (int) weather_setting('weather_poll_seconds', '60'));
$last     = (int) weather_setting('weather_last_poll_at', '0');
$now      = time();
if ($last > 0 && ($now - $last) < $interval) {
    json_response(['ran' => false, 'reason' => 'throttled', 'next_in' => $interval - ($now - $last)]);
}

// Claim the slot BEFORE polling so concurrent heartbeats don't double-poll.
try {
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_last_poll_at', ?)
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [(string) $now]);
} catch (Throwable $e) { /* non-fatal */ }

$summary = weather_poll_run(false, null);
json_response(['ran' => true, 'summary' => $summary]);
