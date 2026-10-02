<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #4, live route
 * apply. Proves api/matrix.php's create/update/delete actions ACTUALLY
 * reach the running Python audio-matrix service immediately (not just
 * the DB, to be picked up whenever the service next restarts), and that
 * a failure to apply live rolls back the DB write and refuses with a
 * clear error rather than silently queuing the change
 * (specs/phase-152-comms-console-v2/plan.md's "no silent routes"
 * guardrail, extended from validation-time to apply-time).
 *
 * This is the one test in the whole PHP suite that boots a REAL Python
 * `services/audio-matrix/control_http.py` server as a subprocess and
 * drives api/matrix.php against it over real HTTP on both sides — the
 * PHP endpoint via an ephemeral `php -S`, the Python control plane via
 * its own real socket. "drives the real endpoint against a running
 * loopback matrix instance" per tasks.md, not a stubbed client.
 *
 * Requires: a PHP binary with `curl`, `proc_open`, and a real `python`
 * (or `python3`) on PATH able to import services/audio-matrix's stdlib-
 * only modules (matrix_core.py, control_http.py — no `websockets` or
 * `mysql-connector-python` needed for this test, since it never touches
 * the browser leg or a DB-backed channel load). Skips cleanly if either
 * prerequisite is missing.
 *
 * Usage: php tests/test_phase152_live_route_apply.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 prerequisite #4 — live route apply (api/matrix.php <-> control_http.py) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

function p152lra_find_python(): ?string {
    foreach (['python', 'python3'] as $cand) {
        $out = [];
        $rc = 1;
        @exec(escapeshellarg($cand) . ' --version 2>&1', $out, $rc);
        if ($rc === 0) { return $cand; }
    }
    return null;
}

$python = $httpCapable ? p152lra_find_python() : null;
if ($python === null) {
    echo "SKIP: no working python/python3 interpreter found on PATH\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$superRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE is_super = 1 ORDER BY id LIMIT 1");
if ($superRoleId <= 0) {
    echo "SKIP: Super Admin role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$adminUserId = 900199061;
$plainPwAdmin = 'Zz152Lra!' . mt_rand(1000, 9999);
$MATRIX_TOKEN = 'zz152-test-control-token-' . mt_rand(100000, 999999);

$createdChannelIds = [];
$createdRouteIds = [];
$savedSettings = [];

function p152lra_get_setting($prefix, $name) {
    return db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = ?", [$name]);
}
function p152lra_set_setting($prefix, $name, $value) {
    $exists = db_fetch_value("SELECT COUNT(*) FROM {$prefix}settings WHERE name = ?", [$name]);
    if ((int) $exists > 0) {
        db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$value, $name]);
    } else {
        db_query("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)", [$name, $value]);
    }
}

$cleanup = function () use ($prefix, $adminUserId, &$createdChannelIds, &$createdRouteIds, &$savedSettings) {
    foreach ($createdRouteIds as $id) { try { db_query("DELETE FROM {$prefix}comm_routes WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM {$prefix}comm_channels WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$adminUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$adminUserId]); } catch (Throwable $e) {}
    foreach ($savedSettings as $name => $prior) {
        try {
            if ($prior === false || $prior === null) {
                db_query("DELETE FROM {$prefix}settings WHERE name = ?", [$name]);
            } else {
                db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$prior, $name]);
            }
        } catch (Throwable $e) {}
    }
};
$cleanup();

// ── Ephemeral php -S helpers (mirrors test_gh139_*.php / test_phase152_console_session.php) ──
function p152lra_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152lra_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152lra_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152lra-php-' . getmypid() . '-' . mt_rand();
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

function p152lra_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152lra_rrmdir($srv['tmpdir']);
}

function p152lra_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152lra_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

// ── Real Python control_http.py subprocess, on its own ephemeral port ──
function p152lra_start_python_matrix(string $python, string $token): ?array {
    $port = p152lra_free_port();
    if ($port === null) return null;
    $audioMatrixDir = str_replace('\\', '/', NEWUI_ROOT) . '/services/audio-matrix';
    $launcher = sys_get_temp_dir() . '/tcad-p152lra-launcher-' . getmypid() . '-' . mt_rand() . '.py';
    $src = "import sys\n"
         . "sys.path.insert(0, " . var_export($audioMatrixDir, true) . ")\n"
         . "from matrix_core import MatrixCore\n"
         . "from control_http import make_control_server\n"
         . "core = MatrixCore()\n"
         . "srv = make_control_server(core, " . (int) $port . ", " . var_export($token, true) . ")\n"
         . "print('READY', flush=True)\n"
         . "srv.serve_forever()\n";
    file_put_contents($launcher, $src);

    // File-redirected stdout/stderr, never anonymous pipes with a manual
    // read loop -- tests/test_proc_open_pipe_deadlock.php (this project's
    // own guard, see CLAUDE.md's proc_open/stream_set_blocking pitfall)
    // forbids pairing proc_open() with stream_set_blocking() anywhere in
    // the tree, because stream_set_blocking() is a documented no-op on a
    // Windows proc_open pipe -- the deadline under it can never actually
    // fire, and either side filling its pipe buffer hangs the process.
    // Redirecting to a file (the same technique
    // p152lra_start_php_server() already uses for its own php -S output)
    // makes that whole class of deadlock structurally impossible.
    $outFile = sys_get_temp_dir() . '/tcad-p152lra-py-out-' . getmypid() . '-' . mt_rand() . '.log';
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'a'], 2 => ['file', $outFile, 'a']];
    $proc = @proc_open([$python, $launcher], $desc, $pipes);
    if (!is_resource($proc)) { @unlink($launcher); @unlink($outFile); return null; }
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $buf = (string) @file_get_contents($outFile);
        if (strpos($buf, 'READY') !== false) {
            return ['proc' => $proc, 'port' => $port, 'launcher' => $launcher, 'outfile' => $outFile];
        }
        usleep(50000);
    }
    @proc_terminate($proc);
    @proc_close($proc);
    @unlink($launcher);
    @unlink($outFile);
    return null;
}

function p152lra_stop_python_matrix(?array $py): void {
    if ($py === null) return;
    @proc_terminate($py['proc']);
    @proc_close($py['proc']);
    @unlink($py['launcher']);
    @unlink($py['outfile']);
}

function p152lra_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => $raw !== false ? @json_decode($raw, true) : null, 'raw' => $raw];
}

// ── login + PHP-app HTTP helpers (mirrors test_phase152_console_session.php) ──
function p152lra_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152lracookie');
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

function p152lra_csrf(string $base, string $cookieFile, string $path): ?string {
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

function p152lra_post_json(string $url, string $cookieFile, array $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}

$phpSrv = null;
$pySrv = null;

try {
    // ── settings: point PHP at the (soon-to-exist) Python control plane ──
    $savedSettings['matrix_control_url'] = p152lra_get_setting($prefix, 'matrix_control_url');
    $savedSettings['matrix_control_token'] = p152lra_get_setting($prefix, 'matrix_control_token');
    p152lra_set_setting($prefix, 'matrix_control_token', $MATRIX_TOKEN);

    // ── fixture channels + admin account ──
    $suffix = uniqid();
    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', 'ZZ152 Source', 'internal', 1, 0, 999)",
        ["zz152_lra_src_$suffix"]
    );
    $srcChanId = (int) db_insert_id();
    $createdChannelIds[] = $srcChanId;
    $srcKey = "zz152_lra_src_$suffix";

    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'test', 'ZZ152 Dest', 'internal', 1, 0, 999)",
        ["zz152_lra_dst_$suffix"]
    );
    $dstChanId = (int) db_insert_id();
    $createdChannelIds[] = $dstChanId;
    $dstKey = "zz152_lra_dst_$suffix";

    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$adminUserId, 'zz152-lra-admin', password_hash($plainPwAdmin, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$adminUserId, $superRoleId]);
    t('fixture channels + Super Admin account created', true);

    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
    } else {
        $phpSrv = p152lra_start_php_server();
        t('ephemeral php -S test server started', $phpSrv !== null);
        $pySrv = p152lra_start_python_matrix($python, $MATRIX_TOKEN);
        t('real python control_http.py subprocess started and reports READY', $pySrv !== null);

        if ($phpSrv !== null && $pySrv !== null) {
            $base = 'http://127.0.0.1:' . $phpSrv['port'];
            $pyBase = 'http://127.0.0.1:' . $pySrv['port'];
            p152lra_set_setting($prefix, 'matrix_control_url', $pyBase);

            // Load the channels into the LIVE python matrix -- the same
            // shape service.py's own load_channels() would produce.
            p152lra_curl('POST', $pyBase . '/channels', ['Authorization: Bearer ' . $MATRIX_TOKEN],
                ['id' => $srcKey, 'name' => 'ZZ152 Source', 'reg_class' => 'internal']);
            p152lra_curl('POST', $pyBase . '/channels', ['Authorization: Bearer ' . $MATRIX_TOKEN],
                ['id' => $dstKey, 'name' => 'ZZ152 Dest', 'reg_class' => 'internal']);

            $cookie = p152lra_login($base, 'zz152-lra-admin', $plainPwAdmin);
            t('admin real HTTP login succeeded', $cookie !== null);

            if ($cookie !== null) {
                $csrf = p152lra_csrf($base, $cookie, '/matrix-admin.php');

                echo "\n--- A. Happy path: create applies to the REAL live matrix immediately ---\n\n";
                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'create', 'csrf_token' => $csrf,
                    'src_channel_id' => $srcChanId, 'dst_channel_id' => $dstChanId,
                    'gain_db' => 0, 'priority' => 0,
                ]);
                t('create returns HTTP 200', $r['status'] === 200);
                $routeId = $r['json']['id'] ?? null;
                $createdRouteIds[] = (int) $routeId;

                $pyRoutes = p152lra_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $found = false;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $srcKey && ($pr['dst'] ?? null) === $dstKey) { $found = true; }
                }
                t('the REAL Python matrix service now has this route live (not just in the DB)', $found);

                echo "\n--- B. Update applies live too ---\n\n";
                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'update', 'csrf_token' => $csrf, 'id' => $routeId, 'gain_db' => -9.0,
                ]);
                t('update returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152lra_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $updatedGain = null;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $srcKey && ($pr['dst'] ?? null) === $dstKey) { $updatedGain = $pr['gain_db'] ?? null; }
                }
                t('the live matrix reflects the new gain_db (-9.0), not the create-time value',
                    abs(((float) $updatedGain) - (-9.0)) < 0.01);

                echo "\n--- C. Delete applies live too ---\n\n";
                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'delete', 'csrf_token' => $csrf, 'id' => $routeId,
                ]);
                t('delete returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152lra_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $stillThere = false;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $srcKey && ($pr['dst'] ?? null) === $dstKey) { $stillThere = true; }
                }
                t('the route is GONE from the live matrix, not just the DB', !$stillThere);
                array_pop($createdRouteIds);

                echo "\n--- D. Fail-closed: control plane unreachable -> DB write rolled back ---\n\n";
                p152lra_stop_python_matrix($pySrv);
                $pySrv = null;
                usleep(300000);   // let the socket actually release

                $countBefore = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}comm_routes WHERE src_channel_id = ? AND dst_channel_id = ?", [$srcChanId, $dstChanId]);
                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'create', 'csrf_token' => $csrf,
                    'src_channel_id' => $srcChanId, 'dst_channel_id' => $dstChanId,
                ]);
                t('create with the control plane DOWN returns HTTP 502', $r['status'] === 502);
                t('the 502 names "matrix service unreachable"', stripos((string) ($r['json']['error'] ?? ''), 'matrix service unreachable') !== false);
                $countAfter = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}comm_routes WHERE src_channel_id = ? AND dst_channel_id = ?", [$srcChanId, $dstChanId]);
                t('NO orphan DB row was left behind (rolled back cleanly)', $countAfter === $countBefore);

                // Recreate a route directly via DB (bypassing the live-apply
                // path, since the python service is intentionally down for
                // this section) so update/delete-while-down have something
                // real to operate on.
                db_query(
                    "INSERT INTO {$prefix}comm_routes (src_channel_id, dst_channel_id, gain_db, priority, ducking, enabled, allow_cross_class)
                     VALUES (?, ?, 1.5, 7, 1, 1, 0)",
                    [$srcChanId, $dstChanId]
                );
                $offlineRouteId = (int) db_insert_id();
                $createdRouteIds[] = $offlineRouteId;

                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'update', 'csrf_token' => $csrf, 'id' => $offlineRouteId, 'gain_db' => 3.0,
                ]);
                t('update with the control plane DOWN returns HTTP 502', $r['status'] === 502);
                $row = db_fetch_one("SELECT gain_db FROM {$prefix}comm_routes WHERE id = ?", [$offlineRouteId]);
                t('the row\'s gain_db was rolled back to its ORIGINAL value (1.5), not left at the failed new value',
                    $row && abs(((float) $row['gain_db']) - 1.5) < 0.01);

                $r = p152lra_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'delete', 'csrf_token' => $csrf, 'id' => $offlineRouteId,
                ]);
                t('delete with the control plane DOWN returns HTTP 502', $r['status'] === 502);
                $stillExists = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}comm_routes WHERE id = ?", [$offlineRouteId]);
                t('the row was NEVER deleted from the DB (live-apply happens before the DB delete)', $stillExists === 1);

                @unlink($cookie);
            }
        }
    }

} finally {
    p152lra_stop_php_server($phpSrv);
    p152lra_stop_python_matrix($pySrv);
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
