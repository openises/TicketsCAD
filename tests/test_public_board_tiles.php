<?php
/**
 * Public incident board — its own map-tile setting (2026-10-15).
 *
 * The public board (public-board.php, data from api/public-board.php) is read
 * by ANONYMOUS visitors. Its map hard-coded OpenStreetMap. The rest of the
 * app follows the administrator's tile provider (inc/tile-config.php,
 * api/map-config.php), but that configuration may carry an API key and the
 * tile proxy is sign-in only, so the board must never use it. Instead the
 * board gets two settings of its own — public_board_tile_url (empty =
 * OpenStreetMap) and public_board_tile_attribution — set in the install-wide
 * panel of public-board-admin.php.
 *
 * This test drives the REAL code throughout:
 *   1. pb_validate_board_tile_settings(): accepts, refuses, normalises, and
 *      returns soft warnings (key-looking query parameter, private host, no
 *      credit) without refusing.
 *   2. The save path: api/public-board-admin.php save_settings / settings,
 *      through tests/_public_board_tiles_probe.php (include, real session,
 *      real csrf_verify()), with the settings table checked afterwards. A
 *      refused address changes NOTHING; a save that omits the tile fields
 *      leaves them alone.
 *   3. pb_board_tile_config() in a fresh process: empty = OpenStreetMap; a
 *      bad value stored by some other route falls back to OpenStreetMap.
 *   4. The anonymous api/public-board.php over real HTTP (tests/_pb_test_server.php),
 *      no session: the new board.map_tiles field, the default when empty,
 *      the rest of the envelope unchanged, the ETag, and that nothing from
 *      the app's own tile settings (tile_api_key, tile_server_url) leaks.
 *   5. Static wiring: the fields are in the install-wide panel only, the
 *      security explanation is on the page, the admin JS posts the keys and
 *      renders warnings as text.
 *   6. The REAL public-board.php inline script under node: the pure
 *      tileLayerSpec()/escapeHtml(), and the whole page run with stubbed
 *      document/fetch/Leaflet — no tile request before the settings arrive,
 *      the configured address used, the credit escaped, a later change swaps
 *      the layer.
 *   7. The administrator guide documents it.
 *
 * NOT @requires-http: section 4 starts its own `php -S` server (same as
 * tests/test_public_board_org_scope.php).
 *
 * @requires-db
 * Usage: php tests/test_public_board_tiles.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_pb_test_server.php';

$pass = 0; $fail = 0;
function t($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else { echo "[FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
}

$base = realpath(__DIR__ . '/..');
$OSM_URL    = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
$OSM_CREDIT = "\u{00A9} OpenStreetMap contributors";

/** Run a command (argv array, never a shell string) with stdout/stderr to temp files — no pipes to deadlock on. */
function pbt_run(array $argv): string
{
    $out = tempnam(sys_get_temp_dir(), 'pbto');
    $err = tempnam(sys_get_temp_dir(), 'pbte');
    $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) { @unlink($out); @unlink($err); return ''; }
    fclose($pipes[0]);
    proc_close($proc);
    $s = (string) @file_get_contents($out);
    @unlink($out); @unlink($err);
    return $s;
}

function pbt_probe(array $args): ?array
{
    $raw = pbt_run(array_merge([PHP_BINARY, __DIR__ . '/_public_board_tiles_probe.php'], $args));
    $j = json_decode(trim($raw), true);
    return is_array($j) ? $j : null;
}

function pbt_setting(string $name): ?string
{
    $v = db_fetch_value("SELECT `value` FROM " . db_table('settings') . " WHERE `name` = ? LIMIT 1", [$name]);
    return ($v === false || $v === null) ? null : (string) $v;
}

function pbt_set(string $name, ?string $value): void
{
    if ($value === null) {
        db_query("DELETE FROM " . db_table('settings') . " WHERE `name` = ?", [$name]);
        return;
    }
    db_query("INSERT INTO " . db_table('settings') . " (`name`, `value`) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, $value]);
}

function pbt_strip_php_comments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

echo "=== Public incident board — map tile setting ===\n\n";

// Every setting this test can touch, restored at the end whatever happens.
$touched = [
    'public_board_enabled', 'public_board_address_precision', 'public_board_excluded_groups',
    'public_board_default_delay_secs', 'public_board_rate_limit_requests', 'public_board_rate_limit_window_secs',
    'public_board_tile_url', 'public_board_tile_attribution', 'tile_api_key', 'tile_server_url',
];
$snapshot = [];
foreach ($touched as $n) $snapshot[$n] = pbt_setting($n);

$srv = null;
$fixtureOrgId = null;

try {

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. pb_validate_board_tile_settings() --\n";
// ─────────────────────────────────────────────────────────────────────────

t('pb_validate_board_tile_settings() exists', function_exists('pb_validate_board_tile_settings'));
t('pb_board_tile_config() exists', function_exists('pb_board_tile_config'));

if (function_exists('pb_validate_board_tile_settings')) {
    $v = pb_validate_board_tile_settings('', '');
    t('empty address is valid (means OpenStreetMap)', $v['ok'] === true && $v['url'] === '' && $v['warnings'] === []);

    $v = pb_validate_board_tile_settings('  ' . $OSM_URL . "  ", $OSM_CREDIT);
    t('the OpenStreetMap template is accepted (trimmed, {s} allowed)', $v['ok'] && $v['url'] === $OSM_URL && $v['warnings'] === [],
        json_encode($v));

    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}{r}.png', 'Example Tiles');
    t('{r} (high-resolution suffix) is accepted', $v['ok'] === true, (string) $v['error']);

    $v = pb_validate_board_tile_settings('HTTP://tiles.example.org/{z}/{x}/{y}.png', 'Example');
    t('scheme check is case-insensitive', $v['ok'] === true, (string) $v['error']);

    // Attribution: normalised (control characters, whitespace, 200 chars), NOT HTML-escaped.
    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}.png', "  Map\x00data\n\t(c)  <b>Co</b> & \"x\"  ");
    t('attribution: control characters removed, whitespace collapsed', $v['attribution'] === 'Map data (c) <b>Co</b> & "x"',
        var_export($v['attribution'], true));
    t('attribution stored as typed (markup not escaped server side)', strpos($v['attribution'], '<b>') !== false);
    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}.png', str_repeat("\u{00A9}", 260));
    t('attribution capped at 200 characters', mb_strlen($v['attribution'], 'UTF-8') === 200, (string) mb_strlen($v['attribution'], 'UTF-8'));

    // Refusals.
    $refuse = [
        'javascript: scheme'                  => 'javascript:alert(1)//{z}/{x}/{y}',
        'data: scheme'                        => 'data:text/html,{z}{x}{y}',
        'protocol-relative //host'            => '//tiles.example.org/{z}/{x}/{y}.png',
        'ftp scheme'                          => 'ftp://tiles.example.org/{z}/{x}/{y}.png',
        'http: with no host'                  => 'https:/{z}/{x}/{y}.png',
        'user:password@ in the address'       => 'https://user:secret@tiles.example.org/{z}/{x}/{y}.png',
        'user@ in the address'                => 'https://user@tiles.example.org/{z}/{x}/{y}.png',
        '{key} placeholder'                   => 'https://tiles.example.org/{z}/{x}/{y}.png?key={key}',
        '{KEY} placeholder (any case)'        => 'https://tiles.example.org/{z}/{x}/{y}.png?key={KEY}',
        '{apikey} placeholder'                => 'https://tiles.example.org/{z}/{x}/{y}.png?apikey={apikey}',
        '{access_token} placeholder'          => 'https://tiles.example.org/{z}/{x}/{y}?access_token={access_token}',
        '{q} quadkey placeholder'             => 'https://ecn.t{s}.tiles.example.org/tiles/r{q}?g=1&{z}{x}{y}',
        'unknown placeholder'                 => 'https://tiles.example.org/{z}/{x}/{y}/{style}.png',
        'missing {y}'                         => 'https://tiles.example.org/{z}/{x}.png',
        'missing {z}'                         => 'https://tiles.example.org/{x}/{y}.png',
        'a space in the address'              => 'https://tiles.example.org/{z}/{x}/{y} .png',
        'a control character'                 => "https://tiles.example.org/{z}/{x}/{y}.png\x07",
        'longer than the maximum'             => 'https://tiles.example.org/{z}/{x}/{y}.png?p=' . str_repeat('a', 2100),
    ];
    foreach ($refuse as $what => $url) {
        $v = pb_validate_board_tile_settings($url, 'credit');
        t("refused: {$what}", $v['ok'] === false && is_string($v['error']) && $v['error'] !== '', json_encode($v));
    }
    $v = pb_validate_board_tile_settings('https://user:secret@tiles.example.org/{z}/{x}/{y}.png', 'c');
    t('the credentials refusal says why (user name or password)', stripos((string) $v['error'], 'password') !== false, (string) $v['error']);
    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}.png?key={key}', 'c');
    t('the {key} refusal names {key}', strpos((string) $v['error'], '{key}') !== false, (string) $v['error']);
    $v = pb_validate_board_tile_settings('https://ecn.t{s}.tiles.example.org/tiles/r{q}?{z}{x}{y}', 'c');
    t('the {q} refusal names {q}', strpos((string) $v['error'], '{q}') !== false, (string) $v['error']);
    $v = pb_validate_board_tile_settings('javascript:alert(1)//{z}/{x}/{y}', 'c');
    t('the scheme refusal names https://', strpos((string) $v['error'], 'https://') !== false, (string) $v['error']);

    // Soft warnings — the save is NOT refused.
    foreach (['key', 'token', 'apikey', 'api_key', 'access_token', 'sig', 'signature', 'KEY', 'Access_Token'] as $param) {
        $v = pb_validate_board_tile_settings("https://tiles.example.org/{z}/{x}/{y}.png?style=a&{$param}=abc123", 'credit');
        t("soft warning, not refusal, for ?{$param}=", $v['ok'] === true && count($v['warnings']) === 1
            && stripos($v['warnings'][0], 'key or token') !== false, json_encode($v));
    }
    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}.png?monkey=1&keyboard=2', 'credit');
    t('no warning for parameter names that merely contain "key"', $v['ok'] && $v['warnings'] === [], json_encode($v['warnings']));

    foreach (['http://10.0.0.10/{z}/{x}/{y}.png', 'http://192.168.1.20:8080/{z}/{x}/{y}.png', 'http://127.0.0.1/{z}/{x}/{y}.png',
              'http://localhost/{z}/{x}/{y}.png', 'https://tiles.local/{z}/{x}/{y}.png', 'http://tileserver/{z}/{x}/{y}.png',
              'http://[::1]/{z}/{x}/{y}.png', 'https://maps.corp/{z}/{x}/{y}.png'] as $url) {
        $v = pb_validate_board_tile_settings($url, 'credit');
        t("soft warning for a private/internal host: {$url}", $v['ok'] === true && count($v['warnings']) === 1
            && stripos($v['warnings'][0], 'private or internal') !== false, json_encode($v));
    }
    $v = pb_validate_board_tile_settings('https://{s}.tile.example.org/{z}/{x}/{y}.png', 'credit');
    t('no private-host warning for a public name with {s}', $v['ok'] && $v['warnings'] === [], json_encode($v['warnings']));

    $v = pb_validate_board_tile_settings('https://tiles.example.org/{z}/{x}/{y}.png', '   ');
    t('soft warning when a custom address has no credit', $v['ok'] === true && count($v['warnings']) === 1
        && stripos($v['warnings'][0], 'credit') !== false, json_encode($v));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The save path (api/public-board-admin.php through a CLI probe) --\n";
// ─────────────────────────────────────────────────────────────────────────

$adminId = test_admin_user_id();
$cur = function (string $n, string $d) { $v = pbt_setting($n); return $v === null ? $d : $v; };
// Everything else stays exactly as stored, so no off->on transition and no other change.
$basePayload = [
    'public_board_enabled'                => $cur('public_board_enabled', '0') === '1' ? 1 : 0,
    'public_board_address_precision'      => $cur('public_board_address_precision', 'block'),
    'public_board_default_delay_secs'     => $cur('public_board_default_delay_secs', '90'),
    'public_board_excluded_groups'        => $cur('public_board_excluded_groups', ''),
    'public_board_rate_limit_requests'    => $cur('public_board_rate_limit_requests', '30'),
    'public_board_rate_limit_window_secs' => $cur('public_board_rate_limit_window_secs', '60'),
];
$save = function (array $extra) use ($adminId, $basePayload) {
    return pbt_probe(['admin', 'POST', 'save_settings', (string) $adminId, json_encode($extra + $basePayload)]);
};

pbt_set('public_board_tile_url', null);
pbt_set('public_board_tile_attribution', null);

$r = $save(['public_board_tile_url' => ' https://tiles.example.org/{z}/{x}/{y}.png ',
            'public_board_tile_attribution' => "Example\tTiles <b>&</b>"]);
t('save with a valid address succeeds', is_array($r) && ($r['saved'] ?? false) === true, json_encode($r));
t('...with no warnings', is_array($r) && ($r['warnings'] ?? null) === [], json_encode($r));
t('...the trimmed address is stored', pbt_setting('public_board_tile_url') === 'https://tiles.example.org/{z}/{x}/{y}.png',
    var_export(pbt_setting('public_board_tile_url'), true));
t('...the credit is stored normalised but not escaped', pbt_setting('public_board_tile_attribution') === 'Example Tiles <b>&</b>',
    var_export(pbt_setting('public_board_tile_attribution'), true));

$g = pbt_probe(['admin', 'GET', 'settings', (string) $adminId, '']);
t('GET settings returns the two tile settings', is_array($g)
    && ($g['settings']['public_board_tile_url'] ?? null) === 'https://tiles.example.org/{z}/{x}/{y}.png'
    && ($g['settings']['public_board_tile_attribution'] ?? null) === 'Example Tiles <b>&</b>', json_encode($g));
t('...and an empty warnings list', is_array($g) && ($g['warnings'] ?? null) === [], json_encode($g));

$r = $save(['public_board_tile_url' => 'https://tiles.example.org/{z}/{x}/{y}.png?access_token=pk.public123',
            'public_board_tile_attribution' => 'Example Tiles']);
t('a key-looking address still saves', is_array($r) && ($r['saved'] ?? false) === true, json_encode($r));
t('...and the response carries the warning', is_array($r) && count($r['warnings'] ?? []) === 1
    && stripos((string) $r['warnings'][0], 'key or token') !== false, json_encode($r));
t('...the address was stored', pbt_setting('public_board_tile_url') === 'https://tiles.example.org/{z}/{x}/{y}.png?access_token=pk.public123');
$g = pbt_probe(['admin', 'GET', 'settings', (string) $adminId, '']);
t('GET settings repeats the warning for what is stored', is_array($g) && count($g['warnings'] ?? []) === 1, json_encode($g));

// A refused address changes nothing — not even the other fields sent with it.
$before = pbt_setting('public_board_tile_url');
$beforeRl = pbt_setting('public_board_rate_limit_requests');
$refusedCases = [
    'javascript: address' => 'javascript:alert(1)//{z}/{x}/{y}',
    '{key} address'       => 'https://tiles.example.org/{z}/{x}/{y}.png?key={key}',
    'credentials'         => 'https://u:p@tiles.example.org/{z}/{x}/{y}.png',
    '{q} address'         => 'https://tiles.example.org/{q}?{z}{x}{y}',
];
foreach ($refusedCases as $what => $url) {
    $r = pbt_probe(['admin', 'POST', 'save_settings', (string) $adminId, json_encode([
        'public_board_tile_url' => $url, 'public_board_tile_attribution' => 'X',
        'public_board_rate_limit_requests' => '977',
    ] + $basePayload)]);
    t("save refused for a {$what}, with a message", is_array($r) && isset($r['error']) && empty($r['saved']), json_encode($r));
}
t('...the stored address is unchanged after the refusals', pbt_setting('public_board_tile_url') === $before);
t('...and no other field from those requests was written', pbt_setting('public_board_rate_limit_requests') === $beforeRl,
    var_export(pbt_setting('public_board_rate_limit_requests'), true));

// A client that predates these fields (or a panel that does not send them) leaves them alone.
$r = $save([]);
t('a save without the tile fields succeeds', is_array($r) && ($r['saved'] ?? false) === true, json_encode($r));
t('...and leaves the stored tile address alone', pbt_setting('public_board_tile_url') === $before);
t('...and the stored credit alone', pbt_setting('public_board_tile_attribution') === 'Example Tiles');

$r = $save(['public_board_tile_url' => '', 'public_board_tile_attribution' => '']);
t('clearing the address saves (back to OpenStreetMap)', is_array($r) && ($r['saved'] ?? false) === true
    && pbt_setting('public_board_tile_url') === '', json_encode($r));

$r = pbt_probe(['admin', 'POST', 'save_settings', (string) $adminId, json_encode([
    'public_board_tile_url' => 'https://tiles.example.org/{z}/{x}/{y}.png', 'csrf_token' => 'bogus',
] + $basePayload)]);
t('a save with a bad CSRF token is refused', is_array($r) && isset($r['error']) && pbt_setting('public_board_tile_url') === '',
    json_encode($r));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. pb_board_tile_config() in a fresh process --\n";
// ─────────────────────────────────────────────────────────────────────────

pbt_set('public_board_tile_url', null);
pbt_set('public_board_tile_attribution', null);
$c = pbt_probe(['config']);
t('no rows: OpenStreetMap default', $c === ['url' => $OSM_URL, 'attribution' => $OSM_CREDIT, 'is_default' => true], json_encode($c));

pbt_set('public_board_tile_url', '');
pbt_set('public_board_tile_attribution', 'Ignored without an address');
$c = pbt_probe(['config']);
t('empty address: OpenStreetMap default, the stored credit ignored', $c === ['url' => $OSM_URL, 'attribution' => $OSM_CREDIT, 'is_default' => true],
    json_encode($c));

pbt_set('public_board_tile_url', 'https://tiles.example.org/{z}/{x}/{y}.png');
pbt_set('public_board_tile_attribution', 'Example <i>Tiles</i>');
$c = pbt_probe(['config']);
t('configured address and credit are published as stored', $c === ['url' => 'https://tiles.example.org/{z}/{x}/{y}.png',
    'attribution' => 'Example <i>Tiles</i>', 'is_default' => false], json_encode($c));

// Written by some other route (direct SQL, an older build) — never reaches visitors.
foreach (['javascript:alert(1)//{z}/{x}/{y}', 'https://tiles.example.org/{z}/{x}/{y}.png?key={key}',
          'https://u:p@tiles.example.org/{z}/{x}/{y}.png'] as $bad) {
    pbt_set('public_board_tile_url', $bad);
    $c = pbt_probe(['config']);
    t("a stored invalid address falls back to OpenStreetMap: {$bad}", is_array($c) && ($c['is_default'] ?? null) === true
        && ($c['url'] ?? '') === $OSM_URL, json_encode($c));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The anonymous api/public-board.php over real HTTP --\n";
// ─────────────────────────────────────────────────────────────────────────

$srv = pb_test_start_server();
if ($srv === null) {
    echo "SKIP (section 4 only): could not start a local PHP server (proc_open/curl unavailable)\n";
} else {
    $slug = 'zzpbt-' . substr(md5((string) mt_rand()), 0, 8);
    db_query("INSERT INTO " . db_table('organizations') . " (`name`, `public_board_enabled`, `public_board_slug`) VALUES (?,1,?)",
        ["ZZ tile test ({$slug})", $slug]);
    $fixtureOrgId = (int) db_insert_id();
    $url = 'http://127.0.0.1:' . $srv['port'] . '/api/public-board.php?org=' . rawurlencode($slug);

    // Sentinels in the APP's own tile settings: none of this may reach the anonymous API.
    $keySentinel = 'zzSECRETKEY' . mt_rand(100000, 999999);
    $srvSentinel = 'https://zz-private-tiles.example.net/{z}/{x}/{y}.png?key=' . $keySentinel;
    pbt_set('tile_api_key', $keySentinel);
    pbt_set('tile_server_url', $srvSentinel);

    pbt_set('public_board_tile_url', null);
    pbt_set('public_board_tile_attribution', null);
    $r1 = pb_test_http_get($url);
    $j1 = $r1 ? json_decode($r1['body'], true) : null;
    t('no setting: the board answers 200 with no session', $r1 !== null && $r1['status'] === 200, $r1 ? (string) $r1['status'] : 'no response');
    t('...board.map_tiles is the OpenStreetMap default', is_array($j1)
        && ($j1['board']['map_tiles'] ?? null) === ['url' => $OSM_URL, 'attribution' => $OSM_CREDIT, 'is_default' => true],
        json_encode($j1['board']['map_tiles'] ?? null));
    $keys = is_array($j1) ? array_values(array_diff(array_keys($j1['board'] ?? []), ['logo_url', 'logo_alt'])) : [];
    t('...the envelope is otherwise unchanged (same keys, map_tiles added last)',
        $keys === ['title', 'generated', 'precision_level', 'org_scoped', 'count', 'map_tiles'], json_encode($keys));
    t('...top level is still board + incidents', is_array($j1) && array_keys($j1) === ['board', 'incidents']);
    t('...nothing from the app\'s own tile settings leaks (key, server URL, provider fields)',
        $r1 !== null && strpos($r1['body'], $keySentinel) === false && strpos($r1['body'], 'zz-private-tiles') === false
        && strpos($r1['body'], 'tile_api_key') === false && strpos($r1['body'], 'tile_provider') === false
        && strpos($r1['body'], 'tile-proxy') === false, $r1['body'] ?? '');
    $etagDefault = $r1['headers']['etag'] ?? '';

    pbt_set('public_board_tile_url', '');
    $r2 = pb_test_http_get($url);
    t('an explicitly empty setting gives the same ETag as no setting (unconfigured installs unchanged)',
        $r2 !== null && $etagDefault !== '' && ($r2['headers']['etag'] ?? '') === $etagDefault);

    pbt_set('public_board_tile_url', 'https://tiles.example.org/{z}/{x}/{y}.png');
    pbt_set('public_board_tile_attribution', '<img src=x onerror=alert(1)> & "Co"');
    $r3 = pb_test_http_get($url, ['If-None-Match: ' . $etagDefault]);
    $j3 = $r3 ? json_decode($r3['body'], true) : null;
    t('configured: a cached copy is not reused (200, not 304)', $r3 !== null && $r3['status'] === 200, $r3 ? (string) $r3['status'] : '');
    t('...the ETag changed', $r3 !== null && ($r3['headers']['etag'] ?? '') !== '' && ($r3['headers']['etag'] ?? '') !== $etagDefault);
    t('...map_tiles carries the configured address and the plain-text credit',
        is_array($j3) && ($j3['board']['map_tiles'] ?? null) === [
            'url' => 'https://tiles.example.org/{z}/{x}/{y}.png',
            'attribution' => '<img src=x onerror=alert(1)> & "Co"',
            'is_default' => false,
        ], json_encode($j3['board']['map_tiles'] ?? null));
    t('...still nothing from the app\'s own tile settings', $r3 !== null && strpos($r3['body'], $keySentinel) === false
        && strpos($r3['body'], 'zz-private-tiles') === false);

    pbt_set('public_board_tile_url', 'https://tiles.example.org/{z}/{x}/{y}.png?key={key}');
    $r4 = pb_test_http_get($url);
    $j4 = $r4 ? json_decode($r4['body'], true) : null;
    t('a stored invalid address is served as the OpenStreetMap default', is_array($j4)
        && ($j4['board']['map_tiles']['is_default'] ?? null) === true && ($j4['board']['map_tiles']['url'] ?? '') === $OSM_URL,
        json_encode($j4['board']['map_tiles'] ?? null));
    t('...with the default ETag', $r4 !== null && ($r4['headers']['etag'] ?? '') === $etagDefault);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Static wiring --\n";
// ─────────────────────────────────────────────────────────────────────────

$adminPage = (string) file_get_contents($base . '/public-board-admin.php');
$tilePanelPos = strpos($adminPage, 'id="pbPanelTiles"');
t('the admin page has a map tiles panel', $tilePanelPos !== false);
$ifPos = $tilePanelPos === false ? false : strrpos(substr($adminPage, 0, $tilePanelPos), '<?php if ($isBoardAdmin): ?>');
$endifPos = $tilePanelPos === false ? false : strpos($adminPage, '<?php endif; ?>', $tilePanelPos);
$between = ($ifPos !== false && $endifPos !== false) ? substr($adminPage, $ifPos, $endifPos - $ifPos) : '';
t('...inside an install-wide ($isBoardAdmin) block', $between !== '' && strpos($between, 'id="pbTileUrl"') !== false
    && strpos($between, 'id="pbTileAttribution"') !== false && strpos($between, '<?php endif; ?>') === false
    && substr_count($between, '<?php if') === 1);
$orgStart = strpos($adminPage, 'id="pbPanelOrgs"');
$orgEnd = $orgStart === false ? false : strpos($adminPage, '<!-- ═══', $orgStart);
$orgPanel = ($orgStart !== false && $orgEnd !== false) ? substr($adminPage, $orgStart, $orgEnd - $orgStart) : '';
t('...and not in the org-scoped organizations panel', $orgPanel !== '' && strpos($orgPanel, 'pbTile') === false);
t('the credit field is limited to 200 characters', preg_match('/id="pbTileAttribution"[^>]*maxlength="200"/', $adminPage) === 1);
t('both fields have labels', strpos($adminPage, 'for="pbTileUrl"') !== false && strpos($adminPage, 'for="pbTileAttribution"') !== false);
foreach ([
    'Anyone who can open the public board can see this address',
    'downloads the map pictures (tiles) from it directly',
    'Never use an address that contains an API key, token, password or signature',
    'Do not use a private or internal address',
    'offline tile server',
    'usually will not work for them anyway',
    'must be able to cope with traffic from the public',
    'terms of use',
    'tile proxy and the main map settings (Settings &gt; Tile Providers) are <strong>not</strong>',
    'Leave the address empty to keep OpenStreetMap',
] as $phrase) {
    // Whitespace-collapsed, so re-wrapping the HTML source cannot break the check.
    t("security note says: \"{$phrase}\"", strpos((string) preg_replace('/\s+/', ' ', $adminPage), $phrase) !== false);
}

$adminJs = (string) file_get_contents($base . '/assets/js/public-board-admin.js');
t('admin JS posts public_board_tile_url', strpos($adminJs, "payload.public_board_tile_url = val('pbTileUrl')") !== false);
t('admin JS posts public_board_tile_attribution', strpos($adminJs, "payload.public_board_tile_attribution = val('pbTileAttribution')") !== false);
t('admin JS loads both into the fields', strpos($adminJs, "setVal('pbTileUrl', c.public_board_tile_url") !== false
    && strpos($adminJs, "setVal('pbTileAttribution', c.public_board_tile_attribution") !== false);
t('admin JS wires the Save map tiles button', strpos($adminJs, "on('pbSaveTiles', 'click'") !== false);
$fnStart = strpos($adminJs, 'function renderTileWarnings(');
$fnEnd = $fnStart === false ? false : strpos($adminJs, "\n    }\n", $fnStart);
$fnBody = ($fnStart !== false && $fnEnd !== false) ? substr($adminJs, $fnStart, $fnEnd - $fnStart) : '';
t('admin JS renders warnings as text, never markup', $fnBody !== '' && strpos($fnBody, 'textContent') !== false
    && strpos($fnBody, 'innerHTML') === false);
t('admin JS shows warnings after load and after save', substr_count($adminJs, 'renderTileWarnings(') >= 3);

$adminApi = pbt_strip_php_comments((string) file_get_contents($base . '/api/public-board-admin.php'));
$saveStart = strpos($adminApi, "\$action === 'save_settings'");
$saveEnd = $saveStart === false ? false : strpos($adminApi, "\$action === 'save_type'", $saveStart);
$saveBlock = ($saveStart !== false && $saveEnd !== false) ? substr($adminApi, $saveStart, $saveEnd - $saveStart) : '';
$gate = strpos($saveBlock, "if (!\$isBoardAdmin) json_error(");
$validate = strpos($saveBlock, 'pb_validate_board_tile_settings(');
$write = strpos($saveBlock, 'ON DUPLICATE KEY UPDATE');
t('the tile fields are saved by save_settings, behind the board-admin gate, validated before any write',
    $gate !== false && $validate !== false && $write !== false && $gate < $validate && $validate < $write);
t('...the board-admin gate still does not fall back to is_admin()', strpos($adminApi, "rbac_can('action.manage_public_board') || is_admin()") === false);

$pubApi = pbt_strip_php_comments((string) file_get_contents($base . '/api/public-board.php'));
t('api/public-board.php publishes pb_board_tile_config()', strpos($pubApi, 'pb_board_tile_config()') !== false
    && strpos($pubApi, "\$boardEnvelope['map_tiles']") !== false);
foreach (['tile_provider', 'tile_api_key', 'tile_server_url', 'resolve_tile_config', 'tile-proxy', 'map-config'] as $never) {
    t("api/public-board.php never reads {$never}", strpos($pubApi, $never) === false);
}
if (function_exists('pb_board_tile_config')) {
    $rf = new ReflectionFunction('pb_board_tile_config');
    $lines = file($rf->getFileName());
    $body = pbt_strip_php_comments("<?php\n" . implode('', array_slice($lines, $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1)));
    t('pb_board_tile_config() reads only the two board settings',
        preg_match_all("/get_variable\\('([a-z_]+)'\\)/", $body, $mm) === 2
        && $mm[1] === ['public_board_tile_url', 'public_board_tile_attribution'], json_encode($mm[1] ?? []));
    foreach (['tile_provider', 'tile_api_key', 'tile_server_url', 'resolve_tile_config'] as $never) {
        t("pb_board_tile_config() never touches {$never}", strpos($body, $never) === false);
    }
}

$page = (string) file_get_contents($base . '/public-board.php');
t('public-board.php no longer hard-codes the OpenStreetMap tile layer', strpos($page, "L.tileLayer('https://") === false);
t('public-board.php builds the layer from the spec', strpos($page, 'L.tileLayer(spec.url, {') !== false
    && strpos($page, 'attribution: spec.attribution') !== false);
t('public-board.php still never assigns .innerHTML', strpos($page, '.innerHTML') === false);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. The real public-board.php script under node --\n";
// ─────────────────────────────────────────────────────────────────────────

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $v = trim(pbt_run([$cand, '--version']));
    if (preg_match('/^v\d+/', $v)) { $node = $cand; break; }
}
$jsBody = preg_match('/<script>([\s\S]*)<\/script>/', $page, $m) ? $m[1] : '';
if ($node === null || trim($jsBody) === '') {
    echo "SKIP (section 6 only): node not available or the inline script could not be extracted\n";
} else {
    $scriptFile  = sys_get_temp_dir() . '/tcad_pbtiles_page_' . getmypid() . '.js';
    $harnessFile = sys_get_temp_dir() . '/tcad_pbtiles_harness_' . getmypid() . '.js';
    file_put_contents($scriptFile, $jsBody);
    $harness = <<<'JS'
var fs = require('fs');
var src = fs.readFileSync(process.argv[2], 'utf8');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }
var OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
var OSM_CREDIT = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
var realSetInterval = global.setInterval;

// ── A. pure functions (no document: the page script stops after exporting them)
function loadPure() {
    delete global.document;
    global.window = global;
    delete global.PublicBoardRender;
    eval(src);
    return global.PublicBoardRender;
}
var PB = loadPure();
check('PublicBoardRender.tileLayerSpec exists', !!(PB && typeof PB.tileLayerSpec === 'function'));
check('PublicBoardRender.escapeHtml exists', !!(PB && typeof PB.escapeHtml === 'function'));
if (PB && PB.tileLayerSpec && PB.escapeHtml) {
    var s = PB.tileLayerSpec(undefined);
    check('no board: OpenStreetMap with its linked credit', s.url === OSM_URL && s.attribution === OSM_CREDIT && s.isDefault === true, JSON.stringify(s));
    s = PB.tileLayerSpec({ title: 'x' });
    check('an older server with no map_tiles: OpenStreetMap', s.url === OSM_URL && s.attribution === OSM_CREDIT, JSON.stringify(s));
    s = PB.tileLayerSpec({ map_tiles: { url: OSM_URL, attribution: '\u00a9 OpenStreetMap contributors', is_default: true } });
    check('is_default: the existing linked OpenStreetMap credit, unchanged', s.url === OSM_URL && s.attribution === OSM_CREDIT && s.isDefault === true, JSON.stringify(s));
    s = PB.tileLayerSpec({ map_tiles: { url: 'https://tiles.example.org/{z}/{x}/{y}.png', attribution: 'Example Tiles', is_default: false } });
    check('configured: the address is used', s.url === 'https://tiles.example.org/{z}/{x}/{y}.png' && s.isDefault === false, JSON.stringify(s));
    check('configured: plain credit passes through', s.attribution === 'Example Tiles', s.attribution);
    s = PB.tileLayerSpec({ map_tiles: { url: 'https://tiles.example.org/{z}/{x}/{y}.png', attribution: '<img src=x onerror=alert(1)>', is_default: false } });
    check('configured: markup in the credit is escaped', s.attribution === '&lt;img src=x onerror=alert(1)&gt;', s.attribution);
    s = PB.tileLayerSpec({ map_tiles: { url: 'https://tiles.example.org/{z}/{x}/{y}.png', attribution: 'Tom & "Jerry\'s" maps', is_default: false } });
    check('configured: & " and \' are escaped', s.attribution === 'Tom &amp; &quot;Jerry&#39;s&quot; maps', s.attribution);
    s = PB.tileLayerSpec({ map_tiles: { url: 'https://tiles.example.org/{z}/{x}/{y}.png', is_default: false } });
    check('configured with no credit: empty credit, never undefined', s.attribution === '', JSON.stringify(s));
    s = PB.tileLayerSpec({ map_tiles: { url: 'javascript:alert(1)//{z}/{x}/{y}', attribution: 'x', is_default: false } });
    check('a javascript: address from the API is not used', s.url === OSM_URL && s.isDefault === true, JSON.stringify(s));
    s = PB.tileLayerSpec({ map_tiles: { url: '//tiles.example.org/{z}/{x}/{y}.png', attribution: 'x', is_default: false } });
    check('a protocol-relative address from the API is not used', s.url === OSM_URL, JSON.stringify(s));
    s = PB.tileLayerSpec({ map_tiles: { url: 'https://tiles.example.org/{z}/{x}.png', attribution: 'x', is_default: false } });
    check('an address without {y} is not used', s.url === OSM_URL, JSON.stringify(s));
    check('escapeHtml(null) is empty', PB.escapeHtml(null) === '');
    check('escapeHtml escapes all five characters', PB.escapeHtml('<>&"\'') === '&lt;&gt;&amp;&quot;&#39;', PB.escapeHtml('<>&"\''));
}

// ── B. the whole page, with stubbed document / fetch / Leaflet
function runPage(responses) {
    var st = { tileCalls: [], removed: [], poll: null, fetches: 0 };
    var els = {};
    function makeEl(tag) {
        return {
            tagName: tag, style: {}, textContent: '', className: '', firstChild: null, checked: false, _l: {},
            addEventListener: function (ev, fn) { this._l[ev] = fn; },
            appendChild: function (c) { return c; }, removeChild: function () {}, setAttribute: function () {},
            classList: { add: function () {}, remove: function () {} }
        };
    }
    global.document = {
        getElementById: function (id) { if (!els[id]) els[id] = makeEl('div'); return els[id]; },
        createElement: makeEl,
        createTextNode: function (t) { return { nodeType: 3, textContent: t }; },
        head: { appendChild: function () {} }
    };
    global.window = global;
    global.location = { search: '?org=test' };
    global.setInterval = function (fn) { st.poll = fn; return 1; };
    global.fetch = function () {
        var data = responses[Math.min(st.fetches, responses.length - 1)];
        st.fetches++;
        return Promise.resolve({
            status: 200, ok: true,
            headers: { get: function () { return null; } },
            json: function () { return Promise.resolve(JSON.parse(JSON.stringify(data))); }
        });
    };
    global.L = {
        map: function () {
            return {
                setView: function () { return this; }, setMaxZoom: function () {}, getZoom: function () { return 4; },
                setZoom: function () {}, fitBounds: function () {}, removeLayer: function (l) { st.removed.push(l); }
            };
        },
        tileLayer: function (url, opts) {
            var layer = { url: url, opts: opts, addTo: function () { return this; } };
            st.tileCalls.push(layer);
            return layer;
        },
        layerGroup: function () { return { addTo: function () { return this; }, clearLayers: function () {}, addLayer: function () {} }; },
        marker: function () { return { bindPopup: function () {} }; }
    };
    delete global.PublicBoardRender;
    eval(src);
    st.showMap = function () {
        var tg = els.pbShowMapToggle;
        tg.checked = true;
        if (tg._l.change) tg._l.change();
    };
    return st;
}
function flush() { return new Promise(function (r) { setTimeout(r, 5); }); }
function board(tiles) {
    var b = { title: 'Test board', precision_level: 'block', org_scoped: true, count: 0 };
    if (tiles !== undefined) b.map_tiles = tiles;
    return { board: b, incidents: [] };
}
var CUSTOM = { url: 'https://tiles.example.org/{z}/{x}/{y}.png', attribution: '<img src=x onerror=alert(1)> & Co', is_default: false };
var DEFAULT = { url: OSM_URL, attribution: '\u00a9 OpenStreetMap contributors', is_default: true };

(async function () {
    // B1 — the visitor opens the map before the first response arrives.
    var st = runPage([board(CUSTOM)]);
    st.showMap();
    check('map opened before the settings arrived: no tile request yet (OpenStreetMap is not fetched first)',
        st.tileCalls.length === 0, JSON.stringify(st.tileCalls.map(function (c) { return c.url; })));
    await flush();
    check('then exactly one tile layer', st.tileCalls.length === 1, String(st.tileCalls.length));
    check('...from the configured address', st.tileCalls.length > 0 && st.tileCalls[0].url === CUSTOM.url, st.tileCalls[0] && st.tileCalls[0].url);
    check('...with the credit escaped for Leaflet', st.tileCalls.length > 0
        && st.tileCalls[0].opts.attribution === '&lt;img src=x onerror=alert(1)&gt; &amp; Co', st.tileCalls[0] && st.tileCalls[0].opts.attribution);
    check('...OpenStreetMap never requested', st.tileCalls.every(function (c) { return c.url !== OSM_URL; }));

    // B2 — default board, data first, then the map.
    st = runPage([board(DEFAULT)]);
    await flush();
    st.showMap();
    check('default: one OpenStreetMap layer with the existing linked credit', st.tileCalls.length === 1
        && st.tileCalls[0].url === OSM_URL && st.tileCalls[0].opts.attribution === OSM_CREDIT && st.tileCalls[0].opts.maxZoom === 19,
        JSON.stringify(st.tileCalls[0] && { u: st.tileCalls[0].url, a: st.tileCalls[0].opts.attribution }));

    // B3 — an older server that sends no map_tiles.
    st = runPage([board(undefined)]);
    await flush();
    st.showMap();
    check('no map_tiles field: OpenStreetMap', st.tileCalls.length === 1 && st.tileCalls[0].url === OSM_URL);

    // B4 — the setting changes between polls: the layer is swapped, once.
    st = runPage([board(CUSTOM), board(DEFAULT), board(DEFAULT)]);
    await flush();
    st.showMap();
    st.poll();
    await flush();
    check('a changed setting swaps the layer', st.tileCalls.length === 2 && st.tileCalls[1].url === OSM_URL && st.removed.length === 1
        && st.removed[0] === st.tileCalls[0], String(st.tileCalls.length) + '/' + String(st.removed.length));
    st.poll();
    await flush();
    check('an unchanged setting does not rebuild the layer', st.tileCalls.length === 2 && st.removed.length === 1);

    global.setInterval = realSetInterval;
    console.log(out.join('\n'));
})().catch(function (e) { console.log(out.join('\n') + '\nFAIL|harness threw|' + (e && e.stack ? e.stack.split('\n')[0] : e)); });
JS;
    file_put_contents($harnessFile, $harness);
    $raw = pbt_run([$node, $harnessFile, $scriptFile]);
    @unlink($harnessFile);
    @unlink($scriptFile);
    $seen = 0;
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', trim($line), 3);
        if (count($parts) < 2 || ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL')) continue;
        $seen++;
        t('[js] ' . $parts[1], $parts[0] === 'PASS', $parts[2] ?? '');
    }
    t('every JS scenario reported', $seen >= 24, $seen . ' lines; raw: ' . substr(trim($raw), 0, 300));
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 7. Documentation --\n";
// ─────────────────────────────────────────────────────────────────────────

$doc = (string) file_get_contents($base . '/docs/PUBLIC-INCIDENT-BOARD.md');
t('the guide documents public_board_tile_url', strpos($doc, 'public_board_tile_url') !== false);
t('the guide documents public_board_tile_attribution', strpos($doc, 'public_board_tile_attribution') !== false);
t('the guide warns about keys in the address', stripos($doc, 'API key') !== false);
t('the guide explains the tile proxy is not used', stripos($doc, 'tile proxy') !== false);

} finally {
    if ($srv !== null) pb_test_stop_server($srv);
    if ($fixtureOrgId) {
        try { db_query("DELETE FROM " . db_table('organizations') . " WHERE `id` = ?", [$fixtureOrgId]); } catch (Throwable $e) { /* reported below */ }
    }
    foreach ($snapshot as $n => $v) {
        try { pbt_set($n, $v); } catch (Throwable $e) { echo "[WARN] could not restore setting {$n}: " . $e->getMessage() . "\n"; }
    }
}

$restored = true;
foreach ($snapshot as $n => $v) { if (pbt_setting($n) !== $v) $restored = false; }
t('every setting this test touched is back as it was', $restored);
if ($fixtureOrgId) {
    t('the fixture organization was removed', (int) db_fetch_value("SELECT COUNT(*) FROM " . db_table('organizations') . " WHERE `id` = ?", [$fixtureOrgId]) === 0);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
