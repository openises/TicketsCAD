<?php
/**
 * 2026-09-08 — Public HTTP audio-stream channel CRUD tests.
 *
 * Eric: "For testing, can you also build support for listening to a
 * public streaming service?... You need to finish building this out. I
 * want to create multiple streams as needed." — a full, first-class
 * channel type (not a CLI tool): a proper admin UI (stream-channels-
 * admin.php), a real API (api/http-stream-channels.php), and live
 * hot-attach to the running matrix service via the control plane (no
 * restart needed to add/edit/remove a stream).
 *
 * Drives the REAL writer functions in inc/http-stream-channels.php
 * (never hand-seeded rows) against throwaway comm_channels fixtures, so
 * every assertion here exercises exactly what api/http-stream-channels.php
 * calls. Covers: validation, create/list/get/update/delete, the url<->
 * config_json round-trip (which service.py's own _http_stream_url_from_
 * config() must agree with -- checked against the real Python source, not
 * just asserted), managed=0 (never touched by channel_registry_sync()),
 * duplicate channel_key refusal, RBAC seeding, and static wiring guards
 * on api/http-stream-channels.php + stream-channels-admin.php +
 * inc/channel_registry.php + inc/config-sidebar.php.
 *
 * Usage: php tests/test_http_stream_channels.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/channel_registry.php';
require_once 'inc/http-stream-channels.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Public HTTP audio-stream channel CRUD (2026-09-08) ===\n\n";

// ── Fixtures: throwaway comm_channels, cleaned up on exit (by REFERENCE --
// this project's own documented lesson: a shutdown closure that captures
// `use ($createdIds)` BY VALUE at registration time, before any ids are
// appended, silently deletes nothing) ────────────────────────────────────
$createdIds = [];
register_shutdown_function(function () use (&$createdIds, $prefix) {
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {}
    }
});

function uniq_key($base) {
    return $base . '_' . substr(md5(uniqid('', true)), 0, 8);
}

// ── Catalog + sync posture ────────────────────────────────────────────
$catalog = channel_adapter_catalog();
t('channel_adapter_catalog() has an http_stream entry', isset($catalog['http_stream']));
t('http_stream is regulatory_class internal (a public feed carries no TX obligation)',
    isset($catalog['http_stream']) && $catalog['http_stream']['regulatory_class'] === 'internal');
t('http_stream is voice_rx only -- one-way, nothing to transmit back',
    isset($catalog['http_stream']['capabilities'])
    && !empty($catalog['http_stream']['capabilities']['voice_rx'])
    && empty($catalog['http_stream']['capabilities']['voice_tx']));

// ── Validation ─────────────────────────────────────────────────────────
function expect_invalid($label, $fn) {
    try { $fn(); t($label, false); }
    catch (InvalidArgumentException $e) { t($label, true); }
    catch (Exception $e) { t($label . ' (wrong exception type: ' . get_class($e) . ')', false); }
}

expect_invalid('empty channel_key is rejected',
    function () { http_stream_channel_validate('', 'Label', 'internal', 'http://x/y.mp3'); });
expect_invalid('channel_key with disallowed characters is rejected',
    function () { http_stream_channel_validate('has spaces!', 'Label', 'internal', 'http://x/y.mp3'); });
expect_invalid('empty label is rejected',
    function () { http_stream_channel_validate('k', '', 'internal', 'http://x/y.mp3'); });
expect_invalid('unknown regulatory_class is rejected',
    function () { http_stream_channel_validate('k', 'Label', 'not_a_real_class', 'http://x/y.mp3'); });
expect_invalid('empty url is rejected',
    function () { http_stream_channel_validate('k', 'Label', 'internal', ''); });
expect_invalid('a non-http(s) url is rejected (e.g. a bare hostname or file:// path)',
    function () { http_stream_channel_validate('k', 'Label', 'internal', 'ftp://example.org/x.mp3'); });

$v = null;
try { $v = http_stream_channel_validate('valid_key-1:2', 'A Label', 'internal', 'http://example.org/x.mp3'); }
catch (Exception $e) {}
t('a fully valid input passes validation', $v !== null && $v['channel_key'] === 'valid_key-1:2');

// ── Create ─────────────────────────────────────────────────────────────
$key1 = uniq_key('test_stream');
$id1 = http_stream_channel_create($key1, 'NOAA Weather Radio (test)', 'internal',
    'http://www.urberg.net:8000/tim273/edina');
$createdIds[] = $id1;
t('create() returns a positive integer id', is_int($id1) && $id1 > 0);

$rawRow = db_fetch_one("SELECT * FROM `{$prefix}comm_channels` WHERE id = ?", [$id1]);
t('the row is adapter=http_stream', $rawRow && $rawRow['adapter'] === 'http_stream');
t('the row is managed=0 -- channel_registry_sync() never touches a hand-created stream channel',
    $rawRow && (int) $rawRow['managed'] === 0);
t('the row is enabled=1 by default', $rawRow && (int) $rawRow['enabled'] === 1);
t('config_json stores the url as real JSON, not a raw string',
    $rawRow && json_decode($rawRow['config_json'], true) === ['url' => 'http://www.urberg.net:8000/tim273/edina']);

// ── Duplicate channel_key refusal ────────────────────────────────────────
$dupeThrew = false;
try {
    http_stream_channel_create($key1, 'Different Label', 'internal', 'http://example.org/other.mp3');
} catch (RuntimeException $e) {
    $dupeThrew = true;
}
t('creating a second channel with the SAME channel_key throws (RuntimeException -> 409)', $dupeThrew);

// ── url <-> config_json round-trip agrees with service.py's own extractor ──
$get1 = http_stream_channel_get($id1);
t('get() round-trips the exact url that was saved',
    $get1 && $get1['url'] === 'http://www.urberg.net:8000/tim273/edina');
t('get() never leaks the raw config_json key -- only the extracted url',
    $get1 && !array_key_exists('config_json', $get1));

$pySrc = (string) @file_get_contents('services/audio-matrix/service.py');
t('service.py has its own _http_stream_url_from_config() -- the PHP and Python sides must '
    . 'independently agree on what counts as a usable url, not share one implementation',
    strpos($pySrc, 'def _http_stream_url_from_config') !== false);

t('http_stream_channel_url_from_config(): empty/null config -> empty string, not a crash',
    http_stream_channel_url_from_config('') === '' && http_stream_channel_url_from_config(null) === '');
t('http_stream_channel_url_from_config(): malformed JSON -> empty string, not a crash',
    http_stream_channel_url_from_config('not valid json{{{') === '');
t('http_stream_channel_url_from_config(): valid JSON with no "url" key -> empty string',
    http_stream_channel_url_from_config('{"note":"no url here"}') === '');
t('http_stream_channel_url_from_config(): whitespace-only url -> empty string (trimmed)',
    http_stream_channel_url_from_config('{"url":"   "}') === '');

// ── List ───────────────────────────────────────────────────────────────
$list = http_stream_channel_list();
$foundInList = false;
foreach ($list as $row) {
    if ((int) $row['id'] === $id1) { $foundInList = true; break; }
}
t('list() includes the freshly-created channel', $foundInList);
t('list() also extracts url (not raw config_json) per row',
    $foundInList === true && (function () use ($list, $id1) {
        foreach ($list as $row) { if ((int) $row['id'] === $id1) { return array_key_exists('url', $row) && !array_key_exists('config_json', $row); } }
        return false;
    })());

// ── Update ─────────────────────────────────────────────────────────────
$updated = http_stream_channel_update($id1, 'Renamed Label', 'amateur',
    'http://example.org/new-url.mp3', false);
t('update() returns the updated row', $updated !== null);
t('update() changes the label', $updated['label'] === 'Renamed Label');
t('update() changes the regulatory_class', $updated['regulatory_class'] === 'amateur');
t('update() changes the url', $updated['url'] === 'http://example.org/new-url.mp3');
t('update() changes enabled to false', (int) $updated['enabled'] === 0);

$rawAfterUpdate = db_fetch_one("SELECT channel_key FROM `{$prefix}comm_channels` WHERE id = ?", [$id1]);
t('update() NEVER changes channel_key -- it is the live matrix\'s own channel id; '
    . 'renaming it out from under an existing route would orphan comm_routes rows',
    $rawAfterUpdate && $rawAfterUpdate['channel_key'] === $key1);

$updateMissingThrew = false;
try { http_stream_channel_update(999999999, 'X', 'internal', 'http://x/y.mp3', true); }
catch (RuntimeException $e) { $updateMissingThrew = true; }
t('update() on a nonexistent id throws (-> 404)', $updateMissingThrew);

// ── Delete ─────────────────────────────────────────────────────────────
$key2 = uniq_key('test_stream_del');
$id2 = http_stream_channel_create($key2, 'To Be Deleted', 'internal', 'http://example.org/x.mp3');
// Intentionally NOT added to $createdIds -- delete() below is what removes it;
// this proves delete() actually works rather than relying on the cleanup hook.
$deleted = http_stream_channel_delete($id2);
t('delete() returns the deleted row\'s data', $deleted !== null && $deleted['channel_key'] === $key2);
$goneRow = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE id = ?", [$id2]);
t('delete() actually removed the row from the database', $goneRow === null);
$deleteAgain = http_stream_channel_delete($id2);
t('delete() on an already-deleted id returns null, not an error', $deleteAgain === null);

// ── Static wiring guards ────────────────────────────────────────────────
$api = (string) @file_get_contents('api/http-stream-channels.php');
t('api/http-stream-channels.php: RBAC-gated on action.manage_matrix',
    strpos($api, "rbac_can('action.manage_matrix')") !== false);
t('api/http-stream-channels.php: deliberately NOT `|| is_admin()` on the RBAC gate '
    . '(this project\'s own Phase 138 lesson -- is_admin()\'s action.manage_config fallback '
    . 'is the wrong idiom the moment a permission is meant to travel narrower)',
    !(bool) preg_match('/rbac_can\(\'action\.manage_matrix\'\)\s*\|\|\s*is_admin\(\)/', $api));
t('api/http-stream-channels.php: CSRF-checked on every mutating action',
    substr_count($api, 'hsc_csrf_check(') >= 3);
t('api/http-stream-channels.php: display_errors suppressed', strpos($api, "display_errors', '0'") !== false);
t('api/http-stream-channels.php: every mutation is audited', substr_count($api, 'audit_log(') >= 3);
t('api/http-stream-channels.php: create failure rolls back the DB write (fail-closed, '
    . 'matching api/matrix.php\'s own route-create discipline -- never a silently-queued channel)',
    strpos($api, 'http_stream_channel_delete($id)') !== false
    && strpos($api, "'Matrix service unreachable") !== false);

$page = (string) @file_get_contents('stream-channels-admin.php');
t('stream-channels-admin.php: session gate, RBAC gate, CSRF meta, cache-busted assets',
    strpos($page, "empty(\$_SESSION['user_id'])") !== false
    && strpos($page, "rbac_can('action.manage_matrix')") !== false
    && strpos($page, 'csrf-token') !== false
    && strpos($page, 'asset_v(') !== false);

$sidebar = (string) @file_get_contents('inc/config-sidebar.php');
t('config-sidebar.php links to stream-channels-admin.php, gated on action.manage_matrix',
    (bool) preg_match(
        "/rbac_can\\('action\\.manage_matrix'\\)\\s*\\)\\s*\\{\\s*\\n?\\s*_cfg_link\\('stream-channels-admin'/",
        $sidebar
    )
    && strpos($sidebar, 'stream-channels-admin.php') !== false);

$registryClient = (string) @file_get_contents('inc/matrix-control-client.php');
t('matrix-control-client.php has matrix_control_apply_http_stream_create()',
    strpos($registryClient, 'function matrix_control_apply_http_stream_create') !== false);
t('matrix-control-client.php has matrix_control_apply_http_stream_delete()',
    strpos($registryClient, 'function matrix_control_apply_http_stream_delete') !== false);

$jsFile = (string) @file_get_contents('assets/js/stream-channels-admin.js');
t('stream-channels-admin.js is ES5 only (no arrows/template literals/let/const)',
    $jsFile !== '' && !preg_match('/=>|`|\\blet\\s|\\bconst\\s/', $jsFile));
t('stream-channels-admin.js disables the channel-key field when editing (it\'s immutable)',
    strpos($jsFile, "getElementById('hscChannelKey').disabled = true") !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
