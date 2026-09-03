<?php
/**
 * Phase 151 (GH#138, follow-on to GH#16) — Primary / Responsible Unit.
 *
 * specs/phase-151-primary-responsible-unit/{spec.md,plan.md,tasks.md}.
 *
 * WHAT THIS CREATES:
 *   - `ticket.primary_responder_id INT NULL` (+ index), `primary_set_at
 *     DATETIME NULL`, `primary_set_by INT NULL` — ticket-level, not
 *     assigns-level (see spec.md's "architectural question the design
 *     review resolved": api/incident-detail.php filters assigns to
 *     `clear IS NULL`, so an assigns-level flag would vanish the moment the
 *     primary unit clears, contradicting the feature's own requirement that
 *     the designation persist).
 *   - Setting `primary_unit_mode` = 'off' (off by default on every install,
 *     fresh or upgraded — an existing install's behaviour, and its webhook
 *     subscribers, must not change just by upgrading). Written to the
 *     `settings` table (name/value) — the store get_variable() reads
 *     (NOT the separate `config` table read by get_setting() — CLAUDE.md
 *     "TWO settings stores", GH #79).
 *   - RBAC permission `action.set_primary_unit`, tier 0 (unrestricted).
 *     Deliberately NOT admin-only: this project's own Roles & Permissions
 *     UI already lets an install revoke it from Dispatcher if it wants
 *     "supervisor only" — see plan.md §3 for why a second, harder-coded
 *     tier was rejected. Granted directly to roles 1/2/3 here because the
 *     base "grant everything" seed in rbac.sql/run_00_rbac.php only runs
 *     once at install; a permission added later is NOT retroactively
 *     granted (same reasoning as run_report_perm.php,
 *     run_phase132_disposition.php, etc.).
 *
 * Idempotent — safe to re-run. Verifies its own outcome (CLAUDE.md, Phase
 * 128 A9: a migration step that catches its own exception and exits 0 is a
 * step that never ran) and exits non-zero if the schema/setting/permission/
 * grants don't actually exist afterward.
 *
 * Usage: php sql/run_phase151_primary_unit.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

$prefix      = $GLOBALS['db_prefix'] ?? '';
$ticketTable = $prefix . 'ticket';
$fail        = [];

echo "Phase 151 — Primary / Responsible Unit\n";
echo "=======================================\n\n";

// ─────────────────────────────────────────────────────────────────────────
// 1. ticket.primary_responder_id / primary_set_at / primary_set_by
// ─────────────────────────────────────────────────────────────────────────
$newCols = [
    'primary_responder_id' => "INT NULL DEFAULT NULL",
    'primary_set_at'       => "DATETIME NULL DEFAULT NULL",
    'primary_set_by'       => "INT NULL DEFAULT NULL",
];
foreach ($newCols as $col => $ddl) {
    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$ticketTable, $col]);
        if ($exists === 0) {
            db_query("ALTER TABLE `{$ticketTable}` ADD COLUMN `{$col}` {$ddl}");
            echo "[OK] added ticket.{$col}\n";
        } else {
            echo "[OK] ticket.{$col} already present\n";
        }
    } catch (Exception $e) {
        $fail[] = "ticket.{$col}: " . $e->getMessage();
        echo "[FAIL] ticket.{$col}: " . $e->getMessage() . "\n";
    }
}

try {
    $idxExists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'idx_ticket_primary_responder'",
        [$ticketTable]);
    if ($idxExists === 0) {
        db_query("ALTER TABLE `{$ticketTable}` ADD INDEX `idx_ticket_primary_responder` (`primary_responder_id`)");
        echo "[OK] added index idx_ticket_primary_responder\n";
    } else {
        echo "[OK] index idx_ticket_primary_responder already present\n";
    }
} catch (Exception $e) {
    $fail[] = 'index idx_ticket_primary_responder: ' . $e->getMessage();
    echo "[FAIL] index idx_ticket_primary_responder: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 2. Default setting — primary_unit_mode
// ─────────────────────────────────────────────────────────────────────────
try {
    $exists = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", ['primary_unit_mode']);
    if ($exists === 0) {
        db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            ['primary_unit_mode', 'off']);
        echo "[OK] setting seeded: primary_unit_mode = off\n";
    } else {
        echo "[OK] setting exists: primary_unit_mode\n";
    }
} catch (Exception $e) {
    $fail[] = 'setting primary_unit_mode: ' . $e->getMessage();
    echo "[FAIL] setting primary_unit_mode: " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────────────────────
// 3. RBAC — action.set_primary_unit (tier 0, granted to roles 1/2/3)
// ─────────────────────────────────────────────────────────────────────────
$permCode = 'action.set_primary_unit';
$permId   = 0;
try {
    $permId = (int) db_fetch_value(
        "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ? LIMIT 1", [$permCode]);
    if ($permId === 0) {
        db_query("INSERT INTO `{$prefix}permissions` (`code`, `name`, `description`, `category`, `resource`, `verb`)
                  VALUES (?, ?, ?, 'action', 'incident', 'set_primary')",
            [$permCode,
             'Set Primary Unit',
             'Designate (or clear) the primary/responsible unit on an incident. Unrestricted (tier 0) — '
             . 'an install that wants this restricted to supervisors can revoke it from Dispatcher in the '
             . 'Roles & Permissions UI.']);
        $permId = (int) db_insert_id();
        echo "[OK] permission inserted: {$permCode} (id={$permId})\n";
    } else {
        echo "[OK] permission exists: {$permCode} (id={$permId})\n";
    }
} catch (Exception $e) {
    $fail[] = 'permission: ' . $e->getMessage();
    echo "[FAIL] permission: " . $e->getMessage() . "\n";
}

if ($permId > 0) {
    foreach ([1, 2, 3] as $roleId) {
        try {
            $has = db_fetch_value(
                "SELECT 1 FROM `{$prefix}role_permissions`
                  WHERE `role_id` = ? AND `permission_id` = ? LIMIT 1", [$roleId, $permId]);
            if (!$has) {
                db_query("INSERT INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
                          VALUES (?, ?)", [$roleId, $permId]);
                echo "  [+] grant: role {$roleId} -> {$permCode}\n";
            } else {
                echo "  [OK] grant already present: role {$roleId} -> {$permCode}\n";
            }
        } catch (Exception $e) {
            echo "  [warn] grant role {$roleId}: " . $e->getMessage() . "\n";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 4. Verify the OUTCOME
// ─────────────────────────────────────────────────────────────────────────
try {
    foreach (array_keys($newCols) as $col) {
        $colThere = (int) db_fetch_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$ticketTable, $col]);
        if ($colThere === 0) $fail[] = "verify: ticket.{$col} does not exist";
    }

    $settingThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", ['primary_unit_mode']);
    if ($settingThere === 0) $fail[] = "verify: setting primary_unit_mode does not exist";

    $permThere = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}permissions` WHERE `code` = ?", [$permCode]);
    if ($permThere === 0) $fail[] = "verify: permission {$permCode} does not exist";

    if ($permId > 0) {
        foreach ([1, 2, 3] as $roleId) {
            $grantThere = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}role_permissions`
                  WHERE `role_id` = ? AND `permission_id` = ?", [$roleId, $permId]);
            if ($grantThere === 0) $fail[] = "verify: role {$roleId} does not hold {$permCode}";
        }
    }
} catch (Exception $e) {
    $fail[] = 'verify: ' . $e->getMessage();
}

if ($fail) {
    echo "\nFAILED:\n  - " . implode("\n  - ", $fail) . "\n";
    exit(1);
}

echo "\nDone. Primary / Responsible Unit (Phase 151) installed.\n";
exit(0);
