<?php
/**
 * NewUI v4.0 API - Zip Code Lookup
 *
 * GET ?zip=55401
 *   Returns: { found: true, zip, city, state, county, lat, lng }
 *
 * GET ?city=Minneapolis&state=MN
 *   Returns: { results: [{ zip, city, state, county }, ...] }
 *
 * Uses local zipcodes table (imported via tools/import-zipcodes.php).
 * Falls back gracefully if table doesn't exist.
 */

require_once __DIR__ . '/auth.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';
$zip    = trim($_GET['zip'] ?? '');
$city   = trim($_GET['city'] ?? '');
$state  = trim($_GET['state'] ?? '');

// ── Zip → City/State ────────────────────────────────────────
if (!empty($zip)) {
    // Normalize — strip spaces, allow partial (5 from 9-digit)
    $zip = preg_replace('/[^0-9]/', '', $zip);
    if (strlen($zip) > 5) $zip = substr($zip, 0, 5);
    if (strlen($zip) < 3) {
        json_error('Zip code too short');
    }

    // Pad leading zero for northeast
    if (strlen($zip) === 4) $zip = '0' . $zip;

    try {
        $row = db_fetch_one(
            "SELECT `zip`, `city`, `state`, `county`, `lat`, `lng`, `timezone`
             FROM `{$prefix}zipcodes` WHERE `zip` = ? LIMIT 1",
            [$zip]
        );

        if ($row) {
            ini_set('display_errors', $prevDisplay);
            json_response([
                'found'    => true,
                'zip'      => $row['zip'],
                'city'     => $row['city'],
                'state'    => $row['state'],
                'county'   => $row['county'] ?? '',
                'lat'      => $row['lat'] ? (float) $row['lat'] : null,
                'lng'      => $row['lng'] ? (float) $row['lng'] : null,
                'timezone' => $row['timezone'] ?? '',
            ]);
        } else {
            ini_set('display_errors', $prevDisplay);
            json_response(['found' => false, 'zip' => $zip]);
        }
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_response([
            'found'   => false,
            'zip'     => $zip,
            'message' => 'Zip code database not available. Import with: php tools/import-zipcodes.php',
        ]);
    }
}

// ── City+State → Zip list ───────────────────────────────────
elseif (!empty($city) && !empty($state)) {
    try {
        $rows = db_fetch_all(
            "SELECT `zip`, `city`, `state`, `county`
             FROM `{$prefix}zipcodes`
             WHERE UPPER(`city`) LIKE ? AND UPPER(`state`) = ?
             ORDER BY `zip`
             LIMIT 50",
            ['%' . strtoupper($city) . '%', strtoupper($state)]
        );

        ini_set('display_errors', $prevDisplay);
        json_response([
            'results' => $rows,
            'count'   => count($rows),
        ]);
    } catch (Exception $e) {
        ini_set('display_errors', $prevDisplay);
        json_response(['results' => [], 'count' => 0]);
    }
}

else {
    ini_set('display_errors', $prevDisplay);
    json_error('Provide ?zip=XXXXX or ?city=Name&state=XX');
}
