<?php
/**
 * Phase 142 (2026-08-17) — Cross-org manual ticket sharing + live push.
 *
 * Adds five columns to the existing `incident_shares` table (no new table).
 * Design: specs/phase-142-cross-org-manual-sharing/{spec.md,plan.md,tasks.md}.
 *
 * Correction to spec.md, verified against both the live database
 * (`SHOW COLUMNS incident_shares`) and Phase 141's shipped migration
 * (sql/run_phase141_cross_org_ticket_sharing.php) before writing this file:
 * spec.md's "In scope" bullet describes `revoked_by` as "already present in
 * the Phase 141 schema, unused until now." It is NOT — Phase 141 added only
 * `revoked_at` and `revoked_reason`. This migration is what actually adds
 * `revoked_by` (plan.md's "Correction to spec.md").
 *
 * None of the five new columns participate in `uk_incident_share
 * (ticket_id, shared_with_org_id)` — that key's own NULL-safety was already
 * verified in Phase 141 for its existing two columns, both NOT NULL,
 * unaffected by this ALTER. Plain `ADD COLUMN`, no generated-column
 * technique needed here.
 *
 * RBAC permission seeding is NOT done here — it lives in sql/rbac.sql /
 * sql/run_00_rbac.php, unchanged convention from Phase 141.
 *
 * Idempotent — each ADD COLUMN is gated individually so a partial prior
 * run (or a re-run) does not error. Five nullable/defaulted column adds on
 * an existing, empty-by-default feature table — a "make this true once"
 * migration, not a "make this true for every row" migration, so no
 * verify-and-exit-nonzero step is required.
 *
 * Spec: specs/phase-142-cross-org-manual-sharing/{spec.md,plan.md,tasks.md}
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Phase 142 — Cross-Org Manual Sharing (schema)\n";
echo "===============================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function _p142_col_exists(string $t, string $c): bool {
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

$columns = [
    'created_by' => "ADD COLUMN `created_by` INT DEFAULT NULL
        COMMENT 'Phase 142 -- user who manually created this share. NULL for
                  Phase 141 auto-routed shares (routing_rule_id is set
                  instead) and for any row created before this column
                  existed.' AFTER `created_at`",
    'created_by_name' => "ADD COLUMN `created_by_name` VARCHAR(128) NOT NULL DEFAULT ''
        COMMENT 'denormalized actor name, same convention as
                  org_type_routing.created_by_name -- survives user
                  deletion' AFTER `created_by`",
    'share_reason' => "ADD COLUMN `share_reason` VARCHAR(255) DEFAULT NULL
        COMMENT 'human-entered reason captured at manual-share creation time
                  (spec.md user story 1). NULL for auto-routed shares.'
        AFTER `created_by_name`",
    'revoked_by' => "ADD COLUMN `revoked_by` INT DEFAULT NULL
        COMMENT 'Phase 142 -- user who revoked this share. NULL while the
                  share is active (revoked_at also NULL). spec.md assumed
                  this column already existed from Phase 141 -- verified it
                  does not (see this migration file docblock); this ALTER
                  is what actually adds it.' AFTER `revoked_reason`",
    'revoked_by_name' => "ADD COLUMN `revoked_by_name` VARCHAR(128) NOT NULL DEFAULT ''
        COMMENT 'denormalized revoker name' AFTER `revoked_by`",
];

if (!_p142_col_exists('incident_shares', 'id')) {
    echo "[WARN] incident_shares table does not exist yet -- run sql/run_phase141_cross_org_ticket_sharing.php first.\n";
} else {
    foreach ($columns as $col => $ddl) {
        if (_p142_col_exists('incident_shares', $col)) {
            echo "[OK] incident_shares.{$col} already exists\n";
            continue;
        }
        try {
            db_query("ALTER TABLE `{$prefix}incident_shares` {$ddl}");
            echo "[OK] Added incident_shares.{$col}\n";
        } catch (Exception $e) {
            echo "[WARN] incident_shares.{$col}: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone.\n";
