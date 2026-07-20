<?php
/**
 * NewUI v4.0 API — Location History
 *
 * Returns historical position data for route playback.
 *
 * GET /api/location-history.php?responder_id=X&start=DATETIME&end=DATETIME
 *   — returns all location reports for a responder in the time range
 *
 * GET /api/location-history.php?responder_id=X&hours=24
 *   — returns last N hours of reports (convenience shorthand)
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/access.php';

ini_set('display_errors', '0');

$prefix = $GLOBALS['db_prefix'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

$responderId = isset($_GET['responder_id']) ? (int) $_GET['responder_id'] : 0;
if (!$responderId) {
    json_error('responder_id is required');
}

// IDOR check (F-006) — non-admins must be in a group allocated to this responder.
// 90-day GPS history is a privacy-critical resource; deny early.
if (!user_can_access_entity('responder', $responderId)) {
    json_error('Responder not found', 404);
}

// Determine time range
$start = null;
$end   = null;

if (isset($_GET['start'])) {
    $start = $_GET['start'];
} elseif (isset($_GET['hours'])) {
    $hours = max(1, min(2160, (int) $_GET['hours'])); // 1 hour to 90 days
    $start = date('Y-m-d H:i:s', time() - ($hours * 3600));
} else {
    // Default: last 24 hours
    $start = date('Y-m-d H:i:s', time() - 86400);
}

$end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d H:i:s');

// Get all bindings for this responder to find matching reports
try {
    $bindings = db_fetch_all(
        "SELECT b.`unit_identifier`, b.`provider_id`,
                lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                lp.`icon`, lp.`color`
         FROM `{$prefix}unit_location_bindings` b
         JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
         WHERE b.`responder_id` = ?
           AND b.`active` = 1
         ORDER BY b.`priority` ASC",
        [$responderId]
    );
} catch (Exception $e) {
    $bindings = [];
}

if (empty($bindings)) {
    json_response([
        'points' => [],
        'count' => 0,
        'responder_id' => $responderId,
        'start' => $start,
        'end' => $end,
    ]);
}

// Build list of unit_identifiers to query
$identifiers = [];
$providerMap = [];
foreach ($bindings as $b) {
    $identifiers[] = $b['unit_identifier'];
    $providerMap[$b['unit_identifier']] = $b;
}

// Get location reports in time range
$placeholders = implode(',', array_fill(0, count($identifiers), '?'));
$params = array_merge($identifiers, [$start, $end]);

try {
    $points = db_fetch_all(
        "SELECT lr.`lat`, lr.`lng`, lr.`altitude`, lr.`speed`,
                lr.`heading`, lr.`accuracy`, lr.`battery`,
                lr.`reported_at`, lr.`unit_identifier`,
                lp.`code` AS `provider_code`, lp.`name` AS `provider_name`,
                lp.`icon`, lp.`color`
         FROM `{$prefix}location_reports` lr
         JOIN `{$prefix}location_providers` lp ON lr.`provider_id` = lp.`id`
         WHERE lr.`unit_identifier` IN ($placeholders)
           AND lr.`reported_at` BETWEEN ? AND ?
         ORDER BY lr.`reported_at` ASC",
        $params
    );
} catch (Exception $e) {
    $points = [];
}

// Get responder info for the response
$responderName = '';
try {
    $r = db_fetch_one(
        "SELECT `name`, `handle`, `callsign` FROM `{$prefix}responder` WHERE `id` = ?",
        [$responderId]
    );
    if ($r) {
        $responderName = $r['name'] ?? $r['handle'] ?? '';
    }
} catch (Exception $e) {}

json_response([
    'points'        => $points,
    'count'         => count($points),
    'responder_id'  => $responderId,
    'responder_name' => $responderName,
    'start'         => $start,
    'end'           => $end,
]);
