<?php
/**
 * Zello shared-location → unit-tracking map (2026-06-26).
 *
 * The Zello proxy (proxy/ZelloProxyApp.php) parses `on_location` events.
 * This feature wires that record onto the existing dispatch map by reusing
 * the location_reports / unit_location_bindings / location_providers
 * storage every other provider uses — no parallel table.
 *
 * This test covers the behaviour end to end against the real DB by
 * replicating the EXACT SQL the proxy's persistZelloLocation() runs:
 *
 *   1. The `zello` location provider row exists (seeded by
 *      run_zello_location_provider.php).
 *   2. Reverse resolve: a Zello `username` in member_comm_identifiers
 *      (comm mode `zello`, key `username`) maps back to the member —
 *      case-insensitively — and an UNKNOWN username maps to nothing.
 *   3. member → responder via an active unit_personnel_assignments row.
 *   4. A location_reports row is written (provider = zello,
 *      unit_identifier = username) + a unit_location_bindings row, so the
 *      map's all_units JOIN (reports→bindings→providers) surfaces the fix.
 *
 * Plus source-level guards on the proxy so the wiring + null-safety can't
 * silently regress.
 *
 * Self-contained: seeds temp rows under a unique sentinel username,
 * asserts, cleans up. Never deploys, never touches a live proxy.
 */

require_once __DIR__ . '/../config.php';
if (!function_exists('db_query')) {
    require_once __DIR__ . '/../inc/db.php';
}

$prefix = $GLOBALS['db_prefix'] ?? '';

$total  = 0;
$passed = 0;
$failed = [];

function zl_assert(string $name, bool $cond, string $detail = '') {
    global $total, $passed, $failed;
    $total++;
    if ($cond) { $passed++; echo "  PASS  $name\n"; }
    else { $failed[] = "$name — $detail"; echo "  FAIL  $name — $detail\n"; }
}

// Unique sentinel so we never collide with or disturb real data.
$ZUSER   = 'zl_test_' . substr(md5((string) mt_rand()), 0, 8);
$ZUSER_UC = strtoupper($ZUSER); // for the case-insensitive lookup test

$memberId = null;
$responderId = null;
$zelloModeId = null;
$providerId = null;

// ── 1. zello location provider present (the migration ran) ──
try {
    $providerId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}location_providers` WHERE code = 'zello' LIMIT 1"
    );
} catch (Exception $e) { $providerId = 0; }
zl_assert('zello location provider row exists (run_zello_location_provider.php)',
    $providerId > 0, 'no provider row — run the migration');

// ── comm mode `zello` present ──
try {
    $zelloModeId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}comm_modes` WHERE code = 'zello' LIMIT 1"
    );
} catch (Exception $e) { $zelloModeId = 0; }
zl_assert('comm_modes has a zello row', $zelloModeId > 0);

if ($providerId > 0 && $zelloModeId > 0) {
    try {
        // ── seed a member ──
        // first_name/last_name are VIRTUAL GENERATED from field1/field2 on
        // the legacy member schema, so seed the base columns directly.
        db_query(
            "INSERT INTO `{$prefix}member` (field1, field2) VALUES (?, ?)",
            ['ZLTest', $ZUSER]
        );
        $memberId = (int) db_insert_id();

        // ── seed that member's Zello username identifier ──
        db_query(
            "INSERT INTO `{$prefix}member_comm_identifiers`
                (member_id, comm_mode_id, label, values_json, is_primary)
             VALUES (?, ?, 'Zello', ?, 1)",
            [$memberId, $zelloModeId, json_encode(['username' => $ZUSER])]
        );

        // ── seed a responder (unit) + active personnel assignment ──
        db_query(
            "INSERT INTO `{$prefix}responder` (name, description) VALUES (?, ?)",
            ['ZL Unit ' . $ZUSER, 'zello location test unit']
        );
        $responderId = (int) db_insert_id();

        db_query(
            "INSERT INTO `{$prefix}unit_personnel_assignments`
                (responder_id, member_id, status, assigned_at)
             VALUES (?, ?, 'active', NOW())",
            [$responderId, $memberId]
        );
    } catch (Exception $e) {
        zl_assert('seed fixtures', false, $e->getMessage());
    }
}

// ── 2. reverse resolve username → member (the proxy's lookup SQL) ──
if ($memberId) {
    $reverseSql =
        "SELECT mci.member_id
           FROM `{$prefix}member_comm_identifiers` mci
           JOIN `{$prefix}comm_modes` cm ON cm.id = mci.comm_mode_id
          WHERE cm.code = 'zello'
            AND cm.enabled = 1
            AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(mci.values_json, '$.username'))) = LOWER(?)
          ORDER BY mci.is_primary DESC, mci.id
          LIMIT 1";

    try {
        $got = (int) db_fetch_value($reverseSql, [$ZUSER]);
        zl_assert('reverse lookup maps Zello username → member', $got === $memberId, "got $got want $memberId");
    } catch (Exception $e) {
        zl_assert('reverse lookup executed', false, $e->getMessage());
    }

    // case-insensitive (Zello usernames aren't case sensitive)
    try {
        $gotUc = (int) db_fetch_value($reverseSql, [$ZUSER_UC]);
        zl_assert('reverse lookup is case-insensitive', $gotUc === $memberId, "got $gotUc want $memberId");
    } catch (Exception $e) {
        zl_assert('reverse lookup (uppercase) executed', false, $e->getMessage());
    }

    // unknown sender → no member (proxy logs + skips, never crashes)
    try {
        $none = db_fetch_value($reverseSql, ['definitely_not_a_real_zello_user_xyz']);
        zl_assert('unknown Zello sender resolves to no member',
            $none === false || $none === null, 'unexpectedly matched ' . var_export($none, true));
    } catch (Exception $e) {
        zl_assert('unknown-sender lookup executed', false, $e->getMessage());
    }
}

// ── 3. member → responder via active assignment (proxy's mapping SQL) ──
if ($memberId && $responderId) {
    try {
        $rid = (int) db_fetch_value(
            "SELECT responder_id
               FROM `{$prefix}unit_personnel_assignments`
              WHERE member_id = ?
                AND status = 'active'
                AND released_at IS NULL
              ORDER BY assigned_at DESC, id DESC
              LIMIT 1",
            [$memberId]
        );
        zl_assert('member → responder via active assignment', $rid === $responderId, "got $rid want $responderId");
    } catch (Exception $e) {
        zl_assert('member→responder mapping executed', false, $e->getMessage());
    }
}

// ── 4. write the fix + binding, then confirm it surfaces on the map ──
if ($memberId && $responderId && $providerId) {
    $lat = 44.9778; $lng = -93.2650;
    try {
        // location_reports row (proxy's INSERT shape)
        db_query(
            "INSERT INTO `{$prefix}location_reports`
                (`provider_id`, `unit_identifier`, `lat`, `lng`, `raw_data`, `reported_at`)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$providerId, $ZUSER, $lat, $lng,
             json_encode(['source' => 'zello', 'from' => $ZUSER, 'lat' => $lat, 'lng' => $lng])]
        );

        // unit_location_bindings row (so the map JOIN surfaces it).
        // source is the enum('manual','personnel') binding-origin tag —
        // 'personnel' (username→member→unit), matching the proxy + autobind.
        db_query(
            "INSERT INTO `{$prefix}unit_location_bindings`
                (responder_id, provider_id, unit_identifier, priority, active, source)
             VALUES (?, ?, ?, 100, 1, 'personnel')",
            [$responderId, $providerId, $ZUSER]
        );

        // The map's all_units query (api/location.php?all_units=1) — does
        // our seeded responder now appear with the Zello fix?
        $row = db_fetch_one(
            "SELECT b.`responder_id`, lr.`lat`, lr.`lng`, lp.`code` AS provider_code
               FROM `{$prefix}unit_location_bindings` b
               JOIN `{$prefix}location_providers` lp ON b.`provider_id` = lp.`id`
               JOIN `{$prefix}location_reports` lr
                 ON lr.`unit_identifier` = b.`unit_identifier`
                AND lr.`provider_id` = b.`provider_id`
              WHERE b.`responder_id` = ?
                AND b.`active` = 1
              ORDER BY lr.`received_at` DESC
              LIMIT 1",
            [$responderId]
        );
        zl_assert('fix surfaces in the map JOIN (reports→bindings→providers)',
            $row && (int) $row['responder_id'] === $responderId, 'no row');
        zl_assert('stored fix carries the zello provider tag',
            $row && $row['provider_code'] === 'zello', 'provider_code=' . ($row['provider_code'] ?? 'null'));
        zl_assert('stored fix carries the correct coordinates',
            $row && abs((float) $row['lat'] - $lat) < 0.0001 && abs((float) $row['lng'] - $lng) < 0.0001,
            'lat/lng mismatch');
    } catch (Exception $e) {
        zl_assert('location write round-trip executed', false, $e->getMessage());
    }
}

// ── 5. source-level guards on the proxy wiring ──
$proxy = @file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');
$proxy = $proxy === false ? '' : $proxy;

zl_assert('proxy on_location calls persistZelloLocation',
    strpos($proxy, 'persistZelloLocation(') !== false);
zl_assert('proxy persistZelloLocation writes location_reports',
    strpos($proxy, 'INTO `{$this->prefix}location_reports`') !== false
    || strpos($proxy, 'location_reports') !== false);
zl_assert('proxy upserts a unit_location_bindings row',
    strpos($proxy, 'unit_location_bindings') !== false);
zl_assert('proxy reverse-resolves via member_comm_identifiers + JSON username',
    strpos($proxy, 'member_comm_identifiers') !== false
    && strpos($proxy, "JSON_EXTRACT(mci.values_json, '\$.username')") !== false);
zl_assert('proxy maps member → responder (unit_personnel_assignments / personal_for_member_id)',
    strpos($proxy, 'unit_personnel_assignments') !== false
    && strpos($proxy, 'personal_for_member_id') !== false);
zl_assert('proxy guards unknown/empty sender (logs + skips)',
    strpos($proxy, "unmapped sender") !== false || strpos($proxy, "=== 'unknown'") !== false);
zl_assert('proxy validates coordinates before storing',
    strpos($proxy, 'is_numeric') !== false && strpos($proxy, 'skipping') !== false);
zl_assert('proxy respects the provider enabled flag',
    strpos($proxy, 'zelloProviderEnabled') !== false);

// ── cleanup ──
try {
    if ($responderId) {
        db_query("DELETE FROM `{$prefix}location_reports` WHERE unit_identifier = ?", [$ZUSER]);
        db_query("DELETE FROM `{$prefix}unit_location_bindings` WHERE responder_id = ?", [$responderId]);
        db_query("DELETE FROM `{$prefix}unit_personnel_assignments` WHERE responder_id = ?", [$responderId]);
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$responderId]);
    }
    if ($memberId) {
        db_query("DELETE FROM `{$prefix}member_comm_identifiers` WHERE member_id = ?", [$memberId]);
        db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]);
    }
} catch (Exception $e) {
    echo "  (cleanup note: " . $e->getMessage() . ")\n";
}

// ── Summary ──
echo "\n";
echo "$passed passed, " . count($failed) . " failed\n";
echo 'Zello location → map — ' . $passed . ' / ' . $total . " tests passed\n";
if ($failed) { foreach ($failed as $f) echo "  - $f\n"; exit(1); }
