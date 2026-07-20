<?php
/**
 * Phase 113 — pluggable TTS engine registry.
 *
 * Exercises the registry contract with INJECTED drivers/fetchers (no live
 * Piper / no live HTTP): the openai_compat driver's HTTP-error handling, the
 * fallback ladder, WAV wrapping, key-file handling (path-traversal safe), the
 * schema + seeds, and the admin API / page wiring.
 *
 * Usage: php tests/test_tts_engines.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/tts/engine.php';
require_once __DIR__ . '/../inc/tts/engine_openai_compat.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== Phase 113 — TTS engines ===\n\n";

// Ensure schema + seeds exist (runner may not have run on a bare checkout).
require_once __DIR__ . '/../sql/run_tts_engines.php';

// ── Schema + seed ────────────────────────────────────────────────────────────
$eCols = array_column(db_fetch_all("SHOW COLUMNS FROM `{$prefix}tts_engines`"), 'Field');
t('tts_engines has driver + config_json + last_error',
    in_array('driver', $eCols) && in_array('config_json', $eCols) && in_array('last_error', $eCols));
$piper = db_fetch_one("SELECT id, driver FROM `{$prefix}tts_engines` WHERE engine_key = 'piper-default'");
t('default Piper engine seeded (driver=piper)', $piper && $piper['driver'] === 'piper');
$appCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}tts_applications`");
t('speech applications seeded (>= 5)', $appCount >= 5);
$wxApp = db_fetch_one("SELECT engine_id, rate FROM `{$prefix}tts_applications` WHERE app_key = 'weather_bulletin'");
t('weather_bulletin app defaults to Piper @ 8000',
    $wxApp && (int) $wxApp['engine_id'] === (int) $piper['id'] && (int) $wxApp['rate'] === 8000);

// ── openai_compat driver (injected fetcher — no live HTTP) ───────────────────
$fakePcm = str_repeat("\x11\x22", 1200); // 2400 bytes s16le @ 24000
$okCfg = ['endpoint' => 'http://x/v1', 'model' => 'kokoro', 'voice' => 'af_bella', 'in_rate' => 24000,
          '_fetch' => function ($u, $h, $b) use ($fakePcm) { return [200, $fakePcm]; }];
$r = tts_driver_openai_compat($okCfg, 'hello', '', 24000);
t('openai_compat returns PCM on 200 (rate match → no resample)',
    $r['ok'] === true && $r['pcm'] === $fakePcm && $r['rate'] === 24000);
$errCfg = $okCfg; $errCfg['_fetch'] = function () { return [401, 'unauthorized']; };
$rErr = tts_driver_openai_compat($errCfg, 'hello', '', 24000);
t('openai_compat surfaces HTTP errors (not silent)',
    $rErr['ok'] === false && strpos($rErr['detail'], '401') !== false);
$unConf = tts_driver_openai_compat(['endpoint' => '', 'model' => '', 'voice' => ''], 'x', '', 8000);
t('openai_compat unconfigured → typed failure', $unConf['ok'] === false);
// Voice override wins over config voice.
$vseen = '';
$vCfg = $okCfg; $vCfg['_fetch'] = function ($u, $h, $b) use (&$vseen, $fakePcm) {
    $j = json_decode($b, true); $vseen = $j['voice'] ?? ''; return [200, $fakePcm]; };
tts_driver_openai_compat($vCfg, 'hi', 'ryan-override', 24000);
t('per-application voice override is sent to the server', $vseen === 'ryan-override');

// ── WAV wrap ─────────────────────────────────────────────────────────────────
$wav = tts_pcm_to_wav($fakePcm, 24000);
t('tts_pcm_to_wav emits a valid RIFF/WAVE container',
    substr($wav, 0, 4) === 'RIFF' && substr($wav, 8, 4) === 'WAVE' &&
    strlen($wav) === strlen($fakePcm) + 44);

// ── Key handling (path-traversal safe, never from DB) ────────────────────────
$dir = tts_keys_dir();
@mkdir($dir, 0750, true);
@file_put_contents($dir . '/zztest.key', "SECRET123\n");
t('tts_read_key reads a key file', tts_read_key('zztest.key') === 'SECRET123');
t('tts_read_key basenames the ref (no traversal)', tts_read_key('../../config.php') === '');
@unlink($dir . '/zztest.key');

// ── Fallback ladder (registry): a broken engine falls through to Piper ───────
// Register a throwaway openai_compat engine that always 500s, point the 'test'
// app at it, and confirm the ladder records the failover then hits Piper
// (Piper is unconfigured here, so the terminal result is a clean all-failed).
db_query("DELETE FROM `{$prefix}tts_engines` WHERE engine_key = 'zz-broken'");
db_query("INSERT INTO `{$prefix}tts_engines` (engine_key, driver, label, config_json, enabled)
          VALUES ('zz-broken','openai_compat','ZZ broken', ?, 1)",
    [json_encode(['endpoint' => 'http://127.0.0.1:9', 'model' => 'x', 'voice' => 'y'])]);
$brokenId = (int) db_insert_id();
$syn = tts_synthesize('test', 'hello', ['rate' => 8000, 'engine_id' => $brokenId]);
t('registry records a failover for a broken engine + falls through to Piper',
    $syn['ok'] === false && count($syn['failovers']) >= 1);
db_query("DELETE FROM `{$prefix}tts_engines` WHERE id = ?", [$brokenId]);

// ── Wiring guards ────────────────────────────────────────────────────────────
$api = rd($base . '/api/tts.php');
t('api/tts.php gates on manage_tts + CSRF + audit',
    strpos($api, "action.manage_tts") !== false && strpos($api, 'csrf_verify') !== false &&
    strpos($api, "audit_log('tts'") !== false);
t('api/tts.php never returns the API key (has_key only)',
    strpos($api, "'has_key'") !== false && strpos($api, "unset(\$cfg['key_ref'])") !== false);
$page = rd($base . '/voice-speech.php');
t('voice-speech.php admin page is RBAC-gated + loads the controller',
    strpos($page, "rbac_can('action.manage_tts')") !== false &&
    strpos($page, 'assets/js/tts-config.js') !== false);
$js = rd($base . '/assets/js/tts-config.js');
t('controller has engine CRUD + Test-Listen + application routing',
    strpos($js, "'test'") !== false && strpos($js, "'save_engine'") !== false &&
    strpos($js, "'delete_engine'") !== false && strpos($js, "'save_application'") !== false &&
    strpos($js, 'playAudio') !== false);
$side = rd($base . '/inc/config-sidebar.php');
t('config sidebar links Voice & Speech (RBAC-gated)',
    strpos($side, 'voice-speech.php') !== false && strpos($side, 'action.manage_tts') !== false);

// ── 113c: Deepgram driver (injected fetcher; native rate, no resample) ───────
require_once __DIR__ . '/../inc/tts/engine_deepgram.php';
$dir = tts_keys_dir(); @mkdir($dir, 0750, true);
@file_put_contents($dir . '/dgunit.key', 'DGKEY');
$dgPcm = str_repeat("\x03\x04", 500);
$seenUrl = '';
$dg = tts_driver_deepgram(
    ['voice' => 'aura-2-thalia-en', 'key_ref' => 'dgunit.key',
     '_fetch' => function ($u, $h, $b) use ($dgPcm, &$seenUrl) { $seenUrl = $u; return [200, $dgPcm]; }],
    'hi', '', 8000);
t('deepgram returns linear16 PCM at the exact rate (no resample)',
    $dg['ok'] === true && $dg['pcm'] === $dgPcm && $dg['rate'] === 8000 &&
    strpos($seenUrl, 'encoding=linear16') !== false && strpos($seenUrl, 'sample_rate=8000') !== false);
$dgErr = tts_driver_deepgram(['voice' => 'x'], 'hi', '', 8000);
t('deepgram without a key → typed failure (never silent)', $dgErr['ok'] === false);
@unlink($dir . '/dgunit.key');
$api2 = rd($base . '/api/tts.php');
t('driver catalog offers deepgram', strpos($api2, "'deepgram'") !== false);

// ── 113e: DMR Piper voice resolver + live-path wiring ────────────────────────
t('tts_dmr_piper_voice exists (Piper-only DMR voice selection)',
    function_exists('tts_dmr_piper_voice'));
// With the seed 'weather_bulletin' → piper-default (a Piper engine), the
// resolver returns the engine's configured voice ('' locally where Piper is
// unconfigured; a real path where it is). Either way it must not throw.
$dv = tts_dmr_piper_voice('weather_bulletin');
t('tts_dmr_piper_voice resolves without error (string)', is_string($dv));
$wr = rd($base . '/inc/weather_radio.php');
t('weather radio TX passes the resolved DMR voice to the bridge',
    strpos($wr, "tts_dmr_piper_voice('weather_bulletin')") !== false &&
    strpos($wr, "\$payload['voice']") !== false);
$hbp = rd($base . '/services/dvswitch/hbp_client.py');
t('bridge /tx/text honors an optional voice override (default-preserving)',
    strpos($hbp, 'body.get("voice")') !== false && strpos($hbp, 'os.path.isfile(voice)') !== false);
$zp = rd($base . '/proxy/ZelloProxyApp.php');
t('Zello proxy is registry-first with a guaranteed inline-Piper fallback',
    strpos($zp, "tts_synthesize('zello_readout'") !== false &&
    strpos($zp, 'inline Piper fallback') !== false &&
    strpos($zp, 'if ($pcm === null)') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
