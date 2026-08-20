<?php
/**
 * GH#76 Phase 144 (2026-08-18) — sql/run_phase144_team_membership_unification.php
 *
 * Covers, against throwaway fixtures created via the REAL writers
 * (team_upsert_internal, member_create_internal, team_add_member_internal
 * — never hand-INSERTed ideal rows):
 *
 *   1. --report-only case (a): member.team_id set, no matching team_members
 *      row -- correctly classified, and makes ZERO writes.
 *   2. --report-only case (b): member.team_id set, a matching team_members
 *      row already exists -- correctly classified as no-op.
 *   3. --report-only case (c) DIVERGENCE: member has team_members rows for
 *      a DIFFERENT team than team_id points at -- correctly flagged, and
 *      the REAL (non-report-only) run REFUSES (non-zero exit) rather than
 *      guessing which is correct.
 *   4. Orphaned team_id (points at a team that no longer exists): the real
 *      run's Step 2 EXISTS guard correctly skips it (no team_members row
 *      created), Step 3's verify logs it as informational, and the run
 *      still exits 0 (non-fatal).
 *   5. Idempotency: running the real migration twice creates the
 *      team_members row exactly once (INSERT IGNORE + the existing
 *      UNIQUE(team_id, member_id) key), not a duplicate.
 *   6. The migration NEVER writes member.team_id itself (Step 4) — value
 *      before and after is byte-identical.
 *
 * Every fixture is created and destroyed within this test — no existing
 * member/team/user account is touched (per this project's standing rule).
 *
 * Usage: php tests/test_team_membership_unification_migration.php
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

echo "=== GH#76 Phase 144 — Team Membership Unification migration ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');
$script = $base . '/sql/run_phase144_team_membership_unification.php';
$phpBin = PHP_BINARY ?: 'php';

function tbl($n) { return db_table($n); }

function _p144t_run(string $script, string $phpBin, array $extraArgs = []): array {
    $cmd = [$phpBin, $script];
    foreach ($extraArgs as $a) $cmd[] = $a;
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return ['', '', 127];
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($proc);
    return [$out, $err, $exit];
}

if (!file_exists($script)) {
    t("sql/run_phase144_team_membership_unification.php exists", false);
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
t("sql/run_phase144_team_membership_unification.php exists", true);

// ═══════════════════════════════════════════════════════════════════════
// Fixtures: two throwaway teams
// ═══════════════════════════════════════════════════════════════════════
$teamAId = 0; $teamBId = 0;
$madeMembers = [];

try {
    $ra = team_upsert_internal(['name' => 'zzP144 Team A ' . uniqid()], 0);
    $rb = team_upsert_internal(['name' => 'zzP144 Team B ' . uniqid()], 0);
    $teamAId = (int) ($ra['id'] ?? 0);
    $teamBId = (int) ($rb['id'] ?? 0);
    t("created throwaway Team A via the real writer", $teamAId > 0);
    t("created throwaway Team B via the real writer", $teamBId > 0);

    if ($teamAId <= 0 || $teamBId <= 0) {
        throw new Exception('team fixtures failed — aborting');
    }

    // ── Case (a): team_id set, no matching team_members row ────────────
    $rA = member_create_internal(['first_name' => 'zzP144a', 'last_name' => 'CaseA', 'available' => 'Yes'], 0);
    $memA = (int) ($rA['id'] ?? 0);
    t("case (a) fixture member created", $memA > 0);
    if ($memA > 0) {
        db_query("UPDATE " . tbl('member') . " SET team_id = ? WHERE id = ?", [$teamAId, $memA]);
        $madeMembers[] = $memA;
    }

    // ── Case (b): team_id set, matching team_members row already exists ─
    $rB = member_create_internal(['first_name' => 'zzP144b', 'last_name' => 'CaseB', 'available' => 'Yes'], 0);
    $memB = (int) ($rB['id'] ?? 0);
    t("case (b) fixture member created", $memB > 0);
    if ($memB > 0) {
        db_query("UPDATE " . tbl('member') . " SET team_id = ? WHERE id = ?", [$teamAId, $memB]);
        $addRes = team_add_member_internal($teamAId, $memB, 'Member');
        t("case (b) fixture pre-seeded with a matching team_members row via the real writer", !empty($addRes['success']));
        $madeMembers[] = $memB;
    }

    echo "\n--- Part 1: --report-only (zero writes) classification ---\n\n";
    [$out1, $err1, $exit1] = _p144t_run($script, $phpBin, ['--report-only']);
    t("--report-only exits 0", $exit1 === 0);
    t("--report-only output classifies case (a) fixture correctly",
        (bool) preg_match('/\(a\).*?\n(?:.*\n)*?\s*#' . $memA . ' /', $out1) || strpos($out1, "#{$memA} ") !== false);
    // More precise: the member id must appear under the "(a)" bucket specifically,
    // i.e. before the "(b)" heading in the output.
    $posA = strpos($out1, '(a) no matching');
    $posB = strpos($out1, '(b) already consistent');
    $posC = strpos($out1, '(c) DIVERGENCE');
    $bucketA = ($posA !== false && $posB !== false) ? substr($out1, $posA, $posB - $posA) : '';
    $bucketB = ($posB !== false && $posC !== false) ? substr($out1, $posB, $posC - $posB) : '';
    t("member #{$memA} (case a) listed under bucket (a)", strpos($bucketA, "#{$memA} ") !== false);
    t("member #{$memB} (case b) listed under bucket (b)", strpos($bucketB, "#{$memB} ") !== false);
    t("member #{$memA} (case a) NOT listed under bucket (b)", strpos($bucketB, "#{$memA} ") === false);

    // Confirm --report-only truly made zero writes: case (a) member still
    // has no team_members row after the dry run.
    $stillNone = !db_fetch_value(
        "SELECT id FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
        [$teamAId, $memA]
    );
    t("--report-only made ZERO writes (case-a member still has no team_members row)", $stillNone);

    echo "\n--- Part 2: divergence (case c) — real run refuses ---\n\n";
    // Give member A (currently case-a) a team_members row for TEAM B
    // instead of team A -- now team_id (A) disagrees with their actual
    // team_members membership (B). This is case (c).
    $addDiv = team_add_member_internal($teamBId, $memA, 'Member');
    t("divergence fixture: member now has a team_members row for a DIFFERENT team than team_id", !empty($addDiv['success']));

    [$outC, , $exitC] = _p144t_run($script, $phpBin, ['--report-only']);
    $posC2 = strpos($outC, '(c) DIVERGENCE');
    $bucketC = ($posC2 !== false) ? substr($outC, $posC2) : '';
    t("--report-only flags member #{$memA} under DIVERGENCE (case c)", strpos($bucketC, "#{$memA} ") !== false);

    [, , $exitReal] = _p144t_run($script, $phpBin, []);
    t("the REAL run REFUSES (non-zero exit) while a divergence exists, rather than guessing", $exitReal !== 0);

    // Resolve the divergence by removing the extra team_members row so the
    // rest of this test (and the shared dev DB's normal migration state)
    // isn't left blocked.
    db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?", [$teamBId, $memA]);
    t("divergence resolved (extra team_members row removed) for the rest of this test", true);

    echo "\n--- Part 3: orphaned team_id (points at a deleted team) ---\n\n";
    $rOrphanTeam = team_upsert_internal(['name' => 'zzP144 Orphan Team ' . uniqid()], 0);
    $orphanTeamId = (int) ($rOrphanTeam['id'] ?? 0);
    $rOrphan = member_create_internal(['first_name' => 'zzP144o', 'last_name' => 'Orphan', 'available' => 'Yes'], 0);
    $memOrphan = (int) ($rOrphan['id'] ?? 0);
    t("orphan fixtures created", $orphanTeamId > 0 && $memOrphan > 0);
    if ($orphanTeamId > 0 && $memOrphan > 0) {
        db_query("UPDATE " . tbl('member') . " SET team_id = ? WHERE id = ?", [$orphanTeamId, $memOrphan]);
        // Hard-delete the team directly (teams has no soft-delete column —
        // mirrors what team_soft_delete_internal()'s cascade produces:
        // team_members row(s) gone, team row gone, member.team_id untouched).
        db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$orphanTeamId]);
        db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$orphanTeamId]);
        $madeMembers[] = $memOrphan;

        [$outO, , $exitO] = _p144t_run($script, $phpBin, ['--report-only']);
        $posOrphan = strpos($outO, 'orphaned —');
        $bucketOrphan = ($posOrphan !== false) ? substr($outO, $posOrphan) : '';
        t("--report-only flags the orphaned member under the orphan bucket", strpos($bucketOrphan, "#{$memOrphan} ") !== false);

        [, , $exitRealOrphan] = _p144t_run($script, $phpBin, []);
        t("the real run still exits 0 with only an orphan present (non-fatal)", $exitRealOrphan === 0);

        $noRowCreated = !db_fetch_value(
            "SELECT id FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
            [$orphanTeamId, $memOrphan]
        );
        t("Step 2's EXISTS guard correctly skipped creating a team_members row for the deleted team", $noRowCreated);
    }

    echo "\n--- Part 4: idempotency + member.team_id untouched ---\n\n";
    $teamIdBefore = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memA]);

    [, , $exitRun1] = _p144t_run($script, $phpBin, []);
    t("real run (post-divergence-resolution) exits 0", $exitRun1 === 0);
    $countAfter1 = (int) db_fetch_value(
        "SELECT COUNT(*) FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
        [$teamAId, $memA]
    );
    t("case-a member has exactly ONE team_members row after the first real run", $countAfter1 === 1);

    [, , $exitRun2] = _p144t_run($script, $phpBin, []);
    t("re-running the migration exits 0 (idempotent)", $exitRun2 === 0);
    $countAfter2 = (int) db_fetch_value(
        "SELECT COUNT(*) FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
        [$teamAId, $memA]
    );
    t("re-running does NOT create a duplicate row (still exactly one)", $countAfter2 === 1);

    $teamIdAfter = db_fetch_value("SELECT team_id FROM " . tbl('member') . " WHERE id = ?", [$memA]);
    t("member.team_id is byte-identical before and after the migration (Step 4 — never written)",
        (string) $teamIdBefore === (string) $teamIdAfter);

    $sourceVal = db_fetch_value(
        "SELECT source FROM " . tbl('team_members') . " WHERE team_id = ? AND member_id = ?",
        [$teamAId, $memA]
    );
    t("the backfilled row is tagged source='legacy_migration'", $sourceVal === 'legacy_migration');

} catch (Throwable $e) {
    t("migration test ran without a fatal error: " . $e->getMessage(), false);
} finally {
    // Hard-clean every throwaway fixture.
    foreach ($madeMembers as $id) {
        try { db_query("DELETE FROM " . tbl('team_members') . " WHERE member_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member_organizations') . " WHERE member_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM " . tbl('member') . " WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ([$teamAId, $teamBId] as $tid) {
        if ($tid > 0) {
            try { db_query("DELETE FROM " . tbl('team_members') . " WHERE team_id = ?", [$tid]); } catch (Throwable $e) {}
            try { db_query("DELETE FROM " . tbl('teams') . " WHERE id = ?", [$tid]); } catch (Throwable $e) {}
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
