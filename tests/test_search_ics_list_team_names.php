<?php
/**
 * GH#76 Phase 144 (2026-08-18) — search + ICS-qualified list now return
 * team names for junction-only members.
 *
 * Before this phase, api/members.php's search branch (?search=) and its
 * ICS-qualified-list branch (?ics_position_id=) were legacy-JOIN-only with
 * NO merge against team_members at all — meaning both silently showed
 * ZERO team for every member whose team assignment lives only in
 * team_members (the common case: 6/10 team-assigned members on training
 * were ALREADY multi-team through team_members before this release). This
 * is a real, previously-silent bug fix, not cosmetic completeness — see
 * CLAUDE.md's GH#76 pitfall entry.
 *
 * This test exercises the REAL SQL both branches now run (copied verbatim
 * from api/members.php, since including that file directly would try to
 * dispatch a live HTTP request and call json_response()/exit — see
 * tests/test_roster_read_paths_unified.php's identical note) against a
 * throwaway junction-only member (team_id left NULL — deliberately never
 * set, to prove this isn't just re-testing the legacy JOIN path).
 *
 * Usage: php tests/test_search_ics_list_team_names.php
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

echo "=== GH#76 Phase 144 — search + ICS-qualified list team names ===\n\n";

$base = realpath(__DIR__ . '/..');
function tbl($n) { return db_table($n); }

// ── Static: both branches call the shared enrichment helper ────────────
echo "--- Part 1: static — both branches call enrichMemberRowsWithTeams() ---\n\n";
$membersSrc = @file_get_contents($base . '/api/members.php');
if ($membersSrc === false) {
    t("api/members.php readable", false);
} else {
    // Search branch: the call must appear between the search SQL and its
    // json_response(['members' => $rows]).
    $searchBlock = null;
    if (preg_match('/if \(!empty\(\$_GET\[\'search\'\]\)\)[\s\S]*?json_response\(\[\'members\' => \$rows\]\);\s*\n\s*\}/', $membersSrc, $m)) {
        $searchBlock = $m[0];
    }
    t("search branch found", $searchBlock !== null);
    t("search branch calls enrichMemberRowsWithTeams(\$rows) before responding",
        $searchBlock !== null && strpos($searchBlock, 'enrichMemberRowsWithTeams($rows)') !== false);

    $icsBlock = null;
    if (preg_match('/if \(!empty\(\$_GET\[\'ics_position_id\'\]\)\)[\s\S]*?ics_filter/', $membersSrc, $m2)) {
        $icsBlock = $m2[0];
    }
    t("ICS-qualified-list branch found", $icsBlock !== null);
    t("ICS-qualified-list branch calls enrichMemberRowsWithTeams(\$rows) before responding",
        $icsBlock !== null && strpos($icsBlock, 'enrichMemberRowsWithTeams($rows)') !== false);
}

// ── Functional: reproduce the exact enrichment SQL against a junction-
// only member and confirm the team surfaces ─────────────────────────────
echo "\n--- Part 2: functional (real writer, throwaway fixtures) ---\n\n";

$teamId = 0; $memberId = 0;
try {
    $tr = team_upsert_internal(['name' => 'zzP144s Team ' . uniqid()], 0);
    $teamId = (int) ($tr['id'] ?? 0);
    // Deliberately do NOT set member.team_id — this member's ONLY team
    // signal is team_members, reproducing exactly the case that was
    // silently invisible to search/ICS-list before this phase.
    $mr = member_create_internal(['first_name' => 'zzP144s', 'last_name' => 'JunctionOnly', 'available' => 'Yes'], 0);
    $memberId = (int) ($mr['id'] ?? 0);
    t("throwaway team + junction-only member created (member.team_id left NULL)", $teamId > 0 && $memberId > 0);

    if ($teamId > 0 && $memberId > 0) {
        $addRes = team_add_member_internal($teamId, $memberId, 'Member');
        t("member added to the team ONLY via team_members (real writer)", !empty($addRes['success']));

        $teamIdCol = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memberId]);
        t("member.team_id is confirmed still NULL/0 (this is genuinely junction-only)", empty($teamIdCol));

        // Reproduce enrichMemberRowsWithTeams()'s exact query.
        $rows = [['id' => $memberId, 'team_id' => null, 'team_name' => null]];
        $teamMemberships = db_fetch_all(
            "SELECT tm.member_id, tm.team_id, t.`team` AS team_name
             FROM " . tbl('team_members') . " tm
             JOIN " . tbl('teams') . " t ON tm.team_id = t.id
             ORDER BY t.`team`"
        );
        $memberTeams = [];
        foreach ($teamMemberships as $tm) {
            $mid = (int) $tm['member_id'];
            if (!isset($memberTeams[$mid])) $memberTeams[$mid] = [];
            $memberTeams[$mid][] = ['id' => (int) $tm['team_id'], 'name' => $tm['team_name']];
        }
        foreach ($rows as &$m) {
            $mid = (int) $m['id'];
            $m['team_ids']   = isset($memberTeams[$mid]) ? array_column($memberTeams[$mid], 'id') : [];
            $m['team_names'] = isset($memberTeams[$mid]) ? array_column($memberTeams[$mid], 'name') : [];
            if (empty($m['team_id']) && !empty($m['team_ids'])) {
                $m['team_id']   = $m['team_ids'][0];
                $m['team_name'] = $m['team_names'][0];
            }
        }
        unset($m);

        t("enrichment resolves team_ids[] for the junction-only member (previously this stayed [] with NO merge at all)",
            $rows[0]['team_ids'] === [$teamId]);
        t("enrichment back-fills team_id/team_name from the first team when the legacy column is empty",
            $rows[0]['team_id'] === $teamId && !empty($rows[0]['team_name']));
    }
} catch (Throwable $e) {
    t("search/ICS-list test ran without a fatal error: " . $e->getMessage(), false);
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
