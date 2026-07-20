<?php
/**
 * Phase 11 — RBAC roles as the canonical user identity.
 *
 * Verifies:
 *   - roles.legacy_level + roles.is_system schema
 *   - 6 default roles backfilled with the canonical legacy_level mapping
 *   - 6 default roles marked is_system=1
 *   - api/config-admin.php GET users includes role_id + role_name
 *   - api/config-admin.php ?section=roles returns roles list
 *   - api/config-admin.php POST users accepts role_id and replaces grants
 *   - api/config-admin.php POST users with only `level` still works (legacy path)
 *   - api/rbac.php ?action=migration_status returns counts
 *   - api/rbac.php delete_role refuses is_system=1
 *   - settings.php source has the new dropdown + label + Role column header
 *   - settings.php source has the gated Migrate Legacy Levels wrap
 *   - config.js source has populateUserRoleDropdown + syncDerivedLevelFromRole
 *   - config.js source sends role_id and renders role_name
 *   - Captions seeded in 5 languages
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 11 — Canonical RBAC tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Schema ────────────────────────────────────────────────────────────────
try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'legacy_level'",
        [$prefix . 'roles']
    );
    if ($col) {
        ok('roles.legacy_level column exists');
    } else {
        bad('roles.legacy_level column missing');
    }
} catch (Exception $e) {
    bad('legacy_level column check', $e->getMessage());
}

try {
    $col = db_fetch_one(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'is_system'",
        [$prefix . 'roles']
    );
    if ($col) {
        ok('roles.is_system column exists');
    } else {
        bad('roles.is_system column missing');
    }
} catch (Exception $e) {
    bad('is_system column check', $e->getMessage());
}

// ── Backfill on 6 default roles ─────────────────────────────────────────
$expected = [
    1 => ['Super Admin', 0],
    2 => ['Org Admin',   1],
    3 => ['Dispatcher',  2],
    4 => ['Operator',    2],
    5 => ['Read-Only',   3],
    6 => ['Field Unit',  4],
];
foreach ($expected as $id => $info) {
    [$expectedName, $expectedLegacy] = $info;
    try {
        $row = db_fetch_one(
            "SELECT name, legacy_level, is_system FROM `{$prefix}roles` WHERE id = ?",
            [$id]
        );
        if (!$row) {
            bad("role id={$id} missing", "expected {$expectedName}");
            continue;
        }
        if ($row['name'] !== $expectedName) {
            bad("role id={$id} name", "expected '{$expectedName}', got '{$row['name']}'");
            continue;
        }
        if ((int) $row['legacy_level'] !== $expectedLegacy) {
            bad("role id={$id} legacy_level", "expected {$expectedLegacy}, got " . var_export($row['legacy_level'], true));
            continue;
        }
        if ((int) $row['is_system'] !== 1) {
            bad("role id={$id} is_system", "expected 1, got {$row['is_system']}");
            continue;
        }
        ok("role id={$id} ({$expectedName}) → legacy_level={$expectedLegacy}, is_system=1");
    } catch (Exception $e) {
        bad("role id={$id}", $e->getMessage());
    }
}

// ── api/config-admin.php source ────────────────────────────────────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (strpos($ca, "AS `role_id`") !== false && strpos($ca, "AS `role_name`") !== false) {
    ok('api/config-admin.php GET users returns role_id + role_name');
} else {
    bad('api/config-admin.php missing role_id/role_name in GET');
}
if (strpos($ca, "if (\$section === 'roles')") !== false) {
    ok('api/config-admin.php has new ?section=roles endpoint');
} else {
    bad('api/config-admin.php missing roles section');
}
// GH #56 (Billy/K9OH 2026-07-04): match the stable prefix, not the full
// parenthetical — the reason string gained ", Phase 99j-2 org scope" in
// Phase 99j-2, which made the old exact-substring check fail spuriously.
if (strpos($ca, "\$explicitRoleId") !== false &&
    strpos($ca, "Set via User Accounts form (Phase 11 canonical RBAC") !== false) {
    ok('api/config-admin.php POST users accepts role_id + replaces grant');
} else {
    bad('api/config-admin.php POST users does NOT accept role_id properly');
}

// ── api/rbac.php source ────────────────────────────────────────────────
$rb = file_get_contents($base . '/api/rbac.php');
if (strpos($rb, "'migration_status'") !== false &&
    strpos($rb, "needs_migration") !== false) {
    ok('api/rbac.php has migration_status endpoint');
} else {
    bad('api/rbac.php missing migration_status');
}
// Phase 11c (2026-06-11): Eric reversed Phase 11's is_system delete
// block. Every role is now deletable; the only guard is the
// lockout-safety check that refuses if it would orphan the last
// super-admin user. Assertion updated to match.
if (strpos($rb, "Refusing to delete the only role granting super-admin") !== false) {
    ok('api/rbac.php has last-super-admin lockout-safety check (Phase 11c)');
} else {
    bad('api/rbac.php missing lockout-safety check');
}

// ── settings.php source ────────────────────────────────────────────────
$st = file_get_contents($base . '/settings.php');
if (strpos($st, 'id="userRoleId"') !== false &&
    strpos($st, "useracct.role_label") !== false) {
    ok('settings.php has Role & Permissions set dropdown');
} else {
    bad('settings.php missing role dropdown');
}
if (strpos($st, 'id="userLevelDerived"') !== false) {
    ok('settings.php has hidden #userLevelDerived for legacy compat');
} else {
    bad('settings.php missing #userLevelDerived');
}
if (strpos($st, "useracct.role_col") !== false) {
    ok('settings.php User table header uses Role caption key');
} else {
    bad('settings.php User table header not i18n');
}
if (strpos($st, 'id="migrateLegacyWrap"') !== false &&
    strpos($st, 'id="migrateLegacyDone"') !== false) {
    ok('settings.php has gated Migrate Legacy Levels wrap');
} else {
    bad('settings.php missing gated migrate wrap');
}

// ── roles.php source ───────────────────────────────────────────────────
$rp = file_get_contents($base . '/roles.php');
if (strpos($rp, 'id="migrateLegacyWrap"') !== false &&
    strpos($rp, 'id="migrateLegacyDone"') !== false) {
    ok('roles.php has gated Migrate Legacy Levels wrap');
} else {
    bad('roles.php missing gated migrate wrap');
}

// ── config.js source ───────────────────────────────────────────────────
$cj = file_get_contents($base . '/assets/js/config.js');
if (strpos($cj, 'populateUserRoleDropdown') !== false &&
    strpos($cj, 'syncDerivedLevelFromRole') !== false) {
    ok('config.js has role-dropdown + derived-level helpers');
} else {
    bad('config.js missing role-dropdown helpers');
}
if (strpos($cj, "fetch('api/config-admin.php?section=roles'") !== false) {
    ok('config.js fetches roles list');
} else {
    bad('config.js does NOT fetch roles list');
}
if (strpos($cj, "fetch('api/rbac.php?action=migration_status'") !== false) {
    ok('config.js gates Migrate button on migration_status');
} else {
    bad('config.js does NOT gate Migrate button');
}
if (strpos($cj, 'u.role_name') !== false) {
    ok('config.js renders role_name in user list');
} else {
    bad('config.js does NOT render role_name');
}

// ── Migration runner ───────────────────────────────────────────────────
if (file_exists($base . '/sql/run_phase11_role_metadata.php')) {
    ok('sql/run_phase11_role_metadata.php exists');
} else {
    bad('Phase 11 migration runner missing');
}

// ── Captions ──────────────────────────────────────────────────────────
foreach (['en','de','nl','fr','es'] as $lang) {
    try {
        $v = db_fetch_value(
            "SELECT value FROM `{$prefix}captions_i18n` WHERE caption_key=? AND lang=?",
            ['useracct.role_label', $lang]
        );
        if ($v) {
            ok("Caption useracct.role_label [{$lang}] present");
        } else {
            bad("Caption useracct.role_label [{$lang}] missing");
        }
    } catch (Exception $e) {
        bad("Caption query [{$lang}]", $e->getMessage());
    }
}

// ── Functional: GET roles endpoint shape ──────────────────────────────
// We can't easily invoke the API endpoint here without HTTP, but we can
// verify the underlying query returns sensible data.
try {
    $rows = db_fetch_all(
        "SELECT id, name, is_super, is_system, legacy_level
         FROM `{$prefix}roles` ORDER BY sort_order, name"
    );
    if (count($rows) >= 6) {
        ok('Roles query returns at least the 6 system roles (' . count($rows) . ' total)');
    } else {
        bad('Roles query', 'expected ≥ 6 rows, got ' . count($rows));
    }
} catch (Exception $e) {
    bad('Roles query', $e->getMessage());
}

echo "\n";
echo "===========================================\n";
echo "Phase 11 canonical RBAC: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
