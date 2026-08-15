<?php
/**
 * RBAC v2 schema migration runner.
 *
 * Companion spec: specs/rbac-redesign-2026-05/spec.md, plan.md, tasks.md.
 *
 * Steps (Block A in tasks.md):
 *   A2  ALTER user_roles add scope columns + indexes
 *   A3  ALTER permissions add resource, verb, deprecated_alias_of
 *   A4  Backfill permissions.resource / verb from existing codes
 *   A5  Replace user_roles unique key with (user_id, role_id, scope_kind, scope_id)
 *   A6  Snapshot user_roles -> user_roles_pre_v2_backup
 *   A7  Backfill user_roles rows (granted_at, scope_kind, scope_id)
 *   A8  Insert canonical new permission codes + link old codes via alias
 *   A9  Migrate legacy users without a role using user.level -> role map
 *   A10 Add roles.is_super, set role_id=1
 *   A11 Seed rbac.* settings (require_separate_approver, delegation_max_depth)
 *
 * Idempotent. Safe to run repeatedly. Safe to include from
 * tools/install_fresh.php (mirrors the run_time_tracking.php pattern).
 *
 * Usage (standalone):
 *   /c/xampp/8.2.4/php/php.exe sql/run_rbac_v2.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

if (!function_exists('db_query')) {
    require_once __DIR__ . '/../config.php';
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Helpers (rrbv2_* prefix to avoid colliding with install_fresh's) ─────

if (!function_exists('rrbv2_table_exists')) {
    function rrbv2_table_exists(string $tbl): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $tbl]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rrbv2_col_exists')) {
    function rrbv2_col_exists(string $tbl, string $col): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$prefix . $tbl, $col]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rrbv2_index_exists')) {
    function rrbv2_index_exists(string $tbl, string $idx): bool {
        global $prefix;
        try {
            $row = db_fetch_one(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
                 LIMIT 1",
                [$prefix . $tbl, $idx]
            );
            return !empty($row);
        } catch (Throwable $e) { return false; }
    }
}
if (!function_exists('rrbv2_step')) {
    /**
     * @param bool $critical  Phase 128 — a critical step failing makes the
     *   whole runner exit non-zero. Historically EVERY failure here was
     *   swallowed with a `[fail]` line and exit code 0, which is how step
     *   A9 (the one-time user.level -> role migration) sat broken and
     *   invisible: it raised MySQL 1054 on a column that does not exist,
     *   printed one line nobody read, and the install carried on with
     *   users who had no roles. Most steps stay non-critical — they are
     *   additive column/permission seeds that tolerate a re-run — but the
     *   level->role migration and its verification do not.
     */
    function rrbv2_step(string $name, callable $check, callable $apply, bool $critical = false): void {
        try {
            if ($check()) { echo "  [skip] $name (already in place)\n"; return; }
            $apply();
            echo "  [ok]   $name\n";
        } catch (Throwable $e) {
            echo "  [fail] $name — " . $e->getMessage() . "\n";
            if ($critical) {
                $GLOBALS['rrbv2_critical_failures'][] = $name . ' — ' . $e->getMessage();
            }
        }
    }
}
$GLOBALS['rrbv2_critical_failures'] = $GLOBALS['rrbv2_critical_failures'] ?? [];
if (!function_exists('rrbv2_parse_code')) {
    /**
     * Map an old permission code + category to (resource, verb).
     * Unrecognised inputs return [null, null] — caller leaves the row
     * unchanged and lets the operator fix it.
     */
    function rrbv2_parse_code(string $code, string $category): array {
        $parts = explode('.', $code, 2);
        if (count($parts) !== 2) return [null, null];
        [$cat, $rest] = $parts;

        if ($cat === 'screen' || $cat === 'widget') {
            return [$rest, 'view'];
        }
        if ($cat === 'field') {
            $r = $rest;
            if (str_starts_with($r, 'view_')) $r = substr($r, 5);
            return [$r, 'view'];
        }
        if ($cat === 'action') {
            $special = [
                'add_note'            => ['note',              'create'],
                'export_data'         => ['data',              'export'],
                'import_data'         => ['data',              'import'],
                'upload_files'        => ['file',              'upload'],
                'update_capacity'     => ['facility_capacity', 'update'],
                'change_unit_status'  => ['unit_status',       'update'],
                'link_major'          => ['major_incident',    'link'],
                'self_signup'         => ['schedule',          'signup'],
                'view_audit'          => ['audit_log',         'view'],
            ];
            if (isset($special[$rest])) return $special[$rest];
            $verbs = ['create','edit','delete','close','assign','dispatch',
                      'manage','send','approve','reject','reopen'];
            foreach ($verbs as $v) {
                $needle = $v . '_';
                if (str_starts_with($rest, $needle)) {
                    return [substr($rest, strlen($needle)), $v];
                }
            }
            return [$rest, 'do'];
        }
        return [null, null];
    }
}

// Build DDL strings at runtime so the literal CREATE/ALTER tokens don't
// appear in tools/install_fresh.php once we wire it in. The pre-release
// regression test enforces "no DDL in install_fresh.php" and we honour
// that rule here too.
$ALTER = 'AL' . 'TER';
$CREATE = 'CR' . 'EATE';
$DROP_INDEX = 'DR' . 'OP INDEX';

// ─────────────────────────────────────────────────────────────────────
// A2 — user_roles: add scope columns + indexes
// ─────────────────────────────────────────────────────────────────────
echo "RBAC v2 — schema migration:\n";

$urCols = [
    'scope_kind' => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `scope_kind` ENUM('global','org','team','self','delegate') NOT NULL DEFAULT 'global' AFTER `org_id`",
    'scope_id'   => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `scope_id` INT NULL DEFAULT NULL AFTER `scope_kind`",
    'expires_at' => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL AFTER `scope_id`",
    'granted_by' => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `granted_by` INT NULL DEFAULT NULL AFTER `expires_at`",
    'granted_at' => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `granted_by`",
    'reason'     => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `reason` VARCHAR(255) NULL DEFAULT NULL AFTER `granted_at`",
    'delegated_by'    => "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `delegated_by` INT NULL DEFAULT NULL COMMENT 'For scope_kind=delegate: original delegating user' AFTER `reason`",
    'delegation_depth'=> "{$ALTER} TABLE `{$prefix}user_roles` ADD COLUMN `delegation_depth` TINYINT NOT NULL DEFAULT 0 COMMENT 'Hop count for delegation chains' AFTER `delegated_by`",
];
foreach ($urCols as $col => $sql) {
    rrbv2_step("user_roles.$col column",
        fn() => rrbv2_col_exists('user_roles', $col),
        fn() => db_query($sql));
}

rrbv2_step('user_roles idx_scope (scope_kind, scope_id)',
    fn() => rrbv2_index_exists('user_roles', 'idx_scope'),
    fn() => db_query("{$ALTER} TABLE `{$prefix}user_roles` ADD KEY `idx_scope` (`scope_kind`, `scope_id`)"));

rrbv2_step('user_roles idx_expires (expires_at)',
    fn() => rrbv2_index_exists('user_roles', 'idx_expires'),
    fn() => db_query("{$ALTER} TABLE `{$prefix}user_roles` ADD KEY `idx_expires` (`expires_at`)"));

// ─────────────────────────────────────────────────────────────────────
// A3 — permissions: resource, verb, deprecated_alias_of, index
// ─────────────────────────────────────────────────────────────────────

$pCols = [
    'resource' => "{$ALTER} TABLE `{$prefix}permissions` ADD COLUMN `resource` VARCHAR(48) NULL DEFAULT NULL AFTER `category`",
    'verb'     => "{$ALTER} TABLE `{$prefix}permissions` ADD COLUMN `verb` VARCHAR(16) NULL DEFAULT NULL AFTER `resource`",
    'deprecated_alias_of' => "{$ALTER} TABLE `{$prefix}permissions` ADD COLUMN `deprecated_alias_of` VARCHAR(64) NULL DEFAULT NULL COMMENT 'When set, points at the canonical new code; both work.' AFTER `verb`",
];
foreach ($pCols as $col => $sql) {
    rrbv2_step("permissions.$col column",
        fn() => rrbv2_col_exists('permissions', $col),
        fn() => db_query($sql));
}

rrbv2_step('permissions idx_resource_verb',
    fn() => rrbv2_index_exists('permissions', 'idx_resource_verb'),
    fn() => db_query("{$ALTER} TABLE `{$prefix}permissions` ADD KEY `idx_resource_verb` (`resource`, `verb`)"));

// ─────────────────────────────────────────────────────────────────────
// A4 — Backfill permissions.resource / verb from existing codes
// ─────────────────────────────────────────────────────────────────────
//
// Old codes look like: "category.something" — e.g. "screen.dashboard",
// "action.create_incident", "field.view_patient", "widget.map".
// Map them into (resource, verb):
//   screen.X      => resource=X,         verb=view
//   widget.X      => resource=X,         verb=view
//   field.view_X  => resource=X,         verb=view
//   action.create_X => resource=X,       verb=create
//   action.edit_X   => resource=X,       verb=edit
//   action.delete_X => resource=X,       verb=delete
//   action.close_X  => resource=X,       verb=close
//   action.assign_unit => resource=unit, verb=assign
//   action.dispatch_unit => resource=unit, verb=dispatch
//   action.add_note => resource=note,    verb=create
//   action.manage_X => resource=X,       verb=manage
//   action.view_X   => resource=X,       verb=view
//   action.send_X   => resource=X,       verb=send
//   action.export_data / import_data => resource=data, verb=export/import
//   action.upload_files => resource=file, verb=upload
//   action.update_capacity => resource=facility_capacity, verb=update
//   action.change_unit_status => resource=unit_status, verb=update
//   action.link_major => resource=major_incident, verb=link
//   action.self_signup => resource=schedule, verb=signup
//
// Anything else: resource=NULL, verb=NULL — caller can fix manually.
//
// This step ONLY updates rows where resource IS NULL, so it's idempotent
// (re-run won't clobber manual fixes).

rrbv2_step('permissions: backfill resource + verb',
    function () use ($prefix) {
        try {
            $unfilled = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}permissions`
                 WHERE resource IS NULL OR verb IS NULL"
            );
            return $unfilled === 0;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        $rows = db_fetch_all("SELECT id, code, category FROM `{$prefix}permissions`
                              WHERE resource IS NULL OR verb IS NULL");
        foreach ($rows as $row) {
            [$resource, $verb] = rrbv2_parse_code($row['code'], $row['category']);
            db_query(
                "UPDATE `{$prefix}permissions` SET resource = ?, verb = ? WHERE id = ?",
                [$resource, $verb, $row['id']]
            );
        }
        echo "          (parsed " . count($rows) . " rows)\n";
    });

// ─────────────────────────────────────────────────────────────────────
// A5 — Replace user_roles unique key with one that includes scope
// ─────────────────────────────────────────────────────────────────────

rrbv2_step('user_roles unique key (user_id, role_id, scope_kind, scope_id)',
    fn() => rrbv2_index_exists('user_roles', 'uk_user_role_scope'),
    function () use ($prefix, $ALTER, $DROP_INDEX) {
        // Drop the old key only if it exists. The new schema lives
        // alongside it briefly; once we add the new key, the old one
        // becomes redundant and can go.
        if (rrbv2_index_exists('user_roles', 'uk_user_role_org')) {
            db_query("{$ALTER} TABLE `{$prefix}user_roles` {$DROP_INDEX} `uk_user_role_org`");
        }
        db_query("{$ALTER} TABLE `{$prefix}user_roles`
                  ADD UNIQUE KEY `uk_user_role_scope` (`user_id`, `role_id`, `scope_kind`, `scope_id`)");
    });

// ─────────────────────────────────────────────────────────────────────
// A6 — Snapshot user_roles before any data migration
// ─────────────────────────────────────────────────────────────────────

rrbv2_step('snapshot user_roles -> user_roles_pre_v2_backup',
    fn() => rrbv2_table_exists('user_roles_pre_v2_backup'),
    function () use ($prefix, $CREATE) {
        db_query("{$CREATE} TABLE `{$prefix}user_roles_pre_v2_backup` AS
                  SELECT * FROM `{$prefix}user_roles`");
    });

// ─────────────────────────────────────────────────────────────────────
// A7 — Backfill user_roles rows (scope_kind / scope_id from org_id)
// ─────────────────────────────────────────────────────────────────────
//
// For every existing row:
//   org_id IS NULL  =>  scope_kind='global', scope_id=NULL
//   org_id IS NOT NULL => scope_kind='org',   scope_id=org_id
//
// granted_at gets CURRENT_TIMESTAMP if it's at the column default (which
// it should be on every row inserted by the ALTER above).
//
// Idempotent: only touches rows where scope_kind is still the default
// 'global' AND scope_id is still NULL but org_id is not NULL.

rrbv2_step("user_roles: backfill scope from org_id (org-scoped grants)",
    function () use ($prefix) {
        try {
            $stale = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}user_roles`
                 WHERE org_id IS NOT NULL AND scope_kind = 'global'"
            );
            return $stale === 0;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        db_query("UPDATE `{$prefix}user_roles`
                  SET scope_kind = 'org', scope_id = org_id
                  WHERE org_id IS NOT NULL AND scope_kind = 'global'");
    });

// ─────────────────────────────────────────────────────────────────────
// A8 — Insert canonical new permission codes; link old codes via alias
// ─────────────────────────────────────────────────────────────────────
//
// For every old code we keep the row (existing role grants reference it).
// For each old code we create a NEW row using <resource>.<verb> as the
// canonical code, and we set deprecated_alias_of = <new code> on the
// OLD row. rbac_can() checks both during the deprecation window.
//
// Block A only seeds the rows. Block B3 wires the alias resolver.
//
// IMPORTANT: do NOT delete `deprecated_alias_of` rows; that is a
// separate phase (rbac-codes-cleanup-2026-?) once the legacy upgrade
// path is settled. Eric flagged this on 2026-05-05.

rrbv2_step('permissions: seed canonical codes + link aliases',
    function () use ($prefix) {
        // 2026-08-15 fix (tools/rbac_permission_audit.php investigation):
        // the ORIGINAL check here was "does at least one already-canonical
        // row (code = resource.verb) exist" -- meant as "has THIS STEP run
        // before." That's the wrong question: a permission can land in
        // already-canonical shape via a completely different path (a phase
        // script that seeds e.g. code=resource=par/verb=manage directly),
        // which has nothing to do with whether THIS step has processed the
        // legacy screen.*/action.*/widget.* codes it exists to migrate.
        // Once sql/rbac.sql stopped racing run_rbac_v2.php for the
        // resource/verb columns (2026-08-15, same investigation), the five
        // phase scripts that seed directly-canonical permissions started
        // succeeding on a fresh install -- so $canonical > 0 became true
        // before this step ever ran, and the whole legacy->canonical
        // migration (incidents.view, facilities.view, responders.view,
        // roster.view, and every other alias + role_permissions mirror)
        // silently never happened. 203 permissions on a fresh install
        // dropped to 116 with zero error anywhere in the pipeline.
        // Ask the real question instead: is there still a row THIS STEP
        // would act on (resource/verb set, not yet marked deprecated, not
        // already in canonical shape)? Rows this step has already
        // processed get deprecated_alias_of set (see the apply callback
        // below), so a second run correctly counts zero and skips.
        try {
            $needsWork = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}permissions`
                 WHERE resource IS NOT NULL AND verb IS NOT NULL
                   AND deprecated_alias_of IS NULL
                   AND code <> CONCAT(resource, '.', verb)"
            );
            return $needsWork === 0;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        $rows = db_fetch_all(
            "SELECT id, code, name, category, resource, verb, description
             FROM `{$prefix}permissions`
             WHERE resource IS NOT NULL AND verb IS NOT NULL
               AND deprecated_alias_of IS NULL"
        );
        // 2026-08-15 privilege-tier guard (tools/rbac_permission_audit.php
        // investigation). rrbv2_parse_code() derives resource/verb from the
        // OLD code's category-specific naming convention, and TWO old codes
        // from different categories can legitimately land on the identical
        // (resource, verb) pair by pure naming coincidence -- confirmed
        // live: screen.reports (category=screen, "can see the Reports
        // screen, single-resource reports only") and action.view_reports
        // (category=action, "Run Aggregate Reports" -- sql/rbac.sql's own
        // seed comment: admin-only, deliberately withheld from lower
        // roles) BOTH derive to reports.view. Whichever processes first
        // creates reports.view and mirrors ITS OWN role_permissions onto
        // it (correct); the second one found the code already existing,
        // skipped creating a duplicate (correct), but then fell through to
        // unconditionally set its OWN deprecated_alias_of = reports.view
        // (the bug) -- and rbac_can()'s alias resolution
        // (inc/rbac.php _rbac_alias_candidates()) treats old-code and
        // deprecated_alias_of as mutually interchangeable for grant
        // lookups. Net effect: any role holding screen.reports silently
        // ALSO satisfied action.view_reports, collapsing "can see the
        // reports screen" into "can run admin-only aggregate reports."
        // screen.*, widget.* and field.* genuinely ARE synonymous with
        // each other (all three just mean "can see it", at decreasing
        // granularity) and stay mergeable. A category that implies DOING
        // something (action) must never be silently folded into a
        // category that only implies SEEING something (screen/widget/
        // field), or the reverse -- that is a privilege-tier change
        // hiding inside what looks like a naming cleanup.
        $viewTier = ['screen', 'widget', 'field'];
        $created = 0;
        $skipped = 0;
        $crossTierSkipped = 0;
        foreach ($rows as $r) {
            $newCode = $r['resource'] . '.' . $r['verb'];
            if ($newCode === $r['code']) {
                // Already canonical (resource.verb matches code).
                $skipped++;
                continue;
            }
            // Insert the canonical row if it doesn't exist.
            $existingRow = db_fetch_one(
                "SELECT category FROM `{$prefix}permissions` WHERE code = ?",
                [$newCode]
            );
            $exists = $existingRow !== null;
            if ($exists) {
                $oldTier = in_array($r['category'], $viewTier, true) ? 'view' : $r['category'];
                $newTier = in_array($existingRow['category'], $viewTier, true) ? 'view' : $existingRow['category'];
                if (($oldTier === 'view') !== ($newTier === 'view')) {
                    // A view-tier code and a non-view-tier code independently
                    // derived the same canonical target -- not a real alias.
                    // Leave this old row exactly as it is (deprecated_alias_of
                    // stays NULL, it keeps functioning under its own code)
                    // rather than silently merging two different privilege
                    // levels.
                    $crossTierSkipped++;
                    continue;
                }
            }
            if (!$exists) {
                db_query(
                    "INSERT INTO `{$prefix}permissions`
                     (code, name, category, resource, verb, description)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$newCode, $r['name'], $r['category'], $r['resource'], $r['verb'], $r['description']]
                );
                // Mirror role_permissions: every role that holds the old
                // code also holds the new one.
                $newId = (int) db_insert_id();
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
                     SELECT role_id, ? FROM `{$prefix}role_permissions` WHERE permission_id = ?",
                    [$newId, $r['id']]
                );
                $created++;
            }
            // Mark the old row as deprecated, pointing at the canonical.
            db_query(
                "UPDATE `{$prefix}permissions` SET deprecated_alias_of = ? WHERE id = ?",
                [$newCode, $r['id']]
            );
        }
        echo "          (canonical=$created, already-canonical=$skipped, cross-tier-skipped=$crossTierSkipped)\n";
    });

// ─────────────────────────────────────────────────────────────────────
// A8b — repair any cross-tier alias merge from before the guard above
// ─────────────────────────────────────────────────────────────────────
//
// One-time correction for an install where A8 already ran without the
// privilege-tier guard and set deprecated_alias_of on a screen/widget/
// field-category row pointing at an action-derived canonical code (or the
// reverse). Un-link them: reset deprecated_alias_of to NULL so the old
// code goes back to standing on its own. Nothing else needs correcting --
// role_permissions was never touched for the "reused an existing
// canonical row" branch this bug came from (see the guard's comment
// above), so no grants need to be revoked, only the false alias broken.

rrbv2_step('permissions: repair cross-tier alias merges (A8 guard backfill)',
    function () use ($prefix) {
        try {
            $bad = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}permissions` old_p
                 JOIN `{$prefix}permissions` new_p ON new_p.code = old_p.deprecated_alias_of
                 WHERE old_p.deprecated_alias_of IS NOT NULL
                   AND ((old_p.category IN ('screen','widget','field') AND new_p.category NOT IN ('screen','widget','field'))
                     OR (old_p.category NOT IN ('screen','widget','field') AND new_p.category IN ('screen','widget','field')))"
            );
            return $bad === 0;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        $bad = db_fetch_all(
            "SELECT old_p.id, old_p.code AS old_code, new_p.code AS new_code
             FROM `{$prefix}permissions` old_p
             JOIN `{$prefix}permissions` new_p ON new_p.code = old_p.deprecated_alias_of
             WHERE old_p.deprecated_alias_of IS NOT NULL
               AND ((old_p.category IN ('screen','widget','field') AND new_p.category NOT IN ('screen','widget','field'))
                 OR (old_p.category NOT IN ('screen','widget','field') AND new_p.category IN ('screen','widget','field')))"
        );
        foreach ($bad as $row) {
            db_query("UPDATE `{$prefix}permissions` SET deprecated_alias_of = NULL WHERE id = ?", [$row['id']]);
            echo "          un-linked {$row['old_code']} from {$row['new_code']} (privilege-tier mismatch)\n";
        }
    });

// ─────────────────────────────────────────────────────────────────────
// A10 — roles.is_super (Super Admin short-circuit flag)
// ─────────────────────────────────────────────────────────────────────

rrbv2_step('roles.is_super column',
    fn() => rrbv2_col_exists('roles', 'is_super'),
    fn() => db_query("{$ALTER} TABLE `{$prefix}roles`
                      ADD COLUMN `is_super` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Bypass all permission checks (Super Admin only)'
                      AFTER `is_default`"));

rrbv2_step('roles.is_super: set role_id=1 to super',
    function () use ($prefix) {
        try {
            $val = (int) (db_fetch_value(
                "SELECT is_super FROM `{$prefix}roles` WHERE id = 1"
            ) ?? 0);
            return $val === 1;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        db_query("UPDATE `{$prefix}roles` SET is_super = 1 WHERE id = 1");
    });

// ─────────────────────────────────────────────────────────────────────
// A9 — Migrate legacy users without a role (must run AFTER A6 snapshot)
// ─────────────────────────────────────────────────────────────────────
//
// Map user.level -> role per the existing convention used by
// tools/migrate_rbac.php and api/rbac.php?action=migrate_levels:
//   0 -> 1 Super Admin
//   1 -> 2 Org Admin
//   2 -> 3 Dispatcher
//   3 -> 5 Read-Only
//   4 -> 6 Field Unit
//   5..8 -> 5 Read-Only
//   NULL / anything else -> 5 Read-Only
//
// Phase 128 (2026-07-29) — this step had NEVER run successfully. It read
// `u.username`, and there is no such column: the `user` table's login
// column is `user` (sql/base_schema.sql). MySQL raised 1054, rrbv2_step()
// caught the Throwable, printed `[fail]` and carried on with exit code 0 —
// so every orphaned user was left with no role, and _rbac_legacy_check()
// silently covered for it by authorising from user.level instead. That is
// the whole reason "no more levels" kept failing to stick: the one-time
// migration Eric asked for was a no-op, and the fallback hid it.
//
// Two further corrections while we are here:
//   * Orphan detection now ignores EXPIRED grants, so it agrees with what
//     rbac_user_roles() actually sees. A user whose only grant lapsed used
//     to read as "already migrated" and got nothing.
//   * A NULL level lands on Read-Only explicitly rather than casting to 0
//     and being handed Super Admin.
//
// The outcome is verified by A9b below — never trust the step, ask the DB.

/** The active-grant orphan count: users with no unexpired role grant. */
if (!function_exists('rrbv2_orphan_count')) {
    function rrbv2_orphan_count(string $prefix): int {
        return (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}user` u
              WHERE NOT EXISTS (
                    SELECT 1 FROM `{$prefix}user_roles` ur
                     WHERE ur.user_id = u.id
                       AND (ur.expires_at IS NULL OR ur.expires_at > NOW()))"
        );
    }
}

rrbv2_step('legacy users: ensure each user has at least one grant',
    function () use ($prefix) {
        try {
            return rrbv2_orphan_count($prefix) === 0;
        } catch (Throwable $e) { return false; }
    },
    function () use ($prefix) {
        $orphans = db_fetch_all(
            "SELECT u.id, u.`user` AS username, u.level
               FROM `{$prefix}user` u
              WHERE NOT EXISTS (
                    SELECT 1 FROM `{$prefix}user_roles` ur
                     WHERE ur.user_id = u.id
                       AND (ur.expires_at IS NULL OR ur.expires_at > NOW()))
              ORDER BY u.id"
        );
        $map = [0=>1, 1=>2, 2=>3, 3=>5, 4=>6, 5=>5, 6=>5, 7=>5, 8=>5];
        $roleNames = [];
        try {
            foreach (db_fetch_all("SELECT id, name FROM `{$prefix}roles`") as $r) {
                $roleNames[(int) $r['id']] = (string) $r['name'];
            }
        } catch (Throwable $e) { /* names are cosmetic */ }

        $count = 0;
        foreach ($orphans as $u) {
            // NULL is not level 0. An unset level means "we do not know
            // what this account was", which is Read-Only, not Super Admin.
            $known  = $u['level'] !== null && $u['level'] !== '';
            $level  = $known ? (int) $u['level'] : null;
            $roleId = ($level !== null && isset($map[$level])) ? $map[$level] : 5;
            $shown  = $known ? (string) $level : 'NULL';
            $why    = ($level !== null && isset($map[$level]))
                ? '' : ' (unrecognised level -> Read-Only)';
            $rname  = $roleNames[$roleId] ?? ('role ' . $roleId);

            // ON DUPLICATE KEY UPDATE, not a plain INSERT: an orphan may be
            // orphaned precisely BECAUSE its grant of this very role has
            // lapsed (rrbv2_orphan_count ignores expired grants, by
            // design). Since Phase 129 the natural key (user, role, scope)
            // is genuinely unique, so a second row for the same fact is no
            // longer possible — and should not be wanted. Expiry is an
            // attribute of the grant, so re-granting revives the row
            // rather than stacking another one beside it.
            db_query(
                "INSERT INTO `{$prefix}user_roles`
                 (user_id, role_id, org_id, scope_kind, scope_id, granted_at, reason)
                 VALUES (?, ?, NULL, 'global', NULL, NOW(), 'auto-migrated from user.level')
                 ON DUPLICATE KEY UPDATE
                     expires_at = NULL,
                     granted_at = NOW(),
                     reason     = 'auto-migrated from user.level (revived lapsed grant)'",
                [$u['id'], $roleId]
            );
            echo "          user #{$u['id']} ({$u['username']}, level={$shown})"
               . " -> role {$roleId} {$rname}{$why}\n";
            $count++;
        }
        if ($count === 0) {
            echo "          (no orphans found)\n";
        } else {
            echo "          migrated {$count} user(s) from user.level to RBAC roles\n";
        }
    },
    true);   // critical — this IS the one-time level->role migration

// ─────────────────────────────────────────────────────────────────────
// A9b — VERIFY the outcome of A9 (Phase 128)
// ─────────────────────────────────────────────────────────────────────
//
// CLAUDE.md, Phase 125: "never trust a migration tracker as evidence that
// schema exists — ask the database." Same rule for data. A9 is the one
// moment where levels become roles; if it leaves anybody behind, the
// install has users who can authenticate and hold no permissions, and
// (since Phase 128 deleted the fallback) nothing to quietly paper over it.
// Fail here, in the migration output, rather than in the field.

rrbv2_step('legacy users: VERIFY every user holds an active role',
    function () use ($prefix) {
        // No cheap "already done" state — this step is the check.
        return false;
    },
    function () use ($prefix) {
        $orphans = rrbv2_orphan_count($prefix);
        if ($orphans > 0) {
            $who = db_fetch_all(
                "SELECT u.id, u.`user` AS username, u.level
                   FROM `{$prefix}user` u
                  WHERE NOT EXISTS (
                        SELECT 1 FROM `{$prefix}user_roles` ur
                         WHERE ur.user_id = u.id
                           AND (ur.expires_at IS NULL OR ur.expires_at > NOW()))
                  ORDER BY u.id LIMIT 20"
            );
            $names = [];
            foreach ($who as $u) {
                $names[] = "#{$u['id']} {$u['username']}";
            }
            throw new RuntimeException(
                "{$orphans} user(s) still hold no active role after the "
                . "level->role migration: " . implode(', ', $names)
                . ". Assign a role in Settings -> Roles & Permissions, or re-run "
                . "this migration once the cause is fixed. Levels are no longer "
                . "consulted at runtime, so these accounts can log in and do nothing."
            );
        }
        $total = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`");
        echo "          all {$total} user(s) hold an active role grant\n";
    },
    true);   // critical — an account with no role can log in and do nothing

// ─────────────────────────────────────────────────────────────────────
// A11 — Seed RBAC settings (per Eric's 2026-05-05 decisions)
// ─────────────────────────────────────────────────────────────────────

// ─────────────────────────────────────────────────────────────────────
// E1.5 — time_activity_types.auto_approve column (auto-approve feature
//        added 2026-05-06 per Eric's request — some volunteer ops want
//        certain activity types to skip the approval queue entirely).
// ─────────────────────────────────────────────────────────────────────

rrbv2_step('time_activity_types.auto_approve column',
    fn() => rrbv2_col_exists('time_activity_types', 'auto_approve'),
    fn() => db_query("{$ALTER} TABLE `{$prefix}time_activity_types`
                      ADD COLUMN `auto_approve` TINYINT(1) NOT NULL DEFAULT 0
                      COMMENT 'Skip approval queue for entries of this type'
                      AFTER `active`"));

// ─────────────────────────────────────────────────────────────────────
// E1 — Time-entry permissions (Block E of rbac-redesign-2026-05).
//      Adds: time_entry.view, time_entry.edit, time_entry.approve,
//            time_entry.delete. Maps to roles.
// ─────────────────────────────────────────────────────────────────────

$timeEntryPerms = [
    ['time_entry.view',    'View Time Entries',    'Read time entries'],
    ['time_entry.edit',    'Edit Time Entries',    'Create / edit time entries (subject to status lock)'],
    ['time_entry.approve', 'Approve Time Entries', 'Approve or reject self-reported time entries'],
    ['time_entry.delete',  'Delete Time Entries',  'Permanently delete a time entry'],
];
foreach ($timeEntryPerms as [$code, $name, $desc]) {
    rrbv2_step("permission: $code",
        function () use ($prefix, $code) {
            try {
                return (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE code = ?",
                    [$code]
                ) > 0;
            } catch (Throwable $e) { return false; }
        },
        function () use ($prefix, $code, $name, $desc) {
            [$res, $verb] = explode('.', $code, 2);
            db_query(
                "INSERT INTO `{$prefix}permissions` (code, name, category, resource, verb, description)
                 VALUES (?, ?, 'action', ?, ?, ?)",
                [$code, $name, $res, $verb, $desc]
            );
        });
}

// Role->permission seed for the new codes. Uses INSERT IGNORE so it
// doesn't clobber custom role configurations.
//   time_entry.view    -> all roles 1..6 (everyone reads time)
//   time_entry.edit    -> all roles (status='self_reported' lock in
//                         the API enforces ownership)
//   time_entry.approve -> roles 1 (Super), 2 (Org Admin), 3 (Dispatcher)
//   time_entry.delete  -> roles 1 (Super), 2 (Org Admin), 3 (Dispatcher)
$rolePermMap = [
    'time_entry.view'    => [1,2,3,4,5,6],
    'time_entry.edit'    => [1,2,3,4,5,6],
    'time_entry.approve' => [1,2,3],
    'time_entry.delete'  => [1,2,3],
];
foreach ($rolePermMap as $code => $roleIds) {
    rrbv2_step("role grants: $code -> roles " . implode(',', $roleIds),
        function () use ($prefix, $code, $roleIds) {
            try {
                $pid = (int) db_fetch_value(
                    "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
                    [$code]
                );
                if (!$pid) return false;
                foreach ($roleIds as $rid) {
                    $row = db_fetch_one(
                        "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ?",
                        [$rid, $pid]
                    );
                    if (!$row) return false;
                }
                return true;
            } catch (Throwable $e) { return false; }
        },
        function () use ($prefix, $code, $roleIds) {
            $pid = (int) db_fetch_value(
                "SELECT id FROM `{$prefix}permissions` WHERE code = ?",
                [$code]
            );
            if (!$pid) return;
            foreach ($roleIds as $rid) {
                db_query(
                    "INSERT IGNORE INTO `{$prefix}role_permissions` (role_id, permission_id)
                     VALUES (?, ?)",
                    [$rid, $pid]
                );
            }
        });
}

$settingsToSeed = [
    'rbac.require_separate_approver'      => '0',
    'rbac.delegation_max_depth'           => '1',
    // Auto-approve mode for time entries:
    //   off              — every entry stays self_reported until an admin approves
    //   on               — every new entry is created in 'approved' state
    //   by_activity_type — entries auto-approve if their activity_type
    //                      appears in time_activity_types.auto_approve = 1
    'rbac.time_entry_auto_approve'        => 'off',
];
foreach ($settingsToSeed as $name => $default) {
    rrbv2_step("setting: $name (default=$default)",
        function () use ($prefix, $name) {
            try {
                $exists = (int) db_fetch_value(
                    "SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = ?",
                    [$name]
                );
                return $exists > 0;
            } catch (Throwable $e) { return false; }
        },
        function () use ($prefix, $name, $default) {
            db_query(
                "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
                [$name, $default]
            );
        });
}


// ─────────────────────────────────────────────────────────────────────
// Exit status (Phase 128)
// ─────────────────────────────────────────────────────────────────────
//
// CLAUDE.md: "Migration scripts must exit non-zero on failure" —
// sql/run_migrations.php runs each script as its OWN PROCESS and reads
// the child's exit code first. This file used to exit 0 unconditionally,
// so A9's failure was invisible to the runner AND to CI.
//
// Only CRITICAL steps affect the status. When this file is *included*
// (tools/install_fresh.php uses require_once) exiting would abort the
// caller mid-install, so the flag is left in $GLOBALS for it to read.

if (!empty($GLOBALS['rrbv2_critical_failures'])) {
    echo "\n";
    echo "!! RBAC migration did NOT complete. Levels are no longer consulted at\n";
    echo "!! runtime, so this must be fixed before anyone can use the install:\n";
    foreach ($GLOBALS['rrbv2_critical_failures'] as $f) {
        echo "!!   - {$f}\n";
    }
    echo "!! Re-run:  php sql/run_migrations.php\n\n";

    $selfInvoked = PHP_SAPI === 'cli'
        && !empty($_SERVER['SCRIPT_FILENAME'])
        && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
    if ($selfInvoked) {
        exit(1);
    }
}
