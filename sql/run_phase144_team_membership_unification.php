<?php
/**
 * Phase 144 (GH#76, 2026-08-18) — Unify team assignment on team_members;
 * retire the legacy member.team_id write path.
 *
 * Full design spec: the GH#76 design-review document (multi-agent
 * research + proposal + synthesis, chosen "Option A" base). Summary:
 * member.team_id becomes a permanent, read-only-from-internal-code,
 * external-API-compat mirror (never dropped — same treatment this
 * project already gave user.level in Phase 128: eliminated from
 * behavior, not physically removed). team_members becomes the sole
 * internal read/write target for "what team is this member on".
 *
 * WHY THIS SCRIPT EXISTS, NOT JUST sql/teams_nims.sql's ORIGINAL BACKFILL:
 * that backfill only ever ran once, via tools/install_fresh.php at
 * initial install — any team_id value SET AFTER that point (which is
 * exactly what happened on live installs, since the Roster Team dropdown
 * kept writing member.team_id right up until this release) was
 * permanently invisible to team_members. This script is wired into the
 * ONGOING migration runner (sql/run_migrations.php auto-discovers every
 * sql/run_*.php file), not a fresh-install-only step, and is safe to
 * re-run any number of times.
 *
 * Usage:
 *   php sql/run_phase144_team_membership_unification.php --report-only
 *       Read-only dry run. Classifies every member with team_id set into
 *       one of three cases (see Step 1 below) and makes ZERO writes.
 *       RUN THIS FIRST against every install before the real run — see
 *       the design spec's deployment section.
 *
 *   php sql/run_phase144_team_membership_unification.php
 *       The real run: schema + backfill + verify. Idempotent — safe to
 *       re-run (e.g. via sql/run_migrations.php on every future migration
 *       pass; INSERT IGNORE + the existing UNIQUE(team_id, member_id) key
 *       make Step 2 a no-op once already applied).
 *
 * member.team_id is NEVER written by this script (Step 4) — every value
 * it holds pre-migration is left exactly as-is, permanently, as
 * historical/compat data for the external API's team_id compatibility
 * shim (api/external/v1/members.php) to keep reading from.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$reportOnly = in_array('--report-only', $argv ?? [], true);

echo "Phase 144 — Team Membership Unification (GH#76)\n";
echo "=================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p144_col_exists(string $t, string $c): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $t, $c]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

// ── Step 0: schema — guarded ALTER for team_members.source ─────────────
// A plain nullable VARCHAR provenance column, no generated column, no new
// unique key (see design spec §1 — deliberately NOT the is_primary
// generated-column mechanism Options B/C proposed; that mechanism is a
// real risk category on an unknown self-hosted install's DB version, and
// this release has no product need for a "primary team" concept — see
// CLAUDE.md's GH#76 pitfall entry).
if (!$reportOnly) {
    echo "Step 0 — schema\n";
    if (_p144_col_exists('team_members', 'source')) {
        echo "  [OK] team_members.source already exists\n";
    } else {
        try {
            db_query(
                "ALTER TABLE `{$prefix}team_members`
                 ADD COLUMN `source` VARCHAR(20) NULL DEFAULT NULL
                 COMMENT 'GH#76 Phase 144: NULL=human/UI, legacy_migration, external_api'"
            );
            echo "  [OK] Added team_members.source\n";
        } catch (Exception $e) {
            echo "  [FATAL] could not add team_members.source: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    echo "\n";
} else {
    echo "Step 0 — schema (SKIPPED — --report-only makes zero writes)\n";
    if (!_p144_col_exists('team_members', 'source')) {
        echo "  [NOTE] team_members.source does not exist yet — a real run will add it.\n";
    }
    echo "\n";
}

// ── Step 1: classify every member with team_id set ──────────────────────
// Three cases per the design spec:
//   (a) no matching team_members row exists for that team -> Step 2 will
//       create one.
//   (b) a matching team_members row already exists -> no-op, already
//       consistent.
//   (c) [not expected on training or your deployment, but must be handled] the
//       member has team_members rows for OTHER teams but NONE matching
//       their team_id -> DIVERGENCE requiring a human glance. This script
//       does not guess or auto-resolve it.
// Plus a fourth, non-failing bucket: team_id points at a team that no
// longer exists (soft-delete-adjacent orphan — teams use HARD delete with
// no deleted_at column, per inc/team-write.php's own docblock, so "the
// team is gone" just means no row with that id remains).
echo ($reportOnly ? "Step 1 — dry run (--report-only, zero writes)\n" : "Step 1 — pre-flight classification\n");

$members = [];
try {
    $members = db_fetch_all(
        "SELECT m.id, m.team_id, m.first_name, m.last_name
         FROM `{$prefix}member` m
         WHERE m.team_id IS NOT NULL AND m.team_id > 0"
    );
} catch (Exception $e) {
    echo "  [FATAL] could not read member table: " . $e->getMessage() . "\n";
    exit(1);
}

$caseA = [];      // no matching row — Step 2 will create one
$caseB = [];      // already consistent — no-op
$caseC = [];      // DIVERGENCE — refuse to guess
$caseOrphan = []; // team_id references a team that no longer exists

foreach ($members as $m) {
    $mid  = (int) $m['id'];
    $tid  = (int) $m['team_id'];
    $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));

    $teamExists = false;
    try {
        $teamExists = (bool) db_fetch_value("SELECT id FROM `{$prefix}teams` WHERE id = ?", [$tid]);
    } catch (Exception $e) {}
    if (!$teamExists) {
        $caseOrphan[] = ['id' => $mid, 'name' => $name, 'team_id' => $tid];
        continue;
    }

    $matching = false;
    try {
        $matching = (bool) db_fetch_value(
            "SELECT id FROM `{$prefix}team_members` WHERE team_id = ? AND member_id = ?",
            [$tid, $mid]
        );
    } catch (Exception $e) {}
    if ($matching) {
        $caseB[] = ['id' => $mid, 'name' => $name, 'team_id' => $tid];
        continue;
    }

    $otherRows = [];
    try {
        $otherRows = db_fetch_all(
            "SELECT tm.team_id, t.`team` AS team_name
             FROM `{$prefix}team_members` tm
             JOIN `{$prefix}teams` t ON t.id = tm.team_id
             WHERE tm.member_id = ?",
            [$mid]
        );
    } catch (Exception $e) {}

    if (!empty($otherRows)) {
        $caseC[] = ['id' => $mid, 'name' => $name, 'team_id' => $tid, 'other' => $otherRows];
    } else {
        $caseA[] = ['id' => $mid, 'name' => $name, 'team_id' => $tid];
    }
}

echo "  (a) no matching team_members row — Step 2 will create one: " . count($caseA) . "\n";
foreach ($caseA as $c) echo "      #{$c['id']} {$c['name']} -> team #{$c['team_id']}\n";
echo "  (b) already consistent (no-op): " . count($caseB) . "\n";
foreach ($caseB as $c) echo "      #{$c['id']} {$c['name']} -> team #{$c['team_id']}\n";
echo "  (c) DIVERGENCE — member has OTHER team_members rows, none matching team_id: " . count($caseC) . "\n";
foreach ($caseC as $c) {
    $otherNames = array_map(function ($o) { return "#{$o['team_id']} '{$o['team_name']}'"; }, $c['other']);
    echo "      #{$c['id']} {$c['name']}: team_id-implied team #{$c['team_id']}, actual team_members: " . implode(', ', $otherNames) . "\n";
    echo "        -> REVIEW BEFORE PROCEEDING. This script does not guess which is correct.\n";
}
echo "  orphaned — team_id references a team that no longer exists (Step 2's EXISTS guard will correctly skip): " . count($caseOrphan) . "\n";
foreach ($caseOrphan as $c) echo "      #{$c['id']} {$c['name']} -> team #{$c['team_id']} (does not exist)\n";
echo "\n";

if ($reportOnly) {
    echo "--report-only: no changes were made. Review any DIVERGENCE (case c) findings above\n";
    echo "before re-running without --report-only.\n";
    exit(0);
}

if (!empty($caseC)) {
    echo "[FATAL] " . count($caseC) . " member(s) have a DIVERGENCE between team_id and their\n";
    echo "        existing team_members rows (case c above). Refusing to guess which is\n";
    echo "        correct. Resolve by hand (decide the right team_members row per member\n";
    echo "        listed above), then re-run.\n";
    exit(1);
}

// ── Step 2: backfill (idempotent, re-runnable) ───────────────────────────
// The EXISTS clause is required and was NOT present in the original
// sql/teams_nims.sql backfill this migration replaces (that file has since
// been corrected too, for fresh installs) — without it, an unguarded
// backfill can create a team_members row pointing at a team that
// team_soft_delete_internal() (inc/team-write.php) hard-deleted, since
// nothing clears member.team_id when that cascade runs.
echo "Step 2 — backfill\n";
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}team_members` (`team_id`, `member_id`, `role`, `assigned_date`, `source`)
         SELECT m.`team_id`, m.`id`, 'Member', COALESCE(m.`join_date`, CURDATE()), 'legacy_migration'
         FROM `{$prefix}member` m
         WHERE m.`team_id` IS NOT NULL AND m.`team_id` > 0
           AND EXISTS (SELECT 1 FROM `{$prefix}teams` t WHERE t.`id` = m.`team_id`)"
    );
    echo "  [OK] backfill INSERT IGNORE executed (relies on the existing UNIQUE(team_id, member_id)\n";
    echo "       key for idempotency — safe to re-run).\n";
} catch (Exception $e) {
    echo "  [FATAL] backfill failed: " . $e->getMessage() . "\n";
    exit(1);
}
echo "\n";

// ── Step 3: verify the OUTCOME, not just the bookkeeping ─────────────────
// Mirrors this project's own Phase 128 A9b precedent: "a migration step
// that catches its own exception and exits 0 is a step that never ran."
// Re-query and confirm every member with a live team_id now has a
// matching team_members row. Orphaned members (team_id -> deleted team)
// are EXPECTED to still lack a row — Step 2's EXISTS guard correctly
// skips them — and are logged by name, not silently dropped, but do not
// fail the run. Any OTHER unresolved case fails the run non-zero.
echo "Step 3 — verify\n";
$unresolved = [];
$loggedOrphans = [];
foreach ($members as $m) {
    $mid  = (int) $m['id'];
    $tid  = (int) $m['team_id'];
    $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));

    $teamExists = false;
    try {
        $teamExists = (bool) db_fetch_value("SELECT id FROM `{$prefix}teams` WHERE id = ?", [$tid]);
    } catch (Exception $e) {}
    if (!$teamExists) {
        $loggedOrphans[] = "#{$mid} {$name} -> team #{$tid} (team no longer exists — correctly skipped by Step 2's EXISTS guard)";
        continue;
    }

    $matching = false;
    try {
        $matching = (bool) db_fetch_value(
            "SELECT id FROM `{$prefix}team_members` WHERE team_id = ? AND member_id = ?",
            [$tid, $mid]
        );
    } catch (Exception $e) {}
    if (!$matching) {
        $unresolved[] = "#{$mid} {$name} -> team #{$tid} (still no matching team_members row after backfill)";
    }
}

if (!empty($loggedOrphans)) {
    echo "  [INFO] orphaned (logged by name, not a failure):\n";
    foreach ($loggedOrphans as $l) echo "    {$l}\n";
}
if (!empty($unresolved)) {
    echo "  [FATAL] " . count($unresolved) . " member(s) still lack a matching team_members row after backfill:\n";
    foreach ($unresolved as $u) echo "    {$u}\n";
    exit(1);
}
echo "  [OK] every member with a live team_id now has a matching team_members row.\n\n";

// ── Step 4: member.team_id is NEVER modified by this script ─────────────
echo "Step 4 — member.team_id left untouched (permanent compat column; this script never\n";
echo "         writes it — see api/external/v1/members.php for the one path that still\n";
echo "         accepts a team_id INPUT, which mirrors it into team_members, not here).\n\n";

echo "Done. Next: run tools/gen_schema_manifest.php and tools/gen_schema_reference.php,\n";
echo "then check the Status page's \"Team membership reconciliation\" health card.\n";
exit(0);
