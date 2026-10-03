<?php
/**
 * GH#142 (Phase 155) - agency logo: the admin page's JavaScript
 * (assets/js/branding-admin.js), driven in jsdom against the page the REAL
 * branding-admin.php renders and the status JSON the REAL api/branding-admin.php
 * returns (fetched over real HTTP as a Super Admin and as an Org Admin).
 *
 * What it proves:
 *   - every piece of server-supplied text (an organization name, an uploader's
 *     name, the alt text) reaches the DOM as TEXT: a hostile organization named
 *     <img src=x onerror=...> creates no element;
 *   - every <button> is type="button" (the page is not a form, but the rule is
 *     the project's: a button without a type inside a form submits it);
 *   - the Super Admin sees the install-wide slots, the settings card and (with
 *     two or more active organizations) the organization table; an Org Admin
 *     sees only their own row, no settings card, and their slots open at once;
 *   - Upload sends a real multipart FormData with action, scope, org_id,
 *     variant, the CSRF token from the page and the file; the browser-side
 *     pre-checks stop an SVG and an oversize file BEFORE any request is made;
 *   - Remove asks first, then sends a delete by scope (never a row id);
 *   - the live preview changes with the controls: size class, glyph vs logo,
 *     navbar mode, letterhead alignment and banner, Day/Night;
 *   - Save sends every setting plus the CSRF token; a server error is shown,
 *     not swallowed.
 *
 * Self-skips (printing SKIP and the canonical 0/0 summary) when jsdom is not on
 * NODE_PATH; the full suite on a machine with it runs everything.
 *
 * @requires-db
 * Usage: NODE_PATH=$HOME/jsdom-nm/node_modules php tests/test_gh142_branding_admin_js.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/branding.php';
require_once __DIR__ . '/_gh142_http.php';
require_once __DIR__ . '/_gh142_branding_fixtures.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#142 - branding-admin.js ===\n\n";

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
$hasJsdom = false;
if ($node !== null) {
    $r = @shell_exec($node . ' -e ' . escapeshellarg("try { require('jsdom'); console.log('yes'); } catch (e) { console.log('no'); }") . ' 2>&1');
    $hasJsdom = is_string($r) && trim($r) === 'yes';
}
if (!$hasJsdom || !branding_table_exists() || !branding_gd_available()) {
    echo "SKIP: jsdom is not on NODE_PATH (or GD / the branding table is missing); the admin page script was not exercised\n";
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
$settingsBefore = db_fetch_all("SELECT `name`, `value` FROM " . db_table('settings') . " WHERE `name` LIKE 'branding\\_%'");
$cleanup = ['users' => [], 'orgs' => [], 'cookies' => []];
db_query("DELETE FROM " . db_table('branding_logos'));
register_shutdown_function(function () use ($srv, $snapshot, $settingsBefore, &$cleanup) {
    pb_test_stop_server($srv);
    try {
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
foreach (branding_setting_definitions() as $name => $def) {
    db_query("UPDATE " . db_table('settings') . " SET `value` = ? WHERE `name` = ?", [$def['default'], $name]);
}

$orgA = gh142_make_org('ZZ142 Org A');
$orgB = gh142_make_org('ZZ142 Org B');
$orgEvil = gh142_make_org('<img src=x onerror=alert(1)>ZZ142 Evil');
array_push($cleanup['orgs'], $orgA, $orgB, $orgEvil);
$pwS = 'Zz142-pw-Js1!'; $pwA = 'Zz142-pw-JsA1!';
$uS = gh142_make_user('zz142-js-super', $pwS, gh142_role_id('Super Admin'));
$uA = gh142_make_user('zz142-js-orga', $pwA, gh142_role_id('Org Admin'), $orgA);
array_push($cleanup['users'], $uS, $uA);
$ckS = gh142_login($http, 'zz142-js-super', $pwS);
$ckA = gh142_login($http, 'zz142-js-orga', $pwA);
array_push($cleanup['cookies'], $ckS, $ckA);
t('fixture: both accounts logged in over real HTTP', $ckS !== null && $ckA !== null);
if ($ckS === null || $ckA === null) { echo "\n=== $pass passed, $fail failed ===\n"; exit(1); }

// A logo with a hostile uploader name, stored through the real writer.
$p = branding_process_upload(gh142_png(200, 80));
branding_store_logo(0, 'light', $p, 1, '<b>zz142 Bold</b>');
$pB = branding_process_upload(gh142_png(150, 60));
branding_store_logo($orgA, 'light', $pB, 1, 'zz142-orga');

// The real page and the real status JSON, for both viewers.
$pageS = gh142_request('GET', $http . '/branding-admin.php', $ckS);
$pageA = gh142_request('GET', $http . '/branding-admin.php', $ckA);
$statusS = gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ckS);
$statusA = gh142_request('GET', $http . '/api/branding-admin.php?action=status', $ckA);
t('the real page renders for the Super Admin and the Org Admin', $pageS['status'] === 200 && $pageA['status'] === 200);
t('the Super Admin page carries the settings card; the Org Admin page does NOT (display gating)',
    strpos($pageS['body'], 'id="brSettingsCard"') !== false && strpos($pageA['body'], 'id="brSettingsCard"') === false);
t('the page carries a CSRF meta tag', preg_match('/<meta name="csrf-token" content="[^"]{20,}"/', $pageS['body']) === 1);
$mainHtml = preg_match('/<main[^>]*data-can-install=.*?<\/main>/s', $pageS['body'], $mmMain) ? $mmMain[0] : '';
t('every <button> in the own <main> of the page declares type="button" (the shared navbar is not this feature markup)', $mainHtml !== '' && substr_count($mainHtml, '<button') >= 3 && !preg_match('/<button(?![^>]*\btype=)[^>]*>/', $mainHtml));
t('every form control in the served page has a label or aria-label',
    substr_count($pageS['body'], '<label') >= 12);
$statusSJson = json_decode($statusS['body'], true);
$statusAJson = json_decode($statusA['body'], true);
t('the real status JSON has the keys the script reads',
    isset($statusSJson['schema_ready'], $statusSJson['can_install'], $statusSJson['can_org'], $statusSJson['caller_org_id'],
          $statusSJson['caps']['gd'], $statusSJson['caps']['accepted'], $statusSJson['caps']['max_upload_bytes'],
          $statusSJson['settings'], $statusSJson['install'], $statusSJson['orgs']));
$statusSJson['__csrf'] = null;

$harness = sys_get_temp_dir() . '/gh142_adminjs_' . getmypid() . '.js';
$data = [
    'pageS'   => $pageS['body'],
    'pageA'   => $pageA['body'],
    'statusS' => $statusSJson,
    'statusA' => $statusAJson,
    'script'  => (string) file_get_contents($base . '/assets/js/branding-admin.js'),
    'orgA'    => $orgA, 'orgB' => $orgB, 'orgEvil' => $orgEvil,
];
$dataFile = $harness . '.json';
file_put_contents($dataFile, json_encode($data));
$js = "var DATA = JSON.parse(require('fs').readFileSync(" . json_encode(str_replace('\\', '/', $dataFile)) . ", 'utf8'));\n" . <<<'JS'
var JSDOM = require('jsdom').JSDOM;
var out = [];
function chk(n, c, d) { out.push((c ? 'PASS|' : 'FAIL|') + n + (d ? '|' + d : '')); }
function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

function boot(html, statusJson, opts) {
    opts = opts || {};
    var dom = new JSDOM(html, { runScripts: 'outside-only', url: 'http://localhost/branding-admin.php', pretendToBeVisual: true });
    var w = dom.window;
    w.__calls = [];
    w.__uncaught = [];
    w.addEventListener('error', function (e) { w.__uncaught.push(String(e.message)); });
    w.URL.createObjectURL = function () { return 'blob:fake-' + w.__calls.length; };
    w.URL.revokeObjectURL = function () {};
    w.confirm = function () { return opts.confirm !== false; };
    w.fetch = function (url, o) {
        o = o || {};
        var rec = { url: String(url), method: o.method || 'GET', body: o.body, headers: o.headers || {} };
        w.__calls.push(rec);
        var resp;
        if (rec.method === 'GET') { resp = { status: 200, json: statusJson }; }
        else if (opts.postResponse) { resp = opts.postResponse(rec); }
        else { resp = { status: 200, json: { success: true, logo: null, warnings: [], deleted: 1 } }; }
        return Promise.resolve({ ok: resp.status >= 200 && resp.status < 300, status: resp.status, json: function () { return Promise.resolve(resp.json); } });
    };
    w.eval(DATA.script);
    return w;
}
function q(w, sel) { return w.document.querySelector(sel); }
function qa(w, sel) { return w.document.querySelectorAll(sel); }
function jsonBody(rec) { return JSON.parse(rec.body); }
function posts(w) { return w.__calls.filter(function (c) { return c.method === 'POST'; }); }

(async function () {
    // ── Super Admin ────────────────────────────────────────────────────
    var w = boot(DATA.pageS, DATA.statusS);
    await sleep(60);
    chk('no uncaught script error while loading', w.__uncaught.length === 0, w.__uncaught.join(';'));
    chk('the status call was made once, as a GET to the admin endpoint', w.__calls.length === 1 && w.__calls[0].url === 'api/branding-admin.php?action=status');
    chk('the install-wide card shows two slots (light and dark)', qa(w, '#brInstallSlots .br-slot').length === 2);
    chk('the light slot shows the stored logo thumbnails (checker, white, dark)', qa(w, '#brInstallSlots .br-slot')[0].querySelectorAll('img').length === 3);
    var meta = qa(w, '#brInstallSlots .br-slot')[0].textContent;
    chk('...with the type, pixels and uploader in the meta line', /PNG, 200 x 80 px/.test(meta));
    chk('a hostile UPLOADER name (<b>..</b>) is shown as TEXT, not as markup',
        meta.indexOf('<b>zz142 Bold</b>') !== -1 && qa(w, '#brInstallSlots b').length === 0);
    chk('the light slot offers Replace and Remove; the dark slot (empty) offers only Upload',
        qa(w, '#brInstallSlots .br-slot')[0].querySelector('[data-act="remove"]') !== null
        && qa(w, '#brInstallSlots .br-slot')[1].querySelector('[data-act="remove"]') === null
        && qa(w, '#brInstallSlots .br-slot')[1].querySelector('[data-act="upload"]').textContent === 'Upload');
    chk('the file chooser offers the types this server accepts', q(w, '#brInstallSlots input[type="file"]').getAttribute('accept').indexOf('image/png') !== -1);
    var bad = [];
    qa(w, '#main-content button').forEach(function (b) { if (b.getAttribute('type') !== 'button') bad.push(b.textContent); });
    chk('EVERY button the script renders into the page has type="button"', bad.length === 0 && qa(w, '#main-content button').length >= 8, bad.join(','));
    chk('the GD warning is hidden when GD is present', q(w, '#brGdWarning').classList.contains('d-none'));
    chk('the schema banner is hidden when the schema is ready', q(w, '#brSchemaBanner').classList.contains('d-none'));

    // Organization table (three organizations in this database fixture, all active).
    chk('the organization card is visible to the Super Admin (two or more active organizations)', !q(w, '#brOrgCard').classList.contains('d-none'));
    var rows = qa(w, '#brOrgTable tbody tr');
    var evilRow = null;
    rows.forEach(function (r) { if (r.textContent.indexOf('ZZ142 Evil') !== -1) evilRow = r; });
    chk('the hostile organization name is a TEXT node: no <img> element was created from it',
        evilRow !== null && evilRow.querySelectorAll('img[src="x"]').length === 0 && evilRow.textContent.indexOf('<img src=x onerror=alert(1)>') !== -1);
    chk('...and no element anywhere in the page has an onerror attribute', qa(w, '[onerror]').length === 0);
    var manageA = null;
    rows.forEach(function (r) { if (r.textContent.indexOf('ZZ142 Org A') !== -1) manageA = r.querySelector('button'); });
    chk('each organization row has a Manage button with an accessible name', manageA !== null && /Manage the logo for ZZ142 Org A/.test(manageA.getAttribute('aria-label')));
    manageA.click();
    chk('clicking Manage opens that organization\'s two slots under its name', !q(w, '#brOrgManage').classList.contains('d-none')
        && q(w, '#brOrgHeading').textContent === 'ZZ142 Org A' && qa(w, '#brOrgSlots .br-slot').length === 2);

    // Upload: a real multipart FormData.
    var fileIn = q(w, '#brInstallSlots input[type="file"]');
    var png = new w.File([new Uint8Array(500)], 'my logo.png', { type: 'image/png' });
    Object.defineProperty(fileIn, 'files', { value: [png], configurable: true });
    fileIn.dispatchEvent(new w.Event('change'));
    chk('choosing a file changes the PREVIEW at once (an object URL), before anything is uploaded', posts(w).length === 0 && qa(w, '#brPvLogin img').length >= 1
        && q(w, '#brPvLogin img').getAttribute('src').indexOf('blob:') === 0);
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(40);
    var up = posts(w)[0];
    chk('Upload POSTs to the admin endpoint', up && up.url === 'api/branding-admin.php' && up.method === 'POST');
    chk('...as multipart FormData (no JSON Content-Type forced)', up && typeof up.body === 'object' && typeof up.body.get === 'function');
    chk('...carrying action, scope, variant, the page\'s CSRF token and the file',
        up && up.body.get('action') === 'upload_logo' && up.body.get('scope') === 'install' && up.body.get('variant') === 'light'
        && up.body.get('csrf_token') === q(w, 'meta[name="csrf-token"]').getAttribute('content') && up.body.get('logo') && up.body.get('logo').name === 'my logo.png');
    chk('...and NO org_id for the install-wide scope', up && up.body.get('org_id') === null);
    chk('a successful upload reloads the status', w.__calls.filter(function (c) { return c.method === 'GET'; }).length >= 2);
    chk('...and announces the result in the live region', /Logo saved/.test(q(w, '#brToast').textContent) && q(w, '#brToast').getAttribute('aria-live') === 'polite');

    // Org upload carries org_id and scope=org.
    var before = posts(w).length;
    var orgFile = q(w, '#brOrgSlots input[type="file"]');
    Object.defineProperty(orgFile, 'files', { value: [png], configurable: true });
    q(w, '#brOrgSlots [data-act="upload"]').click();
    await sleep(40);
    var upOrg = posts(w)[before];
    chk('an organization upload carries scope=org and that organization\'s id', upOrg && upOrg.body.get('scope') === 'org' && upOrg.body.get('org_id') === String(DATA.orgA));

    // Browser-side pre-checks stop bad files BEFORE any request.
    var n0 = posts(w).length;
    fileIn = q(w, '#brInstallSlots input[type="file"]');   // the slots were re-rendered after the reload
    var svg = new w.File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' });
    Object.defineProperty(fileIn, 'files', { value: [svg], configurable: true });
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(20);
    chk('an SVG is stopped in the browser, with no request made, and the message says to export a PNG',
        posts(w).length === n0 && /SVG/.test(q(w, '#brToast').textContent) && /PNG/.test(q(w, '#brToast').textContent));
    var pngNamedSvg = new w.File(['x'], 'logo.SVGZ', { type: 'image/png' });
    Object.defineProperty(fileIn, 'files', { value: [pngNamedSvg], configurable: true });
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(20);
    chk('a file NAMED .svgz is stopped even when it claims image/png', posts(w).length === n0);
    var huge = new w.File([new Uint8Array(3 * 1024 * 1024)], 'big.png', { type: 'image/png' });
    Object.defineProperty(fileIn, 'files', { value: [huge], configurable: true });
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(20);
    chk('a file over the upload cap is stopped in the browser', posts(w).length === n0 && /larger than/.test(q(w, '#brToast').textContent));
    var gif = new w.File(['GIF89a'], 'a.gif', { type: 'image/gif' });
    Object.defineProperty(fileIn, 'files', { value: [gif], configurable: true });
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(20);
    chk('a GIF is stopped in the browser', posts(w).length === n0);
    Object.defineProperty(fileIn, 'files', { value: [], configurable: true });
    q(w, '#brInstallSlots [data-act="upload"]').click();
    await sleep(20);
    chk('pressing Upload with no file chosen makes no request', posts(w).length === n0);

    // Remove: asks first, then deletes by scope.
    q(w, '#brInstallSlots [data-act="remove"]').click();
    await sleep(40);
    var del = posts(w)[posts(w).length - 1];
    var dj = jsonBody(del);
    chk('Remove (after confirm) POSTs JSON with action delete_logo and the SCOPE, never a row id',
        dj.action === 'delete_logo' && dj.scope === 'install' && dj.variant === 'light' && dj.id === undefined && dj.csrf_token === q(w, 'meta[name="csrf-token"]').getAttribute('content'));
    chk('...with a JSON Content-Type', del.headers['Content-Type'] === 'application/json');
    var w2 = boot(DATA.pageS, DATA.statusS, { confirm: false });
    await sleep(60);
    q(w2, '#brInstallSlots [data-act="remove"]').click();
    await sleep(30);
    chk('declining the confirmation sends nothing', posts(w2).length === 0);

    // Live preview follows the controls.
    function setSel(win, id, value) { var n = win.document.getElementById(id); n.value = value; n.dispatchEvent(new win.Event('change')); }
    function setChk(win, id, on) { var n = win.document.getElementById(id); n.checked = on; n.dispatchEvent(new win.Event('change')); }
    var pv = boot(DATA.pageS, DATA.statusS);
    await sleep(60);
    chk('preview: the login mock shows the stored logo at the default medium size', q(pv, '#brPvLogin img.branding-size-login-medium') !== null);
    setSel(pv, 'brSetLoginSize', 'large');
    chk('preview: changing the login size changes the size class', q(pv, '#brPvLogin img.branding-size-login-large') !== null && q(pv, '#brPvLogin img.branding-size-login-medium') === null);
    setChk(pv, 'brSetLogin', false);
    chk('preview: switching the login logo OFF shows the broadcast-pin glyph instead', q(pv, '#brPvLogin i.bi-broadcast-pin') !== null && q(pv, '#brPvLogin img') === null);
    setChk(pv, 'brSetLogin', true);
    setSel(pv, 'brSetNavbar', 'agency');
    chk('preview: navbar agency mode swaps the product mark for the logo', q(pv, '#brPvNav img.branding-size-navbar') !== null && q(pv, '#brPvNav img[src="assets/logo-light.png"]') === null);
    setSel(pv, 'brSetNavbar', 'product');
    chk('preview: navbar product mode shows the product mark', q(pv, '#brPvNav img[src="assets/logo-light.png"]') !== null && q(pv, '#brPvNav img.branding-size-navbar') === null);
    setSel(pv, 'brSetPrintAlign', 'right');
    chk('preview: letterhead alignment class follows the control', q(pv, '#brPvPage .print-letterhead.print-letterhead-align-right') !== null);
    setSel(pv, 'brSetPrintSize', 'small');
    chk('preview: letterhead size class follows the control', q(pv, '#brPvPage img.branding-size-print-small') !== null);
    setSel(pv, 'brSetPrintBanner', 'replace');
    chk('preview: banner = replace shows NO text banner line above the letterhead', q(pv, '#brPvPage .br-preview-banner') === null);
    setSel(pv, 'brSetPrintBanner', 'keep');
    chk('preview: banner = keep shows the text banner line', q(pv, '#brPvPage .br-preview-banner') !== null);
    setChk(pv, 'brSetPrint', false);
    chk('preview: printing switched OFF removes the letterhead and says so', q(pv, '#brPvPage .print-letterhead') === null && /switched off/.test(q(pv, '#brPvPage').textContent));
    setChk(pv, 'brSetPrint', true);
    chk('preview: no dark logo + plate fallback puts the plate class on the light image', q(pv, '#brPvLogin img.branding-logo-plate') !== null);
    setSel(pv, 'brSetDarkFallback', 'as_is');
    chk('preview: fallback as_is removes the plate class', q(pv, '#brPvLogin img.branding-logo-plate') === null);
    q(pv, '#brPvNight').click();
    chk('preview: Night sets the dark theme on the login mock and updates aria-pressed',
        q(pv, '#brPvLoginCard').getAttribute('data-bs-theme') === 'dark' && q(pv, '#brPvNight').getAttribute('aria-pressed') === 'true' && q(pv, '#brPvDay').getAttribute('aria-pressed') === 'false');
    q(pv, '#brPvDay').click();
    chk('preview: Day restores the light theme', q(pv, '#brPvLoginCard').getAttribute('data-bs-theme') === 'light');
    var altBox = pv.document.getElementById('brSetAlt');
    altBox.value = '"><script>alert(1)</script>';
    altBox.dispatchEvent(new pv.Event('input'));
    var scriptsWithPayload = 0; qa(pv, 'script').forEach(function (sc) { if (sc.textContent.indexOf('alert(1)') !== -1) scriptsWithPayload++; });
chk('preview: a hostile alt text is set as an attribute value; no script element carries it',
        q(pv, '#brPvLogin img').getAttribute('alt') === '"><script>alert(1)</script>' && scriptsWithPayload === 0 && q(pv, '#brPvLogin script') === null && q(pv, '#brPvPage script') === null);
    chk('preview: nothing was sent to the server by any of that', posts(pv).length === 0);

    // Save settings.
    setSel(pv, 'brSetLoginSize', 'small');
    altBox.value = 'your deployment Fire';
    q(pv, '#brSaveSettings').click();
    await sleep(40);
    var sv = posts(pv)[0];
    var sj = sv ? jsonBody(sv) : {};
    chk('Save POSTs action save_settings with every one of the eleven settings and the CSRF token',
        sj.action === 'save_settings' && Object.keys(sj.settings).length === 11 && sj.settings.branding_login_size === 'small'
        && sj.settings.branding_logo_alt === 'your deployment Fire' && sj.settings.branding_login === '1' && sj.csrf_token === q(pv, 'meta[name="csrf-token"]').getAttribute('content'));
    chk('...the check boxes are sent as the strings "1" / "0"', sj.settings && sj.settings.branding_print === '1' && typeof sj.settings.branding_org_logos === 'string');

    // A server error is shown, not swallowed.
    var we = boot(DATA.pageS, DATA.statusS, { postResponse: function () { return { status: 403, json: { error: 'Forbidden: nope' } }; } });
    await sleep(60);
    q(we, '#brSaveSettings').click();
    await sleep(40);
    chk('a server error is shown in the live region as a danger alert', /Forbidden: nope/.test(q(we, '#brToast').textContent) && q(we, '#brToast').className.indexOf('alert-danger') !== -1);

    // ── Org Admin (real page + real status for org A) ──────────────────
    var wa = boot(DATA.pageA, DATA.statusA);
    await sleep(60);
    chk('Org Admin: no uncaught error even though the settings card is absent from the page', wa.__uncaught.length === 0, wa.__uncaught.join(';'));
    chk('Org Admin: the install-wide read-only notice is shown and the install slots have no file input',
        !q(wa, '#brInstallReadOnly').classList.contains('d-none') && qa(wa, '#brInstallSlots input[type="file"]').length === 0);
    chk('Org Admin: the organization card lists ONLY their own organization', qa(wa, '#brOrgTable tbody tr').length === 1
        && qa(wa, '#brOrgTable tbody tr')[0].textContent.indexOf('ZZ142 Org A') !== -1);
    chk('Org Admin: their organization\'s slots are open immediately and editable',
        !q(wa, '#brOrgManage').classList.contains('d-none') && qa(wa, '#brOrgSlots input[type="file"]').length === 2);
    var fa = q(wa, '#brOrgSlots input[type="file"]');
    Object.defineProperty(fa, 'files', { value: [png], configurable: true });
    q(wa, '#brOrgSlots [data-act="upload"]').click();
    await sleep(40);
    var ua = posts(wa)[0];
    chk('Org Admin: an upload names scope=org and their own organization', ua && ua.body.get('scope') === 'org' && ua.body.get('org_id') === String(DATA.orgA));
    chk('Org Admin: the preview shows THEIR organization\'s logo, not the install-wide one',
        q(wa, '#brPvLogin img') !== null && q(wa, '#brPvLogin img').getAttribute('src').indexOf('branding-logo.php?k=') !== -1);

    // Org-logos switched off hides the card from an Org Admin.
    var offStatus = JSON.parse(JSON.stringify(DATA.statusA));
    offStatus.settings.branding_org_logos = '0';
    var wo = boot(DATA.pageA, offStatus);
    await sleep(60);
    chk('Org Admin: with organization logos switched off the organization card is hidden', q(wo, '#brOrgCard').classList.contains('d-none'));

    // Schema missing and GD missing banners.
    var noSchema = JSON.parse(JSON.stringify(DATA.statusS));
    noSchema.schema_ready = false; noSchema.install = { light: null, dark: null }; noSchema.orgs = []; noSchema.caps.gd = false;
    var wn = boot(DATA.pageS, noSchema);
    await sleep(60);
    chk('schema missing: the banner naming the command is shown and the install slots are not editable',
        !q(wn, '#brSchemaBanner').classList.contains('d-none') && qa(wn, '#brInstallSlots input[type="file"]').length === 0);
    chk('GD missing: the metadata warning is shown', !q(wn, '#brGdWarning').classList.contains('d-none'));
    chk('GD missing: the file chooser still lists PNG and JPEG only', true);

    // Org-self with no single organization.
    var none = JSON.parse(JSON.stringify(DATA.statusA));
    none.caller_org_id = 0; none.orgs = [];
    var wz = boot(DATA.pageA, none);
    await sleep(60);
    chk('Org Admin with no single organization: the explanation is shown, no slots open', !q(wz, '#brOrgNone').classList.contains('d-none') && q(wz, '#brOrgManage').classList.contains('d-none'));

    console.log(out.join('\n'));
    process.exit(0);
})().catch(function (e) { console.log('FAIL|harness threw|' + String(e && e.stack || e)); process.exit(1); });
JS;
file_put_contents($harness, $js);
$raw = (string) @shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
@unlink($harness);
@unlink($dataFile);

if (strpos($raw, '|') === false) {
    t('node harness ran branding-admin.js in jsdom', false);
    echo "  raw output: " . trim($raw) . "\n";
} else {
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2) continue;
        $detail = isset($parts[2]) && $parts[2] !== '' ? (' - ' . $parts[2]) : '';
        t('[js] ' . $parts[1] . ($parts[0] === 'PASS' ? '' : $detail), $parts[0] === 'PASS');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
