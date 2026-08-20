<?php
/**
 * Phase 141 (2026-08-17) — Cross-org ticket sharing (auto-routing core).
 *
 * GitHub issue #70: an org wants incidents of a given type (or a whole
 * group of types) automatically visible to a partner org, without any
 * per-ticket manual action. Design: specs/phase-141-cross-org-ticket-sharing/
 * {spec.md,plan.md,tasks.md} (Option D, "Tiered Sharing", from a 4-option
 * design-synthesis comparison).
 *
 * Schema:
 *   - `org_type_routing` — the admin-configured rule: which owning org
 *     routes tickets of a given incident type/group TO which target org,
 *     at what access tier. `match_key` is a STORED generated column that
 *     collapses the discriminated union (match_scope='type' -> t:<id>,
 *     match_scope='group' -> g:<group>) into one NULL-safe value so the
 *     uniqueness constraint on (owning_org_id, shared_with_org_id,
 *     match_key) actually binds — same technique as Phase 129's
 *     uk_user_role_scope / Phase 140's org_key, generalized here from a
 *     NULL-collapse to a discriminated-union collapse.
 *   - `incident_shares` — the per-ticket grant an active routing rule
 *     produces at ticket-creation time. `uk_incident_share (ticket_id,
 *     shared_with_org_id)` does NOT need the NULL-safe generated-column
 *     technique: both columns are NOT NULL on every row a share can ever
 *     have (a share always names a specific ticket and a specific target
 *     org), verified here rather than assumed, per this project's Phase
 *     134 discipline of confirming nullability before trusting a plain
 *     UNIQUE key.
 *
 * `access_tier ENUM('view','assist')` only — 'full' is deliberately absent
 * from the enum in Phase 1 (not just unused), so no code path can ever
 * accidentally treat a Phase-1 row as ownership-capable. Adding 'full' is
 * a Phase-3-scoped schema change.
 *
 * No FK on either table — matches this table family's existing convention
 * (organizations, member_organizations, ics_form_types all use plain
 * unenforced INT references).
 *
 * RBAC permission seeding is NOT done here — it lives in sql/rbac.sql /
 * sql/run_00_rbac.php per this project's existing RBAC convention, not
 * duplicated into a phase migration script.
 *
 * Idempotent — safe to run repeatedly. Both tables start empty; this is a
 * "make this true once" migration (Phase 140's simpler ending), not a
 * "make this true for every row" migration, so no verify-and-exit-nonzero
 * step is required.
 *
 * Spec: specs/phase-141-cross-org-ticket-sharing/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 141 — Cross-Org Ticket Sharing (schema)\n";
echo "===============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p141_table_exists(string $t): bool {
    global $prefix;
    try {
        $r = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $t]
        );
        return !empty($r);
    } catch (Exception $e) { return false; }
}

function _p141_col_exists(string $t, string $c): bool {
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

// ── A. org_type_routing — the admin-configured rule ─────────────────────
if (!_p141_table_exists('org_type_routing')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}org_type_routing` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `owning_org_id`      INT NOT NULL COMMENT 'the org this rule routes FROM -- matches ticket.org_id exactly, no descendant tree-walk in Phase 1',
                `shared_with_org_id` INT NOT NULL COMMENT 'the org this rule grants visibility TO',
                `match_scope`        ENUM('group','type') NOT NULL DEFAULT 'group',
                `match_group`        VARCHAR(20) DEFAULT NULL COMMENT 'set iff match_scope=group; matches in_types.group verbatim (same VARCHAR(20) width as in_types.group)',
                `match_in_type_id`   INT DEFAULT NULL COMMENT 'set iff match_scope=type; matches in_types.id (same width as ticket.in_types_id, int(4))',
                `match_key`          VARCHAR(24) AS (
                                          CASE WHEN `match_scope` = 'type'
                                               THEN CONCAT('t:', COALESCE(`match_in_type_id`, -1))
                                               ELSE CONCAT('g:', COALESCE(`match_group`, ''))
                                          END
                                      ) STORED COMMENT 'NULL-safe collapsed match target for the uniqueness constraint -- same technique as Phase 129 uk_user_role_scope / Phase 140 org_key, generalized from NULL-collapse to discriminated-union-collapse',
                `access_tier`        ENUM('view','assist') NOT NULL DEFAULT 'view',
                `active`             TINYINT(1) NOT NULL DEFAULT 1,
                `created_by`         INT NOT NULL DEFAULT 0,
                `created_by_name`    VARCHAR(128) NOT NULL DEFAULT '',
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deactivated_at`     DATETIME DEFAULT NULL,
                `deactivated_by`     INT DEFAULT NULL,
                UNIQUE KEY `uk_org_routing_rule` (`owning_org_id`, `shared_with_org_id`, `match_key`),
                KEY `idx_org_routing_owner`  (`owning_org_id`, `active`),
                KEY `idx_org_routing_shared` (`shared_with_org_id`, `active`),
                KEY `idx_org_routing_group`  (`match_group`),
                KEY `idx_org_routing_type`   (`match_in_type_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] Created table org_type_routing\n";
    } catch (Exception $e) {
        echo "[WARN] org_type_routing: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] org_type_routing already exists\n";
}

// ── B. incident_shares — the per-ticket grant ────────────────────────────
//
// uk_incident_share (ticket_id, shared_with_org_id) does NOT use the
// NULL-safe generated-column technique used above for org_type_routing.
// Verified, not assumed: both ticket_id and shared_with_org_id are NOT
// NULL on this table -- a share row always names a specific ticket and a
// specific target org, so no NULL can ever occupy either half of this key
// and defeat the constraint the way Phase 129's uk_user_role_scope was
// defeated by a NULLable org_id/scope_id. tests/test_org_sharing_schema.php
// asks the live database to accept a duplicate insert to confirm this,
// rather than trusting the DDL alone.
if (!_p141_table_exists('incident_shares')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}incident_shares` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `ticket_id`          BIGINT(8) NOT NULL COMMENT 'FK-by-convention to ticket.id -- no FK, matches this table family',
                `shared_with_org_id` INT NOT NULL,
                `owning_org_id`      INT NOT NULL COMMENT 'denormalized snapshot of ticket.org_id at share-creation time -- defensive: nothing in this codebase can change ticket.org_id today, but this stays correct even if a future phase adds a path that can',
                `routing_rule_id`    INT DEFAULT NULL COMMENT 'the org_type_routing.id that produced this share -- not FK-enforced, but always resolvable in Phase 1 since rules are only deactivated, never hard-deleted',
                `access_tier`        ENUM('view','assist') NOT NULL DEFAULT 'view' COMMENT 'copied from the rule AT CREATION TIME -- a later edit to the rule''s access_tier does NOT retroactively change already-shared tickets',
                `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `revoked_at`         DATETIME DEFAULT NULL COMMENT 'NULL = active share. Phase 1 code NEVER sets this column -- no UI, no code path writes it. Present now so Phase 2 ad hoc unshare and Phase 3 activation-window expiry are additive, not a schema change.',
                `revoked_reason`     VARCHAR(255) DEFAULT NULL,
                UNIQUE KEY `uk_incident_share` (`ticket_id`, `shared_with_org_id`),
                KEY `idx_incident_share_ticket` (`ticket_id`),
                KEY `idx_incident_share_org`    (`shared_with_org_id`, `revoked_at`),
                KEY `idx_incident_share_rule`   (`routing_rule_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] Created table incident_shares\n";
    } catch (Exception $e) {
        echo "[WARN] incident_shares: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] incident_shares already exists\n";
}

echo "\nDone.\n";
