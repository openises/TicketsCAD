<?php
/**
 * GH#142 (Phase 155) - agency logo: NOTHING CHANGES for an install that never
 * configures one.
 *
 * The reporter's own requirement (public issue #142): "fall back to the current
 * icon when none is set, so this is purely additive and never breaks an install
 * that doesn't configure it". This is the regression for that sentence, in two
 * states, against the REAL pages and endpoints over real HTTP:
 *
 *   A. a fresh install: the table exists and holds NO row, every setting at its
 *      default;
 *   B. an install that has not run the migration yet: the table does not exist
 *      (the mid-upgrade state), so every reader must degrade to "no logo" and
 *      every write must say what to run, with no error text leaking.
 *
 * In both: login.php still renders the broadcast-pin glyph and no logo; the
 * signed-in top bar still renders assets/logo-light.png and the word "Tickets"
 * and emits no branding config; the ICS print document has no <img> and the
 * original footer, for every form type, and is identical to the document with
 * printing branding switched OFF; the public board JSON has no logo keys;
 * print.css's text banner rule is untouched. Equality-only checks against the
 * source (never an inequality against `git show HEAD`, which rots the moment the
 * commit lands - the Phase 142 lesson).
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_noop.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_cli.php';
require_once __DIR__ . '/_gh142_http.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding is a no-op until configured ===\n\n";

if (!branding_table_exists()) {
    echo "SKIP: branding_logos is missing (run sql/run_migrations.php)\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
$srv = gh142_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}
$http = 'http://127.0.0.1:' . $srv['port'];
$base = dirname(__DIR__);

$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
$settingsBefore = db_fetch_all("SELECT `name`, `value` FROM " . db_table('settings') . " WHERE `name` LIKE 'branding\\_%' OR `name` = 'public_board_enabled'");
$cleanup = ['users' => [], 'cookies' => []];
db_query("DELETE FROM " . db_table('branding_logos'));
foreach (branding_setting_definitions() as $name => $def) {
    db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$def['default'], $name]);
}
register_shutdown_function(function () use ($srv, $snapshot, $settingsBefore, &$cleanup) {
    pb_test_stop_server($srv);
    try {
        // a test that died while the table was renamed must not leave the install broken
        if (!db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branding_logos'")
            && db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branding_logos_zz142bak'")) {
            db_query("RENAME TABLE " . db_table('branding_logos_zz142bak') . " TO " . db_table('branding_logos'));
        }
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
        foreach ($settingsBefore as $s) {
            db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$s['value'], $s['name']]);
        }
        foreach ($cleanup['users'] as $uid) {
            db_query("DELETE FROM " . db_table('user_roles') . " WHERE `user_id` = ?", [$uid]);
            db_query("DELETE FROM " . db_table('user') . " WHERE `id` = ?", [$uid]);
        }
        db_query("DELETE FROM " . db_table('newui_audit_log') . " WHERE `user_name` LIKE 'zz142-%'");
    } catch (Throwable $e) { /* best effort */ }
    gh142_cleanup_cookies($cleanup['cookies']);
});

$pw = 'Zz142-pw-Noop1!';
$uid = gh142_make_user('zz142-noop-super', $pw, gh142_role_id('Super Admin'));
$cleanup['users'][] = $uid;
$ck = gh142_login($http, 'zz142-noop-super', $pw);
$cleanup['cookies'][] = $ck;
t('fixture: Super Admin logged in over real HTTP', $ck !== null);
if ($ck === null) { echo "\n=== $pass passed, $fail failed ===\n"; exit(1); }
$csrf = gh142_csrf($http, $ck);

$productMark = '<img src="assets/logo-light.png" alt="Tickets" height="36" class="d-block">';
$types = ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221', 'custom'];

$sweep = function (string $state) use ($http, $ck, $productMark, $types) {
    $l = gh142_request('GET', $http . '/login.php');
    t("[{$state}] login.php renders (200)", $l !== null && $l['status'] === 200);
    t("[{$state}] login.php still shows the bi-broadcast-pin glyph", strpos($l['body'], '<i class="bi bi-broadcast-pin fs-1 text-primary"></i>') !== false);
    t("[{$state}] login.php has no branding logo <img> and does not link branding.css", strpos($l['body'], 'branding-logo') === false && strpos($l['body'], 'branding.css') === false);
    t("[{$state}] the login title and version are unchanged", strpos($l['body'], '<h4 class="mt-2">Tickets NewUI</h4>') !== false);

    $p = gh142_request('GET', $http . '/reports.php', $ck);
    t("[{$state}] a signed-in page renders (200)", $p !== null && $p['status'] === 200);
    t("[{$state}] the top bar still renders the product mark and the word Tickets", strpos($p['body'], $productMark) !== false && strpos($p['body'], '<span class="fw-semibold">Tickets</span>') !== false);
    t("[{$state}] ...no agency mark, no <img> from branding anywhere on the page", strpos($p['body'], 'branding-size-navbar') === false && !preg_match('/<img[^>]*branding-logo/', $p['body']));
    t("[{$state}] ...and NO brandingConfig block is emitted", strpos($p['body'], 'id="brandingConfig"') === false);

    $ics = gh142_probe('ics', ['types' => $types]);
    foreach ($types as $type) {
        $h = $ics[$type] ?? '';
        t("[{$state}] ICS {$type}: no <img>, no letterhead, the original footer", $h !== '' && strpos($h, '<img') === false && strpos($h, 'bl-lh') === false
            && strpos($h, '<div class="footer">Generated by Tickets CAD v4 &mdash; ') !== false);
    }

    $b = gh142_request('GET', $http . '/api/public-board.php');
    $bj = $b !== null ? json_decode($b['body'], true) : null;
    t("[{$state}] the public board still answers", $b !== null && in_array($b['status'], [200, 503], true));
    t("[{$state}] ...and its JSON has no logo keys", !is_array($bj) || !isset($bj['board']) || (!array_key_exists('logo_url', $bj['board']) && !array_key_exists('logo_alt', $bj['board'])));

    $s = gh142_request('GET', $http . '/api/branding-logo.php?k=' . str_repeat('a', 32));
    t("[{$state}] the serving endpoint answers a plain 404", $s !== null && $s['status'] === 404 && strpos($s['body'], 'SQL') === false && strpos($s['body'], 'branding_logos') === false);
};

// ── A. a fresh install: table present, no row, defaults ────────────────
t('state A: the table holds no logo row', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos')) === 0);
$sweep('A: no logo');

// Printing branding switched OFF must give the SAME document as no logo at all.
$noLogoDocs = gh142_probe('ics', ['types' => $types]);
db_query("UPDATE " . db_table('settings') . " SET `value` = '0' WHERE `name` = 'branding_print'");
$printOffDocs = gh142_probe('ics', ['types' => $types]);
db_query("UPDATE " . db_table('settings') . " SET `value` = '1' WHERE `name` = 'branding_print'");
$normalize = function (array $docs) { return array_map(function ($h) { return preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', 'DATE', $h); }, $docs); };
t('state A: with no logo, the ICS documents equal the documents with branding_print = 0 (apart from the timestamp)', $normalize($noLogoDocs) === $normalize($printOffDocs));

// Static, equality-only source checks.
$printCss = (string) file_get_contents($base . '/assets/css/print.css');
t('print.css still carries the text banner rule, untouched',
    strpos($printCss, "content: \"Tickets CAD \\2014  Printed \" attr(data-print-date);") !== false);
t('print.css carries no letterhead rules (they live in branding.css, which only a logo makes visible)', strpos($printCss, 'letterhead') === false);
$loginSrc = (string) file_get_contents($base . '/login.php');
t('login.php keeps the glyph in its markup as the fallback branch', strpos($loginSrc, '<i class="bi bi-broadcast-pin fs-1 text-primary"></i>') !== false);
$navSrc = (string) file_get_contents($base . '/inc/navbar.php');
t('inc/navbar.php keeps the product mark literal in its markup as the fallback branch', strpos($navSrc, $productMark) !== false);
t('...and only the opt-in branding_navbar = agency mode can replace it', strpos($navSrc, 'branding_navbar_brand_html()') !== false);
$aboutSrc = (string) file_get_contents($base . '/about.php');
t('about.php was not touched by branding at all', stripos($aboutSrc, 'branding') === false);

// ── B. the table does not exist (mid-upgrade) ──────────────────────────
db_query("RENAME TABLE " . db_table('branding_logos') . " TO " . db_table('branding_logos_zz142bak'));
try {
    $sweep('B: table missing');

    $page = gh142_request('GET', $http . '/branding-admin.php', $ck);
    t('[B] the admin page still renders and names the command to run', $page !== null && $page['status'] === 200 && strpos($page['body'], 'php sql/run_migrations.php') !== false);
    $st = gh142_json(gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ck));
    t('[B] the admin status reports schema_ready = false (not an error)', is_array($st) && $st['schema_ready'] === false && $st['install'] === ['light' => null, 'dark' => null] && $st['orgs'] === []);
    $up = gh142_upload($http, $ck, ['action' => 'upload_logo', 'scope' => 'install', 'variant' => 'light', 'csrf_token' => $csrf], file_get_contents($base . '/assets/logo-light.png'));
    $uj = gh142_json($up);
    t('[B] an upload is a 409 that names php sql/run_migrations.php', $up !== null && $up['status'] === 409 && strpos((string) ($uj['error'] ?? ''), 'php sql/run_migrations.php') !== false);
    $sv = gh142_post_json($http, $ck, ['action' => 'save_settings', 'settings' => ['branding_login' => '0'], 'csrf_token' => $csrf]);
    t('[B] saving settings is a 409 too', $sv !== null && $sv['status'] === 409);
    $dl = gh142_post_json($http, $ck, ['action' => 'delete_logo', 'scope' => 'install', 'variant' => 'light', 'csrf_token' => $csrf]);
    t('[B] deleting is a 409 too', $dl !== null && $dl['status'] === 409);
    t('[B] no error text, SQL or table name leaked in any of those bodies',
        !preg_match('/SQLSTATE|Table .* doesn\'t exist|PDOException|branding_logos/i', ($up['body'] ?? '') . ($sv['body'] ?? '') . ($dl['body'] ?? '')));
} finally {
    db_query("RENAME TABLE " . db_table('branding_logos_zz142bak') . " TO " . db_table('branding_logos'));
}
t('the table is back after the schema-missing checks', branding_table_exists() || (bool) db_fetch_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'branding_logos'"));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
