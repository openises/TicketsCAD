<?php
/**
 * GH#122 — make `roles.uk_role_name_org` actually constrain.
 * -----------------------------------------------------------
 *
 * THE DEFECT (reported by rjonesbsink, 2026-08-28, already root-caused
 * precisely in the issue itself)
 *
 * `roles` carries `UNIQUE KEY uk_role_name_org (name, org_id)` — the same
 * NULL-in-a-unique-index defect this codebase has already found and fixed
 * three times (Phase 129's user_roles.scope_key, Phase 140's
 * ics_form_types.org_key, Phase 141's org_type_routing.match_key): MySQL
 * and MariaDB treat every NULL as distinct in a UNIQUE index, so a key
 * containing a NULLable column places NO constraint on rows where that
 * column is NULL. Every global role (org_id IS NULL — which is every
 * seeded role) is exactly that row.
 *
 * `sql/rbac.sql` and `sql/run_00_rbac.php` both seed the 'Facility' role
 * WITHOUT an explicit id (deliberately — a real install can already have
 * a custom role occupying id 7):
 *
 *     INSERT IGNORE INTO roles (name, description, is_default, sort_order)
 *     VALUES ('Facility', ..., 0, 7);
 *
 * INSERT IGNORE suppresses the duplicate-key error the comment expects
 * uk_role_name_org to raise — but there is no duplicate-key error to
 * suppress, because the key cannot see two NULL org_ids as a collision.
 * So this statement inserts a NEW 'Facility' role on EVERY migration run.
 * Roles 1-6 are unaffected because they ARE seeded with explicit ids,
 * where the PRIMARY KEY does the real work; Facility is the only seeded
 * role relying on the broken index.
 *
 * Confirmed on the reporter's install: 6 duplicate rows (ids 7-12), one
 * per migration run since v4.2.23 (2026-08-20). Inert today only because
 * inc/facility-scope.php resolves the role by NAME and
 * `SELECT id ... WHERE name='Facility' LIMIT 1` happens to return the
 * lowest id — the original row. That is luck, not a guarantee: the
 * permission counts already show drift (7, 5, 5, 2, 2, 2 — later
 * duplicates are half-configured seed-run artifacts), and if the
 * original row were ever deleted, name resolution would silently land on
 * an under-permissioned duplicate instead.
 *
 * THE FIX — same shape as sql/run_rbac_v3_grant_uniqueness.php (Phase 129):
 *
 *   1. Dedupe existing duplicate (name, org_id)-collapsed groups, keeping
 *      the OLDEST row (lowest id — the one with the real history/most
 *      complete permission grants; later ones are seed-run artifacts,
 *      exactly as Phase 129 reasoned for user_roles). Before deleting a
 *      duplicate role row, reassign any user_roles grants pointing at it
 *      to the kept row instead, so a real user's access is never
 *      silently dropped even if this install's duplicates DID pick up a
 *      user assignment (unlike the reporter's own install, where none
 *      had). role_permissions rows on the duplicate are simply removed
 *      with it (they describe the SAME role by name; the kept row's own
 *      role_permissions already cover it, or the standard RBAC re-seed
 *      grant statements will fill in anything the oldest row is missing
 *      on the very next migration pass).
 *   2. Add a STORED generated column `org_key` = COALESCE(org_id, -1) —
 *      same technique, same column name convention as ics_form_types.org_key.
 *   3. Drop uk_role_name_org and rebuild it as (name, org_key). The index
 *      NAME is kept unchanged (uk_role_name_org) so nothing that checks
 *      for this index by name needs updating.
 *   4. VERIFY the outcome by asking the database directly, never trusting
 *      that the steps ran without error (CLAUDE.md, Phase 125/128).
 *
 * No change is needed to the seed statements themselves in sql/rbac.sql /
 * sql/run_00_rbac.php — INSERT IGNORE's conflict detection works against
 * whatever the table's real unique indexes cover, and org_key is computed
 * automatically from org_id on every insert. Once the index binds NULL
 * org_ids via org_key, the EXISTING seed statements start being correctly
 * idempotent with no code change on their end — exactly as Phase 129's
 * fix needed no change to run_00_rbac.php's user_roles seed line either.
 *
 * Idempotent. Safe to re-run.
 *
 * Usage:  php sql/run_gh122_roles_org_key.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$failures = [];

function gh122_say(string $s): void { echo $s . "\n"; }

function gh122_col_exists(string $table, string $col): bool {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    return (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
        [$prefix . $table, $col]) > 0;
}

function gh122_index_columns(string $table, string $index): array {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all(
        "SELECT COLUMN_NAME c FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
          ORDER BY SEQ_IN_INDEX", [$prefix . $table, $index]);
    $out = [];
    foreach ($rows as $r) $out[] = $r['c'];
    return $out;
}

gh122_say('GH#122 — roles.uk_role_name_org uniqueness fix');
gh122_say(str_repeat('=', 62));

if (!gh122_col_exists('roles', 'name')) {
    gh122_say('[SKIP] roles table not present — run sql/run_00_rbac.php first.');
    exit(0);
}

// ── 1. Dedupe: keep the OLDEST row per (name, org_id) group ─────────────
try {
    $rows = db_fetch_all("SELECT id, name, org_id FROM `{$prefix}roles` ORDER BY id");

    $seen = [];   // "name|org_id-sentinel" => id of the oldest row holding it
    $drop = [];
    foreach ($rows as $r) {
        $k = $r['name'] . '|' . ($r['org_id'] === null ? "\0" : (string) $r['org_id']);
        if (isset($seen[$k])) $drop[] = (int) $r['id'];
        else                  $seen[$k] = (int) $r['id'];
    }

    if (!$drop) {
        gh122_say('[ok]  no duplicate roles');
    } else {
        foreach ($drop as $dropId) {
            // Find which kept row this duplicate's (name, org_id) maps to.
            $dupRow = null;
            foreach ($rows as $r) { if ((int) $r['id'] === $dropId) { $dupRow = $r; break; } }
            if ($dupRow === null) continue;
            $k = $dupRow['name'] . '|' . ($dupRow['org_id'] === null ? "\0" : (string) $dupRow['org_id']);
            $keepId = $seen[$k];

            // Reassign any user_roles grants on the duplicate to the kept
            // row FIRST, so a real user's access is never silently
            // dropped by the cleanup below, even on an install where a
            // duplicate role did pick up a user assignment.
            $reassigned = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}user_roles` WHERE role_id = ?", [$dropId]);
            if ($reassigned > 0) {
                db_query("UPDATE IGNORE `{$prefix}user_roles` SET role_id = ? WHERE role_id = ?",
                    [$keepId, $dropId]);
                // Anything UPDATE IGNORE couldn't move (would collide with
                // an existing grant on the kept role) is now a true
                // duplicate grant — remove it rather than leave it
                // dangling on a role_id about to be deleted.
                db_query("DELETE FROM `{$prefix}user_roles` WHERE role_id = ?", [$dropId]);
                gh122_say("[new] reassigned {$reassigned} user_roles grant(s) from duplicate role #{$dropId} to #{$keepId}");
            }

            db_query("DELETE FROM `{$prefix}role_permissions` WHERE role_id = ?", [$dropId]);
            db_query("DELETE FROM `{$prefix}roles` WHERE id = ?", [$dropId]);
        }
        gh122_say('[new] removed ' . count($drop) . ' duplicate role row(s), oldest of each kept');
    }
} catch (Throwable $e) {
    $failures[] = 'dedupe: ' . $e->getMessage();
    gh122_say('[FAIL] dedupe: ' . $e->getMessage());
}

// ── 2. A uniqueness constraint that actually constrains ─────────────────
try {
    if (gh122_col_exists('roles', 'org_key')) {
        gh122_say('[ok]  roles.org_key generated column present');
    } else {
        // STORED (not VIRTUAL) so it can carry a UNIQUE index.
        // GENERATED ALWAYS AS (...) STORED is the spelling that works on
        // both engines (MariaDB rejects NOT NULL before AS outright).
        $base = "ALTER TABLE `{$prefix}roles`
                 ADD COLUMN `org_key` INT
                     GENERATED ALWAYS AS (COALESCE(`org_id`, -1)) STORED
                     COMMENT 'NULL-collapsed org_id so UNIQUE can see it -- GH#122'";
        try {
            db_query($base . ' INVISIBLE');
        } catch (Throwable $inv) {
            // MariaDB < 10.3 / MySQL < 8.0.23 have no INVISIBLE.
            db_query($base);
            gh122_say('[note] INVISIBLE unsupported here — org_key is visible '
                 . 'to SELECT *; avoid SELECT *-based row copies of roles');
        }
        gh122_say('[new] added roles.org_key (COALESCE(org_id,-1), STORED)');
    }
} catch (Throwable $e) {
    $failures[] = 'org_key column: ' . $e->getMessage();
    gh122_say('[FAIL] org_key column: ' . $e->getMessage());
}

try {
    $cur  = gh122_index_columns('roles', 'uk_role_name_org');
    $want = ['name', 'org_key'];
    if ($cur === $want) {
        gh122_say('[ok]  uk_role_name_org already covers org_key');
    } elseif (!gh122_col_exists('roles', 'org_key')) {
        gh122_say('[SKIP] org_key missing — cannot rebuild the unique key');
    } else {
        if ($cur) {
            db_query("ALTER TABLE `{$prefix}roles` DROP INDEX `uk_role_name_org`");
        }
        db_query("ALTER TABLE `{$prefix}roles`
                  ADD UNIQUE KEY `uk_role_name_org` (`name`, `org_key`)");
        gh122_say('[new] uk_role_name_org rebuilt over org_key — global roles are now unique');
    }
} catch (Throwable $e) {
    $failures[] = 'unique key rebuild: ' . $e->getMessage();
    gh122_say('[FAIL] unique key rebuild: ' . $e->getMessage());
}

// ── 3. VERIFY the outcome — never trust the step, ask the database ──────
try {
    $stillDupe = (int) db_fetch_value(
        "SELECT COUNT(*) FROM (
            SELECT COUNT(*) n FROM `{$prefix}roles`
             GROUP BY `name`, COALESCE(`org_id`, -1)
            HAVING n > 1) d");
    if ($stillDupe > 0) {
        $failures[] = "{$stillDupe} duplicate role group(s) remain";
    } else {
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
        gh122_say("[ok]  verified: {$total} role(s), no duplicates");
    }
} catch (Throwable $e) {
    $failures[] = 'verification: ' . $e->getMessage();
}

gh122_say(str_repeat('=', 62));
if ($failures) {
    gh122_say('[FAILED] ' . count($failures) . ' problem(s):');
    foreach ($failures as $f) gh122_say('   - ' . $f);
    exit(1);
}
gh122_say('Done.');
exit(0);
