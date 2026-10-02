<?php
/**
 * Phase 152 (Console rebuild) — the Recall tab. Source-level wiring
 * guards, in the style this feature's other test files already
 * established — the PHP-side fix (channel_feed()'s dmr_local gap) is
 * driven directly against the real function; the JS side (assets/js/
 * console-recall.js, console.js's per-strip replay shortcut, console-
 * designer.js's patchchips/recall no-longer-future flip, console.php's
 * toggle+panel+script wiring) is checked as source-level assertions,
 * since a full live-HTTP recall round-trip needs real dmr_messages/
 * zello_messages/chat_messages history rows across several channel
 * types — heavier fixture setup than this feature's own "genuinely
 * secondary/lightweight" framing (plan.md) warrants for its test.
 *
 * Usage: php tests/test_phase152_recall_tab.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';
require_once 'inc/channel_registry.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 -- the Recall tab ===\n\n";

echo "1. api/channels.php's channel_feed(): the dmr_local gap, driven against the REAL function\n";
// CHANNELS_API_LIBRARY_ONLY (mirrors OT_CONFIG_LIBRARY_ONLY, api/owntracks-
// config.php) — skips auth.php's session/RBAC machinery and the HTTP
// dispatch entirely, leaving channel_feed() defined and callable.
define('CHANNELS_API_LIBRARY_ONLY', true);
require_once 'api/channels.php';
$createdChannelIds = [];
$createdMessageIds = [];
register_shutdown_function(function () use (&$createdChannelIds, &$createdMessageIds, $prefix) {
    foreach ($createdMessageIds as $id) { try { db_query("DELETE FROM `{$prefix}dmr_messages` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM `{$prefix}dmr_channels` WHERE id = ?", [$id]); } catch (Exception $e) {} }
});

$s = uniqid();
db_query(
    "INSERT INTO `{$prefix}dmr_channels`
        (label, talkgroup, bridge_host, bridge_token, usrp_listen_port, usrp_send_port, enabled)
     VALUES (?, '99999', 'zz152.invalid', 'zz152-fixture-token', 34001, 34002, 1)",
    ["ZZ152 Recall Test DMR $s"]
);
$dmrChannelId = (int) db_insert_id();
$createdChannelIds[] = $dmrChannelId;
db_query(
    "INSERT INTO `{$prefix}dmr_messages` (channel_id, direction, call_started_at, radio_callsign, transcript)
     VALUES (?, 'rx', NOW(), 'ZZ152TEST', 'test transcript for recall')",
    [$dmrChannelId]
);
$createdMessageIds[] = (int) db_insert_id();

$fakeChannelBm = ['channel_key' => 'dmr_bm:zz152test', 'adapter' => 'dmr_bm', 'config' => ['dmr_channel_id' => $dmrChannelId]];
$fakeChannelLocal = ['channel_key' => 'dmr_local:zz152test', 'adapter' => 'dmr_local', 'config' => ['dmr_channel_id' => $dmrChannelId]];
$feedBm = channel_feed($fakeChannelBm, 10);
$feedLocal = channel_feed($fakeChannelLocal, 10);
t('channel_feed() returns the transcript for a dmr_bm channel (pre-existing behavior, unaffected)',
    !empty($feedBm) && strpos($feedBm[0]['body'], 'test transcript for recall') !== false);
t('channel_feed() ALSO returns it for a dmr_local channel with the identical config shape -- '
    . 'this was the gap: dmr_local fell through to no branch at all and got an empty feed silently',
    !empty($feedLocal) && strpos($feedLocal[0]['body'], 'test transcript for recall') !== false);
t('both adapters return the byte-identical item shape (who/body/dir) -- proving this is a '
    . 'genuine condition fix, not two diverging code paths',
    $feedBm[0]['who'] === $feedLocal[0]['who'] && $feedBm[0]['body'] === $feedLocal[0]['body']);

echo "\n2. assets/js/console-recall.js: reuses channel_feed() via the existing ?feed= endpoint\n";
$recall = (string) @file_get_contents('assets/js/console-recall.js');
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $recall));
t('fetches the SAME api/channels.php?feed= endpoint every strip\'s live text drawer already uses -- '
    . 'one consistent screen.console-gated code path, not a second parallel one',
    strpos($recall, "CH_API + '?feed='") !== false);
t('missed calls (api/inbound-calls.php, install-wide, no per-channel equivalent) are still their OWN '
    . 'separate fetch, exactly as plan.md described for the one source with nothing to reuse',
    strpos($recall, "MISSED_CALLS_API = 'api/inbound-calls.php?action=list_missed'") !== false);
t('groups are sorted by MOST RECENT activity first, not insertion order', strpos($recall, 'groups.sort(function') !== false);
t('within a group, items render chronological (persona review: "chronological within group")',
    strpos($recall, 'ordered = g.items.slice().reverse()') !== false);
t('a count badge is rendered per group (persona review: "grouped by channel/mode with counts")',
    strpos($recall, "'badge text-bg-secondary ms-1', String(g.items.length)") !== false);
t('the panel fetches ONLY when opened, never eagerly on page load (plan.md: "genuinely secondary/lightweight")',
    strpos($recall, 'if (open && !loading) { loadRecall(); }') !== false
    && strpos($recall, 'window.onload') === false);
t('replay audio fetches the RICHER per-adapter endpoint (audio_path/media_url) only on demand -- '
    . 'channel_feed() itself never carries playable audio, by design, to keep the default list light',
    strpos($recall, "DMR_HISTORY_API = 'api/dmr-history.php'") !== false
    && strpos($recall, "ZELLO_HISTORY_API = 'api/zello-messages.php'") !== false
    && strpos($recall, 'row.audio_path') !== false && strpos($recall, 'row.media_url') !== false);
t('exposes replayLatestForChannel() for console.js\'s own per-strip shortcut, reusing the SAME playLatest() logic '
    . 'the Recall tab\'s own per-group Replay button uses -- one implementation, two call sites',
    strpos($recall, 'window.ConsoleRecall = {') !== false
    && strpos($recall, 'replayLatestForChannel: function (ch)') !== false);

echo "\n3. console.php: the toggle button + panel + script tag are wired\n";
$page = (string) @file_get_contents('console.php');
t('a consoleRecallToggle button and a consoleRecallPanel container both exist',
    strpos($page, 'id="consoleRecallToggle"') !== false && strpos($page, 'id="consoleRecallPanel"') !== false);
t('the panel starts hidden (d-none) -- never shown until the operator opens it',
    preg_match('/id="consoleRecallPanel"[^>]*class="[^"]*d-none/', $page)
    || preg_match('/class="[^"]*d-none[^"]*"[^>]*id="consoleRecallPanel"/', $page));
t('console-recall.js loads before console.js (which calls window.ConsoleRecall at click time)',
    strpos($page, 'console-recall.js') !== false
    && strpos($page, 'console-recall.js') < strrpos($page, 'assets/js/console.js'));

echo "\n4. console.js: the per-strip replay shortcut is opt-in via show.recall, voice-capable adapters only\n";
$cjs = (string) @file_get_contents('assets/js/console.js');
t('gated on show.recall (the strip-template flag reserved for this exact feature)', strpos($cjs, 'if (show.recall && (') !== false);
t('only zello/dmr_bm/dmr_local -- text-only channels already show history via their own Feed drawer',
    strpos($cjs, "show.recall && (ch.adapter === 'zello' || ch.adapter === 'dmr_bm' || ch.adapter === 'dmr_local')") !== false);
t('calls the SAME window.ConsoleRecall.replayLatestForChannel() the module exposes, never re-implements the fetch',
    strpos($cjs, 'window.ConsoleRecall.replayLatestForChannel(ch)') !== false);

echo "\n5. console-designer.js: patchchips AND recall are both real now\n";
$djs = (string) @file_get_contents('assets/js/console-designer.js');
t('FUTURE_FLAGS contains ONLY vu -- patchchips (previous commit) and recall (this one) both shipped',
    strpos($djs, 'var FUTURE_FLAGS = { vu: true };') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
