<?php
/**
 * Phase 79a-c regression — silent-catch hotspots actually work
 *
 * The Phase 73f audit added error_log() to ~15 silent-catch sites. The
 * follow-up live-probe (tools/probe_silent_catches.php) confirmed most
 * sites were actually working — the audit was speculative. Two real
 * bugs surfaced and were fixed in this phase:
 *
 *   - api/config-summary.php: queried user_sessions (doesn't exist on
 *     real installs) instead of active_sessions
 *   - api/incident-types.php: queried facilities.hide (column not in
 *     v4 schema; soft-delete is via deleted_at instead)
 *
 * These tests pin the right column / table names so future drift will
 * fail the test suite instead of silently dropping data again.
 */
require __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

function _t($pass, $fail, $label, $ok) {
    if ($ok) { echo "[PASS] {$label}\n"; return [$pass+1, $fail]; }
    echo "[FAIL] {$label}\n"; return [$pass, $fail+1];
}

echo "=== Phase 79a silent-catch hotspot regression ===\n";

// Phase 79a — active_sessions is the correct table name
$cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'active_sessions'")
            ->fetchAll(PDO::FETCH_COLUMN);
[$pass, $fail] = _t($pass, $fail, "active_sessions table exists", count($cols) > 0);
[$pass, $fail] = _t($pass, $fail, "active_sessions has expires_at column",
    in_array('expires_at', $cols));
[$pass, $fail] = _t($pass, $fail, "active_sessions has last_active column",
    in_array('last_active', $cols));

// config-summary.php's exact post-fix query must execute without error
$ok = false;
try {
    $pdo->query("SELECT COUNT(*) FROM `{$prefix}active_sessions` WHERE `expires_at` > NOW()");
    $ok = true;
} catch (Exception $e) {}
[$pass, $fail] = _t($pass, $fail, "config-summary.php active_sessions query executes", $ok);

// Phase 79c — facilities.hide does NOT exist; deleted_at DOES
$fcols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns
                      WHERE table_schema = DATABASE() AND table_name = 'facilities'")
             ->fetchAll(PDO::FETCH_COLUMN);
[$pass, $fail] = _t($pass, $fail, "facilities table exists", count($fcols) > 0);
[$pass, $fail] = _t($pass, $fail, "facilities.deleted_at exists (soft-delete column)",
    in_array('deleted_at', $fcols));
[$pass, $fail] = _t($pass, $fail, "facilities.hide does NOT exist (was legacy column)",
    !in_array('hide', $fcols));

// incident-types.php's exact post-fix query must execute without error
$ok = false;
try {
    $pdo->query("SELECT `id`, `name`, `type`, `lat`, `lng`
                 FROM `{$prefix}facilities`
                 WHERE `deleted_at` IS NULL
                 ORDER BY `name`");
    $ok = true;
} catch (Exception $e) {}
[$pass, $fail] = _t($pass, $fail, "incident-types.php facilities query executes", $ok);

// Spot-check that the audit's other suspected hotspots are NOT actually
// broken — these were speculative risks, confirmed working.
$specQueries = [
    'statistics.php open tickets' => "SELECT COUNT(DISTINCT t.id) FROM `{$prefix}ticket` t WHERE t.status IN (2,3)",
    'statistics.php closed today' => "SELECT COUNT(*) FROM `{$prefix}ticket` WHERE status = 1 AND DATE(problemend) = CURDATE()",
    'statistics.php available units' => "SELECT COUNT(*) FROM `{$prefix}responder` r LEFT JOIN `{$prefix}un_status` us ON r.un_status_id = us.id WHERE us.hide = 'n'",
    'incident-types list (with sort)' => "SELECT id, type, protocol, set_severity, `group`, color FROM `{$prefix}in_types` ORDER BY `sort`, `type`",
    'layout.php cleanup query' => "SELECT id FROM `{$prefix}dashboard_layouts` WHERE user_id = 0 ORDER BY updated_at DESC LIMIT 20, 999",
    'location-resolver main query' => "SELECT b.responder_id, lr.lat, lr.lng FROM `{$prefix}unit_location_bindings` b
        JOIN `{$prefix}location_providers` lp ON b.provider_id = lp.id
        JOIN `{$prefix}location_reports` lr ON lr.unit_identifier = b.unit_identifier AND lr.provider_id = b.provider_id
        LEFT JOIN `{$prefix}responder` r ON b.responder_id = r.id
        WHERE b.active = 1 AND lp.enabled = 1 LIMIT 1",
    'location-resolver personnel query' => "SELECT upa.member_id FROM `{$prefix}unit_personnel_assignments` upa LEFT JOIN `{$prefix}member` m ON upa.member_id = m.id WHERE upa.responder_id = 1 AND upa.status = 'active'",
];
foreach ($specQueries as $name => $sql) {
    $ok = false;
    try {
        $pdo->query($sql);
        $ok = true;
    } catch (Exception $e) {}
    [$pass, $fail] = _t($pass, $fail, "speculative-risk query works: {$name}", $ok);
}

echo "\n=== TOTAL: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
