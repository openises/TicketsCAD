<?php
/**
 * Check Service State — Display service health monitoring data.
 *
 * Purpose:  Shows the current state of all monitored services (from
 *           newui_service_state) and recent service events (from
 *           newui_service_events).
 * Usage:    php sql/check_service_state.php
 * Prerequisites: config.php; service tables created by run_service_events.php.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Service status, last check time, failure count; recent 20 events.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

echo "=== Service State ===\n";
try {
    $rows = $pdo->query('SELECT * FROM newui_service_state ORDER BY service')->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "  (empty — will populate on first health check via browser)\n";
    foreach ($rows as $r) {
        echo "  {$r['service']}: status={$r['last_status']}, checked={$r['last_checked']}, failures={$r['consecutive_failures']}\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n=== Service Events ===\n";
try {
    $rows = $pdo->query('SELECT * FROM newui_service_events ORDER BY detected_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) echo "  (empty — will populate on first health check via browser)\n";
    foreach ($rows as $r) {
        echo "  [{$r['detected_at']}] {$r['service']} — {$r['event_type']}";
        if ($r['uptime_seconds'] !== null) echo " (uptime: {$r['uptime_seconds']}s)";
        echo "\n";
    }
} catch (Exception $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}
