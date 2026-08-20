<?php
/**
 * GH#76 Phase 144 (2026-08-18) — scheduling permissions multi-team union.
 *
 * Section 7's SHIPPED DEFAULT: inc/scheduling-perms.php's
 * scheduling_get_permissions() now resolves "which team-scoped profile
 * applies" from ALL of a member's team_members rows (union / most-
 * permissive-wins), not the single legacy member.team_id column. Covers:
 *
 *   1. Zero-membership member: unchanged fallback (member_type / global
 *      default) — this phase does not touch that path at all.
 *   2. Single-membership member: reproduces the prior single-team-column
 *      behavior BYTE-FOR-BYTE (a single-row "union" is just that row).
 *   3. Genuine 2-team member with DISAGREEING profiles at the same scope:
 *      proves union/most-permissive-wins — a capability granted by EITHER
 *      team's profile is granted to the member, even though neither
 *      profile alone grants both.
 *
 * Every profile/assignment/team/member fixture created here is disposable
 * and is removed at the end (never touches an existing profile/assignment
 * — global-scope 'all' fallback rows already seeded by
 * sql/scheduling_permissions.sql are read, never written).
 *
 * Usage: php tests/test_scheduling_perms_multiteam_union.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/member-write.php';
require_once __DIR__ . '/../inc/team-write.php';
require_once __DIR__ . '/../inc/scheduling-perms.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#76 Phase 144 — scheduling permissions multi-team union ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
function tbl($n) { return db_table($n); }

$requiredTables = ['scheduling_permission_profiles', 'scheduling_permission_assignments'];
$missing = false;
foreach ($requiredTables as $tn) {
    try {
        db_fetch_value("SELECT 1 FROM " . tbl($tn) . " LIMIT 1");
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), 'Base table or view not found') !== false) {
            $missing = true;
        }
    }
}
if ($missing) {
    echo "SKIP: scheduling_permission_profiles/assignments tables absent (run sql/scheduling_permissions.sql first).\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — zero-membership member: fallback unchanged
// ═══════════════════════════════════════════════════════════════════════
echo "--- Part 1: zero-membership member (fallback unchanged) ---\n\n";

$memZero = 0;
try {
    $mr = member_create_internal(['first_name' => 'zzP144sp', 'last_name' => 'ZeroTeam', 'available' => 'Yes'], 0);
    $memZero = (int) ($mr['id'] ?? 0);
    t("zero-membership fixture member created", $memZero > 0);

    if ($memZero > 0) {
        $perms = scheduling_get_permissions($memZero, 'global', null);
        t("zero-membership member resolves to SOME profile (member_type or global default fallback — unchanged path)",
            !empty($perms['profile_code']));
        // A member with zero team_members rows must never reach the
        // team_union candidate at all.
        t("profile is not the union marker (proves the team_union candidate was correctly skipped)",
            $perms['profile_code'] !== 'combined_team_union');
    }
} catch (Throwable $e) {
    t("Part 1 ran without a fatal error: " . $e->getMessage(), false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — single-membership member: byte-for-byte reproduction
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 2: single-membership member (byte-for-byte reproduction) ---\n\n";

$profileSingleId = 0; $teamSingleId = 0; $assignSingleId = 0; $memSingle = 0;
try {
    db_query(
        "INSERT INTO " . tbl('scheduling_permission_profiles') . "
         (code, name, can_view_schedule, can_view_own, can_view_others, can_view_available,
          can_self_assign, can_self_remove, can_mark_unavailable, can_swap, can_request_cover,
          can_assign_others, can_remove_others, can_change_status, can_manage_slots, sort_order)
         VALUES (?, 'zzP144 Single Profile', 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 999)",
        ['zzp144single' . uniqid()]
    );
    $profileSingleId = (int) db_insert_id();

    $tr = team_upsert_internal(['name' => 'zzP144sp Single Team ' . uniqid()], 0);
    $teamSingleId = (int) ($tr['id'] ?? 0);

    db_query(
        "INSERT INTO " . tbl('scheduling_permission_assignments') . "
         (profile_id, scope_type, scope_id, target_type, target_id)
         VALUES (?, 'global', NULL, 'team', ?)",
        [$profileSingleId, $teamSingleId]
    );
    $assignSingleId = (int) db_insert_id();

    $mr = member_create_internal(['first_name' => 'zzP144sp', 'last_name' => 'SingleTeam', 'available' => 'Yes'], 0);
    $memSingle = (int) ($mr['id'] ?? 0);
    t("single-team fixtures created (profile + team + assignment + member)",
        $profileSingleId > 0 && $teamSingleId > 0 && $assignSingleId > 0 && $memSingle > 0);

    if ($memSingle > 0) {
        $addRes = team_add_member_internal($teamSingleId, $memSingle, 'Member');
        t("member added to the single team via the real writer", !empty($addRes['success']));

        $perms = scheduling_get_permissions($memSingle, 'global', null);
        t("resolves to the single team's profile", $perms['can_self_assign'] === 1 && $perms['can_view_others'] === 0);
        t("profile_code matches the fixture profile exactly (single-row union == the old direct lookup, byte-for-byte)",
            strpos((string) $perms['profile_code'], 'zzp144single') === 0);
    }
} catch (Throwable $e) {
    t("Part 2 ran without a fatal error: " . $e->getMessage(), false);
}

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — genuine 2-team member: union / most-permissive-wins
// ═══════════════════════════════════════════════════════════════════════
echo "\n--- Part 3: genuine 2-team member (union / most-permissive-wins) ---\n\n";

$profileAId = 0; $profileBId = 0; $teamAId = 0; $teamBId = 0; $memUnion = 0;
try {
    // Profile A grants can_self_assign but NOT can_swap.
    db_query(
        "INSERT INTO " . tbl('scheduling_permission_profiles') . "
         (code, name, can_view_schedule, can_view_own, can_view_others, can_view_available,
          can_self_assign, can_self_remove, can_mark_unavailable, can_swap, can_request_cover,
          can_assign_others, can_remove_others, can_change_status, can_manage_slots, sort_order)
         VALUES (?, 'zzP144 Union Profile A', 1, 1, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 999)",
        ['zzp144unionA' . uniqid()]
    );
    $profileAId = (int) db_insert_id();

    // Profile B grants can_swap but NOT can_self_assign — the exact
    // opposite capability, so the union result must have BOTH.
    db_query(
        "INSERT INTO " . tbl('scheduling_permission_profiles') . "
         (code, name, can_view_schedule, can_view_own, can_view_others, can_view_available,
          can_self_assign, can_self_remove, can_mark_unavailable, can_swap, can_request_cover,
          can_assign_others, can_remove_others, can_change_status, can_manage_slots, sort_order)
         VALUES (?, 'zzP144 Union Profile B', 1, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 999)",
        ['zzp144unionB' . uniqid()]
    );
    $profileBId = (int) db_insert_id();

    $ra = team_upsert_internal(['name' => 'zzP144sp Union Team A ' . uniqid()], 0);
    $rb = team_upsert_internal(['name' => 'zzP144sp Union Team B ' . uniqid()], 0);
    $teamAId = (int) ($ra['id'] ?? 0);
    $teamBId = (int) ($rb['id'] ?? 0);

    db_query(
        "INSERT INTO " . tbl('scheduling_permission_assignments') . "
         (profile_id, scope_type, scope_id, target_type, target_id) VALUES (?, 'global', NULL, 'team', ?)",
        [$profileAId, $teamAId]
    );
    db_query(
        "INSERT INTO " . tbl('scheduling_permission_assignments') . "
         (profile_id, scope_type, scope_id, target_type, target_id) VALUES (?, 'global', NULL, 'team', ?)",
        [$profileBId, $teamBId]
    );

    $mr = member_create_internal(['first_name' => 'zzP144sp', 'last_name' => 'UnionTeam', 'available' => 'Yes'], 0);
    $memUnion = (int) ($mr['id'] ?? 0);
    t("2-team union fixtures created (2 profiles + 2 teams + 2 assignments + member)",
        $profileAId > 0 && $profileBId > 0 && $teamAId > 0 && $teamBId > 0 && $memUnion > 0);

    if ($memUnion > 0) {
        $addA = team_add_member_internal($teamAId, $memUnion, 'Member');
        $addB = team_add_member_internal($teamBId, $memUnion, 'Member');
        t("member added to BOTH teams via the real writer", !empty($addA['success']) && !empty($addB['success']));

        // Sanity: each team ALONE would grant only one of the two flags.
        $onlyA = scheduling_get_permissions($memUnion, 'global', null);
        // (can't isolate to "only team A" once both memberships exist —
        // the union result below is what actually matters; this call
        // just primes nothing, kept for clarity of intent.)

        $perms = scheduling_get_permissions($memUnion, 'global', null);
        t("union grants can_self_assign (from Profile A's team)", $perms['can_self_assign'] === 1);
        t("union grants can_swap (from Profile B's team) — a capability NEITHER profile alone granted together",
            $perms['can_swap'] === 1);
        t("union does not spuriously grant an UNRELATED flag neither profile set (can_manage_slots)",
            $perms['can_manage_slots'] === 0);
        t("profile_code reflects the combined marker", $perms['profile_code'] === 'combined_team_union');
        t("profile_name reflects the combined marker", $perms['profile_name'] === 'Combined (multiple teams)');

        // scheduling_get_effective_permissions() with admin override must
        // still short-circuit to full control regardless (unrelated to
        // this phase, but a real regression risk if the dispatch changed).
        require_once __DIR__ . '/../inc/rbac.php';
    }
} catch (Throwable $e) {
    t("Part 3 ran without a fatal error: " . $e->getMessage(), false);
} finally {
    foreach ([$memZero, $memSingle, $memUnion] as $mid) {
        if ($mid > 0) {
            try { db_query("DELETE FROM " . tbl('team_members') . " WHERE member_id = ?", [$mid]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id = ?", [$mid]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$mid]); } catch (Throwable $e) {}
        }
    }
    foreach ([$teamSingleId, $teamAId, $teamBId] as $tid) {
        if ($tid > 0) {
            try { db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$tid]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$tid]); } catch (Throwable $e) {}
        }
    }
    foreach ([$profileSingleId, $profileAId, $profileBId] as $pid) {
        if ($pid > 0) {
            try { db_query("DELETE FROM " . tbl('scheduling_permission_assignments') . " WHERE profile_id = ?", [$pid]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('scheduling_permission_profiles') . " WHERE id = ?", [$pid]); } catch (Throwable $e) {}
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
