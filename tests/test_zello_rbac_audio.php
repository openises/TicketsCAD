<?php
/**
 * GHSA-x9x6-w4fg-pmcc regression — Zello recordings no longer served
 * unauthenticated
 *
 * Reported by @rjonesbsink: cache/zello-audio/*.ogg was served as a static
 * file with no session, no RBAC check, and no audit entry, unlike the
 * equivalent DMR path (api/dmr-audio.php, gated on action.dmr_receive).
 * api/zello-messages.php had no permission check at all -- any authenticated
 * session, any role, could enumerate media_url for every retained recording.
 *
 * Three changes, verified here:
 *   1. action.zello_receive permission, seeded like action.dmr_receive
 *      (sql/run_zello_receive_rbac.php).
 *   2. api/zello-audio.php -- authenticated endpoint mirroring
 *      api/dmr-audio.php, looks the message up by id (not a client-supplied
 *      path), audits the play, streams the file.
 *   3. api/zello-messages.php now requires the same permission.
 *
 * Plus the storage relocation (inc/zello_audio_dir.php,
 * proxy/ZelloProxyApp.php) -- see tests/test_zello_infra_fixes.php for that
 * half, kept together with the systemd/schema assertions it already owns
 * rather than duplicated here.
 *
 * Does NOT exercise the HTTP layer (would need session+CSRF fixtures) --
 * the function-level and file-level contract is what's locked in here,
 * matching the established pattern in tests/test_dmr_rbac.php.
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/zello_audio_dir.php';

$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

function _t($pass, $fail, $label, $ok) {
    if ($ok) { echo "[PASS] {$label}\n"; return [$pass+1, $fail]; }
    echo "[FAIL] {$label}\n"; return [$pass, $fail+1];
}

echo "=== GHSA-x9x6-w4fg-pmcc — Zello recording auth ===\n";

// ── 1. Permission seeded ────────────────────────────────────────────────
$row = $pdo->prepare("SELECT id FROM `{$prefix}permissions` WHERE code = ?");
$row->execute(['action.zello_receive']);
$permId = (int) $row->fetchColumn();
[$pass, $fail] = _t($pass, $fail, 'permission exists: action.zello_receive', $permId > 0);

if ($permId > 0) {
    $rows = $pdo->prepare("SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id = ?");
    $rows->execute([$permId]);
    $actualRoles = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
    sort($actualRoles);
    $expectedRoles = [1, 2, 3, 4]; // Super Admin, Org Admin, Dispatcher, Operator
    [$pass, $fail] = _t($pass, $fail,
        'action.zello_receive granted to Super Admin/Org Admin/Dispatcher/Operator (matches action.dmr_receive)',
        empty(array_diff($expectedRoles, $actualRoles)));
    [$pass, $fail] = _t($pass, $fail,
        'action.zello_receive NOT granted to Read-Only or Field Unit by default',
        !in_array(5, $actualRoles, true) && !in_array(6, $actualRoles, true));
}

// ── 2. api/zello-audio.php ──────────────────────────────────────────────
$audioSrc = file_get_contents(__DIR__ . '/../api/zello-audio.php');
[$pass, $fail] = _t($pass, $fail, 'api/zello-audio.php exists', $audioSrc !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php gates on action.zello_receive',
    strpos((string) $audioSrc, "rbac_can('action.zello_receive')") !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php admin fallback preserved',
    strpos((string) $audioSrc, 'is_admin()') !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php looks the recording up by id, not a client-supplied path',
    strpos((string) $audioSrc, "(int) (\$_GET['id']") !== false
    && strpos((string) $audioSrc, "zello_messages` WHERE id = ?") !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php resolves the file via zello_audio_resolve() rather than trusting media_url as a path',
    strpos((string) $audioSrc, 'zello_audio_resolve(') !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php writes an audit entry for the playback',
    (bool) preg_match("/audit_log\\(\\s*'comms',\\s*'view',\\s*'zello_message'/s", (string) $audioSrc));
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php only serves message_type=voice rows',
    strpos((string) $audioSrc, "\$row['message_type'] !== 'voice'") !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-audio.php supports Range requests (HTML5 audio scrubbing)',
    strpos((string) $audioSrc, 'HTTP_RANGE') !== false && strpos((string) $audioSrc, 'Accept-Ranges') !== false);

// ── 3. api/zello-messages.php gated ─────────────────────────────────────
$msgsSrc = file_get_contents(__DIR__ . '/../api/zello-messages.php');
[$pass, $fail] = _t($pass, $fail,
    'zello-messages.php gates on action.zello_receive',
    strpos((string) $msgsSrc, "rbac_can('action.zello_receive')") !== false);
[$pass, $fail] = _t($pass, $fail,
    'zello-messages.php admin fallback preserved',
    strpos((string) $msgsSrc, 'is_admin()') !== false);

// ── 4. Frontend routes through the endpoint, not the raw path ──────────
$archiveSrc = file_get_contents(__DIR__ . '/../assets/js/zello-archive.js');
[$pass, $fail] = _t($pass, $fail,
    'zello-archive.js plays back via api/zello-audio.php?id=..., not media_url directly',
    strpos((string) $archiveSrc, "'api/zello-audio.php?id='") !== false
    && strpos((string) $archiveSrc, 'audio.src = m.media_url;') === false);

$widgetSrc = file_get_contents(__DIR__ . '/../assets/js/zello-widget.js');
[$pass, $fail] = _t($pass, $fail,
    'zello-widget.js plays back via api/zello-audio.php?id=..., not audio_url directly',
    strpos((string) $widgetSrc, "'api/zello-audio.php?id='") !== false);

// ── 5. zello_audio_dir() helpers — pure logic, exercised directly ──────
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_dir() is outside NEWUI_ROOT (not web-servable)',
    strpos(str_replace('\\', '/', zello_audio_dir()), str_replace('\\', '/', NEWUI_ROOT) . '/') !== 0);
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_dir_legacy() is the old in-tree cache path',
    rtrim(str_replace('\\', '/', zello_audio_dir_legacy()), '/')
        === rtrim(str_replace('\\', '/', NEWUI_ROOT) . '/cache/zello-audio', '/'));

$tmpPrivate = zello_audio_dir();
$tmpLegacy  = zello_audio_dir_legacy();
@mkdir($tmpPrivate, 0775, true);
@mkdir($tmpLegacy, 0775, true);
$privateOnly = 'ztest_private_only_' . getmypid() . '.ogg';
$legacyOnly  = 'ztest_legacy_only_'  . getmypid() . '.ogg';
file_put_contents($tmpPrivate . '/' . $privateOnly, 'x');
file_put_contents($tmpLegacy  . '/' . $legacyOnly,  'x');

[$pass, $fail] = _t($pass, $fail,
    'zello_audio_resolve() finds a bare filename in the private dir',
    zello_audio_resolve($privateOnly) === $tmpPrivate . '/' . $privateOnly);
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_resolve() falls back to the legacy dir for pre-fix recordings',
    zello_audio_resolve($legacyOnly) === $tmpLegacy . '/' . $legacyOnly);
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_resolve() accepts the OLD "cache/zello-audio/<file>" stored form too (pre-fix rows)',
    zello_audio_resolve('cache/zello-audio/' . $legacyOnly) === $tmpLegacy . '/' . $legacyOnly);
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_resolve() returns null for a file that exists in neither location',
    zello_audio_resolve('ztest_does_not_exist_' . getmypid() . '.ogg') === null);
[$pass, $fail] = _t($pass, $fail,
    'zello_audio_resolve() rejects path traversal (basename() strips directory components)',
    zello_audio_resolve('../../../../etc/passwd') === null);

@unlink($tmpPrivate . '/' . $privateOnly);
@unlink($tmpLegacy  . '/' . $legacyOnly);

// ── 6. Relocation migration only touches recordings ────────────────────
// A first version of sql/run_zello_audio_relocate.php moved every FILE it
// found in the legacy directory, which silently relocated
// cache/zello-audio/web.config (the defense-in-depth IIS deny) right along
// with the recordings the very first time the migration ran -- undoing the
// protection it exists to provide, on every install that upgrades. Caught
// by hand before this shipped; pinned here so it can't come back.
$relocateSrc = file_get_contents(__DIR__ . '/../sql/run_zello_audio_relocate.php');
[$pass, $fail] = _t($pass, $fail,
    'run_zello_audio_relocate.php filters by extension (ogg/webm only) before moving anything',
    (bool) preg_match("/pathinfo\\(\\\$name, PATHINFO_EXTENSION\\)[\\s\\S]{0,200}?in_array\\(\\\$ext, \\['ogg', 'webm'\\]/", (string) $relocateSrc));
[$pass, $fail] = _t($pass, $fail,
    'the extension filter runs BEFORE the move (continue, not just a comment)',
    (bool) preg_match("/in_array\\(\\\$ext, \\['ogg', 'webm'\\], true\\)\\) \\{\\s*continue;/", (string) $relocateSrc));

echo "\n=== TOTAL: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
