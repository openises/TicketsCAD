<?php
/**
 * SPEC-STATUS.md gap B16 — `require_https` enforcement banner.
 *
 * Covers inc/https-enforcement.php's https_enforcement_status() across all
 * three https_verification_failure_reason() states ('tls',
 * 'untrusted_proxy', 'plaintext') crossed with the require_https setting
 * on/off, health_check_https_enforcement()'s severity mapping, and — the
 * hard requirement this file exists to prove — that NONE of this ever
 * blocks a request, in any state, structurally and behaviourally.
 *
 * ── Why every scenario spawns a fresh subprocess ─────────────────────────
 * get_variable() (inc/functions.php) caches the WHOLE settings table in a
 * process-static on first call. This file writes the `require_https`
 * setting via direct SQL between scenarios — calling
 * https_enforcement_status() a second time in the SAME process would
 * silently answer from the FIRST scenario's stale cache, exactly the trap
 * tests/test_public_board_health_check.php already documents and works
 * around with its own pbhc_probe() helper. https_enf_probe() below is the
 * same pattern, copied rather than reinvented (per this project's own
 * "copy it, don't reinvent it" convention).
 *
 * @requires-db
 * Usage: php tests/test_https_enforcement.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/health-check.php';

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($cond ? '' : ($hint !== '' ? "\n       $hint" : '')) . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$root   = realpath(__DIR__ . '/..');
$php    = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';

/**
 * Run https_enforcement_status() (and, if $withHealthCheck, also
 * health_check_https_enforcement()) in a FRESH PHP subprocess under a
 * simulated $_SERVER, against whatever `require_https` value is currently
 * in the real database at call time. Returns
 * ['status' => ..., 'health' => ...] decoded from JSON, or null if the
 * probe couldn't run at all (no PHP binary, etc.).
 */
function https_enf_probe(string $root, string $php, array $server, bool $withHealthCheck = false): ?array
{
    $code = '<?php '
          . '$_SERVER = array_merge($_SERVER, ' . var_export($server, true) . '); '
          . 'require_once ' . var_export($root . '/config.php', true) . '; '
          . 'require_once ' . var_export($root . '/inc/https-enforcement.php', true) . '; '
          . '$out = ["status" => https_enforcement_status()]; '
          . ($withHealthCheck
                ? 'require_once ' . var_export($root . '/inc/health-check.php', true) . '; '
                . '$out["health"] = health_check_https_enforcement(); '
                : '')
          . 'echo "<<<E2E>>>" . json_encode($out);';
    $tmp = sys_get_temp_dir() . '/newui-https-enf-probe-' . getmypid() . '-' . mt_rand() . '.php';
    if (@file_put_contents($tmp, $code) === false) { return null; }
    $out = (string) @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    $at = strpos($out, '<<<E2E>>>');
    if ($at === false) { return null; }
    $json = trim(substr($out, $at + strlen('<<<E2E>>>')));
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

/** Set (or clear) require_https via direct SQL — never through get_variable(). */
function https_enf_set_require($prefix, $value) {
    if ($value === null) {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'require_https'");
        return;
    }
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('require_https', ?)
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)", [$value]);
}

if ($root === false || !function_exists('shell_exec')) {
    echo "SKIP: shell_exec()/realpath() unavailable — cannot spawn the isolated probe process this test requires\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$origRequireHttps = null;
$origTrustedProxies = null;

try {
    $origRequireHttps = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='require_https'");
    $origTrustedProxies = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='trusted_proxies'");

    // Pin trusted_proxies to the shipped default for the whole run, so the
    // untrusted-proxy vs trusted-proxy scenarios below are not dependent
    // on ambient dev-DB state (this dev box's trusted_proxies may already
    // list extra hosts).
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('trusted_proxies', '127.0.0.1,::1')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    // ═══════════════════════════════════════════════════════════════════
    echo "-- 1. require_https OFF: never shows a banner, regardless of connection state --\n";
    https_enf_set_require($prefix, '0');

    $off_tls = https_enf_probe($root, $php, ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.9']);
    t('off + genuine TLS: probe ran', $off_tls !== null);
    t('off + genuine TLS: enabled === false', ($off_tls['status']['enabled'] ?? true) === false);
    t('off + genuine TLS: show_banner === false', ($off_tls['status']['show_banner'] ?? true) === false);

    $off_plain = https_enf_probe($root, $php, ['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9']);
    t('off + plaintext: show_banner === false even though unverified',
        ($off_plain['status']['show_banner'] ?? true) === false);
    t('off + plaintext: reason is still reported honestly as plaintext',
        ($off_plain['status']['reason'] ?? '') === 'plaintext',
        'reason=' . ($off_plain['status']['reason'] ?? '?'));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 2. require_https ON + genuine TLS: verified, no banner --\n";
    https_enf_set_require($prefix, '1');

    $on_tls = https_enf_probe($root, $php, ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.9'], true);
    t('on + genuine TLS: probe ran', $on_tls !== null);
    t('on + genuine TLS: reason === tls', ($on_tls['status']['reason'] ?? '') === 'tls');
    t('on + genuine TLS: verified === true', ($on_tls['status']['verified'] ?? false) === true);
    t('on + genuine TLS: show_banner === false', ($on_tls['status']['show_banner'] ?? true) === false);
    t('on + genuine TLS: health check severity is "ok"',
        ($on_tls['health']['severity'] ?? '') === 'ok', 'severity=' . ($on_tls['health']['severity'] ?? '?'));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 3. require_https ON + spoofed header from an UNTRUSTED peer: untrusted_proxy --\n";
    $on_spoof = https_enf_probe($root, $php, [
        'SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ], true);
    t('on + spoofed header: probe ran', $on_spoof !== null);
    t('on + spoofed header: reason === untrusted_proxy',
        ($on_spoof['status']['reason'] ?? '') === 'untrusted_proxy',
        'reason=' . ($on_spoof['status']['reason'] ?? '?'));
    t('on + spoofed header: verified === false', ($on_spoof['status']['verified'] ?? true) === false);
    t('on + spoofed header: show_banner === true', ($on_spoof['status']['show_banner'] ?? false) === true);
    t('on + spoofed header: message names the Trusted Reverse Proxies setting, not a generic warning',
        stripos((string) ($on_spoof['status']['message'] ?? ''), 'trust') !== false,
        (string) ($on_spoof['status']['message'] ?? ''));
    t('on + spoofed header: health check severity is "warn", never "critical"',
        ($on_spoof['health']['severity'] ?? '') === 'warn', 'severity=' . ($on_spoof['health']['severity'] ?? '?'));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 4. require_https ON + no TLS evidence at all: plaintext --\n";
    $on_plain = https_enf_probe($root, $php, ['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.9'], true);
    t('on + plaintext: probe ran', $on_plain !== null);
    t('on + plaintext: reason === plaintext',
        ($on_plain['status']['reason'] ?? '') === 'plaintext', 'reason=' . ($on_plain['status']['reason'] ?? '?'));
    t('on + plaintext: show_banner === true', ($on_plain['status']['show_banner'] ?? false) === true);
    t('on + plaintext: message says the connection does not appear encrypted, not "proxy not trusted"',
        stripos((string) ($on_plain['status']['message'] ?? ''), 'encrypted') !== false,
        (string) ($on_plain['status']['message'] ?? ''));
    t('on + plaintext: health check severity is "warn"',
        ($on_plain['health']['severity'] ?? '') === 'warn');

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 5. require_https ON + spoofed header from a TRUSTED peer: still verifies (real proxies must keep working) --\n";
    $on_trusted = https_enf_probe($root, $php, [
        'SERVER_PORT' => '80', 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);
    t('on + trusted proxy: reason === tls', ($on_trusted['status']['reason'] ?? '') === 'tls',
        'reason=' . ($on_trusted['status']['reason'] ?? '?'));
    t('on + trusted proxy: show_banner === false', ($on_trusted['status']['show_banner'] ?? true) === false,
        'a real reverse-proxy deployment must not get nagged once trusted_proxies is configured correctly');

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 6. NEVER BLOCKS — structural proof (the hard requirement) --\n";

    // 6a. inc/https-enforcement.php is a pure computation file: it must
    //     contain ZERO exit/die/header()/http_response_code() calls of any
    //     kind. Tokenized, not grepped — a doc-comment mentioning "never
    //     redirects" must not itself trip the check (see this project's
    //     own GH#96/Phase-138 lesson about substring scans of prose).
    $logicSrc = (string) file_get_contents($root . '/inc/https-enforcement.php');
    $blockingTokens = 0;
    $tokens = token_get_all($logicSrc);
    for ($i = 0; $i < count($tokens); $i++) {
        $tk = $tokens[$i];
        if (!is_array($tk)) continue;
        if ($tk[0] === T_EXIT) { $blockingTokens++; continue; }
        if ($tk[0] === T_STRING && in_array(strtolower($tk[1]), ['header', 'http_response_code'], true)) {
            $blockingTokens++;
        }
    }
    t('inc/https-enforcement.php contains no exit/die/header()/http_response_code() calls',
        $blockingTokens === 0, "found $blockingTokens");

    // 6b. The banner include's embedded <script> never navigates the
    //     browser (no location.href / window.location / location.replace)
    //     — its only DOM effect anywhere is classList.remove('d-none') /
    //     classList.add('d-none') and sessionStorage.
    $bannerSrc = (string) file_get_contents($root . '/inc/https-enforcement-notice.php');
    t('the banner script never assigns window.location / location.href / location.replace',
        preg_match('/\blocation\s*(\.\s*href|\.\s*replace)\s*=|window\.location\s*=/i', $bannerSrc) === 0);
    t('the banner script never calls header(\'Location\') (it is a PHP+JS include, not an endpoint)',
        preg_match('/header\s*\(\s*[\'"]Location:/i', $bannerSrc) === 0);

    // 6c. api/https-enforcement-status.php is read-only and reports, never
    //     acts: its only non-2xx paths are the ordinary auth/method gates
    //     every read-only admin endpoint in this codebase uses, and those
    //     do not vary with the https_enforcement_status() answer.
    $apiSrc = (string) file_get_contents($root . '/api/https-enforcement-status.php');
    t('the API endpoint calls https_enforcement_status() exactly once, and only to report it (json_response)',
        substr_count($apiSrc, 'https_enforcement_status(') === 1
        && strpos($apiSrc, 'json_response(https_enforcement_status())') !== false);

    // 6d. Nothing ELSE in the tree has wired https_enforcement_status() or
    //     require_https_enabled() into a gate. Only the two files that
    //     actually CALL them (as opposed to merely mentioning them in a
    //     doc-comment, which several pages do — settings.php and
    //     status.php both explain in prose that they read the same
    //     canonical function the API endpoint calls) may call them.
    // Comments stripped before matching, tokenized rather than grepped —
    // a prose mention of the function name must not itself trip this,
    // the same reasoning this project's own test_https_detection.php §6
    // already applies to raw $_SERVER['HTTPS'] reads.
    $expectedCallers = [
        'inc/https-enforcement.php',
        'inc/health-check.php',
        'api/https-enforcement-status.php',
    ];
    $unexpectedCallers = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = str_replace('\\', '/', $f->getPathname());
        $rel = ltrim(substr($p, strlen(str_replace('\\', '/', $root))), '/');
        if (preg_match('#^(vendor|tests|tools|\.claude|node_modules)/#', $rel)) continue;
        if (in_array($rel, $expectedCallers, true)) continue;
        $src = (string) file_get_contents($p);
        $stripped = '';
        foreach (token_get_all($src) as $tk) {
            if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $stripped .= is_array($tk) ? $tk[1] : $tk;
        }
        if (strpos($stripped, 'https_enforcement_status(') !== false || strpos($stripped, 'require_https_enabled(') !== false) {
            $unexpectedCallers[] = $rel;
        }
    }
    t('no file outside the expected set calls https_enforcement_status()/require_https_enabled() (comments excluded)',
        $unexpectedCallers === [], implode(', ', $unexpectedCallers));

    // ═══════════════════════════════════════════════════════════════════
    echo "\n-- 7. Behavioural check: the live endpoint never redirects, whatever the verification state --\n";
    // Unauthenticated calls under two different require_https states both
    // stop at the ordinary admin auth gate (403), never a 3xx redirect —
    // proving the auth gate, not the verification state, decides the
    // response shape.
    foreach (['0' => 'off', '1' => 'on'] as $val => $label) {
        https_enf_set_require($prefix, $val);
        $harness = sys_get_temp_dir() . '/newui-https-enf-endpoint-' . getmypid() . '-' . mt_rand() . '.php';
        $code = "<?php\n\$_SERVER['REQUEST_METHOD']='GET';\n"
              . "\$_SERVER['SCRIPT_NAME']='/newui/api/https-enforcement-status.php';\n"
              . 'require ' . var_export($root . '/api/https-enforcement-status.php', true) . ";\n";
        @file_put_contents($harness, $code);
        $out = (string) @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($harness) . ' 2>&1');
        @unlink($harness);
        t("require_https=$label: unauthenticated call to the endpoint never emits a Location header",
            stripos($out, 'Location:') === false, trim(substr($out, 0, 200)));
    }

} finally {
    if ($origRequireHttps !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'require_https'", [$origRequireHttps]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'require_https'");
    }
    if ($origTrustedProxies !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'trusted_proxies'", [$origTrustedProxies]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'trusted_proxies'");
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
