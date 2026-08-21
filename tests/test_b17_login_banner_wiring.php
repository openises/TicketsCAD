<?php
/**
 * SPEC-STATUS.md B17 follow-up (2026-08-21) — login_banner wired for real.
 *
 * B17 named `login_banner` as the one item in its dead-settings-controls
 * cluster that deserved more than a "Not yet wired" badge: GH #91's
 * 2026-08-19 audit had already found it saves to the `settings` table
 * with nothing reading it back, and disabled the two form fields (Display
 * Settings -> Appearance, and Login Settings -> General) with a badge —
 * but it sits right next to the CJIS click-through notice pair in the
 * Login Settings panel, which DOES work (login.php reads
 * `cjis_login_notice_enabled`/`cjis_login_notice_text` via direct SQL).
 * An admin filling in the wrong box would see no effect either way and
 * have no way to tell which box was "the broken one" — B17's own words.
 *
 * This test proves the OBSERVABLE effect, not a DB round-trip or a
 * source-grep for the setting's name (the tile_mode lesson this project
 * already learned the hard way, see CLAUDE.md's "THE SETTING THAT WAS
 * NEVER WIRED TO ANYTHING" entry): it drives the REAL, unmodified
 * login.php over REAL HTTP (php -S rooted at THIS checkout, the
 * established ephemeral-server pattern from tests/_pb_test_server.php /
 * tests/test_gh99_facility_portal_unit_destination_filter.php), sets the
 * `login_banner` settings row to a known value, and asserts the exact
 * text appears in the rendered HTML. Then clears it and asserts the
 * banner element is genuinely ABSENT (not just empty) — proving the
 * control is actually conditional on the setting, not a static fixture
 * that would render even if the setting fetch silently failed. Also
 * proves output escaping (a pre-auth page rendering admin-supplied text
 * is exactly where an XSS bug would be worst) and that setting
 * login_banner alone does not accidentally pull in any of the CJIS
 * notice's own markup (the two controls must stay genuinely distinct).
 *
 * NOT @requires-http: spins up its own local PHP server, never touches
 * a live Apache/localhost install — runs fine under NEWUI_TEST_NO_HTTP=1
 * and in CI. Needs a reachable MySQL/MariaDB, like every DB-backed test.
 *
 * Usage: php tests/test_b17_login_banner_wiring.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function b17lb_t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== B17 login_banner wiring (SPEC-STATUS.md follow-up) ===\n\n";

$httpCapable = function_exists('proc_open') && function_exists('curl_init');
if (!$httpCapable) {
    echo "SKIP: proc_open()/curl_init() unavailable in this environment\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Ephemeral php -S server + HTTP GET helper (mirrors tests/_pb_test_server.php) ──
function b17lb_free_port(): ?int {
    $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($s)) return null;
    $name = stream_socket_get_name($s, false);
    fclose($s);
    if (!is_string($name) || strrpos($name, ':') === false) return null;
    return (int) substr($name, strrpos($name, ':') + 1);
}

function b17lb_start_server(): ?array {
    $bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : null;
    if ($bin === null || !@is_file($bin)) return null;
    $port = b17lb_free_port();
    if ($port === null) return null;

    $tmpdir = sys_get_temp_dir() . '/tcad-b17lb-' . getmypid() . '-' . mt_rand();
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

function b17lb_stop_server(?array $srv): void {
    if ($srv === null) return;
    @proc_terminate($srv['proc']);
    @proc_close($srv['proc']);
    b17lb_rrmdir($srv['tmpdir']);
}

function b17lb_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) b17lb_rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function b17lb_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body === false ? null : (string) $body;
}

function b17lb_set_login_banner(string $value): void {
    global $prefix;
    db_query(
        "INSERT INTO {$prefix}settings (name, value) VALUES ('login_banner', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)",
        [$value]
    );
}

// ── Preserve + restore whatever value already lives in this shared
//    dev database (the same "never clobber real state" discipline
//    every other DB-backed test in this suite follows). ──
$originalValue = db_fetch_value(
    "SELECT value FROM {$prefix}settings WHERE name = ? LIMIT 1",
    ['login_banner']
);
$hadRow = $originalValue !== false;
$originalValue = $hadRow ? (string) $originalValue : null;

$srv = null;

try {
    $srv = b17lb_start_server();
    if ($srv === null) {
        echo "SKIP: could not start ephemeral php -S test server\n";
        echo "\n=== 0 passed, 0 failed ===\n";
        exit(0);
    }
    $base = 'http://127.0.0.1:' . $srv['port'];

    // ══════════════════════════════════════════════════════════════
    // A. Set a distinctive value -> it must render in the raw HTML.
    // ══════════════════════════════════════════════════════════════
    $marker = 'B17-LOGIN-BANNER-MARKER-' . mt_rand(100000, 999999);
    b17lb_set_login_banner($marker);

    $html = b17lb_get($base . '/login.php');
    b17lb_t('login.php reachable over the ephemeral server', $html !== null && $html !== '');

    if ($html !== null) {
        b17lb_t(
            'set login_banner text appears in the rendered login page',
            strpos($html, $marker) !== false
        );
        b17lb_t(
            'banner renders inside its own dedicated element (id="loginBannerText")',
            (bool) preg_match('/id="loginBannerText"[^>]*>[^<]*' . preg_quote($marker, '/') . '/', $html)
        );
        // Distinct from the CJIS notice: setting login_banner alone must
        // NOT pull in the CJIS click-through checkbox (cjis_login_notice_enabled
        // is untouched by this test and defaults off on a fresh/dev install
        // unless an admin explicitly enabled it separately).
        $cjisEnabled = (string) db_fetch_value(
            "SELECT value FROM {$prefix}settings WHERE name = ? LIMIT 1",
            ['cjis_login_notice_enabled']
        );
        if ($cjisEnabled !== '1') {
            b17lb_t(
                'login_banner does not pull in the CJIS click-through checkbox',
                strpos($html, 'id="cjisAccept"') === false
            );
        } else {
            echo "SKIP: cjis_login_notice_enabled is '1' on this dev DB — CJIS-independence check skipped (not this test's setting to touch)\n";
        }
    }

    // ══════════════════════════════════════════════════════════════
    // B. XSS escaping — a pre-auth page echoing admin-supplied text is
    //    exactly where this matters most.
    // ══════════════════════════════════════════════════════════════
    $xssMarker = '<script>alert(1)</script>"B17XSS';
    b17lb_set_login_banner($xssMarker);
    $html = b17lb_get($base . '/login.php');
    if ($html !== null) {
        b17lb_t(
            'login_banner text is HTML-escaped, not injected raw',
            strpos($html, '<script>alert(1)</script>') === false
                && strpos($html, '&lt;script&gt;') !== false
        );
    }

    // ══════════════════════════════════════════════════════════════
    // C. Cleared setting -> the banner element must be genuinely
    //    ABSENT, not merely empty (proves this is a real conditional,
    //    not a fixture that always renders regardless of the fetch).
    // ══════════════════════════════════════════════════════════════
    b17lb_set_login_banner('');
    $html = b17lb_get($base . '/login.php');
    if ($html !== null) {
        b17lb_t(
            'empty login_banner renders no loginBannerText element at all',
            strpos($html, 'id="loginBannerText"') === false
        );
    }

} finally {
    // Restore the dev database to exactly what it had before this test
    // ran (a real value was already sitting there — see this test's own
    // discovery of it; never leave it cleared or test-marker-polluted).
    if ($hadRow) {
        b17lb_set_login_banner($originalValue);
    } else {
        try { db_query("DELETE FROM {$prefix}settings WHERE name = ?", ['login_banner']); } catch (Throwable $e) {}
    }
    b17lb_stop_server($srv);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
