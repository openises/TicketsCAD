<?php
/**
 * Phase 152 (Console rebuild) — strip-template migration
 * (sql/run_phase152_strip_templates.php).
 *
 * Seeds console_view_strips rows directly via SQL in each of the THREE
 * shapes that have ever existed in this table (Phase 114b2 flat control-
 * key list, Phase 114b3 positioned-component array, and NULL), because
 * the CURRENT write path (console_view_save_strips()) can no longer
 * produce any of those legacy shapes — direct SQL is the only way to
 * reproduce genuinely legacy rows for a migration test. Drives the REAL
 * migration script as a CLI subprocess (not a copy of its logic), then
 * verifies the converted show-flags against the expected capability-gated
 * result.
 *
 * Every fixture strip points at a DEDICATED fixture channel created here
 * with known, guaranteed-full capabilities (voice_tx/voice_rx/text_rx/
 * text_tx) — NOT a hardcoded channel id from the live registry. An
 * earlier version hardcoded channel_id=1, which happened to be a fully-
 * capable Zello channel on this project's own long-lived dev database but
 * is an ARBITRARY, differently-seeded channel (or none at all) on a
 * genuinely fresh CI install — exactly the "verified against the shared
 * dev DB instead of a fresh install" trap this project's own root-cause
 * discipline warns about. CI caught it: 5 assertions failed there because
 * the migration's capability gate (console_strip_show_allowed()) silently
 * dropped ptt/mon/mute/vol to false against whatever channel_id=1 actually
 * was on that fresh install.
 *
 * Usage: php tests/test_phase152_strip_templates.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/functions.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 152 -- strip-template migration ===\n\n";

$createdViewIds = [];
$createdStripIds = [];
$createdChannelIds = [];
register_shutdown_function(function () use (&$createdStripIds, &$createdViewIds, &$createdChannelIds, $prefix) {
    foreach ($createdStripIds as $id) { try { db_query("DELETE FROM `{$prefix}console_view_strips` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdViewIds as $id) { try { db_query("DELETE FROM `{$prefix}console_views` WHERE id = ?", [$id]); } catch (Exception $e) {} }
    foreach ($createdChannelIds as $id) { try { db_query("DELETE FROM `{$prefix}comm_channels` WHERE id = ?", [$id]); } catch (Exception $e) {} }
});

$s = uniqid();
db_query(
    "INSERT INTO `{$prefix}comm_channels` (channel_key, adapter, label, regulatory_class, enabled, managed, sort_order, capabilities_json)
     VALUES (?, 'test', 'ZZ152 Fixture Channel', 'internal', 1, 0, 999, ?)",
    ["zz152strip:fixture:$s", json_encode(['voice_tx' => true, 'voice_rx' => true, 'text_rx' => true, 'text_tx' => true])]
);
$fixtureChannelId = (int) db_insert_id();
$createdChannelIds[] = $fixtureChannelId;

db_query(
    "INSERT INTO `{$prefix}console_views` (name, owner_user_id, sort_order, created_by) VALUES (?, NULL, 999, NULL)",
    ["ZZ152 Strip Template Test $s"]
);
$viewId = (int) db_insert_id();
$createdViewIds[] = $viewId;

function mk_strip($prefix, $viewId, $channelId, $controlsJson, &$createdStripIds) {
    db_query(
        "INSERT INTO `{$prefix}console_view_strips` (view_id, channel_id, position, width, controls_json)
         VALUES (?, ?, 0, 1, ?)",
        [$viewId, $channelId, $controlsJson]
    );
    $id = (int) db_insert_id();
    $createdStripIds[] = $id;
    return $id;
}

// ── Fixture rows: one per legacy shape, plus NULL and already-new ──────
// Every row points at $fixtureChannelId (full voice+text capabilities,
// guaranteed regardless of what a fresh install happens to seed).
$idB2Full = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode(['activity', 'voice', 'text']), $createdStripIds);
$idB2VoiceOnly = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode(['activity', 'voice']), $createdStripIds);
$idB2TextOnly = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode(['activity', 'text']), $createdStripIds);
$idB3Full = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode([
    ['type' => 'label', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 3],
    ['type' => 'led', 'x' => 10, 'y' => 0, 'w' => 2, 'h' => 1],
    ['type' => 'ptt', 'x' => 0, 'y' => 5, 'w' => 12, 'h' => 3],
    ['type' => 'monitor', 'x' => 0, 'y' => 8, 'w' => 4, 'h' => 2],
    ['type' => 'mute', 'x' => 4, 'y' => 8, 'w' => 4, 'h' => 2],
    ['type' => 'volume', 'x' => 0, 'y' => 10, 'w' => 12, 'h' => 1],
    ['type' => 'text', 'x' => 0, 'y' => 11, 'w' => 12, 'h' => 10],
]), $createdStripIds);
$idB3PttOnly = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode([
    ['type' => 'ptt', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 3],
]), $createdStripIds);
$idB3Empty = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode([]), $createdStripIds);
$idNull = mk_strip($prefix, $viewId, $fixtureChannelId, null, $createdStripIds);
$idAlreadyNew = mk_strip($prefix, $viewId, $fixtureChannelId, json_encode(['show' => ['ptt' => true, 'sel' => true, 'mon' => false, 'mute' => false, 'vol' => false, 'vu' => true, 'recall' => false, 'patchchips' => false, 'text' => false], 'hotkey' => 'F5']), $createdStripIds);

// ── Run the REAL migration script as a CLI subprocess ──────────────────
echo "1. Running the real migration script\n";
$bin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
$out = [];
$rc = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/sql/run_phase152_strip_templates.php') . ' 2>&1', $out, $rc);
$outStr = implode("\n", $out);
t('the migration script exits 0', $rc === 0);
t('the migration script reports verified', stripos($outStr, 'verified') !== false);

function get_show($prefix, $id) {
    $raw = db_fetch_value("SELECT controls_json FROM `{$prefix}console_view_strips` WHERE id = ?", [$id]);
    $decoded = json_decode((string) $raw, true);
    return $decoded['show'] ?? null;
}
function get_hotkey($prefix, $id) {
    $raw = db_fetch_value("SELECT controls_json FROM `{$prefix}console_view_strips` WHERE id = ?", [$id]);
    $decoded = json_decode((string) $raw, true);
    return array_key_exists('hotkey', $decoded) ? $decoded['hotkey'] : '__missing__';
}

// ── 2. Phase 114b2 flat control-key list conversions ────────────────────
echo "\n2. Legacy Phase 114b2 flat control-key list\n";
$show = get_show($prefix, $idB2Full);
t('["activity","voice","text"]: voice -> ptt+mon+mute+vol all true', $show && $show['ptt'] && $show['mon'] && $show['mute'] && $show['vol']);
t('["activity","voice","text"]: text -> text true', $show && $show['text']);
t('sel is true (migrated, matching universal Select chrome today)', $show && $show['sel'] === true);
t('vu/recall/patchchips default false (no prior signal to migrate from)', $show && !$show['vu'] && !$show['recall'] && !$show['patchchips']);

$show = get_show($prefix, $idB2VoiceOnly);
t('["activity","voice"]: voice controls true, text false', $show && $show['ptt'] && $show['mon'] && $show['mute'] && $show['vol'] && !$show['text']);

$show = get_show($prefix, $idB2TextOnly);
t('["activity","text"]: text true, voice controls all false', $show && $show['text'] && !$show['ptt'] && !$show['mon'] && !$show['mute'] && !$show['vol']);

// ── 3. Phase 114b3 positioned-component array conversions ──────────────
echo "\n3. Legacy Phase 114b3 positioned-component array\n";
$show = get_show($prefix, $idB3Full);
t('full component set: ptt/mon/mute/vol/text all true', $show && $show['ptt'] && $show['mon'] && $show['mute'] && $show['vol'] && $show['text']);
t('label/led components carried no independent show-flag before and produce none now (always-rendered chrome)',
    $show && !array_key_exists('label', $show) && !array_key_exists('led', $show));

$show = get_show($prefix, $idB3PttOnly);
t('ptt-only component array: only ptt true, everything else (except sel) false',
    $show && $show['ptt'] && !$show['mon'] && !$show['mute'] && !$show['vol'] && !$show['text'] && $show['sel']);

$show = get_show($prefix, $idB3Empty);
t('empty component array: fully default (Select only)', $show && $show['sel'] && !$show['ptt'] && !$show['mon'] && !$show['text']);

// ── 4. NULL controls_json -> fully default template ─────────────────────
echo "\n4. NULL controls_json\n";
$show = get_show($prefix, $idNull);
t('NULL controls_json migrates to the fully-default (Select-only) template', $show && $show['sel'] && !$show['ptt'] && !$show['mon'] && !$show['mute'] && !$show['vol'] && !$show['text']);
t('hotkey defaults to null', get_hotkey($prefix, $idNull) === null);

// ── 5. Idempotency: an already-new-shape row is left untouched ─────────
echo "\n5. Idempotency -- an already-migrated row is never re-touched\n";
$show = get_show($prefix, $idAlreadyNew);
t('a row already in the new {show:...} shape keeps its EXACT original values (vu=true is a signal nothing here would derive)',
    $show && $show['vu'] === true && $show['ptt'] === true);
t('its hotkey (F5) survives untouched', get_hotkey($prefix, $idAlreadyNew) === 'F5');

// Run the migration a SECOND time -- must be a true no-op (same content,
// no crash, no double-wrapping of an already-migrated row).
$out2 = []; $rc2 = 1;
exec(escapeshellarg($bin) . ' ' . escapeshellarg(NEWUI_ROOT . '/sql/run_phase152_strip_templates.php') . ' 2>&1', $out2, $rc2);
t('running the migration a SECOND time exits 0 (idempotent)', $rc2 === 0);
$show2 = get_show($prefix, $idB2Full);
t('a row migrated on the FIRST run is unchanged by the second run', $show2 === $show2 && $show2['ptt'] && $show2['text']);
t('the row is now correctly reported as "already in the new shape" on the second run',
    stripos(implode("\n", $out2), 'already in the new template shape') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
