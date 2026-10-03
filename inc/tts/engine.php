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
 * filename in the TTS key directory (mode 0640). Where that directory is —
 * and why it is NOT inside the application tree — is inc/tts/keys.php, which
 * owns tts_keys_dir(), tts_read_key() and the one writer, tts_write_key().
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/keys.php';

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

/**
 * Is a bare command name resolvable on PATH?
 *
 * Resolved in PHP rather than by asking a shell. Three reasons this is not a
 * mechanical rewrite of the old `command -v` / `where` subprocess:
 *
 *  1. `command` is a POSIX shell BUILTIN — there is no /usr/bin/command on
 *     Debian/Ubuntu/Alpine/macOS. So the old line only worked because a shell
 *     parsed it, and an argv-array proc_open() of it would fail with ENOENT on
 *     every POSIX host, silently reporting every on-PATH binary as missing.
 *  2. The Windows branch selected `where` but still appended `2>/dev/null`,
 *     which cmd.exe treats as a path — it failed before running, so this
 *     function has always returned false on Windows.
 *  3. shell_exec() is removed by disable_functions on hardened Windows/IIS
 *     hosts, and @ does not suppress the resulting fatal.
 *
 * No subprocess also means $bin can no longer reach a command line at all,
 * which is why the escapeshellarg() that used to guard it is gone rather than
 * merely moved.
 */
function tts_bin_on_path(string $bin): bool
{
    if ($bin === '' || strpbrk($bin, "/\\") !== false) return false;
    $path = (string) getenv('PATH');
    if ($path === '') return false;

    $isWin = stripos(PHP_OS, 'WIN') === 0;
    // A bare name on Windows resolves against PATHEXT (.EXE, .BAT, …).
    $exts = [''];
    if ($isWin) {
        $pathext = (string) getenv('PATHEXT');
        if ($pathext === '') $pathext = '.COM;.EXE;.BAT;.CMD';
        $exts = array_merge($exts, explode(';', $pathext));
    }

    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        $dir = rtrim(trim($dir), "/\\");
        if ($dir === '') continue;
        foreach ($exts as $ext) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $bin . $ext;
            // is_executable() is unreliable on Windows; PATHEXT is the test there.
            if (@is_file($candidate) && ($isWin || @is_executable($candidate))) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Pipe $input to a shell command's stdin, capture stdout. Returns null on
 * failure. Shared by the subprocess drivers (Piper, ffmpeg resample).
 *
 * openises/TicketsCAD#28 (@rjonesbsink) — this used pipes for all three
 * descriptors and relied on stream_set_blocking() to keep them from wedging.
 * **stream_set_blocking() cannot put a proc_open pipe into non-blocking mode
 * on Windows**: it returns false and the stream stays blocking. Measured here
 * on PHP 8.2.4/Windows (reporter saw the same on 8.4.22):
 *
 *     stream_set_blocking(stdout,false) returned: false
 *     stdout meta blocked=true  stream_type=STDIO
 *
 * The old loop then blocked in stream_get_contents($pipes[1]), which reads to
 * EOF, while NEVER draining stderr at all. A child that writes more stderr
 * than one pipe buffer (Piper's startup logging does) blocks writing it, so
 * stdout never reaches EOF and the parent never returns — and because the
 * `$timeoutSec` check sits AFTER that blocking read, the guard is unreachable
 * and cannot fire. Measured pre-fix: an internal 5s guard with a child writing
 * 8192 bytes of stderr ran until an external kill at 20s; with the child
 * exiting on its own after 6s, a 1s guard returned at 6.11s.
 *
 * Worse than hanging, it then returned what it had: the reporter saw ok=true /
 * detail='ok' with 2,940 bytes (0.09s) of audio for a ~4s sentence.
 *
 * Fix: temp files for all three descriptors. The child writes as fast as the
 * filesystem allows and can never block on a full pipe, there is nothing for
 * the parent to block on, and the deadline check becomes reachable — so a
 * genuinely wedged synth is now terminated and reported as a failure instead
 * of being silently truncated into a caller's audio. Identical behaviour on
 * POSIX, so this is not Windows-only code.
 */
function tts_run_pipe(string $cmd, string $input, int $timeoutSec = 30): ?string
{
    $tag  = 'ttspipe_' . getmypid() . '_' . bin2hex(random_bytes(6));
    $dir  = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR;
    $fIn  = $dir . $tag . '.in';
    $fOut = $dir . $tag . '.out';
    $fErr = $dir . $tag . '.err';

    $cleanup = static function () use ($fIn, $fOut, $fErr) {
        foreach ([$fIn, $fOut, $fErr] as $f) { if (@is_file($f)) @unlink($f); }
    };

    if (@file_put_contents($fIn, $input) === false) { $cleanup(); return null; }

    $descriptors = [
        0 => ['file', $fIn,  'r'],
        1 => ['file', $fOut, 'w'],
        2 => ['file', $fErr, 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) { $cleanup(); return null; }

    $timedOut = false;
    $deadline = microtime(true) + max(1, $timeoutSec);
    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (microtime(true) > $deadline) { $timedOut = true; proc_terminate($proc, 9); break; }
        usleep(5000);
    }
    proc_close($proc);

    $out = (string) @file_get_contents($fOut);
    $err = (string) @file_get_contents($fErr);
    $cleanup();

    // A timeout is a failure, not a short read. Returning the partial buffer is
    // exactly the "success with truncated audio" the old code produced.
    if ($timedOut) {
        error_log('tts_run_pipe: timed out after ' . $timeoutSec . 's, killed: ' . $cmd
            . ($err !== '' ? ' | stderr: ' . trim(substr($err, 0, 300)) : ''));
        return null;
    }
    if ($out === '') {
        error_log('tts_run_pipe: no stdout from: ' . $cmd
            . ($err !== '' ? ' | stderr: ' . trim(substr($err, 0, 300)) : ''));
        return null;
    }
    return $out;
}
