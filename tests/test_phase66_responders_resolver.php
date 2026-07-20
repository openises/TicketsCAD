<?php
/**
 * Phase 66 regression test — api/responders.php must consult the
 * unit_location_bindings resolver, not just the legacy APRS tracks
 * table. Without this, OwnTracks (and any other binding-fed provider)
 * never reaches the Responders widget, so clicking a unit fires the
 * "No location available" toast on situation.php even though the data
 * is sitting in location_reports.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;

$prefix = $GLOBALS['db_prefix'] ?? '';
$tests  = 0; $passes = 0; $fails = [];
function p66($cond, $msg)
{
    global $tests, $passes, $fails;
    $tests++;
    if ($cond) { $passes++; return; }
    $fails[] = $msg;
}

// ─── Setup fixtures ────────────────────────────────────────────────
$fx = [];
try {
    db_query(
        "INSERT INTO `{$prefix}location_providers`
           (`code`, `name`, `enabled`, `priority`, `config_json`, `icon`, `color`, `max_age_seconds`)
         VALUES ('ph66_test', 'Phase66 Test', 1, 50, '{}', 'bi-phone', '#000', 3600)"
    );
    $fx['prov'] = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`)
         VALUES ('ph66-test-unit', '')"
    );
    $fx['rid'] = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
           (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, 'PH66', 100, 1)",
        [$fx['rid'], $fx['prov']]
    );

    db_query(
        "INSERT INTO `{$prefix}location_reports`
           (`provider_id`, `unit_identifier`, `lat`, `lng`,
            `reported_at`, `received_at`)
         VALUES (?, 'PH66', 44.999, -93.111,
                 DATE_SUB(NOW(), INTERVAL 1 MINUTE),
                 DATE_SUB(NOW(), INTERVAL 1 MINUTE))",
        [$fx['prov']]
    );
} catch (Exception $e) {
    echo "SKIP: fixture setup failed: " . $e->getMessage() . "\n";
    exit(0);
}

// ─── Cleanup runs even if assertions throw ─────────────────────────
register_shutdown_function(function () use ($prefix, $fx) {
    try {
        db_query("DELETE FROM `{$prefix}location_reports` WHERE unit_identifier='PH66'");
        if (!empty($fx['rid'])) {
            db_query("DELETE FROM `{$prefix}unit_location_bindings` WHERE responder_id = ?", [$fx['rid']]);
            db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$fx['rid']]);
        }
        if (!empty($fx['prov'])) {
            db_query("DELETE FROM `{$prefix}location_providers` WHERE id = ?", [$fx['prov']]);
        }
    } catch (Exception $e) {}
});

// ─── Exercise the API the way situation.php's widget does ──────────
// json_response() calls exit, so we run the API in a subprocess and
// capture its stdout. The subprocess uses a tiny harness that seeds
// $_SESSION before requiring the endpoint.
$admin = db_fetch_one("SELECT id, user FROM `{$prefix}user` WHERE level = 0 OR id = 1 ORDER BY id LIMIT 1");
$harness = sprintf(
    '<?php
        ini_set("display_errors", "0");
        error_reporting(0);
        chdir(%s);
        require %s;
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $_SESSION["user_id"] = %d;
        $_SESSION["username"] = %s;
        require %s;
    ',
    var_export(__DIR__ . '/..', true),
    var_export(__DIR__ . '/../config.php', true),
    (int) $admin['id'],
    var_export((string) $admin['user'], true),
    var_export(__DIR__ . '/../api/responders.php', true)
);

$tmpFile = tempnam(sys_get_temp_dir(), 'ph66_');
file_put_contents($tmpFile, $harness);
// Windows cmd has no /dev/null — the shell_exec silently returned empty
// there, failing every subprocess assertion.
$raw = shell_exec(PHP_BINARY . ' ' . escapeshellarg($tmpFile)
    . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'));
@unlink($tmpFile);

// Strip any leading PHP warnings/notices that escape display_errors.
// The actual JSON payload starts at the first '{'.
$jsonStart = strpos($raw, '{');
$jsonStr = $jsonStart !== false ? substr($raw, $jsonStart) : $raw;
$data = json_decode($jsonStr, true);
p66(is_array($data), 'API subprocess returned valid JSON (raw=' . substr($raw, 0, 200) . ')');
p66(isset($data['responders']) && is_array($data['responders']), 'JSON has responders[]');

$row = null;
foreach ($data['responders'] ?? [] as $r) {
    if ((int) $r['id'] === $fx['rid']) { $row = $r; break; }
}
p66($row !== null, "fixture responder id={$fx['rid']} present in feed");

if ($row) {
    p66(abs($row['lat'] - 44.999) < 0.001, "lat from resolver (got " . $row['lat'] . ", want 44.999)");
    p66(abs($row['lng'] - (-93.111)) < 0.001, "lng from resolver (got " . $row['lng'] . ", want -93.111)");
    p66(($row['location_source'] ?? '') === 'ph66_test', "location_source=ph66_test (got '" . ($row['location_source'] ?? '') . "')");
    p66((int) ($row['location_is_fresh'] ?? 0) === 1, 'location_is_fresh=1 (1-min-old report under 3600s threshold)');
    p66(($row['last_track'] ?? null) !== null, 'last_track populated from received_at');
}

echo "Phase 66 responders-resolver tests: $passes/$tests passed\n";
foreach ($fails as $f) echo "  FAIL: $f\n";
if ($fails) exit(1);
