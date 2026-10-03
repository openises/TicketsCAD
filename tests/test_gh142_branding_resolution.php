<?php
/**
 * GH#142 (Phase 155) - agency logo: which logo applies where.
 *
 * Drives the REAL resolution functions. Anything that depends on a SETTING runs
 * in a fresh child process (tests/_gh142_probe.php), because get_variable()
 * caches the whole settings table for the life of a process: one process per
 * value, never an in-process UPDATE followed by a second read.
 *
 *   - organization -> parent -> grandparent -> install-wide -> none, in that order;
 *   - a depth cap of 8 and a cycle guard (a looping parent chain cannot hang);
 *   - branding_org_logos = 0 makes an uploaded organization logo IGNORED
 *     (the setting changes observable output, not just a stored value);
 *   - the dark variant is used when present; the light logo on a plate when it
 *     is absent and the fallback is `plate`; nothing extra for `as_is`; and the
 *     dark variant is only ever read from the SAME scope that won on light;
 *   - a missing table returns "no logo" without an exception;
 *   - garbage setting values fall back to their defaults;
 *   - a display organization of 0 uses the install-wide logo, NOT organization 1;
 *   - deleting an organization through the real api/organizations.php removes
 *     its logo rows.
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_resolution.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_cli.php';
require_once __DIR__ . '/_gh142_http.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding resolution ===\n\n";

if (!branding_table_exists() || !branding_gd_available()) {
    echo "SKIP: branding_logos is missing or GD is not loaded\n";
    echo "\n=== 0 passed, 0 failed ===\n";
    exit(0);
}

$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
$settingsBefore = db_fetch_all("SELECT `name`, `value` FROM " . db_table('settings') . " WHERE `name` LIKE 'branding\\_%'");
db_query("DELETE FROM " . db_table('branding_logos'));
$orgs = [];
register_shutdown_function(function () use ($snapshot, $settingsBefore, &$orgs) {
    try {
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($orgs as $oid) {
            db_query("DELETE FROM " . db_table('organizations') . " WHERE `id` = ?", [$oid]);
        }
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
        foreach ($settingsBefore as $s) {
            db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$s['value'], $s['name']]);
        }
        db_query("RENAME TABLE " . db_table('branding_logos_zz142bak') . " TO " . db_table('branding_logos'));
    } catch (Throwable $e) { /* best effort; the rename only exists if a test died mid-way */ }
});
$setSetting = function (string $name, string $value) {
    db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$value, $name]);
};
$logo = function (int $org, string $variant, int $w = 100): array {
    $p = branding_process_upload(gh142_png($w, 40));
    $s = branding_store_logo($org, $variant, $p, 1, 'zz142');
    return ['key' => $s['asset_key'], 'sha' => $p['sha256'], 'w' => $p['width']];
};
$probe = function (string $action, array $params = []) { return gh142_probe($action, $params); };

// Organization tree: grandparent G > parent P > child C, plus an unrelated U.
$G = gh142_make_org('ZZ142 Grandparent');
$P = gh142_make_org('ZZ142 Parent', $G);
$C = gh142_make_org('ZZ142 Child', $P);
$U = gh142_make_org('ZZ142 Unrelated');
array_push($orgs, $G, $P, $C, $U);

// ── 1. Inheritance order ───────────────────────────────────────────────
t('with no logo anywhere, resolution is null', $probe('scope', ['org' => $C]) === null);
$inst = $logo(0, 'light', 111);
$r = $probe('resolve', ['org' => $C]);
t('only an install-wide logo: the child gets the install-wide one', ($r['key'] ?? '') === $inst['key'] && ($r['scope'] ?? '') === 'install');
$lg = $logo($G, 'light', 120);
$r = $probe('resolve', ['org' => $C]);
t('a grandparent logo beats install-wide (child inherits UP two levels)', ($r['key'] ?? '') === $lg['key'] && ($r['scope'] ?? '') === 'org' && ($r['org_id'] ?? 0) === $G);
$lp = $logo($P, 'light', 130);
$r = $probe('resolve', ['org' => $C]);
t('a parent logo beats the grandparent\'s', ($r['key'] ?? '') === $lp['key'] && ($r['org_id'] ?? 0) === $P);
$lc = $logo($C, 'light', 140);
$r = $probe('resolve', ['org' => $C]);
t('the child\'s OWN logo beats the parent\'s', ($r['key'] ?? '') === $lc['key'] && ($r['org_id'] ?? 0) === $C);
$r = $probe('resolve', ['org' => $P]);
t('the parent still resolves to ITS own (inheritance runs only upward, never down)', ($r['key'] ?? '') === $lp['key']);
$r = $probe('resolve', ['org' => $U]);
t('an unrelated organization falls back to install-wide (it does not borrow a sibling branch)', ($r['key'] ?? '') === $inst['key'] && ($r['scope'] ?? '') === 'install');
branding_delete_logo($C, 'light');
$r = $probe('resolve', ['org' => $C]);
t('removing the child\'s logo returns it to the parent\'s', ($r['key'] ?? '') === $lp['key']);
$r = $probe('resolve', ['org' => 0]);
t('organization 0 is the install-wide logo', ($r['key'] ?? '') === $inst['key']);
$r = $probe('resolve', []);
t('no organization given is the install-wide logo', ($r['key'] ?? '') === $inst['key']);
t('the metadata carries width, height and mime and NEVER the image bytes',
    isset($r['width'], $r['height'], $r['mime']) && !isset($r['data_b64']) && !array_key_exists('bytes', $r));

// ── 2. Depth cap and cycle guard ───────────────────────────────────────
// A chain of ten organizations with a logo ONLY on the root: the walk stops at
// eight, so the leaf must NOT reach the root's logo and falls back instead.
$chain = [];
$parent = null;
for ($i = 0; $i < 10; $i++) {
    $oid = gh142_make_org('ZZ142 Deep ' . $i, $parent);
    $orgs[] = $oid;
    $chain[] = $oid;
    $parent = $oid;
}
$rootLogo = $logo($chain[0], 'light', 150);
$leaf = $chain[9];
$r = $probe('resolve', ['org' => $leaf]);
t('a 10-deep chain: the leaf does NOT reach a root logo beyond the depth cap of 8 (falls back to install-wide)',
    ($r['key'] ?? '') === $inst['key']);
$r = $probe('resolve', ['org' => $chain[7]]);
t('...but the eighth organization (7 hops up) DOES reach it', ($r['key'] ?? '') === $rootLogo['key']);
$r = $probe('resolve', ['org' => $chain[8]]);
t('...and the ninth (8 hops up) does not', ($r['key'] ?? '') === $inst['key']);

// A cycle: X's parent is Y and Y's parent is X (the write path forbids it, but a
// database edit or a bug could create one; the walk must not hang or recurse).
$X = gh142_make_org('ZZ142 Cycle X');
$Y = gh142_make_org('ZZ142 Cycle Y', $X);
array_push($orgs, $X, $Y);
db_query("UPDATE " . db_table('organizations') . " SET `parent_org_id` = ? WHERE `id` = ?", [$Y, $X]);
$t0 = microtime(true);
$r = gh142_run_php([__DIR__ . '/_gh142_probe.php', 'resolve', json_encode(['org' => $X])], 20);
t('a parent CYCLE terminates promptly (no hang) and falls back to install-wide',
    $r['code'] === 0 && (microtime(true) - $t0) < 15 && ((json_decode(trim($r['out']), true)['v']['key'] ?? '') === $inst['key']));

// ── 3. branding_org_logos = 0 makes organization logos ignored ─────────
$setSetting('branding_org_logos', '1');
$on = $probe('resolve', ['org' => $P]);
$setSetting('branding_org_logos', '0');
$off = $probe('resolve', ['org' => $P]);
$offImg = $probe('img', ['surface' => 'login', 'org' => $P]);
$setSetting('branding_org_logos', '1');
t('with organization logos ON the parent resolves to its own logo', ($on['key'] ?? '') === $lp['key']);
t('with organization logos OFF the SAME organization resolves to the install-wide logo (the setting changes output)',
    ($off['key'] ?? '') === $inst['key']);
t('...and the markup uses the install-wide key', is_string($offImg) && strpos($offImg, $inst['key']) !== false && strpos($offImg, $lp['key']) === false);
t('...the organization\'s row is KEPT, only ignored',
    (bool) db_fetch_value("SELECT 1 FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$P]));

// ── 4. Dark variant, plate fallback, as_is ─────────────────────────────
db_query("DELETE FROM " . db_table('branding_logos'));
branding_reset_cache();
$base = $logo(0, 'light', 160);
$setSetting('branding_dark_fallback', 'plate');
$plate = $probe('img', ['surface' => 'login']);
t('no dark variant + fallback plate: the light image carries the plate class',
    is_string($plate) && strpos($plate, 'branding-logo-plate') !== false && strpos($plate, 'branding-logo-dark') === false);
$setSetting('branding_dark_fallback', 'as_is');
$asis = $probe('img', ['surface' => 'login']);
t('no dark variant + fallback as_is: no plate class and no dark image',
    is_string($asis) && strpos($asis, 'branding-logo-plate') === false && strpos($asis, 'branding-logo-dark') === false
    && strpos($asis, 'branding-logo-light') !== false);
$setSetting('branding_dark_fallback', 'plate');
$dk = $logo(0, 'dark', 170);
$both = $probe('img', ['surface' => 'login']);
t('WITH a dark variant: both images are emitted, the dark one with its own key',
    is_string($both) && substr_count($both, '<img') === 2 && strpos($both, 'branding-logo-dark') !== false && strpos($both, $dk['key']) !== false);
t('...the light image is marked branding-has-dark and gets NO plate',
    strpos($both, 'branding-has-dark') !== false && strpos($both, 'branding-logo-plate') === false);
$rd = $probe('resolve', ['variant' => 'dark']);
t('branding_resolve(..., dark) returns the dark logo', ($rd['key'] ?? '') === $dk['key']);

// The dark variant is read from the SAME scope that won on light: org Q has a
// light logo and NO dark one, install-wide has both. Q must not mix in the
// install-wide dark logo with its own light one.
$Q = gh142_make_org('ZZ142 Mixed');
$orgs[] = $Q;
$ql = $logo($Q, 'light', 180);
$scope = $probe('scope', ['org' => $Q]);
t('an organization with a light logo and no dark one does NOT borrow the install-wide dark logo',
    ($scope['light']['key'] ?? '') === $ql['key'] && array_key_exists('dark', $scope) && $scope['dark'] === null);
$qImg = $probe('img', ['surface' => 'login', 'org' => $Q]);
t('...it gets the plate fallback instead', is_string($qImg) && substr_count($qImg, '<img') === 1 && strpos($qImg, 'branding-logo-plate') !== false);

// Each surface asks for its own size class.
foreach (['small', 'medium', 'large'] as $sz) {
    $setSetting('branding_login_size', $sz);
    $img = $probe('img', ['surface' => 'login']);
    t("login size '{$sz}' maps to branding-size-login-{$sz}", is_string($img) && strpos($img, 'branding-size-login-' . $sz) !== false);
}
$setSetting('branding_login_size', 'medium');
$nav = $probe('img', ['surface' => 'navbar']);
t('the navbar surface uses branding-size-navbar', is_string($nav) && strpos($nav, 'branding-size-navbar') !== false);
$pub = $probe('img', ['surface' => 'public']);
t('the public surface uses branding-size-public', is_string($pub) && strpos($pub, 'branding-size-public') !== false);

// ── 5. Garbage setting values fall back to defaults ────────────────────
$garbage = [
    'branding_login_size'    => ['gigantic', 'medium'],
    'branding_print_align'   => ['diagonal', 'center'],
    'branding_print_banner'  => ['maybe', 'replace'],
    'branding_dark_fallback' => ['neon', 'plate'],
    'branding_navbar'        => ['both', 'product'],
    'branding_login'         => ['yes', '1'],
    'branding_print'         => ['2', '1'],
];
foreach ($garbage as $name => [$bad, $default]) {
    $setSetting($name, $bad);
    $v = $probe('setting', ['name' => $name]);
    t("{$name} = '{$bad}' (written straight into the table) reads back as the default '{$default}'", $v === $default);
    $setSetting($name, $default);
}
$setSetting('branding_logo_alt', str_repeat('x', 150));
t('an over-long alt text reads back as the default (empty)', $probe('setting', ['name' => 'branding_logo_alt']) === '');
$setSetting('branding_logo_alt', "bad\x07alt");
t('an alt text with a control character reads back as the default', $probe('setting', ['name' => 'branding_logo_alt']) === '');
$setSetting('branding_logo_alt', '  Fire Dept  ');
t('a good alt text is trimmed and kept', $probe('setting', ['name' => 'branding_logo_alt']) === 'Fire Dept');
$setSetting('branding_logo_alt', '');
t('an unknown setting name reads as an empty string, not an error', $probe('setting', ['name' => 'branding_nonsense']) === '');
t('branding_normalize_setting() is the same pure rule (bool 1/0 only)',
    branding_normalize_setting('branding_login', '1') === '1' && branding_normalize_setting('branding_login', '0') === '0'
    && branding_normalize_setting('branding_login', 'true') === '1' /* default is 1 */ && branding_normalize_setting('branding_login', false) === '1');

// ── 6. A missing table is "no logo", never an exception ────────────────
db_query("RENAME TABLE " . db_table('branding_logos') . " TO " . db_table('branding_logos_zz142bak'));
try {
    t('with the table missing, branding_table_exists() is false', $probe('table_exists') === false);
    t('...resolution is null', $probe('scope', ['org' => 0]) === null);
    t('...the login markup is empty (the glyph stays)', $probe('login') === '');
    t('...the navbar markup is empty (the product mark stays)', $probe('navbar') === '');
    t('...the print config script is empty', $probe('print_script') === '');
    t('...the public URL is null', $probe('public_url') === null);
} finally {
    db_query("RENAME TABLE " . db_table('branding_logos_zz142bak') . " TO " . db_table('branding_logos'));
}

// ── 7. The display organization is NOT the home-org fallback ───────────
// Organization 1 (System Owner) exists on every install and org_user_home_id()
// returns 1 for anyone with no home organization. A global-scope viewer with no
// active organization must see the INSTALL-WIDE logo, never organization 1's.
db_query("DELETE FROM " . db_table('branding_logos'));
branding_reset_cache();
$iw = $logo(0, 'light', 190);
$o1 = $logo(1, 'light', 200);
$r = $probe('print_config', ['session_org' => 0]);
t('a viewer with active organization 0 gets the INSTALL-WIDE logo on the print config', ($r['src'] ?? '') === 'api/branding-logo.php?k=' . $iw['key']);
$r = $probe('print_config', ['session_org' => 1]);
t('a viewer whose active organization really is 1 gets organization 1\'s logo', ($r['src'] ?? '') === 'api/branding-logo.php?k=' . $o1['key']);
$r = $probe('print_config', []);
t('a viewer with NO session organization at all gets the install-wide logo (not organization 1)', ($r['src'] ?? '') === 'api/branding-logo.php?k=' . $iw['key']);

// ── 8. Deleting an organization removes its logo rows (real endpoint) ──
if (class_exists('CURLFile')) {
    $srv = gh142_start_server();
    if ($srv !== null) {
        $doomed = gh142_make_org('ZZ142 Doomed');
        $orgs[] = $doomed;
        $logo($doomed, 'light', 210);
        $logo($doomed, 'dark', 210);
        $pw = 'Zz142-pw-Del1!';
        $uid = gh142_make_user('zz142-orgdel-super', $pw, gh142_role_id('Super Admin'));
        $ck = gh142_login('http://127.0.0.1:' . $srv['port'], 'zz142-orgdel-super', $pw);
        t('fixture: Super Admin logged in', $ck !== null);
        $before = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$doomed]);
        // delete_org has always needed a CSRF token to run (api/organizations.php, Phase 155):
        // a request with none must change nothing, one with the session's token deletes.
        $base142 = 'http://127.0.0.1:' . $srv['port'];
        $noTok = gh142_request('POST', $base142 . '/api/organizations.php', $ck,
            json_encode(['action' => 'delete_org', 'id' => $doomed]), ['Content-Type: application/json']);
        t('delete_org with NO csrf token is refused (403) and the organization is still there',
            $noTok !== null && $noTok['status'] === 403
            && (bool) db_fetch_value("SELECT 1 FROM " . db_table('organizations') . " WHERE `id` = ?", [$doomed]));
        $resp = gh142_request('POST', $base142 . '/api/organizations.php', $ck,
            json_encode(['action' => 'delete_org', 'id' => $doomed, 'csrf_token' => gh142_csrf($base142, $ck)]),
            ['Content-Type: application/json']);
        $after = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$doomed]);
        t('fixture: the doomed organization had two logo rows', $before === 2);
        t('the real delete_org endpoint succeeds', $resp !== null && $resp['status'] === 200);
        t('...and its logo rows are gone with it', $after === 0);
        t('...the organization itself is gone', !db_fetch_value("SELECT 1 FROM " . db_table('organizations') . " WHERE `id` = ?", [$doomed]));
        t('...and the cleanup is audit-logged',
            (bool) db_fetch_value("SELECT 1 FROM " . db_table('newui_audit_log') . " WHERE `target_type` = 'branding_logo' AND `summary` LIKE ?", ['Removed 2 logo(s) with deleted organization%']));
        db_query("DELETE FROM " . db_table('user_roles') . " WHERE `user_id` = ?", [$uid]);
        db_query("DELETE FROM " . db_table('user') . " WHERE `id` = ?", [$uid]);
        db_query("DELETE FROM " . db_table('newui_audit_log') . " WHERE `user_name` LIKE 'zz142-%' OR `summary` LIKE 'Removed 2 logo(s) with deleted organization%' OR `summary` LIKE '%ZZ142%'");
        gh142_cleanup_cookies([$ck]);
        pb_test_stop_server($srv);
    } else {
        echo "[SKIP] could not start a local server for the organization-delete check\n";
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
