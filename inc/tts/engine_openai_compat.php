<?php
/**
 * Phase 113 — OpenAI-compatible HTTP driver.
 *
 * ONE driver covers OpenAI, Groq (Orpheus), and every self-hosted server that
 * exposes OpenAI's POST /v1/audio/speech: Kokoro-FastAPI, Chatterbox-TTS-
 * Server, Kitten-TTS-Server, … (the de-facto local-TTS standard). So a user
 * can run Kokoro in Docker for a free quality upgrade over Piper, or point at
 * a hosted key, with the same config shape.
 *
 * config_json: {
 *   endpoint  — base URL (e.g. http://127.0.0.1:8880/v1 or
 *               https://api.openai.com/v1). '/audio/speech' is appended.
 *   model     — e.g. 'kokoro', 'tts-1', 'playai-tts'
 *   voice     — default voice (overridable per application)
 *   key_ref   — filename under ../keys/tts/ holding the API key (blank for a
 *               local no-auth server)
 *   format    — request format we ask the server for; we always want raw PCM.
 *               Defaults to 'pcm' (OpenAI + Kokoro return s16le). ffmpeg then
 *               normalizes to mono @ $rate.
 *   in_rate   — the server's PCM sample rate (OpenAI = 24000, Kokoro = 24000).
 *   ffmpeg    — ffmpeg binary (default 'ffmpeg')
 * }
 */

require_once __DIR__ . '/engine.php';

function tts_driver_openai_compat(array $cfg, string $text, string $voice, int $rate): array
{
    $endpoint = rtrim(trim((string) ($cfg['endpoint'] ?? '')), '/');
    $model    = trim((string) ($cfg['model'] ?? ''));
    $useVoice = trim($voice) !== '' ? trim($voice) : trim((string) ($cfg['voice'] ?? ''));
    $inRate   = (int) ($cfg['in_rate'] ?? 24000) ?: 24000;
    $ffmpeg   = trim((string) ($cfg['ffmpeg'] ?? '')) ?: 'ffmpeg';
    $key      = tts_read_key($cfg['key_ref'] ?? '');
    $rate     = $rate > 0 ? $rate : 8000;

    if ($endpoint === '' || $model === '' || $useVoice === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate,
                'detail' => 'openai_compat not configured (endpoint/model/voice)'];
    }

    $url  = $endpoint . '/audio/speech';
    $body = json_encode([
        'model'           => $model,
        'input'           => $text,
        'voice'           => $useVoice,
        'response_format' => 'pcm',   // s16le mono @ in_rate on OpenAI + Kokoro
    ], JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type: application/json'];
    if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

    // Allow tests to inject a fetcher: fn(url,headers,body)->[code,bytes].
    $fetch = $cfg['_fetch'] ?? null;
    if (is_callable($fetch)) {
        [$code, $raw] = $fetch($url, $headers, $body);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => 'HTTP error: ' . $err];
        }
    }

    if ($code !== 200 || !is_string($raw) || $raw === '') {
        $snippet = is_string($raw) ? mb_substr($raw, 0, 180) : '';
        return ['ok' => false, 'pcm' => '', 'rate' => $rate,
                'detail' => 'server HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '')];
    }

    // Normalize the server's PCM to mono @ $rate. If ffmpeg is missing and the
    // rate already matches, hand it back as-is.
    if ($inRate === $rate) {
        return ['ok' => true, 'pcm' => $raw, 'rate' => $rate, 'detail' => 'ok'];
    }
    $ffmpegCmd = escapeshellarg($ffmpeg)
        . ' -hide_banner -loglevel error'
        . ' -f s16le -ar ' . $inRate . ' -ac 1 -i pipe:0'
        . ' -ar ' . $rate . ' -ac 1 -f s16le pipe:1';
    $pcm = tts_run_pipe($ffmpegCmd, $raw, 20);
    if ($pcm === null || $pcm === '') {
        return ['ok' => true, 'pcm' => $raw, 'rate' => $inRate,
                'detail' => 'ok (server rate; ffmpeg resample unavailable)'];
    }
    return ['ok' => true, 'pcm' => $pcm, 'rate' => $rate, 'detail' => 'ok'];
}
