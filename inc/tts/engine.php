<?php
/**
 * Phase 113 — TTS engine registry (the one place TicketsCAD turns text into
 * speech). Every "speech application" (weather bulletins, radio-AI replies,
 * Zello read-outs, announcements, SIP callouts, Test-Listen) resolves to an
 * engine + voice + target sample rate here, so an admin can pick the engine
 * they're comfortable with per application without touching code.
 *
 * Driver contract (each inc/tts/engine_<driver>.php exposes):
 *   tts_driver_<driver>(array $cfg, string $text, string $voice, int $rate): array
 *     → ['ok'=>bool, 'pcm'=>string(bytes, s16le mono @ $rate), 'detail'=>string]
 *   The driver resamples to $rate itself (ffmpeg). A typed failure (ok=false)
 *   lets the registry fall through to the fallback engine, ultimately Piper —
 *   the mandatory-fallback policy (a hosted engine can vanish outright, cf.
 *   PlayHT 2025). PCM is the lingua franca; callers wrap it (WAV for the
 *   browser Test-Listen, Opus for Zello, AMBE for DMR).
 *
 * API keys are NEVER in the DB: an engine's config_json carries a `key_ref`
 * filename under ../keys/tts/ (mode 0640, outside the webroot).
 */

require_once __DIR__ . '/../db.php';

/** Directory holding TTS API-key files (outside the webroot). */
function tts_keys_dir(): string
{
    return dirname(__DIR__, 2) . '/keys/tts';
}

/** Read an engine's API key from its 0640 key file (never from the DB). */
function tts_read_key(?string $keyRef): string
{
    $keyRef = trim((string) $keyRef);
    if ($keyRef === '') return '';
    // Basename only — never let a stored value traverse out of the keys dir.
    $path = tts_keys_dir() . '/' . basename($keyRef);
    if (!is_file($path)) return '';
    return trim((string) @file_get_contents($path));
}

/** Load one engine row (decoded config). Returns null if missing/disabled-ok. */
function tts_get_engine(int $engineId): ?array
{
    if ($engineId <= 0) return null;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $row = db_fetch_one(
            "SELECT id, engine_key, driver, label, config_json, enabled
             FROM `{$prefix}tts_engines` WHERE id = ? LIMIT 1",
            [$engineId]
        );
    } catch (Throwable $e) { return null; }
    if (!$row) return null;
    $row['config'] = json_decode((string) ($row['config_json'] ?? ''), true) ?: [];
    return $row;
}

/** The default Piper engine (the base of every fallback ladder). */
function tts_default_engine(): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        $id = (int) db_fetch_value("SELECT id FROM `{$prefix}tts_engines` WHERE engine_key = 'piper-default' LIMIT 1");
    } catch (Throwable $e) { return null; }
    return $id ? tts_get_engine($id) : null;
}

/** Resolve a speech application → {engine_id, voice, rate, fallback_engine_id}. */
function tts_get_application(string $appKey): ?array
{
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        return db_fetch_one(
            "SELECT app_key, label, engine_id, voice, rate, fallback_engine_id
             FROM `{$prefix}tts_applications` WHERE app_key = ? LIMIT 1",
            [$appKey]
        ) ?: null;
    } catch (Throwable $e) { return null; }
}

/** Lazy-load a driver's implementation file. */
function tts_load_driver(string $driver): bool
{
    $driver = preg_replace('/[^a-z0-9_]/', '', strtolower($driver));
    if ($driver === '') return false;
    $fn = 'tts_driver_' . $driver;
    if (function_exists($fn)) return true;
    $file = __DIR__ . '/engine_' . $driver . '.php';
    if (is_file($file)) { require_once $file; }
    return function_exists($fn);
}

/**
 * Run ONE engine. Returns ['ok'=>bool,'pcm'=>string,'rate'=>int,'detail'=>string].
 * Records last_ok_at / last_error on the engine row (best-effort).
 */
function tts_run_engine(array $engine, string $text, string $voice, int $rate): array
{
    $driver = (string) ($engine['driver'] ?? '');
    if (!tts_load_driver($driver)) {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => "driver '{$driver}' not available"];
    }
    $fn = 'tts_driver_' . preg_replace('/[^a-z0-9_]/', '', strtolower($driver));
    try {
        $r = $fn($engine['config'] ?? [], $text, $voice, $rate);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => 'driver threw: ' . $e->getMessage()];
    }
    _tts_stamp_engine((int) ($engine['id'] ?? 0), !empty($r['ok']), (string) ($r['detail'] ?? ''));
    return $r + ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => ''];
}

function _tts_stamp_engine(int $engineId, bool $ok, string $detail): void
{
    if ($engineId <= 0) return;
    $prefix = $GLOBALS['db_prefix'] ?? '';
    try {
        if ($ok) {
            db_query("UPDATE `{$prefix}tts_engines` SET last_ok_at = NOW(), last_error = NULL WHERE id = ?", [$engineId]);
        } else {
            db_query("UPDATE `{$prefix}tts_engines` SET last_error = ? WHERE id = ?",
                [mb_substr($detail, 0, 255), $engineId]);
        }
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * Synthesize for a speech application, with the mandatory fallback ladder:
 *   chosen engine → application fallback engine → default Piper engine.
 * @return array{ok:bool, pcm:string, rate:int, engine:string, detail:string, failovers:array}
 */
function tts_synthesize(string $appKey, string $text, array $opts = []): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => 0, 'engine' => '', 'detail' => 'empty text', 'failovers' => []];
    }
    $app = tts_get_application($appKey);
    // Build the ordered candidate ladder (dedup by id, skip nulls/disabled).
    $rate  = (int) ($opts['rate'] ?? ($app['rate'] ?? 8000));
    $voice = (string) ($opts['voice'] ?? ($app['voice'] ?? ''));

    $ladder = [];
    $push = function ($engineId) use (&$ladder) {
        $engineId = (int) $engineId;
        if ($engineId > 0 && !in_array($engineId, $ladder, true)) $ladder[] = $engineId;
    };
    if (!empty($opts['engine_id'])) $push($opts['engine_id']);
    if ($app) { $push($app['engine_id']); $push($app['fallback_engine_id']); }
    $def = tts_default_engine();
    if ($def) $push($def['id']);

    $failovers = [];
    foreach ($ladder as $engineId) {
        $engine = tts_get_engine($engineId);
        if (!$engine || (int) ($engine['enabled'] ?? 1) !== 1) {
            $failovers[] = ['engine_id' => $engineId, 'detail' => 'missing or disabled'];
            continue;
        }
        // A per-engine default voice from its config, unless the app overrides.
        $useVoice = $voice !== '' ? $voice : (string) ($engine['config']['voice'] ?? '');
        $r = tts_run_engine($engine, $text, $useVoice, $rate);
        if (!empty($r['ok']) && $r['pcm'] !== '') {
            return ['ok' => true, 'pcm' => $r['pcm'], 'rate' => (int) ($r['rate'] ?? $rate),
                    'engine' => (string) $engine['engine_key'], 'detail' => 'ok', 'failovers' => $failovers];
        }
        $failovers[] = ['engine_id' => $engineId, 'engine' => $engine['engine_key'],
                        'detail' => (string) ($r['detail'] ?? 'failed')];
    }
    return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'engine' => '',
            'detail' => 'all engines failed', 'failovers' => $failovers];
}

/**
 * Resolve the Piper voice model a speech application should use on a DMR
 * read-out. Phase 113e: DMR audio is 8 kHz AMBE, so hosted/neural engines
 * buy nothing through the vocoder — only Piper applies here. If the
 * application is routed to a non-Piper engine (or nothing), returns '' and
 * the bridge falls back to its own configured default voice. Lets the Voice
 * & Speech page's per-application voice actually reach the radio.
 */
function tts_dmr_piper_voice(string $appKey): string
{
    $app = tts_get_application($appKey);
    $engine = null;
    if ($app && !empty($app['engine_id'])) {
        $engine = tts_get_engine((int) $app['engine_id']);
    }
    if (!$engine) $engine = tts_default_engine();
    if (!$engine || (string) ($engine['driver'] ?? '') !== 'piper'
        || (int) ($engine['enabled'] ?? 1) !== 1) {
        return '';
    }
    // Application voice override wins; else the engine's configured voice.
    $voice = $app ? trim((string) ($app['voice'] ?? '')) : '';
    if ($voice === '') $voice = trim((string) ($engine['config']['voice'] ?? ''));
    return $voice;
}

/** Wrap raw s16le mono PCM in a WAV container (for browser Test-Listen). */
function tts_pcm_to_wav(string $pcm, int $rate): string
{
    $ch = 1; $bits = 16;
    $byteRate   = $rate * $ch * ($bits / 8);
    $blockAlign = $ch * ($bits / 8);
    $dataLen    = strlen($pcm);
    return 'RIFF' . pack('V', 36 + $dataLen) . 'WAVE'
         . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $ch)
         . pack('V', $rate) . pack('V', $byteRate) . pack('v', $blockAlign) . pack('v', $bits)
         . 'data' . pack('V', $dataLen) . $pcm;
}

/** Is a bare command name resolvable on PATH? (which/where) */
function tts_bin_on_path(string $bin): bool
{
    if ($bin === '' || strpbrk($bin, "/\\") !== false) return false;
    $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'command -v';
    $out = @shell_exec($which . ' ' . escapeshellarg($bin) . ' 2>/dev/null');
    return is_string($out) && trim($out) !== '';
}

/**
 * Pipe $input to a shell command's stdin, capture stdout. Returns null on
 * failure. Shared by the subprocess drivers (Piper, ffmpeg resample).
 */
function tts_run_pipe(string $cmd, string $input, int $timeoutSec = 30): ?string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return null;
    // Non-blocking write+read to avoid pipe deadlock on large payloads.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $out = ''; $start = time();
    do {
        $out .= (string) stream_get_contents($pipes[1]);
        $status = proc_get_status($proc);
        if (!$status['running']) { $out .= (string) stream_get_contents($pipes[1]); break; }
        if (time() - $start > $timeoutSec) { proc_terminate($proc); break; }
        usleep(10000);
    } while (true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $out === '' ? null : $out;
}
