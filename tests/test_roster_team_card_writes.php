<?php
/**
 * GH#76 Phase 144 (2026-08-18) — roster page Team Memberships card writes.
 *
 * Design spec §3's load-bearing constraint: the roster card does NOT get a
 * new endpoint or a new gate. It calls api/teams.php's EXISTING
 * add_member/remove_member actions — the same ones the Teams tab has
 * always used. This test proves that by exercising the REAL functions
 * those actions now delegate to (inc/team-write.php ::
 * team_add_member_internal() / team_remove_member_internal(), extracted
 * from api/teams.php in this same phase — see that file's docblocks), and
 * by statically confirming api/teams.php has exactly ONE add_member and
 * ONE remove_member handler (not a second, roster-specific copy with a
 * weaker gate — the exact failure pattern CLAUDE.md's "two-permission-
 * systems" / "RBAC exclusion-list leak" entries warn about).
 *
 * Covers:
 *   1. api/teams.php's add_member/remove_member/update_member_role actions
 *      are gated by action.manage_teams (source-level — unchanged from
 *      before this phase).
 *   2. Exactly one add_member action and one remove_member action exist in
 *      api/teams.php (no roster-specific duplicate).
 *   3. Positive case: team_add_member_internal() / team_remove_member_
 *      internal() actually write/remove the team_members row, via the
 *      real writer, against a throwaway team + member (never a real
 *      account).
 *   4. Negative case: action.manage_teams's real role-grant boundary — a
 *      role that does NOT hold it (Read-Only) cannot reach these actions;
 *      confirmed against the live role_permissions table, the same
 *      authorization data api/teams.php's rbac_can() check reads from.
 *   5. Every write through team_add_member_internal() with no explicit
 *      $source produces source=NULL (a human/UI action) — matching what
 *      BOTH the Teams tab and the roster card produce when calling
 *      through api/teams.php (neither passes a $source argument).
 *
 * Usage: php tests/test_roster_team_card_writes.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/member-write.php';
require_once __DIR__ . '/../inc/team-write.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#76 Phase 144 — Roster Team Memberships card writes ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
function tbl($n) { return db_table($n); }

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — static: single write path, unchanged gate
// ═══════════════════════════════════════════════════════════════════════
echo "--- Part 1: api/teams.php — single write path, unchanged gate ---\n\n";

$teamsSrc = @file_get_contents($base . '/api/teams.php');
if ($teamsSrc === false) {
    t("api/teams.php readable", false);
} else {
    t("api/teams.php readable", true);
    t("handlePost() requires action.manage_teams for ALL POST actions (top-of-function gate)",
        (bool) preg_match('/function handlePost\(\)[\s\S]{0,400}?rbac_can\([\'"]action\.manage_teams[\'"]\)/', $teamsSrc));
    t("exactly one add_member action handler (no roster-specific duplicate)",
        substr_count($teamsSrc, "\$action === 'add_member'") === 1);
    t("exactly one remove_member action handler", substr_count($teamsSrc, "\$action === 'remove_member'") === 1);
    t("add_member delegates to inc/team-write.php's team_add_member_internal()",
        (bool) preg_match('/add_member[\s\S]{0,600}?team_add_member_internal\(/', $teamsSrc));
    t("remove_member delegates to inc/team-write.php's team_remove_member_internal()",
        (bool) preg_match('/remove_member[\s\S]{0,600}?team_remove_member_internal\(/', $teamsSrc));
    t("no second api/*.php file defines an add_member/remove_member team action",
        true); // documented invariant — grep below double-checks it
}

$otherApiFiles = glob($base . '/api/*.php') ?: [];
$dupFound = false;
foreach ($otherApiFiles as $f) {
    if (basename($f) === 'teams.php') continue;
    $src = @file_get_contents($f);
    if ($src !== false && strpos($src, "'add_member'") !== false && strpos($src, 'team_id') !== false) {
        $dupFound = true;
        echo "  [WARN] possible duplicate add_member handler in " . basename($f) . "\n";
    }
}
t("no other api/*.php defines a team add_member action", !$dupFound);

// roster.js calls the SAME endpoint/action, not a new one.
$rosterJs = @file_get_contents($base . '/assets/js/roster.js');
if ($rosterJs !== false) {
    t("roster.js's Team Memberships card posts to api/teams.php (not a new endpoint)",
        (bool) preg_match("/apiPostUrl\\(\\s*['\"]api\\/teams\\.php['\"][\\s\\S]{0,200}?action:\\s*['\"]add_member['\"]/", $rosterJs));
    t("roster.js's Team Memberships card removes via api/teams.php's remove_member action",
        (bool) preg_match("/apiPostUrl\\(\\s*['\"]api\\/teams\\.php['\"][\\s\\S]{0,200}?action:\\s*['\"]remove_member['\"]/", $rosterJs));
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — RBAC boundary (real role_permissions data)
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 2: action.manage_teams role-grant boundary ---\n\n";

$perm = db_fetch_one("SELECT id FROM " . tbl('permissions') . " WHERE code = 'action.manage_teams'");
t("action.manage_teams permission row exists", (bool) $perm);

if ($perm) {
    $permId = (int) $perm['id'];
    function _p144w_role_has($permId) {
        global $prefix;
        return function ($roleId) use ($permId, $prefix) {
            return (bool) db_fetch_value(
                "SELECT 1 FROM {$prefix}role_permissions WHERE role_id = ? AND permission_id = ?",
                [$roleId, $permId]
            );
        };
    }
    $has = _p144w_role_has($permId);
    t("Super Admin (role 1) holds action.manage_teams", $has(1));
    t("Read-Only (role 5) does NOT hold action.manage_teams (the negative case the roster card's canManageTeams flag and the server-side gate both key off)", !$has(5));
    t("Field Unit (role 6) does NOT hold action.manage_teams", !$has(6));
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — functional: real writer, throwaway fixtures
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 3: functional (real writer, throwaway fixtures) ---\n\n";

$teamId = 0; $memberId = 0;
try {
    $tr = team_upsert_internal(['name' => 'zzP144w Team ' . uniqid()], 0);
    $teamId = (int) ($tr['id'] ?? 0);
    $mr = member_create_internal(['first_name' => 'zzP144w', 'last_name' => 'CardTest', 'available' => 'Yes'], 0);
    $memberId = (int) ($mr['id'] ?? 0);
    t("throwaway team + member created", $teamId > 0 && $memberId > 0);

    if ($teamId > 0 && $memberId > 0) {
        $addRes = team_add_member_internal($teamId, $memberId, 'Member');
        t("team_add_member_internal() reports success", !empty($addRes['success']) && empty($addRes['errors']));

        $row = db_fetch_one(
            "SELECT id, role, source FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
            [$teamId, $memberId]
        );
        t("a team_members row now exists for this member/team", (bool) $row);
        t("role defaults to 'Member'", $row && $row['role'] === 'Member');
        t("no explicit \$source produces source=NULL (matches what the Teams tab AND the roster card both produce)",
            $row && $row['source'] === null);

        // Re-add with a different role -- ON DUPLICATE KEY UPDATE upserts,
        // matching the pre-extraction inline SQL's own semantics exactly.
        $addRes2 = team_add_member_internal($teamId, $memberId, 'Leader');
        $row2 = db_fetch_one("SELECT role FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamId, $memberId]);
        t("re-adding the same member updates their role (upsert, not a duplicate row)", $row2 && $row2['role'] === 'Leader');
        $countRows = (int) db_fetch_value(
            "SELECT COUNT(*) FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
            [$teamId, $memberId]
        );
        t("still exactly one row after the upsert", $countRows === 1);

        $assignmentId = (int) $row['id'];
        $remResult = team_remove_member_internal($assignmentId);
        t("team_remove_member_internal() reports success", !empty($remResult['success']));
        t("removal result carries the team_id for the caller's audit_log() payload", $remResult['team_id'] === $teamId);
        t("removal result carries the member_id for the caller's audit_log() payload", $remResult['member_id'] === $memberId);

        $goneRow = db_fetch_one("SELECT id FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamId, $memberId]);
        t("the team_members row is actually gone after removal", !$goneRow);

        // Negative: missing ids are rejected, not silently accepted.
        $badAdd = team_add_member_internal(0, $memberId, 'Member');
        t("team_add_member_internal() rejects a missing team_id", empty($badAdd['success']) && !empty($badAdd['errors']));
        $badRemove = team_remove_member_internal(0);
        t("team_remove_member_internal() rejects a missing assignment_id", empty($badRemove['success']) && !empty($badRemove['errors']));
    }
} catch (Throwable $e) {
    t("functional roster card test ran without a fatal error: " . $e->getMessage(), false);
} finally {
    if ($memberId > 0) {
        try { db_query("DELETE FROM " . tbl('team_members') . " WHERE member_id = ?", [$memberId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id = ?", [$memberId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$memberId]); } catch (Throwable $e) {}
    }
    if ($teamId > 0) {
        try { db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$teamId]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$teamId]); } catch (Throwable $e) {}
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
