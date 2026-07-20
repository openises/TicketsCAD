<?php
/**
 * Phase 63 regression tests — location freshness signal
 *
 * Verifies the fix for the silent-dot-on-map bug: OwnTracks Significant-mode
 * heartbeats reuse the last GPS fix's `reported_at` indefinitely. Freshness
 * must derive from `received_at` (server-side check-in time) instead.
 *
 * Run via: /c/xampp/8.2.4/php/php.exe tools/test_all.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
require_once 'inc/location-resolver.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$tests  = 0;
$passes = 0;
$fails  = [];

function ph63_assert($cond, $msg)
{
    global $tests, $passes, $fails;
    $tests++;
    if ($cond) { $passes++; return; }
    $fails[] = $msg;
}

// ── Setup: ephemeral fixtures (cleaned up at end) ─────────────────
$fixture = [];

try {
    db_query(
        "INSERT INTO `{$prefix}location_providers`
            (`code`, `name`, `enabled`, `priority`, `config_json`, `icon`, `color`, `max_age_seconds`)
         VALUES ('ph63_otest', 'Phase63 Test Provider', 1, 99, '{}', 'bi-phone', '#000', 600)"
    );
    $fixture['provider_id'] = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `personal_for_member_id`)
         VALUES ('ph63-test-unit', '', NULL)"
    );
    $fixture['responder_id'] = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}unit_location_bindings`
            (`responder_id`, `provider_id`, `unit_identifier`, `priority`, `active`)
         VALUES (?, ?, 'PH63', 100, 1)",
        [$fixture['responder_id'], $fixture['provider_id']]
    );
} catch (Exception $e) {
    echo "SKIP: fixture setup failed: " . $e->getMessage() . "\n";
    exit(0);
}

// ── Scenario 1: reported_at stale, received_at recent → must be fresh ──
db_query(
    "INSERT INTO `{$prefix}location_reports`
        (`provider_id`, `unit_identifier`, `lat`, `lng`,
         `reported_at`, `received_at`)
     VALUES (?, 'PH63', 44.5, -93.5,
             DATE_SUB(NOW(), INTERVAL 10 HOUR),
             DATE_SUB(NOW(), INTERVAL 2 MINUTE))",
    [$fixture['provider_id']]
);

$pos = location_resolve_unit($fixture['responder_id']);
ph63_assert($pos !== null, 'Scenario 1: resolver returns a position');
ph63_assert($pos && (int) $pos['is_fresh'] === 1,
    'Scenario 1: stale reported_at + fresh received_at → is_fresh=1 (got ' . ($pos['is_fresh'] ?? 'null') . ')');
ph63_assert($pos && (int) $pos['age_seconds'] < 600,
    'Scenario 1: age_seconds derives from received_at, not reported_at (got ' . ($pos['age_seconds'] ?? 'null') . ')');

// ── Scenario 2: both timestamps stale → is_fresh=0 ─────────────────
db_query("DELETE FROM `{$prefix}location_reports` WHERE `unit_identifier` = 'PH63'");
db_query(
    "INSERT INTO `{$prefix}location_reports`
        (`provider_id`, `unit_identifier`, `lat`, `lng`,
         `reported_at`, `received_at`)
     VALUES (?, 'PH63', 44.5, -93.5,
             DATE_SUB(NOW(), INTERVAL 10 HOUR),
             DATE_SUB(NOW(), INTERVAL 10 HOUR))",
    [$fixture['provider_id']]
);

$pos = location_resolve_unit($fixture['responder_id']);
ph63_assert($pos !== null, 'Scenario 2: resolver returns the stale position (not null)');
ph63_assert($pos && (int) $pos['is_fresh'] === 0,
    'Scenario 2: stale received_at → is_fresh=0 (got ' . ($pos['is_fresh'] ?? 'null') . ')');

// ── Scenario 3: multiple heartbeats with identical reported_at —
//                 resolver picks the one with the latest received_at ──
db_query("DELETE FROM `{$prefix}location_reports` WHERE `unit_identifier` = 'PH63'");
$frozenReported = date('Y-m-d H:i:s', time() - 7200); // 2h old fix
for ($i = 0; $i < 5; $i++) {
    $minutesAgo = 60 - ($i * 10);
    db_query(
        "INSERT INTO `{$prefix}location_reports`
            (`provider_id`, `unit_identifier`, `lat`, `lng`,
             `reported_at`, `received_at`)
         VALUES (?, 'PH63', ?, -93.5, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))",
        [$fixture['provider_id'], 44.5 + ($i * 0.001), $frozenReported, $minutesAgo]
    );
}

$pos = location_resolve_unit($fixture['responder_id']);
ph63_assert($pos !== null, 'Scenario 3: resolver returns a position');
ph63_assert($pos && abs((float) $pos['lat'] - 44.504) < 0.0001,
    'Scenario 3: picks the heartbeat with latest received_at (got lat=' . ($pos['lat'] ?? 'null') . ', expected 44.504)');

// ── Scenario 4: OwnTracks provider in production has the post-Phase-63
//                 max_age_seconds — should be ≥ 3600 to allow hourly heartbeats ──
$ot = db_fetch_one(
    "SELECT `max_age_seconds` FROM `{$prefix}location_providers` WHERE `code` = 'owntracks'"
);
if ($ot) {
    ph63_assert((int) $ot['max_age_seconds'] >= 3600,
        'Scenario 4: OwnTracks max_age_seconds is at least 1h (got ' . $ot['max_age_seconds'] . 's) — battery-friendly baseline');
}

// ── Cleanup ───────────────────────────────────────────────────────
try {
    db_query("DELETE FROM `{$prefix}location_reports` WHERE `unit_identifier` = 'PH63'");
    db_query("DELETE FROM `{$prefix}unit_location_bindings` WHERE `responder_id` = ?", [$fixture['responder_id']]);
    db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$fixture['responder_id']]);
    db_query("DELETE FROM `{$prefix}location_providers` WHERE `id` = ?", [$fixture['provider_id']]);
} catch (Exception $e) {}

// ── Report ────────────────────────────────────────────────────────
echo "Phase 63 location-freshness tests: $passes/$tests passed\n";
if ($fails) {
    foreach ($fails as $f) {
        echo "  FAIL: $f\n";
    }
    exit(1);
}
