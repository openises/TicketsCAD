<?php
/**
 * Run Phase 147 migration — `user` table dead-column cleanup (GH#91).
 *
 * GH#91 asked for a full audit of "controls/columns that exist but
 * nothing reads." The issue's own starting list named ~15 `user` columns;
 * `responder_id` and `facility_id` were split off to GH#90/Phase 145
 * (responder_id dropped, facility_id repurposed for facility logins;
 * see sql/run_phase145_facility_accounts.php) and `level` was
 * deliberately left for this phase to decide.
 *
 * Verified independently, per-column, via a whole-tree grep across
 * api/, inc/, tools/, services/, proxy/, page templates, and assets/js/
 * (excluding tests/docs/specs — see tools/dead_control_audit.php's own
 * column check, which surfaced 5 of these 10 DROP candidates beyond the
 * issue's own list). Two shapes, three outcomes:
 *
 *   DROP (10) — a legacy v3 mechanism, confirmed superseded, with ZERO
 *   writers and ZERO readers anywhere in the app (a couple are still
 *   populated by test/seed fixtures only — tools/seed_training_demo.php,
 *   tools/test_par_ownership.php — never by real application code):
 *     - `pers`, `disp`, `teams`, `reporting` — legacy v3 per-feature
 *       binary access flags ("personnel data access", "dispatch access",
 *       "teams data access", implicit reporting access). Fully superseded
 *       by RBAC's granular screen.NNN / action.NNN permissions (Phase 128
 *       eliminated user.level as an authorization signal the same way).
 *     - `open_at` — legacy "which page to show after login" (d/f/p/t).
 *       No v4 equivalent concept; NewUI has one dashboard entry point.
 *     - `ticket_per_page` — legacy per-user pagination. Superseded by the
 *       global `page_size` setting (Settings -> Display Settings).
 *     - `sortorder`, `sort_desc` — legacy default list-sort preference.
 *       No v4 equivalent; list views manage their own sort state.
 *     - `browser`, `sid` — legacy last-login-browser / PHP session id
 *       tracking on the user row itself. Superseded by the dedicated
 *       `active_sessions` table (session_id, user_agent, force-logout
 *       support) — a real, actively-used mechanism, not an absent one.
 *
 *   MARK (7) — never built into any UI or backend logic, but NOT a
 *   removed/superseded mechanism either: a plausible small "expanded
 *   user profile" feature (name_mi, title_id, mailing address, a
 *   secondary phone/email) that simply never got wired to anything.
 *   Column kept; comment updated to say so, so a future reader does not
 *   mistake "commented, therefore designed" for "commented, therefore
 *   implemented" (the exact trap GH#91 named — user.facility_id's old
 *   comment 'For level = facility' before Phase 145 fixed it):
 *     `name_mi`, `title_id`, `addr_street`, `addr_city`, `addr_st`,
 *     `phone_s`, `email_s`.
 *
 *   CORRECT THE COMMENT (1) — `level` itself. Confirmed genuinely alive,
 *   not dead: still written by tools/create_admin.php and
 *   api/legacy-import.php, and still READ — but only by the one-time
 *   v3->v4 migration bridge (api/rbac.php's migrate_levels action,
 *   sql/run_rbac_v2.php's A9/A9b orphan-repair step, tools/migrate_rbac.php).
 *   rbac_can() itself never consults it (Phase 128,
 *   tools/legacy_level_audit.php's baseline is empty). The stale comment
 *   'privileges' is exactly the bait GH#91 is about — it describes a
 *   mechanism (level-based authorization) that no longer exists, even
 *   though the COLUMN still has a real, narrower purpose. Column and
 *   value are left completely untouched; only the comment changes.
 *
 * Idempotent — every step guarded by an information_schema existence
 * check, safe to re-run. DROP steps check the column still exists before
 * attempting removal; comment-only steps check the comment hasn't
 * already been updated.
 *
 * Usage:  php sql/run_phase147_user_table_cleanup.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 147 — user table dead-column cleanup (GH#91)\n";
echo "====================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$table  = $prefix . 'user';

/** Does this column currently exist on the user table? */
function p147_col_exists(string $table, string $col): bool
{
    return (bool) db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$table, $col]
    );
}

/** Drop $col from `user` if it's still there. */
function p147_drop(string $table, string $prefix, string $col, string $why): void
{
    try {
        if (!p147_col_exists($table, $col)) {
            echo "[OK] {$table}.{$col} already absent (skipped)\n";
            return;
        }
        db_query("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
        echo "[OK] Dropped {$table}.{$col} — {$why}\n";
    } catch (Exception $e) {
        echo "[WARN] drop {$table}.{$col}: " . $e->getMessage() . "\n";
    }
}

// ── DROP: 10 confirmed-dead legacy columns ──────────────────────────────
p147_drop($table, $prefix, 'pers',
    'legacy v3 "personnel data access" flag, superseded by RBAC');
p147_drop($table, $prefix, 'disp',
    'legacy v3 "dispatch access" flag, superseded by RBAC');
p147_drop($table, $prefix, 'teams',
    'legacy v3 "teams data access" flag, superseded by RBAC');
p147_drop($table, $prefix, 'reporting',
    'legacy v3 reporting-access flag, superseded by RBAC');
p147_drop($table, $prefix, 'open_at',
    'legacy "page to show after login" (d/f/p/t) -- no v4 equivalent');
p147_drop($table, $prefix, 'ticket_per_page',
    'legacy per-user pagination, superseded by the global page_size setting');
p147_drop($table, $prefix, 'sortorder',
    'legacy default list-sort preference -- no v4 equivalent');
p147_drop($table, $prefix, 'sort_desc',
    'legacy default list-sort direction -- no v4 equivalent');
p147_drop($table, $prefix, 'browser',
    'legacy last-login-browser tracking, superseded by active_sessions.user_agent');
p147_drop($table, $prefix, 'sid',
    'legacy PHP session id on the user row, superseded by active_sessions.session_id');

// ── MARK: 7 never-wired profile fields — comment only, column kept ─────
$markColumns = [
    // col            type                                    comment
    ['name_mi',       'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (middle initial), not a removed mechanism."],
    ['title_id',      'tinyint(2)',                            "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (title), not a removed mechanism."],
    ['addr_street',   'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (mailing address), not a removed mechanism."],
    ['addr_city',     'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (mailing address), not a removed mechanism."],
    ['addr_st',       'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (mailing address), not a removed mechanism."],
    ['phone_s',       'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (secondary phone), not a removed mechanism."],
    ['email_s',       'text',                                 "Phase 147 (GH#91): reserved. No UI reads or writes this -- never built into any user-profile page. Not RBAC/authorization-relevant. A plausible future 'full profile' field (secondary email), not a removed mechanism."],
];
foreach ($markColumns as [$col, $type, $comment]) {
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $col]
        );
        if ($row === null) {
            echo "[WARN] {$table}.{$col} not found -- cannot mark\n";
            continue;
        }
        if (strpos((string) ($row['COLUMN_COMMENT'] ?? ''), 'Phase 147') !== false) {
            echo "[OK] {$table}.{$col} comment already updated (skipped)\n";
            continue;
        }
        $escComment = str_replace("'", "''", $comment);
        db_query("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` {$type} NULL COMMENT '{$escComment}'");
        echo "[OK] Marked {$table}.{$col} as reserved/never-wired (comment updated; column kept)\n";
    } catch (Exception $e) {
        echo "[WARN] mark {$table}.{$col}: " . $e->getMessage() . "\n";
    }
}

// ── CORRECT: user.level's stale 'privileges' comment ────────────────────
try {
    $row = db_fetch_one(
        "SELECT COLUMN_COMMENT FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'level'",
        [$table]
    );
    if ($row === null) {
        echo "[WARN] {$table}.level not found -- cannot correct comment\n";
    } elseif (strpos((string) ($row['COLUMN_COMMENT'] ?? ''), 'Phase 147') !== false) {
        echo "[OK] {$table}.level comment already corrected (skipped)\n";
    } else {
        db_query(
            "ALTER TABLE `{$table}` MODIFY COLUMN `level` tinyint(1) NOT NULL DEFAULT 0
             COMMENT 'Phase 147 (GH#91): NOT an authorization signal -- rbac_can() never consults this (Phase 128). Still written by tools/create_admin.php and api/legacy-import.php, and still READ, but ONLY by the one-time v3->v4 migration bridge (api/rbac.php migrate_levels, sql/run_rbac_v2.php A9/A9b, tools/migrate_rbac.php). Do not add a new reader.'"
        );
        echo "[OK] Corrected {$table}.level comment (was 'privileges'; column and value unchanged)\n";
    }
} catch (Exception $e) {
    echo "[WARN] correct {$table}.level comment: " . $e->getMessage() . "\n";
}

// ── Verify outcome ───────────────────────────────────────────────────────
$dropped = ['pers', 'disp', 'teams', 'reporting', 'open_at', 'ticket_per_page', 'sortorder', 'sort_desc', 'browser', 'sid'];
$stillPresent = [];
foreach ($dropped as $c) {
    if (p147_col_exists($table, $c)) { $stillPresent[] = $c; }
}
echo "\n";
if ($stillPresent) {
    echo "[FAIL] Columns still present after drop attempt: " . implode(', ', $stillPresent) . "\n";
    exit(1);
}
echo "[OK] All 10 dead columns confirmed dropped from {$table}\n";
echo "Phase 147 complete.\n";
