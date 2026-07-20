<?php
/**
 * Phase 115 — Zone Coverage (GH #64) — RBAC migration.
 *
 * Adds two permissions and grants them so a roaming volunteer can SEE the
 * zone-coverage board and report their OWN unit's zone:
 *
 *   screen.zone_coverage  (category 'screen') — view the Zone Coverage board.
 *       Granted to ALL six roles, including Field Unit (role 6), which is
 *       deliberately locked out of the dispatcher Net Control board but is
 *       exactly who Eric wants to see zone counts (#64).
 *
 *   action.set_own_zone   (category 'action') — set the zone of the unit the
 *       caller is the active person on (self-report only; never another unit).
 *       Granted to roles 1,2,3,4,6 — NOT Read-Only (5).
 *
 * No core-schema change: event_zones + assigns.current_zone_id already exist
 * (Phase 109). Idempotent (INSERT IGNORE); safe to re-run. Fresh installs get
 * the same grants from sql/run_00_rbac.php + sql/rbac.sql (kept in sync).
 *
 * Usage: php sql/run_zone_coverage.php   (also runs via sql/run_migrations.php)
 */

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

function _zc_out($s) { echo $s . "\n"; }

// Guard: RBAC tables must exist. On a pre-RBAC install there's nothing to do.
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}permissions` LIMIT 1");
    db_fetch_value("SELECT 1 FROM `{$prefix}roles` LIMIT 1");
} catch (Exception $e) {
    _zc_out('[skip] RBAC tables not present — nothing to seed.');
    return;
}

$perms = [
    [
        'code'        => 'screen.zone_coverage',
        'name'        => 'Zone Coverage Board',
        'category'    => 'screen',
        'resource'    => 'zone_coverage',
        'verb'        => 'view',
        'description' => 'View the event Zone Coverage board (unit counts per zone)',
    ],
    [
        'code'        => 'action.set_own_zone',
        'name'        => 'Report Own Zone',
        'category'    => 'action',
        'resource'    => 'set_own_zone',
        'verb'        => 'do',
        'description' => "Report the zone of the caller's own unit (self-report only)",
    ],
];

$permId = [];
foreach ($perms as $p) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions`
                (`code`, `name`, `category`, `resource`, `verb`, `description`)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$p['code'], $p['name'], $p['category'], $p['resource'], $p['verb'], $p['description']]
        );
    } catch (Exception $e) {
        // Older schema without resource/verb columns — fall back to the minimal set.
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
                 VALUES (?, ?, ?, ?)",
                [$p['code'], $p['name'], $p['category'], $p['description']]
            );
        } catch (Exception $e2) {
            _zc_out('[warn] could not seed ' . $p['code'] . ': ' . $e2->getMessage());
        }
    }
    $id = (int) db_fetch_value("SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ?", [$p['code']]);
    $permId[$p['code']] = $id;
    _zc_out('[ok] permission ' . $p['code'] . ' (id ' . $id . ')');
}

/**
 * Grant one permission to a list of role ids, idempotently.
 */
function _zc_grant(string $prefix, int $permId, array $roleIds): int {
    if ($permId <= 0) return 0;
    $granted = 0;
    foreach ($roleIds as $rid) {
        try {
            db_query(
                "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                 VALUES (?, ?)",
                [$rid, $permId]
            );
            $granted++;
        } catch (Exception $e) { /* role may not exist on this install */ }
    }
    return $granted;
}

// screen.zone_coverage → every role (1..6). Field Unit included by design.
$g1 = _zc_grant($prefix, $permId['screen.zone_coverage'], [1, 2, 3, 4, 5, 6]);
_zc_out("[ok] screen.zone_coverage granted to {$g1} role(s)");

// action.set_own_zone → 1 Super Admin, 2 Org Admin, 3 Dispatcher, 4 Operator,
// 6 Field Unit. NOT 5 Read-Only.
$g2 = _zc_grant($prefix, $permId['action.set_own_zone'], [1, 2, 3, 4, 6]);
_zc_out("[ok] action.set_own_zone granted to {$g2} role(s)");

// ── Caption keys (INSERT IGNORE so the Translations UI has editable rows and a
//    per-install rename can reach these strings — CLAUDE.md i18n pitfall). ──
$captions = [
    ['nav.menu.zone_coverage', 'Zone Coverage'],
    ['zonecov.title',          'Zone Coverage'],
    ['zonecov.heading',        'Zone Coverage'],
    ['zonecov.pick_event',     'Choose event'],
    ['zonecov.refresh',        'Refresh now'],
    ['zonecov.im_in',          "I'm in:"],
    ['zonecov.loading',        'Loading zone coverage...'],
    ['zonecov.no_zone',        'No zone reported yet'],
    ['zonecov.empty_title',    'No zones are set up for an active event yet.'],
    ['zonecov.empty_hint',     'A dispatcher defines zones on the Net Control board. Once zones exist and units are assigned, coverage shows here.'],
];
$capAdded = 0;
foreach ($captions as $c) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, 'zonecov')",
            [$c[0], $c[1]]
        );
        $capAdded++;
    } catch (Exception $e) {
        // captions_i18n absent on a very old install — non-fatal (t() falls back
        // to the inline default passed at each call site).
    }
}
_zc_out("[ok] {$capAdded} caption key(s) ensured");

_zc_out('[done] Phase 115 zone-coverage RBAC + captions seeded.');
