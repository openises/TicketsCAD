<?php
/**
 * DEV-ONLY demo seed for the Zone Coverage feature (Phase 115, GH #64).
 *
 * Builds a realistic Summer-Fete-style event: one active event, four zones,
 * and a handful of units placed across them (plus one not-yet-reported), so
 * the Zone Coverage board has something to show for a live walkthrough / the
 * training video. Also ensures a no-2FA local test admin ('zonedemo' /
 * 'ZoneDemo!234') so the walkthrough can log in without the real admin's 2FA.
 *
 * LOCAL DEV ONLY. Never run against a live host. Idempotent-ish: it reuses the
 * demo event by scope name if present, and clears its prior demo units first.
 *
 * Usage: php tools/seed_zone_demo.php
 */

chdir(__DIR__ . '/..');
require_once 'config.php';

// ── HARD SAFETY GUARD ──────────────────────────────────────────────
// This seeder creates a known-password admin + demo data. It must NEVER
// run against a live host. Refuse unless the DB host is local AND the
// operator passes --i-am-on-localhost. (DB_HOST comes from config.php.)
$dbHost = defined('DB_HOST') ? DB_HOST : ($GLOBALS['db_host'] ?? '');
$localHosts = ['localhost', '127.0.0.1', '::1', ''];
$optedIn = in_array('--i-am-on-localhost', $argv, true);
if (!in_array(strtolower((string) $dbHost), $localHosts, true) || !$optedIn) {
    fwrite(STDERR,
        "REFUSED: seed_zone_demo.php is a LOCAL DEV tool only.\n" .
        "  DB host: '" . $dbHost . "'\n" .
        "  It creates a known-password admin and demo data — never run it on a\n" .
        "  live host. If this really is your local machine, re-run with:\n" .
        "      php tools/seed_zone_demo.php --i-am-on-localhost\n");
    exit(2);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$SCOPE = 'Summer Fete (zone demo)';

function out($s) { echo $s . "\n"; }

// ── Test admin (no 2FA) for the walkthrough ──
$adminUser = 'zonedemo';
$existing = db_fetch_one("SELECT id FROM `{$prefix}user` WHERE `user` = ?", [$adminUser]);
if (!$existing) {
    $hash = password_hash('ZoneDemo!234', PASSWORD_DEFAULT);
    // level 0 = Super Admin, consistent with the role_id=1 grant below (a
    // mismatched level/role trips tests/test_migration_upgrade.php).
    db_query("INSERT INTO `{$prefix}user` (`user`, `passwd`, `name_f`, `name_l`, `level`, `can_login`)
              VALUES (?, ?, 'Zone', 'Demo', 0, 1)", [$adminUser, $hash]);
    $adminId = (int) db_insert_id();
    // Super Admin role.
    try { db_query("INSERT IGNORE INTO `{$prefix}user_roles` (`user_id`, `role_id`, `org_id`) VALUES (?, 1, NULL)", [$adminId]); } catch (Exception $e) {}
    out("[ok] created test admin '{$adminUser}' / ZoneDemo!234 (id {$adminId})");
} else {
    $adminId = (int) $existing['id'];
    out("[ok] test admin '{$adminUser}' already exists (id {$adminId})");
}

// ── Demo event ──
$event = db_fetch_one("SELECT id FROM `{$prefix}ticket` WHERE `scope` = ? ORDER BY id DESC LIMIT 1", [$SCOPE]);
if ($event) {
    $eventId = (int) $event['id'];
    // Clear prior demo units/zones so re-runs stay clean.
    db_query("DELETE FROM `{$prefix}assigns` WHERE ticket_id = ?", [$eventId]);
    db_query("DELETE FROM `{$prefix}event_zones` WHERE ticket_id = ?", [$eventId]);
    out("[ok] reusing demo event id {$eventId} (cleared prior zones/units)");
} else {
    db_query("INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `status`)
              VALUES (0, ?, 'Annual community festival — volunteer public-safety coverage.', 2)", [$SCOPE]);
    $eventId = (int) db_insert_id();
    out("[ok] created demo event id {$eventId}");
}

// ── Zones ──
$zoneDefs = [
    ['North Gate', 'NG', '#0d6efd'],
    ['Bandstand',  'BS', '#d63384'],
    ['Midway',     'MW', '#fd7e14'],
    ['First Aid',  'FA', '#198754'],
];
$zoneId = [];
foreach ($zoneDefs as $i => $z) {
    db_query("INSERT INTO `{$prefix}event_zones` (`ticket_id`, `name`, `code`, `color`, `sort_order`)
              VALUES (?, ?, ?, ?, ?)", [$eventId, $z[0], $z[1], $z[2], $i]);
    $zoneId[$z[1]] = (int) db_insert_id();
}
out("[ok] seeded " . count($zoneDefs) . " zones");

// ── Units: reuse-or-create demo responders, assign to the event + a zone. ──
//    [handle, name, zone-code|null]
$units = [
    ['Alpha',   'Alpha Team',   'NG'],
    ['Bravo',   'Bravo Team',   'NG'],
    ['Charlie', 'Charlie Team', 'MW'],
    ['Delta',   'Delta Team',   'MW'],
    ['Echo',    'Echo Team',    'BS'],
    ['Foxtrot', 'Foxtrot Team', null],   // not yet reported
];
foreach ($units as $u) {
    $r = db_fetch_one("SELECT id FROM `{$prefix}responder` WHERE `handle` = ? LIMIT 1", [$u[0]]);
    if ($r) { $rid = (int) $r['id']; }
    else {
        db_query("INSERT INTO `{$prefix}responder` (`name`, `description`, `handle`) VALUES (?, '', ?)", [$u[1], $u[0]]);
        $rid = (int) db_insert_id();
    }
    $zid = $u[2] !== null ? $zoneId[$u[2]] : null;
    db_query("INSERT INTO `{$prefix}assigns`
              (`ticket_id`, `responder_id`, `user_id`, `current_zone_id`, `dispatched`, `last_checkin_at`, `zone_updated_at`, `clear`)
              VALUES (?, ?, 0, ?, NOW(), NOW(), NOW(), NULL)", [$eventId, $rid, $zid]);
}
out("[ok] assigned " . count($units) . " units (5 in zones, 1 unreported)");

out("");
out("Demo ready. Event #{$eventId} '{$SCOPE}'.");
out("Log in as {$adminUser} / ZoneDemo!234, then open Zone Coverage.");
