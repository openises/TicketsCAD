<?php
/**
 * NewUI v4.0 API - Service Uptime History
 *
 * GET  /api/service-uptime.php                — All recent events (last 7 days)
 * GET  /api/service-uptime.php?service=X      — Events for specific service
 * GET  /api/service-uptime.php?days=N         — Events for last N days (max 90)
 * GET  /api/service-uptime.php?summary=1      — Per-service uptime summary
 *
 * Admin-only (level <= 1).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../inc/rbac.php';

$prevDisplay = ini_get('display_errors');
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405);
}

if (!is_admin()) {
    json_error('Admin access required', 403);
}

// Summary mode: per-service uptime stats
if (!empty($_GET['summary'])) {
    handleSummary();
} else {
    handleList();
}

ini_set('display_errors', $prevDisplay);

function handleList() {
    $days = min(max(intval($_GET['days'] ?? 7), 1), 90);
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $where = ['se.detected_at >= ?'];
    $params = [$since];

    if (!empty($_GET['service'])) {
        $where[] = 'se.service = ?';
        $params[] = $_GET['service'];
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    try {
        $events = db_fetch_all(
            "SELECT se.*
             FROM " . db_table('newui_service_events') . " se
             {$whereSQL}
             ORDER BY se.detected_at DESC
             LIMIT 500",
            $params
        );
    } catch (Exception $e) {
        $events = [];
    }

    // Also return current state
    try {
        $states = db_fetch_all(
            "SELECT * FROM " . db_table('newui_service_state') . " ORDER BY service"
        );
    } catch (Exception $e) {
        $states = [];
    }

    json_response([
        'events' => $events,
        'states' => $states,
        'days'   => $days,
    ]);
}

function handleSummary() {
    $days = min(max(intval($_GET['days'] ?? 30), 1), 90);
    $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    try {
        // Count events per service per type
        $counts = db_fetch_all(
            "SELECT service, event_type, COUNT(*) AS cnt
             FROM " . db_table('newui_service_events') . "
             WHERE detected_at >= ?
             GROUP BY service, event_type
             ORDER BY service, event_type",
            [$since]
        );

        // Current state
        $states = db_fetch_all(
            "SELECT * FROM " . db_table('newui_service_state') . " ORDER BY service"
        );

        // Calculate time since last outage per service
        $lastOutages = db_fetch_all(
            "SELECT service, MAX(detected_at) AS last_outage
             FROM " . db_table('newui_service_events') . "
             WHERE event_type IN ('crash', 'stop')
               AND detected_at >= ?
             GROUP BY service",
            [$since]
        );
    } catch (Exception $e) {
        $counts = [];
        $states = [];
        $lastOutages = [];
    }

    // Build per-service summary
    $summary = [];
    foreach ($states as $s) {
        $svc = $s['service'];
        $summary[$svc] = [
            'service'        => $svc,
            'current_status' => $s['last_status'],
            'last_checked'   => $s['last_checked'],
            'uptime_seconds' => $s['last_uptime_sec'],
            'consecutive_failures' => (int) $s['consecutive_failures'],
            'events'         => [],
            'last_outage'    => null,
        ];
    }

    foreach ($counts as $c) {
        $svc = $c['service'];
        if (!isset($summary[$svc])) {
            $summary[$svc] = [
                'service' => $svc,
                'current_status' => 'unknown',
                'events' => [],
                'last_outage' => null,
            ];
        }
        $summary[$svc]['events'][$c['event_type']] = (int) $c['cnt'];
    }

    foreach ($lastOutages as $lo) {
        $svc = $lo['service'];
        if (isset($summary[$svc])) {
            $summary[$svc]['last_outage'] = $lo['last_outage'];
        }
    }

    json_response([
        'summary' => array_values($summary),
        'days'    => $days,
    ]);
}
