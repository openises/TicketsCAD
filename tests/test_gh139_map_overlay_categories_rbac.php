<?php
/**
 * GH#139 (2026-09-06) — api/map-overlay-categories.php's read-only
 * `?action=list` was gated behind `action.manage_config` (Super-Admin-only)
 * along with the file's genuinely admin-only mutations (create/update/
 * archive/assign_markup). Every non-Super-Admin role got a flat 403 on
 * `list`, and the two callers — situation.php's real Leaflet layer control
 * and index.php's dashboard Map widget (assets/js/app.js) — both swallow a
 * failed fetch silently (`r.ok ? r.json() : { categories: [] }`), so a
 * Dispatcher/Org Admin/Operator/Read-Only/Field Unit session never saw an
 * error and never saw the install's real named overlay categories (Region
 * Boundary, Hazards, Zones, ...) either — just a generic "Uncategorised
 * markups" toggle. Reported by a beta tester as "cannot view map overlays... and
 * cannot view from the index.php screen" for non-super-admins; rjonesbsink
 * checked the toolbar code and correctly found no gate THERE, but left the
 * claim unconfirmed. Root-caused by an actual live Dispatcher-role browser
 * session against local dev before this fix (per the project's
 * reproduce-via-real-writer/real-endpoint discipline — never assumed from
 * reading the RBAC tables alone).
 *
 * Fix: `list` (GET) now falls through with no RBAC check beyond auth.php's
 * own login requirement — the same treatment its siblings
 * (map-image-overlays.php, map-config.php, road-conditions.php,
 * weather-alerts.php) already get, since a category's name/color/icon/
 * sort_order/default_visible/markup_count is overlay-toggle labeling, not
 * sensitive data. `create`/`update`/`archive`/`assign_markup` are
 * unchanged — still `action.manage_config`.
 *
 * This drives the REAL api/map-overlay-categories.php over REAL HTTP
 * (ephemeral `php -S` rooted at NEWUI_ROOT, matching tests/test_gh99_
 * facility_portal_unit_destination_filter.php's established pattern) via a
 * REAL login.php CSRF+cookie+session flow — never a hand-simulated
 * $_SESSION. The core assertion is the exact HTTP status code (200 vs 403)
 * and that the fixture category's real name appears in the JSON body —
 * that pair is precisely the pre-fix/post-fix signature of this bug.
 *
 * NOT @requires-http — spins up its own local PHP server, never touches a
 * live Apache/localhost install. Needs a reachable MySQL/MariaDB.
 *
 * Usage: php tests/test_gh139_map_overlay_categories_rbac.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#139 — map-overlay-categories.php `list` no longer Super-Admin-only ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$httpCapable = function_exists('proc_open') && function_exists('curl_init');

$dispatcherRoleId = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE name = 'Dispatcher' LIMIT 1");
$superRoleId      = (int) db_fetch_value("SELECT id FROM {$prefix}roles WHERE is_super = 1 ORDER BY id LIMIT 1");
if ($dispatcherRoleId <= 0 || $superRoleId <= 0) {
    echo "SKIP: Dispatcher or Super Admin role not found — run sql/run_00_rbac.php first\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$dispUserId = 900199031;
$adminUserId = 900199032;
$catId = 0;

$plainPwDisp  = 'Zz139GH139Disp!' . mt_rand(1000, 9999);
$plainPwAdmin = 'Zz139GH139Admin!' . mt_rand(1000, 9999);

$cleanup = function () use ($prefix, $dispUserId, $adminUserId, &$catId) {
    if ($catId) { try { db_query("DELETE FROM {$prefix}mmarkup_cats WHERE id = ?", [$catId]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM {$prefix}user_roles WHERE user_id IN (?, ?)", [$dispUserId, $adminUserId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM {$prefix}user WHERE id IN (?, ?)", [$dispUserId, $adminUserId]); } catch (Throwable $e) {}
};
$cleanup();

// ── Ephemeral php -S server + HTTP helpers (mirrors tests/test_gh99_facility_portal_unit_destination_filter.php) ──
function gh139_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function gh139_start_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = gh139_free_port();
    if ($port === null) return null;

    $tmpdir = sys_get_temp_dir() . '/tcad-gh139-' . getmypid() . '-' . mt_rand();
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

function gh139_stop_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    gh139_rrmdir($srv['tmpdir']);
}

function gh139_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) gh139_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function gh139_login(string $base, string $username, string $password): ?string {
    $cookieFile = tempnam(sys_get_temp_dir(), 'gh139cookie');

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

function gh139_curl(string $method, string $url, string $cookieFile): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 8,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['name' => 'ZZ139 should never be created']));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'json' => @json_decode((string) $body, true)];
}

$srv = null;

try {
    // ── Source-level guard: `list` must be handled BEFORE the
    //    action.manage_config gate, not just "somewhere in the file". ──
    $src = (string) @file_get_contents(NEWUI_ROOT . '/api/map-overlay-categories.php');
    $listPos = strpos($src, "\$action === 'list'");
    $gatePos = strpos($src, "rbac_can('action.manage_config')");
    t('source has both the `list` branch and the action.manage_config gate', $listPos !== false && $gatePos !== false);
    t('the `list` branch appears BEFORE the action.manage_config gate in source', $listPos !== false && $gatePos !== false && $listPos < $gatePos);

    // ── Fixture: a real named category, so we can prove it round-trips ──
    db_query(
        "INSERT INTO {$prefix}mmarkup_cats (id, category, color, sort_order, default_visible) VALUES (?, 'ZZ139 Staging Areas', '#1976d2', 50, 1)",
        [900199041]
    );
    $catId = 900199041;
    t('fixture category "ZZ139 Staging Areas" created', $catId > 0);

    // ── Fixture accounts — real bcrypt passwords, never TFA-enrolled ──
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$dispUserId, 'zz139-gh139-disp', password_hash($plainPwDisp, PASSWORD_BCRYPT)]
    );
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)",
        [$dispUserId, $dispatcherRoleId]
    );
    db_query(
        "INSERT INTO {$prefix}user (id, user, passwd, must_change_password) VALUES (?, ?, ?, 0)",
        [$adminUserId, 'zz139-gh139-admin', password_hash($plainPwAdmin, PASSWORD_BCRYPT)]
    );
    db_query(
        "INSERT INTO {$prefix}user_roles (user_id, role_id) VALUES (?, ?)",
        [$adminUserId, $superRoleId]
    );
    t('fixture Dispatcher-role and Super-Admin-role accounts created', true);

    $base = null;
    if (!$httpCapable) {
        echo "\nSKIP: proc_open/curl unavailable in this environment — real end-to-end HTTP not run.\n";
    } else {
        echo "\n--- Starting ephemeral php -S server for real end-to-end HTTP verification ---\n\n";
        $srv = gh139_start_server();
        t('ephemeral php -S test server started', $srv !== null);
        if ($srv !== null) {
            $base = 'http://127.0.0.1:' . $srv['port'];
        }
    }

    if ($base !== null) {

        echo "\n--- A. Dispatcher session: `list` now returns 200 with the real category (the GH#139 fix) ---\n\n";
        $cookieDisp = gh139_login($base, 'zz139-gh139-disp', $plainPwDisp);
        t('Dispatcher real HTTP login succeeded (session cookie obtained)', $cookieDisp !== null);
        if ($cookieDisp !== null) {
            $r = gh139_curl('GET', $base . '/api/map-overlay-categories.php?action=list', $cookieDisp);
            t('Dispatcher GET ?action=list returns HTTP 200 (pre-fix this was 403)', $r['status'] === 200);
            $names = array_column((array) ($r['json']['categories'] ?? []), 'name');
            t('Dispatcher\'s response contains the real fixture category name "ZZ139 Staging Areas" (pre-fix: empty, swallowed by the JS as {categories: []})',
                in_array('ZZ139 Staging Areas', $names, true));

            echo "\n--- B. Dispatcher session: mutating actions are STILL admin-only (no regression the other direction) ---\n\n";
            $rCreate = gh139_curl('POST', $base . '/api/map-overlay-categories.php?action=create', $cookieDisp);
            t('Dispatcher POST ?action=create still returns HTTP 403', $rCreate['status'] === 403);
            t('Dispatcher POST ?action=create still names action.manage_config as the requirement',
                strpos((string) $rCreate['body'], 'action.manage_config') !== false);

            @unlink($cookieDisp);
        }

        echo "\n--- C. Super Admin session: `list` still returns 200 (no regression for the admin path) ---\n\n";
        $cookieAdmin = gh139_login($base, 'zz139-gh139-admin', $plainPwAdmin);
        t('Super Admin real HTTP login succeeded (session cookie obtained)', $cookieAdmin !== null);
        if ($cookieAdmin !== null) {
            $r = gh139_curl('GET', $base . '/api/map-overlay-categories.php?action=list', $cookieAdmin);
            t('Super Admin GET ?action=list still returns HTTP 200', $r['status'] === 200);
            $names = array_column((array) ($r['json']['categories'] ?? []), 'name');
            t('Super Admin also sees the real fixture category name', in_array('ZZ139 Staging Areas', $names, true));
            @unlink($cookieAdmin);
        }
    }

} finally {
    gh139_stop_server($srv);
    $cleanup();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
