<?php
/**
 * Phase 152 (Communications Console v2) — prerequisite #3, api/console-
 * session.php over REAL HTTP: mints/revokes the token a browser tab uses
 * to authenticate its WebSocket connection to the audio-matrix service's
 * dynamic browser leg (services/audio-matrix/legs/browser.py,
 * services/audio-matrix/tests/test_browser_leg.py covers the Python side
 * of the same handshake with a canned validator standing in for this
 * table).
 *
 * Drives the REAL endpoint over REAL HTTP (ephemeral `php -S` rooted at
 * NEWUI_ROOT, matching tests/test_gh139_map_overlay_categories_rbac.php's
 * established pattern) via a REAL login.php CSRF+cookie+session flow.
 * Proves: screen.console is required (a Field Unit without it is
 * refused), a created row lands in console_sessions with the right
 * user_id/username/expires_at, the returned session_token round-trips
 * against the DB, revoke actually sets revoked_at, and a user cannot
 * revoke another user's session (the IDOR check named in this file's own
 * docblock).
 *
 * NOT @requires-http — spins up its own local PHP server, never touches a
 * live Apache/localhost install. Needs a reachable MySQL/MariaDB.
 *
 * Usage: php tests/test_phase152_console_session.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 — api/console-session.php (prerequisite #3) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

$dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
$fieldRoleId      = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Field Unit' LIMIT 1");
if ($dispatcherRoleId <= 0 || $fieldRoleId <= 0) {
    echo "SKIP: Dispatcher or Field Unit role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispA = 900199051;  // holds screen.console
$dispB = 900199052;  // holds screen.console — used to prove no cross-user revoke
$field = 900199053;  // does NOT hold screen.console

$pwA = 'Zz152CsA!' . mt_rand(1000, 9999);
$pwB = 'Zz152CsB!' . mt_rand(1000, 9999);
$pwF = 'Zz152CsF!' . mt_rand(1000, 9999);

$cleanup = function () use ($prefix, $dispA, $dispB, $field) {
    try { db_query("DELETE FROM {$prefix}console_sessions WHERE user_id IN (?, ?, ?)", [$dispA, $dispB, $field]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?, ?)", [$dispA, $dispB, $field]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id IN (?, ?, ?)", [$dispA, $dispB, $field]); } catch (Throwable $e) {}
};
$cleanup();

// ── Ephemeral php -S server + HTTP helpers (mirrors test_gh139_*.php) ──
function p152cs_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152cs_start_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152cs_free_port();
    if ($port === null) return null;

    $tmpdir = sys_get_temp_dir() . '/tcad-p152cs-' . getmypid() . '-' . mt_rand();
    if (!@mkdir($tmpdir, 0777, true) && !is_dir($tmpdir)) return null;
    $logdir = $tmpdir . '/logs';
    @mkdir($logdir, 0777, true);

    $docroot = rtrim(str_replace('\\', '/', NEWUI_ROOT), '/');
    $env = array_merge($_ENV ?: [], getenv() ?: []);

    $desc = [1 => ['file', $logdir . '/out.log', 'a'], 2 => ['file', $logdir . '/err.log', 'a']];
    $proc = @proc_open(
        [$bin, '-S', '127.0.0.1:' . $port, '-t', $docroot],
        $desc, $pipes, $docroot, $env
    );
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

function p152cs_stop_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152cs_rrmdir($srv['tmpdir']);
}

function p152cs_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152cs_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152cs_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152cscookie');

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
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username'   => $username,
        'password'   => $password,
        'csrf_token' => $csrf,
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $loginResp = curl_exec($ch);
    $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($loginResp === false) return null;

    if ($loginCode === 302 || $loginCode === 301) {
        preg_match('/Location:\s*(.+)/i', (string) $loginResp, $locMatch);
        if (!empty($locMatch[1])) {
            $redirectUrl = trim($locMatch[1]);
            if (strpos($redirectUrl, 'http') !== 0) {
                $redirectUrl = $base . '/' . ltrim($redirectUrl, '/');
            }
            $ch = curl_init($redirectUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_exec($ch);
            curl_close($ch);
        }
        return $cookieFile;
    }

    @unlink($cookieFile);
    return null;
}

// A page's own <meta name="csrf-token"> is the same per-session token every
// POST endpoint on this app checks (inc/functions.php's csrf_token()/
// csrf_verify() are session-scoped, not per-page) — console.php is the
// natural page to pull it from for a console-session test.
function p152cs_page_csrf(string $base, string $cookieFile, string $path = '/console.php'): ?string {
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

function p152cs_post_json(string $url, string $cookieFile, array $body): array {
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
    return ['status' => $status, 'body' => $respBody, 'json' => @json_decode((string) $respBody, true)];
}

$srv = null;

try {
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$dispA, 'zz152-cs-dispa', password_hash($pwA, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$dispA, $dispatcherRoleId]);

    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$dispB, 'zz152-cs-dispb', password_hash($pwB, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$dispB, $dispatcherRoleId]);

    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$field, 'zz152-cs-field', password_hash($pwF, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$field, $fieldRoleId]);
    t('fixture accounts created (2x Dispatcher, 1x Field Unit)', true);

    $base = null;
    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable in this environment — real end-to-end HTTP not run.\n";
    } else {
        echo "\n--- Starting ephemeral php -S server for real end-to-end HTTP verification ---\n\n";
        $srv = p152cs_start_server();
        t('ephemeral php -S test server started', $srv !== null);
        if ($srv !== null) {
            $base = 'http://127.0.0.1:' . $srv['port'];
        }
    }

    if ($base !== null) {

        echo "\n--- A. Field Unit (no screen.console): create is refused ---\n\n";
        $cookieField = p152cs_login($base, 'zz152-cs-field', $pwF);
        t('Field Unit real HTTP login succeeded', $cookieField !== null);
        if ($cookieField !== null) {
            // console.php itself 403s for this role, so pull CSRF from a page
            // every logged-in role can reach instead (about.php).
            $csrfField = p152cs_page_csrf($base, $cookieField, '/about.php');
            t('got a CSRF token for the Field Unit session', is_string($csrfField) && $csrfField !== '');
            $r = p152cs_post_json($base . '/api/console-session.php', $cookieField, [
                'action' => 'create', 'csrf_token' => $csrfField,
            ]);
            t('Field Unit create returns HTTP 403 (missing screen.console)', $r['status'] === 403);
            @unlink($cookieField);
        }

        echo "\n--- B. Dispatcher A: create succeeds and lands in console_sessions ---\n\n";
        $cookieA = p152cs_login($base, 'zz152-cs-dispa', $pwA);
        t('Dispatcher A real HTTP login succeeded', $cookieA !== null);
        $tokenA = null;
        if ($cookieA !== null) {
            $csrfA = p152cs_page_csrf($base, $cookieA, '/console.php');
            t('got a CSRF token for Dispatcher A (console.php)', is_string($csrfA) && $csrfA !== '');
            $r = p152cs_post_json($base . '/api/console-session.php', $cookieA, [
                'action' => 'create', 'csrf_token' => $csrfA, 'workstation_token' => 'ws-fixture-abc',
            ]);
            t('Dispatcher A create returns HTTP 200', $r['status'] === 200);
            $tokenA = $r['json']['session_token'] ?? null;
            t('response carries a 64-hex-char session_token', is_string($tokenA) && preg_match('/^[0-9a-f]{64}$/', $tokenA) === 1);
            t('response carries an expires_at', !empty($r['json']['expires_at'] ?? null));
            t('response carries a ws_url key (null is fine — unconfigured install)', array_key_exists('ws_url', (array) $r['json']));

            if (is_string($tokenA)) {
                $row = db_fetch_one(
                    "SELECT user_id, username, workstation_token, revoked_at FROM {$prefix}console_sessions WHERE session_token = ?",
                    [$tokenA]
                );
                t('the row exists in console_sessions', $row !== null);
                t('row user_id matches Dispatcher A', $row && (int) $row['user_id'] === $dispA);
                t('row username was denormalized to the real username', $row && $row['username'] === 'zz152-cs-dispa');
                t('row workstation_token round-tripped from the request', $row && $row['workstation_token'] === 'ws-fixture-abc');
                t('row is not revoked yet', $row && $row['revoked_at'] === null);
            }

            echo "\n--- C. Dispatcher B cannot revoke Dispatcher A's session (IDOR check) ---\n\n";
            $cookieB = p152cs_login($base, 'zz152-cs-dispb', $pwB);
            t('Dispatcher B real HTTP login succeeded', $cookieB !== null);
            if ($cookieB !== null && is_string($tokenA)) {
                $csrfB = p152cs_page_csrf($base, $cookieB, '/console.php');
                $r = p152cs_post_json($base . '/api/console-session.php', $cookieB, [
                    'action' => 'revoke', 'csrf_token' => $csrfB, 'session_token' => $tokenA,
                ]);
                t('Dispatcher B revoke call returns HTTP 200 (best-effort, no row-existence leak)', $r['status'] === 200);
                $row = db_fetch_one("SELECT revoked_at FROM {$prefix}console_sessions WHERE session_token = ?", [$tokenA]);
                t('Dispatcher A\'s session is STILL NOT revoked after Dispatcher B\'s attempt', $row && $row['revoked_at'] === null);
                @unlink($cookieB);
            }

            echo "\n--- D. Dispatcher A revokes her own session ---\n\n";
            if (is_string($tokenA)) {
                $r = p152cs_post_json($base . '/api/console-session.php', $cookieA, [
                    'action' => 'revoke', 'csrf_token' => $csrfA, 'session_token' => $tokenA,
                ]);
                t('Dispatcher A revoke returns HTTP 200', $r['status'] === 200);
                $row = db_fetch_one("SELECT revoked_at FROM {$prefix}console_sessions WHERE session_token = ?", [$tokenA]);
                t('Dispatcher A\'s own session IS revoked now', $row && $row['revoked_at'] !== null);
            }

            @unlink($cookieA);
        }
    }

} finally {
    p152cs_stop_server($srv);
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
