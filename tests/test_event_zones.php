<?php
/**
 * Event Zones Tests — Phase 109 Slice A
 *
 * Verifies:
 *   - Migration idempotency (double shell_exec — 2nd run clean)
 *   - event_zones table + columns exist
 *   - assigns zone columns added
 *   - 3 RBAC permissions seeded + granted to roles 1/2/3
 *   - api/event-zones.php, event-zone-update.php, net-control.php
 *     contain rbac + csrf + json_error_safe (string checks)
 *   - net-control.php page contains sess_bootstrap_auto + rbac_require_screen
 *   - net-control.js is present and parses
 *   - Functional round-trip: scratch ticket + responder + assign +
 *     zone; set current_zone_id; read back via a net-control-style
 *     query; assert zone name resolves. All scratch rows cleaned up.
 *
 * Usage: php tests/test_event_zones.php
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0;
$fail = 0;

function ok($label, $condition) {
    global $pass, $fail;
    if ($condition) {
        echo "[PASS] $label\n";
        $pass++;
    } else {
        echo "[FAIL] $label\n";
        $fail++;
    }
}

// ── Scratch-row cleanup registry (runs even on fatal). ──
$GLOBALS['_ez_cleanup'] = [
    'ticket'     => [],
    'responder'  => [],
    'assigns'    => [],
    'event_zone' => [],
];
register_shutdown_function(function () use ($prefix) {
    $c = $GLOBALS['_ez_cleanup'] ?? [];
    $map = [
        'assigns'    => 'assigns',
        'event_zone' => 'event_zones',
        'responder'  => 'responder',
        'ticket'     => 'ticket',
    ];
    foreach ($map as $key => $table) {
        foreach (($c[$key] ?? []) as $id) {
            try {
                db_query("DELETE FROM `{$prefix}{$table}` WHERE `id` = ?", [$id]);
            } catch (Exception $e) { /* best-effort */ }
        }
    }
});

echo "=== Event Zones (Phase 109 Slice A) Tests ===\n\n";

// ══════════════════════════════════════════════════════════════
// Migration idempotency
// ══════════════════════════════════════════════════════════════
echo "-- Migration idempotency --\n";
$php = PHP_BINARY;
$mig = __DIR__ . '/../sql/run_event_zones.php';
$run1 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($mig) . ' 2>&1');
$run2 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($mig) . ' 2>&1');
ok('Migration run 1 completed (Done.)', strpos((string) $run1, 'Done.') !== false);
ok('Migration run 2 completed (Done.)', strpos((string) $run2, 'Done.') !== false);
ok('Migration run 2 is idempotent (no [ERR])', strpos((string) $run2, '[ERR]') === false);
ok('Migration run 2 shows already-present markers', strpos((string) $run2, 'already') !== false);

// ══════════════════════════════════════════════════════════════
// Schema — event_zones table + columns
// ══════════════════════════════════════════════════════════════
echo "\n-- Schema: event_zones --\n";
$ezTableExists = false;
try {
    db_fetch_all("SELECT 1 FROM `{$prefix}event_zones` LIMIT 0");
    $ezTableExists = true;
} catch (Exception $e) {}
ok('event_zones table exists', $ezTableExists);

$ezCols = [];
try {
    $ezCols = array_column(db_fetch_all("DESCRIBE `{$prefix}event_zones`"), 'Field');
} catch (Exception $e) {}
ok('event_zones has ticket_id column', in_array('ticket_id', $ezCols));
ok('event_zones has name column', in_array('name', $ezCols));
ok('event_zones has code column', in_array('code', $ezCols));
ok('event_zones has color column', in_array('color', $ezCols));
ok('event_zones has geo_json column', in_array('geo_json', $ezCols));
ok('event_zones has sort_order column', in_array('sort_order', $ezCols));
ok('event_zones has hide column', in_array('hide', $ezCols));

// ══════════════════════════════════════════════════════════════
// Schema — assigns zone columns
// ══════════════════════════════════════════════════════════════
echo "\n-- Schema: assigns columns --\n";
$aCols = [];
try {
    $aCols = array_column(db_fetch_all("DESCRIBE `{$prefix}assigns`"), 'Field');
} catch (Exception $e) {}
ok('assigns has current_zone_id column', in_array('current_zone_id', $aCols));
ok('assigns has zone_updated_at column', in_array('zone_updated_at', $aCols));
ok('assigns has last_checkin_at column', in_array('last_checkin_at', $aCols));

// ══════════════════════════════════════════════════════════════
// RBAC permissions seeded + granted
// ══════════════════════════════════════════════════════════════
echo "\n-- RBAC permissions --\n";
$permCodes = ['screen.net_control', 'action.update_zone', 'action.manage_event_zones'];
foreach ($permCodes as $code) {
    $row = null;
    try {
        $row = db_fetch_one("SELECT id FROM `{$prefix}permissions` WHERE `code` = ?", [$code]);
    } catch (Exception $e) {}
    ok("permission $code seeded", $row !== null);

    if ($row) {
        $granted = 0;
        try {
            $granted = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}role_permissions`
                 WHERE `permission_id` = ? AND `role_id` IN (1,2,3)",
                [(int) $row['id']]
            );
        } catch (Exception $e) {}
        ok("permission $code granted to roles 1/2/3", $granted === 3);
    }
}

// ══════════════════════════════════════════════════════════════
// API / page source string checks
// ══════════════════════════════════════════════════════════════
echo "\n-- Source checks --\n";
$base = __DIR__ . '/..';
$zonesApi = @file_get_contents($base . '/api/event-zones.php') ?: '';
$updApi   = @file_get_contents($base . '/api/event-zone-update.php') ?: '';
$boardApi = @file_get_contents($base . '/api/net-control.php') ?: '';
$page     = @file_get_contents($base . '/net-control.php') ?: '';
$js       = @file_get_contents($base . '/assets/js/net-control.js') ?: '';

ok('event-zones.php checks manage_event_zones RBAC', strpos($zonesApi, "action.manage_event_zones") !== false);
ok('event-zones.php verifies CSRF', strpos($zonesApi, 'csrf_verify') !== false);
ok('event-zones.php uses json_error_safe', strpos($zonesApi, 'json_error_safe') !== false);

ok('event-zone-update.php checks update_zone RBAC', strpos($updApi, "action.update_zone") !== false);
ok('event-zone-update.php verifies CSRF', strpos($updApi, 'csrf_verify') !== false);
ok('event-zone-update.php uses json_error_safe', strpos($updApi, 'json_error_safe') !== false);
ok('event-zone-update.php writes ICS-214 note', strpos($updApi, 'incident_add_note_internal') !== false);

ok('net-control.php API checks screen.net_control RBAC', strpos($boardApi, "screen.net_control") !== false);

ok('net-control.php page uses sess_bootstrap_auto', strpos($page, 'sess_bootstrap_auto') !== false);
ok('net-control.php page uses rbac_require_screen', strpos($page, "rbac_require_screen('screen.net_control')") !== false);

ok('net-control.js is present', strlen($js) > 500);
// Strip comments before scanning for ES5 violations so the header
// comment (which names the forbidden constructs) doesn't self-trip.
$jsCode = preg_replace('#/\*.*?\*/#s', '', $js);      // block comments
$jsCode = preg_replace('#(^|\n)\s*//[^\n]*#', "\n", $jsCode); // line comments
ok('net-control.js has no arrow functions', strpos($jsCode, '=>') === false);
ok('net-control.js has no let/const declarations', !preg_match('/(^|[^\w.])(let|const)\s+[\w$]/', $jsCode));

// ══════════════════════════════════════════════════════════════
// Functional round-trip
// ══════════════════════════════════════════════════════════════
echo "\n-- Functional round-trip --\n";
$now = date('Y-m-d H:i:s');

// Scratch ticket (event). in_types_id is required-ish; use 0 which is
// fine for a raw scratch row we delete.
$ticketId = 0;
try {
    // ticket.description is NOT NULL without a default on some installs
    // (CLAUDE.md pitfall) — include it explicitly for the scratch row.
    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `status`, `date`, `updated`, `_by`, `owner`)
         VALUES (0, ?, ?, 2, ?, ?, 0, 0)",
        ['NC-TEST scratch event', 'NC-TEST scratch', $now, $now]
    );
    $ticketId = (int) db_insert_id();
    $GLOBALS['_ez_cleanup']['ticket'][] = $ticketId;
} catch (Exception $e) {
    echo "  (ticket insert: " . $e->getMessage() . ")\n";
}
ok('scratch ticket created', $ticketId > 0);

// Scratch responder (unit).
$responderId = 0;
try {
    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`)
         VALUES (?, ?, ?)",
        ['NC-TEST Alpha', 'ALPHA-TEST', 'scratch net-control unit']
    );
    $responderId = (int) db_insert_id();
    $GLOBALS['_ez_cleanup']['responder'][] = $responderId;
} catch (Exception $e) {
    echo "  (responder insert: " . $e->getMessage() . ")\n";
}
ok('scratch responder created', $responderId > 0);

// Scratch assignment (active — clear NULL).
$assignId = 0;
if ($ticketId > 0 && $responderId > 0) {
    try {
        db_query(
            "INSERT INTO `{$prefix}assigns` (`as_of`, `ticket_id`, `responder_id`, `user_id`)
             VALUES (?, ?, ?, 0)",
            [$now, $ticketId, $responderId]
        );
        $assignId = (int) db_insert_id();
        $GLOBALS['_ez_cleanup']['assigns'][] = $assignId;
    } catch (Exception $e) {
        echo "  (assign insert: " . $e->getMessage() . ")\n";
    }
}
ok('scratch assignment created', $assignId > 0);

// Scratch zone.
$zoneId = 0;
if ($ticketId > 0) {
    try {
        db_query(
            "INSERT INTO `{$prefix}event_zones` (`ticket_id`, `name`, `code`, `color`, `sort_order`)
             VALUES (?, ?, ?, ?, 0)",
            [$ticketId, 'Zone 3', '3', '#0d6efd']
        );
        $zoneId = (int) db_insert_id();
        $GLOBALS['_ez_cleanup']['event_zone'][] = $zoneId;
    } catch (Exception $e) {
        echo "  (zone insert: " . $e->getMessage() . ")\n";
    }
}
ok('scratch zone created', $zoneId > 0);

// Unique-code-per-ticket check: a second zone with the same code should
// be detectable by the same query the API uses.
if ($ticketId > 0) {
    $dupe = null;
    try {
        $dupe = db_fetch_one(
            "SELECT `id` FROM `{$prefix}event_zones` WHERE `ticket_id` = ? AND LOWER(`code`) = LOWER(?)",
            [$ticketId, '3']
        );
    } catch (Exception $e) {}
    ok('duplicate-code detection query finds the existing zone', $dupe !== null);
}

// Simulate the zone move (what api/event-zone-update.php does).
if ($assignId > 0 && $zoneId > 0) {
    $moved = false;
    try {
        db_query(
            "UPDATE `{$prefix}assigns`
             SET `current_zone_id` = ?, `zone_updated_at` = NOW(), `last_checkin_at` = NOW()
             WHERE `id` = ?",
            [$zoneId, $assignId]
        );
        $moved = true;
    } catch (Exception $e) {
        echo "  (zone move: " . $e->getMessage() . ")\n";
    }
    ok('zone move UPDATE succeeds', $moved);
}

// Read back via a net-control-style query and assert the zone name
// resolves and the check-in was stamped.
if ($assignId > 0 && $zoneId > 0) {
    $row = null;
    try {
        $row = db_fetch_one(
            "SELECT `a`.`id` AS assign_id, `a`.`current_zone_id`, `a`.`last_checkin_at`,
                    `r`.`handle` AS callsign, `z`.`name` AS zone_name
             FROM `{$prefix}assigns` `a`
             LEFT JOIN `{$prefix}responder` `r` ON `r`.`id` = `a`.`responder_id`
             LEFT JOIN `{$prefix}event_zones` `z` ON `z`.`id` = `a`.`current_zone_id`
             WHERE `a`.`id` = ? AND `a`.`ticket_id` = ?",
            [$assignId, $ticketId]
        );
    } catch (Exception $e) {
        echo "  (readback: " . $e->getMessage() . ")\n";
    }
    ok('board readback returns the unit', $row !== null);
    ok('board readback resolves zone name to "Zone 3"', $row && $row['zone_name'] === 'Zone 3');
    ok('board readback stamped last_checkin_at', $row && !empty($row['last_checkin_at']));
    ok('board readback carries the unit callsign', $row && $row['callsign'] === 'ALPHA-TEST');
}

// ICS-214 note write via the real helper (exercises incident-write.php).
if ($ticketId > 0) {
    require_once $base . '/inc/incident-write.php';
    $noteRes = incident_add_note_internal($ticketId, 'ALPHA-TEST -> Zone 3 (reported via net control)', 0);
    ok('incident_add_note_internal wrote an ICS-214 row', !empty($noteRes['id']) && empty($noteRes['errors']));
    // Clean the action row up too.
    if (!empty($noteRes['id'])) {
        try {
            db_query("DELETE FROM `{$prefix}action` WHERE `id` = ?", [(int) $noteRes['id']]);
        } catch (Exception $e) {}
    }
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
