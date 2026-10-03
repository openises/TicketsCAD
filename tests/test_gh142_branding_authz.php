<?php
/**
 * GH#142 (Phase 155) - agency logo: who may change which logo.
 *
 * Three layers, because the boundary is only as strong as the weakest one:
 *
 *   1. PURE: branding_resolve_write_target() (the whole decision, driven
 *      directly the way pb_resolve_admin_write_org() is): the install-wide holder
 *      may name any organization; the org-scoped holder's organization is FORCED
 *      and a crafted other-organization id is a 403, never silently overridden.
 *   2. DATABASE: branding_resolve_caller_org_id() against real user_roles rows:
 *      one org-scoped grant resolves; global scope only, two distinct
 *      organizations, an expired grant and no grant all resolve to 0.
 *   3. REAL HTTP against the REAL api/branding-admin.php, with real accounts
 *      logged in through login.php and real multipart uploads: Super Admin, an
 *      Org Admin scoped to organization A, and a Dispatcher. Plus CSRF,
 *      unauthenticated, an SVG renamed .png, the organization-logos kill switch,
 *      a dark-only upload, invalid settings, and the audit trail.
 *
 * Also a TOKENIZED check that neither the endpoint nor the page falls back to
 * is_admin() (the documented leak the moment two permissions split by blast
 * radius). Tokenized, not grepped: the files' docblocks name it on purpose.
 *
 * @requires-db
 * Usage: php tests/test_gh142_branding_authz.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_http.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding authorization ===\n\n";

// ── 1. The pure decision ───────────────────────────────────────────────
$w = 'branding_resolve_write_target';
$r = $w(true, true, 0, 'install', null);
t('install-wide holder, install scope: ok, org 0', $r['ok'] && $r['org_id'] === 0);
$r = $w(false, true, 5, 'install', null);
t('org-scoped holder, install scope: 403', !$r['ok'] && $r['status'] === 403);
$r = $w(true, true, 0, 'org', 9);
t('install-wide holder may name ANY organization', $r['ok'] && $r['org_id'] === 9);
$r = $w(true, true, 0, 'org', null);
t('install-wide holder naming no organization: 400', !$r['ok'] && $r['status'] === 400);
$r = $w(false, true, 5, 'org', null);
t('org-scoped holder naming nothing is forced to their OWN organization', $r['ok'] && $r['org_id'] === 5);
$r = $w(false, true, 5, 'org', 5);
t('org-scoped holder naming their own organization: ok', $r['ok'] && $r['org_id'] === 5);
$r = $w(false, true, 5, 'org', 6);
t('org-scoped holder naming ANOTHER organization is a 403 (never silently overridden)', !$r['ok'] && $r['status'] === 403 && $r['org_id'] === null);
$r = $w(false, true, 0, 'org', null);
t('org-scoped holder with no single organization: 403 "No organization"', !$r['ok'] && $r['status'] === 403 && strpos((string) $r['error'], 'No organization') !== false);
$r = $w(false, false, 0, 'org', 5);
t('no permission at all: 403', !$r['ok'] && $r['status'] === 403);
$r = $w(false, false, 0, 'install', null);
t('no permission, install scope: 403', !$r['ok'] && $r['status'] === 403);
$r = $w(true, true, 0, 'galaxy', 1);
t('an unknown scope is a 400', !$r['ok'] && $r['status'] === 400);

// ── 2. branding_resolve_caller_org_id() against real grants ────────────
$orgA = gh142_make_org('ZZ142 Org A');
$orgB = gh142_make_org('ZZ142 Org B');
$roleOrgAdmin = gh142_role_id('Org Admin');
$roleDispatcher = gh142_role_id('Dispatcher');
$roleSuper = gh142_role_id('Super Admin');
$cleanup = ['users' => [], 'orgs' => [$orgA, $orgB], 'cookies' => []];
register_shutdown_function(function () use (&$cleanup) {
    try {
        foreach ($cleanup['users'] as $uid) {
            db_query("DELETE FROM " . db_table('user_roles') . " WHERE `user_id` = ?", [$uid]);
            db_query("DELETE FROM " . db_table('user') . " WHERE `id` = ?", [$uid]);
        }
        foreach ($cleanup['orgs'] as $oid) {
            db_query("DELETE FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$oid]);
            db_query("DELETE FROM " . db_table('organizations') . " WHERE `id` = ?", [$oid]);
        }
        db_query("DELETE FROM " . db_table('newui_audit_log') . " WHERE `user_name` LIKE 'zz142-%'");
    } catch (Throwable $e) { /* best effort */ }
    gh142_cleanup_cookies($cleanup['cookies']);
});
$mk = function (string $name, int $roleId, ?int $orgId = null) use (&$cleanup) {
    $uid = gh142_make_user($name, 'Zz142-pw-' . substr(md5($name), 0, 6) . '!', $roleId, $orgId);
    $cleanup['users'][] = $uid;
    return $uid;
};

$uOne = $mk('zz142-one-org', $roleOrgAdmin, $orgA);
t('one org-scoped grant resolves to that organization', branding_resolve_caller_org_id($uOne) === $orgA);

$uGlobal = $mk('zz142-global', $roleOrgAdmin, null);
t('an Org Admin with ONLY a global-scope grant resolves to 0 (there is no single "own org")', branding_resolve_caller_org_id($uGlobal) === 0);

$uTwo = $mk('zz142-two-orgs', $roleOrgAdmin, $orgA);
gh142_grant_role($uTwo, $roleOrgAdmin, $orgB);
t('two distinct organizations resolve to 0 (never the first or lowest)', branding_resolve_caller_org_id($uTwo) === 0);

$uExpired = $mk('zz142-expired', $roleOrgAdmin, $orgA);
db_query("UPDATE " . db_table('user_roles') . " SET `expires_at` = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE `user_id` = ?", [$uExpired]);
t('an expired org-scoped grant resolves to 0', branding_resolve_caller_org_id($uExpired) === 0);

$uDisp = $mk('zz142-dispatcher-org', $roleDispatcher, $orgA);
t('an org-scoped grant of a role WITHOUT the permission resolves to 0', branding_resolve_caller_org_id($uDisp) === 0);
t('a user id with no grants at all resolves to 0', branding_resolve_caller_org_id(2000000000) === 0);
t('user id 0 resolves to 0', branding_resolve_caller_org_id(0) === 0);

// ── 3. Source gates (tokenized) ────────────────────────────────────────
$base = dirname(__DIR__);
$strip = function (string $file): array {
    $names = [];
    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        if (is_array($tok) && $tok[0] === T_STRING) { $names[] = $tok[1]; }
    }
    return $names;
};
foreach (['api/branding-admin.php', 'branding-admin.php', 'inc/branding.php'] as $rel) {
    t("{$rel} never calls is_admin() (no widening fallback)", !in_array('is_admin', $strip($base . '/' . $rel), true));
}
// Positive control: the tokenizer really would see it.
$fixture = tempnam(sys_get_temp_dir(), 'gh142tok');
file_put_contents($fixture, "<?php\n// is_admin() is named in this comment on purpose\nif (rbac_can('x') || is_admin()) {}\n");
t('control: the tokenizer DOES flag a real is_admin() call and ignores a comment', in_array('is_admin', $strip($fixture), true));
file_put_contents($fixture, "<?php\n// is_admin() is only named in this comment\n");
t('control: ...and does not flag a comment alone', !in_array('is_admin', $strip($fixture), true));
@unlink($fixture);
$epSrc = (string) file_get_contents($base . '/api/branding-admin.php');
t("the endpoint gates on rbac_can('action.manage_branding') ALONE", strpos($epSrc, "rbac_can('action.manage_branding')") !== false);
t("...and on rbac_can('action.manage_branding_org') ALONE", strpos($epSrc, "rbac_can('action.manage_branding_org')") !== false);
t('...and verifies CSRF on every write', substr_count($epSrc, '_branding_require_csrf($input)') >= 3);

// ── 4. REAL HTTP, real sessions, real uploads ──────────────────────────
$srv = gh142_start_server();
if ($srv === null) {
    echo "SKIP: could not start a local PHP server (the HTTP checks were not run)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
register_shutdown_function(function () use ($srv) { pb_test_stop_server($srv); });
$http = 'http://127.0.0.1:' . $srv['port'];

$pwSuper = 'Zz142-pw-Super1!'; $pwOrgA = 'Zz142-pw-OrgA1!'; $pwDisp = 'Zz142-pw-Disp1!';
$uSuper = gh142_make_user('zz142-super', $pwSuper, $roleSuper);
$uOrgA  = gh142_make_user('zz142-orgadmin-a', $pwOrgA, $roleOrgAdmin, $orgA);
$uDispG = gh142_make_user('zz142-dispatcher', $pwDisp, $roleDispatcher);
array_push($cleanup['users'], $uSuper, $uOrgA, $uDispG);

$snapshot = db_fetch_all("SELECT * FROM " . db_table('branding_logos'));
$settingsBefore = db_fetch_all("SELECT `name`, `value` FROM " . db_table('settings') . " WHERE `name` LIKE 'branding\\_%'");
db_query("DELETE FROM " . db_table('branding_logos'));
register_shutdown_function(function () use ($snapshot, $settingsBefore) {
    try {
        db_query("DELETE FROM " . db_table('branding_logos'));
        foreach ($snapshot as $row) {
            $cols = array_keys($row);
            db_query("INSERT INTO " . db_table('branding_logos') . " (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", array_values($row));
        }
        foreach ($settingsBefore as $s) {
            db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$s['value'], $s['name']]);
        }
    } catch (Throwable $e) { /* best effort */ }
});

$ckSuper = gh142_login($http, 'zz142-super', $pwSuper);
$ckOrgA  = gh142_login($http, 'zz142-orgadmin-a', $pwOrgA);
$ckDisp  = gh142_login($http, 'zz142-dispatcher', $pwDisp);
array_push($cleanup['cookies'], $ckSuper, $ckOrgA, $ckDisp);
t('Super Admin logged in over real HTTP', $ckSuper !== null);
t('Org Admin (scoped to org A) logged in over real HTTP', $ckOrgA !== null);
t('Dispatcher logged in over real HTTP', $ckDisp !== null);
if ($ckSuper === null || $ckOrgA === null || $ckDisp === null) {
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(1);
}
$csrfSuper = gh142_csrf($http, $ckSuper);
$csrfOrgA  = gh142_csrf($http, $ckOrgA);
$csrfDisp  = gh142_csrf($http, $ckDisp);
t('a CSRF token was obtained for each session', $csrfSuper !== '' && $csrfOrgA !== '' && $csrfDisp !== '');

$good = gh142_png(240, 80);
$up = function (string $cookie, string $csrf, array $fields, string $bytes = null, string $name = 'logo.png', string $type = 'image/png') use ($http, $good) {
    $fields['csrf_token'] = $csrf;
    $fields['action'] = 'upload_logo';
    return gh142_upload($http, $cookie, $fields, $bytes ?? $good, $name, $type);
};
$status = function (?array $resp) { return $resp === null ? -1 : $resp['status']; };

// Unauthenticated and CSRF.
$anon = gh142_request('GET', $http . '/api/branding-admin.php?action=status');
t('unauthenticated GET status: 401', $status($anon) === 401);
$noCsrf = gh142_upload($http, $ckSuper, ['action' => 'upload_logo', 'scope' => 'install', 'variant' => 'light'], $good);
t('upload with NO csrf token: 403', $status($noCsrf) === 403);
t('...and nothing was stored', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos')) === 0);
$badCsrf = $up($ckSuper, 'deadbeef', ['scope' => 'install', 'variant' => 'light']);
t('upload with a WRONG csrf token: 403', $status($badCsrf) === 403);
$delNoCsrf = gh142_post_json($http, $ckSuper, ['action' => 'delete_logo', 'scope' => 'install', 'variant' => 'light']);
t('delete with no csrf token: 403', $status($delNoCsrf) === 403);
$setNoCsrf = gh142_post_json($http, $ckSuper, ['action' => 'save_settings', 'settings' => ['branding_login' => '0']]);
t('save_settings with no csrf token: 403', $status($setNoCsrf) === 403);
t('...and the setting did not change', branding_normalize_setting('branding_login', db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_login'")) === '1');

// Dispatcher: nothing.
$d1 = gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ckDisp);
t('Dispatcher GET status: 403', $status($d1) === 403);
$d2 = $up($ckDisp, $csrfDisp, ['scope' => 'install', 'variant' => 'light']);
t('Dispatcher upload: 403', $status($d2) === 403);
$d3 = $up($ckDisp, $csrfDisp, ['scope' => 'org', 'org_id' => $orgA, 'variant' => 'light']);
t('Dispatcher org-scope upload: 403', $status($d3) === 403);
$dPage = gh142_request('GET', $http . '/branding-admin.php', $ckDisp);
t('Dispatcher gets the permission-required page (403), not the admin page', $status($dPage) === 403 && strpos($dPage['body'], 'Permission required') !== false);

// Super Admin: everything.
$s1 = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light']);
$s1j = gh142_json($s1);
t('Super Admin uploads the install-wide light logo: 200', $status($s1) === 200 && !empty($s1j['success']));
t('...the response carries the new capability URL, never image bytes', isset($s1j['logo']['url']) && preg_match('/^api\/branding-logo\.php\?k=[a-f0-9]{32}$/', $s1j['logo']['url']) === 1 && strlen($s1['body']) < 1500);
$s2 = $up($ckSuper, $csrfSuper, ['scope' => 'org', 'org_id' => $orgB, 'variant' => 'light']);
t('Super Admin uploads ANOTHER organization\'s logo: 200', $status($s2) === 200);
$sUnknown = $up($ckSuper, $csrfSuper, ['scope' => 'org', 'org_id' => 987654, 'variant' => 'light']);
t('Super Admin naming an organization that does not exist: 404', $status($sUnknown) === 404);
$sNoOrg = $up($ckSuper, $csrfSuper, ['scope' => 'org', 'variant' => 'light']);
t('Super Admin org scope with no org_id: 400', $status($sNoOrg) === 400);

// Org Admin scoped to A.
$oStatus = gh142_json(gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ckOrgA));
t('Org Admin GET status: 200 with can_org true and can_install false', !empty($oStatus['can_org']) && empty($oStatus['can_install']));
t('...caller_org_id is organization A', ($oStatus['caller_org_id'] ?? 0) === $orgA);
t('...and the organization list holds ONLY organization A (never B)',
    count($oStatus['orgs'] ?? []) === 1 && ($oStatus['orgs'][0]['id'] ?? 0) === $orgA);
t('...the install-wide logo is visible to them but its UPLOADER\'s name (a Super Admin\'s username) is blanked',
    isset($oStatus['install']['light']['url']) && ($oStatus['install']['light']['uploaded_by_name'] ?? 'x') === '');
$sStatus = gh142_json(gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ckSuper));
t('...while the Super Admin still sees who uploaded it', ($sStatus['install']['light']['uploaded_by_name'] ?? '') === 'zz142-super');
$o1 = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'org_id' => $orgA, 'variant' => 'light']);
t('Org Admin uploads their OWN organization\'s logo: 200', $status($o1) === 200);
$o1b = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'variant' => 'light']);
t('Org Admin naming no organization is forced to their own: 200', $status($o1b) === 200);
$logoA = db_fetch_one("SELECT `org_id`, `variant` FROM " . db_table('branding_logos') . " WHERE `org_id` = ? ", [$orgA]);
t('...and the row landed on organization A', $logoA && (int) $logoA['org_id'] === $orgA);
$o2 = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'org_id' => $orgB, 'variant' => 'light']);
t('Org Admin naming ANOTHER organization: 403', $status($o2) === 403);
t('...and organization B\'s logo is untouched (still the Super Admin\'s)',
    db_fetch_value("SELECT `uploaded_by_name` FROM " . db_table('branding_logos') . " WHERE `org_id` = ? AND `variant` = 'light'", [$orgB]) === 'zz142-super');
$o3 = $up($ckOrgA, $csrfOrgA, ['scope' => 'install', 'variant' => 'light']);
t('Org Admin uploading the INSTALL-WIDE logo: 403', $status($o3) === 403);
t('...and the install-wide logo is untouched (still the Super Admin\'s)',
    db_fetch_value("SELECT `uploaded_by_name` FROM " . db_table('branding_logos') . " WHERE `org_id` = 0 AND `variant` = 'light'") === 'zz142-super');
$o4 = gh142_post_json($http, $ckOrgA, ['action' => 'delete_logo', 'scope' => 'org', 'org_id' => $orgB, 'variant' => 'light', 'csrf_token' => $csrfOrgA]);
t('Org Admin deleting ANOTHER organization\'s logo: 403', $status($o4) === 403);
t('...organization B still has its logo', (bool) db_fetch_value("SELECT 1 FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$orgB]));
$o5 = gh142_post_json($http, $ckOrgA, ['action' => 'delete_logo', 'scope' => 'install', 'variant' => 'light', 'csrf_token' => $csrfOrgA]);
t('Org Admin deleting the install-wide logo: 403', $status($o5) === 403);
$o6 = gh142_post_json($http, $ckOrgA, ['action' => 'save_settings', 'settings' => ['branding_login' => '0'], 'csrf_token' => $csrfOrgA]);
t('Org Admin changing the branding settings: 403', $status($o6) === 403);
t('...and the setting did not change', branding_normalize_setting('branding_login', db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_login'")) === '1');
$oDarkOnly = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'variant' => 'dark']);
t('Org Admin dark logo after their light one exists: 200', $status($oDarkOnly) === 200);
$o7 = gh142_post_json($http, $ckOrgA, ['action' => 'delete_logo', 'scope' => 'org', 'variant' => 'light', 'csrf_token' => $csrfOrgA]);
t('Org Admin deleting their own light logo: 200', $status($o7) === 200);
t('...which also removed their dark logo', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos') . " WHERE `org_id` = ?", [$orgA]) === 0);
$oDarkFirst = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'variant' => 'dark']);
t('a dark logo with no light logo yet: 409', $status($oDarkFirst) === 409);

// Hostile uploads through the real endpoint.
$before = (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos'));
$svg = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], gh142_svg(), 'logo.png', 'image/png');
t('an SVG named logo.png with Content-Type image/png: 422', $status($svg) === 422);
t('...the refusal tells the admin to export a PNG', strpos((string) (gh142_json($svg)['error'] ?? ''), 'PNG') !== false);
$gif = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], gh142_gif(), 'logo.png', 'image/png');
t('a GIF named logo.png with Content-Type image/png: 422', $status($gif) === 422);
$apng = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], gh142_apng($good), 'logo.png', 'image/png');
t('an animated PNG: 422', $status($apng) === 422);
$bomb = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], gh142_png_bomb(), 'logo.png', 'image/png');
t('a header-only 30000 x 30000 bomb: 422 (and the server did not run out of memory)', $status($bomb) === 422);
$php = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], "<?php echo 'x';", 'shell.php', 'image/png');
t('a PHP file claiming to be an image: 422', $status($php) === 422);
$big = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], str_repeat('A', BRANDING_MAX_UPLOAD_BYTES + 10), 'logo.png', 'image/png');
t('a file over the 2 MB cap is refused (413 from the PHP limits or 422 from the pipeline)', in_array($status($big), [413, 422], true));
$trav = $up($ckSuper, $csrfSuper, ['scope' => 'install', 'variant' => 'light'], $good, '../../../../etc/passwd.png', 'image/png');
t('a path-traversal FILE NAME is simply ignored (nothing derives a path from it): 200', $status($trav) === 200);
$noFile = gh142_post_json($http, $ckSuper, ['action' => 'upload_logo', 'scope' => 'install', 'variant' => 'light', 'csrf_token' => $csrfSuper]);
t('an upload action with no file: 400', $status($noFile) === 400);
t('no refused upload stored anything (only the one valid logo that followed)', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('branding_logos')) === $before);
$files = array_merge(glob(dirname(__DIR__) . '/uploads/*') ?: [], glob(dirname(__DIR__) . '/uploads/*/*') ?: []);
t('and no file was written under uploads/ by any of it',
    count(array_filter($files, function ($f) { return stripos(basename($f), 'logo') !== false || stripos(basename($f), 'passwd') !== false; })) === 0);

// Settings: strict writer, audited.
$sBad = gh142_post_json($http, $ckSuper, ['action' => 'save_settings', 'settings' => ['branding_login_size' => 'gigantic'], 'csrf_token' => $csrfSuper]);
t('save_settings with an invalid enumerated value: 400', $status($sBad) === 400);
$sUnk = gh142_post_json($http, $ckSuper, ['action' => 'save_settings', 'settings' => ['branding_evil' => '1'], 'csrf_token' => $csrfSuper]);
t('save_settings with an unknown setting name: 400', $status($sUnk) === 400);
$sCtl = gh142_post_json($http, $ckSuper, ['action' => 'save_settings', 'settings' => ['branding_logo_alt' => "bad\x01alt"], 'csrf_token' => $csrfSuper]);
t('save_settings with a control character in the alt text: 400', $status($sCtl) === 400);
$sLong = gh142_post_json($http, $ckSuper, ['action' => 'save_settings', 'settings' => ['branding_logo_alt' => str_repeat('x', 101)], 'csrf_token' => $csrfSuper]);
t('save_settings with 101 characters of alt text: 400', $status($sLong) === 400);
$sOk = gh142_post_json($http, $ckSuper, ['action' => 'save_settings',
    'settings' => ['branding_login_size' => 'large', 'branding_navbar' => 'agency', 'branding_logo_alt' => 'your deployment Fire'], 'csrf_token' => $csrfSuper]);
t('save_settings with valid values: 200', $status($sOk) === 200);
t('...they persisted', db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_login_size'") === 'large'
    && db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = 'branding_logo_alt'") === 'your deployment Fire');
$auditSet = db_fetch_one("SELECT `summary`, `details` FROM " . db_table('newui_audit_log') . " WHERE `target_type` = 'branding_settings' AND `user_name` = 'zz142-super' ORDER BY `id` DESC LIMIT 1");
t('...and an audit entry records the old and new values', $auditSet && strpos($auditSet['details'], '"old":"medium"') !== false && strpos($auditSet['details'], '"new":"large"') !== false);

// The kill switch applies to Org Admins only.
db_query("UPDATE " . db_table('settings') . " SET `value` = '0' WHERE `name` = 'branding_org_logos'");
$ks = $up($ckOrgA, $csrfOrgA, ['scope' => 'org', 'variant' => 'light']);
t('with organization logos switched off, an Org Admin upload is refused: 403', $status($ks) === 403);
t('...and the message says why', strpos((string) (gh142_json($ks)['error'] ?? ''), 'switched off') !== false);
$ksSuper = $up($ckSuper, $csrfSuper, ['scope' => 'org', 'org_id' => $orgA, 'variant' => 'light']);
t('...but Super Admin can still manage organization logos (to stage them): 200', $status($ksSuper) === 200);
db_query("UPDATE " . db_table('settings') . " SET `value` = '1' WHERE `name` = 'branding_org_logos'");

// Audit trail: every write, never image bytes.
$auditUp = db_fetch_all("SELECT `summary`, `details`, `user_name` FROM " . db_table('newui_audit_log') . " WHERE `target_type` = 'branding_logo' AND `user_name` LIKE 'zz142-%' ORDER BY `id`");
t('uploads, refusals and removals were audit-logged', count($auditUp) >= 6);
$maxDetail = 0;
foreach ($auditUp as $a) { $maxDetail = max($maxDetail, strlen((string) $a['details'])); }
t('...and no audit payload carries image bytes (every detail under 600 bytes)', $maxDetail < 600);
t('...a refused upload is on the record', (bool) array_filter($auditUp, function ($a) { return strpos($a['summary'], 'Refused logo upload') === 0; }));
t('...a removal is on the record', (bool) array_filter($auditUp, function ($a) { return strpos($a['summary'], 'Removed logo') === 0; }));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
