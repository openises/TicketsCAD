<?php
/**
 * test_gh116_backfill_assign_status_id.php — proves
 * sql/run_gh116_backfill_assign_status_id.php against real fixtures with
 * genuine ladder progress (never hand-seeded "already correct" rows).
 *
 * The migration exists because an assignment already open before the
 * status_id-maintenance fix (see test_gh116_multi_assign_status_scoping.php,
 * Sections 7-11) landed keeps its stale creation-time status_id until its
 * NEXT status change. This test creates assignments, advances their
 * TIMESTAMP columns directly (bypassing the real writers, so status_id is
 * deliberately left stale exactly the way a pre-fix install's data would
 * be), runs the REAL migration script as a subprocess, and confirms:
 *   1. a fully-progressed assignment (u2farr set) backfills to
 *      facility_arrived's mapped status;
 *   2. a partially-progressed one (on_scene only) backfills to on_scene's;
 *   3. one that never progressed past creation is left untouched;
 *   4. running the migration a SECOND time is a clean no-op (idempotent);
 *   5. the migration's own verification pass reports success (exit 0).
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_fixture_guard.php';
require_once __DIR__ . '/_test_node_probe.php'; // for test_run_cli() — reused for the PHP subprocess too

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== GH#116 backfill migration (run_gh116_backfill_assign_status_id.php) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

function gh116bf_php_bin(): string {
    // Match this repo's own CLI convention (see other tests' subprocess
    // helpers) rather than assuming 'php' is on PATH.
    $candidates = ['/c/xampp/8.2.4/php/php.exe', 'C:/xampp/8.2.4/php/php.exe', PHP_BINARY, 'php'];
    foreach ($candidates as $c) {
        if ($c === PHP_BINARY || is_file($c)) return $c;
    }
    return 'php';
}

$userId = test_admin_user_id();
$statusIds = []; $ticketIds = []; $responderIds = []; $assignIds = [];

try {
    // assign_create_internal() writes a HARDCODED status_id=1 at creation
    // (see its own docblock) rather than resolving via
    // _assign_status_id_by_action('dispatched') the way every other branch
    // now does -- a separate, pre-existing quirk out of scope for this fix.
    // Read the REAL creation-time value back from a throwaway row instead
    // of assuming any particular resolver agrees with it.
    $sResponding = gh116bf_mk_status($prefix, $statusIds, 'gh116bf_resp', 'responding');
    $sOnScene    = gh116bf_mk_status($prefix, $statusIds, 'gh116bf_onscene', 'on_scene');
    $sFacArr     = gh116bf_mk_status($prefix, $statusIds, 'gh116bf_facarr', 'facility_arrived');

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh116bf_unit', 'GH116BFU', 'test', 1, NOW(), NOW())");
    $responderId = (int) db_insert_id();
    $responderIds[] = $responderId;
    test_fixture_guard_track('responder', $responderId);

    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");

    // Three open assignments, three different ladder positions, all
    // created (correctly) with whatever status_id assign_create_internal()
    // actually writes at creation, then their TIMESTAMPS advanced directly
    // (bypassing the real writers, which would themselves keep status_id
    // current per the fix this migration exists alongside) -- reproducing
    // exactly the stale state a pre-fix-deployment install's open calls
    // would be in. $sDispatched is captured from the FIRST real creation,
    // not assumed from any resolver -- see the note above.
    $fixtures = [
        ['scope' => 'gh116bf_A', 'progress' => 'facility_arrived'],
        ['scope' => 'gh116bf_B', 'progress' => 'on_scene'],
        ['scope' => 'gh116bf_C', 'progress' => 'none'],
    ];
    $aidByScope = [];
    $sDispatched = null;
    foreach ($fixtures as $fx) {
        db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
                  VALUES (?, 2, 0, ?, 'GH116 backfill fixture', NOW(), NOW(), 1)", [$typeId, $fx['scope']]);
        $tid = (int) db_insert_id(); $ticketIds[] = $tid;
        test_fixture_guard_track('ticket', $tid);
        test_fixture_guard_track_where('action', 'ticket_id = ?', [$tid]);

        $ra = assign_create_internal($tid, $responderId, '', $userId, true);
        $aid = (int) ($ra['id'] ?? 0);
        is_true($aid > 0, "fixture: assignment created for {$fx['scope']}", json_encode($ra));
        $assignIds[] = $aid;
        test_fixture_guard_track('assigns', $aid);
        $aidByScope[$fx['scope']] = $aid;

        $preRow = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aid]);
        $createdStatusId = (int) ($preRow['status_id'] ?? -1);
        if ($sDispatched === null) {
            $sDispatched = $createdStatusId;
        } else {
            is_true($createdStatusId === $sDispatched,
                "sanity: {$fx['scope']} was created with the SAME status_id as the others (consistent baseline)",
                'expected ' . $sDispatched . ', got ' . $createdStatusId);
        }

        // Directly stamp timestamps (NOT via a real writer) to simulate
        // pre-fix-deployment drift: status_id stays at the creation-time
        // value no matter how far the ladder actually advanced.
        if ($fx['progress'] === 'facility_arrived') {
            db_query("UPDATE `{$prefix}assigns` SET `responding` = NOW(), `on_scene` = NOW(), `u2fenr` = NOW(), `u2farr` = NOW() WHERE id = ?", [$aid]);
        } elseif ($fx['progress'] === 'on_scene') {
            db_query("UPDATE `{$prefix}assigns` SET `responding` = NOW(), `on_scene` = NOW() WHERE id = ?", [$aid]);
        }
        // 'none' -- leave exactly as assign_create_internal() left it.

        $stillStale = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aid]);
        is_true((int) ($stillStale['status_id'] ?? -1) === $sDispatched,
            "sanity: {$fx['scope']} still shows the stale creation-time status_id before the migration runs",
            'got ' . ($stillStale['status_id'] ?? 'null'));
    }

    // ── Run the REAL migration as a subprocess ──
    $php = gh116bf_php_bin();
    $migPath = $base . '/sql/run_gh116_backfill_assign_status_id.php';
    $out1 = test_run_cli([$php, $migPath]);
    is_true($out1 !== null, 'migration ran (subprocess produced output)');
    is_true($out1 !== null && strpos($out1, 'Verified:') !== false,
        'migration reports its own verification pass succeeded', (string) $out1);

    $rowA = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidByScope['gh116bf_A']]);
    is_true((int) ($rowA['status_id'] ?? 0) === (int) $sFacArr,
        'A (facility_arrived progress): backfilled to the facility_arrived-mapped status',
        'expected ' . $sFacArr . ', got ' . ($rowA['status_id'] ?? 'null'));

    $rowB = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidByScope['gh116bf_B']]);
    is_true((int) ($rowB['status_id'] ?? 0) === (int) $sOnScene,
        'B (on_scene progress): backfilled to the on_scene-mapped status',
        'expected ' . $sOnScene . ', got ' . ($rowB['status_id'] ?? 'null'));

    $rowC = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidByScope['gh116bf_C']]);
    is_true((int) ($rowC['status_id'] ?? 0) === $sDispatched,
        'C (no progress): left untouched at its creation-time status — nothing to backfill',
        'expected ' . $sDispatched . ', got ' . ($rowC['status_id'] ?? 'null'));

    // ── Idempotency: a second run is a clean no-op ──
    $out2 = test_run_cli([$php, $migPath]);
    is_true($out2 !== null && strpos($out2, 'Backfilled:        0') !== false,
        'idempotent: a second run backfills nothing (everything already correct)', (string) $out2);
    is_true($out2 !== null && strpos($out2, 'Verified:') !== false,
        'idempotent: second run still verifies clean', (string) $out2);

    $rowA2 = db_fetch_one("SELECT status_id FROM `{$prefix}assigns` WHERE id = ?", [$aidByScope['gh116bf_A']]);
    is_true((int) ($rowA2['status_id'] ?? 0) === (int) $sFacArr,
        'A still correct after the second, no-op run');

} catch (Throwable $e) {
    bad('fixture/migration path threw', $e->getMessage() . "\n" . $e->getTraceAsString());
}

function gh116bf_mk_status(string $prefix, array &$statusIds, string $val, string $incidentAction): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (`status_val`, `description`, `incident_action`, `dispatch`, `watch`, `hide`, `excl_from_reset`, `group`, `sort`, `bg_color`, `text_color`)
         VALUES (?, ?, ?, 0, 0, 'n', 'n', 'gh116bf_test', 999, '#888888', '#000000')",
        [$val, 'GH116 backfill test — ' . $val, $incidentAction]
    );
    $id = (int) db_insert_id();
    test_fixture_guard_track('un_status', $id);
    $statusIds[] = $id;
    return $id;
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";

// ── Teardown ──
try {
    foreach ($assignIds as $aid) { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$aid]); }
    foreach ($ticketIds as $tid) {
        db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
        db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    }
    foreach ($responderIds as $rid) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]); }
    foreach ($statusIds as $sid) { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$sid]); }
} catch (Throwable $e) { echo "  Teardown warning: " . $e->getMessage() . "\n"; }

exit($fail === 0 ? 0 : 1);
