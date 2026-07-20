<?php
/**
 * Gap 1 (docs/training-scripts/zello-config-video-brief.md) — Zello TTS
 * broadcast audio tests.
 *
 * "Type text → synthesise speech → key it onto the Zello channel as audio."
 * The path: api/zello-inbox.php?action=originate with `tts:true` queues a
 * zello_outbox row with kind='tts'; the long-running proxy (ZelloProxyApp)
 * drains it on its loop timer, runs Piper → ffmpeg → WebmOpusExtractor →
 * start_stream/binary-frames/stop_stream, and marks the row sent/failed.
 *
 * What's verifiable without a live Zello upstream + a Piper install:
 *   1. The OUTBOX CONTRACT — a kind='tts' row is broadcast-only (empty
 *      recipient), queued (not faked sent), source=originate; a kind='text'
 *      row is unchanged. (Direct DB inserts, mirroring the originate endpoint.)
 *   2. The MIGRATION — sql/run_zello_tts.php is idempotent and leaves
 *      zello_outbox.kind wide enough for 'tts'.
 *   3. SOURCE WIRING — the proxy branches pollZelloOutbox on kind, has the
 *      synth/stream/markOutbox methods, never throws out of synth, and gates
 *      audio failures without crashing; the API forces TTS to a channel
 *      broadcast + queues kind='tts'; the UI exposes "Speak on channel" only
 *      for a Zello channel send and passes tts:true.
 *
 * The actual Piper synth + Opus framing + Zello stream handshake need the
 * proxy host's Piper binary + a live upstream, so those are an integration
 * concern (see the deploy plan in the handoff), not unit-testable here.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tools/test_all.php   (or this file directly)
 */

require __DIR__ . '/../config.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== Zello TTS Broadcast Audio Tests (Gap 1) ===\n\n";
$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';

function t_ok(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] {$label}\n"; $pass++; }
    else       { echo "[FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n"; $fail++; }
}

// ── Schema: run the (idempotent) TTS migration; ensure the outbox is ready ──
echo "-- Migration --\n";
ob_start();
try { require __DIR__ . '/../sql/run_zello_tts.php'; } catch (Throwable $e) {}
$migOut = ob_get_clean();
$hasOutbox = (bool) db_fetch_value(
    "SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    [$prefix . 'zello_outbox']
);
t_ok("zello_outbox table present after migration", $hasOutbox);

$kindCol = db_fetch_one(
    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ? AND COLUMN_NAME = 'kind'",
    [$prefix . 'zello_outbox']
);
t_ok("zello_outbox.kind exists and fits 'tts' (>=16 chars)",
    $kindCol && ($kindCol['CHARACTER_MAXIMUM_LENGTH'] === null
        || (int) $kindCol['CHARACTER_MAXIMUM_LENGTH'] >= 16),
    'col=' . var_export($kindCol, true));

// Migration is safe to re-run (idempotent).
ob_start();
try { require __DIR__ . '/../sql/run_zello_tts.php'; } catch (Throwable $e) {}
ob_end_clean();
t_ok("run_zello_tts.php is idempotent (re-run did not throw)", true);

// Clean any leftover test rows.
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE `body` LIKE 'ZTTSTEST_%'");

// ──────────────────────────────────────────────────────────────────────
// 1. Outbox contract — a kind='tts' row is a channel broadcast (no DM).
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Outbox contract: TTS row --\n";
db_query(
    "INSERT INTO `{$prefix}zello_outbox`
        (kind, channel, recipient, body, status, queued_by, source)
     VALUES ('tts', 'Network radio Midwest', '', 'ZTTSTEST_speak', 'queued', 1, 'originate')"
);
$ttsOid = (int) db_insert_id();
$ttsRow = db_fetch_one(
    "SELECT kind, channel, recipient, status, source FROM `{$prefix}zello_outbox` WHERE id = ?",
    [$ttsOid]
);
t_ok("TTS row stored with kind='tts'", $ttsRow && $ttsRow['kind'] === 'tts');
t_ok("TTS row is broadcast-only (EMPTY recipient — voice has no per-user addressing)",
    $ttsRow && $ttsRow['recipient'] === '');
t_ok("TTS row keeps a named channel", $ttsRow && $ttsRow['channel'] === 'Network radio Midwest');
t_ok("TTS row status=queued (NOT faked sent) + source=originate",
    $ttsRow && $ttsRow['status'] === 'queued' && $ttsRow['source'] === 'originate');

// Blank channel is valid (proxy uses the dispatch channel).
db_query(
    "INSERT INTO `{$prefix}zello_outbox`
        (kind, channel, recipient, body, status, queued_by, source)
     VALUES ('tts', '', '', 'ZTTSTEST_speak_default', 'queued', 1, 'originate')"
);
$ttsDefRow = db_fetch_one(
    "SELECT kind, channel FROM `{$prefix}zello_outbox` WHERE id = ?", [(int) db_insert_id()]
);
t_ok("blank-channel TTS row queues (proxy resolves dispatch channel)",
    $ttsDefRow && $ttsDefRow['kind'] === 'tts' && $ttsDefRow['channel'] === '');

// A kind='text' row is unchanged by the TTS work (no regression).
db_query(
    "INSERT INTO `{$prefix}zello_outbox`
        (kind, channel, recipient, body, status, queued_by, source)
     VALUES ('text', '', 'someuser', 'ZTTSTEST_text', 'queued', 1, 'originate')"
);
$textRow = db_fetch_one(
    "SELECT kind, recipient FROM `{$prefix}zello_outbox` WHERE id = ?", [(int) db_insert_id()]
);
t_ok("kind='text' DM row still works (recipient preserved)",
    $textRow && $textRow['kind'] === 'text' && $textRow['recipient'] === 'someuser');

// ──────────────────────────────────────────────────────────────────────
// 2. API wiring — api/zello-inbox.php originate handles the tts flag.
// ──────────────────────────────────────────────────────────────────────
echo "\n-- API wiring (api/zello-inbox.php) --\n";
$inboxSrc = file_get_contents(__DIR__ . '/../api/zello-inbox.php');

t_ok("originate reads a tts flag/kind", (bool) preg_match('/\$isTts\s*=/', $inboxSrc));
t_ok("originate forces TTS to a channel broadcast (clears unit/member/recipient)",
    (bool) preg_match('/if\s*\(\s*\$isTts\s*\)\s*\{[^}]*\$recipient\s*=\s*\'\'/s', $inboxSrc));
t_ok("originate skips DM resolution when isTts (no resolve for a TTS send)",
    strpos($inboxSrc, '!$isTts && ($unitId > 0 || $memberId > 0)') !== false);
t_ok("originate queues kind='tts' (parameterised, not always 'text')",
    strpos($inboxSrc, "\$outboxKind = \$isTts ? 'tts' : 'text'") !== false);
t_ok("originate still CSRF-checks + admin-gates (manage_mesh_bridges) for TTS",
    strpos($inboxSrc, 'csrf_verify') !== false
    && strpos($inboxSrc, '_zinbox_admin_auth') !== false
    && strpos($inboxSrc, 'action.manage_mesh_bridges') !== false);
t_ok("originate never sends RF itself (still queue-only)",
    strpos($inboxSrc, 'zello_outbox') !== false
    && strpos($inboxSrc, 'sendTextMessage') === false);

// ──────────────────────────────────────────────────────────────────────
// 3. Proxy wiring — ZelloProxyApp synth + stream + branch + safety.
// ──────────────────────────────────────────────────────────────────────
echo "\n-- Proxy wiring (proxy/ZelloProxyApp.php) --\n";
$proxySrc = file_get_contents(__DIR__ . '/../proxy/ZelloProxyApp.php');

t_ok("pollZelloOutbox SELECTs the kind column",
    (bool) preg_match('/SELECT\s+`id`,\s*`kind`/', $proxySrc));
t_ok("pollZelloOutbox branches on kind==='tts'",
    (bool) preg_match("/if\s*\(\s*\\\$kind\s*===\s*'tts'\s*\)/", $proxySrc));
t_ok("proxy has relayTtsOutbox()", strpos($proxySrc, 'function relayTtsOutbox') !== false);
t_ok("proxy has synthesizeTtsFrames()", strpos($proxySrc, 'function synthesizeTtsFrames') !== false);
t_ok("proxy has beginTtsStream() + pumpTtsFrame() (paced frame send)",
    strpos($proxySrc, 'function beginTtsStream') !== false
    && strpos($proxySrc, 'function pumpTtsFrame') !== false);
t_ok("proxy has markOutbox() to terminalise the row (sent/failed)",
    strpos($proxySrc, 'function markOutbox') !== false);

t_ok("synth shells to a CONFIGURABLE Piper binary (not hard-coded)",
    strpos($proxySrc, 'piper_bin') !== false
    && strpos($proxySrc, 'zello_tts_piper_bin') !== false);
t_ok("synth reuses ffmpeg → WebM/Opus → WebmOpusExtractor (NO new Opus encoder)",
    strpos($proxySrc, '-c:a libopus') !== false
    && strpos($proxySrc, 'new WebmOpusExtractor') !== false);
t_ok("synth reports 'TTS not configured' (returns null) when Piper unset",
    strpos($proxySrc, 'TTS not configured') !== false);

// Safety: a missing Piper / synth failure must NOT throw out of the proxy.
t_ok("relayTtsOutbox catches Throwable from synth (never crashes proxy)",
    (bool) preg_match('/synthesizeTtsFrames\([^)]*\);\s*\}\s*catch\s*\(\\\\Throwable/s', $proxySrc));
t_ok("pumpTtsFrame is wrapped so a timer tick can't crash the loop",
    (bool) preg_match('/function pumpTtsFrame.*catch\s*\(\\\\Throwable/s', $proxySrc));
t_ok("a TTS synth/stream failure marks the outbox row 'failed'",
    (bool) preg_match("/markOutbox\([^)]*,\s*'failed'/", $proxySrc));

// Stream framing matches the proven mic path (16k/1ch/20ms codec_header).
t_ok("TTS opens an audio stream with start_stream + opus codec_header",
    (bool) preg_match("/'command'\s*=>\s*'start_stream'.*'codec'\s*=>\s*'opus'/s", $proxySrc));
t_ok("TTS frames go out as 0x01 binary packets then stop_stream (reused path)",
    strpos($proxySrc, "chr(0x01)") !== false
    && strpos($proxySrc, "'command'   => 'stop_stream'") !== false);

// ──────────────────────────────────────────────────────────────────────
// 4. UI wiring — mesh-console "Speak on channel" affordance.
// ──────────────────────────────────────────────────────────────────────
echo "\n-- UI wiring (mesh-console) --\n";
$phpSrc = file_get_contents(__DIR__ . '/../mesh-console.php');
$jsSrc  = file_get_contents(__DIR__ . '/../assets/js/mesh-console.js');

t_ok("Send tab has a 'Speak on channel' (TTS) checkbox", strpos($phpSrc, 'sendZelloTts') !== false);
t_ok("the TTS affordance is in its own wrap that JS can show/hide",
    strpos($phpSrc, 'sendZelloTtsWrap') !== false);
t_ok("JS shows TTS only for a Zello CHANNEL send (mode==='channel' && isZello)",
    (bool) preg_match("/showTts\s*=\s*\(mode === 'channel' && isZello\)/", $jsSrc));
t_ok("JS clears the TTS tick when the affordance is hidden (no stale leak)",
    (bool) preg_match('/if\s*\(!showTts\).*ttsCb\.checked\s*=\s*false/s', $jsSrc));
t_ok("sendZello passes tts:true for a channel TTS send",
    (bool) preg_match('/if\s*\(isTts\)\s*body\.tts\s*=\s*true/', $jsSrc));
t_ok("sendZello blocks a TTS+unit combination (broadcast-only guidance)",
    (bool) preg_match('/mode === .unit.[\s\S]*isTts[\s\S]*broadcast/', $jsSrc));

// ── Cleanup ──
db_query("DELETE FROM `{$prefix}zello_outbox` WHERE `body` LIKE 'ZTTSTEST_%'");

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
