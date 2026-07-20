<?php
/**
 * NewUI v4.0 — APRS Location Poller
 *
 * CLI script that polls the aprs.fi API for position reports from
 * responders with APRS bindings and writes results to location_reports.
 *
 * Usage:
 *   php tools/aprs-poller.php            # normal run
 *   php tools/aprs-poller.php --dry-run  # show what would be polled, no writes
 *   php tools/aprs-poller.php --verbose  # extra output for debugging
 *
 * Cron example (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/newui/tools/aprs-poller.php
 */

// ── Bootstrap ──────────────────────────────────────────────────
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/functions.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$prefix  = $GLOBALS['db_prefix'] ?? '';
$dryRun  = in_array('--dry-run', $argv ?? [], true);
$verbose = in_array('--verbose', $argv ?? [], true);

// ── Read configuration from settings table ─────────────────────
$apiKey = get_variable('aprs_fi_api_key');
if (empty($apiKey)) {
    fwrite(STDERR, "ERROR: aprs_fi_api_key not set in settings table. Configure it in Settings > Location > APRS.\n");
    exit(1);
}

$enabled = get_variable('aprs_enabled');
if ($enabled === '0') {
    if ($verbose) {
        echo "APRS polling is disabled. Set aprs_enabled=1 in settings.\n";
    }
    exit(0);
}

$pollInterval = (int) (get_variable('aprs_poll_interval') ?: 5);

// ── Check location retention and clean old reports ─────────────
$retentionDays = (int) (get_variable('location_retention_days') ?: 90);
if ($retentionDays > 0) {
    try {
        $stmt = db_query(
            "DELETE FROM `{$prefix}location_reports`
             WHERE `received_at` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$retentionDays]
        );
        $purged = $stmt->rowCount();
        if ($purged > 0 && $verbose) {
            echo "Purged {$purged} location reports older than {$retentionDays} days.\n";
        }
    } catch (Exception $e) {
        fwrite(STDERR, "WARNING: Retention cleanup failed: " . $e->getMessage() . "\n");
    }
}

// ── Get the APRS provider ID ───────────────────────────────────
$aprsProvider = null;
try {
    $aprsProvider = db_fetch_one(
        "SELECT `id`, `enabled` FROM `{$prefix}location_providers` WHERE `code` = 'aprs'"
    );
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Cannot find APRS provider: " . $e->getMessage() . "\n");
    exit(1);
}

if (!$aprsProvider) {
    fwrite(STDERR, "ERROR: APRS provider not found in location_providers table.\n");
    exit(1);
}

if (!(int) $aprsProvider['enabled']) {
    if ($verbose) {
        echo "APRS provider is disabled in location_providers table.\n";
    }
    exit(0);
}

$aprsProviderId = (int) $aprsProvider['id'];

// ── Find all responders with active APRS bindings ──────────────
$bindings = [];
try {
    $bindings = db_fetch_all(
        "SELECT b.`responder_id`, b.`unit_identifier`
         FROM `{$prefix}unit_location_bindings` b
         WHERE b.`provider_id` = ?
           AND b.`active` = 1
           AND b.`unit_identifier` != ''",
        [$aprsProviderId]
    );
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: Cannot read bindings: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($bindings)) {
    echo "No active APRS bindings found. Nothing to poll.\n";
    exit(0);
}

// Build callsign list (deduplicated)
$callsignMap = []; // callsign => responder_id
foreach ($bindings as $b) {
    $cs = trim($b['unit_identifier']);
    if ($cs !== '') {
        $callsignMap[$cs] = (int) $b['responder_id'];
    }
}

$allCallsigns = array_keys($callsignMap);
$totalCallsigns = count($allCallsigns);

if ($verbose) {
    echo "Found {$totalCallsigns} APRS callsign(s) to poll.\n";
}

// ── Batch callsigns in groups of 20 (aprs.fi limit) ───────────
$batches  = array_chunk($allCallsigns, 20);
$updated  = 0;
$errors   = 0;

foreach ($batches as $batchIndex => $batch) {
    $callStr = implode(',', $batch);

    if ($dryRun) {
        echo "[dry-run] Would poll: {$callStr}\n";
        continue;
    }

    $url = 'https://api.aprs.fi/api/get?'
         . 'name=' . urlencode($callStr)
         . '&what=loc'
         . '&apikey=' . urlencode($apiKey)
         . '&format=json';

    if ($verbose) {
        echo "Polling batch " . ($batchIndex + 1) . "/" . count($batches) . ": {$callStr}\n";
    }

    // ── HTTP request via cURL ──────────────────────────────────
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'TicketsCAD-NewUI/4.0 APRS-Poller');
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            fwrite(STDERR, "WARNING: aprs.fi request failed (HTTP {$httpCode}): {$curlErr}\n");
            $errors++;
            continue;
        }
        $response = $body;
    } else {
        // Fallback to file_get_contents
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'TicketsCAD-NewUI/4.0 APRS-Poller',
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            fwrite(STDERR, "WARNING: aprs.fi request failed (file_get_contents)\n");
            $errors++;
            continue;
        }
    }

    // ── Parse JSON response ────────────────���───────────────────
    $data = json_decode($response, true);
    if (!is_array($data)) {
        fwrite(STDERR, "WARNING: Invalid JSON from aprs.fi\n");
        $errors++;
        continue;
    }

    if (!isset($data['result']) || strtoupper($data['result']) !== 'OK') {
        $desc = isset($data['description']) ? $data['description'] : 'unknown error';
        fwrite(STDERR, "WARNING: aprs.fi returned error: {$desc}\n");
        $errors++;
        continue;
    }

    $found = isset($data['found']) ? (int) $data['found'] : 0;
    if ($found === 0 || !isset($data['entries'])) {
        if ($verbose) {
            echo "  No entries returned for this batch.\n";
        }
        continue;
    }

    // ── Process each entry ──────────────��──────────────────────
    foreach ($data['entries'] as $entry) {
        $callsign = isset($entry['name']) ? trim($entry['name']) : '';
        $lat      = isset($entry['lat']) ? (float) $entry['lat'] : null;
        $lng      = isset($entry['lng']) ? (float) $entry['lng'] : null;

        if ($callsign === '' || $lat === null || $lng === null) {
            continue;
        }

        // Sanity check
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) {
            if ($verbose) {
                echo "  Skipping {$callsign}: invalid coordinates ({$lat}, {$lng})\n";
            }
            continue;
        }

        // Extract optional fields from aprs.fi response
        $altitude = isset($entry['altitude']) ? (float) $entry['altitude'] : null;
        $speed    = isset($entry['speed'])    ? (float) $entry['speed']    : null;
        $course   = isset($entry['course'])   ? (float) $entry['course']   : null;
        $lasttime = isset($entry['lasttime']) ? (int) $entry['lasttime']   : null;

        // Convert Unix timestamp to MySQL datetime
        $reportedAt = $lasttime ? date('Y-m-d H:i:s', $lasttime) : date('Y-m-d H:i:s');

        // Build raw_data for debugging
        $rawData = json_encode($entry);
        if (strlen($rawData) > 65000) {
            $rawData = substr($rawData, 0, 65000);
        }

        // ── Insert location report ───��─────────────────────────
        try {
            db_query(
                "INSERT INTO `{$prefix}location_reports`
                 (`provider_id`, `unit_identifier`, `lat`, `lng`, `altitude`,
                  `speed`, `heading`, `accuracy`, `battery`, `raw_data`, `reported_at`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$aprsProviderId, $callsign, $lat, $lng, $altitude,
                 $speed, $course, null, null, $rawData, $reportedAt]
            );
            $updated++;

            // Check geofences for this position
            try {
                require_once __DIR__ . '/../inc/geofence.php';
                $gfEvents = geofence_check($lat, $lng, $callsign);
                if ($verbose && !empty($gfEvents)) {
                    foreach ($gfEvents as $evt) {
                        echo "  GEOFENCE: {$callsign} {$evt['event']} '{$evt['geofence_name']}'\n";
                    }
                }
            } catch (Exception $gfErr) {
                // Non-fatal
            }

            if ($verbose) {
                echo "  Updated {$callsign}: ({$lat}, {$lng}) at {$reportedAt}\n";
            }
        } catch (Exception $e) {
            fwrite(STDERR, "WARNING: Failed to insert report for {$callsign}: " . $e->getMessage() . "\n");
            $errors++;
        }
    }

    // Rate-limit between batches to be polite to aprs.fi
    if (count($batches) > 1 && $batchIndex < count($batches) - 1) {
        usleep(500000); // 500ms between batches
    }
}

// ── Summary ───────────────────���────────────────────────────────
if ($dryRun) {
    echo "Dry run complete. {$totalCallsigns} callsign(s) would be polled.\n";
} else {
    echo "Polled {$totalCallsigns} callsigns, updated {$updated} positions";
    if ($errors > 0) {
        echo ", {$errors} errors";
    }
    echo ".\n";
}

exit($errors > 0 ? 1 : 0);
