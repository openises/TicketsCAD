<?php
/**
 * Phase 86-archive followup (#61) — fetch unknown DMR IDs from
 * radioid.net and backfill dmr_messages.radio_callsign.
 *
 * Finds every distinct dmr_messages.radio_id that isn't already in
 * the radioid_users cache, asks radioid.net for each one, upserts
 * the cache, then sweeps the message table to populate any rows
 * whose radio_callsign is still NULL but now has a cached answer.
 *
 * Polite to radioid.net:
 *   - Defaults to 0.75 s between requests (~80 req/min).
 *   - Stops on five consecutive errors so we don't hammer them when
 *     the API or the network is degraded.
 *
 * Usage:
 *   php tools/radioid_fetch_unknowns.php             # interactive run
 *   php tools/radioid_fetch_unknowns.php --batch=50  # cap per run
 *   php tools/radioid_fetch_unknowns.php --dry-run   # show what'd happen
 *
 * Safe to run on a cron (hourly or nightly). Idempotent: known IDs
 * are skipped on subsequent runs.
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$opts = getopt('', ['batch::', 'dry-run', 'sleep::']);
$batch  = isset($opts['batch']) ? (int) $opts['batch'] : 200;
$dryRun = isset($opts['dry-run']);
$sleepUs = isset($opts['sleep']) ? (int) ((float) $opts['sleep'] * 1_000_000) : 750_000;

function log_line(string $msg): void {
    echo '[' . date('H:i:s') . "] $msg\n";
}

log_line("scanning for unknown DMR IDs (batch={$batch}, sleep=" .
         round($sleepUs / 1_000_000, 2) . "s" .
         ($dryRun ? ", DRY RUN" : '') . ")");

// Step 1: find IDs that appear in dmr_messages but not in radioid_users.
$ids = db_fetch_all(
    "SELECT DISTINCT m.radio_id
       FROM `{$prefix}dmr_messages` m
       LEFT JOIN `{$prefix}radioid_users` r ON r.dmr_id = m.radio_id
      WHERE m.radio_id IS NOT NULL
        AND m.radio_id <> ''
        AND r.dmr_id IS NULL
      ORDER BY m.radio_id
      LIMIT " . (int) $batch
);
$ids = array_filter(array_map(static fn($r) => (string) $r['radio_id'], $ids), 'ctype_digit');
if (!$ids) { log_line("nothing to fetch — every seen ID is already cached"); exit(0); }
log_line("found " . count($ids) . " uncached ID(s)");

if ($dryRun) {
    foreach ($ids as $id) echo "  would fetch $id\n";
    exit(0);
}

// Step 2: hit radioid.net one at a time, upsert into cache.
$ok = 0; $miss = 0; $err = 0; $consecutive_err = 0;
foreach ($ids as $id) {
    if ($consecutive_err >= 5) {
        log_line("stopping — 5 consecutive errors, radioid.net or network unhealthy");
        break;
    }
    $url = 'https://database.radioid.net/api/dmr/user/?id=' . $id;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT      => 'TicketsCAD/4.0 (DMR archive backfill)',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        $err++; $consecutive_err++;
        log_line("  $id: HTTP $code" . ($cErr ? " ($cErr)" : '') . " — skip");
        usleep($sleepUs);
        continue;
    }
    $consecutive_err = 0;
    $j = json_decode((string) $body, true);
    if (!is_array($j) || empty($j['results'][0]['id'])) {
        $miss++;
        log_line("  $id: not in radioid.net database");
        usleep($sleepUs);
        continue;
    }
    $r = $j['results'][0];
    try {
        db_query(
            "INSERT INTO `{$prefix}radioid_users`
                (dmr_id, callsign, fname, surname, country, state, city, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               callsign=VALUES(callsign), fname=VALUES(fname),
               surname=VALUES(surname), country=VALUES(country),
               state=VALUES(state), city=VALUES(city),
               fetched_at=NOW()",
            [
                (int) $r['id'],
                substr((string) ($r['callsign'] ?? ''), 0, 16),
                substr((string) ($r['fname']    ?? ''), 0, 64),
                substr((string) ($r['surname']  ?? ''), 0, 64),
                substr((string) ($r['country']  ?? ''), 0, 64),
                substr((string) ($r['state']    ?? ''), 0, 64),
                substr((string) ($r['city']     ?? ''), 0, 64),
            ]
        );
        $ok++;
        log_line("  $id -> " . ($r['callsign'] ?: '(no callsign)') .
                 " / " . trim(($r['fname'] ?? '') . ' ' . ($r['surname'] ?? '')));
    } catch (Exception $e) {
        $err++; $consecutive_err++;
        log_line("  $id: cache write failed — " . $e->getMessage());
    }
    usleep($sleepUs);
}
log_line("fetch summary: $ok cached, $miss not-in-radioid, $err errors");

// Step 3: backfill dmr_messages.radio_callsign for anything the new
// cache rows can now resolve. Same UPDATE as the manual one-shot we
// ran inline during commit cdf19e6.
$updated = db_query(
    "UPDATE `{$prefix}dmr_messages` m
       JOIN `{$prefix}radioid_users` r ON r.dmr_id = m.radio_id
        SET m.radio_callsign = r.callsign
      WHERE (m.radio_callsign IS NULL OR m.radio_callsign = '')
        AND m.radio_id IS NOT NULL
        AND r.callsign IS NOT NULL
        AND r.callsign <> ''"
)->rowCount();
log_line("backfilled radio_callsign on $updated dmr_messages row(s)");
