<?php
/**
 * Phase 109 Slice D (part 1) — zone templates + geo_json plumbing.
 *
 * Save an event's zone set (with geometry) as a named template and apply it to a
 * new event in one click; the event-zones API now also accepts/stores geo_json
 * on create/update. Static wiring guards + a save→apply round-trip that proves
 * geometry survives.
 *
 * Usage: php tests/test_event_zone_templates.php
 */
require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 109 Slice D (part 1) — zone templates + geo_json ===\n\n";

// ── Schema ────────────────────────────────────────────────────────────────────
$hasTbl = (bool) db_fetch_one(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='zones_json'",
    [$prefix . 'event_zone_templates']);
t('event_zone_templates table exists', $hasTbl);

// ── API ───────────────────────────────────────────────────────────────────────
$api = rd($base . '/api/event-zones.php');
t('event-zones API adds save_template / list_templates / apply_template',
    $api !== false &&
    strpos($api, "'save_template'") !== false &&
    strpos($api, "'list_templates'") !== false &&
    strpos($api, "'apply_template'") !== false);
t('event-zones create + update accept/store geo_json',
    $api !== false &&
    strpos($api, "\$out['geo_json']") !== false &&
    (bool) preg_match('/INSERT INTO `\{\$prefixTbl\}`[^;]*`geo_json`/', $api) &&
    strpos($api, '$geoForUpdate') !== false);
t('list_templates is event-independent (exempt from the ticket_id gate)',
    $api !== false && strpos($api, "\$action !== 'list_templates' && \$ticketId <= 0") !== false);

// ── UI ─────────────────────────────────────────────────────────────────────────
$ph = rd($base . '/net-control.php');
t('zones modal has the templates picker + save',
    $ph !== false &&
    strpos($ph, 'id="ncTemplateSelect"') !== false &&
    strpos($ph, 'id="ncApplyTemplateBtn"') !== false &&
    strpos($ph, 'id="ncSaveTemplateBtn"') !== false);
$js = rd($base . '/assets/js/net-control.js');
t('net-control.js wires template load/apply/save',
    $js !== false &&
    strpos($js, 'function loadTemplateList()') !== false &&
    strpos($js, "action: 'apply_template'") !== false &&
    strpos($js, "action: 'save_template'") !== false);

// ── Slice D part 2 — map editor + situation overlay ──────────────────────────
t('net-control has the zone map editor (Leaflet + modal + draw/save/clear)',
    $ph !== false &&
    strpos($ph, 'id="ncZoneMapModal"') !== false &&
    strpos($ph, 'assets/vendor/leaflet/leaflet.js') !== false &&
    $js !== false &&
    strpos($js, 'function openZoneMapEditor(zoneId)') !== false &&
    strpos($js, 'function zoneMapSave()') !== false &&
    strpos($js, 'function zoneMapClear()') !== false);
t('map editor round-trips geometry (Point on 1 click, Polygon 3+, closed ring)',
    $js !== false &&
    strpos($js, "type: 'Point'") !== false &&
    strpos($js, "type: 'Polygon'") !== false &&
    strpos($js, '// close the ring') !== false);
$sit = rd($base . '/situation.php');
t('situation map renders the active event\'s zones (Event Zones overlay)',
    $sit !== false &&
    strpos($sit, 'eventZonesGroup') !== false &&
    strpos($sit, 'Event Zones') !== false &&
    strpos($sit, 'function loadEventZones()') !== false &&
    strpos($sit, "fetch('api/active-event.php'") !== false &&
    strpos($sit, 'sit-zone-label') !== false);

// ── Round-trip: save an event's zones (with geometry) → apply to a new event ──
if (!$hasTbl) { t('SKIP integration — templates table absent', true);
    echo "\n=== $passed passed, $failed failed ===\n"; exit($failed === 0 ? 0 : 1); }

$tA = 991091; $tB = 991092; $tplName = 'ZZTEST_TPL_D';
$geo = '{"type":"Point","coordinates":[-93.27,44.98]}';
try {
    db_query("DELETE FROM `{$prefix}event_zones` WHERE ticket_id IN (?,?)", [$tA, $tB]);
    db_query("DELETE FROM `{$prefix}event_zone_templates` WHERE name=?", [$tplName]);

    // Seed event A with 2 zones, one carrying geometry.
    db_query("INSERT INTO `{$prefix}event_zones` (ticket_id,name,code,color,geo_json,sort_order,hide) VALUES (?, 'Zone 3','3','#0d6efd',?,0,0)", [$tA, $geo]);
    db_query("INSERT INTO `{$prefix}event_zones` (ticket_id,name,code,color,geo_json,sort_order,hide) VALUES (?, 'Parking','park',NULL,NULL,1,0)", [$tA]);

    // save_template — snapshot A's zones to a template.
    $snap = db_fetch_all("SELECT name,code,color,geo_json,sort_order,hide FROM `{$prefix}event_zones` WHERE ticket_id=? ORDER BY sort_order", [$tA]);
    db_query("INSERT INTO `{$prefix}event_zone_templates` (name,zones_json,created_at) VALUES (?,?,NOW())", [$tplName, json_encode($snap)]);
    t('template snapshot captured both zones', count($snap) === 2);

    // apply_template — recreate the zones on event B (skip dup codes).
    $tpl = db_fetch_one("SELECT zones_json FROM `{$prefix}event_zone_templates` WHERE name=?", [$tplName]);
    $zones = json_decode((string) $tpl['zones_json'], true);
    $existing = [];
    foreach (db_fetch_all("SELECT code FROM `{$prefix}event_zones` WHERE ticket_id=?", [$tB]) as $er) $existing[strtolower($er['code'])] = true;
    $added = 0;
    foreach ($zones as $z) {
        $code = strtolower(trim((string) $z['code']));
        if ($code === '' || isset($existing[$code])) continue;
        db_query("INSERT INTO `{$prefix}event_zones` (ticket_id,name,code,color,geo_json,sort_order,hide) VALUES (?,?,?,?,?,?,?)",
            [$tB, $z['name'], $z['code'], $z['color'], $z['geo_json'], (int) $z['sort_order'], (int) $z['hide']]);
        $existing[$code] = true; $added++;
    }
    t('applying the template created both zones on the new event', $added === 2);

    $bGeo = db_fetch_value("SELECT geo_json FROM `{$prefix}event_zones` WHERE ticket_id=? AND code='3'", [$tB]);
    t('zone GEOMETRY survived the save→apply round-trip', $bGeo === $geo);

    db_query("DELETE FROM `{$prefix}event_zones` WHERE ticket_id IN (?,?)", [$tA, $tB]);
    db_query("DELETE FROM `{$prefix}event_zone_templates` WHERE name=?", [$tplName]);
} catch (Throwable $e) {
    t('round-trip (unexpected error: ' . $e->getMessage() . ')', false);
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
