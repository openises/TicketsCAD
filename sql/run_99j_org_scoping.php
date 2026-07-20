<?php
/**
 * Phase 99j-1 (2026-06-29) — Org scoping foundation: schema only.
 *
 *   - organizations.parent_org_id INT NULL (hierarchical orgs)
 *   - user.home_org_id INT NULL (with backfill to organization 1)
 *
 * No endpoint wiring in this phase — the helpers in inc/org-scope.php
 * are available but only called when subsequent phases (99j-2 onward)
 * start filtering each list endpoint.
 *
 * Idempotent. Run with: php sql/run_99j_org_scoping.php
 *
 * Origin: Billy Irwin's 2026-06-29 beta email. See full design in
 * specs/phase-99j-org-scoping/spec.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

$colExists = function (string $table, string $column) use ($prefix): bool {
    $n = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?",
        [$prefix . $table, $column]
    );
    return (int) $n > 0;
};

$indexExists = function (string $table, string $indexName) use ($prefix): bool {
    $n = db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?",
        [$prefix . $table, $indexName]
    );
    return (int) $n > 0;
};

try {
    // 1. organizations.parent_org_id — for hierarchical orgs
    //    (Statewide -> County -> District). FK to organizations(id)
    //    with ON DELETE SET NULL so deleting a parent doesn't cascade
    //    to children (admins can re-home them after).
    if (!$colExists('organizations', 'parent_org_id')) {
        db_query(
            "ALTER TABLE `{$prefix}organizations`
             ADD COLUMN `parent_org_id` INT NULL AFTER `id`"
        );
        echo "✓ organizations.parent_org_id column added\n";
    } else {
        echo "✓ organizations.parent_org_id already exists — skipping\n";
    }

    if (!$indexExists('organizations', 'idx_org_parent')) {
        db_query(
            "CREATE INDEX `idx_org_parent`
             ON `{$prefix}organizations` (`parent_org_id`)"
        );
        echo "✓ idx_org_parent index created\n";
    } else {
        echo "✓ idx_org_parent already exists — skipping\n";
    }

    // FK constraint (separate from index so this is independently
    // idempotent — FK lookup needs information_schema.TABLE_CONSTRAINTS).
    $fkExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = ?",
        [$prefix . 'organizations', 'fk_org_parent']
    ) > 0;
    if (!$fkExists) {
        try {
            db_query(
                "ALTER TABLE `{$prefix}organizations`
                 ADD CONSTRAINT `fk_org_parent`
                 FOREIGN KEY (`parent_org_id`)
                 REFERENCES `{$prefix}organizations` (`id`)
                 ON DELETE SET NULL ON UPDATE CASCADE"
            );
            echo "✓ fk_org_parent FK constraint added\n";
        } catch (Throwable $e) {
            // MyISAM-only databases can't have FK constraints — log
            // and continue. The application-layer logic is robust to
            // missing FK enforcement.
            echo "⚠ fk_org_parent FK could not be added: " . $e->getMessage() . "\n";
            echo "  (Non-fatal — app-layer org-tree code does not depend on FK enforcement.)\n";
        }
    } else {
        echo "✓ fk_org_parent FK already exists — skipping\n";
    }

    // 2. user.home_org_id — primary org affiliation. Backfilled to
    //    organization 1 ("System Owner") for any existing user with
    //    NULL home_org_id, so legacy users aren't org-less after
    //    99j-2 starts requiring this.
    if (!$colExists('user', 'home_org_id')) {
        db_query(
            "ALTER TABLE `{$prefix}user`
             ADD COLUMN `home_org_id` INT NULL AFTER `org`"
        );
        echo "✓ user.home_org_id column added\n";
    } else {
        echo "✓ user.home_org_id already exists — skipping\n";
    }

    $rowsBackfilled = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}user` WHERE `home_org_id` IS NULL"
    );
    if ($rowsBackfilled > 0) {
        db_query(
            "UPDATE `{$prefix}user`
                SET `home_org_id` = 1
              WHERE `home_org_id` IS NULL"
        );
        echo "✓ home_org_id backfilled to 1 for {$rowsBackfilled} existing user(s)\n";
    } else {
        echo "✓ all users already have home_org_id set — skipping backfill\n";
    }

    echo "\nDone — Phase 99j foundation in place.\n";
    echo "Next: Phase 99j-2 (User Accounts UI for home_org_id + per-role org_id).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
