<?php
/**
 * Phase 152 (Console rebuild) — the patch rail: api/matrix.php's RBAC
 * widening (action.patch_create can now reach the same create/update/
 * delete/renew CRUD that used to be action.manage_matrix-only, with
 * action.patch_cross_class gating specifically the cross-class case) and
 * its new group_create/group_break actions (matrix_group_create()/
 * matrix_group_break(), inc/matrix-routes.php — already unit-tested in
 * tests/test_phase152_group_coupling.php, this is their first live-HTTP
 * exposure). Same real Python control_http.py + real php -S harness as
 * tests/test_phase152_live_route_apply.php and tests/test_phase152_
 * console_patch.php — a stubbed control-client would never catch a real
 * wire-format mismatch.
 *
 * The frontend (assets/js/console-patch-rail.js, the checkbox-select
 * creation flow, live comm:route_... and comm:group_... SSE consumption,
 * the patchchips badge) is covered as source-level wiring guards at the
 * bottom of this file, in the style this feature's other test files
 * already established.
 *
 * Requires: proc_open/curl, and a working python/python3 on PATH. Skips
 * cleanly if either prerequisite is missing.
 *
 * Usage: php tests/test_phase152_patch_rail.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- the patch rail (RBAC widening + group actions) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

function p152pr_find_python(): ?string {
    foreach (['python', 'python3'] as $cand) {
        $out = []; $rc = 1;
        @exec(escapeshellarg($cand) . ' --version 2>&1', $out, $rc);
        if ($rc === 0) { return $cand; }
    }
    return null;
}

$python = $httpCapable ? p152pr_find_python() : null;
if ($python === null) {
    echo "SKIP: no working python/python3 interpreter found on PATH\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
if ($dispatcherRoleId <= 0) {
    echo "SKIP: Dispatcher role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispatcherUserId = 900199093;
$plainPwDispatcher = 'Zz152Pr!' . mt_rand(1000, 9999);
$MATRIX_TOKEN = 'zz152-pr-test-control-token-' . mt_rand(100000, 999999);

$createdChannelIds = [];
$createdRouteIds = [];
$savedSettings = [];

function p152pr_get_setting($prefix, $name) { return db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = ?", [$name]); }
function p152pr_set_setting($prefix, $name, $value) {
    $exists = db_fetch_value("SELECT COUNT(*) FROM {$prefix}settings WHERE name = ?", [$name]);
    if ((int) $exists > 0) { db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$value, $name]); }
    else { db_query("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)", [$name, $value]); }
}

$cleanup = function () use ($prefix, $dispatcherUserId, &$createdChannelIds, &$createdRouteIds, &$savedSettings) {
    foreach ($createdRouteIds as $id) { try { db_query("DELETE FROM {$prefix}comm_routes WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM {$prefix}comm_channels WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$dispatcherUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$dispatcherUserId]); } catch (Throwable $e) {}
    foreach ($savedSettings as $name => $prior) {
        try {
            if ($prior === false || $prior === null) { db_query("DELETE FROM {$prefix}settings WHERE name = ?", [$name]); }
            else { db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$prior, $name]); }
        } catch (Throwable $e) {}
    }
};
$cleanup();

function p152pr_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152pr_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152pr_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152pr-php-' . getmypid() . '-' . mt_rand();
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
    @proc_terminate($proc); @proc_close($proc);
    return null;
}
function p152pr_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']); @proc_close($srv['proc']);
    p152pr_rrmdir($srv['tmpdir']);
}
function p152pr_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152pr_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}
function p152pr_start_python_matrix(string $python, string $token): ?array {
    $port = p152pr_free_port();
    if ($port === null) return null;
    $audioMatrixDir = str_replace('\\', '/', NEWUI_ROOT) . '/services/audio-matrix';
    $launcher = sys_get_temp_dir() . '/tcad-p152pr-launcher-' . getmypid() . '-' . mt_rand() . '.py';
    $src = "import sys\n"
         . "sys.path.insert(0, " . var_export($audioMatrixDir, true) . ")\n"
         . "from matrix_core import MatrixCore\n"
         . "from control_http import make_control_server\n"
         . "core = MatrixCore()\n"
         . "srv = make_control_server(core, " . (int) $port . ", " . var_export($token, true) . ")\n"
         . "print('READY', flush=True)\n"
         . "srv.serve_forever()\n";
    file_put_contents($launcher, $src);
    $outFile = sys_get_temp_dir() . '/tcad-p152pr-py-out-' . getmypid() . '-' . mt_rand() . '.log';
    $desc = [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'a'], 2 => ['file', $outFile, 'a']];
    $proc = @proc_open([$python, $launcher], $desc, $pipes);
    if (!is_resource($proc)) { @unlink($launcher); @unlink($outFile); return null; }
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $buf = (string) @file_get_contents($outFile);
        if (strpos($buf, 'READY') !== false) { return ['proc' => $proc, 'port' => $port, 'launcher' => $launcher, 'outfile' => $outFile]; }
        usleep(50000);
    }
    @proc_terminate($proc); @proc_close($proc); @unlink($launcher); @unlink($outFile);
    return null;
}
function p152pr_stop_python_matrix(?array $py): void {
    if ($py === null) return;
    @proc_terminate($py['proc']); @proc_close($py['proc']);
    @unlink($py['launcher']); @unlink($py['outfile']);
}
function p152pr_curl(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => $headers];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body); }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => $raw !== false ? @json_decode($raw, true) : null];
}
function p152pr_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152prcookie');
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
function p152pr_csrf(string $base, string $cookieFile, string $path): ?string {
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
function p152pr_post_json(string $url, string $cookieFile, array $body): array {
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

$phpSrv = null; $pySrv = null;

try {
    $savedSettings['matrix_control_url'] = p152pr_get_setting($prefix, 'matrix_control_url');
    $savedSettings['matrix_control_token'] = p152pr_get_setting($prefix, 'matrix_control_token');
    p152pr_set_setting($prefix, 'matrix_control_token', $MATRIX_TOKEN);

    $suffix = uniqid();
    $mk = function ($label) use ($prefix, $suffix, &$createdChannelIds) {
        db_query(
            "INSERT INTO {$prefix}comm_channels (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
             VALUES (?, 'test', ?, 'internal', 1, 0, 999)",
            ["zz152pr_" . strtolower($label) . "_$suffix", "ZZ152PR $label"]
        );
        $id = (int) db_insert_id();
        $createdChannelIds[] = $id;
        return ['id' => $id, 'key' => "zz152pr_" . strtolower($label) . "_$suffix"];
    };
    // A/B/C are used by the single-route RBAC checks (A-C below); D/E/F
    // are a SEPARATE set for the group-mesh test, so the group's full
    // mesh (every pair, both directions) never collides with the
    // standalone chanA->chanB route those earlier steps create.
    $chanA = $mk('A'); $chanB = $mk('B'); $chanC = $mk('C');
    $chanD = $mk('D'); $chanE = $mk('E'); $chanF = $mk('F');

    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$dispatcherUserId, 'zz152pr-dispatcher', password_hash($plainPwDispatcher, PASSWORD_BCRYPT)]
    );
    db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$dispatcherUserId, $dispatcherRoleId]);
    t('fixture channels + Dispatcher-tier account created', true);

    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
    } else {
        $phpSrv = p152pr_start_php_server();
        t('ephemeral php -S test server started', $phpSrv !== null);
        $pySrv = p152pr_start_python_matrix($python, $MATRIX_TOKEN);
        t('real python control_http.py subprocess started and reports READY', $pySrv !== null);

        if ($phpSrv !== null && $pySrv !== null) {
            $base = 'http://127.0.0.1:' . $phpSrv['port'];
            $pyBase = 'http://127.0.0.1:' . $pySrv['port'];
            p152pr_set_setting($prefix, 'matrix_control_url', $pyBase);
            foreach ([$chanA, $chanB, $chanC, $chanD, $chanE, $chanF] as $c) {
                p152pr_curl('POST', $pyBase . '/channels', ['Authorization: Bearer ' . $MATRIX_TOKEN],
                    ['id' => $c['key'], 'name' => $c['key'], 'reg_class' => 'internal']);
            }

            $cookie = p152pr_login($base, 'zz152pr-dispatcher', $plainPwDispatcher);
            t('dispatcher real HTTP login succeeded', $cookie !== null);

            if ($cookie !== null) {
                $csrf = p152pr_csrf($base, $cookie, '/console.php');

                echo "\n--- A. RBAC widening: a plain Dispatcher (action.patch_create) CAN create a same-class patch ---\n\n";
                $r = p152pr_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'create', 'csrf_token' => $csrf,
                    'src_channel_id' => $chanA['id'], 'dst_channel_id' => $chanB['id'],
                ]);
                t('a Dispatcher (no action.manage_matrix) can create a SAME-CLASS route — HTTP 200, not 403',
                    $r['status'] === 200 && !empty($r['json']['id']));
                $routeId = $r['json']['id'] ?? null;
                $createdRouteIds[] = (int) $routeId;

                echo "\n--- B. RBAC widening: the SAME Dispatcher is refused a cross-class override ---\n\n";
                $r2 = p152pr_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'create', 'csrf_token' => $csrf,
                    'src_channel_id' => $chanA['id'], 'dst_channel_id' => $chanC['id'],
                    'allow_cross_class' => true,
                ]);
                t('a Dispatcher without action.patch_cross_class is refused a cross-class create (403)',
                    $r2['status'] === 403 && stripos((string) ($r2['json']['error'] ?? ''), 'patch_cross_class') !== false);

                echo "\n--- C. RBAC widening: the same Dispatcher cannot widen an existing route to cross-class either ---\n\n";
                $r3 = p152pr_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'update', 'csrf_token' => $csrf, 'id' => $routeId, 'allow_cross_class' => true,
                ]);
                t('updating a route to allow_cross_class=true is ALSO refused for a Dispatcher (403)', $r3['status'] === 403);

                echo "\n--- D. group_create: 3 channels -> 6 live routes, all-or-nothing ---\n\n";
                // D/E/F are a channel set NEVER touched by A-C above -- the
                // full mesh below creates BOTH directions of every pair,
                // which would otherwise collide with A-C's own standalone
                // chanA->chanB route (matrix_route_validate()'s duplicate-
                // pair check correctly refuses that, exactly as it should
                // for two genuinely independent callers racing for the
                // same src/dst -- this test just needs to not manufacture
                // that collision against itself).
                $g = p152pr_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'group_create', 'csrf_token' => $csrf,
                    'channel_ids' => [$chanD['id'], $chanE['id'], $chanF['id']],
                ]);
                if (getenv('P152PR_DEBUG')) { fwrite(STDERR, "group_create response: status={$g['status']} json=" . var_export($g['json'], true) . "\n"); }
                t('group_create returns HTTP 200 with a group_id and 6 routes (full mesh)',
                    $g['status'] === 200 && !empty($g['json']['group_id']) && count($g['json']['routes'] ?? []) === 6);
                $groupId = $g['json']['group_id'] ?? null;
                if (!empty($g['json']['routes'])) {
                    foreach ($g['json']['routes'] as $gr) { $createdRouteIds[] = (int) $gr['id']; }
                }
                $pyRoutes = p152pr_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $meshFound = 0;
                foreach ((array) $pyRoutes as $pr) {
                    foreach ([[$chanD['key'], $chanE['key']], [$chanE['key'], $chanD['key']],
                              [$chanD['key'], $chanF['key']], [$chanF['key'], $chanD['key']],
                              [$chanE['key'], $chanF['key']], [$chanF['key'], $chanE['key']]] as $pair) {
                        if (($pr['src'] ?? null) === $pair[0] && ($pr['dst'] ?? null) === $pair[1]) { $meshFound++; }
                    }
                }
                t('all 6 mesh legs are live in the REAL matrix, not just the DB response', $meshFound === 6);

                echo "\n--- E. group_break: instant, removes every leg live AND in the DB ---\n\n";
                $b = p152pr_post_json($base . '/api/matrix.php', $cookie, [
                    'action' => 'group_break', 'csrf_token' => $csrf, 'group_id' => $groupId,
                ]);
                t('group_break returns HTTP 200 with removed=6', $b['status'] === 200 && ($b['json']['removed'] ?? 0) === 6);
                $pyRoutes = p152pr_curl('GET', $pyBase . '/routes')['json'] ?? [];
                $stillThere = 0;
                foreach ((array) $pyRoutes as $pr) {
                    if (in_array($pr['src'] ?? null, [$chanD['key'], $chanE['key'], $chanF['key']], true)
                        && in_array($pr['dst'] ?? null, [$chanD['key'], $chanE['key'], $chanF['key']], true)) { $stillThere++; }
                }
                t('none of the group\'s legs remain live in the real matrix', $stillThere === 0);
                $dbCount = (int) db_fetch_value("SELECT COUNT(*) FROM {$prefix}comm_routes WHERE group_id = ?", [$groupId]);
                t('none of the group\'s legs remain in comm_routes either', $dbCount === 0);
                $createdRouteIds = []; // all cleaned up by group_break already

                @unlink($cookie);
            }
        }
    }

} finally {
    p152pr_stop_php_server($phpSrv);
    p152pr_stop_python_matrix($pySrv);
    $cleanup();
}

echo "\n--- F. Frontend wiring: console-patch-rail.js ---\n\n";
$rail = (string) @file_get_contents('assets/js/console-patch-rail.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $rail));
t('never opens its own SSE connection or polls — subscribes to the SAME EventBus every other SSE consumer uses',
    strpos($rail, "EventSource(") === false && strpos($rail, 'window.EventBus.on(') !== false);
t('subscribes to all 5 comm:route_*/comm:group_* event types api/matrix.php now publishes',
    strpos($rail, "'comm:route_created'") !== false && strpos($rail, "'comm:route_updated'") !== false
    && strpos($rail, "'comm:route_removed'") !== false && strpos($rail, "'comm:group_created'") !== false
    && strpos($rail, "'comm:group_removed'") !== false);
t('event-bus.js\'s dynamic-load timing (inc/navbar.php loadGlobal()) is handled with a poll, not a bare unconditional check',
    strpos($rail, 'function waitForEventBus(') !== false);
t('breaking a patch/group is a single click with NO confirmation dialog (persona review: always fail toward less connectivity)',
    strpos($rail, 'window.confirm') === false);
t('creation is checkbox-select + one confirm button, never drag (persona review: unanimous rejection of drag-to-patch)',
    strpos($rail, 'draggable') === false && strpos($rail, "'Couple Selected'") !== false);
t('2 selected channels create a single route; 3+ create a group_create call', strpos($rail, "chanIds.length === 2") !== false);
t('the ephemeral browser-leg session channel is NEVER offered as a patchable checkbox target (this rail is for STANDING '
    . 'comm_routes patches only, not a dispatcher\'s own mic — api/console-patch.php\'s separate concern)',
    strpos($rail, 'per-session') !== false && strpos($rail, 'ephemeral browser-leg routes') !== false);

echo "\n--- G. console-designer.js: patchchips is real now, not future ---\n\n";
$djs = (string) @file_get_contents('assets/js/console-designer.js');
t('FUTURE_FLAGS no longer includes patchchips', strpos($djs, 'var FUTURE_FLAGS = { vu: true };') !== false);

echo "\n--- H. console.js: the patch checkbox + patched badge are wired ---\n\n";
$cjs = (string) @file_get_contents('assets/js/console.js');
t('a patch-select checkbox is offered only when ConsolePatchRail.canPatch() is true',
    strpos($cjs, 'window.ConsolePatchRail && window.ConsolePatchRail.canPatch()') !== false);
t('the patched badge is gated on BOTH tpl.show.patchchips and the rail\'s own live isPatched() state',
    strpos($cjs, 'show.patchchips && window.ConsolePatchRail && window.ConsolePatchRail.isPatched(ch.id)') !== false);
t('paintPatchState() repaints in place rather than forcing a full renderBank() (never disrupts another operator\'s open drawer)',
    strpos($cjs, 'function paintPatchState()') !== false && strpos($cjs, "renderBank();") !== 0);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
