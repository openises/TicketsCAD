<?php
/**
 * Phase 80c — Recent-activity dashboard widget permission.
 *
 * Seeds the `widget.audit_log` permission code and grants it to the
 * roles that should see the new dashboard widget out of the box:
 *
 *   - role id 1 — Super Admin     (covered by INSERT-everything path)
 *   - role id 2 — Org Admin
 *   - role id 3 — Dispatcher
 *
 * Operator (4), Read-Only (5), and Field Unit (6) do NOT get the
 * widget by default — admins can grant it manually if a non-admin
 * role needs visibility into audit activity.
 *
 * Idempotent — uses INSERT IGNORE everywhere, safe to re-run.
 *
 * Usage:  php sql/run_phase80c_perms.php
 */
require_once __DIR__ . '/../config.php';

echo "Phase 80c — Audit-log dashboard widget permission\n";
echo "==================================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Seed the permission row ───────────────────────────────────
try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`)
         VALUES (?, ?, ?)",
        ['widget.audit_log', 'Recent Activity Widget', 'widget']
    );
    echo "[OK] permission 'widget.audit_log' present\n";
} catch (Exception $e) {
    echo "[WARN] could not seed permission: " . $e->getMessage() . "\n";
    exit(1);
}

// ── 2. Resolve the permission's id ───────────────────────────────
$permId = null;
try {
    $row = db_fetch_one(
        "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = 'widget.audit_log' LIMIT 1"
    );
    $permId = $row ? (int) $row['id'] : null;
} catch (Exception $e) {}

if (!$permId) {
    echo "[ERR] could not resolve permission id; aborting role grants\n";
    exit(1);
}

// ── 3. Grant to the default roles ────────────────────────────────
// Super Admin (1) gets every permission via the general "INSERT all"
// in run_00_rbac.php — but we still INSERT IGNORE here in case an install
// was set up by hand and never re-ran the master script.
$defaultRoles = [1, 2, 3];
$granted = 0;
foreach ($defaultRoles as $roleId) {
    try {
        // Confirm the role row exists before we try to grant.
        $r = db_fetch_one("SELECT `id` FROM `{$prefix}roles` WHERE `id` = ? LIMIT 1", [$roleId]);
        if (!$r) {
            echo "[--] role id={$roleId} not present; skipped\n";
            continue;
        }
        $stmt = db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
             VALUES (?, ?)",
            [$roleId, $permId]
        );
        $rc = $stmt ? $stmt->rowCount() : 0;
        if ($rc > 0) {
            echo "[OK] granted widget.audit_log to role id={$roleId}\n";
            $granted++;
        } else {
            echo "[OK] role id={$roleId} already has widget.audit_log\n";
        }
    } catch (Exception $e) {
        echo "[WARN] grant to role {$roleId} failed: " . $e->getMessage() . "\n";
    }
}

echo "\nDone. {$granted} new grant(s) applied.\n";
