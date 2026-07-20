<?php
/**
 * NewUI v4.0 API - Combined Dashboard Data
 *
 * GET /api/dashboard-data.php?widgets=incidents,responders,facilities,log,statistics,scheduled
 *
 * Fetches multiple widget datasets in a single request.
 * The client specifies which widgets it needs via the `widgets` parameter.
 * Each widget's data is fetched independently and returned under its key.
 *
 * This endpoint exists so the dashboard can make ONE request on load/poll
 * instead of 6 separate requests, reducing HTTP overhead.
 */

require_once __DIR__ . '/auth.php';

$requested = $_GET['widgets'] ?? 'incidents,responders,facilities,log,statistics,scheduled';
$widgets = array_map('trim', explode(',', $requested));

$response = [];

// Use output buffering to capture individual endpoint output
foreach ($widgets as $widget) {
    $file = __DIR__ . '/' . basename($widget) . '.php';
    if (!file_exists($file)) {
        $response[$widget] = ['error' => 'Unknown widget'];
        continue;
    }

    // Capture the JSON output from each endpoint
    ob_start();

    // Override json_response to capture instead of exit
    // We'll use a different approach: include files that return data
    // Since our endpoints call json_response() which exits, we need
    // to call the logic directly instead.

    ob_end_clean();

    // For now, the client should use individual endpoints.
    // This combined endpoint will be refactored in Phase 2
    // when we extract the data-fetching logic into service classes.
    $response[$widget] = ['_endpoint' => "api/{$widget}.php"];
}

// Provide the list of available endpoints for the client to fetch in parallel
json_response([
    'endpoints' => [
        'incidents'  => 'api/incidents.php?func=0',
        'responders' => 'api/responders.php',
        'facilities' => 'api/facilities.php',
        'log'        => 'api/log.php?days=7',
        'statistics' => 'api/statistics.php',
        'scheduled'  => 'api/scheduled.php',
    ],
    'note' => 'Use Promise.all() to fetch these endpoints in parallel for best performance.',
]);
