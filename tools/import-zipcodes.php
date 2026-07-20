<?php
/**
 * Import US Zip Code data from CSV into the zipcodes table.
 *
 * Supports multiple CSV formats:
 *   - SimpleMaps:  zip, city, state_id, state_name, ...lat, lng...
 *   - GeoNames:    country_code, postal_code, place_name, admin_name1, admin_code1, ...lat, lng
 *   - zip-codes.com: zip, type, decommissioned, primary_city, ...state, ...
 *   - Generic:     Any CSV with headers containing zip/postal, city/place, state columns
 *
 * Usage:
 *   php tools/import-zipcodes.php <csv-file> [--format=auto|simplemaps|geonames|generic]
 *   php tools/import-zipcodes.php data/uszips.csv
 *   php tools/import-zipcodes.php data/US.txt --format=geonames
 */

require_once __DIR__ . '/../config.php';

// ── Parse args ──────────────────────────────────────────────
$file   = isset($argv[1]) ? $argv[1] : '';
$format = 'auto';

foreach ($argv as $arg) {
    if (strpos($arg, '--format=') === 0) {
        $format = substr($arg, 9);
    }
}

if (empty($file) || !is_file($file)) {
    echo "Usage: php tools/import-zipcodes.php <csv-file> [--format=auto|simplemaps|geonames|generic]\n";
    echo "\nSupported sources:\n";
    echo "  SimpleMaps:   https://simplemaps.com/data/us-zips (download free CSV)\n";
    echo "  GeoNames:     https://download.geonames.org/export/zip/US.zip\n";
    echo "  zip-codes.com: https://www.zip-codes.com/free-zip-code-database.asp\n";
    echo "  Any CSV with zip, city, state columns\n";
    exit(1);
}

// ── Ensure table exists ─────────────────────────────────────
$prefix = $GLOBALS['db_prefix'] ?? '';
try {
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}zipcodes` (
        `zip`       VARCHAR(10)   NOT NULL PRIMARY KEY,
        `city`      VARCHAR(64)   NOT NULL,
        `state`     VARCHAR(4)    NOT NULL,
        `county`    VARCHAR(64)   DEFAULT NULL,
        `lat`       DOUBLE        DEFAULT NULL,
        `lng`       DOUBLE        DEFAULT NULL,
        `timezone`  VARCHAR(48)   DEFAULT NULL,
        KEY `idx_city_state` (`city`, `state`),
        KEY `idx_state` (`state`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] zipcodes table ready\n";
} catch (Exception $e) {
    echo "[WARN] " . $e->getMessage() . "\n";
}

// ── Detect format ───────────────────────────────────────────
$handle = fopen($file, 'r');
if (!$handle) {
    echo "ERROR: Cannot open file: $file\n";
    exit(1);
}

// Read first line to detect format
$firstLine = fgets($handle);
$firstLine = trim($firstLine);

// Detect delimiter
$delimiter = ',';
if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
    $delimiter = "\t";
}

$headers = str_getcsv($firstLine, $delimiter);
$headers = array_map('strtolower', array_map('trim', $headers));

// GeoNames raw format has NO header row — detect by checking if first field is a country code
$isGeoNamesRaw = false;
if ($format === 'geonames' || ($format === 'auto' && preg_match('/^[A-Z]{2}$/', $headers[0]))) {
    // First line looks like data (country code), not a header
    $isGeoNamesRaw = true;
    $format = 'geonames';
}

if ($format === 'auto') {
    if (in_array('state_id', $headers) && in_array('city', $headers)) {
        $format = 'simplemaps';
    } elseif (in_array('country_code', $headers) || in_array('country code', $headers)) {
        $format = 'geonames';
    } elseif (in_array('primary_city', $headers)) {
        $format = 'zipcodes_com';
    } else {
        $format = 'generic';
    }
}

echo "Detected format: $format\n";
echo "Delimiter: " . ($delimiter === "\t" ? "TAB" : "COMMA") . "\n";

// ── Column mapping ──────────────────────────────────────────
function findCol($headers, $names) {
    foreach ($names as $n) {
        $idx = array_search(strtolower($n), $headers);
        if ($idx !== false) return $idx;
    }
    return false;
}

// GeoNames positional columns (no header):
// 0:country 1:postal_code 2:place_name 3:admin_name1(state name) 4:admin_code1(state code)
// 5:admin_name2(county) 6:admin_code2 7:admin_name3 8:admin_code3 9:latitude 10:longitude 11:accuracy
if ($format === 'geonames' && $isGeoNamesRaw) {
    $colZip    = 1;
    $colCity   = 2;
    $colState  = 4;  // admin_code1 = state abbreviation
    $colCounty = 5;  // admin_name2 = county
    $colLat    = 9;
    $colLng    = 10;
    $colTz     = false;

    // Rewind — first line was data, not a header
    rewind($handle);
    echo "GeoNames raw format (no header row) — using positional columns\n";
} else {
    $colZip   = findCol($headers, ['zip', 'zipcode', 'zip_code', 'postal_code', 'postalcode']);
    $colCity  = findCol($headers, ['city', 'primary_city', 'place_name', 'place name', 'acceptable_cities']);
    $colState = findCol($headers, ['state_id', 'state', 'state_code', 'admin_code1', 'admin code1']);
    $colCounty = findCol($headers, ['county', 'county_name', 'admin_name2', 'admin name2']);
    $colLat   = findCol($headers, ['lat', 'latitude']);
    $colLng   = findCol($headers, ['lng', 'longitude', 'lon', 'long']);
    $colTz    = findCol($headers, ['timezone', 'tz', 'time_zone']);
}

if ($colZip === false || $colCity === false || $colState === false) {
    echo "ERROR: Cannot find required columns (zip, city, state) in headers:\n";
    echo "  " . implode(', ', $headers) . "\n";
    exit(1);
}

echo "Column mapping: zip=$colZip, city=$colCity, state=$colState";
if ($colCounty !== false) echo ", county=$colCounty";
if ($colLat !== false) echo ", lat=$colLat";
if ($colLng !== false) echo ", lng=$colLng";
echo "\n\n";

// ── Import rows ─────────────────────────────────────────────
$imported = 0;
$skipped  = 0;
$errors   = 0;
$batch    = [];
$batchSize = 500;

$sql = "INSERT INTO `{$prefix}zipcodes` (`zip`, `city`, `state`, `county`, `lat`, `lng`, `timezone`)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE `city` = VALUES(`city`), `state` = VALUES(`state`),
        `county` = VALUES(`county`), `lat` = VALUES(`lat`), `lng` = VALUES(`lng`),
        `timezone` = VALUES(`timezone`)";

while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
    if (count($line) <= max($colZip, $colCity, $colState)) {
        $skipped++;
        continue;
    }

    $zip   = trim($line[$colZip]);
    $city  = trim($line[$colCity]);
    $state = trim($line[$colState]);

    // Skip obviously bad data
    if (empty($zip) || empty($city) || empty($state) || strlen($state) > 4) {
        $skipped++;
        continue;
    }

    // Normalize 4-digit zips (leading zero)
    if (strlen($zip) === 4 && is_numeric($zip)) {
        $zip = '0' . $zip;
    }

    $county = ($colCounty !== false && isset($line[$colCounty])) ? trim($line[$colCounty]) : null;
    $lat    = ($colLat !== false && isset($line[$colLat]) && is_numeric($line[$colLat])) ? (float) $line[$colLat] : null;
    $lng    = ($colLng !== false && isset($line[$colLng]) && is_numeric($line[$colLng])) ? (float) $line[$colLng] : null;
    $tz     = ($colTz !== false && isset($line[$colTz])) ? trim($line[$colTz]) : null;

    try {
        db_query($sql, [$zip, $city, $state, $county, $lat, $lng, $tz]);
        $imported++;
    } catch (Exception $e) {
        $errors++;
        if ($errors <= 5) echo "[ERR] $zip: " . $e->getMessage() . "\n";
    }

    if ($imported % 5000 === 0 && $imported > 0) {
        echo "  ... $imported imported\n";
    }
}

fclose($handle);

echo "\nDone!\n";
echo "  Imported: $imported\n";
echo "  Skipped:  $skipped\n";
echo "  Errors:   $errors\n";
