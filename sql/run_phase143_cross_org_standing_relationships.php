<?php
/**
 * Phase 143 (2026-08-17) — Cross-org STANDING relationships + time-boxed
 * activation windows (GH#70 Phase 3, the final phase of the Option D build).
 *
 * Three new tables. Design: specs/phase-143-cross-org-standing-relationships/
 * {spec.md,plan.md,tasks.md}. This migration does NOT re-derive the design —
 * see plan.md's "Schema" section for the full reasoning behind every column
 * and index below; only the load-bearing facts are repeated here.
 *
 *   - `org_relationships` — the named group. `status` is DERIVED (recomputed
 *     synchronously by _org_relationship_recompute_status() after every
 *     membership change) and safe to read directly — it changes only via an
 *     explicit human action processed atomically in the same request, never
 *     by elapsed wall-clock time.
 *   - `org_relationships_members` — per-org consent row. `uk_org_rel_member`
 *     (relationship_id, org_id) does NOT need the NULL-safe generated-column
 *     technique — verified, not assumed: both columns are NOT NULL on every
 *     row this table can ever have.
 *   - `org_relationships_activations` — the time-boxed lifecycle.
 *     `live_key` is a STORED generated column that collapses the NULLable
 *     LIFECYCLE column `deactivated_at` into a uniqueness target: every LIVE
 *     row (deactivated_at IS NULL) collapses to 'live:<relationship_id>' and
 *     DOES collide with any other live row for the same relationship —
 *     enforcing "at most one live activation per relationship" as a real DB
 *     constraint, not an app-level check. Every CLOSED row (deactivated_at
 *     NOT NULL) collapses to plain SQL NULL.
 *
 *     CORRECTION vs. plan.md's original text (verified live, not assumed —
 *     this project's own Phase 129/134/141/142 discipline of confirming a
 *     generated-column technique against the real database before trusting
 *     it): plan.md described the closed-row value as 'closed:<id>' (using
 *     this table's own AUTO_INCREMENT `id` column to guarantee distinctness).
 *     MariaDB flatly refuses that — "SQLSTATE[HY000]: General error: 1901
 *     Function or expression 'AUTO_INCREMENT' cannot be used in the
 *     GENERATED ALWAYS AS clause of `id`" — a generated column may never
 *     reference an AUTO_INCREMENT column on the same table (the auto-inc
 *     value isn't assigned early enough in the write pipeline for a
 *     generated-column computation to depend on it). Fixed by using plain
 *     NULL for the closed case instead: MySQL/MariaDB treat every NULL in a
 *     UNIQUE index as mutually non-colliding — literally the Phase 129
 *     uk_user_role_scope lesson ("MySQL/MariaDB treat every NULL in a UNIQUE
 *     index as distinct") applied here as an ASSET rather than a defect.
 *     Two closed activations for the same relationship both have live_key
 *     NULL and therefore never collide with each other or with a later live
 *     one — the exact practical guarantee plan.md wanted, reached without
 *     touching the AUTO_INCREMENT column at all.
 *     `tests/test_org_relationships_schema.php` asks the live database to
 *     accept a duplicate insert to confirm this, per this project's Phase
 *     129/134/141 discipline — never trust a generated-column technique from
 *     the DDL alone.
 *
 * `access_tier` and `redaction_profile` are independently-configurable
 * ENUM('view','assist') columns — see plan.md's "Two independent axes"
 * section for why this phase deliberately does NOT collapse them into one
 * field the way Phase 141/142 did for their own single-purpose grants.
 * Neither enum includes 'full' — deliberately absent, matching every prior
 * phase's discipline in this table family.
 *
 * No FK on any of the three tables — matches this table family's existing
 * convention (organizations, org_type_routing, incident_shares, all plain
 * unenforced INT references).
 *
 * RBAC permission seeding is NOT done here — it lives in sql/rbac.sql /
 * sql/run_00_rbac.php per this project's existing convention.
 *
 * Idempotent — safe to run repeatedly. All three tables start empty; a
 * "make this true once" migration (not a "make this true for every row"
 * one), so no verify-and-exit-nonzero step is required.
 *
 * Spec: specs/phase-143-cross-org-standing-relationships/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 143 — Cross-Org Standing Relationships (schema)\n";
echo "=========================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p143_table_exists(string $t): bool {
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

// ── A. org_relationships — the named group ──────────────────────────────
if (!_p143_table_exists('org_relationships')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}org_relationships` (
                `id`                     INT AUTO_INCREMENT PRIMARY KEY,
                `name`                   VARCHAR(128) NOT NULL,
                `relationship_type`      VARCHAR(40) NOT NULL DEFAULT 'mutual_aid'
                    COMMENT 'descriptive only (mutual_aid/escalation/backup_dispatch/other) -- not
                              consumed by any authorization branch, app-layer suggested values
                              like org_type_routing.match_scope, not a DB CHECK',
                `access_tier`            ENUM('view','assist') NOT NULL DEFAULT 'view'
                    COMMENT 'write-capability CEILING -- consumed by org_can_mutate_ticket()''s
                              new relationship branch. No ''full'' value -- absent from the
                              enum entirely, same discipline Phase 141 used.',
                `redaction_profile`      ENUM('view','assist') NOT NULL DEFAULT 'view'
                    COMMENT 'read-redaction FLOOR -- independently configurable from
                              access_tier, see plan.md \"Two independent axes\". Consumed by
                              org_share_redact_ticket_fields()/org_share_redact_assignment_fields()
                              in place of access_tier for relationship-sourced context.',
                `requires_activation`    TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'default ON (time-boxed), not always-on -- default-conservative
                              posture matches this project''s general default for anything
                              standing up new cross-org visibility.',
                `max_activation_minutes` INT DEFAULT NULL
                    COMMENT 'admin-configured CEILING on any single activation''s duration.
                              NULL = no ceiling. The per-activation actual duration
                              (org_relationships_activations.max_activation_minutes) is chosen
                              by the activator and clamped to this ceiling at write time if set.',
                `status`                 ENUM('pending','active','rejected') NOT NULL DEFAULT 'pending'
                    COMMENT 'DERIVED, synchronously recomputed by
                              _org_relationship_recompute_status() immediately after every
                              membership status change. Safe to read directly at query time
                              because it changes ONLY via an explicit human action processed
                              atomically in the same request that changed it -- never by
                              elapsed wall-clock time. Contrast org_relationships_activations,
                              whose liveness DOES change purely by elapsed time and is
                              therefore NEVER read from a stored boolean.',
                `created_by`             INT NOT NULL DEFAULT 0,
                `created_by_name`        VARCHAR(128) NOT NULL DEFAULT '',
                `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_org_rel_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] Created table org_relationships\n";
    } catch (Exception $e) {
        echo "[WARN] org_relationships: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] org_relationships already exists\n";
}

// ── B. org_relationships_members — per-org consent row ──────────────────
//
// uk_org_rel_member (relationship_id, org_id) does NOT use the NULL-safe
// generated-column technique. Verified, not assumed: both relationship_id
// and org_id are NOT NULL on this table -- a member row always names a
// specific relationship and a specific org, so no NULL can ever occupy
// either half of this key. tests/test_org_relationships_schema.php asks the
// live database to accept a duplicate insert to confirm this.
if (!_p143_table_exists('org_relationships_members')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}org_relationships_members` (
                `id`                 INT AUTO_INCREMENT PRIMARY KEY,
                `relationship_id`    INT NOT NULL,
                `org_id`             INT NOT NULL,
                `status`             ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                `proposed_by`        INT NOT NULL DEFAULT 0,
                `proposed_by_name`   VARCHAR(128) NOT NULL DEFAULT '',
                `proposed_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `approved_by`        INT DEFAULT NULL,
                `approved_by_name`   VARCHAR(128) NOT NULL DEFAULT '',
                `approved_at`        DATETIME DEFAULT NULL,
                `rejected_by`        INT DEFAULT NULL,
                `rejected_by_name`   VARCHAR(128) NOT NULL DEFAULT '',
                `rejected_at`        DATETIME DEFAULT NULL,
                `rejection_reason`   VARCHAR(255) DEFAULT NULL,
                UNIQUE KEY `uk_org_rel_member` (`relationship_id`, `org_id`),
                KEY `idx_org_rel_member_org` (`org_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] Created table org_relationships_members\n";
    } catch (Exception $e) {
        echo "[WARN] org_relationships_members: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] org_relationships_members already exists\n";
}

// ── C. org_relationships_activations — the time-boxed lifecycle ─────────
//
// live_key generalizes org_type_routing.match_key / ics_form_types.org_key
// one step further: from "collapse a NULLable DISCRIMINANT" to "collapse a
// NULLable LIFECYCLE column". See this file's own top docblock and plan.md
// for the full reasoning, INCLUDING the live correction to plan.md's
// original 'closed:<id>' text (a generated column cannot reference this
// table's own AUTO_INCREMENT `id` column -- MariaDB SQLSTATE[HY000] 1901,
// confirmed live). STORED, not VIRTUAL -- it must carry the UNIQUE index.
if (!_p143_table_exists('org_relationships_activations')) {
    try {
        db_query(
            "CREATE TABLE `{$prefix}org_relationships_activations` (
                `id`                      INT AUTO_INCREMENT PRIMARY KEY,
                `relationship_id`         INT NOT NULL,
                `activated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `activated_by`            INT NOT NULL,
                `activated_by_name`       VARCHAR(128) NOT NULL DEFAULT '',
                `activation_reason`       VARCHAR(255) DEFAULT NULL,
                `max_activation_minutes`  INT DEFAULT NULL
                    COMMENT 'per-activation duration chosen by the activator, clamped at
                              write time to org_relationships.max_activation_minutes if that
                              ceiling is set. NULL = no auto-expiry for THIS activation
                              (manual deactivation only) -- only legal when the
                              relationship''s own ceiling is also NULL, enforced in
                              org_relationship_activate(), not the DB.',
                `deactivated_at`          DATETIME DEFAULT NULL
                    COMMENT 'NULL = still live (subject to the max_activation_minutes
                              window below). Set by EITHER an explicit operator
                              deactivation OR the cleanup sweep -- both write it for the
                              SAME reason, audit-trail closure. Neither path is what
                              revoked access; the read-time predicate already did.',
                `deactivated_by`          INT DEFAULT NULL,
                `deactivated_by_name`     VARCHAR(128) NOT NULL DEFAULT '',
                `deactivated_reason`      VARCHAR(255) DEFAULT NULL,
                `live_key` VARCHAR(24) AS (
                                IF(`deactivated_at` IS NULL,
                                   CONCAT('live:', `relationship_id`),
                                   NULL)
                            ) STORED
                    COMMENT 'Live rows collapse to live:<relationship_id> (collides on
                              purpose -- enforces at most one live activation per
                              relationship). Closed rows collapse to NULL (never
                              collides -- reuses Phase 129''s NULL-uniqueness lesson as
                              an asset). See sql/run_phase143_....php docblock.',
                UNIQUE KEY `uk_org_rel_activation_live` (`live_key`),
                KEY `idx_org_rel_activation_rel` (`relationship_id`, `deactivated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo "[OK] Created table org_relationships_activations\n";
    } catch (Exception $e) {
        echo "[WARN] org_relationships_activations: " . $e->getMessage() . "\n";
    }
} else {
    echo "[OK] org_relationships_activations already exists\n";
}

echo "\nDone.\n";
