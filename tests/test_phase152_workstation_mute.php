<?php
/**
 * Phase 152 (Console rebuild) — workstation identity + adjacent-transmit
 * mute (spec.md user story #12, plan.md section 3.5). Covers the manual-
 * pairing PHP surface: sql/run_phase152_workstations.php's two tables,
 * inc/console-workstations.php's business rules (driven directly, Part 1),
 * api/console-workstation-mutes.php's RBAC over real HTTP (Part 2, a
 * lightweight `php -S` harness -- no Python needed, this file never
 * touches the live audio matrix), and frontend wiring (Part 3).
 *
 * The Python-side per-destination audio filtering (services/audio-
 * matrix's Channel.workstation_token / MatrixCore mute-set / tick()
 * exclusion logic) is a SEPARATE, not-yet-built follow-on task per
 * tasks.md's own sequencing -- this phase's manual-pairing storage layer
 * is complete and independently useful (an admin/operator can already
 * configure and audit pairings) even before the matrix actually consults
 * them.
 *
 * Usage: php tests/test_phase152_workstation_mute.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/console-workstations.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- workstation identity + adjacent-transmit mute ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

function p152wm_uuid() {
    return sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff), mt_rand(0x8000, 0xbfff),
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

$tokenA = p152wm_uuid();
$tokenB = p152wm_uuid();
$tokenC = p152wm_uuid();
$createdWorkstationTokens = [$tokenA, $tokenB, $tokenC];
$userIdA = 900199291;
$userIdB = 900199292;
$plainPwA = 'Zz152Wm!' . mt_rand(1000, 9999);
$plainPwB = 'Zz152WmB!' . mt_rand(1000, 9999);

$cleanup = function () use ($prefix, &$createdWorkstationTokens, $userIdA, $userIdB) {
    foreach ($createdWorkstationTokens as $tok) {
        try {
            $id = db_fetch_value("SELECT id FROM {$prefix}console_workstations WHERE workstation_token = ?", [$tok]);
            if ($id) {
                db_query("DELETE FROM {$prefix}console_workstation_mutes WHERE workstation_id = ? OR muted_workstation_id = ?", [$id, $id]);
                db_query("DELETE FROM {$prefix}console_workstations WHERE id = ?", [$id]);
            }
        } catch (Throwable $e) {}
    }
    foreach ([$userIdA, $userIdB] as $uid) {
        try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id = ?", [$uid]); } catch (Throwable $e) {}
        try { db_query("DELETE FROM {$prefix}user WHERE id = ?", [$uid]); } catch (Throwable $e) {}
    }
};
$cleanup();

try {
    echo "--- 1. console_workstation_resolve(): upsert-and-touch, validated ---\n\n";
    $threw = false;
    try { console_workstation_resolve('not a real token; DROP TABLE x'); } catch (Exception $e) { $threw = true; }
    t('a malformed token is refused, not silently stored', $threw);

    $rowA = console_workstation_resolve($tokenA);
    $idA = (int) $rowA['id'];
    t('a valid token creates a row on first sight', $idA > 0 && $rowA['workstation_token'] === $tokenA);
    t('a fresh row has no label yet', $rowA['label'] === null);

    $firstSeen = db_fetch_value("SELECT last_seen_at FROM {$prefix}console_workstations WHERE id = ?", [$idA]);
    $rowA2 = console_workstation_resolve($tokenA);
    t('resolving the SAME token again does not create a second row', (int) $rowA2['id'] === $idA);
    t('...but DOES touch last_seen_at (a real re-registration, not a stale one)',
        strtotime($rowA2['last_seen_at']) >= strtotime($firstSeen));

    echo "\n--- 2. Labels ---\n\n";
    console_workstation_set_label($idA, 'ZZ152WM Desk 1');
    $rowA3 = console_workstation_get_row($idA);
    t('set_label renames the workstation', $rowA3['label'] === 'ZZ152WM Desk 1');
    console_workstation_set_label($idA, '');
    $rowA4 = console_workstation_get_row($idA);
    t('an empty label clears it back to NULL, not an empty string', $rowA4['label'] === null);
    $threw = false;
    try { console_workstation_set_label($idA, str_repeat('x', 65)); } catch (Exception $e) { $threw = true; }
    t('a label over 64 characters is refused', $threw);

    echo "\n--- 3. Nearby list is recency-based, excludes self ---\n\n";
    $rowB = console_workstation_resolve($tokenB);
    $idB = (int) $rowB['id'];
    $rowC = console_workstation_resolve($tokenC);
    $idC = (int) $rowC['id'];
    console_workstation_set_label($idB, 'ZZ152WM Desk 2');

    $nearbyForA = console_workstations_nearby($idA);
    $nearbyIds = array_map(function ($r) { return (int) $r['id']; }, $nearbyForA);
    t('nearby list for A includes B and C but never A itself',
        in_array($idB, $nearbyIds, true) && in_array($idC, $nearbyIds, true) && !in_array($idA, $nearbyIds, true));

    db_query("UPDATE {$prefix}console_workstations SET last_seen_at = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE id = ?",
        [CONSOLE_WORKSTATION_NEARBY_WINDOW_SECONDS + 60, $idC]);
    $nearbyForA2 = console_workstations_nearby($idA);
    $nearbyIds2 = array_map(function ($r) { return (int) $r['id']; }, $nearbyForA2);
    t('a workstation not seen recently (past the window) drops out of the nearby list',
        in_array($idB, $nearbyIds2, true) && !in_array($idC, $nearbyIds2, true));
    console_workstation_resolve($tokenC); // restore freshness for later sections

    echo "\n--- 4. Mute pairing: BOTH directions in one call ---\n\n";
    $threw = false;
    try { console_workstation_set_mute_pair($idA, $idA, true); } catch (Exception $e) { $threw = true; }
    t('a workstation cannot mute itself', $threw);

    console_workstation_set_mute_pair($idA, $idB, true, $userIdA);
    t('A now has B muted', in_array($idB, console_workstation_muted_ids($idA), true));
    t('...AND B now has A muted too -- the "both directions in one action" mechanism plan.md describes',
        in_array($idA, console_workstation_muted_ids($idB), true));
    t('a THIRD, uninvolved workstation (C) has nothing muted', empty(console_workstation_muted_ids($idC)));

    // idA's label was deliberately cleared back to NULL in section 2 above
    // (testing the "empty label -> NULL" behavior) -- only idB actually
    // carries a label at this point, so this checks the JOIN resolves
    // whichever labels genuinely exist, not that every row has one.
    $allPairs = console_workstation_mutes_all();
    $pairIds = [];
    foreach ($allPairs as $p) { $pairIds[] = (int) $p['workstation_id'] . ':' . (int) $p['muted_workstation_id']; }
    t('the admin-facing "all pairings" view shows both directional rows for A<->B',
        count($allPairs) === 2 && in_array("$idA:$idB", $pairIds, true) && in_array("$idB:$idA", $pairIds, true));
    $bRow = $allPairs[0]['workstation_id'] == $idB ? $allPairs[0] : $allPairs[1];
    t('the JOIN resolves B\'s real label (A\'s is NULL by this test\'s own earlier design, not a bug)',
        $bRow['workstation_label'] === 'ZZ152WM Desk 2');

    console_workstation_set_mute_pair($idA, $idB, false);
    t('clearing the pairing removes BOTH directions', empty(console_workstation_muted_ids($idA)) && empty(console_workstation_muted_ids($idB)));

    console_workstation_set_mute_pair($idA, $idB, true);
    console_workstation_set_mute_pair($idA, $idC, true);
    t('A muting BOTH B and C leaves those pairings independent -- unmuting one does not touch the other',
        count(console_workstation_muted_ids($idA)) === 2);
    console_workstation_set_mute_pair($idA, $idB, false);
    t('...confirmed: A still has C muted after unmuting just B',
        console_workstation_muted_ids($idA) === [$idC] || in_array($idC, console_workstation_muted_ids($idA), true));
    t('...and B is no longer muting A, while C still is (independent pairs, not a group mute)',
        empty(console_workstation_muted_ids($idB)) && in_array($idA, console_workstation_muted_ids($idC), true));
    console_workstation_set_mute_pair($idA, $idC, false); // clean up for section 5+

    // ── Part 2: api/console-workstation-mutes.php over real HTTP ──────
    echo "\n--- 5. api/console-workstation-mutes.php: RBAC + wiring over real HTTP ---\n\n";
    $httpCapable = function_exists('proc_open') && function_exists('curl_init');
    if (!$httpCapable) {
        echo "SKIP: proc_open/curl unavailable — real end-to-end HTTP not run.\n";
    } else {
        $dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
        $orgAdminRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Org Admin' LIMIT 1");
        if ($dispatcherRoleId <= 0 || $orgAdminRoleId <= 0) {
            echo "SKIP: Dispatcher/Org Admin roles not found — run sql/run_00_rbac.php first\n";
        } else {
            db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
                [$userIdA, 'zz152wm-a', password_hash($plainPwA, PASSWORD_BCRYPT)]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdA, $dispatcherRoleId]);
            db_query("INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
                [$userIdB, 'zz152wm-b', password_hash($plainPwB, PASSWORD_BCRYPT)]);
            db_query("INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)", [$userIdB, $orgAdminRoleId]);

            // Real Python control_http.py subprocess -- proves the live
            // push (inc/matrix-control-client.php::matrix_control_apply_
            // workstation_mute()) actually reaches a running matrix, not
            // just that it doesn't crash when there is none (this project's
            // own established discipline: a stubbed control client would
            // never catch a real wire-format mismatch). Best-effort: the
            // main RBAC/wiring assertions below all still run even if no
            // working python is found.
            $python = p152wm_find_python();
            $pySrv = $python !== null ? p152wm_start_python_matrix($python, 'zz152wm-control-token') : null;
            $savedSettings = [
                'matrix_control_url' => p152wm_get_setting($prefix, 'matrix_control_url'),
                'matrix_control_token' => p152wm_get_setting($prefix, 'matrix_control_token'),
            ];
            if ($pySrv !== null) {
                p152wm_set_setting($prefix, 'matrix_control_url', 'http://127.0.0.1:' . $pySrv['port']);
                p152wm_set_setting($prefix, 'matrix_control_token', 'zz152wm-control-token');
            }

            $srv = p152wm_start_php_server();
            t('ephemeral php -S test server started', $srv !== null);
            if ($srv !== null) {
                $base = 'http://127.0.0.1:' . $srv['port'];
                $cookieA = p152wm_login($base, 'zz152wm-a', $plainPwA);
                $cookieB = p152wm_login($base, 'zz152wm-b', $plainPwB);
                t('both real HTTP logins succeeded', $cookieA !== null && $cookieB !== null);

                if ($cookieA !== null && $cookieB !== null) {
                    $csrfA = p152wm_csrf($base, $cookieA, '/console.php');
                    $csrfB = p152wm_csrf($base, $cookieB, '/console.php');

                    $r = p152wm_get($base . '/api/console-workstation-mutes.php?workstation_token=' . urlencode($tokenA), $cookieA);
                    t('GET resolves/registers the caller\'s own workstation and returns the nearby shape',
                        $r['status'] === 200 && (int) ($r['json']['my_workstation_id'] ?? 0) === $idA
                        && isset($r['json']['nearby']) && isset($r['json']['muted_ids']));

                    $r = p152wm_post_json($base . '/api/console-workstation-mutes.php', $cookieA, [
                        'action' => 'set_label', 'csrf_token' => $csrfA, 'workstation_token' => $tokenA, 'label' => 'HTTP Desk A',
                    ]);
                    t('a Dispatcher can rename their OWN workstation over real HTTP', $r['status'] === 200 && !empty($r['json']['ok']));

                    $r = p152wm_post_json($base . '/api/console-workstation-mutes.php', $cookieA, [
                        'action' => 'set_mute_pair', 'csrf_token' => $csrfA, 'workstation_token' => $tokenA,
                        'target_workstation_id' => $idB, 'muted' => true,
                    ]);
                    t('a Dispatcher can create a mute pairing involving their OWN current workstation', $r['status'] === 200 && !empty($r['json']['ok']));
                    t('...and it really did apply both directions (checked directly against the DB, not just HTTP 200)',
                        in_array($idA, console_workstation_muted_ids($idB), true));

                    if ($pySrv !== null) {
                        $pyMutes = p152wm_curl_json('GET', 'http://127.0.0.1:' . $pySrv['port'] . '/workstation-mutes');
                        t('the pairing ALSO reached the REAL live Python matrix -- both directions, by real HTTP push, '
                            . 'not just written to the database',
                            isset($pyMutes[$tokenA]) && in_array($tokenB, (array) $pyMutes[$tokenA], true)
                            && isset($pyMutes[$tokenB]) && in_array($tokenA, (array) $pyMutes[$tokenB], true));
                    } else {
                        echo "SKIP: no working python interpreter found — live-push-to-matrix half not run (DB-level push above still fully verified)\n";
                    }

                    $r = p152wm_post_json($base . '/api/console-workstation-mutes.php', $cookieA, [
                        'action' => 'set_label', 'csrf_token' => $csrfA, 'workstation_token' => $tokenA,
                        'workstation_id' => $idB, 'label' => 'Should Fail',
                    ]);
                    t('a Dispatcher (no action.manage_positions) is refused renaming a DIFFERENT workstation with 403', $r['status'] === 403);

                    $r = p152wm_post_json($base . '/api/console-workstation-mutes.php', $cookieA, [
                        'action' => 'set_mute_pair', 'csrf_token' => $csrfA, 'workstation_token' => $tokenA,
                        'source_workstation_id' => $idB, 'target_workstation_id' => $idC, 'muted' => true,
                    ]);
                    t('a Dispatcher is refused configuring a pairing NOT involving their own workstation (403)', $r['status'] === 403);

                    $r = p152wm_post_json($base . '/api/console-workstation-mutes.php', $cookieB, [
                        'action' => 'set_mute_pair', 'csrf_token' => $csrfB, 'workstation_token' => $tokenB,
                        'source_workstation_id' => $idB, 'target_workstation_id' => $idC, 'muted' => true,
                    ]);
                    t('an Org Admin (action.manage_positions) CAN configure a pairing between two OTHER workstations',
                        $r['status'] === 200 && !empty($r['json']['ok'])
                        && in_array($idC, console_workstation_muted_ids($idB), true));
                    console_workstation_set_mute_pair($idB, $idC, false); // clean up this admin-created pair

                    @unlink($cookieA);
                    @unlink($cookieB);
                }
            }
            p152wm_stop_php_server($srv);
            p152wm_stop_python_matrix($pySrv);
            foreach ($savedSettings as $name => $prior) {
                if ($prior === false || $prior === null) {
                    try { db_query("DELETE FROM {$prefix}settings WHERE name = ?", [$name]); } catch (Throwable $e) {}
                } else {
                    try { db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$prior, $name]); } catch (Throwable $e) {}
                }
            }
        }
    }
} finally {
    $cleanup();
}

echo "\n--- 6. Frontend wiring (source-level, this feature's own established style) ---\n\n";
$wjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-workstation.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $wjs));
t('console-workstation.js persists the token in localStorage and exposes getToken()',
    strpos($wjs, "STORAGE_KEY = 'ticketscad_console_workstation_token'") !== false
    && strpos($wjs, 'window.ConsoleWorkstation = {') !== false);

$mjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-mic.js');
t('console-mic.js sends the workstation token when minting a session, but degrades fine without it',
    strpos($mjs, 'body.workstation_token = window.ConsoleWorkstation.getToken();') !== false);

$pjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-workstation-panel.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $pjs));
t('the panel is hidden entirely when there is no workstation token available',
    strpos($pjs, "if (!t) { barEl.classList.add('d-none'); return; }") !== false);
t('a persistent badge shows which nearby workstations are currently muted -- not just at setup time',
    strpos($pjs, 'console-workstation-muted-badge') !== false);
t('the "Hearing yourself?" affordance is present, matching plan.md\'s exact reactive-trigger framing',
    strpos($pjs, 'Hearing yourself?') !== false);

$page = (string) @file_get_contents(__DIR__ . '/../console.php');
t('console.php renders the workstation bar container', strpos($page, 'id="consoleWorkstationBar"') !== false);
// console-mic.js moved into inc/navbar.php on 2026-09-08 (unification plan
// step 1/3), which loads BEFORE console.php's own console-workstation.js
// tag now -- the reverse of the order this assertion used to require.
// That's fine, not a regression: console-mic.js only reads window.
// ConsoleWorkstation.getToken() at actual connect() time (a later user
// action), never at script-parse time, and by the time any real connect()
// can happen every script on the page -- including console-workstation.js
// -- has already run. What still matters, and what this checks now: both
// scripts are actually present on this page's real load path (mic via
// navbar.php, workstation directly), not that one textually precedes the
// other.
$navbarSrc = (string) @file_get_contents(__DIR__ . '/../inc/navbar.php');
t('console-mic.js is present (now via inc/navbar.php, loaded globally)',
    strpos($navbarSrc, 'assets/js/console-mic.js') !== false);
// Phase 155 (GH#108 S1): the workstation identity helper moved into the
// navbar too (the Phone widget needs the token on every page); console.php
// reaches it through inc/navbar.php rather than carrying a second copy.
t('console-workstation.js reaches console.php through inc/navbar.php (loaded once, for every page)',
    strpos($page, '<script src="assets/js/console-workstation.js') === false
    && strpos($page, 'inc/navbar.php') !== false
    && substr_count($navbarSrc, '<script src="assets/js/console-workstation.js') === 1);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);

// ── Lightweight php -S harness (no Python -- this feature never touches
//    the live audio matrix at the PHP layer) ────────────────────────────
function p152wm_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function p152wm_start_php_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = p152wm_free_port();
    if ($port === null) return null;
    $tmpdir = sys_get_temp_dir() . '/tcad-p152wm-php-' . getmypid() . '-' . mt_rand();
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

function p152wm_stop_php_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    p152wm_rrmdir($srv['tmpdir']);
}

function p152wm_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) p152wm_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function p152wm_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'p152wmcookie');
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

function p152wm_csrf(string $base, string $cookieFile, string $path): ?string {
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

function p152wm_post_json(string $url, string $cookieFile, array $body): array {
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

function p152wm_get(string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_TIMEOUT => 8,
    ]);
    $respBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => @json_decode((string) $respBody, true)];
}

// ── Real Python control_http.py subprocess (mirrors tests/test_phase152_
//    console_patch.php's own established pattern verbatim) ────────────
function p152wm_find_python(): ?string {
    foreach (['python', 'python3'] as $cand) {
        $out = []; $rc = 1;
        @exec(escapeshellarg($cand) . ' --version 2>&1', $out, $rc);
        if ($rc === 0) { return $cand; }
    }
    return null;
}

function p152wm_get_setting($prefix, $name) {
    return db_fetch_value("SELECT value FROM {$prefix}settings WHERE name = ?", [$name]);
}
function p152wm_set_setting($prefix, $name, $value) {
    $exists = db_fetch_value("SELECT COUNT(*) FROM {$prefix}settings WHERE name = ?", [$name]);
    if ((int) $exists > 0) {
        db_query("UPDATE {$prefix}settings SET value = ? WHERE name = ?", [$value, $name]);
    } else {
        db_query("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)", [$name, $value]);
    }
}

function p152wm_start_python_matrix(string $python, string $token): ?array {
    $port = p152wm_free_port();
    if ($port === null) return null;
    $audioMatrixDir = str_replace('\\', '/', NEWUI_ROOT) . '/services/audio-matrix';
    $launcher = sys_get_temp_dir() . '/tcad-p152wm-launcher-' . getmypid() . '-' . mt_rand() . '.py';
    $src = "import sys\n"
         . "sys.path.insert(0, " . var_export($audioMatrixDir, true) . ")\n"
         . "from matrix_core import MatrixCore\n"
         . "from control_http import make_control_server\n"
         . "core = MatrixCore()\n"
         . "srv = make_control_server(core, " . (int) $port . ", " . var_export($token, true) . ")\n"
         . "print('READY', flush=True)\n"
         . "srv.serve_forever()\n";
    file_put_contents($launcher, $src);
    $outFile = sys_get_temp_dir() . '/tcad-p152wm-py-out-' . getmypid() . '-' . mt_rand() . '.log';
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

function p152wm_stop_python_matrix(?array $py): void {
    if ($py === null) return;
    @proc_terminate($py['proc']);
    @proc_close($py['proc']);
    @unlink($py['launcher']);
    @unlink($py['outfile']);
}

function p152wm_curl_json(string $method, string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return $raw !== false ? @json_decode($raw, true) : null;
}
