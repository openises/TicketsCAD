<?php
/**
 * Phase 138 — Public incident board: org-scoped `?org=<slug>` resolution
 * (tasks.md D3).
 *
 * Drives the REAL api/public-board.php over real HTTP against a
 * self-hosted local server rooted at the actual project tree (see
 * tests/_pb_test_server.php) — never a hand-simulated router.
 *
 * Covers plan.md §5 step 3: an unknown slug and a real-but-disabled slug
 * must 404 IDENTICALLY (same status, no differentiation of the reason —
 * never leak which slugs are valid). A real, enabled slug must scope the
 * result set to that org's own incidents only.
 *
 * NOT @requires-http: this spins up its OWN local PHP server (same
 * self-contained pattern as tests/test_web_exposure_backups_probe.php,
 * which is likewise unmarked) — it never touches a live Apache/localhost
 * install, so it runs fine under NEWUI_TEST_NO_HTTP=1 / in CI. It DOES
 * need a reachable MySQL/MariaDB, same as every other DB-backed test.
 *
 * @requires-db
 * Usage: php tests/test_public_board_org_scope.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_pb_test_server.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — public-board.php org-scope resolution (?org=) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

try {
    $hasCol = db_fetch_value(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_slug'",
        [$prefix . 'organizations']
    );
} catch (Throwable $e) { $hasCol = false; }
if (!$hasCol) {
    echo "SKIP: Phase 138 schema not present (run sql/run_phase138_public_board.php first)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$srv = pb_test_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server for this test (proc_open/curl unavailable)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$createdOrgIds = [];
$createdTypeId = null;
$createdTicketId = null;

try {
    $uniq = 'zz138-' . substr(md5((string) mt_rand()), 0, 8);
    $slugA = $uniq . '-a'; // enabled, has the test ticket
    $slugB = $uniq . '-b'; // enabled, no matching incidents
    $slugC = $uniq . '-c'; // exists but DISABLED (public_board_enabled=0)
    $slugD = $uniq . '-d'; // enabled AND active=0 (deactivated org) — security review finding #2
    $slugUnknown = $uniq . '-does-not-exist';

    db_query("INSERT INTO " . db_table('organizations') . " (`name`, `public_board_enabled`, `public_board_slug`) VALUES (?,1,?)", ["ZZ138 Org A ({$uniq})", $slugA]);
    $orgA = (int) db_insert_id(); $createdOrgIds[] = $orgA;
    db_query("INSERT INTO " . db_table('organizations') . " (`name`, `public_board_enabled`, `public_board_slug`) VALUES (?,1,?)", ["ZZ138 Org B ({$uniq})", $slugB]);
    $orgB = (int) db_insert_id(); $createdOrgIds[] = $orgB;
    db_query("INSERT INTO " . db_table('organizations') . " (`name`, `public_board_enabled`, `public_board_slug`) VALUES (?,0,?)", ["ZZ138 Org C ({$uniq})", $slugC]);
    $orgC = (int) db_insert_id(); $createdOrgIds[] = $orgC;
    db_query("INSERT INTO " . db_table('organizations') . " (`name`, `public_board_enabled`, `public_board_slug`, `active`) VALUES (?,1,?,0)", ["ZZ138 Org D ({$uniq})", $slugD]);
    $orgD = (int) db_insert_id(); $createdOrgIds[] = $orgD;

    db_query("INSERT INTO " . db_table('in_types') . " (`type`, `description`) VALUES (?, 'zz138 org-scope test type')", ['zz138-' . uniqid()]);
    $createdTypeId = (int) db_insert_id();

    db_query(
        "INSERT INTO " . db_table('ticket') . "
            (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`org_id`)
         VALUES (?, '', '1 Test Way', 'Testville', 'MN', 44.8, -93.3, ?, 'zz138 org scope test', 'zz138 org scope test', 2, 1, NOW(), ?)",
        [$createdTypeId, date('Y-m-d H:i:s', time() - 3600), $orgA]
    );
    $createdTicketId = (int) db_insert_id();

    $base = 'http://127.0.0.1:' . $srv['port'] . '/api/public-board.php';

    // ── Unknown slug: 404 ────────────────────────────────────────────
    $r1 = pb_test_http_get($base . '?org=' . urlencode($slugUnknown));
    t('unknown slug -> 404', $r1 !== null && $r1['status'] === 404);

    // ── Real slug, but board disabled for that org: 404 (SAME as unknown) ──
    $r2 = pb_test_http_get($base . '?org=' . urlencode($slugC));
    t('real-but-disabled slug -> 404', $r2 !== null && $r2['status'] === 404);
    t('disabled-slug 404 is INDISTINGUISHABLE from unknown-slug 404 (same status, same shape)',
        $r1 !== null && $r2 !== null && $r1['status'] === $r2['status']
        && array_keys(json_decode($r1['body'], true) ?: []) === array_keys(json_decode($r2['body'], true) ?: []));

    // ── Security review finding #2 (2026-08-13): a deactivated org
    // (active=0) with public_board_enabled=1 must NOT keep serving its
    // board — same 404 as unknown/disabled, indistinguishable. ──
    $rD = pb_test_http_get($base . '?org=' . urlencode($slugD));
    t('deactivated org (active=0, enabled=1) -> 404, not 200', $rD !== null && $rD['status'] === 404);
    t('deactivated-org 404 is INDISTINGUISHABLE from unknown-slug 404 (same status, same shape)',
        $r1 !== null && $rD !== null && $r1['status'] === $rD['status']
        && array_keys(json_decode($r1['body'], true) ?: []) === array_keys(json_decode($rD['body'], true) ?: []));

    // ── Enabled org A: 200, org_scoped, includes our ticket ─────────────
    $r3 = pb_test_http_get($base . '?org=' . urlencode($slugA));
    t('enabled org A -> 200', $r3 !== null && $r3['status'] === 200);
    $j3 = $r3 !== null ? json_decode($r3['body'], true) : null;
    t('org A response: board.org_scoped === true', $j3 !== null && ($j3['board']['org_scoped'] ?? null) === true);
    $idsA = $j3 !== null ? array_map(function ($i) { return (int) $i['id']; }, $j3['incidents'] ?? []) : [];
    t('org A response includes our test ticket', in_array($createdTicketId, $idsA, true));

    // ── Enabled org B: 200, org_scoped, does NOT include our ticket ────
    $r4 = pb_test_http_get($base . '?org=' . urlencode($slugB));
    t('enabled org B -> 200', $r4 !== null && $r4['status'] === 200);
    $j4 = $r4 !== null ? json_decode($r4['body'], true) : null;
    t('org B response: board.org_scoped === true', $j4 !== null && ($j4['board']['org_scoped'] ?? null) === true);
    $idsB = $j4 !== null ? array_map(function ($i) { return (int) $i['id']; }, $j4['incidents'] ?? []) : [];
    t('org B response EXCLUDES our test ticket (org_id mismatch)', !in_array($createdTicketId, $idsB, true));

} finally {
    pb_test_stop_server($srv);
    if ($createdTicketId !== null) { try { db_query("DELETE FROM " . db_table('ticket') . " WHERE id = ?", [$createdTicketId]); } catch (Throwable $e) {} }
    if ($createdTypeId !== null) { try { db_query("DELETE FROM " . db_table('in_types') . " WHERE id = ?", [$createdTypeId]); } catch (Throwable $e) {} }
    foreach ($createdOrgIds as $id) { try { db_query("DELETE FROM " . db_table('organizations') . " WHERE id = ?", [$id]); } catch (Throwable $e) {} }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
