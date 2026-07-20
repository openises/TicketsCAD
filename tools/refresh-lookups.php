<?php
/**
 * Phase 41 — Lookup table refresh runner.
 *
 * Eric's cadence:
 *   - Zip codes: twice a year
 *   - FCC Amateur:  monthly
 *   - FCC GMRS:     monthly
 *
 * This script downloads the latest source files, extracts, runs the
 * existing importers, and cleans up. Intended to run from cron.
 *
 * Usage:
 *   php tools/refresh-lookups.php [--zip] [--fcc-amateur] [--fcc-gmrs] [--all]
 *
 * Suggested cron lines (run as the web user):
 *   # FCC Amateur + GMRS — first Sunday of each month at 03:30 UTC
 *   30 3 1-7 * 0 /usr/bin/php /var/www/newui/tools/refresh-lookups.php --fcc-amateur --fcc-gmrs >> /var/log/newui/refresh-lookups.log 2>&1
 *
 *   # Zip codes — first Sunday of January & July at 03:45 UTC
 *   45 3 1-7 1,7 0 /usr/bin/php /var/www/newui/tools/refresh-lookups.php --zip >> /var/log/newui/refresh-lookups.log 2>&1
 *
 * The runner is safe to re-run (importers are idempotent — they
 * re-create staging tables, MERGE into the real table, then drop
 * staging on completion).
 */

require_once __DIR__ . '/../config.php';

$opts = [
    'zip'        => in_array('--zip', $argv) || in_array('--all', $argv),
    'amateur'    => in_array('--fcc-amateur', $argv) || in_array('--all', $argv),
    'gmrs'       => in_array('--fcc-gmrs', $argv) || in_array('--all', $argv),
];

if (!$opts['zip'] && !$opts['amateur'] && !$opts['gmrs']) {
    fwrite(STDERR, "usage: php tools/refresh-lookups.php [--zip] [--fcc-amateur] [--fcc-gmrs] [--all]\n");
    exit(1);
}

$dataDir = __DIR__ . '/../data';
@mkdir($dataDir, 0755, true);

function _log(string $msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function _run(string $cmd) {
    _log("\$ {$cmd}");
    passthru($cmd, $rc);
    return $rc === 0;
}

if ($opts['zip']) {
    _log("==> Refreshing US zip codes (GeoNames)");
    $url  = 'https://download.geonames.org/export/zip/US.zip';
    $zip  = "{$dataDir}/US.zip";
    $txt  = "{$dataDir}/US.txt";
    if (!_run("curl -sSfL -o " . escapeshellarg($zip) . " " . escapeshellarg($url))) {
        _log("[ERR] zip download failed");
    } elseif (!_run("unzip -qo " . escapeshellarg($zip) . " -d " . escapeshellarg($dataDir))) {
        _log("[ERR] zip extract failed");
    } else {
        _run("php " . escapeshellarg(__DIR__ . '/import-zipcodes.php') . " " . escapeshellarg($txt) . " --format=geonames");
        @unlink($zip);
        @unlink($txt);
        @unlink("{$dataDir}/readme.txt");
        _log("[OK] zip codes refreshed");
    }
}

foreach ([['amateur', 'l_amat.zip'], ['gmrs', 'l_gmrs.zip']] as $pair) {
    [$type, $file] = $pair;
    if (!$opts[$type]) continue;
    _log("==> Refreshing FCC {$type}");
    $url = "https://data.fcc.gov/download/pub/uls/complete/{$file}";
    $zip = "{$dataDir}/{$file}";
    $ext = "{$dataDir}/l_" . ($type === 'amateur' ? 'amat' : 'gmrs');
    if (!_run("curl -sSfL -o " . escapeshellarg($zip) . " " . escapeshellarg($url))) {
        _log("[ERR] {$type} download failed");
        continue;
    }
    @mkdir($ext, 0755, true);
    if (!_run("unzip -qo " . escapeshellarg($zip) . " -d " . escapeshellarg($ext))) {
        _log("[ERR] {$type} extract failed");
        continue;
    }
    if (!_run("php " . escapeshellarg(__DIR__ . '/import-fcc.php') . " {$type} " . escapeshellarg($ext))) {
        _log("[ERR] {$type} import failed");
    } else {
        _log("[OK] FCC {$type} refreshed");
    }
    // Clean up source files — the rows are now in the DB.
    @passthru("rm -rf " . escapeshellarg($ext) . " " . escapeshellarg($zip));
}

_log("Done.");
