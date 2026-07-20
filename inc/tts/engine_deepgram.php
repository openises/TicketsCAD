<?php
/**
 * Phase 113c — Deepgram Aura driver.
 *
 * Deepgram's TTS returns RAW audio in the exact encoding + sample rate you
 * ask for (container=none), so for our s16le mono @ $rate contract there is
 * NO ffmpeg resample step at all — request linear16 at the target rate and
 * hand the bytes straight back. (mulaw is available too for the future SIP
 * path.) Best free tier of the hosted engines ($200 non-expiring credit) and
 * the most telephony-literate API.
 *
 * config_json: {
 *   voice    — the Aura model, e.g. 'aura-2-thalia-en' (overridable per app)
 *   key_ref  — filename under ../keys/tts/ holding the API key
 *   endpoint — override (default https://api.deepgram.com/v1/speak)
 *   encoding — 'linear16' (default) | 'mulaw' | 'alaw'
 * }
 */

require_once __DIR__ . '/engine.php';

function tts_driver_deepgram(array $cfg, string $text, string $voice, int $rate): array
{
    $model    = trim($voice) !== '' ? trim($voice) : trim((string) ($cfg['voice'] ?? ''));
    $endpoint = rtrim(trim((string) ($cfg['endpoint'] ?? '')), '/') ?: 'https://api.deepgram.com/v1/speak';
    $encoding = trim((string) ($cfg['encoding'] ?? 'linear16')) ?: 'linear16';
    $key      = tts_read_key($cfg['key_ref'] ?? '');
    $rate     = $rate > 0 ? $rate : 8000;

    if ($model === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => 'deepgram not configured (voice/model)'];
    }
    if ($key === '') {
        return ['ok' => false, 'pcm' => '', 'rate' => $rate, 'detail' => 'deepgram API key missing'];
    }

    // container=none → headerless raw audio; encoding + sample_rate exact.
    $url = $endpoint . '?model=' . rawurlencode($model)
         . '&encoding=' . rawurlencode($encoding)
         . '&sample_rate=' . $rate
         . '&container=none';
    $body = json_encode(['text' => $text], JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'Authorization: Token ' . $key];

    $fetch = $cfg['_fetch'] ?? null; // injectable for tests
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
                'detail' => 'Deepgram HTTP ' . $code . ($snippet !== '' ? ': ' . $snippet : '')];
    }
    // linear16 = s16le mono @ $rate — exactly our contract, no resample.
    return ['ok' => true, 'pcm' => $raw, 'rate' => $rate, 'detail' => 'ok'];
}
