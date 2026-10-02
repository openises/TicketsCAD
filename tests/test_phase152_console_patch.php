<?php
/**
 * Phase 152 (Console rebuild) — api/console-patch.php, the dispatcher-
 * facing endpoint Select/Monitor/Volume/PTT call to connect a console
 * session's own browser-leg channel (`browser:<console_sessions.id>`) to
 * a matrix-backed strip channel.
 *
 * Same real-HTTP, real-Python harness as tests/test_phase152_live_route_
 * apply.php (this project's own established pattern for this subsystem —
 * a stubbed control-client would never catch a real wire-format mismatch
 * between PHP and the Python control plane) — a REAL control_http.py
 * subprocess plus a REAL `php -S` server, driven over real HTTP with a
 * real admin login for api/console-session.php's own session mint, then a
 * real (non-admin, action.patch_create-only) Dispatcher account for
 * api/console-patch.php itself, proving the RBAC split actually holds.
 *
 * Requires: proc_open/curl, and a working python/python3 on PATH able to
 * import services/audio-matrix's stdlib-only modules. Skips cleanly if
 * either prerequisite is missing.
 *
 * Usage: php tests/test_phase152_console_patch.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- api/console-patch.php (browser-leg ephemeral routes) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

function p152cp_find_python(): ?string {
    foreach (['python', 'python3'] as $cand) {
        $out = []; $rc = 1;
        @exec(escapeshellarg($cand) . ' --version 2>&1', $out, $rc);
        if ($rc === 0) { return $cand; }
    }
    return null;
}

$python = $httpCapable ? p152cp_find_python() : null;
if ($python === null) {
    echo "SKIP: no working python/python3 interpreter found on PATH\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispatcherRoleId = (int) db_fetch_value(
    "SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1"
);
if ($dispatcherRoleId <= 0) {
    echo "SKIP: Dispatcher role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispatcherUserId = 900199091;
$plainPwDispatcher = 'Zz152Cp!' . mt_rand(1000, 9999);
$MATRIX_TOKEN = 'zz152-cp-test-control-token-' . mt_rand(100000, 999999);

$createdChannelIds = [];
$createdSessionIds = [];
$savedSettings = [];

function p152cp_get_setting($prefix, $name) {
    return db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = ?", [$name]);
}
function p152cp_set_setting($prefix, $name, $value) {
    $exists = db_fetch_value("SELECT COUNT(*) FROM {$prefix}settings WHERE name = ?", [$name]);
    if ((int) $exists > 0) {
        db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$value, $name]);
    } else {
        db_query("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)", [$name, $value]);
    }
}

$cleanup = function () use ($prefix, $dispatcherUserId, &$createdChannelIds, &$createdSessionIds, &$savedSettings) {
    foreach ($createdSessionIds as $id) { try { db_query("DELETE FROM {$prefix}console_sessions WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM {$prefix}comm_channels WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$dispatcherUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$dispatcherUserId]); } catch (Throwable $e) {}
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

// ── Ephemeral php -S + real Python control_http.py (mirrors test_phase152_live_route_apply.php verbatim) ──
function p152cp_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152cp_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152cp_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152cp-php-' . getmypid() . '-' . mt_rand();
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

function p152cp_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152cp_rrmdir($srv['tmpdir']);
}

function p152cp_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152cp_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152cp_start_python_matrix(string $python, string $token): ?array {
    $port = p152cp_free_port();
    if ($port === null) return null;
    $audioMatrixDir = str_replace('\\', '/', NEWUI_ROOT) . '/services/audio-matrix';
    $launcher = sys_get_temp_dir() . '/tcad-p152cp-launcher-' . getmypid() . '-' . mt_rand() . '.py';
    $src = "import sys\n"
         . "sys.path.insert(0, " . var_export($audioMatrixDir, true) . ")\n"
         . "from matrix_core import MatrixCore\n"
         . "from control_http import make_control_server\n"
         . "core = MatrixCore()\n"
         . "srv = make_control_server(core, " . (int) $port . ", " . var_export($token, true) . ")\n"
         . "print('READY', flush=True)\n"
         . "srv.serve_forever()\n";
    file_put_contents($launcher, $src);
    $outFile = sys_get_temp_dir() . '/tcad-p152cp-py-out-' . getmypid() . '-' . mt_rand() . '.log';
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

function p152cp_stop_python_matrix(?array $py): void {
    if ($py === null) return;
    @proc_terminate($py['proc']);
    @proc_close($py['proc']);
    @unlink($py['launcher']);
    @unlink($py['outfile']);
}

function p152cp_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => $raw !== false ? @json_decode($raw, true) : null, 'raw' => $raw];
}

function p152cp_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152cpcookie');
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

function p152cp_csrf(string $base, string $cookieFile, string $path): ?string {
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

function p152cp_post_json(string $url, string $cookieFile, array $body): array {
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

$phpSrv = null;
$pySrv = null;

try {
    $savedSettings['matrix_control_url'] = p152cp_get_setting($prefix, 'matrix_control_url');
    $savedSettings['matrix_control_token'] = p152cp_get_setting($prefix, 'matrix_control_token');
    p152cp_set_setting($prefix, 'matrix_control_token', $MATRIX_TOKEN);

    $suffix = uniqid();
    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order, capabilities_json)
         VALUES (?, 'test', 'ZZ152CP Test DMR', 'internal', 1, 0, 999, ?)",
        ["zz152cp_dmr_$suffix", json_encode(['voice_tx' => true, 'voice_rx' => true])]
    );
    $dmrChanId = (int) db_insert_id();
    $createdChannelIds[] = $dmrChanId;
    $dmrKey = "zz152cp_dmr_$suffix";

    db_query(
        "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
         VALUES (?, 'intercom_dd', 'ZZ152CP Test Intercom', 'internal', 1, 0, 999)",
        ["zz152cp_intercom_$suffix"]
    );
    $intercomChanId = (int) db_insert_id();
    $createdChannelIds[] = $intercomChanId;

    // A Dispatcher-tier account -- proves action.patch_create is the
    // OPERATIVE gate (not accidentally requiring action.manage_matrix, the
    // admin-only permission api/matrix.php uses).
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$dispatcherUserId, 'zz152cp-dispatcher', password_hash($plainPwDispatcher, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$dispatcherUserId, $dispatcherRoleId]);
    t('fixture channels + Dispatcher-tier account created', true);

    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
    } else {
        $phpSrv = p152cp_start_php_server();
        t('ephemeral php -S test server started', $phpSrv !== null);
        $pySrv = p152cp_start_python_matrix($python, $MATRIX_TOKEN);
        t('real python control_http.py subprocess started and reports READY', $pySrv !== null);

        if ($phpSrv !== null && $pySrv !== null) {
            $base = 'http://127.0.0.1:' . $phpSrv['port'];
            $pyBase = 'http://127.0.0.1:' . $pySrv['port'];
            p152cp_set_setting($prefix, 'matrix_control_url', $pyBase);

            p152cp_curl('POST', $pyBase . '/channels', ['Authorization: Bearer ' . $MATRIX_TOKEN],
                ['id' => $dmrKey, 'name' => 'ZZ152CP Test DMR', 'reg_class' => 'internal']);

            $cookie = p152cp_login($base, 'zz152cp-dispatcher', $plainPwDispatcher);
            t('dispatcher real HTTP login succeeded', $cookie !== null);

            if ($cookie !== null) {
                $csrf = p152cp_csrf($base, $cookie, '/console.php');

                // Mint a real console session (the same endpoint console-mic.js
                // will call) and register its browser-leg channel in the live
                // matrix -- mirroring what BrowserLeg's WS accept path would
                // do on a real connection (add_channel before any route).
                $sr = p152cp_post_json($base . '/api/console-session.php', $cookie, [
                    'action' => 'create', 'csrf_token' => $csrf,
                ]);
                t('console-session create returns HTTP 200 with a session_token', $sr['status'] === 200 && !empty($sr['json']['session_token']));
                $sessionToken = $sr['json']['session_token'] ?? '';
                $sessionId = (int) db_fetch_value(
                    "SELECT id FROM {$prefix}console_sessions WHERE session_token = ?", [$sessionToken]
                );
                $createdSessionIds[] = $sessionId;
                $myChannel = 'browser:' . $sessionId;
                p152cp_curl('POST', $pyBase . '/channels', ['Authorization: Bearer ' . $MATRIX_TOKEN],
                    ['id' => $myChannel, 'name' => 'zz152cp-dispatcher', 'reg_class' => 'internal']);

                echo "\n--- A. connect(listen): DMR -> me, live route appears ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'direction' => 'listen', 'gain_db' => -3.0,
                ]);
                t('connect(listen) returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152cp_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $found = null;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $dmrKey && ($pr['dst'] ?? null) === $myChannel) { $found = $pr; }
                }
                t('the REAL matrix now has DMR -> browser:<session> live, NOT written to comm_routes',
                    $found !== null
                    && (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}comm_routes WHERE src_channel_id = ?", [$dmrChanId]) === 0);
                t('the route carries the requested gain_db (-3.0)', $found && abs(((float) $found['gain_db']) - (-3.0)) < 0.01);

                echo "\n--- B. connect is idempotent: reconnecting the SAME pair is not an error ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'direction' => 'listen',
                ]);
                t('re-connecting an already-connected listen pair still returns HTTP 200', $r['status'] === 200);

                echo "\n--- C. set_gain updates the live route's gain ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'set_gain', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'gain_db' => 6.0,
                ]);
                t('set_gain returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152cp_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $newGain = null;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $dmrKey && ($pr['dst'] ?? null) === $myChannel) { $newGain = $pr['gain_db'] ?? null; }
                }
                t('the live route reflects the new gain (6.0)', $newGain !== null && abs(((float) $newGain) - 6.0) < 0.01);

                echo "\n--- D. connect(talk): me -> DMR (PTT), live route appears the OTHER direction ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'direction' => 'talk',
                ]);
                t('connect(talk) returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152cp_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $talkFound = false;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $myChannel && ($pr['dst'] ?? null) === $dmrKey) { $talkFound = true; }
                }
                t('the live matrix now ALSO has browser:<session> -> DMR (full duplex, two distinct rows)', $talkFound);

                echo "\n--- E. disconnect removes exactly the one direction asked for ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'disconnect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'direction' => 'talk',
                ]);
                t('disconnect(talk) returns HTTP 200', $r['status'] === 200);
                $pyRoutes = p152cp_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $talkGone = true; $listenStill = false;
                foreach ((array) $pyRoutes as $pr) {
                    if (($pr['src'] ?? null) === $myChannel && ($pr['dst'] ?? null) === $dmrKey) { $talkGone = false; }
                    if (($pr['src'] ?? null) === $dmrKey && ($pr['dst'] ?? null) === $myChannel) { $listenStill = true; }
                }
                t('the talk route is gone', $talkGone);
                t('the UNRELATED listen route is untouched', $listenStill);

                echo "\n--- F. disconnect is idempotent: disconnecting an already-gone route is not an error ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'disconnect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $dmrChanId, 'direction' => 'talk',
                ]);
                t('disconnecting an already-gone route still returns HTTP 200 (idempotent, not a 404 error)', $r['status'] === 200);

                echo "\n--- G. the intercom_dd leaf rule refuses, even for a dispatcher's OWN mic ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => $intercomChanId, 'direction' => 'listen',
                ]);
                t('connecting to the intercom_dd channel is REFUSED', $r['status'] === 400
                    && stripos((string) ($r['json']['error'] ?? ''), 'intercom') !== false);

                echo "\n--- H. session_token ownership: a token that is not mine is refused ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => 'not-a-real-token-' . uniqid(),
                    'channel_id' => $dmrChanId, 'direction' => 'listen',
                ]);
                t('an unknown/foreign session_token is refused with 401, not silently accepted', $r['status'] === 401);

                echo "\n--- I. unknown channel is refused ---\n\n";
                $r = p152cp_post_json($base . '/api/console-patch.php', $cookie, [
                    'action' => 'connect', 'csrf_token' => $csrf, 'session_token' => $sessionToken,
                    'channel_id' => 900199999, 'direction' => 'listen',
                ]);
                t('an unknown channel_id is refused', $r['status'] === 400);

                @unlink($cookie);
            }

            echo "\n--- J. RBAC: action.patch_create is the operative gate, not action.manage_matrix ---\n\n";
            // A plain Read-Only account (no action.patch_create) must be
            // refused, proving the endpoint isn't accidentally open to every
            // screen.console holder.
            $readOnlyRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name LIKE 'Read%Only%' LIMIT 1");
            if ($readOnlyRoleId > 0) {
                $roUserId = 900199092;
                $plainPwRo = 'Zz152CpRo!' . mt_rand(1000, 9999);
                db_query(
                    "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
                    [$roUserId, 'zz152cp-readonly', password_hash($plainPwRo, PASSWORD_BCRYPT)]
                );
                db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$roUserId, $readOnlyRoleId]);
                $roCookie = p152cp_login($base, 'zz152cp-readonly', $plainPwRo);
                if ($roCookie !== null) {
                    $roCsrf = p152cp_csrf($base, $roCookie, '/console.php');
                    $r = p152cp_post_json($base . '/api/console-patch.php', $roCookie, [
                        'action' => 'connect', 'csrf_token' => $roCsrf ?? '', 'session_token' => 'irrelevant',
                        'channel_id' => $dmrChanId, 'direction' => 'listen',
                    ]);
                    t('a Read-Only account (no action.patch_create) is refused with 403', $r['status'] === 403);
                    @unlink($roCookie);
                } else {
                    t('a Read-Only account (no action.patch_create) is refused with 403', false);
                }
                try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$roUserId]); } catch (Throwable $e) {}
                try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$roUserId]); } catch (Throwable $e) {}
            } else {
                echo "SKIP: no Read-Only-shaped role found for the RBAC-refusal check\n";
            }
        }
    }

} finally {
    p152cp_stop_php_server($phpSrv);
    p152cp_stop_python_matrix($pySrv);
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
