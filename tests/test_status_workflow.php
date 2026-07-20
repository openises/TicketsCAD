<?php
/**
 * Status Workflow Tests (Phase 105, a beta tester GH #16)
 *
 * Verifies the conditional / stateful unit-status feature:
 *   - sql/run_status_workflow.php migration is idempotent
 *   - status_transitions + status_workflow_layout tables + unique key
 *   - RBAC permission action.manage_status_workflow seeded to
 *     Super Admin + Org Admin
 *   - sw_get_mode() defaults to 'off' when the settings row is absent
 *   - sw_check_transition(): off allows everything; enforce blocks
 *     missing edges, allows drawn edges + ANY-source (from=0) edges;
 *     requires_assignment / requires_no_assignment conditions evaluate
 *     against live assigns rows; bogus conditions_json fails open
 *   - endpoint / page / JS hygiene (rbac, csrf, json_error_safe,
 *     sess_bootstrap_auto, node --check)
 *
 * The settings row status_workflow_mode is restored to 'off' at the
 * end of the run regardless of pass/fail (shutdown handler).
 *
 * Usage: php tests/test_status_workflow.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/status-workflow.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0;
$failed = 0;

function test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label\n";
        $failed++;
    }
}

/** Set (or clear) the status_workflow_mode settings row + reset cache. */
function sw_test_set_mode(?string $mode): void {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['status_workflow_mode']);
    if ($mode !== null) {
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            ['status_workflow_mode', $mode]
        );
    }
    sw_mode_cache_reset();
}

// ── SAFETY NET: restore mode to 'off' no matter how the run ends ──
register_shutdown_function(function () {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", ['status_workflow_mode']);
        db_query(
            "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?)",
            ['status_workflow_mode', 'off']
        );
    } catch (Exception $e) {
        echo "[WARN] Could not restore status_workflow_mode: " . $e->getMessage() . "\n";
    }
});

echo "=== Status Workflow Tests (Phase 105) ===\n\n";

// ── Migration idempotency ─────────────────────────────────────
echo "-- Migration --\n";

$phpBin = PHP_BINARY;
$migration = __DIR__ . '/../sql/run_status_workflow.php';

exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migration) . ' 2>&1', $out1, $rc1);
test('Migration runs cleanly (first pass)', $rc1 === 0);

exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($migration) . ' 2>&1', $out2, $rc2);
test('Migration is idempotent (second pass)', $rc2 === 0);

// ── Schema ────────────────────────────────────────────────────
echo "\n-- Schema --\n";

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'status_transitions']
    );
    test('status_transitions table exists', $tbl !== null);
} catch (Exception $e) {
    test('status_transitions table exists', false);
}

try {
    $tbl = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'status_workflow_layout']
    );
    test('status_workflow_layout table exists', $tbl !== null);
} catch (Exception $e) {
    test('status_workflow_layout table exists', false);
}

try {
    $indexes = db_fetch_all("SHOW INDEX FROM `{$prefix}status_transitions`");
    $uniqEdge = false;
    foreach ($indexes as $ix) {
        if ($ix['Key_name'] === 'uniq_edge' && (int) $ix['Non_unique'] === 0) {
            $uniqEdge = true;
        }
    }
    test('uniq_edge UNIQUE key exists on status_transitions', $uniqEdge);
} catch (Exception $e) {
    test('uniq_edge UNIQUE key exists on status_transitions', false);
}

// ── RBAC seeding ──────────────────────────────────────────────
echo "\n-- RBAC --\n";

$permId = 0;
try {
    $permId = (int) db_fetch_value(
        "SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1",
        ['action.manage_status_workflow']
    );
    test('action.manage_status_workflow permission exists', $permId > 0);
} catch (Exception $e) {
    test('action.manage_status_workflow permission exists', false);
}

foreach (['Super Admin', 'Org Admin'] as $roleName) {
    try {
        $granted = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}role_permissions` rp
             JOIN `{$prefix}roles` r ON r.id = rp.role_id
             WHERE rp.permission_id = ? AND r.name = ?",
            [$permId, $roleName]
        );
        test("Permission granted to {$roleName}", $granted > 0);
    } catch (Exception $e) {
        test("Permission granted to {$roleName}", false);
    }
}

// ── sw_get_mode ───────────────────────────────────────────────
echo "\n-- Mode --\n";

// Snapshot any pre-existing mode row so behavior matches a fresh install
$origMode = null;
try {
    $origMode = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = ? LIMIT 1",
        ['status_workflow_mode']
    );
    if ($origMode === false) $origMode = null;
} catch (Exception $e) { /* leave null */ }

sw_test_set_mode(null);   // no row at all
test("sw_get_mode defaults to 'off' when settings row is missing", sw_get_mode() === 'off');

sw_test_set_mode('bogus-value');
test("sw_get_mode falls back to 'off' on unknown value", sw_get_mode() === 'off');

sw_test_set_mode('warn');
test("sw_get_mode reads 'warn'", sw_get_mode() === 'warn');

sw_test_set_mode('enforce');
test("sw_get_mode reads 'enforce'", sw_get_mode() === 'enforce');

// ── Scratch fixtures ──────────────────────────────────────────
// Two un_status rows + a responder + (later) a ticket + assign.
echo "\n-- Enforcement Logic --\n";

$stA = $stB = $stC = 0;         // scratch statuses
$scratchResponder = 0;
$scratchTicket = 0;
$scratchAssign = 0;
$edgeIds = [];

try {
    db_query(
        "INSERT INTO `{$prefix}un_status` (`status_val`, `description`) VALUES (?, ?)",
        ['WF Test A', 'phase105 test']
    );
    $stA = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}un_status` (`status_val`, `description`) VALUES (?, ?)",
        ['WF Test B', 'phase105 test']
    );
    $stB = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}un_status` (`status_val`, `description`) VALUES (?, ?)",
        ['WF Test C', 'phase105 test']
    );
    $stC = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`)
         VALUES (?, ?, ?)",
        ['WF Test Unit', 'phase105 scratch unit', $stA]
    );
    $scratchResponder = (int) db_insert_id();
    test('Scratch fixtures created (statuses + responder)',
        $stA > 0 && $stB > 0 && $stC > 0 && $scratchResponder > 0);
} catch (Exception $e) {
    test('Scratch fixtures created (statuses + responder): ' . $e->getMessage(), false);
}

// mode off → everything allowed even with zero edges
sw_test_set_mode('off');
$chk = sw_check_transition($scratchResponder, $stA, $stB);
test("mode 'off' allows any transition", $chk['allowed'] === true && $chk['mode'] === 'off');

// mode enforce with zero edges → blocked, reason names the statuses
sw_test_set_mode('enforce');
$chk = sw_check_transition($scratchResponder, $stA, $stB);
test("mode 'enforce' blocks a transition with no edge", $chk['allowed'] === false);
test('Block reason names both statuses',
    strpos($chk['reason'], 'WF Test A') !== false
    && strpos($chk['reason'], 'WF Test B') !== false);

// same-status "transition" is a refresh — always allowed
$chk = sw_check_transition($scratchResponder, $stA, $stA);
test('Re-applying the current status is always allowed', $chk['allowed'] === true);

// draw edge A → B → allowed
try {
    db_query(
        "INSERT INTO `{$prefix}status_transitions` (`from_status_id`, `to_status_id`) VALUES (?, ?)",
        [$stA, $stB]
    );
    $edgeIds[] = (int) db_insert_id();
} catch (Exception $e) { /* surfaces in the next assertion */ }
$chk = sw_check_transition($scratchResponder, $stA, $stB);
test('Drawn edge A→B passes under enforce', $chk['allowed'] === true);

// reverse (B → A) still blocked — edges are directional
$chk = sw_check_transition($scratchResponder, $stB, $stA);
test('Reverse direction B→A still blocked (edges are directional)', $chk['allowed'] === false);

// ANY-source edge (0 → C) passes from anywhere
try {
    db_query(
        "INSERT INTO `{$prefix}status_transitions` (`from_status_id`, `to_status_id`) VALUES (0, ?)",
        [$stC]
    );
    $edgeIds[] = (int) db_insert_id();
} catch (Exception $e) { /* surfaces below */ }
$chkFromA = sw_check_transition($scratchResponder, $stA, $stC);
$chkFromB = sw_check_transition($scratchResponder, $stB, $stC);
test('ANY-source edge (from=0) passes from status A', $chkFromA['allowed'] === true);
test('ANY-source edge (from=0) passes from status B', $chkFromB['allowed'] === true);

// requires_assignment condition — B → A edge, unit has no assignment
try {
    db_query(
        "INSERT INTO `{$prefix}status_transitions`
         (`from_status_id`, `to_status_id`, `conditions_json`) VALUES (?, ?, ?)",
        [$stB, $stA, json_encode(['requires_assignment' => true])]
    );
    $edgeIds[] = (int) db_insert_id();
} catch (Exception $e) { /* surfaces below */ }

$chk = sw_check_transition($scratchResponder, $stB, $stA);
test('requires_assignment blocks a unit with no open assignment', $chk['allowed'] === false);
test('Condition failure reason names the condition',
    strpos($chk['reason'], 'requires an active incident assignment') !== false);

// give the unit an open assignment (scratch ticket + assigns row)
try {
    // in_types_id / scope / description are NOT NULL without defaults
    // (strict mode) — provide all three.
    db_query(
        "INSERT INTO `{$prefix}ticket`
         (`in_types_id`, `street`, `city`, `state`, `scope`, `description`, `status`, `updated`)
         VALUES (1, '1 Test Way', 'Testville', 'MN', 'phase105 test', 'phase105 workflow test', 1, NOW())"
    );
    $scratchTicket = (int) db_insert_id();
    db_query(
        "INSERT INTO `{$prefix}assigns` (`as_of`, `ticket_id`, `responder_id`, `user_id`)
         VALUES (NOW(), ?, ?, 1)",
        [$scratchTicket, $scratchResponder]
    );
    $scratchAssign = (int) db_insert_id();
    test('Scratch ticket + open assignment created', $scratchTicket > 0 && $scratchAssign > 0);
} catch (Exception $e) {
    test('Scratch ticket + open assignment created: ' . $e->getMessage(), false);
}

$chk = sw_check_transition($scratchResponder, $stB, $stA);
test('requires_assignment passes once an open assigns row exists', $chk['allowed'] === true);

// requires_no_assignment — new edge C → B; unit currently HAS an assignment
try {
    db_query(
        "INSERT INTO `{$prefix}status_transitions`
         (`from_status_id`, `to_status_id`, `conditions_json`) VALUES (?, ?, ?)",
        [$stC, $stB, json_encode(['requires_no_assignment' => true])]
    );
    $edgeIds[] = (int) db_insert_id();
} catch (Exception $e) { /* surfaces below */ }

// NOTE: the ANY edge 0→? doesn't exist toward B, so only C→B applies here
$chk = sw_check_transition($scratchResponder, $stC, $stB);
test('requires_no_assignment blocks a unit WITH an open assignment', $chk['allowed'] === false);

// clear the assignment → condition passes
try {
    db_query("UPDATE `{$prefix}assigns` SET `clear` = NOW() WHERE `id` = ?", [$scratchAssign]);
} catch (Exception $e) { /* surfaces below */ }
$chk = sw_check_transition($scratchResponder, $stC, $stB);
test('requires_no_assignment passes after the assignment is cleared', $chk['allowed'] === true);

// warn mode: blocked transitions report allowed=false + mode='warn'
// (the caller applies the change anyway and surfaces the warning).
// C → A is undrawn (A → B, 0 → C, B → A, C → B exist) so it blocks.
sw_test_set_mode('warn');
$chk = sw_check_transition($scratchResponder, $stC, $stA);
test("mode 'warn' reports blocked (caller decides to apply)",
    $chk['allowed'] === false && $chk['mode'] === 'warn');

// fail-open on bogus conditions_json
sw_test_set_mode('enforce');
try {
    db_query(
        "INSERT INTO `{$prefix}status_transitions`
         (`from_status_id`, `to_status_id`, `conditions_json`) VALUES (?, ?, ?)",
        [$stA, $stC + 1000000, 'this is {{ not json']
    );
    $edgeIds[] = (int) db_insert_id();
    $chk = sw_check_transition($scratchResponder, $stA, $stC + 1000000);
    test('Bogus conditions_json fails open (edge treated as unconditional)',
        $chk['allowed'] === true);
} catch (Exception $e) {
    test('Bogus conditions_json fails open: ' . $e->getMessage(), false);
}

// ── File hygiene ──────────────────────────────────────────────
echo "\n-- File Hygiene --\n";

$apiSrc = @file_get_contents(__DIR__ . '/../api/status-workflow.php');
test('API endpoint checks rbac_can(action.manage_status_workflow)',
    $apiSrc !== false && strpos($apiSrc, "rbac_can('action.manage_status_workflow')") !== false);
test('API endpoint verifies CSRF on POST',
    $apiSrc !== false && strpos($apiSrc, 'csrf_verify') !== false);
test('API endpoint masks exceptions via json_error_safe',
    $apiSrc !== false && strpos($apiSrc, 'json_error_safe') !== false);

$pageSrc = @file_get_contents(__DIR__ . '/../workflow-designer.php');
test('Designer page uses sess_bootstrap_auto()',
    $pageSrc !== false && strpos($pageSrc, 'sess_bootstrap_auto()') !== false);
test('Designer page gates on action.manage_status_workflow',
    $pageSrc !== false && strpos($pageSrc, "rbac_can('action.manage_status_workflow')") !== false);

$writeSrc = @file_get_contents(__DIR__ . '/../inc/responder-write.php');
test('responder_set_status_internal calls sw_check_transition',
    $writeSrc !== false && strpos($writeSrc, 'sw_check_transition(') !== false);

// node --check on the designer JS (skip gracefully if node missing)
$nodeBin = null;
foreach (['C:\\Program Files\\nodejs\\node.exe', 'node'] as $cand) {
    $probe = [];
    $rc = 1;
    @exec(($cand === 'node' ? 'node' : escapeshellarg($cand)) . ' --version 2>&1', $probe, $rc);
    if ($rc === 0) { $nodeBin = $cand; break; }
}
if ($nodeBin !== null) {
    $jsPath = __DIR__ . '/../assets/js/workflow-designer.js';
    $out = [];
    $rc = 1;
    @exec(($nodeBin === 'node' ? 'node' : escapeshellarg($nodeBin))
        . ' --check ' . escapeshellarg($jsPath) . ' 2>&1', $out, $rc);
    test('workflow-designer.js passes node --check', $rc === 0);
} else {
    echo "[SKIP] node not found — JS syntax check skipped\n";
}

// ── Cleanup ───────────────────────────────────────────────────
echo "\n-- Cleanup --\n";
try {
    foreach ($edgeIds as $eid) {
        db_query("DELETE FROM `{$prefix}status_transitions` WHERE `id` = ?", [$eid]);
    }
    if ($scratchAssign > 0) {
        db_query("DELETE FROM `{$prefix}assigns` WHERE `id` = ?", [$scratchAssign]);
    }
    if ($scratchTicket > 0) {
        db_query("DELETE FROM `{$prefix}ticket` WHERE `id` = ?", [$scratchTicket]);
    }
    if ($scratchResponder > 0) {
        db_query("DELETE FROM `{$prefix}responder` WHERE `id` = ?", [$scratchResponder]);
    }
    foreach ([$stA, $stB, $stC] as $sid) {
        if ($sid > 0) {
            db_query("DELETE FROM `{$prefix}un_status` WHERE `id` = ?", [$sid]);
            db_query("DELETE FROM `{$prefix}status_workflow_layout` WHERE `status_id` = ?", [$sid]);
        }
    }
    echo "[OK] Test data cleaned up\n";
} catch (Exception $e) {
    echo "[WARN] Cleanup failed: " . $e->getMessage() . "\n";
}

// Restore the settings row to 'off' (the shutdown handler also does
// this, but do it explicitly so the report reflects the final state).
sw_test_set_mode('off');
echo "[OK] status_workflow_mode restored to 'off'\n";

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
