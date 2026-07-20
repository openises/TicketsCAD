<?php
/**
 * Phase 112 — NWS weather alerts (Phase 1: poll + notify).
 *
 * Covers: provider normalize (captured fixture, NO live call), severity/urgency
 * ranking, Haversine, area match (state/zones/point-radius), rule match
 * (severity/urgency floors + event allow/deny + message types), and the full
 * poll lifecycle (Alert → dedup → Update → Cancel) via an INJECTED fetcher.
 * Also asserts DISABLED-INSTALL INERTNESS — with the master switch off the
 * poller writes nothing and touches no UI.
 *
 * All DB-touching tests use marker ids/labels and clean up after themselves,
 * and the master switch is restored to OFF at the end.
 *
 * Usage: php tests/test_weather_alerts.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/weather_provider_nws.php';
require_once __DIR__ . '/../inc/weather_alerts.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function approx($a, $b, $tol) { return abs($a - $b) <= $tol; }

echo "=== Phase 112 weather alerts (poll + notify) ===\n\n";

// ── A captured-shape NWS Tornado Warning near Minneapolis (polygon) ────────
$MINNEAPOLIS = ['lat' => 44.98, 'lng' => -93.27];
$tornadoFeature = [
    'id' => 'urn:test:wx:tornado-1',
    'geometry' => [
        'type' => 'Polygon',
        'coordinates' => [[
            [-93.30, 45.00], [-93.20, 45.00], [-93.20, 44.95], [-93.30, 44.95], [-93.30, 45.00],
        ]],
    ],
    'properties' => [
        'event' => 'Tornado Warning',
        'severity' => 'Extreme',
        'urgency' => 'Immediate',
        'certainty' => 'Observed',
        'messageType' => 'Alert',
        'areaDesc' => 'Hennepin, MN',
        'headline' => 'Tornado Warning issued',
        'description' => 'A tornado was detected.',
        'instruction' => 'Take cover now.',
        'onset' => '2026-07-05T20:00:00-05:00',
        'expires' => '2099-07-05T20:45:00-05:00',
        'ends' => '2099-07-05T20:45:00-05:00',
        'geocode' => ['UGC' => ['MNC053'], 'SAME' => ['027053']],
    ],
];
$farFeature = [ // Severe but ~250mi away (Duluth-ish), same state
    'id' => 'urn:test:wx:far-1',
    'geometry' => ['type' => 'Point', 'coordinates' => [-92.10, 46.78]],
    'properties' => [
        'event' => 'Severe Thunderstorm Warning', 'severity' => 'Severe', 'urgency' => 'Expected',
        'messageType' => 'Alert', 'areaDesc' => 'St. Louis, MN',
        'headline' => 'Severe TS', 'instruction' => 'Seek shelter.',
        'expires' => '2099-07-05T21:00:00-05:00', 'geocode' => ['UGC' => ['MNC137']],
    ],
];

// ── 1. Provider normalize (PURE) ──────────────────────────────────────────
$n = weather_nws_normalize($tornadoFeature);
t('normalize: nws_id preserved', $n['nws_id'] === 'urn:test:wx:tornado-1');
t('normalize: event/severity/message_type', $n['event'] === 'Tornado Warning' && $n['severity'] === 'Extreme' && $n['message_type'] === 'Alert');
t('normalize: UGC → CSV', $n['geocode_ugc'] === 'MNC053');
t('normalize: ISO datetime → SQL', $n['onset'] === '2026-07-05 20:00:00' || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)$n['onset']));
t('normalize: polygon centroid near Minneapolis',
    $n['centroid_lat'] !== null && approx((float)$n['centroid_lat'], 44.975, 0.1) && approx((float)$n['centroid_lng'], -93.25, 0.1));
t('normalize: feature with no id → null', weather_nws_normalize(['properties' => ['event' => 'x']]) === null);

$coll = weather_nws_normalize_collection(['features' => [$tornadoFeature, ['properties' => []], $farFeature]]);
t('normalize_collection drops id-less features', count($coll) === 2);

// ── 2. Ranking + Haversine (PURE) ─────────────────────────────────────────
t('severity rank order', weather_severity_rank('Extreme') > weather_severity_rank('Severe')
    && weather_severity_rank('Severe') > weather_severity_rank('Moderate')
    && weather_severity_rank('Minor') > weather_severity_rank('Unknown'));
t('urgency rank order', weather_urgency_rank('Immediate') > weather_urgency_rank('Expected')
    && weather_urgency_rank('Expected') > weather_urgency_rank('Future'));
// Minneapolis → St Paul ≈ 9 mi
t('haversine ~ Minneapolis→StPaul', approx(weather_haversine_miles(44.98, -93.27, 44.95, -93.09), 9.0, 2.5));

// ── 3. Area match (PURE) ──────────────────────────────────────────────────
$stateArea = ['kind' => 'state', 'state_code' => 'MN'];
$wrongState = ['kind' => 'state', 'state_code' => 'WI'];
t('area state: MN matches MN alert', weather_area_matches($stateArea, $n));
t('area state: WI rejects MN alert', !weather_area_matches($wrongState, $n));

$zoneArea = ['kind' => 'zones', 'zones' => 'MNC053,MNZ061'];
$zoneMiss = ['kind' => 'zones', 'zones' => 'MNZ099'];
t('area zones: matching UGC', weather_area_matches($zoneArea, $n));
t('area zones: non-matching UGC', !weather_area_matches($zoneMiss, $n));

$radiusArea = ['kind' => 'point_radius', 'lat' => $MINNEAPOLIS['lat'], 'lng' => $MINNEAPOLIS['lng'], 'radius_miles' => 40];
$nFar = weather_nws_normalize($farFeature);
t('area point_radius: near alert (<40mi) matches', weather_area_matches($radiusArea, $n));
t('area point_radius: far alert (>40mi) rejected', !weather_area_matches($radiusArea, $nFar));

// ── 4. Rule match (PURE) ──────────────────────────────────────────────────
$severeRule = ['min_severity' => 'Severe', 'min_urgency' => 'Expected', 'message_types' => 'Alert,Update'];
$moderateAlert = array_merge($n, ['severity' => 'Moderate']);
t('rule severity floor: Severe rule accepts Extreme', weather_rule_matches($severeRule, $n));
t('rule severity floor: Severe rule rejects Moderate', !weather_rule_matches($severeRule, $moderateAlert));

$tornadoOnly = array_merge($severeRule, ['event_allow' => 'tornado']);
t('rule event_allow: "tornado" accepts Tornado Warning', weather_rule_matches($tornadoOnly, $n));
t('rule event_allow: "tornado" rejects Severe TS', !weather_rule_matches($tornadoOnly, $nFar));

$denyTornado = array_merge($severeRule, ['event_deny' => 'tornado']);
t('rule event_deny: "tornado" rejects Tornado Warning', !weather_rule_matches($denyTornado, $n));

$cancelAlert = array_merge($n, ['message_type' => 'Cancel']);
t('rule message_types: default set rejects Cancel', !weather_rule_matches($severeRule, $cancelAlert));
$cancelRule = array_merge($severeRule, ['message_types' => 'Alert,Update,Cancel']);
t('rule message_types: Cancel accepted when listed', weather_rule_matches($cancelRule, $cancelAlert));

// ── 5. DISABLED-INSTALL INERTNESS ─────────────────────────────────────────
// Ensure master switch OFF (baseline) → poll is fully inert, writes nothing.
$origEnabled = (string) get_variable('weather_alerts_enabled');
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_alerts_enabled','0')
          ON DUPLICATE KEY UPDATE `value`='0'");
$before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}weather_alerts`");
$injected = static function (array $area, string $ua) use ($tornadoFeature) {
    return ['ok' => true, 'status' => 200, 'features' => [$tornadoFeature]];
};
$sumOff = weather_poll_run(false, $injected);
$after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}weather_alerts`");
t('disabled install: poll reports enabled=false, ran=false', $sumOff['enabled'] === false && $sumOff['ran'] === false);
t('disabled install: poll wrote ZERO alert rows', $after === $before);

// ── 6. Enabled end-to-end poll lifecycle (Alert → dedup → Update → Cancel) ─
// Seed a marker area + tray rule, enable, drive with the injected fetcher.
db_query("DELETE FROM `{$prefix}weather_alert_areas` WHERE `label`='TEST_WX_AREA'");
db_query("INSERT INTO `{$prefix}weather_alert_areas` (`label`,`kind`,`state_code`,`active`,`sort_order`)
          VALUES ('TEST_WX_AREA','state','MN',1,0)");
$areaId = (int) db_insert_id();
db_query("INSERT INTO `{$prefix}weather_alert_rules`
          (`label`,`area_id`,`target`,`min_severity`,`min_urgency`,`message_types`,`action_mode`,`repeat_on_update`,`active`)
          VALUES ('TEST_WX_RULE',?, 'tray','Severe','Expected','Alert,Update','notify',1,1)", [$areaId]);
$ruleId = (int) db_insert_id();
db_query("DELETE FROM `{$prefix}weather_alerts` WHERE `nws_id` LIKE 'urn:test:wx:%'");

db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_alerts_enabled','1')
          ON DUPLICATE KEY UPDATE `value`='1'");
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_ua_contact','test@example.com')
          ON DUPLICATE KEY UPDATE `value`='test@example.com'");

// First poll: an Alert should notify once.
$s1 = weather_poll_run(false, $injected);
t('enabled poll: Alert notified once', $s1['ran'] === true && $s1['notified'] === 1);
$row = db_fetch_one("SELECT `id`,`status`,`message_type` FROM `{$prefix}weather_alerts` WHERE `nws_id`='urn:test:wx:tornado-1'");
t('enabled poll: alert row persisted (active)', $row && $row['status'] === 'active');
$alertRowId = (int) ($row['id'] ?? 0);
$disp = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}weather_alert_dispatch` WHERE `alert_id`=? AND `rule_id`=?", [$alertRowId, $ruleId]);
t('enabled poll: one dispatch ledger row', $disp === 1);

// Second poll, identical Alert: dedup → no new notify.
$s2 = weather_poll_run(false, $injected);
t('dedup: identical Alert does NOT re-notify', $s2['notified'] === 0);

// Update revision: repeat_on_update=1 → notifies once more.
$updated = $tornadoFeature; $updated['properties']['messageType'] = 'Update';
$injUpd = static function (array $area, string $ua) use ($updated) {
    return ['ok' => true, 'status' => 200, 'features' => [$updated]];
};
$s3 = weather_poll_run(false, $injUpd);
t('update: repeat_on_update re-notifies once', $s3['notified'] === 1);

// Cancel: marks cancelled, does not notify (no Cancel rule).
$cancel = $tornadoFeature; $cancel['properties']['messageType'] = 'Cancel';
$injCancel = static function (array $area, string $ua) use ($cancel) {
    return ['ok' => true, 'status' => 200, 'features' => [$cancel]];
};
$s4 = weather_poll_run(false, $injCancel);
$rowC = db_fetch_one("SELECT `status` FROM `{$prefix}weather_alerts` WHERE `nws_id`='urn:test:wx:tornado-1'");
t('cancel: alert marked cancelled', $rowC && $rowC['status'] === 'cancelled');
t('cancel: not notified to tray (no Cancel rule)', $s4['notified'] === 0);

// active_alerts must NOT include the cancelled one.
$act = weather_active_alerts();
$hasCancelled = false;
foreach ($act as $a) { if ($a['event'] === 'Tornado Warning' && ($a['status'] ?? '') !== 'active') $hasCancelled = true; }
t('active_alerts excludes cancelled alert', !$hasCancelled);

// ── Cleanup + restore master switch ───────────────────────────────────────
db_query("DELETE FROM `{$prefix}weather_alert_dispatch` WHERE `rule_id`=?", [$ruleId]);
db_query("DELETE FROM `{$prefix}weather_alert_rules` WHERE `id`=?", [$ruleId]);
db_query("DELETE FROM `{$prefix}weather_alert_areas` WHERE `id`=?", [$areaId]);
db_query("DELETE FROM `{$prefix}weather_alerts` WHERE `nws_id` LIKE 'urn:test:wx:%'");
db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('weather_alerts_enabled',?)
          ON DUPLICATE KEY UPDATE `value`=?", [$origEnabled ?: '0', $origEnabled ?: '0']);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
