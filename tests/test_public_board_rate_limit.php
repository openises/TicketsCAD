<?php
/**
 * Phase 138 — Public incident board: rate limiting (tasks.md D4).
 *
 * Drives the REAL api/public-board.php over real HTTP against a
 * self-hosted local server (tests/_pb_test_server.php — same
 * self-contained pattern as tests/test_web_exposure_backups_probe.php,
 * runs fine under NEWUI_TEST_NO_HTTP=1 / in CI, never touches a live
 * Apache).
 *
 * Two things proven, not asserted:
 *
 *   1. The endpoint reads its limit/window from the TWO configured
 *      settings (public_board_rate_limit_requests /
 *      _window_secs) — NOT hardcoded values. Set both to a tiny limit,
 *      confirm the Nth+1 request within the window actually 429s with a
 *      Retry-After header matching the configured window.
 *
 *   2. The fail-open contract (rate_limit_ok()'s own docblock,
 *      inc/rate-limit.php): if the rate limiter itself cannot be
 *      consulted, the request must still succeed — never a 500, never a
 *      silent block. Forced here the same way rate_limit_ok()'s own file
 *      fallback fails open: pre-create the exact bucket path AS A
 *      DIRECTORY so `fopen($path, 'c+b')` cannot open it as a file. This
 *      environment has no APCu (extension_loaded('apcu') === false), so
 *      the file-based fallback is the live path being exercised here, not
 *      a hypothetical alternate.
 *
 * @requires-db
 * Usage: php tests/test_public_board_rate_limit.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_pb_test_server.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — public-board.php rate limiting ===\n\n";

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

// Confirms the source actually WIRES the two settings into rate_limit_ok()
// (not hardcoded), same lightweight convention tests/test_rate_limit_73x.php
// uses for its "ingest endpoints actually wired the limiter" checks.
$src = file_get_contents(__DIR__ . '/../api/public-board.php');
t('source reads public_board_rate_limit_requests (not a hardcoded number)', strpos($src, "get_variable('public_board_rate_limit_requests')") !== false);
t('source reads public_board_rate_limit_window_secs (not a hardcoded number)', strpos($src, "get_variable('public_board_rate_limit_window_secs')") !== false);
t('source calls rate_limit_ok() with the client IP bucket', strpos($src, "rate_limit_ok('public_board:' . client_ip()") !== false);
t('source calls rate_limit_reject() on failure (429 + Retry-After path)', strpos($src, 'rate_limit_reject(') !== false);

$origRequests = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_rate_limit_requests'");
$origWindow   = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_rate_limit_window_secs'");

$srv = pb_test_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server for this test (proc_open/curl unavailable)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

try {
    // ── Part 1: settings-driven limit actually trips ────────────────────
    $limit = 2; $window = 60;
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_rate_limit_requests', ?)
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [(string) $limit]);
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_rate_limit_window_secs', ?)
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [(string) $window]);

    $base = 'http://127.0.0.1:' . $srv['port'] . '/api/public-board.php';
    // Every request in this test hits the SAME bucket (same client_ip() —
    // curl to 127.0.0.1 with no proxy headers resolves to REMOTE_ADDR).
    $statuses = [];
    for ($i = 0; $i < $limit + 1; $i++) {
        $r = pb_test_http_get($base);
        $statuses[] = $r !== null ? $r['status'] : null;
    }
    $last = pb_test_http_get($base);

    $withinLimitOk = true;
    for ($i = 0; $i < $limit; $i++) {
        if ($statuses[$i] === 429) $withinLimitOk = false;
    }
    t("first {$limit} requests are NOT rate-limited (settings limit={$limit} honored)", $withinLimitOk);
    t('a request beyond the configured limit IS rate-limited (429)', $last !== null && $last['status'] === 429);
    t('429 response carries a Retry-After header', $last !== null && isset($last['headers']['retry-after']));
    t('Retry-After matches the configured window', $last !== null && (int) ($last['headers']['retry-after'] ?? -1) === $window);

    // ── Part 2: fail-open proof ──────────────────────────────────────────
    // Restart the server with a FRESH tmp dir, then poison the exact
    // bucket file path (as a directory) BEFORE the first request, so
    // rate_limit_ok()'s file fallback can never open it and returns true
    // (fail open) on every call — proving the endpoint does not 500 or
    // silently block when the limiter itself is unreachable.
    pb_test_stop_server($srv);
    $srv = pb_test_start_server();
    if ($srv === null) {
        echo "SKIP: could not restart local server for the fail-open case\n";
    } else {
        $bucket = 'public_board:127.0.0.1';
        $bucketDir = $srv['tmpdir'] . '/ticketscad-rate-limit';
        @mkdir($bucketDir, 0777, true);
        $poisonPath = $bucketDir . '/' . sha1($bucket) . '.bin';
        // Create the bucket file's path AS A DIRECTORY so fopen(path, 'c+b')
        // inside rate_limit_ok() cannot open it as a file.
        @mkdir($poisonPath, 0777, true);
        t('fail-open setup: bucket path exists as a directory (fopen must fail)', is_dir($poisonPath));

        $r = pb_test_http_get('http://127.0.0.1:' . $srv['port'] . '/api/public-board.php');
        t('fail-open: request still gets a real HTTP response (server did not hang/crash)', $r !== null);
        t('fail-open: request is NOT 500 (rate limiter failure does not become a server error)', $r !== null && $r['status'] !== 500);
        t('fail-open: request is NOT 429 (fails OPEN, not closed, when the limiter cannot be consulted)', $r !== null && $r['status'] !== 429);
        // Default state has the board disabled, so the expected fail-open
        // outcome is the ordinary "board not enabled" 503 — proving the
        // request reached the endpoint's normal logic PAST the rate-limit
        // check, rather than being rejected by it.
        t('fail-open: request reaches normal endpoint logic (503 board-disabled, not blocked earlier)', $r !== null && $r['status'] === 503);
    }

} finally {
    pb_test_stop_server($srv);
    if ($origRequests !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_rate_limit_requests'", [$origRequests]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_rate_limit_requests'");
    }
    if ($origWindow !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_rate_limit_window_secs'", [$origWindow]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_rate_limit_window_secs'");
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
