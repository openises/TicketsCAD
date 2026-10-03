<?php
/**
 * Phase 152 (Console rebuild) — the dispatcher-to-dispatcher voice channel
 * (`intercom_dd`, added 2026-09-06 per Eric's direct request — spec.md
 * user story #11).
 *
 * The catalog entry, the always-present `intercom_dd:main` channel row,
 * and BOTH leaf rules that refuse to ever patch/couple it (inc/matrix-
 * routes.php's persistent-route validator, and its ephemeral browser-leg
 * counterpart) were already built and tested in an earlier commit this
 * phase (tests/test_phase152_console_patch.php's own "G" case covers the
 * ephemeral leaf rule end to end over real HTTP). This file is the HOME
 * test for the feature as a whole: it re-confirms those two headline
 * claims directly (cheap, no HTTP/Python harness needed for either), and
 * covers what was actually still missing when this section of tasks.md
 * was opened —
 *
 *   1. `sql/run_phase152_intercom_dd.php` — the backfill migration that
 *      makes "one row exists on every install by default"
 *      (inc/channel_registry.php's own comment) true on an install that
 *      already ran the one-time channel-registry migration BEFORE
 *      intercom_dd existed in the catalog (channel_registry_sync() only
 *      ever creates a source's row once; adding a new source to the
 *      "want" list does nothing on its own for an already-migrated
 *      install).
 *   2. intercom_dd rides the SAME browser leg as DMR (console-mic.js's
 *      MATRIX_ADAPTERS), but with adapter-appropriate UI wording (no
 *      legacy widget to be mutually exclusive with, unlike DMR) — proven
 *      as source-level wiring guards, this feature's own established
 *      style for frontend coverage.
 *   3. Confirms `id_policy_by_class('internal')` already treats it as
 *      not-applicable with ZERO new code (Prerequisite 2's generic
 *      class-based design already covers this).
 *   4. Confirms the strip renders/functions independent of whether any
 *      console_positions row exists (structural: console.js's intercom_dd
 *      branch never references anything from the position layer).
 *
 * A FIFTH, more serious gap was found while investigating the workstation-
 * mute feature (the next section of tasks.md) and required a real Python-
 * side fix in the SAME area: intercom_dd:main, as shipped, had NO leg at
 * all (every comm_channels row loads with leg=None by default unless
 * something attaches one — DMR does, via config; nothing did for
 * intercom_dd), so under matrix_core.py's own documented single-hop model
 * ("does NOT re-inject into D's inbound") a talk route INTO the hub was
 * silently discarded and a listen route OUT of it was permanently silent —
 * the party line could never actually carry audio between two dispatchers.
 * Fixed with a new services/audio-matrix/legs/reflector.py (ReflectorLeg),
 * attached automatically for the intercom_dd adapter in service.py's
 * load_channels(). The full behavioral proof (reproducing the bug against
 * a bare leg=None channel, then proving the fix carries audio to two AND
 * three simultaneous listeners) lives in the Python suite, where it
 * belongs — services/audio-matrix/tests/test_intercom_reflector.py (6
 * assertions) and 3 new assertions in tests/test_service.py proving
 * load_channels() attaches the leg for intercom_dd and ONLY for
 * intercom_dd. This PHP file only confirms the fix's source files exist
 * and are wired, below.
 *
 * Usage: php tests/test_phase152_dispatcher_intercom.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/channel_registry.php';
require_once __DIR__ . '/../inc/id-policy.php';
require_once __DIR__ . '/../inc/matrix-routes.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- the dispatcher-to-dispatcher voice channel (intercom_dd) ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "--- 1. Catalog + registry-source shape ---\n\n";
$catalog = channel_adapter_catalog();
t('channel_adapter_catalog() has an intercom_dd entry', isset($catalog['intercom_dd']));
t('intercom_dd is regulatory_class = internal', ($catalog['intercom_dd']['regulatory_class'] ?? null) === 'internal');
t('intercom_dd carries voice capabilities', !empty($catalog['intercom_dd']['capabilities']['voice_tx'])
    && !empty($catalog['intercom_dd']['capabilities']['voice_rx']));

$sources = channel_registry_sources();
t('channel_registry_sources() wants an intercom_dd:main row, always enabled', isset($sources['intercom_dd:main'])
    && $sources['intercom_dd:main']['adapter'] === 'intercom_dd'
    && (int) $sources['intercom_dd:main']['enabled'] === 1);

echo "\n--- 2. id_policy_by_class('internal') already treats this as not-applicable ---\n\n";
$policy = id_policy_by_class('internal');
t('no station-ID requirement for internal-class channels (Prerequisite 2 already covers this -- zero new code needed)',
    $policy['requires_id'] === false && $policy['interval_ceiling_secs'] === null);

echo "\n--- 3. sql/run_phase152_intercom_dd.php: backfills the row on an already-migrated install ---\n\n";
$phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : '/c/xampp/8.2.4/php/php.exe';
$scriptPath = __DIR__ . '/../sql/run_phase152_intercom_dd.php';

$before = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", ['intercom_dd:main']);

$out1 = []; $rc1 = 1;
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out1, $rc1);
t('first run exits 0', $rc1 === 0);
$row = db_fetch_one(
    "SELECT id, adapter, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE channel_key = ?",
    ['intercom_dd:main']
);
t('intercom_dd:main now exists with the right shape', $row
    && $row['adapter'] === 'intercom_dd' && $row['regulatory_class'] === 'internal' && (int) $row['enabled'] === 1);

$out2 = []; $rc2 = 1;
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' 2>&1', $out2, $rc2);
$rowAfterSecondRun = db_fetch_one("SELECT id FROM `{$prefix}comm_channels` WHERE channel_key = ?", ['intercom_dd:main']);
t('a second run exits 0 and is a clean no-op (same row id, not a duplicate)', $rc2 === 0
    && $rowAfterSecondRun && $row && (int) $rowAfterSecondRun['id'] === (int) $row['id']);
t('running the script does not create a second comm_channels row for this channel_key',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}comm_channels` WHERE channel_key = ?", ['intercom_dd:main']) === 1);

echo "\n--- 4. Both leaf rules refuse to ever patch or couple intercom_dd ---\n\n";
$intercomChannelId = (int) $row['id'];

$suffix = uniqid();
db_query(
    "INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order)
     VALUES (?, 'test', 'ZZ152DI Other Channel', 'internal', 1, 0, 999)",
    ["zz152di_other_$suffix"]
);
$otherChannelId = (int) db_insert_id();

try {
    $threw = false; $msg = '';
    try {
        matrix_route_validate($intercomChannelId, $otherChannelId, false);
    } catch (Exception $e) { $threw = true; $msg = $e->getMessage(); }
    t('the PERSISTENT-route leaf rule (matrix_route_validate) refuses intercom_dd as a source',
        $threw && stripos($msg, 'party line') !== false);

    $threw = false; $msg = '';
    try {
        matrix_route_validate($otherChannelId, $intercomChannelId, false);
    } catch (Exception $e) { $threw = true; $msg = $e->getMessage(); }
    t('...and refuses it as a destination too (the check is symmetric)',
        $threw && stripos($msg, 'party line') !== false);

    $threw = false; $msg = '';
    try {
        matrix_browser_leg_validate_channel($intercomChannelId);
    } catch (Exception $e) { $threw = true; $msg = $e->getMessage(); }
    t('the EPHEMERAL browser-leg leaf rule (matrix_browser_leg_validate_channel) ALSO refuses intercom_dd',
        $threw && stripos($msg, 'intercom') !== false);
} finally {
    try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$otherChannelId]); } catch (Throwable $e) {}
}

echo "\n--- 5. Frontend wiring: rides the browser leg like any other channel ---\n\n";
$mjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-mic.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $mjs));
// Phase 155: the map gained dvmproject/usrp_bridge, so it is no longer one fixed
// line; the intent (intercom_dd rides the browser leg like DMR) is unchanged.
preg_match('/var MATRIX_ADAPTERS = \{([^}]*)\}/s', $mjs, $micMapM);
t('console-mic.js treats intercom_dd as matrix-backed, riding the same browser leg as DMR — no new transport',
    isset($micMapM[1]) && preg_match('/dmr_bm\s*:\s*true/', $micMapM[1]) && preg_match('/dmr_local\s*:\s*true/', $micMapM[1])
    && preg_match('/intercom_dd\s*:\s*true/', $micMapM[1]));

$ajs = (string) @file_get_contents(__DIR__ . '/../assets/js/console-audio.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $ajs));
t('console-audio.js treats intercom_dd as matrix-capable for gain application (not just DMR)',
    strpos($ajs, "var isMatrixCapable = isRadio || meta.adapter === 'intercom_dd'") !== false);

$cjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $cjs));
t('console.js relabels the toggle "Join Intercom" for this adapter, since there is no legacy widget to replace',
    strpos($cjs, "isIntercomDd = (ch.adapter === 'intercom_dd')") !== false
    && strpos($cjs, "isIntercomDd ? 'Join Intercom' : (listenOnlyMatrix ? 'Listen' : 'Matrix Audio')") !== false);
t('console.js gives intercom_dd its own honest "no legacy widget" note instead of the generic Phase 114c placeholder',
    strpos($cjs, "'Turn on Join Intercom below to talk on this channel'") !== false);

echo "\n--- 6. Works without positions configured -- no dependency on the position layer ---\n\n";
t('console.js has zero references to the position layer (console-positions.js exposes no global at all -- '
    . 'the intercom strip renders/functions purely from channel capabilities, same as any other voice channel)',
    strpos($cjs, 'ConsolePositions') === false && strpos($cjs, 'consolePositionBar') === false);
t('the position layer, in turn, has no reference to intercom_dd -- the two features compose without coupling',
    strpos((string) @file_get_contents(__DIR__ . '/../assets/js/console-positions.js'), 'intercom') === false);

echo "\n--- 7. The reflector fix: intercom_dd can now actually carry audio (full proof lives in Python) ---\n\n";
t('services/audio-matrix/legs/reflector.py exists',
    file_exists(__DIR__ . '/../services/audio-matrix/legs/reflector.py'));
$svcPy = (string) @file_get_contents(__DIR__ . '/../services/audio-matrix/service.py');
t('service.py imports ReflectorLeg', strpos($svcPy, 'from reflector import ReflectorLeg') !== false);
t('load_channels() attaches it specifically (and only) for the intercom_dd adapter',
    strpos($svcPy, 'if row["adapter"] == "intercom_dd":') !== false
    && strpos($svcPy, 'core.channel(key).leg = ReflectorLeg(core, key)') !== false);
t('services/audio-matrix/tests/test_intercom_reflector.py exists (the real behavioral proof: reproduces the '
    . 'bug against a bare leg=None channel, then proves 2-way and 3-way audio delivery through the fix)',
    file_exists(__DIR__ . '/../services/audio-matrix/tests/test_intercom_reflector.py'));
t('the new Python test is actually wired into CI -- not just present on disk (this project\'s own repeated '
    . '"shipped but nobody wired the last mile" failure class)',
    strpos((string) @file_get_contents(__DIR__ . '/../.github/workflows/qa.yml'), 'test_intercom_reflector') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
