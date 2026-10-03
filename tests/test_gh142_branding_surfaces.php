<?php
/**
 * GH#142 (Phase 155) - agency logo: every place it renders.
 *
 * Drives the REAL pages and endpoints over real HTTP (a local `php -S`; every
 * request is a fresh PHP process, so a setting changed in the database is seen
 * by the very next request) and the real functions in fresh child processes:
 *
 *   - login.php: the logo <img> when configured and branding_login = 1, the
 *     glyph when 0; branding_login_size maps to the size class;
 *   - the navbar (reports.php): `product` keeps the product mark, `agency` swaps
 *     it, `agency` with no logo falls back to the product mark;
 *   - the print letterhead config block: emitted only when branding_print = 1 AND
 *     a logo applies, carries the configured size/align/banner, is JSON-safe;
 *   - the ICS print document: for each of the nine built-in form types AND a
 *     custom type, the data: URI letterhead when branding_print = 1 and none
 *     when 0; the footer follows branding_print_banner; the incident's OWN
 *     organization's logo is used (not the viewer's); through the real
 *     export_pdf endpoint too;
 *   - the public board JSON: logo_url (and logo_alt) only when
 *     branding_public_board = 1, per organization, inherited, and the ETag moves
 *     when the logo is replaced; the page's own JS accepts only the exact
 *     capability URL shape;
 *   - the CSS size classes carry the same numbers as branding_size_px();
 *   - a HOSTILE alt text ("><script>) is escaped on every surface.
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_surfaces.php
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

echo "=== GH#142 - branding surfaces ===\n\n";

if (!branding_table_exists() || !branding_gd_available()) {
    echo "SKIP: branding_logos is missing or GD is not loaded\n";
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
$cleanup = ['users' => [], 'orgs' => [], 'tickets' => [], 'forms' => [], 'cookies' => []];
db_query("DELETE FROM " . db_table('branding_logos'));
register_shutdown_function(function () use ($srv, $snapshot, $settingsBefore, &$cleanup) {
    pb_test_stop_server($srv);
    try {
        foreach ($cleanup['forms'] as $id) { db_query("DELETE FROM " . db_table('ics_forms') . " WHERE `id` = ?", [$id]); }
        foreach ($cleanup['tickets'] as $id) { db_query("DELETE FROM " . db_table('ticket') . " WHERE `id` = ?", [$id]); }
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($cleanup['users'] as $uid) {
            db_query("DELETE FROM " . db_table('user_roles') . " WHERE `user_id` = ?", [$uid]);
            db_query("DELETE FROM " . db_table('user') . " WHERE `id` = ?", [$uid]);
        }
        foreach ($cleanup['orgs'] as $oid) { db_query("DELETE FROM " . db_table('organizations') . " WHERE `id` = ?", [$oid]); }
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
        foreach ($settingsBefore as $s) {
            db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$s['value'], $s['name']]);
        }
        db_query("DELETE FROM " . db_table('newui_audit_log') . " WHERE `user_name` LIKE 'zz142-%'");
    } catch (Throwable $e) { /* best effort */ }
    gh142_cleanup_cookies($cleanup['cookies']);
});
$set = function (string $name, string $value) {
    $n = db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$value, $name])->rowCount();
    if ($n === 0 && !db_fetch_value("SELECT 1 FROM " . db_table('settings') . " WHERE `name` = ?", [$name])) {
        db_query("INSERT INTO " . db_table('settings') . " (`name`, `value`) VALUES (?, ?)", [$name, $value]);
    }
};
$logo = function (int $org, string $variant, int $w = 100) {
    $p = branding_process_upload(gh142_png($w, 40));
    $s = branding_store_logo($org, $variant, $p, 1, 'zz142');
    return ['key' => $s['asset_key'], 'sha' => $p['sha256'], 'url' => 'api/branding-logo.php?k=' . $s['asset_key']];
};
$HOSTILE = '"><script>alert(1)</script>';

$orgA = gh142_make_org('ZZ142 Org A');
$orgA2 = gh142_make_org('ZZ142 Org A child', $orgA);
$orgN = gh142_make_org('ZZ142 Org NoLogo');
array_push($cleanup['orgs'], $orgA, $orgA2, $orgN);
$pwS = 'Zz142-pw-Surf1!'; $pwA = 'Zz142-pw-SurfA1!';
$uSuper = gh142_make_user('zz142-surf-super', $pwS, gh142_role_id('Super Admin'));
$uOrgA  = gh142_make_user('zz142-surf-orga', $pwA, gh142_role_id('Org Admin'), $orgA);
array_push($cleanup['users'], $uSuper, $uOrgA);
$ckS = gh142_login($http, 'zz142-surf-super', $pwS);
$ckA = gh142_login($http, 'zz142-surf-orga', $pwA);
array_push($cleanup['cookies'], $ckS, $ckA);
t('fixture: Super Admin and Org Admin (org A) logged in over real HTTP', $ckS !== null && $ckA !== null);
if ($ckS === null || $ckA === null) { echo "\n=== $pass passed, $fail failed ===\n"; exit(1); }
$csrfS = gh142_csrf($http, $ckS);

$inst  = $logo(0, 'light', 111);
$instD = $logo(0, 'dark', 112);
$aLogo = $logo($orgA, 'light', 133);
$img = function (string $html) { return preg_match('/<img[^>]*branding-logo[^>]*>/', $html, $m) ? $m[0] : ''; };

// ── 1. Login ───────────────────────────────────────────────────────────
$set('branding_login', '1'); $set('branding_login_size', 'medium'); $set('branding_logo_alt', '');
$login = gh142_request('GET', $http . '/login.php');
$tag = $img($login['body']);
t('login.php shows the install-wide logo <img> when configured', $tag !== '' && strpos($tag, 'src="' . $inst['url'] . '"') !== false);
t('...instead of the broadcast-pin glyph', strpos($login['body'], 'bi-broadcast-pin') === false);
t('...and links branding.css', strpos($login['body'], 'assets/css/branding.css') !== false);
t('...a dark variant is emitted too (both images, theme CSS picks one)', strpos($login['body'], 'branding-logo-dark') !== false && strpos($login['body'], $instD['key']) !== false);
t('...with the default alt text', strpos($tag, 'alt="Agency logo"') !== false);
t('...the login title and version are unchanged', strpos($login['body'], 'Tickets NewUI') !== false);
t('login uses the INSTALL-wide logo even though an organization has its own (nothing is known pre-sign-in)',
    strpos($login['body'], $aLogo['key']) === false);
$set('branding_login', '0');
$login0 = gh142_request('GET', $http . '/login.php');
t('with branding_login = 0 the glyph is back and there is no logo <img>', strpos($login0['body'], 'bi-broadcast-pin') !== false && $img($login0['body']) === '');
t('...and branding.css is not even linked', strpos($login0['body'], 'branding.css') === false);
$set('branding_login', '1');
foreach (['small', 'medium', 'large'] as $sz) {
    $set('branding_login_size', $sz);
    $l = gh142_request('GET', $http . '/login.php');
    t("branding_login_size = {$sz} renders branding-size-login-{$sz}", strpos($img($l['body']), 'branding-size-login-' . $sz) !== false);
}
$set('branding_login_size', 'medium');

// ── 2. The navbar (reports.php, signed in) ─────────────────────────────
$set('branding_navbar', 'product');
$page = gh142_request('GET', $http . '/reports.php', $ckS);
t('navbar: product mode keeps the product mark byte for byte', strpos($page['body'], '<img src="assets/logo-light.png" alt="Tickets" height="36" class="d-block">') !== false);
t('...and renders no agency mark in the brand area', strpos($page['body'], 'branding-size-navbar') === false);
$set('branding_navbar', 'agency');
$page = gh142_request('GET', $http . '/reports.php', $ckS);
t('navbar: agency mode swaps in the agency logo', strpos($page['body'], 'branding-size-navbar') !== false && strpos($page['body'], $inst['key']) !== false);
t('...and the product mark is gone from the brand area', strpos($page['body'], '<img src="assets/logo-light.png" alt="Tickets" height="36" class="d-block">') === false);
t('...the word "Tickets" and the version label stay', strpos($page['body'], '<span class="fw-semibold">Tickets</span>') !== false);
$pageA = gh142_request('GET', $http . '/reports.php', $ckA);
t('navbar: an Org Admin scoped to org A sees ORG A\'s logo, not the install-wide one', strpos($pageA['body'], 'branding-size-navbar') !== false && strpos($pageA['body'], $aLogo['key']) !== false);
branding_delete_logo($orgA, 'light');
$pageA = gh142_request('GET', $http . '/reports.php', $ckA);
t('...after org A\'s logo is removed that viewer falls back to the install-wide one', strpos($pageA['body'], $inst['key']) !== false && strpos($pageA['body'], $aLogo['key']) === false);
$aLogo = $logo($orgA, 'light', 133);
$about = gh142_request('GET', $http . '/about.php', $ckS);
t('the About page keeps ITS OWN product mark and credits even in agency mode (only the shared top bar swaps)',
    strpos($about['body'], '<img src="assets/logo-light.png" alt="TicketsCAD" height="64" class="mb-3">') !== false
    && strpos($about['body'], '<h3 class="mb-1">TicketsCAD</h3>') !== false);

// agency mode with NO logo anywhere
db_query("DELETE FROM " . db_table('branding_logos'));
branding_reset_cache();
$page = gh142_request('GET', $http . '/reports.php', $ckS);
t('navbar: agency mode with NO logo anywhere falls back to the product mark',
    strpos($page['body'], '<img src="assets/logo-light.png" alt="Tickets" height="36" class="d-block">') !== false && strpos($page['body'], 'branding-size-navbar') === false);
$set('branding_navbar', 'product');
$inst  = $logo(0, 'light', 111);
$instD = $logo(0, 'dark', 112);
$aLogo = $logo($orgA, 'light', 133);

// ── 3. The print letterhead config block ───────────────────────────────
$set('branding_print', '1'); $set('branding_print_size', 'large'); $set('branding_print_align', 'right'); $set('branding_print_banner', 'keep');
$page = gh142_request('GET', $http . '/reports.php', $ckS);
$m = [];
preg_match('/<script type="application\/json" id="brandingConfig">(.*?)<\/script>/s', $page['body'], $m);
$cfg = isset($m[1]) ? json_decode($m[1], true) : null;
t('a signed-in page emits ONE brandingConfig JSON block when printing branding is on and a logo applies', $cfg !== null && substr_count($page['body'], 'id="brandingConfig"') === 1);
t('...carrying the capability URL of the install-wide LIGHT logo only', ($cfg['src'] ?? '') === $inst['url']);
t('...and the configured size, alignment and banner mode', ($cfg['size'] ?? '') === 'large' && ($cfg['align'] ?? '') === 'right' && ($cfg['banner'] ?? '') === 'keep');
t('...and links the letterhead script and styles', strpos($page['body'], 'assets/js/print-letterhead.js') !== false && strpos($page['body'], 'assets/css/branding.css') !== false);
$pageA = gh142_request('GET', $http . '/reports.php', $ckA);
preg_match('/id="brandingConfig">(.*?)<\/script>/s', $pageA['body'], $m2);
t('an Org Admin\'s printed pages carry THEIR organization\'s logo', (json_decode($m2[1] ?? '', true)['src'] ?? '') === $aLogo['url']);
$set('branding_print', '0');
$page0 = gh142_request('GET', $http . '/reports.php', $ckS);
t('with branding_print = 0 NO config block is emitted', strpos($page0['body'], 'id="brandingConfig"') === false);
t('...but the letterhead script is still linked (it also stamps the print date on every page)', strpos($page0['body'], 'print-letterhead.js') !== false);
$set('branding_print', '1');
$set('branding_print_size', 'medium'); $set('branding_print_align', 'center'); $set('branding_print_banner', 'replace');

// ── 4. ICS print/PDF: ten form types, three states, incident organization ─
$types = ['213', '214', '202', '205', '205a', '213rr', '206', '214a', '221', 'custom'];
$footerKept = 'Generated by Tickets CAD v4';
$setPrint = function (string $print, string $banner) use ($set) { $set('branding_print', $print); $set('branding_print_banner', $banner); };
$setPrint('1', 'replace');
$htmls = gh142_probe('ics', ['types' => $types]);
foreach ($types as $type) {
    $h = $htmls[$type] ?? '';
    t("ICS {$type}: the letterhead data: URI is present when branding_print = 1", strpos($h, '<img src="data:image/png;base64,') !== false && strpos($h, 'class="bl-lh"') !== false);
    t("ICS {$type}: banner = replace drops the product footer line", strpos($h, $footerKept) === false && strpos($h, 'Generated &mdash;') !== false);
    t("ICS {$type}: the letterhead comes BEFORE the form title", strpos($h, 'class="bl-lh"') < strpos($h, '<h1>'));
}
$setPrint('1', 'keep');
$htmls = gh142_probe('ics', ['types' => $types]);
foreach ($types as $type) {
    $h = $htmls[$type] ?? '';
    t("ICS {$type}: banner = keep retains the product footer line", strpos($h, $footerKept) !== false && strpos($h, '<img src="data:image/png;base64,') !== false);
}
$setPrint('0', 'replace');
$htmls = gh142_probe('ics', ['types' => $types]);
foreach ($types as $type) {
    $h = $htmls[$type] ?? '';
    t("ICS {$type}: with branding_print = 0 there is NO <img> and the original footer", strpos($h, '<img') === false && strpos($h, $footerKept) !== false && strpos($h, 'bl-lh') === false);
}
$setPrint('1', 'replace');

// The incident's OWN organization's logo, then the viewer's, then install-wide.
db_query("INSERT INTO " . db_table('ticket') . " (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`facility`,`rec_facility`,`org_id`)
          VALUES (0,'','1 ZZ142 Way','Testville','MN',44.8,-93.3,NOW(),'zz142 ticket','zz142',2,1,NOW(),0,0,?)", [$orgA]);
$tkA = (int) db_insert_id(); $cleanup['tickets'][] = $tkA;
db_query("INSERT INTO " . db_table('ticket') . " (`in_types_id`,`contact`,`street`,`city`,`state`,`lat`,`lng`,`date`,`scope`,`description`,`status`,`severity`,`updated`,`facility`,`rec_facility`,`org_id`)
          VALUES (0,'','2 ZZ142 Way','Testville','MN',44.8,-93.3,NOW(),'zz142 ticket n','zz142',2,1,NOW(),0,0,?)", [$orgN]);
$tkN = (int) db_insert_id(); $cleanup['tickets'][] = $tkN;
$uriOf = function (string $html) { return preg_match('/<img src="(data:image\/png;base64,[^"]+)"/', $html, $mm) ? $mm[1] : ''; };
$shaOfUri = function (string $uri) { return hash('sha256', (string) base64_decode(substr($uri, strlen('data:image/png;base64,')), true)); };
$aSha = db_fetch_value("SELECT `sha256` FROM " . db_table('branding_logos') . " WHERE `asset_key` = ?", [$aLogo['key']]);
$iSha = db_fetch_value("SELECT `sha256` FROM " . db_table('branding_logos') . " WHERE `asset_key` = ?", [$inst['key']]);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T', 'incident_id' => $tkA]])['213'];
t('an ICS form on an incident owned by org A prints ORG A\'s logo (not the viewer\'s)', $shaOfUri($uriOf($h)) === $aSha);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T', 'incident_id' => $tkA], 'session_org' => 0])['213'];
t('...regardless of the viewer\'s own organization', $shaOfUri($uriOf($h)) === $aSha);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T', 'incident_id' => $tkN]])['213'];
t('an incident whose organization has NO logo (and no ancestor with one) falls back to install-wide', $shaOfUri($uriOf($h)) === $iSha);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T']])['213'];
t('a standalone form with no incident and no viewer organization prints the install-wide logo', $shaOfUri($uriOf($h)) === $iSha);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T'], 'session_org' => $orgA])['213'];
t('a standalone form falls back to the VIEWER\'S organization', $shaOfUri($uriOf($h)) === $aSha);
$h = gh142_probe('ics', ['types' => ['213'], 'row' => ['id' => 1, 'title' => 'T', 'incident_id' => 987654321]])['213'];
t('an incident id that does not exist is not an error (install-wide)', $shaOfUri($uriOf($h)) === $iSha);

// ...and through the REAL export_pdf endpoint, signed in.
$save = gh142_request('POST', $http . '/api/ics-forms.php', $ckS, json_encode([
    'csrf_token' => $csrfS, 'action' => 'save', 'form_type' => '213', 'title' => 'ZZ142 form',
    'incident_id' => $tkA, 'form_data' => ['to_name' => 'A', 'subject' => 'S', 'message' => 'm'],
]), ['Content-Type: application/json']);
$sj = gh142_json($save);
t('fixture: a real ICS-213 form was saved on org A\'s incident through the real endpoint', $save !== null && $save['status'] === 200 && !empty($sj['id']));
if (!empty($sj['id'])) {
    $cleanup['forms'][] = (int) $sj['id'];
    $exp = gh142_request('POST', $http . '/api/ics-forms.php', $ckS, json_encode(['csrf_token' => $csrfS, 'action' => 'export_pdf', 'id' => (int) $sj['id']]), ['Content-Type: application/json']);
    $ej = gh142_json($exp);
    t('the real export_pdf endpoint carries ORG A\'s letterhead for that form', $exp['status'] === 200 && $shaOfUri($uriOf((string) ($ej['html'] ?? ''))) === $aSha);
}
$save2 = gh142_request('POST', $http . '/api/ics-forms.php', $ckS, json_encode([
    'csrf_token' => $csrfS, 'action' => 'save', 'form_type' => '214', 'title' => 'ZZ142 standalone',
    'form_data' => ['incident_name' => 'x'],
]), ['Content-Type: application/json']);
$sj2 = gh142_json($save2);
if (!empty($sj2['id'])) {
    $cleanup['forms'][] = (int) $sj2['id'];
    $exp = gh142_request('POST', $http . '/api/ics-forms.php', $ckS, json_encode(['csrf_token' => $csrfS, 'action' => 'export_pdf', 'id' => (int) $sj2['id']]), ['Content-Type: application/json']);
    t('a standalone form exported by the (no-organization) Super Admin prints the install-wide logo',
        $exp['status'] === 200 && $shaOfUri($uriOf((string) (gh142_json($exp)['html'] ?? ''))) === $iSha);
}

// ── 5. Public incident board ───────────────────────────────────────────
$set('public_board_enabled', '1'); $set('branding_public_board', '1');
db_query("UPDATE " . db_table('organizations') . " SET `public_board_enabled` = 1, `public_board_slug` = ? WHERE `id` = ?", ['zz142-a', $orgA]);
db_query("UPDATE " . db_table('organizations') . " SET `public_board_enabled` = 1, `public_board_slug` = ? WHERE `id` = ?", ['zz142-a-child', $orgA2]);
db_query("UPDATE " . db_table('organizations') . " SET `public_board_enabled` = 1, `public_board_slug` = ? WHERE `id` = ?", ['zz142-nologo', $orgN]);
$shared = gh142_json(gh142_request('GET', $http . '/api/public-board.php'));
t('the shared board JSON carries the install-wide logo_url', ($shared['board']['logo_url'] ?? '') === $inst['url']);
t('...and a logo_alt', ($shared['board']['logo_alt'] ?? '') === 'Agency logo');
$ob = gh142_json(gh142_request('GET', $http . '/api/public-board.php?org=zz142-a'));
t('an organization\'s board carries THAT organization\'s logo', ($ob['board']['logo_url'] ?? '') === $aLogo['url']);
$child = gh142_json(gh142_request('GET', $http . '/api/public-board.php?org=zz142-a-child'));
t('a child organization\'s board INHERITS its parent\'s logo', ($child['board']['logo_url'] ?? '') === $aLogo['url']);
$nl = gh142_json(gh142_request('GET', $http . '/api/public-board.php?org=zz142-nologo'));
t('an organization with no logo falls back to the install-wide one', ($nl['board']['logo_url'] ?? '') === $inst['url']);
$etag1 = gh142_request('GET', $http . '/api/public-board.php')['headers']['etag'] ?? '';
$inst = $logo(0, 'light', 120);   // replace: new key
$etag2 = gh142_request('GET', $http . '/api/public-board.php')['headers']['etag'] ?? '';
t('replacing the logo CHANGES the board ETag (so a poller\'s 304 cannot hide it)', $etag1 !== '' && $etag2 !== '' && $etag1 !== $etag2);
$set('branding_public_board', '0');
$off = gh142_json(gh142_request('GET', $http . '/api/public-board.php'));
t('with branding_public_board = 0 the board JSON has NO logo_url and NO logo_alt', is_array($off) && !array_key_exists('logo_url', $off['board']) && !array_key_exists('logo_alt', $off['board']));
$set('branding_public_board', '1');
$set('branding_logo_alt', $HOSTILE);
$hostileBoard = gh142_request('GET', $http . '/api/public-board.php');
t('a hostile alt reaches the board JSON as DATA (decoded exactly), never as markup', (gh142_json($hostileBoard)['board']['logo_alt'] ?? '') === $HOSTILE
    && strpos($hostileBoard['headers']['content-type'] ?? '', 'application/json') === 0);
$set('branding_logo_alt', '');
db_query("DELETE FROM " . db_table('branding_logos'));
branding_reset_cache();
$none = gh142_json(gh142_request('GET', $http . '/api/public-board.php'));
t('with no logo the board JSON has no logo keys', !array_key_exists('logo_url', $none['board']));
$inst = $logo(0, 'light', 111); $instD = $logo(0, 'dark', 112); $aLogo = $logo($orgA, 'light', 133);

// The page's own JS accepts only the exact capability URL shape.
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
$pageSrc = (string) file_get_contents($base . '/public-board.php');
if ($node !== null && preg_match('/<script>([\s\S]*)<\/script>/', $pageSrc, $mm)) {
    $harness = sys_get_temp_dir() . '/gh142_pb_' . getmypid() . '.js';
    $js = "global.window = global;\n" . $mm[1] . "\n" . <<<'JS'
var PB = window.PublicBoardRender, out = [];
function chk(n, c) { out.push((c ? 'PASS|' : 'FAIL|') + n); }
var k = 'abcdef0123456789abcdef0123456789';
chk('accepts the exact capability URL', PB.logoUrl({ logo_url: 'api/branding-logo.php?k=' + k }) === 'api/branding-logo.php?k=' + k);
chk('rejects an absolute URL', PB.logoUrl({ logo_url: 'https://evil.example/x.png' }) === '');
chk('rejects a protocol-relative URL', PB.logoUrl({ logo_url: '//evil.example/x.png' }) === '');
chk('rejects a javascript: URL', PB.logoUrl({ logo_url: 'javascript:alert(1)' }) === '');
chk('rejects a data: URL', PB.logoUrl({ logo_url: 'data:image/svg+xml,<svg onload=alert(1)>' }) === '');
chk('rejects a traversal prefix', PB.logoUrl({ logo_url: '../api/branding-logo.php?k=' + k }) === '');
chk('rejects an absolute path', PB.logoUrl({ logo_url: '/api/branding-logo.php?k=' + k }) === '');
chk('rejects a trailing parameter', PB.logoUrl({ logo_url: 'api/branding-logo.php?k=' + k + '&x=1' }) === '');
chk('rejects an uppercase key', PB.logoUrl({ logo_url: 'api/branding-logo.php?k=' + k.toUpperCase() }) === '');
chk('rejects a 31-character key', PB.logoUrl({ logo_url: 'api/branding-logo.php?k=' + k.substring(1) }) === '');
chk('rejects a trailing newline (no multiline bypass)', PB.logoUrl({ logo_url: 'api/branding-logo.php?k=' + k + '\n' }) === '');
chk('rejects a non-string', PB.logoUrl({ logo_url: { toString: function () { return 'api/branding-logo.php?k=' + k; } } }) === '');
chk('rejects a missing board', PB.logoUrl(null) === '' && PB.logoUrl(undefined) === '' && PB.logoUrl({}) === '');
chk('caps the alt at 100 characters', PB.logoAlt({ logo_alt: new Array(201).join('x') }).length === 100);
chk('a non-string alt is empty', PB.logoAlt({ logo_alt: 5 }) === '');
console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $raw = (string) @shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
    @unlink($harness);
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', $line, 2);
        if (count($parts) === 2) { t('[public-board.php JS] ' . $parts[1], $parts[0] === 'PASS'); }
    }
    t('public-board.php sets the logo with setAttribute, never innerHTML', strpos($pageSrc, "img.setAttribute('src', url)") !== false
        && strpos($pageSrc, "img.setAttribute('alt', pbLogoAlt(board))") !== false);
} else {
    echo "[SKIP] node is not available: the public-board.php JS checks were not run\n";
}

// ── 6. CSS size classes carry the same numbers as the PHP ──────────────
$css = (string) file_get_contents($base . '/assets/css/branding.css');
foreach (['login' => 'login', 'print' => 'print'] as $ctx => $cls) {
    foreach (['small', 'medium', 'large'] as $sz) {
        $selector = $ctx === 'login' ? ".branding-size-login-{$sz}" : ".branding-size-print-{$sz}";
        $ok = preg_match('/' . preg_quote($selector, '/') . '\s*\{\s*max-height:\s*(\d+)px;\s*\}/', $css, $mm) === 1
            && (int) $mm[1] === branding_size_px($ctx, $sz);
        t("branding.css {$selector} = branding_size_px('{$ctx}', '{$sz}') = " . branding_size_px($ctx, $sz) . 'px', $ok);
    }
}
t('branding.css has no hard-coded hex colours (theme variables only)', preg_match('/#[0-9a-fA-F]{3,8}\b/', preg_replace('~/\*.*?\*/~s', '', $css)) === 0);

// ── 7. A hostile alt text is escaped on every surface ──────────────────
$set('branding_logo_alt', $HOSTILE);
$set('branding_navbar', 'agency');
$set('branding_print', '1'); $set('branding_print_banner', 'replace');
$escaped = 'alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"';
$l = gh142_request('GET', $http . '/login.php');
t('hostile alt, login.php: escaped in the attribute', strpos($l['body'], $escaped) !== false);
t('hostile alt, login.php: the raw string appears nowhere', strpos($l['body'], $HOSTILE) === false);
$p = gh142_request('GET', $http . '/reports.php', $ckS);
t('hostile alt, navbar: escaped in the attribute', strpos($p['body'], $escaped) !== false);
t('hostile alt, signed-in page: the raw string appears nowhere', strpos($p['body'], $HOSTILE) === false);
preg_match('/id="brandingConfig">(.*?)<\/script>/s', $p['body'], $mc);
t('hostile alt, brandingConfig JSON: valid JSON that decodes back to the exact text', (json_decode($mc[1] ?? '', true)['alt'] ?? '') === $HOSTILE);
t('hostile alt, brandingConfig JSON: no raw < > & \' or " other than JSON structure inside the block',
    isset($mc[1]) && strpos($mc[1], '<') === false && strpos($mc[1], '>') === false && strpos($mc[1], '&') === false && strpos($mc[1], "'") === false
    && strpos($mc[1], '\\u003C') !== false);
t('hostile alt, brandingConfig JSON: the block ends at ITS OWN closing tag (one script close, not two)', substr_count(substr($p['body'], strpos($p['body'], 'id="brandingConfig"'), strlen($mc[0] ?? '') + 40), '</script>') === 1);
$ics = gh142_probe('ics', ['types' => ['213']]);
t('hostile alt, ICS print document: escaped in the img attribute', strpos($ics['213'], $escaped) !== false && strpos($ics['213'], $HOSTILE) === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
