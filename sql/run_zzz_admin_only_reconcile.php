<?php
/**
 * Found while investigating a GH#115 CI failure (2026-08-26) -- a THIRD,
 * separate, pre-existing bug in the admin_only classification pipeline,
 * unrelated to GH#115's own change but caught by the same "does this
 * actually work on a genuinely fresh install" scrutiny that produced it.
 *
 * sql/rbac.sql's own admin_only classification UPDATE statements (added
 * 2026-08-22) run as part of the FOUNDATIONAL .sql import list --
 * tools/install_fresh.php imports rbac.sql BEFORE any sql/run_*.php
 * migration ever executes. That's fine for permission codes rbac.sql
 * itself creates, but three of the codes in its own tier lists --
 * console.design and action.intercom_unlock (created by
 * sql/run_phase114a_channel_registry.php) and action.manage_matrix
 * (created by sql/run_phase114c_comm_routes.php) -- don't exist as rows
 * yet at the point rbac.sql's UPDATE runs, on a genuinely fresh install.
 * An UPDATE against a WHERE clause matching zero rows is not an error --
 * it just silently does nothing, so rbac.sql's own admin_only tiering
 * for these three codes was a complete no-op every time, forever, on
 * every fresh install. sql/run_00_rbac.php carries an identical copy of
 * the same UPDATE statements (this file's own sibling fix, landed
 * earlier the same day) and has the exact same blind spot: it sorts
 * ("run_00_rbac.php") well before both Phase 114 migrations
 * alphabetically, so it never sees these rows either.
 *
 * Confirmed live, not guessed: a CI diagnostic step queried
 * admin_only for these three codes immediately after "Fresh install"
 * completed (0 failures reported) and found all three at the column's
 * DEFAULT 0; manually re-running rbac.sql's import a SECOND time
 * (idempotent -- CREATE TABLE IF NOT EXISTS + INSERT IGNORE are no-ops
 * by then, only the UPDATE statements do anything) immediately fixed
 * all three to the correct value, proving the rows existed by then and
 * the UPDATE's own logic is correct -- it was purely a timing gap.
 *
 * This migration is the general fix, not a special case for today's
 * three codes: it re-applies the SAME classification UPDATE + canonical-
 * alias propagation that sql/rbac.sql and sql/run_00_rbac.php both
 * already carry, but from a file whose name is deliberately chosen to
 * sort LAST among every sql/run_*.php script (ksort() order), so it always
 * runs after every permission-creating migration has had its turn --
 * regardless of which future migration adds the next admin-only-tier
 * code. Idempotent: setting an already-correct value again is a no-op.
 *
 * Usage: php sql/run_zzz_admin_only_reconcile.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $hasAdminOnlyCol = (bool) db_fetch_value(
        "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'admin_only'",
        [$prefix . 'permissions']
    );
    if (!$hasAdminOnlyCol) {
        echo "[SKIP] {$prefix}permissions.admin_only does not exist on this install -- nothing to reconcile\n";
        exit(0);
    }

    // Keep this list identical to sql/rbac.sql's and sql/run_00_rbac.php's
    // own tier lists -- see inc/rbac_admin_only.php for the authoritative
    // model this mirrors.
    $tier2 = db_query("UPDATE `{$prefix}permissions` SET admin_only = 2 WHERE code IN (
        'action.manage_config', 'action.manage_roles', 'action.bulk_delete_members',
        'action.manage_audit_retention', 'action.manage_dispositions',
        'action.manage_public_board', 'action.manage_ics_form_types',
        'action.manage_org_routing', 'action.manage_org_routing_org',
        'action.manage_org_relationships'
    ) AND admin_only < 2")->rowCount();

    $tier1 = db_query("UPDATE `{$prefix}permissions` SET admin_only = 1 WHERE code IN (
        'action.manage_users', 'action.delete_incident', 'action.import_data',
        'console.design', 'action.intercom_unlock', 'action.view_reports',
        'action.delete_ics_form', 'action.delete_equipment_log',
        'action.manage_public_board_org', 'action.manage_ics_form_types_org',
        'action.manage_matrix', 'action.manage_calls'
    ) AND admin_only < 1")->rowCount();

    $alias1 = db_query("UPDATE `{$prefix}permissions` canon
                JOIN `{$prefix}permissions` old_p ON old_p.deprecated_alias_of = canon.code
                 SET canon.admin_only = old_p.admin_only
              WHERE old_p.admin_only > canon.admin_only")->rowCount();
    $alias2 = db_query("UPDATE `{$prefix}permissions` old_p
                JOIN `{$prefix}permissions` canon ON canon.code = old_p.deprecated_alias_of
                 SET old_p.admin_only = canon.admin_only
              WHERE canon.admin_only > old_p.admin_only")->rowCount();

    echo "[OK] admin_only reconciled: {$tier2} tier-2, {$tier1} tier-1, "
        . ($alias1 + $alias2) . " canonical-alias propagation(s)\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
