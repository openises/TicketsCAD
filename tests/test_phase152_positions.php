<?php
/**
 * Phase 152 (Console rebuild) — the thin position layer:
 * sql/run_phase152_positions.php's three tables, inc/console-positions.php's
 * business rules (driven directly, Part 1), and api/console-positions.php's
 * RBAC + wiring over real HTTP (Part 2 — a lightweight `php -S` harness, no
 * Python subprocess needed: this feature never touches the live audio
 * matrix, unlike api/console-patch.php / api/matrix.php's own Phase 152
 * test files).
 *
 * Staleness is proven by BACKDATING last_heartbeat_at via direct SQL
 * (this project's own established technique for testing time-based
 * behavior without sleeping the runner — Phase 143's read-time-expiry
 * test, GH#64's interval-math test), never by waiting in real time.
 *
 * Usage: php tests/test_phase152_positions.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/console-positions.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- the thin position layer ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$suffix = uniqid();

$createdChannelIds = [];
$createdPositionIds = [];
$userIdA = 900199191;
$userIdB = 900199192;
$plainPwA = 'Zz152Pos!' . mt_rand(1000, 9999);
$plainPwB = 'Zz152PosB!' . mt_rand(1000, 9999);

$cleanup = function () use ($prefix, &$createdChannelIds, &$createdPositionIds, $userIdA, $userIdB) {
    foreach ($createdPositionIds as $id) {
        try { db_query("DELETE FROM {$prefix}console_position_sessions WHERE position_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}comm_channel_reads WHERE position_id = ?", [$id]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}console_positions WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM {$prefix}comm_channels WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ([$userIdA, $userIdB] as $uid) {
        try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$uid]); } catch (Throwable $e) {}
    }
};
$cleanup();

try {
    // ── Fixtures ──────────────────────────────────────────────────
    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', 'ZZ152POS Channel A', 'internal', 1, 0, 999)",
        ["zz152pos_a_$suffix"]
    );
    $chanA = (int) db_insert_id();
    $createdChannelIds[] = $chanA;
    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', 'ZZ152POS Channel B', 'internal', 1, 0, 999)",
        ["zz152pos_b_$suffix"]
    );
    $chanB = (int) db_insert_id();
    $createdChannelIds[] = $chanB;
    t('fixture channels created', $chanA > 0 && $chanB > 0);

    echo "\n--- 1. console_position_validate_channel_ids() ---\n\n";
    $clean = console_position_validate_channel_ids([$chanA, $chanB, $chanA]);
    t('valid ids pass through, deduplicated', $clean === [$chanA, $chanB]);
    $threw = false;
    try { console_position_validate_channel_ids([$chanA, 900199999]); } catch (Exception $e) { $threw = true; }
    t('an unknown channel id throws rather than being silently dropped', $threw);

    echo "\n--- 2. Position CRUD ---\n\n";
    $posId = console_position_create(['label' => 'ZZ152POS Dispatch 1', 'default_channel_ids' => [$chanA, $chanB], 'sort_order' => 5]);
    $createdPositionIds[] = $posId;
    t('create returns a real id', $posId > 0);
    $row = console_position_get_row($posId);
    t('get_row round-trips label + channel set', $row['label'] === 'ZZ152POS Dispatch 1' && $row['default_channel_ids'] === [$chanA, $chanB]);
    $threw = false;
    try { console_position_create(['label' => '', 'default_channel_ids' => []]); } catch (Exception $e) { $threw = true; }
    t('an empty label is refused', $threw);

    console_position_update($posId, ['label' => 'ZZ152POS Dispatch 1 (renamed)', 'default_channel_ids' => [$chanA]]);
    $row = console_position_get_row($posId);
    t('update renames + narrows the channel set', $row['label'] === 'ZZ152POS Dispatch 1 (renamed)' && $row['default_channel_ids'] === [$chanA]);

    $listed = console_positions_list();
    $found = false;
    foreach ($listed as $p) { if ((int) $p['id'] === $posId) { $found = true; } }
    t('the position appears in console_positions_list()', $found);

    echo "\n--- 3. Log in / log out: one seat per user, no forced takeover ---\n\n";
    db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$userIdA, 'zz152pos-a', password_hash($plainPwA, PASSWORD_BCRYPT)]);
    db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$userIdB, 'zz152pos-b', password_hash($plainPwB, PASSWORD_BCRYPT)]);

    $secondPosId = console_position_create(['label' => 'ZZ152POS Net Control', 'default_channel_ids' => [$chanB]]);
    $createdPositionIds[] = $secondPosId;

    console_position_log_in($posId, $userIdA, 'zz152pos-a');
    $current = console_position_current_for_user($userIdA);
    t('user A is now logged into position 1', $current && (int) $current['position_id'] === $posId);

    console_position_log_in($secondPosId, $userIdA, 'zz152pos-a');
    $current = console_position_current_for_user($userIdA);
    t('logging into a NEW position closes the user\'s OTHER open session first', $current && (int) $current['position_id'] === $secondPosId);
    $stillOpenOnFirst = db_fetch_value(
        "SELECT COUNT(*) FROM {$prefix}console_position_sessions WHERE position_id = ? AND user_id = ? AND logged_out_at IS NULL",
        [$posId, $userIdA]
    );
    t('the old session on position 1 is now closed (logged_out_at set)', (int) $stillOpenOnFirst === 0);

    console_position_log_in($secondPosId, $userIdB, 'zz152pos-b');
    $presence = console_positions_presence();
    $onSecond = array_filter($presence, function ($p) use ($secondPosId) { return (int) $p['position_id'] === $secondPosId; });
    t('logging user B into a position user A already occupies does NOT evict A -- both show present (no forced takeover)', count($onSecond) === 2);

    console_position_log_out_all($userIdB);
    $current = console_position_current_for_user($userIdB);
    t('log_out_all clears the user\'s open session', $current === null);

    echo "\n--- 4. Heartbeat + staleness (backdated, never sleeps the runner) ---\n\n";
    console_position_heartbeat($userIdA);
    $row = db_fetch_one("SELECT last_heartbeat_at FROM {$prefix}console_position_sessions WHERE user_id = ? AND logged_out_at IS NULL", [$userIdA]);
    t('heartbeat bumped last_heartbeat_at to (approximately) now',
        $row && (time() - strtotime($row['last_heartbeat_at'])) < 5);

    $presence = console_positions_presence();
    $mine = null;
    foreach ($presence as $p) { if ((int) $p['user_id'] === $userIdA) { $mine = $p; } }
    t('a fresh heartbeat is NOT reported stale', $mine && $mine['stale'] === false);

    db_query(
        "UPDATE {$prefix}console_position_sessions SET last_heartbeat_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE user_id = ? AND logged_out_at IS NULL",
        [CONSOLE_POSITION_STALE_AFTER_SECONDS + 30, $userIdA]
    );
    $presence = console_positions_presence();
    $mine = null;
    foreach ($presence as $p) { if ((int) $p['user_id'] === $userIdA) { $mine = $p; } }
    t('a heartbeat older than 2x the interval IS reported stale', $mine && $mine['stale'] === true);
    t('staleness is reported, never acted on -- the row is still logged_out_at IS NULL (no auto-eviction)',
        (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}console_position_sessions WHERE user_id = ? AND logged_out_at IS NULL", [$userIdA]) === 1);

    console_position_heartbeat($userIdA); // restore freshness for the next section

    echo "\n--- 5. Clear at handoff: authorization boundary ---\n\n";
    $threw = false;
    try {
        // userIdB is not the current occupant of $secondPosId (logged out above) and has no admin override.
        console_channel_reads_clear_handoff($secondPosId, $userIdB, false);
    } catch (Exception $e) { $threw = true; }
    t('a non-occupant with no admin override cannot clear a position at handoff', $threw);

    console_position_log_in($secondPosId, $userIdB, 'zz152pos-b');
    console_channel_reads_clear_handoff($secondPosId, $userIdB, false);
    $reads = console_channel_reads_for_position($secondPosId);
    t('the current occupant CAN clear their own position, resetting every default channel',
        count($reads) === 1 && (int) $reads[0]['channel_id'] === $chanB);

    console_position_log_out_all($userIdB);
    $threw = false;
    try {
        console_channel_reads_clear_handoff($secondPosId, $userIdB, true); // admin override
    } catch (Exception $e) { $threw = true; }
    t('an admin override (action.manage_positions) can clear a position even when not currently occupying it', !$threw);

    echo "\n--- 6. Delete cleans up dependent rows (no FK constraint, so this must be explicit) ---\n\n";
    console_position_log_in($secondPosId, $userIdA, 'zz152pos-a');
    console_position_delete($secondPosId);
    t('the position row is gone', console_position_get_row($secondPosId) === null);
    t('its open position_sessions were force-closed, not left dangling',
        (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}console_position_sessions WHERE position_id = ? AND logged_out_at IS NULL", [$secondPosId]) === 0);
    t('its comm_channel_reads rows were dropped', count(console_channel_reads_for_position($secondPosId)) === 0);
    array_splice($createdPositionIds, array_search($secondPosId, $createdPositionIds), 1);

    console_position_delete($posId);
    array_splice($createdPositionIds, array_search($posId, $createdPositionIds), 1);

    // ── Part 2: api/console-positions.php over real HTTP ──────────────
    echo "\n--- 7. api/console-positions.php: RBAC + basic wiring over real HTTP ---\n\n";
    $httpCapable = function_exists('proc_open') && function_exists('curl_init');
    if (!$httpCapable) {
        echo "SKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
    } else {
        $dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
        $orgAdminRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Org Admin' LIMIT 1");
        if ($dispatcherRoleId <= 0 || $orgAdminRoleId <= 0) {
            echo "SKIP: Dispatcher/Org Admin roles not found — run sql/run_00_rbac.php first\n";
        } else {
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdA, $dispatcherRoleId]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdB, $orgAdminRoleId]);

            $srv = p152pos_start_php_server();
            t('ephemeral php -S test server started', $srv !== null);
            if ($srv !== null) {
                $base = 'http://127.0.0.1:' . $srv['port'];

                $adminPosId = console_position_create(['label' => 'ZZ152POS HTTP Test', 'default_channel_ids' => [$chanA]]);
                $createdPositionIds[] = $adminPosId;

                $cookieA = p152pos_login($base, 'zz152pos-a', $plainPwA);
                t('Dispatcher (userA) real HTTP login succeeded', $cookieA !== null);
                $cookieB = p152pos_login($base, 'zz152pos-b', $plainPwB);
                t('Org Admin (userB, action.manage_positions) real HTTP login succeeded', $cookieB !== null);

                if ($cookieA !== null && $cookieB !== null) {
                    $csrfA = p152pos_csrf($base, $cookieA, '/console.php');
                    $csrfB = p152pos_csrf($base, $cookieB, '/console-positions-admin.php');

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'create', 'csrf_token' => $csrfA, 'label' => 'ZZ152POS Should Fail', 'default_channel_ids' => [],
                    ]);
                    t('a Dispatcher (no action.manage_positions) is refused create with 403', $r['status'] === 403);

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieB, [
                        'action' => 'update', 'csrf_token' => $csrfB, 'id' => $adminPosId, 'label' => 'ZZ152POS HTTP Test (renamed)',
                    ]);
                    t('an Org Admin (holds action.manage_positions) CAN update a position', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_get($base . '/api/console-positions.php', $cookieA);
                    t('GET (any screen.console holder) returns the position list + presence + my_position', $r['status'] === 200
                        && isset($r['json']['positions']) && isset($r['json']['presence']) && array_key_exists('my_position', $r['json']));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'log_in', 'csrf_token' => $csrfA, 'position_id' => $adminPosId,
                    ]);
                    t('any screen.console holder (Dispatcher) can log into a position', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_get($base . '/api/console-positions.php', $cookieA);
                    t('after logging in, GET reports my_position correctly', $r['status'] === 200 && (int) ($r['json']['my_position'] ?? 0) === $adminPosId);
                    $presenceUsernames = array_map(function ($p) { return $p['username']; }, $r['json']['presence'] ?? []);
                    t('the presence list includes the logged-in dispatcher', in_array('zz152pos-a', $presenceUsernames, true));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'heartbeat', 'csrf_token' => $csrfA,
                    ]);
                    t('heartbeat returns HTTP 200', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'clear_handoff', 'csrf_token' => $csrfA, 'position_id' => $adminPosId,
                    ]);
                    t('the current occupant can clear their own position at handoff over real HTTP', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieB, [
                        'action' => 'clear_handoff', 'csrf_token' => $csrfB, 'position_id' => $adminPosId,
                    ]);
                    t('an admin (action.manage_positions) can ALSO clear a position they do not occupy', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'log_out', 'csrf_token' => $csrfA,
                    ]);
                    t('log_out returns HTTP 200', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieA, [
                        'action' => 'delete', 'csrf_token' => $csrfA, 'id' => $adminPosId,
                    ]);
                    t('a Dispatcher (no action.manage_positions) is refused delete with 403', $r['status'] === 403);

                    $r = p152pos_post_json($base . '/api/console-positions.php', $cookieB, [
                        'action' => 'delete', 'csrf_token' => $csrfB, 'id' => $adminPosId,
                    ]);
                    t('an Org Admin CAN delete the position', $r['status'] === 200 && !empty($r['json']['ok']));
                    array_splice($createdPositionIds, array_search($adminPosId, $createdPositionIds), 1);

                    @unlink($cookieA);
                    @unlink($cookieB);
                }
            }
            p152pos_stop_php_server($srv);
        }
    }
} finally {
    $cleanup();
}

echo "\n--- 8. Frontend wiring (source-level, this feature's own established style) ---\n\n";
$page = (string) @file_get_contents(__DIR__ . '/../console.php');
t('console.php renders the position bar container', strpos($page, 'id="consolePositionBar"') !== false);
t('console.php gates the Positions admin toolbar link on action.manage_positions',
    strpos($page, "\$can_positions_admin = rbac_can('action.manage_positions')") !== false
    && strpos($page, 'console-positions-admin.php') !== false);
t('console-positions.js loads on console.php', strpos($page, 'console-positions.js') !== false);

$pjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-positions.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $pjs));
t('the bar is hidden entirely when there are zero positions (additive, not a mode switch)',
    strpos($pjs, "if (!state.positions.length) {\n            barEl.classList.add('d-none');") !== false);
t('heartbeat piggybacks the console-mic session token when available, but does not require it',
    strpos($pjs, 'window.ConsoleMatrix.getSessionToken') !== false);

$mjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-mic.js');
t('console-mic.js exposes getSessionToken() for the position layer to piggyback on', strpos($mjs, 'getSessionToken: function ()') !== false);

$adminPage = (string) @file_get_contents(__DIR__ . '/../console-positions-admin.php');
t('console-positions-admin.php gates on action.manage_positions', strpos($adminPage, "rbac_can('action.manage_positions')") !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

// ── Lightweight php -S harness (no Python -- this feature never touches
//    the live audio matrix, unlike console-patch.php/matrix.php's own
//    Phase 152 test files) ─────────────────────────────────────────────
function p152pos_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152pos_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152pos_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152pos-php-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
    $logdir = $tmpdir . '/logs';
    @mkdir($logdir, 0777, true);
    $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
    $env = array_merge($_ENV ?: [], getenv() ?: []);
    $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
    $proc = @proc_open([$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot], $desc, $pipes, $docroot, $env);
    if (!is_resource($proc)) return null;
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if (is_resource($c)) { fclose($c); return ['proc' => $proc, 'port' => $port, 'tmpdir' => $tmpdir]; }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    return null;
}

function p152pos_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152pos_rrmdir($srv['tmpdir']);
}

function p152pos_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152pos_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152pos_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152poscookie');
    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);
    if ($html === false) return null;
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', (string) $html, $m);
    $csrf = $m[1] ?? '';
    $ch = curl_init($base . '/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $username, 'password' => $password, 'csrf_token' => $csrf]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return null;
    if ($code === 302 || $code === 301) { return $cookieFile; }
    @unlink($cookieFile);
    return null;
}

function p152pos_csrf(string $base, string $cookieFile, string $path): ?string {
    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html)) return null;
    if (!preg_match('/name="csrf-token"\s+content="([^"]+)"/', $html, $m)) return null;
    return $m[1];
}

function p152pos_post_json(string $url, string $cookieFile, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}

function p152pos_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}
