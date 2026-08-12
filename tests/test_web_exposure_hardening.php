<?php
/**
 * Gate: the directories that are not part of the web interface must not be
 * reachable, on any web server, by any route.
 *
 * ── WHAT HAPPENED ────────────────────────────────────────────────────
 *
 * On 2026-07-30, from the public internet, against a live TicketsCAD install:
 *
 *   GET /backups/<archive>.zip  → HTTP 200, 110 MB, a real ZIP — a complete
 *                                 database dump, with no authentication
 *   GET /backups/               → a directory listing of every archive
 *   GET /sql/  and  GET /tools/ → directory listings of 181 and 109 PHP
 *                                 scripts, including sql/run_migrations.php,
 *                                 which applies database migrations with no
 *                                 authentication of any kind
 *   GET /inc/db.php             → served
 *
 * The cause was not a bug in any one file. The documented install puts the web
 * root AT the application root, so every directory in the tree is served unless
 * the web server is told otherwise, and nothing shipped told it.
 *
 * ── WHY THIS TEST CHECKS FOUR LAYERS AND NOT ONE ─────────────────────
 *
 * No single layer covers every install, and a fix that only looks fixed is
 * worse than none:
 *
 *   1. .htaccess          — Apache only, and only when AllowOverride permits it.
 *   2. nginx snippet      — nginx NEVER reads .htaccess. Neither does IIS.
 *   3. PHP_SAPI guards    — works on any server, in any configuration, even one
 *                           where nobody installed any rules. This is the layer
 *                           that must never regress, which is why the assertion
 *                           below is over EVERY script rather than a list.
 *   4. backups above the  — the archive that was actually downloaded is no
 *      web root             longer inside any served directory at all.
 *
 * Usage: php tests/test_web_exposure_hardening.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/backup.php';

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

$root = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);

// The canonical guard. One exact form, deliberately: a single string is
// greppable, and a single form means there is nothing to argue about when
// someone adds the 297th script.
const CLI_GUARD = "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }";

// Directories that must be denied, and directories that must NOT be.
$mustDeny  = ['backups', 'inc', 'sql', 'tools', 'tests', 'specs', 'coordination',
              'drafts', 'apache', 'vendor', 'keys'];
// proxy/ is on this list for a concrete reason: proxy/dmr-proxy.php is fetched
// over HTTP by the radio widget, so denying it silently kills push-to-talk.
$mustServe = ['api', 'assets', 'proxy', 'sw', 'uploads', 'cache', 'documentation'];

echo "=== Web exposure hardening ===\n\n";

// ─────────────────────────────────────────────────────────────────────
echo "-- Layer 3: every CLI-only script refuses to run over HTTP --\n";
// This is the layer that works everywhere, so it is asserted over every file
// rather than a hand-maintained list. A new script without the guard fails the
// suite the day it is written, not the day someone scans the server.

/** Tokens with byte-independent ids, comments and whitespace dropped. */
function weh_tokens(string $src): array {
    $out = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out[] = ['id' => $t[0], 'text' => $t[1]];
        } else {
            $out[] = ['id' => null, 'text' => $t];
        }
    }
    return $out;
}

/**
 * Is the SAPI guard the FIRST executable statement?
 *
 * `declare(strict_types=…)` and `namespace` are allowed to precede it because
 * PHP requires them to come first; nothing else is. A guard placed after
 * `require config.php` is not a guard — by then the request has connected to
 * the database and run every side effect in the bootstrap.
 */
function weh_guard_is_first(array $toks): bool {
    $i = 0;
    $n = count($toks);
    // Skip the leading <?php.
    if ($n > 0 && $toks[0]['id'] === T_OPEN_TAG) $i = 1;

    while ($i < $n && ($toks[$i]['id'] === T_DECLARE || $toks[$i]['id'] === T_NAMESPACE)) {
        while ($i < $n && $toks[$i]['text'] !== ';' && $toks[$i]['text'] !== '{') $i++;
        $i++;                                   // step past the ; / {
    }
    if ($i >= $n || $toks[$i]['id'] !== T_IF) return false;
    // The condition must be about the SAPI.
    for ($j = $i + 1; $j < min($i + 6, $n); $j++) {
        if ($toks[$j]['id'] === T_STRING && $toks[$j]['text'] === 'PHP_SAPI') return true;
    }
    return false;
}

$cliScripts = [];
foreach (['sql', 'tools'] as $d) {
    $base = $root . '/' . $d;
    if (!is_dir($base)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
            $cliScripts[] = str_replace('\\', '/', $f->getPathname());
        }
    }
}
sort($cliScripts);

test('sql/ and tools/ still contain the CLI scripts', count($cliScripts) > 100,
    'found ' . count($cliScripts));

$noGuard   = [];
$notFirst  = [];
foreach ($cliScripts as $path) {
    $src = (string) @file_get_contents($path);
    $rel = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
    if (strpos($src, CLI_GUARD) === false) {
        $noGuard[] = $rel;
        continue;
    }
    if (!weh_guard_is_first(weh_tokens($src))) {
        $notFirst[] = $rel;
    }
}

test('every script under sql/ and tools/ carries the CLI-only guard',
    empty($noGuard),
    count($noGuard) . ' missing it: ' . implode(', ', array_slice($noGuard, 0, 8))
    . (count($noGuard) > 8 ? ' …' : '')
    . ' — add as the first statement: ' . CLI_GUARD);

test('the guard is the FIRST executable statement in each of them',
    empty($notFirst),
    count($notFirst) . ' have it too late: ' . implode(', ', array_slice($notFirst, 0, 8)));

// The three the security review named, asserted by name so the report can be
// answered directly.
foreach (['sql/run_migrations.php', 'tools/install_fresh.php', 'tools/check-schema.php'] as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    test("$rel refuses to run under a web SAPI",
        $src !== '' && strpos($src, CLI_GUARD) !== false && weh_guard_is_first(weh_tokens($src)));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- Layer 1: the shipped Apache .htaccess --\n";
$ht = (string) @file_get_contents($root . '/.htaccess');
test('.htaccess exists', $ht !== '');

foreach ($mustDeny as $dir) {
    if ($dir === 'services') continue;
    test(".htaccess denies $dir/", strpos($ht, $dir) !== false
        && preg_match('/\b' . preg_quote($dir, '/') . '\b/', $ht) === 1);
}
test('.htaccess denies services/ (the bridge scripts are the only exception)',
    strpos($ht, 'services/') !== false);
test('.htaccess still allows the Meshtastic/MeshCore bridge downloads',
    strpos($ht, 'meshtastic|meshcore') !== false,
    'the Mesh Console tells operators to curl services/meshtastic/bridge_v2.py '
    . 'from this server; blocking it breaks the documented bridge install');

foreach ($mustServe as $dir) {
    test(".htaccess does NOT deny $dir/",
        preg_match('/[(|]' . preg_quote($dir, '/') . '[|)]/', $ht) !== 1,
        $dir . '/ is fetched by the browser');
}

test('.htaccess uses both mod_alias and mod_rewrite (an install may have one)',
    strpos($ht, 'RedirectMatch') !== false && strpos($ht, 'RewriteRule') !== false);

// ── The rules EVALUATED, not read (2026-08-01) ────────────────────────────
// Reading the file could not catch what shipped in 4.2.2: the deny pattern was
// `(^|/)(…|vendor)(/|$)`, i.e. the name ANYWHERE in the path. `assets/vendor/`
// matched it, so every Apache install that grants AllowOverride answered 403
// for Bootstrap, Leaflet and GridStack — the application rendered unstyled,
// with no map and no widget grid. Every text assertion above passed the whole
// time, because `assets` genuinely was not in the alternation. Apache and PHP
// both use PCRE, so running the shipped pattern over real URL-paths is a
// faithful check of what the server will actually do.
$redirects = [];
if (preg_match_all('/^\s*RedirectMatch\s+404\s+(\S+)\s*$/m', $ht, $rm)) {
    $redirects = $rm[1];
}
test('the deny patterns could be extracted from .htaccess', $redirects !== []);

/** True when ANY shipped RedirectMatch rule would deny this URL-path. */
$denies = function (string $path) use ($redirects): bool {
    foreach ($redirects as $pat) {
        if (@preg_match('#' . str_replace('#', '\#', $pat) . '#', $path) === 1) return true;
    }
    return false;
};

// Root install and subdirectory install, because RedirectMatch sees the whole
// URL-path and a subdirectory install is what hid this locally for a fortnight.
foreach (['', '/newui'] as $prefix) {
    $where = $prefix === '' ? 'root install' : 'subdirectory install';

    // The front end MUST be served. These are the exact files index.php loads.
    foreach (['/assets/vendor/bootstrap/bootstrap.min.css',
              '/assets/vendor/bootstrap/bootstrap.bundle.min.js',
              '/assets/vendor/leaflet/leaflet.js',
              '/assets/vendor/gridstack/gridstack-all.js',
              '/assets/js/app.js',
              '/assets/css/action-bar.css'] as $p) {
        test("[$where] serves $p", !$denies($prefix . $p),
            'denying this leaves the application unstyled with no map');
    }

    // …and the directories the hardening exists for MUST still be denied.
    foreach (['/vendor/autoload.php', '/inc/db.php', '/sql/run_migrations.php',
              '/tools/backup_run.php', '/backups/dump.zip', '/keys/tfa.key',
              '/tests/test_security.php', '/specs/handoff.md'] as $p) {
        test("[$where] denies $p", $denies($prefix . $p));
    }

    // The one deliberate hole, both ways round.
    test("[$where] serves the Meshtastic bridge script",
        !$denies($prefix . '/services/meshtastic/bridge_v2.py'),
        'the Mesh Console tells operators to curl it from this server');
    test("[$where] denies the rest of services/",
        $denies($prefix . '/services/bridge/listener.ini'));
}
// The two directives that make Apache answer 500 for the WHOLE site from a
// .htaccess. <Directory> is illegal here; `Options` needs AllowOverride Options,
// which plenty of hosts do not grant. Checked over DIRECTIVE lines only — the
// file's own comments explain both hazards by name, and warning about a thing
// is not doing it.
$htDirectives = implode("\n", array_filter(
    preg_split('/\R/', $ht) ?: [],
    function ($l) { return preg_match('/^\s*#/', $l) !== 1; }
));
test('.htaccess contains no <Directory>/<DirectoryMatch> (illegal here → 500)',
    stripos($htDirectives, '<Directory') === false);
test('.htaccess sets no Options directive (needs AllowOverride Options → 500)',
    preg_match('/^\s*Options\s/mi', $htDirectives) !== 1);

foreach (['sql', 'tools'] as $d) {
    test("$d/.htaccess exists (survives a replaced root .htaccess)",
        is_file($root . '/' . $d . '/.htaccess'));
    test("$d/web.config exists (IIS ignores .htaccess entirely)",
        is_file($root . '/' . $d . '/web.config'));
}

// ─────────────────────────────────────────────────────────────────────
echo "\n-- Layer 2: nginx and the docs that say who needs what --\n";
// The most important part of the whole change. An Apache-only fix that reads as
// "fixed" leaves every nginx install exactly as exposed as it was.
$ngx = (string) @file_get_contents($root . '/docs/nginx/ticketscad-hardening.conf');
test('docs/nginx/ticketscad-hardening.conf ships', $ngx !== '');
foreach ($mustDeny as $dir) {
    test("nginx snippet denies $dir/", strpos($ngx, '/' . $dir . '/') !== false);
}
test('nginx snippet keeps the bridge scripts downloadable',
    strpos($ngx, 'meshtastic|meshcore') !== false);
test('nginx snippet uses ^~ so the denies beat the PHP regex location',
    strpos($ngx, 'location ^~ /sql/') !== false,
    'a plain prefix location loses to `location ~ \\.php$` and run_migrations.php '
    . 'would still execute');
foreach ($mustServe as $dir) {
    // A plain substring check would false-positive on a scoped, narrower deny
    // under a must-serve directory (GHSA-x9x6-w4fg-pmcc added exactly one:
    // `location ^~ /cache/zello-audio/` denies ONLY that subdirectory, not
    // cache/ as a whole — nginx's own location matching is per-path, so this
    // does not touch cache/weather or any other cache/ content). The check
    // has to tell "the whole directory is denied" apart from "a subdirectory
    // of it is," the same distinction this project got burned on before with
    // the `vendor` .htaccess rule matching assets/vendor/ by name alone. A
    // whole-directory deny's location path ends exactly at the trailing
    // slash (whitespace follows); a narrower deny has another path segment
    // there instead.
    test("nginx snippet does NOT deny $dir/ as a whole (a narrower deny under it is fine)",
        preg_match('#location \^~ /' . preg_quote($dir, '#') . '/\s#', $ngx) !== 1);
}

$hard = (string) @file_get_contents($root . '/docs/WEB-SERVER-HARDENING.md');
test('docs/WEB-SERVER-HARDENING.md ships', $hard !== '');
test('it states plainly that nginx never reads .htaccess',
    stripos($hard, 'nginx') !== false && stripos($hard, '.htaccess') !== false
    && preg_match('/nginx\s+(never|does not|ignores)/i', $hard) === 1);
test('it says IIS ignores .htaccess too', stripos($hard, 'IIS') !== false);
test('it gives the operator a curl one-liner to check their own install',
    strpos($hard, 'curl') !== false && strpos($hard, '/backups/') !== false);

$vhost = (string) @file_get_contents($root . '/apache/newui.conf.example');
test('the shipped Apache vhost turns directory listings OFF',
    strpos($vhost, 'Options -Indexes') !== false,
    'the template used to say `Options Indexes FollowSymLinks`, which is how '
    . 'GET /backups/ came to return a browsable list of database dumps');
test('the shipped Apache vhost denies the private directories at server level',
    strpos($vhost, '<DirectoryMatch') !== false && strpos($vhost, 'backups') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- Layer 4: backups are not under the web root --\n";
$n = function (string $p): string { return rtrim(str_replace('\\', '/', $p), '/'); };

test('BACKUP_DIR is defined', defined('BACKUP_DIR'));
test('BACKUP_DIR resolves OUTSIDE the web root',
    defined('BACKUP_DIR') && strpos($n(BACKUP_DIR) . '/', $n($root) . '/') !== 0,
    'BACKUP_DIR=' . (defined('BACKUP_DIR') ? BACKUP_DIR : 'undefined')
    . ' root=' . $root);
// NOT "a sibling of the install directory" any more. That was the whole of the
// 2026-08-02 regression: dirname() is above the web root on /var/www/newui and
// is C:\inetpub\wwwroot — another site's document root, on port 80 — for a
// stock Windows install. The platform split is asserted properly, both sides,
// in tests/test_backup_dir_platform.php.
test('the POSIX default is a sibling of the install directory (like FE_KEYS_DIR)',
    $n(backup_default_dir_for('/var/www/newui', false)) === '/var/www/backups');
test('the Windows default is NOT a sibling of the install directory',
    $n(backup_default_dir_for('C:\\inetpub\\wwwroot\\TicketsV4', true))
        !== $n('C:/inetpub/wwwroot/backups'),
    'dirname() on a stock IIS install is C:\\inetpub\\wwwroot — Default Web Site, port 80');
test('BACKUP_DIR_LEGACY still names the old in-webroot path',
    defined('BACKUP_DIR_LEGACY') && strpos($n(BACKUP_DIR_LEGACY), $n($root) . '/backups') === 0,
    'without it, archives an existing install already wrote would be orphaned');
test('BACKUP_DIR_LEGACY_SIBLING names the v4.2.3 default, so its archives are not orphaned',
    defined('BACKUP_DIR_LEGACY_SIBLING')
    && $n(BACKUP_DIR_LEGACY_SIBLING) === $n(dirname($root)) . '/backups');

test('backup_dir_is_web_served() recognises the old location',
    backup_dir_is_web_served(BACKUP_DIR_LEGACY) === true);
test('backup_dir_is_web_served() clears the new one',
    backup_dir_is_web_served(BACKUP_DIR) === false);

require_once $root . '/inc/backup_schedule.php';
test('backup_dirs_all() exists', function_exists('backup_dirs_all'));
if (function_exists('backup_dirs_all') && is_dir(BACKUP_DIR_LEGACY)) {
    $all = array_map($n, backup_dirs_all());
    test('backup_dirs_all() includes the legacy directory, so old archives stay listable',
        in_array($n(BACKUP_DIR_LEGACY), $all, true));
} else {
    echo "SKIP: no legacy backups/ directory on this machine — nothing to keep listable\n";
}

// Retention must never reach into the legacy directory: an update that deleted
// archives the operator has not been told about yet would be a data-loss bug
// dressed up as a security fix.
$sched = (string) @file_get_contents($root . '/inc/backup_schedule.php');
test('retention prunes only the ACTIVE directory, never backup_dirs_all()',
    preg_match('/backup_prune[^;]*backup_dirs_all/', $sched) !== 1);

$compose = (string) @file_get_contents($root . '/docker-compose.yml');
test('docker-compose mounts the backups volume outside the DocumentRoot',
    strpos($compose, 'app_backups:/var/www/backups') !== false
    && strpos($compose, 'app_backups:/var/www/html/backups') === false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- The install can check itself --\n";
require_once $root . '/inc/health-check.php';

test('health_check_web_exposure() exists', function_exists('health_check_web_exposure'));
test('health_check_backups() exists', function_exists('health_check_backups'));
test('health_check_all() reports both sections',
    array_key_exists('web_exposure', health_check_all())
    && array_key_exists('backups', health_check_all()));

$bk = health_check_backups();
test('the backup-location check runs', !empty($bk['checked']));
test('it names the directory it found', !empty($bk['active_dir']));
if (!empty($bk['checked'])) {
    // On a machine that still has archives in the old place this is CRITICAL,
    // and that is the correct answer — including on this developer's box.
    test('archives in a web-served directory are reported as critical',
        ((int) ($bk['exposed_archives'] ?? 0) > 0) === (($bk['severity'] ?? '') === 'critical')
        || ($bk['active_web_served'] ?? false),
        'exposed_archives=' . (int) ($bk['exposed_archives'] ?? 0)
        . ' severity=' . ($bk['severity'] ?? '?'));
}

$we = health_check_web_exposure();
// Under CLI there is no request to derive a URL from, so the honest answer is
// "not checked" with an explanation — never a green "ok" the operator would
// read as an all-clear.
test('the HTTP probe reports honestly when it cannot run (CLI: no base URL)',
    ($we['checked'] ?? null) === false ? !empty($we['error']) : true);
test('a failed probe is never reported as a pass',
    ($we['checked'] ?? null) === false ? ($we['severity'] ?? '') !== 'critical' : true);

$hcSrc = (string) file_get_contents($root . '/inc/health-check.php');
test('the probe uses HEAD, not GET (a probe target may be a 110 MB archive)',
    strpos($hcSrc, 'CURLOPT_NOBODY') !== false && strpos($hcSrc, "'method'          => 'HEAD'") !== false);
test('the probe does not follow redirects (a redirect is not a deny)',
    strpos($hcSrc, 'CURLOPT_FOLLOWLOCATION => false') !== false);
test('an unreachable probe is "unknown", not "blocked"',
    strpos($hcSrc, "'unknown'") !== false);

$status = (string) file_get_contents($root . '/status.php');
test('the Status page renders the web-exposure result',
    strpos($status, 'data.web_exposure') !== false);
test('an exposed directory is shown as a prominent alert, not a table row',
    strpos($status, 'alert alert-danger') !== false
    && strpos($status, 'reachable over HTTP') !== false);

// ─────────────────────────────────────────────────────────────────────
echo "\n-- The advisory --\n";
$adv = (string) @file_get_contents($root . '/docs/security/advisory-2026-07-30-exposed-directories.md');
test('docs/security/advisory-2026-07-30-exposed-directories.md ships', $adv !== '');
test('the advisory tells the reader how to check their own install',
    strpos($adv, 'curl') !== false);
test('the advisory says what to do if the backups directory was exposed',
    stripos($adv, 'backups') !== false
    && (stripos($adv, 'password') !== false || stripos($adv, 'rotate') !== false));

echo "\n=== Results: $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
